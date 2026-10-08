package com.romavb23.grandmahealth

import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.os.BatteryManager
import android.os.PowerManager
import android.os.SystemClock
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL

/** Latest phone sample, never queued with historical watch events. Called on the upload worker. */
internal class PhoneBatteryReporter(private val context: Context, private val settings: UploadSettings) {
    private var lastAttempt: Long? = null

    fun publish() {
        if (!settings.enabled || !PhoneBatteryPolicy.due(SystemClock.elapsedRealtime(), lastAttempt)) return
        lastAttempt = SystemClock.elapsedRealtime()
        val lock = context.getSystemService(PowerManager::class.java)
            .newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "GrandmaHealth:PhoneBattery")
        try {
            lock.acquire(15_000L)
            val battery = context.registerReceiver(null, IntentFilter(Intent.ACTION_BATTERY_CHANGED))
                ?: error("Battery unavailable")
            val percent = PhoneBatteryPolicy.percent(battery.getIntExtra(BatteryManager.EXTRA_LEVEL, -1),
                battery.getIntExtra(BatteryManager.EXTRA_SCALE, -1)) ?: error("Battery unavailable")
            val plugged = battery.getIntExtra(BatteryManager.EXTRA_PLUGGED, -1)
            check(plugged >= 0) { "Charging state unavailable" }
            val endpoint = UploadPolicy.endpoint(settings.endpoint)
            val token = settings.token()
            check(token.isNotBlank()) { "Token unavailable" }
            val body = JSONObject().put("device_id", "grandma-watch").put("battery_percent", percent)
                .put("charging", plugged != 0).put("snapshot_at_ms", System.currentTimeMillis())
                .toString().toByteArray(Charsets.UTF_8)
            val connection = URL("$endpoint/api/v1/phone-status").openConnection() as HttpURLConnection
            try {
                connection.requestMethod = "POST"
                connection.connectTimeout = 4_000
                connection.readTimeout = 6_000
                connection.instanceFollowRedirects = false
                connection.setRequestProperty("Authorization", "Bearer $token")
                connection.setRequestProperty("Accept", "application/json")
                connection.setRequestProperty("Content-Type", "application/json; charset=utf-8")
                connection.doOutput = true
                connection.setFixedLengthStreamingMode(body.size)
                connection.outputStream.use { it.write(body) }
                val code = connection.responseCode
                if (code == 204) {
                    settings.preferences.edit().putLong("phone_battery_last_ack_at", System.currentTimeMillis())
                        .putString("phone_battery_last_error", "").apply()
                } else {
                    settings.preferences.edit().putString("phone_battery_last_error",
                        "HTTP $code: заряд телефона не подтверждён сервером").apply()
                }
            } finally { connection.disconnect() }
        } catch (_: Exception) {
            // Keep this diagnostic separate: a battery response cannot acknowledge a watch packet.
            settings.preferences.edit().putString("phone_battery_last_error",
                "Не удалось передать заряд телефона. Повторим позже").apply()
        } finally {
            if (lock.isHeld) lock.release()
        }
    }
}
