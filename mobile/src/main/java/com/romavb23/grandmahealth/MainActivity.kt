package com.romavb23.grandmahealth

import android.content.Context
import android.content.SharedPreferences
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
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

    DisposableEffect(preferences) {
        val listener =
            SharedPreferences.OnSharedPreferenceChangeListener { _, key ->
                if (key == HEART_RATE_KEY_BPM || key == HEART_RATE_KEY_MEASURED_AT) {
                    reading = preferences.readHeartRate()
                }
            }

        preferences.registerOnSharedPreferenceChangeListener(listener)
        onDispose {
            preferences.unregisterOnSharedPreferenceChangeListener(listener)
        }
    }

    HeartRateContent(
        reading = reading,
        modifier = modifier,
    )
}

@Composable
private fun HeartRateContent(
    reading: HeartRateReading?,
    modifier: Modifier = Modifier,
) {
    Column(
        modifier =
            modifier
                .fillMaxSize()
                .padding(horizontal = 24.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center,
    ) {
        Text(
            text = "Состояние бабушки",
            style = MaterialTheme.typography.headlineSmall,
            fontWeight = FontWeight.Bold,
        )
        Text(
            text = reading?.bpm?.toString() ?: "—",
            modifier = Modifier.padding(top = 28.dp),
            style = MaterialTheme.typography.displayLarge,
            fontWeight = FontWeight.Bold,
        )
        Text(
            text = "уд/мин",
            style = MaterialTheme.typography.titleMedium,
        )
        Text(
            text =
                if (reading == null) {
                    "Ожидаем данные с Galaxy Watch"
                } else {
                    "Получено с часов: ${formatTime(reading.measuredAt)}"
                },
            modifier = Modifier.padding(top = 24.dp),
            style = MaterialTheme.typography.bodyLarge,
        )
    }
}

@Preview(showBackground = true)
@Composable
private fun HeartRatePreview() {
    GrandmaHealthTheme {
        HeartRateContent(
            reading = HeartRateReading(bpm = 72, measuredAt = System.currentTimeMillis()),
        )
    }
}

private fun SharedPreferences.readHeartRate(): HeartRateReading? {
    val bpm = getInt(HEART_RATE_KEY_BPM, -1)
    val measuredAt = getLong(HEART_RATE_KEY_MEASURED_AT, 0L)

    return if (bpm > 0 && measuredAt > 0L) {
        HeartRateReading(bpm = bpm, measuredAt = measuredAt)
    } else {
        null
    }
}

private fun formatTime(timestamp: Long): String =
    SimpleDateFormat("dd.MM.yyyy HH:mm:ss", Locale("ru", "RU")).format(Date(timestamp))

private data class HeartRateReading(
    val bpm: Int,
    val measuredAt: Long,
)
