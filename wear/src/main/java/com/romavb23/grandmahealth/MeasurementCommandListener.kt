package com.romavb23.grandmahealth

import android.util.Log
import com.google.android.gms.wearable.DataMap
import com.google.android.gms.wearable.MessageEvent
import com.google.android.gms.wearable.WearableListenerService
import java.util.UUID

class MeasurementCommandListener : WearableListenerService() {
    override fun onMessageReceived(event: MessageEvent) {
        if (event.path != MEASUREMENT_REQUEST_PATH || event.data.size > 1024) return
        try {
            val id = DataMap.fromByteArray(event.data).getString("request_id") ?: return
            if (UUID.fromString(id).toString() != id) return
            MonitoringForegroundService.requestMeasurement(this, id, event.sourceNodeId)
        } catch (_: Exception) { Log.w("GrandmaMeasure", "Invalid spot command") }
    }
}
