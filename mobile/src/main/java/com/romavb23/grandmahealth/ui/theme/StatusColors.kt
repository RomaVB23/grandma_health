package com.romavb23.grandmahealth.ui.theme

import androidx.compose.material3.MaterialTheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.luminance

/** Colours describe wearing/battery state, not the patient's medical condition. */
internal enum class StatusTone { NEUTRAL, GREEN, AMBER, RED }

internal fun batteryStatusTone(percent: Int): StatusTone = when (percent) {
    in 0..20 -> StatusTone.RED
    in 21..59 -> StatusTone.AMBER
    in 60..100 -> StatusTone.GREEN
    else -> StatusTone.NEUTRAL
}

internal fun wearingStatusTone(state: String): StatusTone = when (state) {
    "on" -> StatusTone.GREEN
    "off" -> StatusTone.RED
    else -> StatusTone.NEUTRAL
}

internal data class StatusColors(val container: Color, val value: Color)

@Composable
internal fun statusColors(tone: StatusTone): StatusColors {
    val theme = MaterialTheme.colorScheme
    val dark = theme.surface.luminance() < 0.5f
    return when (tone) {
        StatusTone.GREEN -> if (dark) StatusColors(Color(0xFF1C352B), Color(0xFF73D6A6))
            else StatusColors(Color(0xFFEDF8F1), Color(0xFF24724F))
        StatusTone.AMBER -> if (dark) StatusColors(Color(0xFF3B3220), Color(0xFFF3CE73))
            else StatusColors(Color(0xFFFFF8E7), Color(0xFF8A5A00))
        StatusTone.RED -> if (dark) StatusColors(Color(0xFF3C2531), Color(0xFFFF9BAC))
            else StatusColors(Color(0xFFFFF0F2), Color(0xFFB4233A))
        StatusTone.NEUTRAL -> StatusColors(theme.surface, theme.onSurfaceVariant)
    }
}
