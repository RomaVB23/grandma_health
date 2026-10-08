package com.romavb23.grandmahealth

internal object PhoneBatteryPolicy {
    const val INTERVAL_MS = 60_000L

    fun percent(level: Int, scale: Int): Int? {
        if (scale <= 0 || level !in 0..scale) return null
        return (level.toLong() * 100 / scale).toInt()
    }

    fun due(now: Long, lastAttempt: Long?): Boolean =
        lastAttempt == null || now - lastAttempt >= INTERVAL_MS
}
