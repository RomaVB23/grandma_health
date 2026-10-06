package com.romavb23.grandmahealth

import android.content.Context
import android.hardware.Sensor
import android.hardware.SensorEvent
import android.hardware.SensorEventListener
import android.hardware.SensorManager
import android.util.Log

/** An on-change sensor: keep registered in the foreground service, including screen-off. */
internal class WearingSensor(private val context: Context, private val changed: () -> Unit) : SensorEventListener {
    private val manager = context.getSystemService(SensorManager::class.java)

    fun start() {
        WatchStateStore.setWearing(context, "unknown")
        try {
            val sensor = manager?.getDefaultSensor(Sensor.TYPE_LOW_LATENCY_OFFBODY_DETECT)
            if (sensor == null || manager?.registerListener(this, sensor, SensorManager.SENSOR_DELAY_NORMAL) != true) {
                Log.w("GrandmaWearing", "Off-body sensor unavailable; wearing stays unknown")
            }
        } catch (_: Exception) {
            Log.w("GrandmaWearing", "Off-body sensor registration failed; wearing stays unknown")
        }
    }

    override fun onSensorChanged(event: SensorEvent) {
        val state = when (event.values.firstOrNull()) {
            1.0f -> "on"
            0.0f -> "off"
            else -> "unknown"
        }
        if (WatchStateStore.setWearing(context, state)) changed()
    }

    override fun onAccuracyChanged(sensor: Sensor?, accuracy: Int) = Unit

    fun stop() {
        manager?.unregisterListener(this)
        WatchStateStore.setWearing(context, "unknown")
    }
}
