package com.seancheren.suite.core

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import java.io.File
import java.time.LocalDate
import java.util.UUID

/**
 * Web-parity behaviours ported from lib/util.php and tools/test.php — the cases the web
 * suite verifies that go beyond the iOS twin set. Pure core, run by `./gradlew :core:test`.
 */
class ParityTest {

    private fun freshStore(): Store {
        val f = File.createTempFile("suiteparity-", ".json"); f.delete(); return Store(f)
    }
    private fun day(y: Int, m: Int, d: Int): LocalDate = LocalDate.of(y, m, d)

    // MARK: - Repeats computed from the start (no drift) — matches web repeat_step($start,$rep,$i)

    @Test fun testMonthlyRepeatDoesNotDriftFromTheThirtyFirst() {
        val dates = Recurrence(1, RepeatUnit.month).dates(day(2026, 1, 31), day(2026, 1, 1), day(2026, 6, 30))
        assertEquals(
            listOf(day(2026, 1, 31), day(2026, 2, 28), day(2026, 3, 31), day(2026, 4, 30), day(2026, 5, 31), day(2026, 6, 30)),
            dates,
        )
    }

    @Test fun testYearlyRepeatClampsLeapDayFromStart() {
        val dates = Recurrence(1, RepeatUnit.year).dates(day(2024, 2, 29), day(2024, 1, 1), day(2028, 12, 31))
        assertEquals(
            listOf(day(2024, 2, 29), day(2025, 2, 28), day(2026, 2, 28), day(2027, 2, 28), day(2028, 2, 29)),
            dates,
        )
    }

    @Test fun testRepeatNextIsComputedFromStartNotIncrementally() {
        // From Jan 31, the first occurrence after Apr 15 is Apr 30 — not Apr 28, which a
        // drifting month-by-month walk (Feb 28 → Mar 28 → Apr 28) would give.
        assertEquals(day(2026, 4, 30), Recurrence(1, RepeatUnit.month).next(day(2026, 1, 31), day(2026, 4, 15)))
    }

    @Test fun testEveryTwoWeeksAndEveryTwoMonthsOverWindow() {
        assertEquals(
            listOf(day(2026, 1, 1), day(2026, 1, 15), day(2026, 1, 29)),
            Recurrence(2, RepeatUnit.week).dates(day(2026, 1, 1), day(2026, 1, 1), day(2026, 1, 31)),
        )
        assertEquals(
            listOf(day(2026, 1, 31), day(2026, 3, 31), day(2026, 5, 31)),
            Recurrence(2, RepeatUnit.month).dates(day(2026, 1, 31), day(2026, 1, 1), day(2026, 6, 30)),
        )
    }

    @Test fun testRepeatLabel() {
        assertEquals("every week", Recurrence(1, RepeatUnit.week).label)
        assertEquals("every 2 weeks", Recurrence(2, RepeatUnit.week).label)
        assertEquals("every day", Recurrence(1, RepeatUnit.day).label)
    }

    @Test fun testTogglingAMonthlyRepeatRollsToAClampedDate() {
        val store = freshStore()
        store.add(Reminder(text = "rent", due = day(2020, 1, 31), recurrence = Recurrence(1, RepeatUnit.month)))
        val r = store.data.reminders.first { it.text == "rent" }
        store.toggle(r)
        val after = store.data.reminders.first { it.text == "rent" }
        assertFalse(after.done)
        // Whatever month it lands in, the day is that month's clamp of the 31st, never a drift.
        val due = after.due!!
        val lastOfMonth = java.time.YearMonth.from(due).lengthOfMonth()
        assertEquals(minOf(31, lastOfMonth), due.dayOfMonth)
        assertTrue(due > LocalDate.now())
    }

    // MARK: - Text parsing edge cases (lib/util.php parse_time_from_text / parse_date_from_text)

    @Test fun testParseNoonMidnightAndAmPm() {
        val now = day(2026, 1, 1)
        assertEquals(12 * 60, parseWhen("Lunch 12pm", now).minutes)   // noon
        assertEquals(0, parseWhen("Sleep 12am", now).minutes)         // midnight
        assertEquals(60, parseWhen("Gym 1am", now).minutes)
        assertEquals(13 * 60, parseWhen("Walk 1pm", now).minutes)
        assertEquals(14 * 60 + 5, parseWhen("Call 2:05 pm", now).minutes)
    }

    @Test fun testParseRejectsImpossibleDate() {
        val p = parseWhen("Report 2/31", day(2026, 1, 1))
        assertNull(p.date)               // Feb 31 is not a real date
        assertEquals("Report 2/31", p.text)   // and the text is left intact
    }

