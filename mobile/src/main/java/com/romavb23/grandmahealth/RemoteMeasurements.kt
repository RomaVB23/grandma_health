package com.romavb23.grandmahealth

import android.content.Context
import android.os.PowerManager
import android.os.SystemClock
import android.util.Log
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL

/** One persistent reply slot, independent of the local UI's latest request. */
internal object RemoteMeasurements {
    private fun prefs(context: Context) = context.getSharedPreferences("remote_measurement", Context.MODE_PRIVATE)

    @Synchronized
    fun capture(context: Context, id: String, status: String, bpm: Int = 0, measuredAt: Long = 0L, sampleAge: Long = 0L) {
        val p = prefs(context)
        if (p.getString("id", "") != id || p.contains("result")) return
        val body = JSONObject().put("status", status)
        if (status == "success") body.put("bpm", bpm).put("measured_at_ms", measuredAt)
            .put("sample_after_request_ms", sampleAge)
        check(p.edit().putString("result", body.toString()).commit())
    }

    fun run(context: Context, running: () -> Boolean) {
        val settings = UploadSettings(context)
        while (running()) {
            val lock = context.getSystemService(PowerManager::class.java)
                .newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "GrandmaHealth:RemoteRequest")
            try {
                // Bounded CPU lock allows outgoing long polling while the phone screen sleeps.
                // No screen lock; only active while the user has enabled server transmission.
                lock.acquire(35_000L)
                cycle(context, settings, running)
                settings.preferences.edit().putString("remote_error", "")
                    .putLong("remote_checked_at", System.currentTimeMillis()).apply()
            } catch (_: Exception) {
                settings.preferences.edit().putString("remote_error", "Не удалось проверить запросы сервера. Повторим автоматически").apply()
                if (running()) Thread.sleep(5_000L)
            } finally {
                if (lock.isHeld) lock.release()
            }
        }
    }

    private fun cycle(context: Context, settings: UploadSettings, running: () -> Boolean) {
        val p = prefs(context)
        val address = UploadPolicy.endpoint(settings.endpoint)
        val token = settings.token()
        check(token.isNotBlank())
        val id = p.getString("id", "") ?: ""
        if (id.isNotEmpty()) {
            if (p.getString("origin", "") != address) {
                check(p.edit().remove("id").remove("result").remove("boot").remove("deadline_elapsed").remove("origin").commit())
                return // Changing the configured server must not send an old reply to the new one.
            }
            if (p.getInt("boot", -1) != phoneBootCount(context)) capture(context, id, "phone_restarted")
            // Expiration is evaluated even when the Activity has never been opened.
            MeasurementRequests.state(context)
            if (!p.contains("result") && RemoteMeasurementRules.expired(p.getInt("boot", -1), phoneBootCount(context),
                    p.getLong("deadline_elapsed", 0L), SystemClock.elapsedRealtime())) {
                capture(context, id, if (p.getInt("boot", -1) != phoneBootCount(context)) "phone_restarted" else "timeout")
            }
            val result = p.getString("result", null)
            if (result != null) {
                val reply = call(address, token, "/api/v1/measurement-requests/$id/result", result)
                check(reply.getString("id") == id && !reply.getBoolean("pending"))
                check(p.edit().remove("id").remove("result").remove("boot").remove("deadline_elapsed").remove("origin").commit())
                Log.i("GrandmaRemote", "Requested result acknowledged by server")
                return
            }
        }
        if (id.isNotEmpty()) { Thread.sleep(500L); return }
        val started = SystemClock.elapsedRealtime()
        val reply = call(address, token, "/api/v1/measurement-requests/claim?wait=20", "{}")
        val command = reply.optJSONObject("request") ?: return
        if (!running() || address != UploadPolicy.endpoint(settings.endpoint) || token != settings.token()) return
        val commandId = command.getString("id")
        val remaining = command.getLong("remaining_ms") - (SystemClock.elapsedRealtime() - started)
        if (!RemoteMeasurementRules.validCommand(commandId, remaining, reply.getLong("server_time_ms"), System.currentTimeMillis())) {
            // A malformed or delayed command must never start a sensor measurement.
            Log.w("GrandmaRemote", "Delayed or invalid command rejected")
            return
        }
        val seen = p.getStringSet("seen", emptySet())!!.toMutableSet()
        val repeated = commandId in seen
        seen.add(commandId)
        while (seen.size > 32) seen.remove(seen.first())
        check(p.edit().putString("id", commandId).putInt("boot", phoneBootCount(context))
            .putLong("deadline_elapsed", SystemClock.elapsedRealtime() + remaining).putString("origin", address)
            .putStringSet("seen", seen).commit())
        if (repeated) { capture(context, commandId, "duplicate_request"); return }
        val accepted = MeasurementRequests.request(context, commandId)
        if (accepted != "sending") capture(context, commandId, accepted)
        Log.i("GrandmaRemote", "Server request handled")
    }

    private fun call(address: String, token: String, path: String, body: String): JSONObject {
        val connection = URL(address + path).openConnection() as HttpURLConnection
        try {
            connection.requestMethod = "POST"
            connection.connectTimeout = 5_000
            connection.readTimeout = 25_000
            connection.instanceFollowRedirects = false
            connection.setRequestProperty("Authorization", "Bearer $token")
            connection.setRequestProperty("Accept", "application/json")
            connection.setRequestProperty("Content-Type", "application/json; charset=utf-8")
            val bytes = body.toByteArray(Charsets.UTF_8)
            connection.doOutput = true
            connection.setFixedLengthStreamingMode(bytes.size)
            connection.outputStream.use { it.write(bytes) }
            check(connection.responseCode == 200)
            return connection.inputStream.use {
                val buffer = ByteArray(16_385)
                var used = 0
                while (used < buffer.size) {
                    val count = it.read(buffer, used, buffer.size - used)
                    if (count < 0) break
                    used += count
                }
                check(used <= 16_384)
                JSONObject(String(buffer, 0, used, Charsets.UTF_8))
            }
        } finally { connection.disconnect() }
    }
}
