package com.romavb23.grandmahealth

import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class MeasurementRequestRulesTest {
    private val now = 1_800_000_000_000L

    @Test fun responseMustComeFromTheSelectedWatchForTheCurrentRequest() {
        assertTrue(MeasurementRequestRules.matches("new", "new", "watch", "watch"))
        assertFalse(MeasurementRequestRules.matches("new", "old", "watch", "watch"))
        assertFalse(MeasurementRequestRules.matches("new", "new", "watch", "other-watch"))
        assertFalse(MeasurementRequestRules.matches("", "", "watch", "watch"))
        assertFalse(MeasurementRequestRules.matches("new", "new", "", ""))
    }

    @Test fun exactlyAtDeadlineAnAnswerCanNoLongerCompleteTheRequest() {
        assertTrue(MeasurementRequestRules.isAlive("measuring", 4, 4, 1000L, 75_999L))
        assertFalse(MeasurementRequestRules.isAlive("measuring", 4, 4, 1000L, 76_000L))
    }

    @Test fun restartAndMissingBootIdentityInvalidatePendingRequest() {
        assertFalse(MeasurementRequestRules.isAlive("sending", 4, 5, 1000L, 2000L))
        assertFalse(MeasurementRequestRules.isAlive("sending", -1, -1, 1000L, 2000L))
        assertFalse(MeasurementRequestRules.isAlive("sending", 4, 4, 2000L, 1000L))
    }

    @Test fun terminalRequestCannotBeCompletedTwice() {
        for (status in listOf("success", "timeout", "off_body", "idle")) {
            assertFalse(MeasurementRequestRules.isAlive(status, 4, 4, 1000L, 2000L))
        }
    }

    @Test fun aCachedSampleBeforeOrAtCommandCannotBeReportedAsNew() {
        assertFalse(MeasurementRequestRules.isNewSample(75, 1000L, 999L, now, now))
        assertFalse(MeasurementRequestRules.isNewSample(75, 1000L, 1000L, now, now))
        assertTrue(MeasurementRequestRules.isNewSample(75, 1000L, 1001L, now, now))
    }

    @Test fun delayedAndFutureWallClockReadingsAreRejected() {
        assertFalse(MeasurementRequestRules.isNewSample(75, 1000L, 2000L, now - 120_001L, now))
        assertFalse(MeasurementRequestRules.isNewSample(75, 1000L, 2000L, now + 120_001L, now))
        assertFalse(MeasurementRequestRules.isNewSample(75, 1000L, 2000L, 0L, now))
    }

    @Test fun sampleMustBeInsideTheWatchMeasurementWindow() {
        assertTrue(MeasurementRequestRules.isNewSample(75, 1000L, 61_000L, now, now))
        assertFalse(MeasurementRequestRules.isNewSample(75, 1000L, 61_001L, now, now))
        assertFalse(MeasurementRequestRules.isNewSample(0, 1000L, 2000L, now, now))
        assertFalse(MeasurementRequestRules.isNewSample(301, 1000L, 2000L, now, now))
        assertFalse(MeasurementRequestRules.isNewSample(75, -1L, 2000L, now, now))
    }
}