    @Test fun testParseIgnoresBareNumbersWithoutSlashOrAmPm() {
        val p = parseWhen("Room 3 for the 5 of us", day(2026, 1, 1))
        assertNull(p.minutes)
        assertNull(p.date)
        assertEquals("Room 3 for the 5 of us", p.text)
    }

    @Test fun testParseTwoDigitAndFourDigitYears() {
        val now = day(2026, 1, 1)
        assertEquals(day(2027, 3, 4), parseWhen("Trip 3/4/27", now).date)
        assertEquals(day(2027, 3, 4), parseWhen("Trip 3/4/2027", now).date)
        assertEquals(day(2099, 12, 31), parseWhen("Future 12/31/99", now).date)   // 2-digit year is +2000
        assertNull(parseWhen("Weird 3/4/123", now).date)   // a 3-digit year is not a date (web: exactly 2 or 4)
    }

    @Test fun testRepeatNextStaysPutWhenTooFarToReach() {
        // The web's repeat_next caps at 400 steps and returns the start if nothing later is
        // found — a daily repeat that's years overdue can't roll. Match that exactly.
        assertEquals(day(2020, 1, 1), Recurrence(1, RepeatUnit.day).next(day(2020, 1, 1), day(2026, 1, 1)))
    }

    @Test fun testParseBareDateRollsToNextYearWhenPast() {
        val now = day(2026, 6, 15)
        assertEquals(day(2027, 1, 2), parseWhen("Taxes 1/2", now).date)   // already gone
        assertEquals(day(2026, 9, 1), parseWhen("Fair 9/1", now).date)    // still ahead
        assertEquals(day(2026, 6, 15), parseWhen("Today 6/15", now).date) // today counts as not past
    }

    @Test fun testParseDateAndTimeTogetherLeaveCleanText() {
        val p = parseWhen("Vet 8/3 2pm", day(2026, 1, 1))
        assertEquals("Vet", p.text)
        assertEquals(day(2026, 8, 3), p.date)
        assertEquals(14 * 60, p.minutes)
    }

    // MARK: - Sort ties break by stored order (undated-first, then date, then order; done sinks)

    @Test fun testSortBreaksTiesByStoredOrder() {
        val store = freshStore()
        val a = Reminder(text = "a", due = day(2026, 1, 5), order = 2)
        val b = Reminder(text = "b", due = day(2026, 1, 5), order = 1)   // same date, lower order → first
        val c = Reminder(text = "c", due = null, order = 9)
        val d = Reminder(text = "d", due = null, order = 3)               // undated, lower order → first among undated
        assertEquals(listOf("d", "c", "b", "a"), store.sorted(listOf(a, b, c, d)).map { it.text })
    }

    // MARK: - Reminders on a day: a repeat that lands there, expanded from the rule

    @Test fun testReminderRepeatLandsOnADayInTheFuture() {
        val store = freshStore()
        val today = day(2026, 3, 1)
        store.add(Reminder(text = "standup", due = day(2026, 3, 1), recurrence = Recurrence(1, RepeatUnit.week)))
        // Two weeks on is an occurrence; the day between is not.
        assertTrue(store.reminders(day(2026, 3, 15), today).any { it.text == "standup" })
        assertFalse(store.reminders(day(2026, 3, 10), today).any { it.text == "standup" })
    }

    // MARK: - Events on a day: an event with no calendar counts as the default one

    @Test fun testEventWithNoCalendarFallsToDefaultForScope() {
        val store = freshStore()
        val def = store.data.defaultCal!!
        store.add(Event(text = "loose", date = day(2026, 5, 1), cal = null))
        assertEquals(listOf("loose"), store.events(day(2026, 5, 1), setOf(def)).map { it.text })
        // Narrowed to some other calendar, the default-scoped event drops out.
        store.addCalendar("Other")
        val other = store.calendarsOnly.first { it.name == "Other" }
        assertTrue(store.events(day(2026, 5, 1), setOf(other.id)).isEmpty())
    }

    // MARK: - Deleting a calendar reassigns the default

    @Test fun testDeletingTheDefaultCalendarMovesTheDefault() {
        val store = freshStore()
        store.addCalendar("Second")
        val first = store.calendarsOnly.first()
        assertEquals(first.id, store.data.defaultCal)
        store.deleteCalendar(first)
        assertNull(store.calendarsOnly.firstOrNull { it.id == first.id })
        assertEquals(store.calendarsOnly.first().id, store.data.defaultCal)   // default moved to a survivor
    }

    // MARK: - Markdown groups in Calendar, Reminders, then sections order

