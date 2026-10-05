package com.romavb23.grandmahealth.presentation

import android.Manifest
import android.content.Context
import android.content.SharedPreferences
import android.os.SystemClock
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.compose.setContent
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.core.content.ContextCompat
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import androidx.health.services.client.HealthServices
import androidx.health.services.client.MeasureCallback
import androidx.health.services.client.data.Availability
import androidx.health.services.client.data.DataPointContainer
import androidx.health.services.client.data.DataType
import androidx.health.services.client.data.DataTypeAvailability
import androidx.health.services.client.data.DeltaDataType
import androidx.wear.compose.material3.AppScaffold
import androidx.wear.compose.material3.Button
import androidx.wear.compose.material3.MaterialTheme
import androidx.wear.compose.material3.ScreenScaffold
import androidx.wear.compose.material3.Text
import androidx.wear.compose.ui.tooling.preview.WearPreviewDevices
import androidx.wear.compose.ui.tooling.preview.WearPreviewFontScales
import com.romavb23.grandmahealth.MonitoringForegroundService
import com.romavb23.grandmahealth.WatchStateStore
import java.time.Instant
import com.romavb23.grandmahealth.sendHeartRateToPhone
import com.romavb23.grandmahealth.presentation.theme.GrandmaHealthTheme
import kotlin.math.roundToInt

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContent {
            HeartRateApp()
        }
    }
}

@Composable
private fun HeartRateApp() {
    val context = LocalContext.current
    val measureClient = remember(context) {
        HealthServices.getClient(context).measureClient
    }

    var bpm by remember { mutableStateOf<Int?>(null) }
    var status by remember { mutableStateOf("Запрашиваем доступ…") }
    var backgroundStatus by remember { mutableStateOf("Проверяем фоновый доступ…") }
    var permissionGranted by remember {
        mutableStateOf(hasHeartRatePermission(context))
    }
    var backgroundPermissionGranted by remember {
        mutableStateOf(hasBackgroundHeartRatePermission(context))
    }

    var monitoringEnabled by remember { mutableStateOf(WatchStateStore.isEnabled(context)) }
    // Background sensor permission may be granted on a separate system settings screen.
    DisposableEffect(context) {
        val lifecycle = (context as? ComponentActivity)?.lifecycle
        val observer = LifecycleEventObserver { _, event ->
            if (event == Lifecycle.Event.ON_RESUME) {
                permissionGranted = hasHeartRatePermission(context)
                backgroundPermissionGranted = hasBackgroundHeartRatePermission(context)
                monitoringEnabled = WatchStateStore.isEnabled(context)
                backgroundStatus = monitoringStatusText(WatchStateStore.status(context))
            }
        }
        lifecycle?.addObserver(observer)
        onDispose { lifecycle?.removeObserver(observer) }
    }
    val monitoringPreferences = remember(context) { WatchStateStore.preferences(context) }
    val notificationLauncher =
        rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { }

    DisposableEffect(monitoringPreferences) {
        val listener = SharedPreferences.OnSharedPreferenceChangeListener { _, _ ->
            monitoringEnabled = WatchStateStore.isEnabled(context)
            backgroundStatus = monitoringStatusText(WatchStateStore.status(context))
        }
        monitoringPreferences.registerOnSharedPreferenceChangeListener(listener)
        onDispose { monitoringPreferences.unregisterOnSharedPreferenceChangeListener(listener) }
    }

    LaunchedEffect(backgroundPermissionGranted) {
        if (backgroundPermissionGranted && Build.VERSION.SDK_INT >= 33 &&
            ContextCompat.checkSelfPermission(context, Manifest.permission.POST_NOTIFICATIONS) !=
            PackageManager.PERMISSION_GRANTED
        ) {
            notificationLauncher.launch(Manifest.permission.POST_NOTIFICATIONS)
        }
    }

    val backgroundPermissionLauncher =
        rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
            backgroundPermissionGranted = granted
            backgroundStatus =
                if (granted) {
                    "Регистрируем фоновый мониторинг…"
                } else {
                    "Нет фонового доступа"
                }
        }

    val permissionLauncher =
        rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
            permissionGranted = granted
            status = if (granted) "Подключаем датчик…" else "Нет доступа к пульсу"
        }

    DisposableEffect(permissionGranted, measureClient) {
        if (!permissionGranted) {
            permissionLauncher.launch(heartRatePermission())
            onDispose { }
        } else {
            var lastSentAt = 0L
            val callback =
                createHeartRateCallback(
                    onBpm = { measuredBpm, measuredAt ->
                        bpm = measuredBpm

                        if (measuredAt - lastSentAt >= HEART_RATE_SEND_INTERVAL_MS) {
                            lastSentAt = measuredAt
                            sendHeartRateToPhone(
                                context = context,
                                bpm = measuredBpm,
                                measuredAt = measuredAt,
                            )
                        }
                    },
                    onStatus = { status = it },
                )

            status = "Подключаем датчик…"
            measureClient.registerMeasureCallback(DataType.HEART_RATE_BPM, callback)

            onDispose {
                measureClient.unregisterMeasureCallbackAsync(DataType.HEART_RATE_BPM, callback)
            }
        }
    }

    DisposableEffect(permissionGranted, backgroundPermissionGranted) {
        val permission = backgroundHeartRatePermission()
        if (permissionGranted && !backgroundPermissionGranted && permission != null) {
            backgroundPermissionLauncher.launch(permission)
        }
        onDispose { }
    }

    DisposableEffect(permissionGranted, backgroundPermissionGranted, monitoringEnabled) {
        if (permissionGranted && backgroundPermissionGranted && monitoringEnabled) {
            registerPassiveHeartRateMonitoring(context) { message ->
                backgroundStatus = message
            }
        } else if (!monitoringEnabled) {
            backgroundStatus = monitoringStatusText(WatchStateStore.status(context))
        }
        onDispose { }
    }

    HeartRateScreen(
        bpm = bpm,
        status = status,
        backgroundStatus = backgroundStatus,
        permissionGranted = permissionGranted,
        backgroundPermissionGranted = backgroundPermissionGranted,
        requestPermission = { permissionLauncher.launch(heartRatePermission()) },
        requestBackgroundPermission = {
            backgroundHeartRatePermission()?.let(backgroundPermissionLauncher::launch)
        },
        monitoringEnabled = monitoringEnabled,
        toggleMonitoring = {
            if (monitoringEnabled) {
                MonitoringForegroundService.stop(context)
            } else {
                monitoringEnabled = true
                WatchStateStore.setEnabled(context, true)
            }
        },
    )
}

