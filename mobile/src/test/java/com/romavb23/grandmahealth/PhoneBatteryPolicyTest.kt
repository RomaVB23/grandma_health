package com.romavb23.grandmahealth

import org.junit.Assert.*
import org.junit.Test

class PhoneBatteryPolicyTest {
    @Test fun unknownOrInvalidBatteryIsNotReportedAsZero() {
        assertNull(PhoneBatteryPolicy.percent(-1, 100))
        assertNull(PhoneBatteryPolicy.percent(50, 0))
        assertNull(PhoneBatteryPolicy.percent(101, 100))
    }

    @Test fun endpointsAndDifferentScalesAreSupportedWithoutOverflow() {
        assertEquals(0, PhoneBatteryPolicy.percent(0, 100))
        assertEquals(100, PhoneBatteryPolicy.percent(100, 100))
        assertEquals(75, PhoneBatteryPolicy.percent(150, 200))
        assertEquals(100, PhoneBatteryPolicy.percent(Int.MAX_VALUE, Int.MAX_VALUE))
    }

    @Test fun watchPacketsDoNotCauseBatteryUploadOnEveryEvent() {
        assertTrue(PhoneBatteryPolicy.due(100, null))
        assertFalse(PhoneBatteryPolicy.due(59_999, 0))
        assertTrue(PhoneBatteryPolicy.due(60_000, 0))
    }
}
