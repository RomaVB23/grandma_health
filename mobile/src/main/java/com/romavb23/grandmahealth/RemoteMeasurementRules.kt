package com.romavb23.grandmahealth

internal object RemoteMeasurementRules {
    fun validCommand(id: String, remaining: Long, serverTime: Long, phoneTime: Long): Boolean =
        id.matches(Regex("[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}")) &&
            remaining in 1L..100_000L && serverTime > 0L &&
            serverTime >= phoneTime - WatchFreshness.CLOCK_TOLERANCE_MS &&
            serverTime <= phoneTime + WatchFreshness.CLOCK_TOLERANCE_MS

    fun expired(boot: Int, currentBoot: Int, deadline: Long, now: Long): Boolean =
        boot < 0 || boot != currentBoot || deadline <= 0L || now >= deadline
}
