package com.romavb23.grandmahealth

import android.content.Context
import android.os.Handler
import android.os.PowerManager
import android.hardware.Sensor
import android.hardware.SensorEvent
import android.hardware.SensorEventListener
import android.hardware.SensorManager
import android.os.SystemClock
import android.util.Log
import com.google.android.gms.wearable.Wearable
import kotlin.math.roundToInt

internal const val MEASUREMENT_REQUEST_PATH = "/watch/measure/request"
internal const val MEASUREMENT_RESPONSE_PATH = "/watch/measure/response"

/** Short SensorManager request in the existing health FGS; no Activity or screen wake-up. */
internal class SpotMeasurement(private val context: Context, private val handler: Handler) {
    private val manager = context.getSystemService(SensorManager::class.java)
    private val power = context.getSystemService(PowerManager::class.java)
    private var pending: Session? = null
    private var lastStart = -SpotMeasurementRules.COOLDOWN_MS
    private val handled = LinkedHashSet<String>()

    private class Session(val id: String?, val node: String?, val startedNanos: Long, val origin: String) {
        val started = startedNanos / 1_000_000L
        lateinit var listener: SensorEventListener
        lateinit var timeout: Runnable
        var wakeLock: PowerManager.WakeLock? = null
        var events = 0
        var rejected = 0
    }

    /** Called only on the watch main thread by its running monitoring service. */
    fun request(id: String, node: String) {
        begin(id, node, "remote")
    }

    /** Uses the same sensor slot as remote requests; automatic results are ordinary measurements. */
    fun requestAutomatic(reason: String): Long? {
        val status = begin(null, null, reason)
        WatchStateStore.preferences(context).edit()
            .putString("auto_last_reason", reason).putString("auto_last_attempt_status", status)
            .putLong("auto_last_attempt_at", System.currentTimeMillis()).apply()
        Log.i("GrandmaAutoHR", "Automatic request reason=$reason result=$status interactive=${power?.isInteractive}")
        return if (status == "cooldown") {
            (SpotMeasurementRules.COOLDOWN_MS - (SystemClock.elapsedRealtime() - lastStart)).coerceAtLeast(1L)
        } else null
    }

