package com.romavb23.grandmahealth

import android.Manifest
import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.content.pm.ServiceInfo
import android.os.Build
import android.os.Handler
import android.os.IBinder
import android.os.Looper
import android.os.PowerManager
import android.os.SystemClock
import android.util.Log
import androidx.core.content.ContextCompat
import androidx.health.services.client.HealthServices
import androidx.health.services.client.PassiveListenerCallback
import androidx.health.services.client.data.DataPointContainer
import androidx.health.services.client.data.DataType
import androidx.health.services.client.data.PassiveListenerConfig
import com.romavb23.grandmahealth.presentation.MainActivity
import java.time.Instant
import kotlin.math.roundToInt

/** Long-lived passive callback + independent live contact packets. No fake workout session. */
class MonitoringForegroundService : Service() {
    private val handler = Handler(Looper.getMainLooper())
    private val passiveClient by lazy { HealthServices.getClient(this).passiveMonitoringClient }
    private var started = false
    private var wakeLock: PowerManager.WakeLock? = null
    private val wearingSensor by lazy { WearingSensor(this) {
        if (started) sendWatchHeartbeat(this)
    } }

    private val heartbeat = object : Runnable {
        override fun run() {
            if (!started) return
            if (!hasPermissions(this@MonitoringForegroundService)) {
                fail("permission_lost")
                return
            }
            // Keep the CPU (not the screen) available for this prototype's timer.
            // Renew a bounded lock; if the loop stalls, it expires after 10 minutes.
            renewWakeLock()
            sendWatchHeartbeat(this@MonitoringForegroundService)
            handler.postDelayed(this, HEARTBEAT_INTERVAL_MS)
        }
    }