    @Test fun testMarkdownOrdersCalendarThenRemindersThenSections() {
        val store = freshStore()
        store.addGroup("Errands", ItemKind.reminder)
        val g = store.data.groupList(ItemKind.reminder).first { it.name == "Errands" }
        store.add(Reminder(text = "rider", group = GroupRef.Calendar))
        store.add(Reminder(text = "inbox one", group = GroupRef.Inbox))
        store.add(Reminder(text = "errand one", group = GroupRef.Group(g.id)))
        val md = store.markdown(null)
        val ci = md.indexOf("## Calendar")
        val ri = md.indexOf("## Reminders")
        val ei = md.indexOf("## Errands")
        assertTrue(ci in 0 until ri && ri < ei)
    }

    // MARK: - A calendar day sorts undated-first, then by date, then by time

    @Test fun testDayRemindersSortUndatedFirstThenByTime() {
        val store = freshStore()
        val today = day(2026, 5, 10)
        store.add(Reminder(text = "rider", group = GroupRef.Calendar))    // undated → first
        store.add(Reminder(text = "3pm", due = today, minutes = 15 * 60))
        store.add(Reminder(text = "9am", due = today, minutes = 9 * 60))
        store.add(Reminder(text = "notime", due = today))                 // no time sorts before timed
        assertEquals(listOf("rider", "notime", "9am", "3pm"), store.reminders(today, today).map { it.text })
    }

    // MARK: - Reordering ("folders reorder and keep every folder"; the manager reorder)

    @Test fun testMoveFoldersKeepsEveryFolder() {
        val store = freshStore()
        listOf("A", "B", "C").forEach { store.addFolder(it, ItemKind.reminder) }
        val before = store.data.folderList(ItemKind.reminder).map { it.name }
        store.moveFolders(ItemKind.reminder, from = setOf(0), to = before.size)   // first → last
        val after = store.data.folderList(ItemKind.reminder).map { it.name }
        assertEquals(before.toSet(), after.toSet())                               // no folder is lost
        assertEquals(before[1], after.first())                                    // and the order really moved
    }

    @Test fun testMoveGroupsReordersWithoutDisturbingRows() {
        val store = freshStore()
        listOf("One", "Two", "Three").forEach { store.addGroup(it, ItemKind.habit) }
        store.addHabit("floss", group = store.data.groupList(ItemKind.habit).first().id)
        val habits = store.data.habits.map { it.name }.sorted()
        store.moveGroups(ItemKind.habit, from = setOf(0), to = 3)                 // first section → last
        assertEquals(listOf("Two", "Three", "One"), store.data.groupList(ItemKind.habit).map { it.name })
        assertEquals(habits, store.data.habits.map { it.name }.sorted())          // no habit moved with them
    }

    // MARK: - Folder visibility ("the picker box / row / All", folder_vis*)

    @Test fun testFolderVisibilityThreeGestures() {
        val store = freshStore()
        listOf("Work", "Home").forEach { store.addFolder(it, ItemKind.reminder) }
        val ids = store.data.folderList(ItemKind.reminder).map { it.id }          // General, Work, Home
        assertTrue(store.foldersAllShown(ItemKind.reminder))                      // everything shows to begin with

        store.toggleFolder(ids[1], ItemKind.reminder)                             // box: hide Work
        assertFalse(store.folderShown(ids[1], ItemKind.reminder))
        assertFalse(store.foldersAllShown(ItemKind.reminder))                     // All is off when one is hidden

        store.showOnlyFolder(ids[2], ItemKind.reminder)                           // row: only Home
        assertEquals(listOf(ids[2]), store.shownFolders(ItemKind.reminder).map { it.id })

        store.setFoldersAll(false, ItemKind.reminder)                             // All off
        assertTrue(store.shownFolders(ItemKind.reminder).isEmpty())
        store.setFoldersAll(true, ItemKind.reminder)                              // All on
        assertTrue(store.foldersAllShown(ItemKind.reminder))
        assertEquals(3, store.shownFolders(ItemKind.reminder).size)
    }

    @Test fun testHiddenFolderDropsOutOfTheListAndWatch() {
        val store = freshStore()
        store.addFolder("Work", ItemKind.reminder)
        val work = store.data.folderList(ItemKind.reminder).first { it.name == "Work" }.id
        val general = store.data.folderList(ItemKind.reminder).first { it.id != work }.id
        store.add(Reminder(text = "work task", folder = work, group = GroupRef.Inbox))
        store.add(Reminder(text = "home task", folder = general, group = GroupRef.Inbox))
        assertEquals(listOf("home task", "work task"),
                     store.remindersShown(folder = null, group = GroupRef.Inbox).map { it.text }.sorted())
        store.toggleFolder(work, ItemKind.reminder)
        assertEquals(listOf("home task"),                                         // hidden folder's rows drop out
                     store.remindersShown(folder = null, group = GroupRef.Inbox).map { it.text })
        assertFalse(store.markdown(folder = null).contains("work task"))          // and out of Copy as Markdown
    }

