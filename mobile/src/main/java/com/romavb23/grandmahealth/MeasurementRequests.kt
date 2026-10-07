package com.romavb23.grandmahealth

import android.content.Context
import android.os.SystemClock
import android.util.Log
import com.google.android.gms.wearable.DataMap
import com.google.android.gms.wearable.Wearable
import java.util.UUID

internal const val MEASUREMENT_REQUEST_PATH = "/watch/measure/request"
internal const val MEASUREMENT_RESPONSE_PATH = "/watch/measure/response"

internal data class MeasurementRequestState(
    val status: String = "idle", val startedElapsed: Long = 0L,
    val bpm: Int = 0, val measuredAt: Long = 0L,
    val cooldownRemaining: Long = 0L,
) {
    val pending get() = MeasurementRequestRules.isPending(status)
}

/** Persistent correlation survives Activity recreation; deadlines use this phone's boot clock. */
internal object MeasurementRequests {
    private fun prefs(context: Context) = context.getSharedPreferences("measurement_request", Context.MODE_PRIVATE)

    @Synchronized
    fun state(context: Context): MeasurementRequestState {
        expire(context)
        val p = prefs(context)
        val elapsed = SystemClock.elapsedRealtime() - p.getLong("started_elapsed", 0L)
        val cooldown = if (p.contains("started_elapsed") && p.getInt("boot", -1) == phoneBootCount(context) && elapsed >= 0L)
            (MeasurementRequestRules.COOLDOWN_MS - elapsed).coerceAtLeast(0L) else 0L
        return MeasurementRequestState(p.getString("status", "idle") ?: "idle",
            p.getLong("started_elapsed", 0L), p.getInt("bpm", 0), p.getLong("measured_at", 0L), cooldown)
    }

    @Synchronized
    fun request(context: Context) {
        val app = context.applicationContext
        expire(app)
        val p = prefs(app)
        if (MeasurementRequestRules.isPending(p.getString("status", "idle") ?: "idle")) return
        val now = SystemClock.elapsedRealtime()
        val boot = phoneBootCount(app)
        if (boot < 0) { p.edit().putString("status", "clock_unavailable").apply(); return }
        if (p.contains("started_elapsed") && p.getInt("boot", -1) == boot &&
            now - p.getLong("started_elapsed", 0L) in 0L until MeasurementRequestRules.COOLDOWN_MS) return
        val id = UUID.randomUUID().toString()
        p.edit().clear().putString("id", id).putString("status", "sending")
            .putLong("started_elapsed", now).putInt("boot", boot).apply()
        try {
            Wearable.getNodeClient(app).connectedNodes.addOnSuccessListener { nodes ->
                synchronized(this) {
                    if (!current(app, id)) return@addOnSuccessListener
                    if (nodes.size != 1) {
                        fail(app, id, if (nodes.isEmpty()) "no_connection" else "multiple_watches")
                        return@addOnSuccessListener
                    }
                    val node = nodes.single()
                    p.edit().putString("node_id", node.id).apply()
                    val command = DataMap().apply { putString("request_id", id) }.toByteArray()
                    try {
                        Wearable.getMessageClient(app).sendMessage(node.id, MEASUREMENT_REQUEST_PATH, command)
                            .addOnFailureListener { fail(app, id, "send_failed") }
                    } catch (_: Exception) { fail(app, id, "send_failed") }
                }
            }.addOnFailureListener { fail(app, id, "no_connection") }
        } catch (_: Exception) { fail(app, id, "no_connection") }
    }

    /** True only for a correlated, timely success: the caller may then persist its telemetry. */
    @Synchronized
    fun receive(context: Context, sourceNodeId: String, data: DataMap): Boolean {
        expire(context)
        val p = prefs(context)
        val id = data.getString("request_id") ?: return false
        if (!current(context, id) || !MeasurementRequestRules.matches(p.getString("id", "") ?: "", id,
                p.getString("node_id", "") ?: "", sourceNodeId)) return false
        val status = data.getString("request_status") ?: return false
        if (status == "measuring") { p.edit().putString("status", status).apply(); return false }
        if (status == "success") {
            val bpm = data.getInt("bpm", -1)
            val measuredAt = data.getLong("measured_at", 0L)
            if (!WatchFreshness.heartbeatIsTimely(data.getLong("sent_at", 0L), System.currentTimeMillis()) ||
                !MeasurementRequestRules.isNewSample(bpm, data.getLong("request_started_elapsed", -1L),
                    data.getLong("sample_elapsed", -1L), measuredAt, System.currentTimeMillis())) {
                fail(context, id, "invalid_result")
                return false
            }
            p.edit().putString("status", "success").putInt("bpm", bpm).putLong("measured_at", measuredAt).apply()
            Log.i("GrandmaMeasure", "New requested measurement received")
            return true
        }
        if (status in setOf("off_body", "wearing_unknown", "monitoring_stopped", "permission_lost",
                "unsupported", "sensor_error", "timeout", "busy", "cooldown", "cancelled", "duplicate_request")) {
            fail(context, id, status)
        }
        return false
    }

    @Synchronized
    private fun expire(context: Context) {
        val p = prefs(context)
        val status = p.getString("status", "idle") ?: "idle"
        if (MeasurementRequestRules.isPending(status) && !MeasurementRequestRules.isAlive(status,
                p.getInt("boot", -1), phoneBootCount(context), p.getLong("started_elapsed", -1L),
                SystemClock.elapsedRealtime())) {
            p.edit().putString("status", "timeout").apply()
        }
    }

    private fun current(context: Context, id: String): Boolean {
        expire(context)
        val p = prefs(context)
        return p.getString("id", "") == id && MeasurementRequestRules.isPending(p.getString("status", "idle") ?: "idle")
    }

    @Synchronized
    private fun fail(context: Context, id: String, status: String) {
        if (current(context, id)) {
            prefs(context).edit().putString("status", status).apply()
            Log.i("GrandmaMeasure", "Measurement request ended: $status")
        }
    }
}
