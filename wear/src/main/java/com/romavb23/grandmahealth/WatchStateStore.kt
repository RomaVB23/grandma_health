package com.romavb23.grandmahealth

import android.content.Context

/** Shared by active measurements, passive batches and the foreground service. */
internal object WatchStateStore {
    const val PREFERENCES = "watch_monitoring"
    const val STATUS = "status"
    private const val ENABLED = "enabled"
    private const val BPM = "bpm"
    private const val MEASURED_AT = "measured_at"

    fun preferences(context: Context) =
        context.getSharedPreferences(PREFERENCES, Context.MODE_PRIVATE)

    fun isEnabled(context: Context): Boolean = preferences(context).getBoolean(ENABLED, true)

    fun setEnabled(context: Context, enabled: Boolean) {
        preferences(context).edit().putBoolean(ENABLED, enabled).apply()
    }

    fun status(context: Context): String = preferences(context).getString(STATUS, "stopped")!!

    fun setStatus(context: Context, status: String) {
        preferences(context).edit().putString(STATUS, status).apply()
    }

    @Synchronized
    fun saveHeartRate(context: Context, bpm: Int, measuredAt: Long): Boolean {
        if (bpm <= 0 || measuredAt <= 0L || !isEnabled(context)) return false
        val preferences = preferences(context)
        // The same sample can arrive through both passive channels. Never regress the cache.
        if (measuredAt <= preferences.getLong(MEASURED_AT, 0L)) return false
        preferences.edit().putInt(BPM, bpm).putLong(MEASURED_AT, measuredAt).apply()
        return true
    }

    @Synchronized
    fun heartRate(context: Context): Pair<Int, Long> {
        val preferences = preferences(context)
        return preferences.getInt(BPM, -1) to preferences.getLong(MEASURED_AT, 0L)
    }
}
