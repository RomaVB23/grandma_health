package com.romavb23.grandmahealth

import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

@Composable
internal fun MeasurementRequestPanel(state: MeasurementRequestState, nowElapsed: Long, onRequest: () -> Unit) {
    val elapsed = (nowElapsed - state.startedElapsed).coerceAtLeast(0L)
    Button(onClick = onRequest, enabled = !state.pending && state.cooldownRemaining == 0L, modifier = Modifier.fillMaxWidth()) {
        Text(when {
            state.pending -> "Ожидаем новый замер…"
            state.cooldownRemaining > 0L -> "Повтор через ${(state.cooldownRemaining + 999L) / 1000} с"
            else -> "Измерить сейчас"
        })
    }
    val message = when (state.status) {
        "idle" -> "Кнопка отправит часам запрос нового замера. Экран часов можно оставить погашенным."
        "sending" -> "Отправляем запрос часам · ${elapsed / 1000} с. Если ответа не будет, ожидание завершится через 75 с."
        "measuring" -> "Часы приняли запрос и измеряют пульс · ${elapsed / 1000} с."
        "success" -> "Замер по запросу: ${state.bpm} уд/мин\nИзмерен: " +
            SimpleDateFormat("dd.MM.yyyy HH:mm:ss", Locale.forLanguageTag("ru-RU")).format(Date(state.measuredAt))
        "off_body" -> "Часы сняты: замер не выполнен."
        "wearing_unknown" -> "Часы пока не определили ношение. Повтори запрос после сигнала «На руке»."
        "monitoring_stopped" -> "На часах не работает служба мониторинга. Запусти её в приложении часов."
        "permission_lost" -> "У приложения часов нет разрешения на пульс в фоне. Проверь разрешения."
        "unsupported" -> "Часы не поддерживают этот способ разового замера."
        "sensor_error" -> "Часы не смогли запустить разовый замер. Фоновый мониторинг продолжает работать."
        "no_connection", "send_failed" -> "Не удалось передать запрос часам. Проверь связь телефона и часов."
        "multiple_watches" -> "Подключено несколько часов. Оставь подключёнными нужные часы."
        "invalid_result" -> "Ответ не прошёл проверку времени нового замера. Проверь время телефона и часов."
        "busy" -> "На часах уже выполняется другой запрос."
        "cooldown", "duplicate_request" -> "Часы отклонили повторный запрос. Подожди 10 секунд."
        "cancelled" -> "Замер прерван: служба часов остановлена."
        "clock_unavailable" -> "Не удалось проверить перезапуск телефона. Повтори после перезагрузки."
        else -> "Новый замер не получен за время ожидания. Проверь связь и посадку часов."
    }
    Text(message, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
}
