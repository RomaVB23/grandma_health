package com.romavb23.grandmahealth

import org.junit.Assert.assertEquals
import org.junit.Test

class AutomaticMeasurementTriggerTest {
    private class Fixture {
        var retryDelay: Long? = null
        var cancels = 0
        val reasons = mutableListOf<String>()
        val deadlines = mutableListOf<Pair<Long, () -> Unit>>()
        val trigger = AutomaticMeasurementTrigger(
            attempt = { reason -> reasons.add(reason); retryDelay },
            schedule = { delay, action ->
                deadlines.add(delay to action)
                val cancel: () -> Unit = { cancels++ }
                cancel
            },
        )
    }

    @Test fun cooldownQueuesOneRetryWithoutExtendingItOnRepeatedSignals() {
        val f = Fixture()
        f.retryDelay = 7000L
        f.trigger.trigger("on_wrist")
        f.trigger.trigger("heartbeat")
        assertEquals(listOf("on_wrist"), f.reasons)
        assertEquals(7000L, f.deadlines.single().first)
        f.retryDelay = null
        f.deadlines.single().second()
        assertEquals(listOf("on_wrist", "on_wrist"), f.reasons)
    }

    @Test fun cooldownDoesNotTurnIntoAnUnboundedRetryLoop() {
        val f = Fixture()
        f.retryDelay = 10000L
        f.trigger.trigger("heartbeat")
        f.deadlines.single().second()
        assertEquals(2, f.reasons.size)
        assertEquals(1, f.deadlines.size)
    }

    @Test fun removalCancelsRetryAndOldCallbackCannotTriggerAfterWearingAgain() {
        val f = Fixture()
        f.retryDelay = 5000L
        f.trigger.trigger("on_wrist")
        val oldCallback = f.deadlines.single().second
        f.trigger.cancelPending()
        f.trigger.trigger("on_wrist")
        oldCallback()
        assertEquals(2, f.reasons.size)
        assertEquals(1, f.cancels)
        f.retryDelay = null
        f.deadlines.last().second()
        assertEquals(3, f.reasons.size)
    }

    @Test fun serviceDestructionPreventsPendingAndNewRequests() {
        val f = Fixture()
        f.retryDelay = 5000L
        f.trigger.trigger("heartbeat")
        f.trigger.close()
        f.deadlines.single().second()
        f.trigger.trigger("on_wrist")
        assertEquals(1, f.reasons.size)
        assertEquals(1, f.cancels)
    }

    @Test fun completedOrDeclinedAttemptCreatesNoTimer() {
        val f = Fixture()
        f.trigger.trigger("heartbeat")
        assertEquals(0, f.deadlines.size)
        f.trigger.trigger("heartbeat")
        assertEquals(2, f.reasons.size)
    }

    @Test fun retryRechecksEligibilityInsteadOfAssumingTheWatchIsStillWorn() {
        var worn = true
        var attempts = 0
        var sensorStarts = 0
        var deliver: (() -> Unit)? = null
        val trigger = AutomaticMeasurementTrigger(
            attempt = {
                attempts++
                if (attempts == 1) 5000L else {
                    if (worn) sensorStarts++
                    null
                }
            },
            schedule = { _, action -> deliver = action; {} },
        )
        trigger.trigger("on_wrist")
        worn = false
        deliver!!()
        assertEquals(2, attempts)
        assertEquals(0, sensorStarts)
    }
}
