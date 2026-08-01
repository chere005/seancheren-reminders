package com.seancheren.suite.core

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import java.io.File
import java.time.LocalDate

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
}
