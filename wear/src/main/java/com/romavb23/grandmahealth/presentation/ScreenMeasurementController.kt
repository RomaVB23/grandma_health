package com.romavb23.grandmahealth.presentation

/** Owns only the screen's subscription. All calls, including completions, run on the main thread. */
internal class ScreenMeasurementController<C>(
    private val createCallback: (isActive: () -> Boolean, onRegistered: () -> Unit) -> C,
    private val register: (C) -> Unit,
    private val unregister: (C, complete: (Throwable?) -> Unit) -> Unit,
    private val onError: (Throwable) -> Unit,
) {
    private inner class Session {
        var stopping = false
        var cleanupInFlight = false
        var cleanupAgain = false
        val callback = createCallback(
            { wanted && !closed && current === this && !stopping },
            {
                // A registration acknowledgement can arrive after the screen was closed,
                // including after the first unregistration completed.
                if (stopping || closed || !wanted || current !== this) stop(this, late = true)
            },
        )
    }

    private var wanted = false
    private var closed = false
    private var current: Session? = null

    fun setActive(active: Boolean) {
        wanted = active && !closed
        val session = current
        if (session != null && (!wanted || session.stopping)) {
            stop(session)
        } else if (wanted && session == null) {
            start()
        }
    }

    fun close() {
        closed = true
        setActive(false)
    }

    private fun start() {
        val session = Session()
        current = session
        try {
            register(session.callback)
        } catch (error: Exception) {
            // Stop even if registration threw after partially reaching the service.
            wanted = false
            onError(error)
            stop(session)
        }
    }

    private fun stop(session: Session, late: Boolean = false) {
        session.stopping = true
        if (session.cleanupInFlight) {
            if (late) session.cleanupAgain = true
            return
        }
        session.cleanupInFlight = true
        try {
            unregister(session.callback) { error ->
                session.cleanupInFlight = false
                if (error != null) {
                    // Keep ownership and block a replacement subscription. A later lifecycle
                    // event or late onRegistered may retry; there is no background retry loop.
                    session.cleanupAgain = false
                    onError(error)
                } else if (session.cleanupAgain) {
                    session.cleanupAgain = false
                    stop(session)
                } else if (current === session) {
                    current = null
                    if (wanted && !closed) start()
                }
            }
        } catch (error: Exception) {
            session.cleanupInFlight = false
            onError(error)
        }
    }
}