@Composable
private fun HeartRateScreen(
    bpm: Int?,
    status: String,
    backgroundStatus: String,
    permissionGranted: Boolean,
    backgroundPermissionGranted: Boolean,
    requestPermission: () -> Unit,
    requestBackgroundPermission: () -> Unit,
    monitoringEnabled: Boolean,
    toggleMonitoring: () -> Unit,
) {
    GrandmaHealthTheme {
        AppScaffold {
            ScreenScaffold { contentPadding ->
                Column(
                    modifier =
                        Modifier
                            .fillMaxSize()
                            .verticalScroll(rememberScrollState())
                            .padding(contentPadding)
                            .padding(horizontal = 18.dp),
                    horizontalAlignment = Alignment.CenterHorizontally,
                    verticalArrangement = Arrangement.Center,
                ) {
                    Text(
                        text = bpm?.toString() ?: "—",
                        style = MaterialTheme.typography.displayMedium,
                        fontWeight = FontWeight.Bold,
                    )
                    Text(
                        text = "уд/мин",
                        style = MaterialTheme.typography.bodyMedium,
                    )
                    Text(
                        text = status,
                        modifier = Modifier.padding(top = 12.dp),
                        style = MaterialTheme.typography.bodySmall,
                    )
                    Text(
                        text = backgroundStatus,
                        modifier = Modifier.padding(top = 6.dp),
                        style = MaterialTheme.typography.bodySmall,
                    )

                    if (!permissionGranted) {
                        Button(
                            onClick = requestPermission,
                            modifier = Modifier.padding(top = 12.dp),
                        ) {
                            Text("Разрешить")
                        }
                    } else if (!backgroundPermissionGranted) {
                        Button(
                            onClick = requestBackgroundPermission,
                            modifier = Modifier.padding(top = 12.dp),
                        ) {
                            Text("Разрешить фон")
                        }
                    } else {
                        Button(
                            onClick = toggleMonitoring,
                            modifier = Modifier.padding(top = 12.dp),
                        ) {
                            Text(if (monitoringEnabled) "Остановить фон" else "Включить фон")
                        }
                    }
                }
            }
        }
    }
}

