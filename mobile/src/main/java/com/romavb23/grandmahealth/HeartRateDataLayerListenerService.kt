package com.romavb23.grandmahealth

import android.content.Context
import android.os.SystemClock
import android.provider.Settings
import android.util.Log
import com.google.android.gms.wearable.DataEvent
import com.google.android.gms.wearable.DataEventBuffer
import com.google.android.gms.wearable.DataMap
import com.google.android.gms.wearable.DataMapItem
import com.google.android.gms.wearable.MessageEvent
import com.google.android.gms.wearable.WearableListenerService
import com.google.android.gms.wearable.Wearable

class HeartRateDataLayerListenerService : WearableListenerService() {
    override fun onDataChanged(dataEvents: DataEventBuffer) {
        dataEvents.forEach { event ->
            if (event.type == DataEvent.TYPE_CHANGED && event.dataItem.uri.path == HEART_RATE_PATH) {
                // DataItems can be replayed after reconnection. Never mark them as live contact.
                saveSnapshot(DataMapItem.fromDataItem(event.dataItem).dataMap, liveHeartbeat = false)
            }
            if (event.type == DataEvent.TYPE_CHANGED && event.dataItem.uri.path?.startsWith("/watch/charging/") == true) {
                // Persist both offline transitions before acknowledging/removing the DataItem.
                // Historical packets never establish live contact or regress the current UI.
                try {
                    val data = DataMapItem.fromDataItem(event.dataItem).dataMap
                    if (TelemetryOutbox.get(this).enqueue(data, true, System.currentTimeMillis(),
                            data.getString(WATCH_KEY_MONITORING_STATUS) ?: "unknown", liveContactEligible = false)) {
                        ServerUploadService.packetReady()
                        Wearable.getDataClient(this).deleteDataItems(event.dataItem.uri).addOnFailureListener {
                            Log.w(LOG_TAG, "Charging history persisted; DataItem cleanup failed")
                        }
                    }
                } catch (_: Exception) {
                    Log.e(LOG_TAG, "Cannot persist charging history; DataItem retained")
                }
            }
        }
    }

    override fun onMessageReceived(messageEvent: MessageEvent) {
        if (messageEvent.path !in setOf(WATCH_HEARTBEAT_PATH, MEASUREMENT_RESPONSE_PATH)) return
        if (messageEvent.data.size > 8192) return
        try {
            val data = DataMap.fromByteArray(messageEvent.data)
            if (messageEvent.path == MEASUREMENT_RESPONSE_PATH) {
                if (MeasurementRequests.receive(this, messageEvent.sourceNodeId, data)) {
                    saveSnapshot(data, liveHeartbeat = true)
                }
                return
            }
            val now = System.currentTimeMillis()
            if (!WatchFreshness.heartbeatIsTimely(data.getLong(WATCH_KEY_SENT_AT, 0L), now)) {
                Log.w(LOG_TAG, "Heartbeat rejected: delayed packet or watch/phone clock mismatch")
                return
            }
            saveSnapshot(data, liveHeartbeat = true)
        } catch (error: Exception) {
            Log.w(LOG_TAG, "Invalid heartbeat payload", error)
        }
    }

