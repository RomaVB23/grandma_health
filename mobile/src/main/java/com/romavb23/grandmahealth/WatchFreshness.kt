package com.romavb23.grandmahealth

/** Technical freshness only. These are not medical alarm thresholds. */
internal object WatchFreshness {
    const val CONTACT_TIMEOUT_MS = 600_000L
    const val MEASUREMENT_STALE_MS = 300_000L
    private const val CLOCK_TOLERANCE_MS = 120_000L

    fun heartbeatIsTimely(sentAt: Long, now: Long): Boolean =
        sentAt > 0L && sentAt >= now - CLOCK_TOLERANCE_MS && sentAt <= now + CLOCK_TOLERANCE_MS

    fun shouldReplaceMeasurement(incoming: Long, previous: Long, now: Long): Boolean =
        incoming > 0L && incoming > previous && incoming <= now + CLOCK_TOLERANCE_MS

    fun contactAgeMillis(
        lastContactElapsed: Long,
        lastContactBootCount: Int,
        currentBootCount: Int,
        nowElapsed: Long,
    ): Long? =
        if (lastContactBootCount >= 0 && lastContactBootCount == currentBootCount &&
            lastContactElapsed > 0L && nowElapsed >= lastContactElapsed
        ) {
            nowElapsed - lastContactElapsed
        } else null

    fun isContactRecent(age: Long?): Boolean = age != null && age < CONTACT_TIMEOUT_MS

    fun isMeasurementStale(measuredAt: Long, now: Long): Boolean =
        measuredAt <= 0L || measuredAt > now + CLOCK_TOLERANCE_MS ||
            now - measuredAt >= MEASUREMENT_STALE_MS
}
