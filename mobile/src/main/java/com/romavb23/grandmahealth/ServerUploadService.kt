package com.romavb23.grandmahealth

import android.Manifest
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.content.pm.ServiceInfo
import android.net.ConnectivityManager
import android.net.Network
import android.os.Handler
import android.os.IBinder
import android.os.Looper
import android.os.PowerManager
import android.os.SystemClock
import android.util.Log
import androidx.core.app.NotificationCompat
import androidx.core.content.ContextCompat
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL
import java.util.concurrent.Executors
import java.util.concurrent.atomic.AtomicBoolean

class ServerUploadService : Service() {
    private val handler = Handler(Looper.getMainLooper())
    private val worker = Executors.newSingleThreadExecutor()
    private val busy = AtomicBoolean(false)
    private lateinit var settings: UploadSettings
    private lateinit var outbox: TelemetryOutbox
    private var networkRegistered = false
    @Volatile private var destroyed = false
    private val periodic = object : Runnable {
        override fun run() { requestUpload(); handler.postDelayed(this, 60_000L) }
    }
    private val networkCallback = object : ConnectivityManager.NetworkCallback() {
        override fun onAvailable(network: Network) { handler.post { requestUpload() } }
    }

    override fun onCreate() {
        super.onCreate()
        settings = UploadSettings(this)
        outbox = TelemetryOutbox.get(this)
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (intent?.action == ACTION_STOP) {
            settings.preferences.edit().putBoolean("enabled", false).apply()
            stopForeground(STOP_FOREGROUND_REMOVE)
            stopSelf()
            return START_NOT_STICKY
        }
        if ((intent == null || intent.action == ACTION_RESTORE) && !settings.enabled) {
            stopSelf()
            return START_NOT_STICKY
        }
        if (checkSelfPermission(Manifest.permission.BLUETOOTH_CONNECT) != PackageManager.PERMISSION_GRANTED) {
            setError("Нет разрешения на Bluetooth — запустите отправку с экрана приложения")
            stopSelf()
            return START_NOT_STICKY
        }
        getSystemService(NotificationManager::class.java).createNotificationChannel(
            NotificationChannel(CHANNEL, "Передача показаний", NotificationManager.IMPORTANCE_LOW))
        val open = PendingIntent.getActivity(this, 0, Intent(this, MainActivity::class.java), PendingIntent.FLAG_IMMUTABLE)
        val stop = PendingIntent.getService(this, 1, Intent(this, ServerUploadService::class.java).setAction(ACTION_STOP), PendingIntent.FLAG_IMMUTABLE)
        val notification = NotificationCompat.Builder(this, CHANNEL)
            .setSmallIcon(R.drawable.ic_upload).setContentTitle("Grandma Health: передача включена")
            .setContentText("Нажмите, чтобы проверить связь с сервером и очередь")
            .setContentIntent(open).setOngoing(true).setOnlyAlertOnce(true)
            .addAction(0, "Остановить", stop).build()
        try {
            startForeground(NOTIFICATION_ID, notification, ServiceInfo.FOREGROUND_SERVICE_TYPE_CONNECTED_DEVICE)
        } catch (_: Exception) {
            settings.preferences.edit().putBoolean("enabled", false)
                .putString("last_error", "Android запретил фоновую службу. Запустите её из приложения").apply()
            stopSelf()
            return START_NOT_STICKY
        }
        settings.preferences.edit().putBoolean("enabled", true)
            .putLong("last_service_started_at", System.currentTimeMillis()).apply()
        active = this
        if (!networkRegistered) {
            getSystemService(ConnectivityManager::class.java).registerDefaultNetworkCallback(networkCallback)
            networkRegistered = true
        }
        handler.removeCallbacks(periodic)
        handler.post(periodic)
        return START_STICKY
    }

