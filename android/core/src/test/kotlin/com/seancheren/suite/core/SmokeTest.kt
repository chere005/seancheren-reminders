package com.seancheren.suite.core

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** Proves the module compiles and the core is reachable — the twin of ios/Tests/SmokeTests.swift. */
class SmokeTest {
    @Test fun testStarterIsOneEmptySuite() {
        val d = AppData.starter
        assertEquals(1, d.folderList(ItemKind.reminder).size)          // one reminder folder
        assertEquals(1, d.folderList(ItemKind.note).size)              // one note folder
        assertEquals(1, d.calendars.size)                              // one calendar
        assertNotNull(d.defaultCal)                                    // with a default
        assertTrue(d.reminders.isEmpty() && d.notes.isEmpty() && d.habits.isEmpty())
    }
}