private fun createHeartRateCallback(
    onBpm: (Int, Long) -> Unit,
    onStatus: (String) -> Unit,
): MeasureCallback =
    object : MeasureCallback {
        override fun onRegistered() {
            onStatus("Ожидаем измерение…")
        }

        override fun onRegistrationFailed(throwable: Throwable) {
            onStatus("Ошибка датчика: ${throwable.message ?: "неизвестная"}")
        }

        override fun onAvailabilityChanged(
            dataType: DeltaDataType<*, *>,
            availability: Availability,
        ) {
            if (dataType != DataType.HEART_RATE_BPM) return

            onStatus(
                if (availability == DataTypeAvailability.AVAILABLE) {
                    "Датчик активен"
                } else {
                    "Наденьте часы плотнее"
                },
            )
        }

        override fun onDataReceived(data: DataPointContainer) {
            val latestHeartRate = data.getData(DataType.HEART_RATE_BPM)
                .maxByOrNull { it.timeDurationFromBoot } ?: return
            if (!latestHeartRate.value.isFinite()) return
            val bootInstant = Instant.ofEpochMilli(
                System.currentTimeMillis() - SystemClock.elapsedRealtime(),
            )
            onBpm(
                latestHeartRate.value.roundToInt(),
                latestHeartRate.getTimeInstant(bootInstant).toEpochMilli(),
            )
            onStatus("Датчик активен")
        }
    }

private fun heartRatePermission(): String =
    if (Build.VERSION.SDK_INT >= 36) {
        READ_HEART_RATE_PERMISSION
    } else {
        Manifest.permission.BODY_SENSORS
    }

private fun hasHeartRatePermission(context: Context): Boolean =
    ContextCompat.checkSelfPermission(context, heartRatePermission()) ==
        PackageManager.PERMISSION_GRANTED

private fun backgroundHeartRatePermission(): String? =
    when {
        Build.VERSION.SDK_INT >= 36 -> READ_HEALTH_DATA_IN_BACKGROUND_PERMISSION
        Build.VERSION.SDK_INT >= 33 -> Manifest.permission.BODY_SENSORS_BACKGROUND
        else -> null
    }

private fun hasBackgroundHeartRatePermission(context: Context): Boolean {
    val permission = backgroundHeartRatePermission() ?: return true
    return ContextCompat.checkSelfPermission(context, permission) ==
        PackageManager.PERMISSION_GRANTED
}

private fun registerPassiveHeartRateMonitoring(
    context: Context,
    onStatus: (String) -> Unit,
) {
    try {
        MonitoringForegroundService.start(context)
        onStatus("Запускаем фон…")
    } catch (error: Exception) {
        WatchStateStore.setEnabled(context, false)
        WatchStateStore.setStatus(context, "error")
        onStatus("Ошибка запуска фона")
    }
}

private fun monitoringStatusText(status: String): String = when (status) {
    "active" -> "Пульс в фоне · связь ≈ 3 мин"
    "starting" -> "Запускаем фон…"
    "permission_lost" -> "Нет разрешения на пульс в фоне"
    "unsupported" -> "Фоновый пульс не поддерживается"
    "error" -> "Ошибка фонового мониторинга"
    else -> "Фон выключен"
}

private const val READ_HEART_RATE_PERMISSION = "android.permission.health.READ_HEART_RATE"
private const val READ_HEALTH_DATA_IN_BACKGROUND_PERMISSION =
    "android.permission.health.READ_HEALTH_DATA_IN_BACKGROUND"
private const val HEART_RATE_SEND_INTERVAL_MS = 10_000L

@WearPreviewDevices
@WearPreviewFontScales
@Composable
private fun DefaultPreview() {
    HeartRateScreen(
        bpm = 72,
        status = "Датчик активен",
        backgroundStatus = "Фоновый мониторинг включён",
        permissionGranted = true,
        backgroundPermissionGranted = true,
        requestPermission = {},
        requestBackgroundPermission = {},
        monitoringEnabled = true,
        toggleMonitoring = {},
    )
}
