package com.romavb23.grandmahealth

import android.content.Context
import android.content.SharedPreferences
import android.os.Bundle
import android.os.SystemClock
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.foundation.Image
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalFocusManager
import androidx.compose.ui.platform.LocalSoftwareKeyboardController
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.text.SpanStyle
import androidx.compose.ui.text.buildAnnotatedString
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.withStyle
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.romavb23.grandmahealth.ui.theme.GrandmaHealthTheme
import com.romavb23.grandmahealth.ui.theme.HealthRed
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import kotlinx.coroutines.delay

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        setContent { GrandmaHealthTheme { GrandmaHealthApp() } }
    }
}

private enum class HealthTab(val label: String, val icon: Int) {
    STATUS("Состояние", R.drawable.ic_tab_status),
    UPLOAD("Передача", R.drawable.ic_tab_upload),
    SETTINGS("Настройки", R.drawable.ic_tab_settings),
}

@Composable
private fun GrandmaHealthApp() {
    val context = LocalContext.current
    val preferences = remember(context) { context.getSharedPreferences(HEART_RATE_PREFERENCES, Context.MODE_PRIVATE) }
    var reading by remember { mutableStateOf(preferences.readHeartRate()) }
    var now by remember { mutableStateOf(System.currentTimeMillis()) }
    var nowElapsed by remember { mutableStateOf(SystemClock.elapsedRealtime()) }
    val bootCount = remember(context) { phoneBootCount(context) }
    var tab by remember { mutableStateOf(HealthTab.STATUS) }
    // Keep forms and their controller outside the selected tab: switching does
    // not lose edits. The token stays only in memory, never in saved UI state.
    val upload = rememberUploadController()
    val focus = LocalFocusManager.current
    val keyboard = LocalSoftwareKeyboardController.current
    DisposableEffect(preferences) {
        val listener = SharedPreferences.OnSharedPreferenceChangeListener { _, _ -> reading = preferences.readHeartRate() }
        preferences.registerOnSharedPreferenceChangeListener(listener)
        onDispose { preferences.unregisterOnSharedPreferenceChangeListener(listener) }
    }
    LaunchedEffect(Unit) {
        focus.clearFocus()
        keyboard?.hide()
        while (true) {
            now = System.currentTimeMillis()
            nowElapsed = SystemClock.elapsedRealtime()
            delay(1_000L)
        }
    }
    Scaffold(
        containerColor = MaterialTheme.colorScheme.background,
        topBar = { BrandHeader() },
        bottomBar = {
            NavigationBar(containerColor = MaterialTheme.colorScheme.surface, tonalElevation = 0.dp) {
                HealthTab.entries.forEach { item ->
                    NavigationBarItem(
                        selected = tab == item,
                        onClick = { focus.clearFocus(); keyboard?.hide(); tab = item },
                        icon = { Icon(painterResource(item.icon), contentDescription = null) },
                        label = { Text(item.label) },
                    )
                }
            }
        },
    ) { innerPadding ->
        when (tab) {
            HealthTab.STATUS -> HeartRateContent(reading, now,
                WatchFreshness.contactAgeMillis(reading.lastContactElapsed, reading.lastContactBootCount, bootCount, nowElapsed),
                upload.state, Modifier.padding(innerPadding), onUpload = { tab = HealthTab.UPLOAD })
            HealthTab.UPLOAD -> ServerUploadPanel(upload, Modifier.padding(innerPadding), onSettings = { tab = HealthTab.SETTINGS })
            HealthTab.SETTINGS -> ServerSettingsPanel(upload, Modifier.padding(innerPadding))
        }
    }
}

