package com.romavb23.grandmahealth

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.os.BatteryManager
import android.util.Log
import androidx.core.content.ContextCompat

/** Observes power connection, including a full battery still sitting on its charger. */
internal class WatchChargingTracker(private val context: Context, private val changed: (State) -> Unit) {
    private var registered = false
    private var last: Boolean? = null
    private val receiver = object : BroadcastReceiver() {
        override fun onReceive(context: Context, intent: Intent) {
            val state = record(context, intent, reset = last == null)
            if (last != state.charging) {
                last = state.charging
                Log.i("GrandmaCharging", "Charging changed: ${state.charging} since=${state.since}")
                changed(state)
            }
        }
    }

    fun start() {
        if (registered) return
        last = null
        ContextCompat.registerReceiver(context, receiver, IntentFilter(Intent.ACTION_BATTERY_CHANGED),
            ContextCompat.RECEIVER_NOT_EXPORTED)
        registered = true
    }

    fun stop() {
        if (registered) context.unregisterReceiver(receiver)
        registered = false
        last = null
    }

    internal data class State(val charging: Boolean, val since: Long)

    companion object {
        private const val STATE = "charger_connected"
        private const val SINCE = "charger_since"

        fun snapshot(context: Context): State {
            val intent = context.registerReceiver(null, IntentFilter(Intent.ACTION_BATTERY_CHANGED))
            if (intent != null) return record(context, intent)
            // No transition timestamp is asserted when Android's sticky snapshot is unavailable.
            return State(context.getSystemService(BatteryManager::class.java)?.isCharging == true, 0L)
        }

        @Synchronized
        private fun record(context: Context, intent: Intent, reset: Boolean = false): State {
            val now = System.currentTimeMillis()
            val connected = intent.getIntExtra(BatteryManager.EXTRA_PLUGGED, 0) != 0
            val prefs = WatchStateStore.preferences(context)
            val oldSince = prefs.getLong(SINCE, 0L)
            val since = if (reset || !prefs.contains(STATE)) 0L
                else if (prefs.getBoolean(STATE, false) != connected || oldSince > now) now else oldSince
            if (oldSince != since || !prefs.contains(STATE) || prefs.getBoolean(STATE, false) != connected) {
                prefs.edit().putBoolean(STATE, connected).putLong(SINCE, since).apply()
            }
            // Restarting observation clears the bound; missed transitions during a stopped
            // service must not be presented as a continuously observed charging session.
            return State(connected, since)
        }
    }
}
