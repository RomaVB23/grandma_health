package com.romavb23.grandmahealth

import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class SpotMeasurementRulesTest {
    @Test fun unreliableNoContactAndUnknownAccuracyDoNotProduceASuccess() {
        for (accuracy in listOf(-1, 0, 4)) {
            assertFalse(SpotMeasurementRules.acceptsSensorSample(75.0, accuracy, 1000000000L, 1500000000L, 2000000000L))
        }
        for (accuracy in 1..3) {
            assertTrue(SpotMeasurementRules.acceptsSensorSample(75.0, accuracy, 1000000000L, 1500000000L, 2000000000L))
        }
    }

    @Test fun directSensorCacheAndFutureTimestampsAreRejected() {
        assertFalse(SpotMeasurementRules.acceptsSensorSample(75.0, 3, 1000000000L, 999000000L, 2000000000L))
        assertFalse(SpotMeasurementRules.acceptsSensorSample(75.0, 3, 1000000000L, 2000000001L, 2000000000L))
        assertFalse(SpotMeasurementRules.acceptsSensorSample(75.0, 3, 1000000000L, 1500000000L, 61000000000L))
    }

    @Test fun cachedAndFutureBootTimestampsAreNotNewMeasurements() {
        assertFalse(SpotMeasurementRules.acceptsSample(75.0, 1000L, 999L, 2000L))
        assertFalse(SpotMeasurementRules.acceptsSample(75.0, 1000L, 1000L, 2000L))
        assertFalse(SpotMeasurementRules.acceptsSample(75.0, 1000L, 2001L, 2000L))
        assertTrue(SpotMeasurementRules.acceptsSample(75.0, 1000L, 1500L, 2000L))
    }

    @Test fun noResultAfterTheWatchDeadlineEvenIfTheSampleItselfWasEarlier() {
        assertTrue(SpotMeasurementRules.acceptsSample(75.0, 1000L, 60_000L, 60_999L))
        assertFalse(SpotMeasurementRules.acceptsSample(75.0, 1000L, 60_000L, 61_000L))
    }

    @Test fun invalidSensorValuesAndInvalidStartAreRejected() {
        for (value in listOf(Double.NaN, Double.POSITIVE_INFINITY, 0.0, -75.0, 301.0)) {
            assertFalse(SpotMeasurementRules.acceptsSample(value, 1000L, 1500L, 2000L))
        }
        assertFalse(SpotMeasurementRules.acceptsSample(75.0, -1L, 1500L, 2000L))
    }
}
