package com.romavb23.grandmahealth

import android.content.Context
import android.content.SharedPreferences
import android.os.Bundle
import android.os.SystemClock
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
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
import androidx.compose.ui.tooling.preview.Preview
import androidx.compose.ui.unit.dp
import com.romavb23.grandmahealth.ui.theme.GrandmaHealthTheme
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import kotlinx.coroutines.delay

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        setContent {
            GrandmaHealthTheme {
                Scaffold(modifier = Modifier.fillMaxSize()) { innerPadding ->
                    HeartRateScreen(modifier = Modifier.padding(innerPadding))
                }
            }
        }
    }
}

@Composable
private fun HeartRateScreen(modifier: Modifier = Modifier) {
    val context = LocalContext.current
    val preferences = remember(context) {
        context.getSharedPreferences(HEART_RATE_PREFERENCES, Context.MODE_PRIVATE)
    }
    var reading by remember { mutableStateOf(preferences.readHeartRate()) }
    var now by remember { mutableStateOf(System.currentTimeMillis()) }
    var nowElapsed by remember { mutableStateOf(SystemClock.elapsedRealtime()) }
    val bootCount = remember(context) { phoneBootCount(context) }

    DisposableEffect(preferences) {
        val listener = SharedPreferences.OnSharedPreferenceChangeListener { _, _ ->
            reading = preferences.readHeartRate()
        }
        preferences.registerOnSharedPreferenceChangeListener(listener)
        onDispose { preferences.unregisterOnSharedPreferenceChangeListener(listener) }
    }

    // Freshness must expire even when no new data arrives.
    LaunchedEffect(Unit) {
        while (true) {
            now = System.currentTimeMillis()
            nowElapsed = SystemClock.elapsedRealtime()
            delay(1_000L)
        }
    }

    HeartRateContent(
        reading = reading,
        now = now,
        contactAge = WatchFreshness.contactAgeMillis(
            reading.lastContactElapsed, reading.lastContactBootCount, bootCount, nowElapsed,
        ),
        modifier = modifier,
    )
}

