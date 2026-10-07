package com.romavb23.grandmahealth

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.os.PowerManager
import android.provider.Settings
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalFocusManager
import androidx.compose.ui.platform.LocalSoftwareKeyboardController
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

@Composable
internal fun rememberUploadController(): UploadController {
    val context = LocalContext.current
    val settings = remember(context) { UploadSettings(context) }
    var address by rememberSaveable { mutableStateOf(settings.endpoint) }
    // Never put tokens in rememberSaveable or display the stored ciphertext.
    var token by remember { mutableStateOf("") }
    var message by remember { mutableStateOf("") }
    var state by remember { mutableStateOf(UploadViewState()) }
    val scope = rememberCoroutineScope()

    fun start() {
        if (context.checkSelfPermission(Manifest.permission.BLUETOOTH_CONNECT) != PackageManager.PERMISSION_GRANTED) {
            message = "Разрешите доступ к устройствам поблизости для фоновой передачи"
            return
        }
        try {
            context.startForegroundService(Intent(context, ServerUploadService::class.java))
            message = "Передача запускается. Ожидаем подтверждение сервера"
        } catch (_: Exception) { message = "Не удалось запустить службу передачи" }
    }
    val permissions = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { start() }
    fun requestStart() {
        val required = mutableListOf(Manifest.permission.BLUETOOTH_CONNECT)
        if (Build.VERSION.SDK_INT >= 33) required.add(Manifest.permission.POST_NOTIFICATIONS)
        if (required.any { context.checkSelfPermission(it) != PackageManager.PERMISSION_GRANTED }) {
            permissions.launch(required.toTypedArray())
        } else start()
    }
    LaunchedEffect(context) {
        while (true) {
            state = withContext(Dispatchers.IO) {
                val outbox = TelemetryOutbox.get(context)
                UploadViewState(loaded = true, running = ServerUploadService.isRunning(),
                    pending = outbox.count(false), blocked = outbox.count(true),
                    lastAck = settings.preferences.getLong("last_ack_at", 0L),
                    error = settings.preferences.getString("last_error", "") ?: "",
                    batteryExempt = context.getSystemService(PowerManager::class.java)
                        .isIgnoringBatteryOptimizations(context.packageName))
            }
            delay(2_000L)
        }
    }
    val configured = settings.endpoint.isNotBlank() && !settings.preferences.getString("token", "").isNullOrBlank()
    return UploadController(address, token, configured, state, message,
        onAddress = { address = it }, onToken = { token = it },
        onSaveAndStart = {
            try {
                settings.save(address, token)
                token = ""
                address = settings.endpoint
                requestStart()
            } catch (error: IllegalArgumentException) { message = error.message ?: "Проверьте настройки" }
            catch (_: Exception) { message = "Не удалось сохранить настройки. Введите токен заново" }
        },
        onStart = {
            if (configured) requestStart() else message = "Сначала сохраните адрес и токен в настройках"
        },
        onStop = {
            settings.preferences.edit().putBoolean("enabled", false).apply()
            context.stopService(Intent(context, ServerUploadService::class.java))
            message = "Передача остановлена. Новые пакеты сохраняются в очередь"
        },
        onRetry = { ServerUploadService.packetReady(); message = "Запрос отправки выполнен" },
        onRetryBlocked = {
            scope.launch {
                try {
                    withContext(Dispatchers.IO) { TelemetryOutbox.get(context).retryBlocked() }
                    ServerUploadService.packetReady()
                    message = "Отложенные пакеты возвращены в очередь"
                } catch (_: Exception) { message = "Не удалось вернуть пакеты в очередь. Попробуйте снова" }
            }
        },
        onPowerSettings = {
            try { context.startActivity(Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS)) }
            catch (_: Exception) { message = "Откройте настройки батареи телефона вручную" }
        },
    )
}

