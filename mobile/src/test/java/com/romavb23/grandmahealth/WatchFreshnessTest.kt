package com.romavb23.grandmahealth

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class WatchFreshnessTest {
    private val now = 1_800_000_000_000L

    @Test fun timelyHeartbeatDoesNotRequireAPulse() {
        assertTrue(WatchFreshness.heartbeatIsTimely(now, now))
        assertFalse(WatchFreshness.heartbeatIsTimely(0L, now))
    }

    @Test fun delayedAndFarFutureHeartbeatsAreRejected() {
        assertFalse(WatchFreshness.heartbeatIsTimely(now - 120_001L, now))
        assertFalse(WatchFreshness.heartbeatIsTimely(now + 120_001L, now))
    }

    @Test fun olderOrDuplicateMeasurementCannotReplaceLatest() {
        assertFalse(WatchFreshness.shouldReplaceMeasurement(now - 1L, now, now))
        assertFalse(WatchFreshness.shouldReplaceMeasurement(now, now, now))
        assertTrue(WatchFreshness.shouldReplaceMeasurement(now, now - 1L, now))
    }

    @Test fun invalidOrFutureMeasurementIsRejected() {
        assertFalse(WatchFreshness.shouldReplaceMeasurement(0L, 0L, now))
        assertFalse(WatchFreshness.shouldReplaceMeasurement(now + 120_001L, 0L, now))
    }

    @Test fun ageUsesMonotonicClockWithinSamePhoneBoot() {
        assertEquals(180_000L, WatchFreshness.contactAgeMillis(1_000L, 4, 4, 181_000L))
        assertTrue(WatchFreshness.isContactRecent(180_000L))
    }

    @Test fun previousPhoneBootOrUnknownBootIsNotFresh() {
        assertNull(WatchFreshness.contactAgeMillis(1_000L, 4, 5, 181_000L))
        assertNull(WatchFreshness.contactAgeMillis(1_000L, -1, -1, 181_000L))
        assertFalse(WatchFreshness.isContactRecent(null))
    }

    @Test fun connectionStatusExpiresWithoutReceivingNewPackets() {
        assertTrue(WatchFreshness.isContactRecent(599_999L))
        assertFalse(WatchFreshness.isContactRecent(600_000L))
    }

    @Test fun freshContactDoesNotMakeAnOldPulseFresh() {
        assertTrue(WatchFreshness.isContactRecent(0L))
        assertTrue(WatchFreshness.isMeasurementStale(now - 300_000L, now))
        assertFalse(WatchFreshness.isMeasurementStale(now - 299_999L, now))
    }
}
