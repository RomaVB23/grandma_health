package com.romavb23.grandmahealth

import android.content.Context
import com.google.android.gms.wearable.DataEvent
import com.google.android.gms.wearable.DataEventBuffer
import com.google.android.gms.wearable.DataMapItem
import com.google.android.gms.wearable.WearableListenerService

class HeartRateDataLayerListenerService : WearableListenerService() {
    override fun onDataChanged(dataEvents: DataEventBuffer) {
        dataEvents.forEach { event ->
            if (
                event.type == DataEvent.TYPE_CHANGED &&
                event.dataItem.uri.path == HEART_RATE_PATH
            ) {
                val dataMap = DataMapItem.fromDataItem(event.dataItem).dataMap
                val bpm = dataMap.getInt(HEART_RATE_KEY_BPM, -1)
                val measuredAt = dataMap.getLong(HEART_RATE_KEY_MEASURED_AT, 0L)
                val batteryPercent = dataMap.getInt(HEART_RATE_KEY_BATTERY_PERCENT, -1)
                val charging = dataMap.getBoolean(HEART_RATE_KEY_CHARGING, false)

                if (bpm > 0 && measuredAt > 0L) {
                    val preferences =
                        getSharedPreferences(HEART_RATE_PREFERENCES, Context.MODE_PRIVATE)
                    val previousMeasuredAt =
                        preferences.getLong(HEART_RATE_KEY_MEASURED_AT, 0L)

                    if (measuredAt >= previousMeasuredAt) {
                        preferences
                            .edit()
                            .putInt(HEART_RATE_KEY_BPM, bpm)
                            .putLong(HEART_RATE_KEY_MEASURED_AT, measuredAt)
                            .putInt(HEART_RATE_KEY_BATTERY_PERCENT, batteryPercent)
                            .putBoolean(HEART_RATE_KEY_CHARGING, charging)
                            .apply()
                    }
                }
            }
        }
    }
}

internal const val HEART_RATE_PATH = "/heart-rate/latest"
internal const val HEART_RATE_PREFERENCES = "heart_rate"
internal const val HEART_RATE_KEY_BPM = "bpm"
internal const val HEART_RATE_KEY_MEASURED_AT = "measured_at"
internal const val HEART_RATE_KEY_BATTERY_PERCENT = "battery_percent"
internal const val HEART_RATE_KEY_CHARGING = "charging"
