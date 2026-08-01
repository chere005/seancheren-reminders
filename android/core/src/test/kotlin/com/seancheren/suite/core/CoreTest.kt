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
 * The suite's logic, exercised the way tools/test.php exercises the website and
 * ios/Tests/CoreTests.swift exercises the Swift core: a fresh store over a throwaway
 * file, drive the real Store/Model/Parse, assert on what comes back. No Compose here.
 */
class CoreTest {

    // A store over a temp file that starts empty (.starter), so a test never touches real data.
    private fun freshStore(): Store {
        val f = File.createTempFile("suitecore-", ".json")
        f.delete()
        return Store(f)
    }

    private fun day(y: Int, m: Int, d: Int): LocalDate = LocalDate.of(y, m, d)

    // MARK: - Repeats

    @Test fun testRepeatStepsByUnit() {
        val start = day(2026, 1, 10)
        assertEquals(day(2026, 1, 13), Recurrence(3, RepeatUnit.day).step(start))
        assertEquals(day(2026, 1, 24), Recurrence(2, RepeatUnit.week).step(start))
        assertEquals(day(2026, 2, 10), Recurrence(1, RepeatUnit.month).step(start))
        assertEquals(day(2027, 1, 10), Recurrence(1, RepeatUnit.year).step(start))
    }

    @Test fun testMonthRepeatClampsShortMonths() {
        assertEquals(day(2026, 2, 28), Recurrence(1, RepeatUnit.month).step(day(2026, 1, 31)))
        assertEquals(day(2026, 4, 30), Recurrence(1, RepeatUnit.month).step(day(2026, 3, 31)))
        assertEquals(day(2024, 2, 29), Recurrence(1, RepeatUnit.month).step(day(2024, 1, 31)))  // leap
    }

    @Test fun testRepeatDatesExpandOverWindow() {
        val rule = Recurrence(1, RepeatUnit.week)
        val dates = rule.dates(day(2026, 1, 1), day(2026, 1, 1), day(2026, 1, 31))
        assertEquals(
            listOf(day(2026, 1, 1), day(2026, 1, 8), day(2026, 1, 15), day(2026, 1, 22), day(2026, 1, 29)),
            dates,
        )
        assertTrue(rule.dates(day(2026, 2, 1), day(2026, 1, 1), day(2026, 1, 31)).isEmpty())
    }

    @Test fun testRepeatNextRollsPastToday() {
        val next = Recurrence(1, RepeatUnit.day).next(day(2026, 1, 1), day(2026, 1, 5))
        assertEquals(day(2026, 1, 6), next)   // strictly after the given day
    }

    // MARK: - Reminder flags

    @Test fun testOverdueAndRidesAlong() {
        val today = day(2026, 6, 15)
        val r = Reminder(text = "late", due = day(2026, 6, 10))
        assertTrue(r.overdue(today))
        r.done = true
        assertFalse(r.overdue(today))   // done is never overdue

        val rider = Reminder(text = "milk", due = null, group = GroupRef.Calendar)
        assertTrue(rider.ridesAlong)
        rider.due = today
        assertFalse(rider.ridesAlong)   // a dated Calendar item doesn't ride along
    }

    // MARK: - Sort order

    @Test fun testSortedUndatedFirstThenByDateThenDoneSinks() {
        val store = freshStore()
        val undated = Reminder(text = "u", due = null, order = 5)
        val early = Reminder(text = "e", due = day(2026, 1, 2), order = 1)
        val late = Reminder(text = "l", due = day(2026, 1, 9), order = 2)
        val doneOne = Reminder(text = "d", due = day(2026, 1, 1), done = true, order = 0)
        val out = store.sorted(listOf(late, doneOne, early, undated)).map { it.text }
        assertEquals(listOf("u", "e", "l", "d"), out)
    }

    // MARK: - Toggle

    @Test fun testToggleFinishesAPlainReminderButRollsARepeat() {
        val store = freshStore()
        store.add(Reminder(text = "plain"))
        val plain = store.data.reminders.first { it.text == "plain" }
        store.toggle(plain)
        assertTrue(store.data.reminders.first { it.text == "plain" }.done)

        // Recently overdue, so next() (which, like the web's repeat_next, computes
        // occurrences from the start with a 400-step cap) can reach forward past today.
        val due = LocalDate.now().minusDays(2)
        store.add(Reminder(text = "rep", due = due, recurrence = Recurrence(1, RepeatUnit.day)))
        val rep = store.data.reminders.first { it.text == "rep" }
        store.toggle(rep)
        val after = store.data.reminders.first { it.text == "rep" }
        assertFalse(after.done)                   // a repeat is never marked done
        assertTrue(after.due!! > due)             // its due date rolled forward instead
    }

