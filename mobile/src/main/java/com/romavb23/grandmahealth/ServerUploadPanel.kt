package com.romavb23.grandmahealth

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.os.PowerManager
import android.provider.Settings
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.withContext

@Composable
internal fun ServerUploadPanel() {
    val context = LocalContext.current
    val settings = remember { UploadSettings(context) }
    var address by remember { mutableStateOf(settings.endpoint) }
    var token by remember { mutableStateOf("") }
    var message by remember { mutableStateOf("") }
    var status by remember { mutableStateOf(UploadViewState()) }

    fun start() {
        if (context.checkSelfPermission(Manifest.permission.BLUETOOTH_CONNECT) != PackageManager.PERMISSION_GRANTED) {
            message = "Разрешите доступ к устройствам поблизости для фоновой передачи"
            return
        }
        try {
            context.startForegroundService(Intent(context, ServerUploadService::class.java))
            message = "Запуск передачи. Проверьте подтверждение сервера ниже"
        } catch (_: Exception) { message = "Не удалось запустить службу передачи" }
    }

    val permissions = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { start() }
    LaunchedEffect(Unit) {
        while (true) {
            status = withContext(Dispatchers.IO) {
                val outbox = TelemetryOutbox.get(context)
                UploadViewState(
                    running = ServerUploadService.isRunning(),
                    pending = outbox.count(false), blocked = outbox.count(true),
                    lastAck = settings.preferences.getLong("last_ack_at", 0L),
                    error = settings.preferences.getString("last_error", "") ?: "",
                    batteryExempt = context.getSystemService(PowerManager::class.java)
                        .isIgnoringBatteryOptimizations(context.packageName),
                )
            }
            delay(2_000L)
        }
    }

    Column(Modifier.fillMaxWidth().padding(top = 24.dp)) {
        Text("Передача на домашний сервер", style = MaterialTheme.typography.titleLarge)
        OutlinedTextField(value = address, onValueChange = { address = it },
            label = { Text("Адрес сервера") }, singleLine = true,
            placeholder = { Text("http://IP-компьютера:47863") },
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri), modifier = Modifier.fillMaxWidth())
        OutlinedTextField(value = token, onValueChange = { token = it },
            label = { Text("TELEMETRY_TOKEN") }, singleLine = true,
            placeholder = { Text("Пусто — оставить сохранённый токен") },
            visualTransformation = PasswordVisualTransformation(),
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password), modifier = Modifier.fillMaxWidth())
        Button(onClick = {
            try {
                settings.save(address, token)
                token = ""
                address = settings.endpoint
                val required = mutableListOf(Manifest.permission.BLUETOOTH_CONNECT)
                if (Build.VERSION.SDK_INT >= 33) required.add(Manifest.permission.POST_NOTIFICATIONS)
                if (required.any { context.checkSelfPermission(it) != PackageManager.PERMISSION_GRANTED }) {
                    permissions.launch(required.toTypedArray())
                } else start()
            } catch (error: IllegalArgumentException) { message = error.message ?: "Проверьте настройки" }
            catch (_: Exception) { message = "Не удалось сохранить настройки. Введите токен заново" }
        }) { Text("Сохранить и запустить") }
        if (status.running) {
            OutlinedButton(onClick = {
                settings.preferences.edit().putBoolean("enabled", false).apply()
                context.stopService(Intent(context, ServerUploadService::class.java))
                message = "Передача остановлена. Новые пакеты сохраняются в очередь"
            }) { Text("Остановить передачу") }
            OutlinedButton(onClick = { ServerUploadService.packetReady(); message = "Запрос отправки выполнен" }) {
                Text("Повторить отправку сейчас")
            }
        }
        Text(if (status.running) "Служба передачи работает" else "Служба передачи остановлена")
        Text("В очереди: ${status.pending} · Отложено из-за отказа сервера: ${status.blocked}")
        Text(if (status.lastAck > 0) "Последнее подтверждение: " +
            SimpleDateFormat("dd.MM.yyyy HH:mm:ss", Locale.forLanguageTag("ru-RU")).format(Date(status.lastAck))
            else "Сервер ещё не подтверждал пакеты")
        if (status.error.isNotBlank()) Text(status.error, color = MaterialTheme.colorScheme.error)
        if (status.blocked > 0) {
            OutlinedButton(onClick = {
                TelemetryOutbox.get(context).retryBlocked()
                ServerUploadService.packetReady()
                message = "Отложенные пакеты возвращены в очередь"
            }) { Text("Повторить отложенные пакеты") }
        }
        if (message.isNotBlank()) Text(message, modifier = Modifier.padding(top = 8.dp))
        Text(if (status.batteryExempt) "Оптимизация батареи для приложения отключена"
            else "Для проверки ночной работы отключите оптимизацию батареи для Grandma Health",
            modifier = Modifier.padding(top = 12.dp))
        OutlinedButton(onClick = {
            try { context.startActivity(Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS)) }
            catch (_: Exception) { message = "Откройте настройки батареи телефона вручную" }
        }) { Text("Настройки питания телефона") }
        Text("Включённая передача восстанавливается после перезагрузки и первой разблокировки. " +
            "Для Honor разрешите приложению автоматический запуск и работу в фоне в настройках питания.",
            style = MaterialTheme.typography.bodySmall)
    }
}

private data class UploadViewState(
    val running: Boolean = false, val pending: Int = 0, val blocked: Int = 0,
    val lastAck: Long = 0, val error: String = "", val batteryExempt: Boolean = false,
)
