package com.romavb23.grandmahealth.presentation

import org.junit.Assert.*
import org.junit.Test

class ScreenMeasurementControllerTest {
    private class Callback(val isActive: () -> Boolean, val registered: () -> Unit) {
        var accepted = 0
        fun sample() { if (isActive()) accepted++ }
    }

    private class Harness {
        val registrations = mutableListOf<Callback>()
        val removals = mutableListOf<Callback>()
        val completions = ArrayDeque<(Throwable?) -> Unit>()
        val errors = mutableListOf<Throwable>()
        var registrationError: RuntimeException? = null
        val controller = ScreenMeasurementController(
            createCallback = { active, registered -> Callback(active, registered) },
            register = { callback ->
                registrations += callback
                registrationError?.let { throw it }
            },
            unregister = { callback, complete ->
                removals += callback
                completions.addLast(complete)
            },
            onError = { errors += it },
        )
        fun complete(error: Throwable? = null) = completions.removeFirst().invoke(error)
    }

    @Test fun inactiveScreenDoesNotRegisterAndRepeatedResumeDoesNotDuplicate() {
        val h = Harness()
        h.controller.setActive(false)
        assertTrue(h.registrations.isEmpty())
        repeat(3) { h.controller.setActive(true) }
        assertEquals(1, h.registrations.size)
    }

    @Test fun pauseStopsDeliveryBeforeAsynchronousUnregistrationCompletes() {
        val h = Harness()
        h.controller.setActive(true)
        val callback = h.registrations.single()
        callback.sample()
        h.controller.setActive(false)
        callback.sample()
        assertEquals(1, callback.accepted)
        assertSame(callback, h.removals.single())
        h.complete()
        callback.sample()
        assertEquals(1, callback.accepted)
    }

    @Test fun rapidReopenWaitsForCleanupAndCreatesOneNewCallback() {
        val h = Harness()
        h.controller.setActive(true)
        val old = h.registrations.single()
        h.controller.setActive(false)
        repeat(3) { h.controller.setActive(true) }
        assertEquals(1, h.registrations.size)
        assertEquals(1, h.removals.size)
        h.complete()
        assertEquals(2, h.registrations.size)
        assertFalse(old.isActive())
        assertTrue(h.registrations.last().isActive())
    }

    @Test fun secondPauseWhileCleanupIsPendingPreventsRestart() {
        val h = Harness()
        h.controller.setActive(true)
        h.controller.setActive(false)
        h.controller.setActive(true)
        h.controller.setActive(false)
        h.complete()
        assertEquals(1, h.registrations.size)
    }

    @Test fun disposalWithPendingRestartCannotReactivateMeasurement() {
        val h = Harness()
        h.controller.setActive(true)
        h.controller.setActive(false)
        h.controller.setActive(true)
        h.controller.close()
        h.complete()
        h.controller.setActive(true)
        assertEquals(1, h.registrations.size)
        assertFalse(h.registrations.single().isActive())
    }

    @Test fun lateRegistrationDuringCleanupTriggersAnotherRemovalBeforeRestart() {
        val h = Harness()
        h.controller.setActive(true)
        val old = h.registrations.single()
        h.controller.setActive(false)
        h.controller.setActive(true)
        old.registered()
        h.complete()
        assertEquals(2, h.removals.size)
        assertEquals(1, h.registrations.size)
        h.complete()
        assertEquals(2, h.registrations.size)
    }

    @Test fun lateOldRegistrationCannotStopNewScreenSubscription() {
        val h = Harness()
        h.controller.setActive(true)
        val old = h.registrations.single()
        h.controller.setActive(false)
        h.complete()
        h.controller.setActive(true)
        val fresh = h.registrations.last()
        old.registered()
        assertSame(old, h.removals.last())
        assertTrue(fresh.isActive())
        assertFalse(old.isActive())
        h.complete()
        assertEquals(2, h.registrations.size)
        assertTrue(fresh.isActive())
    }

    @Test fun lateAcknowledgementAfterDisposalIsRemovedAgain() {
        val h = Harness()
        h.controller.setActive(true)
        val old = h.registrations.single()
        h.controller.close()
        h.complete()
        old.registered()
        assertEquals(2, h.removals.size)
        h.complete()
        assertEquals(1, h.registrations.size)
        assertFalse(old.isActive())
    }

    @Test fun cleanupFailureIsReportedAndBlocksNewRegistrationUntilRetrySucceeds() {
        val h = Harness()
        h.controller.setActive(true)
        h.controller.setActive(false)
        h.controller.setActive(true)
        val error = IllegalStateException("cleanup failed")
        h.complete(error)
        assertSame(error, h.errors.single())
        assertEquals(1, h.registrations.size)
        assertFalse(h.registrations.single().isActive())
        assertTrue(h.completions.isEmpty()) // No automatic retry loop.
        h.controller.setActive(true)
        h.complete()
        assertEquals(2, h.registrations.size)
    }

    @Test fun registrationExceptionStillCleansUpWithoutRetryLoop() {
        val h = Harness()
        val error = IllegalStateException("registration failed")
        h.registrationError = error
        h.controller.setActive(true)
        assertSame(error, h.errors.single())
        assertSame(h.registrations.single(), h.removals.single())
        assertFalse(h.registrations.single().isActive())
        h.complete()
        assertEquals(1, h.registrations.size)
    }
}
