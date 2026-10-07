package com.romavb23.grandmahealth

internal object SpotMeasurementRules {
    const val TIMEOUT_MS = 60_000L
    const val COOLDOWN_MS = 10_000L

    fun acceptsSample(bpm: Double, started: Long, sampled: Long, now: Long): Boolean =
        bpm.isFinite() && bpm in 1.0..300.0 && started >= 0L && sampled > started && sampled <= now &&
            now >= started && now - started < TIMEOUT_MS

    // SensorEvent timestamps use elapsedRealtimeNanos. Accuracy 0 is unreliable,
    // -1 is no contact; neither may produce a successful requested measurement.
    fun acceptsSensorSample(bpm: Double, accuracy: Int, startedNanos: Long, sampledNanos: Long, nowNanos: Long): Boolean =
        accuracy in 1..3 && startedNanos >= 0L && sampledNanos > startedNanos && sampledNanos <= nowNanos &&
            acceptsSample(bpm, startedNanos / 1_000_000L, sampledNanos / 1_000_000L, nowNanos / 1_000_000L)
}