    private val callback = object : PassiveListenerCallback {
        override fun onRegistered() {
            if (!started) return
            WatchStateStore.setStatus(this@MonitoringForegroundService, "active")
            updateNotification("Пульс в фоне · сигнал связи ≈ 3 мин")
            sendWatchHeartbeat(this@MonitoringForegroundService)
        }

        override fun onRegistrationFailed(throwable: Throwable) {
            if (!started) return
            Log.e(LOG_TAG, "Passive callback registration failed", throwable)
            fail("error")
        }

        override fun onPermissionLost() {
            if (started) fail("permission_lost")
        }

        override fun onNewDataPointsReceived(dataPoints: DataPointContainer) {
            if (!started) return
            val latest = dataPoints.getData(DataType.HEART_RATE_BPM)
                .maxByOrNull { it.timeDurationFromBoot } ?: return
            if (!latest.value.isFinite()) return
            val bootInstant = Instant.ofEpochMilli(
                System.currentTimeMillis() - SystemClock.elapsedRealtime(),
            )
            sendHeartRateToPhone(
                this@MonitoringForegroundService,
                latest.value.roundToInt(),
                latest.getTimeInstant(bootInstant).toEpochMilli(),
            )
        }
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (intent?.action == ACTION_STOP || !WatchStateStore.isEnabled(this)) {
            wearingSensor.stop()
            WatchStateStore.setEnabled(this, false)
            WatchStateStore.setStatus(this, "stopped")
            sendWatchHeartbeat(this)
            clearPassiveService()
            stopSelf()
            return START_NOT_STICKY
        }
        if (!hasPermissions(this)) {
            fail("permission_lost")
            return START_NOT_STICKY
        }
        if (started) return START_STICKY

        try {
            val manager = getSystemService(NotificationManager::class.java)
            manager.createNotificationChannel(
                NotificationChannel(CHANNEL_ID, "Мониторинг здоровья", NotificationManager.IMPORTANCE_LOW),
            )
            val notification = notification("Запускаем фоновый мониторинг…")
            if (Build.VERSION.SDK_INT >= 34) {
                startForeground(NOTIFICATION_ID, notification, ServiceInfo.FOREGROUND_SERVICE_TYPE_HEALTH)
            } else {
                startForeground(NOTIFICATION_ID, notification)
            }
            started = true
            wearingSensor.start()
            WatchStateStore.setStatus(this, "starting")
            val powerManager = getSystemService(PowerManager::class.java)
            wakeLock = powerManager.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "GrandmaHealth:monitoring")
                .apply { setReferenceCounted(false) }
            handler.post(heartbeat)
            registerMonitoring()
        } catch (error: Exception) {
            Log.e(LOG_TAG, "Could not start monitoring", error)
            fail("error")
            return START_NOT_STICKY
        }
        return START_STICKY
    }

    private fun registerMonitoring() {
        val executor = ContextCompat.getMainExecutor(this)
        val future = passiveClient.getCapabilitiesAsync()
        future.addListener({
            if (!started || !WatchStateStore.isEnabled(this)) return@addListener
            try {
                if (DataType.HEART_RATE_BPM !in future.get().supportedDataTypesPassiveMonitoring) {
                    fail("unsupported")
                    return@addListener
                }
                val config = PassiveListenerConfig.builder()
                    .setDataTypes(setOf(DataType.HEART_RATE_BPM)).build()
                // Callback isn't batched while the service process is alive.
                passiveClient.setPassiveListenerCallback(config, executor, callback)
                // Keep the existing batched channel as a fallback after process death.
                val registration = passiveClient.setPassiveListenerServiceAsync(
                    PassiveHeartRateService::class.java, config,
                )
                registration.addListener({
                    try {
                        registration.get()
                        // A queued registration must not undo an explicit stop.
                        if (!WatchStateStore.isEnabled(this)) clearPassiveService()
                    } catch (error: Exception) {
                        Log.w(LOG_TAG, "Fallback passive service registration failed", error)
                    }
                }, executor)
            } catch (error: Exception) {
                Log.e(LOG_TAG, "Could not register monitoring", error)
                fail("error")
            }
        }, executor)
    }

    private fun renewWakeLock() {
        wakeLock?.acquire(WAKE_LOCK_TIMEOUT_MS)
    }

    private fun fail(status: String) {
        wearingSensor.stop()
        WatchStateStore.setEnabled(this, false)
        WatchStateStore.setStatus(this, status)
        sendWatchHeartbeat(this)
        clearPassiveService()
        stopSelf()
    }

    private fun clearPassiveService() {
        try {
            val future = passiveClient.clearPassiveListenerServiceAsync()
            future.addListener({
                try { future.get() } catch (error: Exception) {
                    Log.w(LOG_TAG, "Could not clear passive service", error)
                }
            }, ContextCompat.getMainExecutor(this))
        } catch (error: Exception) {
            Log.w(LOG_TAG, "Could not clear passive service", error)
        }
    }

    private fun notification(text: String): Notification {
        val openApp = PendingIntent.getActivity(
            this, 0, Intent(this, MainActivity::class.java),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
        val stop = PendingIntent.getService(
            this, 1, Intent(this, MonitoringForegroundService::class.java).setAction(ACTION_STOP),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
        return Notification.Builder(this, CHANNEL_ID)
            .setSmallIcon(R.drawable.ic_monitoring)
            .setContentTitle("Grandma Health")
            .setContentText(text)
            .setContentIntent(openApp)
            .setOngoing(true)
            .setOnlyAlertOnce(true)
            .setCategory(Notification.CATEGORY_SERVICE)
            .addAction(Notification.Action.Builder(null, "Остановить фон", stop).build())
            .build()
    }

    private fun updateNotification(text: String) {
        getSystemService(NotificationManager::class.java).notify(NOTIFICATION_ID, notification(text))
    }

    override fun onDestroy() {
        started = false
        wearingSensor.stop()
        handler.removeCallbacks(heartbeat)
        wakeLock?.let { if (it.isHeld) it.release() }
        if (WatchStateStore.status(this) in setOf("active", "starting")) {
            WatchStateStore.setStatus(this, "stopped")
        }
        try {
            val future = passiveClient.clearPassiveListenerCallbackAsync()
            future.addListener({
                try { future.get() } catch (error: Exception) {
                    Log.w(LOG_TAG, "Could not clear passive callback", error)
                }
            }, ContextCompat.getMainExecutor(this))
        } catch (error: Exception) {
            Log.w(LOG_TAG, "Could not clear passive callback", error)
        }
        super.onDestroy()
    }

    override fun onBind(intent: Intent?): IBinder? = null

    companion object {
        fun start(context: Context) {
            WatchStateStore.setEnabled(context, true)
            ContextCompat.startForegroundService(context, Intent(context, MonitoringForegroundService::class.java))
        }

        fun stop(context: Context) {
            WatchStateStore.setEnabled(context, false)
            context.startService(Intent(context, MonitoringForegroundService::class.java).setAction(ACTION_STOP))
        }

        private fun hasPermissions(context: Context): Boolean {
            val sensor = if (Build.VERSION.SDK_INT >= 36) {
                "android.permission.health.READ_HEART_RATE"
            } else Manifest.permission.BODY_SENSORS
            val background = when {
                Build.VERSION.SDK_INT >= 36 -> "android.permission.health.READ_HEALTH_DATA_IN_BACKGROUND"
                Build.VERSION.SDK_INT >= 33 -> Manifest.permission.BODY_SENSORS_BACKGROUND
                else -> null
            }
            return ContextCompat.checkSelfPermission(context, sensor) == PackageManager.PERMISSION_GRANTED &&
                (background == null || ContextCompat.checkSelfPermission(context, background) == PackageManager.PERMISSION_GRANTED)
        }
    }
}

private const val HEARTBEAT_INTERVAL_MS = 180_000L
private const val WAKE_LOCK_TIMEOUT_MS = 600_000L
private const val CHANNEL_ID = "health_monitoring"
private const val NOTIFICATION_ID = 1
private const val ACTION_STOP = "com.romavb23.grandmahealth.STOP_MONITORING"
private const val LOG_TAG = "GrandmaMonitoring"