    private fun begin(id: String?, node: String?, origin: String): String {
        fun reject(status: String): String {
            if (id != null && node != null) sendMeasurementResponse(context, node, id, status)
            return status
        }
        if (!WatchStateStore.isEnabled(context)) return reject("monitoring_stopped")
        pending?.let {
            return reject(if (id != null && it.id == id) "measuring" else "busy")
        }
        if (id != null && id in handled) return reject("duplicate_request")
        val wearing = WatchStateStore.wearing(context).first
        if (wearing != "on") {
            return reject(if (wearing == "off") "off_body" else "wearing_unknown")
        }
        val now = SystemClock.elapsedRealtime()
        if (now - lastStart < SpotMeasurementRules.COOLDOWN_MS) {
            return reject("cooldown")
        }
        lastStart = now
        if (id != null) {
            handled.add(id)
            if (handled.size > 16) handled.remove(handled.first())
        }
        val session = Session(id, node, SystemClock.elapsedRealtimeNanos(), origin)
        pending = session
        session.timeout = Runnable { finish(session, "timeout") }
        session.listener = object : SensorEventListener {
            override fun onAccuracyChanged(sensor: Sensor?, accuracy: Int) {
                if (pending === session) Log.i("GrandmaMeasure", "Direct HR accuracy=$accuracy interactive=${power?.isInteractive}")
            }

            override fun onSensorChanged(event: SensorEvent) {
                if (pending !== session || event.sensor.type != Sensor.TYPE_HEART_RATE) return
                if (!WatchStateStore.isEnabled(context)) { finish(session, "cancelled"); return }
                if (WatchStateStore.wearing(context).first != "on") { finish(session, "off_body"); return }
                val value = event.values.firstOrNull()?.toDouble() ?: return
                val receivedNanos = SystemClock.elapsedRealtimeNanos()
                val sampled = event.timestamp / 1_000_000L
                val received = receivedNanos / 1_000_000L
                session.events++
                val accepted = SpotMeasurementRules.acceptsSensorSample(value, event.accuracy,
                    session.startedNanos, event.timestamp, receivedNanos)
                if (!accepted) session.rejected++
                WatchStateStore.preferences(context).edit()
                    .putInt("spot_events", session.events).putInt("spot_rejected", session.rejected)
                    .putInt("spot_last_accuracy", event.accuracy)
                    .putLong("spot_last_sample_age_ms", received - sampled).apply()
                Log.i("GrandmaMeasure", "Direct HR event accuracy=${event.accuracy} age_ms=${received - sampled} accepted=$accepted interactive=${power?.isInteractive}")
                if (!accepted) return
                val measuredAt = System.currentTimeMillis() - (received - sampled)
                finish(session, "success", value.roundToInt(), measuredAt, sampled)
            }
        }
        WatchStateStore.preferences(context).edit().putString("spot_backend", "sensor_manager")
            .putString("spot_origin", origin)
            .putString("spot_last_status", "starting").putInt("spot_events", 0).putInt("spot_rejected", 0)
            .putInt("spot_last_accuracy", -2).putLong("spot_last_sample_age_ms", -1L)
            .putBoolean("spot_started_interactive", power?.isInteractive == true).apply()
        handler.postDelayed(session.timeout, SpotMeasurementRules.TIMEOUT_MS)
        try {
            // Prefer a wake-up sensor (CPU wake-up only), if this watch exposes one.
            val sensor = manager?.getDefaultSensor(Sensor.TYPE_HEART_RATE, true)
                ?: manager?.getDefaultSensor(Sensor.TYPE_HEART_RATE)
            if (sensor == null) { finish(session, "unsupported"); return "unsupported" }
            session.wakeLock = power?.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "GrandmaHealth:spot")
                ?.apply { setReferenceCounted(false); acquire(SpotMeasurementRules.TIMEOUT_MS + 5_000L) }
            val registered = manager?.registerListener(session.listener, sensor, 1_000_000, 0, handler) == true
            if (!registered) { finish(session, "sensor_error"); return "sensor_error" }
            WatchStateStore.preferences(context).edit().putString("spot_last_status", "measuring").apply()
            if (id != null && node != null) sendMeasurementResponse(context, node, id, "measuring")
            Log.i("GrandmaMeasure", "Direct HR registered wake_up=${sensor.isWakeUpSensor} mode=${sensor.reportingMode} interactive=${power?.isInteractive}")
        } catch (_: SecurityException) { finish(session, "permission_lost"); return "permission_lost" }
        catch (_: Exception) { finish(session, "sensor_error"); return "sensor_error" }
        return "measuring"
    }

    fun onWearingChanged() {
        pending?.let {
            val wearing = WatchStateStore.wearing(context).first
            if (wearing != "on") finish(it, if (wearing == "off") "off_body" else "wearing_unknown")
        }
    }

    fun cancel() { pending?.let { finish(it, "cancelled") } }

    private fun finish(session: Session, status: String, bpm: Int = 0, measuredAt: Long = 0L, sampled: Long = 0L) {
        if (pending !== session) return
        pending = null
        handler.removeCallbacks(session.timeout)
        unregister(session)
        if (status == "success") {
            sendHeartRateToPhone(context, bpm, measuredAt)
            if (session.node == null) sendWatchHeartbeat(context)
        }
        if (session.node != null && session.id != null) {
            sendMeasurementResponse(context, session.node, session.id, status, bpm, measuredAt, session.started, sampled)
        } else {
            WatchStateStore.preferences(context).edit()
                .putString("auto_last_status", status).putLong("auto_last_finished_at", System.currentTimeMillis())
                .putLong("auto_last_measured_at", if (status == "success") measuredAt else 0L).apply()
            Log.i("GrandmaAutoHR", "Automatic result reason=${session.origin} status=$status bpm=${if (status == "success") bpm else 0} interactive=${power?.isInteractive}")
        }
        WatchStateStore.preferences(context).edit().putString("spot_last_status", status)
            .putBoolean("spot_finished_interactive", power?.isInteractive == true).apply()
        Log.i("GrandmaMeasure", "Direct HR request finished: $status events=${session.events} rejected=${session.rejected} interactive=${power?.isInteractive}")
    }

    private fun unregister(session: Session) {
        try { manager?.unregisterListener(session.listener) }
        catch (_: Exception) { Log.w("GrandmaMeasure", "Direct HR unregistration failed") }
        session.wakeLock?.let {
            try { if (it.isHeld) it.release() }
            catch (_: Exception) { Log.w("GrandmaMeasure", "Spot CPU lock release failed") }
        }
    }

}

internal fun sendMeasurementResponse(context: Context, node: String, id: String, status: String,
    bpm: Int = 0, measuredAt: Long = 0L, started: Long = 0L, sampled: Long = 0L) {
    val data = watchSnapshot(context).apply {
        putString("request_id", id)
        putString("request_status", status)
        if (status == "success") {
            putInt("bpm", bpm)
            putLong("measured_at", measuredAt)
            putLong("request_started_elapsed", started)
            putLong("sample_elapsed", sampled)
        }
    }
    try {
        Wearable.getMessageClient(context.applicationContext).sendMessage(node, MEASUREMENT_RESPONSE_PATH, data.toByteArray())
            .addOnFailureListener { Log.w("GrandmaMeasure", "Could not send spot response") }
    } catch (_: Exception) { Log.w("GrandmaMeasure", "Could not send spot response") }
}