@Composable
private fun BrandHeader() {
    Surface(color = MaterialTheme.colorScheme.background) {
        Row(Modifier.fillMaxWidth().statusBarsPadding().padding(horizontal = 20.dp, vertical = 14.dp),
            verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            Image(painterResource(R.drawable.brand_photo), contentDescription = null,
                contentScale = ContentScale.Crop, modifier = Modifier.size(44.dp).clip(CircleShape))
            Column {
                Text(buildAnnotatedString {
                    append("Grandma ")
                    withStyle(SpanStyle(color = HealthRed, fontWeight = FontWeight.Bold)) { append("Health") }
                }, style = MaterialTheme.typography.titleLarge)
                Text("Состояние бабушки", style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
        }
    }
}

@Composable
internal fun HealthPanel(modifier: Modifier = Modifier, content: @Composable ColumnScope.() -> Unit) {
    Card(modifier = modifier.fillMaxWidth(), shape = RoundedCornerShape(22.dp),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface)) {
        Column(Modifier.padding(20.dp), verticalArrangement = Arrangement.spacedBy(10.dp), content = content)
    }
}

@Composable
internal fun HealthBadge(text: String, good: Boolean = false) {
    Surface(shape = RoundedCornerShape(10.dp),
        color = if (good) Color(0xFFEAF6EF) else Color(0xFFFFF3DF),
        contentColor = if (good) Color(0xFF28653F) else Color(0xFF805A18)) {
        Text(text, style = MaterialTheme.typography.labelLarge, modifier = Modifier.padding(horizontal = 12.dp, vertical = 8.dp))
    }
}

@Composable
private fun HeartRateContent(
    reading: HeartRateReading, now: Long, contactAge: Long?, upload: UploadViewState,
    modifier: Modifier = Modifier, onUpload: () -> Unit,
) {
    val pulseStale = WatchFreshness.isMeasurementStale(reading.measuredAt, now)
    val contactRecent = WatchFreshness.isContactRecent(contactAge)
    val muted = MaterialTheme.colorScheme.onSurfaceVariant
    Column(modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(horizontal = 20.dp, vertical = 10.dp),
        verticalArrangement = Arrangement.spacedBy(16.dp)) {
        HealthPanel {
            Text("Последний измеренный пульс", style = MaterialTheme.typography.titleMedium)
            Row(verticalAlignment = Alignment.Bottom, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                Text(reading.bpm?.toString() ?: "—", fontSize = 64.sp, lineHeight = 72.sp, fontWeight = FontWeight.Bold)
                Text("уд/мин", color = muted, modifier = Modifier.padding(bottom = 12.dp))
            }
            HealthBadge(when {
                reading.measuredAt <= 0L -> "Ожидаем первый замер"
                pulseStale -> "Замер устарел"
                else -> "Свежий замер"
            }, good = reading.measuredAt > 0L && !pulseStale)
            Text(if (reading.measuredAt > 0L) "Измерен: " + formatTime(reading.measuredAt) else "Данные появятся после измерения на часах",
                style = MaterialTheme.typography.bodyMedium, color = muted)
            if (reading.measuredAt > 0L) Text("Прошло с измерения: " + formatAge((now - reading.measuredAt).coerceAtLeast(0L)),
                style = MaterialTheme.typography.bodyMedium, color = muted)
        }
        HealthPanel {
            Text("Связь с часами", style = MaterialTheme.typography.titleMedium)
            HealthBadge(when {
                contactRecent -> "Свежий сигнал получен"
                reading.lastContactAt == 0L -> "Ожидаем первый сигнал"
                contactAge == null -> "Ожидаем сигнал после перезапуска"
                else -> "Нет свежего сигнала ≥ 10 мин"
            }, good = contactRecent)
            Text(if (reading.lastContactAt > 0L) "Последний: " + formatTime(reading.lastContactAt) else "Последний сигнал: —",
                color = muted, style = MaterialTheme.typography.bodyMedium)
            contactAge?.let { Text("Прошло: " + formatAge(it), color = muted, style = MaterialTheme.typography.bodyMedium) }
        }
        Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            HealthPanel(Modifier.weight(1f)) {
                Text("Заряд часов", style = MaterialTheme.typography.labelLarge, color = muted)
                Text(if (reading.batteryPercent in 0..100) "${reading.batteryPercent}%" else "—",
                    style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Bold)
                Text(if (reading.batteryPercent !in 0..100) "Ожидаем данные" else if (reading.charging) "Заряжаются" else "Без зарядки",
                    style = MaterialTheme.typography.bodySmall, color = muted)
                if (!contactRecent && reading.batteryPercent in 0..100) Text("Последние данные", style = MaterialTheme.typography.bodySmall, color = muted)
            }
            HealthPanel(Modifier.weight(1f)) {
                Text("Ношение", style = MaterialTheme.typography.labelLarge, color = muted)
                Text(when {
                    !contactRecent -> "Нет данных"
                    reading.wearingState == "on" -> "На руке"
                    reading.wearingState == "off" -> "Сняты"
                    else -> "Неизвестно"
                }, style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
                Text("По сигналу датчика", style = MaterialTheme.typography.bodySmall, color = muted)
            }
        }
        HealthPanel {
            Text("Мониторинг и передача", style = MaterialTheme.typography.titleMedium)
            Text(monitoringStatusText(reading.monitoringStatus) +
                (if (!contactRecent && reading.monitoringStatus != "unknown") " · последний известный статус" else ""),
                style = MaterialTheme.typography.bodyMedium)
            Text(if (!upload.loaded) "Проверяем службу передачи" else if (upload.running) "Передача на сервер включена" else "Передача на сервер остановлена",
                style = MaterialTheme.typography.bodyMedium)
            Text(if (upload.lastAck > 0L) "Сервер подтвердил: " + formatTime(upload.lastAck) else "Ожидаем подтверждение сервера",
                style = MaterialTheme.typography.bodySmall, color = muted)
            if (upload.error.isNotBlank()) Text(upload.error, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.error)
            TextButton(onClick = onUpload, contentPadding = PaddingValues(0.dp)) { Text("Подробнее о передаче →") }
        }
        Text("Показания часов помогают наблюдать за состоянием. Свежая связь и свежий пульс — разные признаки; они не подтверждают, что со здоровьем всё в порядке.",
            style = MaterialTheme.typography.bodySmall, color = muted, modifier = Modifier.padding(bottom = 8.dp))
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