    // MARK: - Folders

    @Test fun testAddFolderDedupesAndColoursByPosition() {
        val store = freshStore()
        val before = store.data.folderList(ItemKind.reminder).size
        store.addFolder("Work", ItemKind.reminder)
        store.addFolder("work", ItemKind.reminder)   // case-insensitive dupe, ignored
        assertEquals(before + 1, store.data.folderList(ItemKind.reminder).size)
        assertEquals(before % 10, store.data.folderList(ItemKind.reminder).last().color)
    }

    @Test fun testDeleteFolderMovesItemsToFallbackNeverTheLast() {
        val store = freshStore()
        store.addFolder("Work", ItemKind.reminder)
        val work = store.data.folderList(ItemKind.reminder).first { it.name == "Work" }
        store.add(Reminder(text = "task", folder = work.id))
        store.deleteFolder(work)
        assertNull(store.data.folderList(ItemKind.reminder).firstOrNull { it.id == work.id })
        assertEquals(
            store.data.folderList(ItemKind.reminder).first().id,
            store.data.reminders.first { it.text == "task" }.folder,
        )
        val last = store.data.folderList(ItemKind.reminder).first()
        store.deleteFolder(last)
        assertEquals(1, store.data.folderList(ItemKind.reminder).size)   // the last is undeletable
    }

    @Test fun testTargetFolder() {
        val store = freshStore()
        val def = store.data.defaultFolder[ItemKind.reminder.name]
        assertEquals(def, store.target(ItemKind.reminder, null))
        val some = UUID.randomUUID()
        assertEquals(some, store.target(ItemKind.reminder, some))
    }

    // MARK: - Groups

    @Test fun testGroupAddRenameDeleteEmptiesToInbox() {
        val store = freshStore()
        store.addGroup("Errands", ItemKind.reminder)
        val g = store.data.groupList(ItemKind.reminder).first { it.name == "Errands" }
        store.add(Reminder(text = "x", group = GroupRef.Group(g.id)))
        store.renameGroup(g.id, "Chores")
        assertEquals("Chores", store.data.groups.first { it.id == g.id }.name)
        store.deleteGroup(store.data.groups.first { it.id == g.id })
        assertNull(store.data.groups.firstOrNull { it.id == g.id })
        assertEquals(GroupRef.Inbox, store.data.reminders.first { it.text == "x" }.group)
    }

    // MARK: - Calendars

    @Test fun testCalendarAddSetScopeAndDelete() {
        val store = freshStore()
        store.addCalendar("Work")
        store.addCalendar("Home")
        val work = store.calendarsOnly.first { it.name == "Work" }
        val home = store.calendarsOnly.first { it.name == "Home" }
        store.addSet("Both", listOf(work.id, home.id))
        val set = store.calSets.first { it.name == "Both" }

        assertNull(store.calScope(null))                                   // no selection = all
        assertEquals(setOf(work.id), store.calScope(work.id))
        assertEquals(setOf(work.id, home.id), store.calScope(set.id))      // a set expands

        store.add(Event(text = "meeting", date = day(2026, 5, 1), cal = work.id))
        store.deleteCalendar(work)
        assertNull(store.calendarsOnly.firstOrNull { it.id == work.id })   // calendar gone
        assertTrue(store.data.events.isEmpty())                            // its events went with it
        assertFalse(store.calScope(set.id)?.contains(work.id) ?: false)    // scrubbed from the set
    }

    // MARK: - Events on a day

    @Test fun testEventsOnDayWithRepeatAndScope() {
        val store = freshStore()
        store.addCalendar("Work")
        val work = store.calendarsOnly.first { it.name == "Work" }
        val other = store.calendarsOnly.first { it.id != work.id }
        store.add(Event(text = "standup", date = day(2026, 3, 2), cal = work.id, recurrence = Recurrence(1, RepeatUnit.day)))
        store.add(Event(text = "one-off", date = day(2026, 3, 5), cal = other.id))

        val all = store.events(day(2026, 3, 5)).map { it.text }
        assertTrue(all.contains("standup") && all.contains("one-off"))

        val scoped = store.events(day(2026, 3, 5), setOf(work.id)).map { it.text }
        assertEquals(listOf("standup"), scoped)
    }

    // MARK: - Reminders on a day

