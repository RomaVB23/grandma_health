package com.romavb23.grandmahealth

import android.content.Context
import android.util.Log
import com.google.android.gms.wearable.PutDataMapRequest
import com.google.android.gms.wearable.Wearable

internal fun sendHeartRateToPhone(
    context: Context,
    bpm: Int,
    measuredAt: Long,
) {
    val request =
        PutDataMapRequest.create(HEART_RATE_PATH).run {
            dataMap.putInt(HEART_RATE_KEY_BPM, bpm)
            dataMap.putLong(HEART_RATE_KEY_MEASURED_AT, measuredAt)
            asPutDataRequest().setUrgent()
        }

    Wearable.getDataClient(context.applicationContext)
        .putDataItem(request)
        .addOnFailureListener { error ->
            Log.e(HEART_RATE_LOG_TAG, "Не удалось передать пульс на телефон", error)
        }
}

private const val HEART_RATE_PATH = "/heart-rate/latest"
private const val HEART_RATE_KEY_BPM = "bpm"
private const val HEART_RATE_KEY_MEASURED_AT = "measured_at"
private const val HEART_RATE_LOG_TAG = "GrandmaHealthWear"
