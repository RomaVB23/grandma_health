package com.romavb23.grandmahealth

/** A requested result is separate from the ordinary stream of cached readings. */
internal object MeasurementRequestRules {
    const val TIMEOUT_MS = 75_000L
    const val COOLDOWN_MS = 10_000L
    val pendingStatuses = setOf("sending", "measuring")

    fun isPending(status: String) = status in pendingStatuses

    fun isAlive(status: String, requestBoot: Int, boot: Int, started: Long, now: Long): Boolean =
        isPending(status) && requestBoot >= 0 && requestBoot == boot &&
            started >= 0L && now >= started && now - started < TIMEOUT_MS

    fun matches(requestId: String, responseId: String, nodeId: String, sourceNodeId: String): Boolean =
        requestId.isNotEmpty() && requestId == responseId && nodeId.isNotEmpty() && nodeId == sourceNodeId

    // Watch monotonic timestamps prove that this sample followed the command,
    // independently of differences between the phone and watch wall clocks.
    fun isNewSample(bpm: Int, started: Long, sampled: Long, measuredAt: Long, now: Long): Boolean =
        bpm in 1..300 && started >= 0L && sampled > started && sampled - started <= 60_000L &&
            measuredAt > 0L && measuredAt >= now - WatchFreshness.CLOCK_TOLERANCE_MS &&
            measuredAt <= now + WatchFreshness.CLOCK_TOLERANCE_MS
}