    private fun saveSnapshot(data: DataMap, liveHeartbeat: Boolean) = synchronized(STORE_LOCK) {
        val now = System.currentTimeMillis()
        val preferences = getSharedPreferences(HEART_RATE_PREFERENCES, Context.MODE_PRIVATE)
        val editor = preferences.edit()
        val bpm = data.getInt(HEART_RATE_KEY_BPM, -1)
        val measuredAt = data.getLong(HEART_RATE_KEY_MEASURED_AT, 0L)
        val newMeasurement = bpm in 1..1000 && WatchFreshness.shouldReplaceMeasurement(
                measuredAt, preferences.getLong(HEART_RATE_KEY_MEASURED_AT, 0L), now,
            )
        if (newMeasurement) {
            editor.putInt(HEART_RATE_KEY_BPM, bpm)
                .putLong(HEART_RATE_KEY_MEASURED_AT, measuredAt)
        }

        val sentAt = data.getLong(WATCH_KEY_SENT_AT, measuredAt)
        if (sentAt > 0L && sentAt >= preferences.getLong(WATCH_KEY_SNAPSHOT_AT, 0L)) {
            editor.putLong(WATCH_KEY_SNAPSHOT_AT, sentAt)
                .putInt(HEART_RATE_KEY_BATTERY_PERCENT, data.getInt(HEART_RATE_KEY_BATTERY_PERCENT, -1))
                .putBoolean(HEART_RATE_KEY_CHARGING, data.getBoolean(HEART_RATE_KEY_CHARGING, false))
        }
        if (liveHeartbeat) {
            if (sentAt >= preferences.getLong("wearing_snapshot_at", 0L)) {
                val state = data.getString("wearing_state") ?: "unknown"
                val since = data.getLong("wearing_since_ms", 0L)
                val valid = state in setOf("on", "off") && since in 1L..sentAt
                editor.putString("wearing_state", if (valid) state else "unknown")
                    .putLong("wearing_since_ms", if (valid) since else 0L)
                    .putLong("wearing_snapshot_at", sentAt)
            }
            // Phone reception time + monotonic clock; NOT the measurement time.
            editor.putLong(WATCH_KEY_LAST_CONTACT_AT, now)
                .putLong(WATCH_KEY_LAST_CONTACT_ELAPSED, SystemClock.elapsedRealtime())
                .putInt(WATCH_KEY_LAST_CONTACT_BOOT_COUNT, phoneBootCount(this))
                .putString(WATCH_KEY_MONITORING_STATUS, data.getString(WATCH_KEY_MONITORING_STATUS) ?: "unknown")
        }
        editor.apply()
        if (liveHeartbeat || newMeasurement) {
            try {
                TelemetryOutbox.get(this).enqueue(data, liveHeartbeat, now,
                    if (liveHeartbeat) data.getString(WATCH_KEY_MONITORING_STATUS) ?: "unknown"
                    else preferences.getString(WATCH_KEY_MONITORING_STATUS, "unknown") ?: "unknown")
                ServerUploadService.packetReady()
            } catch (_: Exception) {
                getSharedPreferences("server_upload", Context.MODE_PRIVATE).edit()
                    .putString("last_error", "Не удалось записать пакет в очередь телефона").apply()
                Log.e(LOG_TAG, "Cannot persist telemetry packet")
            }
        }
    }
}

internal fun phoneBootCount(context: Context): Int =
    Settings.Global.getInt(context.contentResolver, Settings.Global.BOOT_COUNT, -1)

private val STORE_LOCK = Any()
private const val LOG_TAG = "GrandmaHealthMobile"
internal const val HEART_RATE_PATH = "/heart-rate/latest"
internal const val WATCH_HEARTBEAT_PATH = "/watch/heartbeat"
internal const val HEART_RATE_PREFERENCES = "heart_rate"
internal const val HEART_RATE_KEY_BPM = "bpm"
internal const val HEART_RATE_KEY_MEASURED_AT = "measured_at"
internal const val HEART_RATE_KEY_BATTERY_PERCENT = "battery_percent"
internal const val HEART_RATE_KEY_CHARGING = "charging"
internal const val WATCH_KEY_SENT_AT = "sent_at"
internal const val WATCH_KEY_SNAPSHOT_AT = "snapshot_at"
internal const val WATCH_KEY_LAST_CONTACT_AT = "last_contact_at"
internal const val WATCH_KEY_LAST_CONTACT_ELAPSED = "last_contact_elapsed"
internal const val WATCH_KEY_LAST_CONTACT_BOOT_COUNT = "last_contact_boot_count"
internal const val WATCH_KEY_MONITORING_STATUS = "monitoring_status"