    @Test fun testRemindersOnDayCollectsDueOverdueAndRiders() {
        val store = freshStore()
        val today = day(2026, 7, 20)
        store.add(Reminder(text = "due-today", due = today))
        store.add(Reminder(text = "overdue", due = day(2026, 7, 1)))
        store.add(Reminder(text = "rider", due = null, group = GroupRef.Calendar))
        store.add(Reminder(text = "future", due = day(2026, 7, 25)))

        val onToday = store.reminders(today, today).map { it.text }
        assertTrue(onToday.contains("due-today"))
        assertTrue(onToday.contains("overdue"))    // rides onto today
        assertTrue(onToday.contains("rider"))
        assertFalse(onToday.contains("future"))

        val onLater = store.reminders(day(2026, 7, 25), today).map { it.text }
        assertEquals(listOf("future"), onLater)
    }

    @Test fun testNotesOnDay() {
        val store = freshStore()
        store.add(Note(title = "n1", date = day(2026, 8, 3)))
        store.add(Note(title = "n2", date = day(2026, 8, 4)))
        store.add(Note(title = "undated"))
        assertEquals(listOf("n1"), store.notes(day(2026, 8, 3)).map { it.title })
        assertFalse(store.notes(day(2026, 8, 4)).any { it.title == "undated" })
    }

    // MARK: - Habits

    @Test fun testHabitToggleAndGrouping() {
        val store = freshStore()
        store.addHabit("Floss", null)
        val h = store.habits(null).first { it.name == "Floss" }
        val d = day(2026, 2, 2)
        store.toggleHabit(h, d)
        assertTrue(store.data.habits.first { it.id == h.id }.on(d))
        store.toggleHabit(store.data.habits.first { it.id == h.id }, d)
        assertFalse(store.data.habits.first { it.id == h.id }.on(d))   // tapping again clears it
    }

    // MARK: - Parsing

    @Test fun testParseTimeAndDate() {
        val now = day(2026, 1, 1)
        val p = parseWhen("Vet 8/3 2pm", now)
        assertEquals("Vet", p.text)
        assertEquals(day(2026, 8, 3), p.date)
        assertEquals(14 * 60, p.minutes)

        assertEquals(14 * 60 + 30, parseWhen("Call 2:30 pm", now).minutes)
        assertEquals(day(2027, 3, 4), parseWhen("Trip 3/4/27", now).date)
        assertEquals(day(2027, 3, 4), parseWhen("Trip 3/4/2027", now).date)
    }

    @Test fun testBareDateIsNextOccurrence() {
        val now = day(2026, 6, 15)
        assertEquals(day(2027, 1, 2), parseWhen("Taxes 1/2", now).date)   // already gone → next year
        assertEquals(day(2026, 9, 1), parseWhen("Fair 9/1", now).date)    // still ahead → this year
    }

    @Test fun testFractionParsesAsADateAsDocumented() {
        val p = parseWhen("2/3 cup flour", day(2026, 1, 1))
        assertEquals(day(2026, 2, 3), p.date)   // 2/3 reads as Feb 3, the known limitation
    }

    // MARK: - The watch list

    @Test fun testWatchListHasOpenItemsInGroupsAndDropsEmpties() {
        val store = freshStore()
        store.add(Reminder(text = "buy milk", due = null, group = GroupRef.Calendar))
        store.add(Reminder(text = "call bank", due = LocalDate.now(), group = GroupRef.Inbox))
        store.add(Reminder(text = "done one", due = LocalDate.now(), done = true, group = GroupRef.Inbox))
        val list = store.watchList()
        val names = list.sections.map { it.name }
        assertTrue(names.contains("Calendar") && names.contains("Reminders"))
        val inbox = list.sections.first { it.name == "Reminders" }
        assertEquals(listOf("call bank"), inbox.items.map { it.text })   // open items only
    }

    // MARK: - Persistence

    @Test fun testSaveAndReadRoundTrip() {
        val f = File.createTempFile("suitecore-rt-", ".json")
        f.delete()
        val a = Store(f)
        a.addFolder("Persisted", ItemKind.reminder)
        a.add(Reminder(text = "written"))
        a.save()   // skip the debounce (that lives in the app)
        val b = Store(f)
        assertTrue(b.data.folderList(ItemKind.reminder).any { it.name == "Persisted" })
        assertTrue(b.data.reminders.any { it.text == "written" })
    }

    // MARK: - The reorder helper

    @Test fun testReorderMatchesDragSemantics() {
        val xs = mutableListOf(0, 1, 2, 3, 4)
        xs.reorder(setOf(0), 3)
        assertEquals(listOf(1, 2, 0, 3, 4), xs)   // moving the first element to before index 3
    }

    @Test fun testDayLabel() {
        val today = day(2026, 5, 10)
        assertEquals("today", dayLabel(today, today))
        assertEquals("tomorrow", dayLabel(day(2026, 5, 11), today))
        assertEquals("yesterday", dayLabel(day(2026, 5, 9), today))
        assertEquals("Dec 25", dayLabel(day(2026, 12, 25), today))
    }
}