    @Test fun testNotesHiddenFolderDropsOut() {
        val store = freshStore()
        store.addFolder("Private", ItemKind.note)
        val priv = store.data.folderList(ItemKind.note).first { it.name == "Private" }.id
        val general = store.data.folderList(ItemKind.note).first { it.id != priv }.id
        store.add(Note(title = "secret", folder = priv))
        store.add(Note(title = "shopping", folder = general))
        store.toggleFolder(priv, ItemKind.note)
        assertEquals(listOf("shopping"), store.notesShown(folder = null, group = null).map { it.title })
    }

    @Test fun testAddTargetIsTheOneShownFolderElseDefault() {
        val store = freshStore()
        store.addFolder("Work", ItemKind.reminder)
        val ids = store.data.folderList(ItemKind.reminder).map { it.id }          // General, Work
        assertEquals(store.data.defaultFolder[ItemKind.reminder.name],            // several shown → the default
                     store.addTarget(ItemKind.reminder))
        store.showOnlyFolder(ids[1], ItemKind.reminder)
        assertEquals(ids[1], store.addTarget(ItemKind.reminder))                  // one shown → it lands there
    }

    // MARK: - Calendar visibility ("tapping a calendar row leaves only it showing", cal_vis*)

    @Test fun testCalendarVisibilityThreeGestures() {
        val store = freshStore()
        store.addCalendar("Work")
        store.addCalendar("Home")
        val ids = store.calendarsOnly.map { it.id }                               // Personal, Work, Home
        assertTrue(store.calsAllShown())
        assertNull(store.shownCalScope)                                           // no filter when everything shows

        store.toggleCal(ids[0])                                                   // hide Personal
        assertFalse(store.calShown(ids[0]))
        assertEquals(setOf(ids[1], ids[2]), store.shownCalScope)                  // narrows to the shown ones

        store.showOnlyCal(ids[1])                                                 // only Work
        assertEquals(setOf(ids[1]), store.shownCalScope)

        store.setCalsAll(false)
        assertEquals(emptySet<UUID>(), store.shownCalScope)                       // All off shows nothing
        store.setCalsAll(true)
        assertNull(store.shownCalScope)
        assertTrue(store.calsAllShown())
    }

    // MARK: - Delete from the calendar unschedules ("... only unschedules it")

    @Test fun testDeletingFromCalendarUnschedulesButKeepsTheItem() {
        val store = freshStore()
        val today = LocalDate.now()
        store.add(Reminder(text = "cal rem", due = today))
        store.add(Note(title = "cal note", date = today))
        val r = store.data.reminders.first { it.text == "cal rem" }
        val n = store.data.notes.first { it.title == "cal note" }
        store.unschedule(r)
        store.unschedule(n)
        assertNull(store.data.reminders.first { it.id == r.id }.due)              // date gone, row stays
        assertNull(store.data.notes.first { it.id == n.id }.date)                 // date gone, note stays
    }

    // MARK: - Per-folder calendar filter ("switched to ... Off for the calendar", rf_mode)

    @Test fun testCalendarFolderFilterHidesAFoldersReminders() {
        val store = freshStore()
        val today = LocalDate.now()
        store.addFolder("Work", ItemKind.reminder)
        val work = store.data.folderList(ItemKind.reminder).first { it.name == "Work" }.id
        val general = store.data.folderList(ItemKind.reminder).first { it.id != work }.id
        store.add(Reminder(text = "work due", due = today, folder = work))
        store.add(Reminder(text = "home due", due = today, folder = general))
        assertEquals(listOf("home due", "work due"), store.reminders(today, today).map { it.text }.sorted())
        store.toggleCalFolder(work)                                               // switch Work off for the calendar
        assertEquals(listOf("home due"), store.reminders(today, today).map { it.text })
        assertFalse(store.calFolderShown(work))
    }

    // MARK: - Persistence

    @Test fun testVisibilitySurvivesASaveAndRead() {
        val f = File.createTempFile("suiteparity-rt-", ".json")
        f.delete()
        val a = Store(f)
        a.addFolder("Work", ItemKind.reminder)
        val work = a.data.folderList(ItemKind.reminder).first { it.name == "Work" }.id
        a.toggleFolder(work, ItemKind.reminder)
        a.addCalendar("Extra")
        val extra = a.calendarsOnly.first { it.name == "Extra" }.id
        a.toggleCal(extra)
        a.save()
        val b = Store(f)
        assertFalse(b.folderShown(work, ItemKind.reminder))                       // a hidden folder survives a reload
        assertFalse(b.calShown(extra))                                            // a hidden calendar survives a reload
    }
}