    private fun requestUpload() {
        if (destroyed || !settings.enabled || !busy.compareAndSet(false, true)) return
        worker.execute {
            val lock = getSystemService(PowerManager::class.java).newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "GrandmaHealth:Upload")
            try {
                lock.acquire(65_000L)
                drain()
            } catch (_: Exception) {
                // Never log credentials, request bodies or medical data.
                setError("Не удалось отправить данные. Очередь сохранена")
            } finally {
                if (lock.isHeld) lock.release()
                busy.set(false)
            }
        }
    }

    private fun drain() {
        val address = settings.endpoint
        if (address.isBlank()) { setError("Сначала задайте адрес и токен сервера"); return }
        val token = try { settings.token() } catch (_: Exception) {
            setError("Не удалось прочитать токен. Введите его заново"); return
        }
        if (token.isBlank()) { setError("Введите токен сервера"); return }
        val endpoint = UploadPolicy.endpoint(address)
        val deadline = SystemClock.elapsedRealtime() + 45_000L
        for (index in 0 until 20) {
            if (destroyed || !settings.enabled || SystemClock.elapsedRealtime() >= deadline) return
            val packet = outbox.next(prioritizeLive = index % 5 == 0) ?: return
            val connection = URL("$endpoint/api/v1/events").openConnection() as HttpURLConnection
            try {
                connection.requestMethod = "POST"
                connection.connectTimeout = 8_000
                connection.readTimeout = 10_000
                connection.instanceFollowRedirects = false
                connection.setRequestProperty("Authorization", "Bearer $token")
                connection.setRequestProperty("Accept", "application/json")
                connection.setRequestProperty("Content-Type", "application/json; charset=utf-8")
                val bytes = packet.body.toByteArray(Charsets.UTF_8)
                connection.doOutput = true
                connection.setFixedLengthStreamingMode(bytes.size)
                connection.outputStream.use { it.write(bytes) }
                val code = connection.responseCode
                val response = if (code == 200 || code == 201) {
                    val text = connection.inputStream.use {
                        val buffer = ByteArray(16_385)
                        var used = 0
                        while (used < buffer.size) {
                            val read = it.read(buffer, used, buffer.size - used)
                            if (read < 0) break
                            used += read
                        }
                        check(used <= 16_384) { "Oversized acknowledgement" }
                        String(buffer, 0, used, Charsets.UTF_8)
                    }
                    JSONObject(text)
                } else null
                when (UploadPolicy.response(code, packet.id, response?.optString("event_id"), response?.optLong("server_received_at_ms") ?: 0L)) {
                    UploadPolicy.Result.ACKNOWLEDGED -> {
                        // A crash before deletion retries this same UUID safely.
                        outbox.acknowledge(packet.id)
                        settings.preferences.edit().putLong("last_ack_at", System.currentTimeMillis())
                            .putLong("last_server_received_at", response!!.getLong("server_received_at_ms"))
                            .putString("last_error", "").apply()
                    }
                    UploadPolicy.Result.BLOCKED -> {
                        outbox.block(packet.id)
                        setError("HTTP $code: пакет сохранён отдельно. Проверьте время устройств и формат данных")
                    }
                    UploadPolicy.Result.AUTH_ERROR -> { setError("HTTP $code: проверьте TELEMETRY_TOKEN"); return }
                    UploadPolicy.Result.RETRY -> { setError("HTTP $code: сервер не подтвердил пакет. Повторим позже"); return }
                }
            } finally { connection.disconnect() }
        }
    }

    private fun setError(message: String) { settings.preferences.edit().putString("last_error", message).apply() }

    override fun onDestroy() {
        destroyed = true
        if (active === this) active = null
        handler.removeCallbacksAndMessages(null)
        if (networkRegistered) getSystemService(ConnectivityManager::class.java).unregisterNetworkCallback(networkCallback)
        worker.shutdown()
        super.onDestroy()
    }

    override fun onBind(intent: Intent?): IBinder? = null

    companion object {
        private const val CHANNEL = "server_upload"
        private const val NOTIFICATION_ID = 2
        private const val ACTION_STOP = "com.romavb23.grandmahealth.STOP_UPLOAD"
        private const val ACTION_RESTORE = "com.romavb23.grandmahealth.RESTORE_UPLOAD"
        @Volatile private var active: ServerUploadService? = null

        internal fun restore(context: Context) {
            val saved = UploadSettings(context)
            if (!saved.enabled) return
            saved.preferences.edit()
                .putLong("last_restore_attempt_at", System.currentTimeMillis()).apply()
            if (saved.endpoint.isBlank() || saved.preferences.getString("token", "").isNullOrBlank()) {
                saved.preferences.edit().putString("last_error",
                    "Для восстановления передачи сохраните адрес и токен сервера").apply()
                return
            }
            try {
                ContextCompat.startForegroundService(context,
                    Intent(context, ServerUploadService::class.java).setAction(ACTION_RESTORE))
                Log.i("GrandmaStartup", "Phone upload restore requested")
            } catch (_: Exception) {
                saved.preferences.edit().putString("last_error",
                    "Android не разрешил восстановить передачу. Запустите её из приложения").apply()
                Log.w("GrandmaStartup", "Android rejected phone restore; open the app")
            }
        }

        fun isRunning(): Boolean = active != null
        internal fun packetReady() { active?.handler?.post { active?.requestUpload() } }
    }
}