@Composable
private fun HeartRateContent(
    reading: HeartRateReading,
    now: Long,
    contactAge: Long?,
    modifier: Modifier = Modifier,
) {
    val pulseStale = WatchFreshness.isMeasurementStale(reading.measuredAt, now)
    val contactRecent = WatchFreshness.isContactRecent(contactAge)
    Column(
        modifier = modifier.fillMaxSize().verticalScroll(rememberScrollState())
            .padding(horizontal = 24.dp, vertical = 20.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center,
    ) {
        Text(
            text = "Состояние бабушки",
            style = MaterialTheme.typography.headlineSmall,
            fontWeight = FontWeight.Bold,
        )
        Text(
            text = when {
                contactRecent -> "Свежий сигнал связи получен"
                reading.lastContactAt == 0L -> "Ожидаем первый сигнал связи"
                contactAge == null -> "Нужен новый сигнал после перезапуска телефона"
                else -> "Нет свежего сигнала связи ≥ 10 мин"
            },
            modifier = Modifier.padding(top = 16.dp),
            color = if (contactRecent) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.error,
            style = MaterialTheme.typography.bodyLarge,
        )
        Text(
            text = if (reading.lastContactAt > 0L) {
                "Последний сигнал: " + formatTime(reading.lastContactAt) +
                    (contactAge?.let { " · " + formatAge(it) + " назад" } ?: "")
            } else "Последний сигнал: —",
            modifier = Modifier.padding(top = 8.dp),
            style = MaterialTheme.typography.bodyMedium,
        )
        Text(
            text = "Последний измеренный пульс",
            modifier = Modifier.padding(top = 24.dp),
            style = MaterialTheme.typography.titleMedium,
        )
        Text(
            text = reading.bpm?.toString() ?: "—",
            style = MaterialTheme.typography.displayLarge,
            fontWeight = FontWeight.Bold,
            color = if (pulseStale) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.onSurface,
        )
        Text(text = "уд/мин", style = MaterialTheme.typography.titleMedium)
        Text(
            text = if (reading.measuredAt > 0L) {
                "Измерен: " + formatTime(reading.measuredAt)
            } else "Ожидаем измерение пульса",
            modifier = Modifier.padding(top = 12.dp),
            style = MaterialTheme.typography.bodyLarge,
        )
        if (reading.measuredAt > 0L) {
            Text(
                text = if (pulseStale) "Пульс устарел — это не текущее значение"
                    else "Возраст измерения: " + formatAge((now - reading.measuredAt).coerceAtLeast(0L)),
                color = if (pulseStale) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.onSurface,
                style = MaterialTheme.typography.bodyMedium,
            )
        }
        Text(
            text = if (reading.batteryPercent in 0..100) {
                "Заряд часов: " + reading.batteryPercent + "%" +
                    (if (reading.charging) " · заряжаются" else "")
            } else "Заряд часов: ожидаем данные",
            modifier = Modifier.padding(top = 16.dp),
            style = MaterialTheme.typography.bodyLarge,
        )
        Text(
            text = monitoringStatusText(reading.monitoringStatus) +
                (if (!contactRecent && reading.monitoringStatus != "unknown") " (последний известный статус)" else ""),
            modifier = Modifier.padding(top = 8.dp),
            style = MaterialTheme.typography.bodyMedium,
        )
        Text(
            text = when {
                !contactRecent -> "Ношение часов: нет свежих данных"
                reading.wearingState == "on" -> "Часы на руке"
                reading.wearingState == "off" -> "Часы сняты"
                else -> "Ношение часов: неизвестно"
            },
            modifier = Modifier.padding(top = 8.dp),
            style = MaterialTheme.typography.bodyMedium,
        )
        Text(
            text = "Связь и возраст пульса — разные показатели. Этот экран не подтверждает, что со здоровьем всё в порядке.",
            modifier = Modifier.padding(top = 20.dp),
            style = MaterialTheme.typography.bodySmall,
        )
        ServerUploadPanel()
    }
}

@Preview(showBackground = true)
@Composable
private fun HeartRatePreview() {
    val now = System.currentTimeMillis()
    GrandmaHealthTheme {
        HeartRateContent(
            reading = HeartRateReading(72, now, 83, false, now, 1_000L, 1, "active"),
            now = now,
            contactAge = 10_000L,
        )
    }
}

private fun SharedPreferences.readHeartRate(): HeartRateReading {
    val bpm = getInt(HEART_RATE_KEY_BPM, -1).takeIf { it > 0 }
    return HeartRateReading(
        bpm = bpm,
        measuredAt = getLong(HEART_RATE_KEY_MEASURED_AT, 0L),
        batteryPercent = getInt(HEART_RATE_KEY_BATTERY_PERCENT, -1),
        charging = getBoolean(HEART_RATE_KEY_CHARGING, false),
        lastContactAt = getLong(WATCH_KEY_LAST_CONTACT_AT, 0L),
        lastContactElapsed = getLong(WATCH_KEY_LAST_CONTACT_ELAPSED, 0L),
        lastContactBootCount = getInt(WATCH_KEY_LAST_CONTACT_BOOT_COUNT, -1),
        monitoringStatus = getString(WATCH_KEY_MONITORING_STATUS, "unknown") ?: "unknown",
        wearingState = getString("wearing_state", "unknown") ?: "unknown",
    )
}

private fun monitoringStatusText(status: String): String = when (status) {
    "active" -> "Фоновый пульс: включён"
    "starting" -> "Фоновый пульс: запускается"
    "stopped" -> "Фоновый пульс: выключен"
    "permission_lost" -> "Фоновый пульс: нет разрешения"
    "unsupported" -> "Фоновый пульс: не поддерживается"
    "error" -> "Фоновый пульс: ошибка"
    else -> "Фоновый пульс: статус неизвестен"
}

private fun formatTime(timestamp: Long): String =
    SimpleDateFormat("dd.MM.yyyy HH:mm:ss", Locale.forLanguageTag("ru-RU")).format(Date(timestamp))

private fun formatAge(durationMillis: Long): String {
    val seconds = durationMillis.coerceAtLeast(0L) / 1_000L
    return (seconds / 60L).toString() + " мин " + (seconds % 60L) + " с"
}

private data class HeartRateReading(
    val bpm: Int?,
    val measuredAt: Long,
    val batteryPercent: Int,
    val charging: Boolean,
    val lastContactAt: Long,
    val lastContactElapsed: Long,
    val lastContactBootCount: Int,
    val monitoringStatus: String,
    val wearingState: String = "unknown",
)
