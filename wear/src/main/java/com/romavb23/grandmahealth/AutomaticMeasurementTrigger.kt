package com.romavb23.grandmahealth

/** Main-thread owner of at most one cooldown retry. No retry loop or catch-up measurements. */
internal class AutomaticMeasurementTrigger(
    // A positive delay means cooldown; null means started, busy, or not eligible.
    private val attempt: (String) -> Long?,
    private val schedule: (Long, () -> Unit) -> (() -> Unit),
) {
    private class Retry { var cancel: (() -> Unit)? = null }
    private var pending: Retry? = null
    private var closed = false

    fun trigger(reason: String) {
        if (closed || pending != null) return
        val delay = attempt(reason) ?: return
        if (delay <= 0L) return
        val retry = Retry()
        pending = retry
        val cancel = schedule(delay) {
            if (!closed && pending === retry) {
                pending = null
                // Re-check wearing, monitoring and the shared sensor slot at delivery time.
                // If still unavailable, wait for the next regular trigger.
                attempt(reason)
            }
        }
        retry.cancel = cancel
        if (pending !== retry) cancel()
    }

    fun cancelPending() {
        val retry = pending ?: return
        pending = null
        retry.cancel?.invoke()
    }

    fun close() {
        closed = true
        cancelPending()
    }
}
