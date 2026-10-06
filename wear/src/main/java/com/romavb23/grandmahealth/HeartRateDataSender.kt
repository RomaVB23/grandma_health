package com.romavb23.grandmahealth

import android.content.Context
import android.os.BatteryManager
import android.util.Log
import com.google.android.gms.wearable.PutDataMapRequest
import com.google.android.gms.wearable.DataMap
import com.google.android.gms.wearable.Wearable

internal fun sendHeartRateToPhone(
    context: Context,
    bpm: Int,
    measuredAt: Long,
) {
    if (!WatchStateStore.saveHeartRate(context, bpm, measuredAt)) return
    val batteryManager = context.getSystemService(BatteryManager::class.java)
    val batteryPercent =
        batteryManager
            ?.getIntProperty(BatteryManager.BATTERY_PROPERTY_CAPACITY)
            ?.takeIf { it in 0..100 }
            ?: -1
    val charging = batteryManager?.isCharging == true

    val request =
        PutDataMapRequest.create(HEART_RATE_PATH).run {
            dataMap.putInt(HEART_RATE_KEY_BPM, bpm)
            dataMap.putLong(HEART_RATE_KEY_MEASURED_AT, measuredAt)
            dataMap.putLong("sent_at", System.currentTimeMillis())
            dataMap.putInt(HEART_RATE_KEY_BATTERY_PERCENT, batteryPercent)
            dataMap.putBoolean(HEART_RATE_KEY_CHARGING, charging)
            val (wearing, since) = WatchStateStore.wearing(context)
            dataMap.putString("wearing_state", wearing)
            dataMap.putLong("wearing_since_ms", since)
            asPutDataRequest().setUrgent()
        }

    Wearable.getDataClient(context.applicationContext)
        .putDataItem(request)
        .addOnFailureListener { error ->
            Log.e(HEART_RATE_LOG_TAG, "Не удалось передать пульс на телефон", error)
        }
}

/** MessageClient doesn't queue offline packets: reception means a live exchange. */
internal fun sendWatchHeartbeat(context: Context) {
    val app = context.applicationContext
    val battery = app.getSystemService(BatteryManager::class.java)
    val (bpm, measuredAt) = WatchStateStore.heartRate(app)
    val message = DataMap().apply {
        putLong("sent_at", System.currentTimeMillis())
        putInt("bpm", bpm)
        putLong("measured_at", measuredAt)
        putInt("battery_percent", battery?.getIntProperty(BatteryManager.BATTERY_PROPERTY_CAPACITY)
            ?.takeIf { it in 0..100 } ?: -1)
        putBoolean("charging", battery?.isCharging == true)
        putString("monitoring_status", WatchStateStore.status(app))
        val (wearing, since) = WatchStateStore.wearing(app)
        putString("wearing_state", wearing)
        putLong("wearing_since_ms", since)
    }.toByteArray()
    Wearable.getNodeClient(app).connectedNodes
        .addOnSuccessListener { nodes ->
            if (nodes.isEmpty()) Log.w(HEART_RATE_LOG_TAG, "Телефон не подключён; heartbeat не отправлен")
            nodes.forEach { node ->
                Wearable.getMessageClient(app).sendMessage(node.id, "/watch/heartbeat", message)
                    .addOnFailureListener { error ->
                        Log.w(HEART_RATE_LOG_TAG, "Не удалось передать heartbeat", error)
                    }
            }
        }
        .addOnFailureListener { error ->
            Log.w(HEART_RATE_LOG_TAG, "Не удалось получить подключённые устройства", error)
        }
}

private const val HEART_RATE_PATH = "/heart-rate/latest"
private const val HEART_RATE_KEY_BPM = "bpm"
private const val HEART_RATE_KEY_MEASURED_AT = "measured_at"
private const val HEART_RATE_KEY_BATTERY_PERCENT = "battery_percent"
private const val HEART_RATE_KEY_CHARGING = "charging"
private const val HEART_RATE_LOG_TAG = "GrandmaHealthWear"