@Composable
internal fun ServerUploadPanel(controller: UploadController, modifier: Modifier = Modifier, onSettings: () -> Unit) {
    val state = controller.state
    val muted = MaterialTheme.colorScheme.onSurfaceVariant
    Column(modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(horizontal = 20.dp, vertical = 10.dp),
        verticalArrangement = Arrangement.spacedBy(16.dp)) {
        Text("Передача данных", style = MaterialTheme.typography.headlineSmall)
        Text("Доставка показаний с телефона на домашний сервер", color = muted, style = MaterialTheme.typography.bodyMedium)
        HealthPanel {
            Text("Служба передачи", style = MaterialTheme.typography.titleMedium)
            HealthBadge(if (!state.loaded) "Проверяем службу" else if (state.running) "Работает" else "Остановлена", good = state.loaded && state.running)
            Text(if (state.lastAck > 0L) "Последнее подтверждение сервера: " + uploadTime(state.lastAck)
                else "Сервер ещё не подтверждал пакеты", style = MaterialTheme.typography.bodyMedium, color = muted)
            if (state.error.isNotBlank()) Text(state.error, color = MaterialTheme.colorScheme.error)
            if (state.running) {
                OutlinedButton(onClick = controller.onStop, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text("Остановить передачу") }
                Button(onClick = controller.onRetry, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text("Отправить очередь сейчас") }
            } else {
                Button(onClick = controller.onStart, enabled = state.loaded && controller.configured,
                    modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text("Запустить передачу") }
            }
            if (!controller.configured) Text("Сначала сохраните адрес и токен сервера в настройках", style = MaterialTheme.typography.bodyMedium)
        }
        HealthPanel {
            Text("Очередь пакетов", style = MaterialTheme.typography.titleMedium)
            Row(horizontalArrangement = Arrangement.spacedBy(24.dp)) {
                Column(Modifier.weight(1f)) {
                    Text(if (state.loaded) state.pending.toString() else "—", style = MaterialTheme.typography.headlineMedium)
                    Text("Ожидают отправки", color = muted, style = MaterialTheme.typography.bodySmall)
                }
                Column(Modifier.weight(1f)) {
                    Text(if (state.loaded) state.blocked.toString() else "—", style = MaterialTheme.typography.headlineMedium)
                    Text("Отложены сервером", color = muted, style = MaterialTheme.typography.bodySmall)
                }
            }
            Text("Отправить очередь — передать уже полученные пакеты. Это не запускает новый замер на часах.",
                style = MaterialTheme.typography.bodySmall, color = muted)
            if (state.blocked > 0) OutlinedButton(onClick = controller.onRetryBlocked,
                modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text("Повторить отложенные пакеты") }
        }
        if (controller.message.isNotBlank()) HealthPanel {
            Text(controller.message, style = MaterialTheme.typography.bodyMedium)
        }
        OutlinedButton(onClick = onSettings, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text("Настройки подключения") }
    }
}

@Composable
internal fun ServerSettingsPanel(controller: UploadController, modifier: Modifier = Modifier) {
    val focus = LocalFocusManager.current
    val keyboard = LocalSoftwareKeyboardController.current
    val muted = MaterialTheme.colorScheme.onSurfaceVariant
    Column(modifier.fillMaxSize().verticalScroll(rememberScrollState()).imePadding().padding(horizontal = 20.dp, vertical = 10.dp),
        verticalArrangement = Arrangement.spacedBy(16.dp)) {
        Text("Настройки", style = MaterialTheme.typography.headlineSmall)
        Text("Подключение и фоновая работа телефона", color = muted, style = MaterialTheme.typography.bodyMedium)
        HealthPanel {
            Text("Домашний сервер", style = MaterialTheme.typography.titleMedium)
            OutlinedTextField(value = controller.address, onValueChange = controller.onAddress,
                label = { Text("Адрес сервера") }, singleLine = true,
                placeholder = { Text("http://IP-компьютера:47863") },
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri, imeAction = ImeAction.Next),
                modifier = Modifier.fillMaxWidth())
            OutlinedTextField(value = controller.token, onValueChange = controller.onToken,
                label = { Text("Токен доступа") }, singleLine = true,
                supportingText = { Text(if (controller.configured) "Токен сохранён. Пустое поле оставит его прежним"
                    else "Введите TELEMETRY_TOKEN из настроек сервера") },
                visualTransformation = PasswordVisualTransformation(),
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password, imeAction = ImeAction.Done),
                modifier = Modifier.fillMaxWidth())
            Button(onClick = { focus.clearFocus(); keyboard?.hide(); controller.onSaveAndStart() },
                modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text("Сохранить и запустить") }
        }
        if (controller.message.isNotBlank()) HealthPanel {
            Text(controller.message, style = MaterialTheme.typography.bodyMedium)
        }
        HealthPanel {
            Text("Фоновая работа", style = MaterialTheme.typography.titleMedium)
            Text(if (controller.state.batteryExempt) "Оптимизация батареи для приложения отключена"
                else "Оптимизация батареи может ограничивать фоновую передачу",
                style = MaterialTheme.typography.bodyMedium)
            OutlinedButton(onClick = controller.onPowerSettings,
                modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text("Настройки питания телефона") }
            Text("Для Honor разрешите автоматический запуск, запуск другими приложениями и работу в фоне в ручном управлении запуском.",
                style = MaterialTheme.typography.bodySmall, color = muted)
            Text("Включённая передача восстанавливается после перезагрузки и первой разблокировки телефона.",
                style = MaterialTheme.typography.bodySmall, color = muted)
        }
    }
}

private fun uploadTime(timestamp: Long): String =
    SimpleDateFormat("dd.MM.yyyy HH:mm:ss", Locale.forLanguageTag("ru-RU")).format(Date(timestamp))

internal data class UploadViewState(
    val loaded: Boolean = false, val running: Boolean = false, val pending: Int = 0, val blocked: Int = 0,
    val lastAck: Long = 0, val error: String = "", val batteryExempt: Boolean = false,
)

internal class UploadController(
    val address: String, val token: String, val configured: Boolean,
    val state: UploadViewState, val message: String,
    val onAddress: (String) -> Unit, val onToken: (String) -> Unit,
    val onSaveAndStart: () -> Unit, val onStart: () -> Unit, val onStop: () -> Unit,
    val onRetry: () -> Unit, val onRetryBlocked: () -> Unit, val onPowerSettings: () -> Unit,
)
