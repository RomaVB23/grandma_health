package com.romavb23.grandmahealth

import org.junit.Assert.*
import org.junit.Test

class RemoteMeasurementRulesTest {
    private val id = "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa"
    @Test fun onlyWellFormedTimelyCommandsCanStart() {
        assertTrue(RemoteMeasurementRules.validCommand(id, 80000, 1_000_000, 1_000_000))
        assertFalse(RemoteMeasurementRules.validCommand("bad", 80000, 1_000_000, 1_000_000))
        assertFalse(RemoteMeasurementRules.validCommand(id, 0, 1_000_000, 1_000_000))
        assertFalse(RemoteMeasurementRules.validCommand(id, 100001, 1_000_000, 1_000_000))
    }
    @Test fun clockSkewAndFutureCommandsAreRejected() {
        assertFalse(RemoteMeasurementRules.validCommand(id, 80000, 879999, 1_000_000))
        assertFalse(RemoteMeasurementRules.validCommand(id, 80000, 1120001, 1_000_000))
        assertTrue(RemoteMeasurementRules.validCommand(id, 80000, 880000, 1_000_000))
    }
    @Test fun restartOrElapsedDeadlineCancelsInsteadOfReplaying() {
        assertTrue(RemoteMeasurementRules.expired(4, 5, 1000, 200))
        assertTrue(RemoteMeasurementRules.expired(-1, -1, 1000, 200))
        assertTrue(RemoteMeasurementRules.expired(4, 4, 1000, 1000))
        assertFalse(RemoteMeasurementRules.expired(4, 4, 1000, 999))
    }
}
