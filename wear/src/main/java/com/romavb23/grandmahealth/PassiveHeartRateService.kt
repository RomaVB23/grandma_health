package com.romavb23.grandmahealth

import android.os.SystemClock
import android.util.Log
import androidx.health.services.client.PassiveListenerService
import androidx.health.services.client.data.DataPointContainer
import androidx.health.services.client.data.DataType
import java.time.Instant
import kotlin.math.roundToInt

class PassiveHeartRateService : PassiveListenerService() {
    override fun onNewDataPointsReceived(dataPoints: DataPointContainer) {
        val latestHeartRate =
            dataPoints
                .getData(DataType.HEART_RATE_BPM)
                .maxByOrNull { it.timeDurationFromBoot }
                ?: return

        val bootInstant =
            Instant.ofEpochMilli(System.currentTimeMillis() - SystemClock.elapsedRealtime())
        val measuredAt = latestHeartRate.getTimeInstant(bootInstant).toEpochMilli()

        sendHeartRateToPhone(
            context = this,
            bpm = latestHeartRate.value.roundToInt(),
            measuredAt = measuredAt,
        )
    }

    override fun onPermissionLost() {
        Log.w(LOG_TAG, "Разрешение на фоновое чтение пульса отозвано")
    }
}

private const val LOG_TAG = "PassiveHeartRate"
