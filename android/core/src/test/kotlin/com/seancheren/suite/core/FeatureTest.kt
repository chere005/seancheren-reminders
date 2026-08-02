package com.seancheren.suite.core

import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.decodeFromJsonElement
import kotlinx.serialization.json.encodeToJsonElement
import kotlinx.serialization.decodeFromString
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import java.io.File
import java.time.DayOfWeek
import java.time.LocalDate
import java.util.UUID

/**
 * The features brought over from the web app — subtasks, section colours, the Habits
 * month filter, Copy as Markdown — plus that old documents still load once the model has
 * grown fields they never had. The twin of ios/Tests/FeatureTests.swift.
 */
class FeatureTest {

    private fun freshStore(): Store {
        val f = File.createTempFile("suitefeat-", ".json")
        f.delete()
        return Store(f)
    }

    private fun day(y: Int, m: Int, d: Int): LocalDate = LocalDate.of(y, m, d)

    // MARK: - Subtasks / the outline sort

    @Test fun testOutlineSortKeepsASubtaskUnderItsParent() {
        val store = freshStore()
        // The subtask is undated; a flat undated-first sort would float it to the top,
        // stranding it under whatever row landed there. The outline sort keeps it put.
        store.data.reminders = mutableListOf(
            Reminder(text = "P", due = day(2026, 1, 20), order = 0, indent = 0),
            Reminder(text = "s", due = null, order = 1, indent = 1),
            Reminder(text = "O", due = day(2026, 1, 10), order = 2, indent = 0),
        )
        assertEquals(listOf("O", "P", "s"), store.reminders(null, GroupRef.Inbox).map { it.text })
    }

    @Test fun testAddSubtaskSlotsUnderParentAtIndentOne() {
        val store = freshStore()
        store.add(Reminder(text = "Parent"))
        val parent = store.data.reminders.first { it.text == "Parent" }
        val child = store.addSubtask(parent)
        assertEquals(1, child.indent)
        assertEquals(parent.group, child.group)
        val ordered = store.data.reminders.sortedBy { it.order }
        val pi = ordered.indexOfFirst { it.id == parent.id }
        assertEquals(child.id, ordered[pi + 1].id)   // sits right after its parent in stored order
    }

    @Test fun testSetIndentIsOneLevelOnly() {
        val store = freshStore()
        store.add(Reminder(text = "x"))
        val r = store.data.reminders.first { it.text == "x" }
        store.setIndent(r, 5)
        assertEquals(1, store.data.reminders.first { it.id == r.id }.indent)   // clamped to one level
        store.setIndent(r, 0)
        assertEquals(0, store.data.reminders.first { it.id == r.id }.indent)
    }

    // MARK: - Section colours

    @Test fun testSectionColoursByPositionThenBySetter() {
        val store = freshStore()
        store.addGroup("A", ItemKind.reminder)
        store.addGroup("B", ItemKind.reminder)
        val a = store.data.groupList(ItemKind.reminder).first { it.name == "A" }
        val b = store.data.groupList(ItemKind.reminder).first { it.name == "B" }
        assertEquals(0, a.color)
        assertEquals(1, b.color)
        store.setGroupColor(a.id, 4)
        assertEquals(4, store.data.groups.first { it.id == a.id }.color)
    }

    // MARK: - Habits month filter

    @Test fun testHabitFilterThreeGestures() {
        val store = freshStore()
        store.addGroup("Morning", ItemKind.habit)
        store.addGroup("Evening", ItemKind.habit)
        val m = store.data.groupList(ItemKind.habit).first { it.name == "Morning" }
        val e = store.data.groupList(ItemKind.habit).first { it.name == "Evening" }
        store.addHabit("floss", m.id)
        store.addHabit("read", e.id)
        store.addHabit("loose", null)

        assertTrue(store.habitAllShown)
        assertEquals(3, store.habitsCounted().size)

        store.toggleHabitSection(m.id)                        // box: Morning off
        assertFalse(store.habitSectionShown(m.id))
        assertEquals(2, store.habitsCounted().size)

        store.onlyHabitSection(e.id)                          // row: only Evening
        assertEquals(listOf("read"), store.habitsCounted().map { it.name })
        assertFalse(store.habitSectionShown(null))            // ungrouped off when isolating one

        store.setHabitAll(false)                              // All: everything off
        assertEquals(0, store.habitsCounted().size)
        store.setHabitAll(true)                               // All: everything on
        assertTrue(store.habitAllShown)
        assertEquals(3, store.habitsCounted().size)
    }

    @Test fun testHabitMonthFillCountsBySection() {
        val store = freshStore()
        store.addGroup("Morning", ItemKind.habit)
        val m = store.data.groupList(ItemKind.habit).first()
        store.addHabit("floss", m.id)
        store.addHabit("water", null)
        val floss = store.habits(m.id).first()
        val water = store.habits(null).first()
        val d = day(2026, 4, 10)
        store.toggleHabit(floss, d)
        store.toggleHabit(water, d)

        val fill = store.habitMonthFill(day(2026, 4, 1))
        assertEquals(1, fill[d.key]?.get(m.id))       // one Morning habit ticked that day
        assertEquals(1, fill[d.key]?.get(null))       // and one ungrouped
        assertNull(fill[day(2026, 4, 11).key])        // a day with nothing ticked isn't in the map

        store.toggleHabitSection(m.id)                // hide Morning
        assertNull(store.habitMonthFill(day(2026, 4, 1))[d.key]?.get(m.id))
    }

    // MARK: - Copy as Markdown

    @Test fun testMarkdownExport() {
        val store = freshStore()
        store.add(Reminder(text = "Buy milk", due = null, group = GroupRef.Calendar))
        store.add(Reminder(text = "Taxes", due = day(2026, 1, 2), group = GroupRef.Inbox))
        val parent = store.data.reminders.first { it.text == "Taxes" }
        val child = store.addSubtask(parent)
        store.update(child.copy(text = "file online"))

        val md = store.markdown(null)
        assertTrue(md.contains("## Calendar"))                 // a heading per group
        assertTrue(md.contains("- [ ] Buy milk"))
        assertTrue(md.contains("## Reminders"))
        assertTrue(md.contains("- [ ] Taxes ("))               // a dated row carries its date
        assertTrue(md.contains("  - [ ] file online"))         // a subtask is indented two spaces
    }

    // MARK: - Backward compatibility

    /** Recursively strip keys that post-date the first version, simulating an older suite.json. */
    private fun stripKeys(keys: Set<String>, el: JsonElement): JsonElement = when (el) {
        is JsonObject -> JsonObject(el.filterKeys { it !in keys }.mapValues { stripKeys(keys, it.value) })
        is JsonArray -> JsonArray(el.map { stripKeys(keys, it) })
        else -> el
    }

    @Test fun testOldDocumentWithoutNewKeysStillLoads() {
        val store = freshStore()
        store.add(Reminder(text = "keep me"))
        store.addGroup("Sec", ItemKind.habit)

        val tree = Store.json.encodeToJsonElement(store.data)
        // These keys did not exist in the first version; a document without them must load,
        // not reset the whole suite to empty because one key was absent.
        val stripped = stripKeys(setOf("indent", "habitHidden", "habitHideUngrouped", "habitsMonth"), tree)
        val restored = Store.json.decodeFromJsonElement<AppData>(stripped)

        assertTrue(restored.reminders.any { it.text == "keep me" })                       // data survived
        assertEquals(0, restored.reminders.first { it.text == "keep me" }.indent)         // indent defaulted
        assertTrue(restored.habitHidden.isEmpty())                                        // the filter defaulted
    }

    // MARK: - Sample data ("buddy's data")

    @Test fun testLoadSamplePopulatesBuddysData() {
        val store = freshStore()
        store.loadSample()
        assertTrue(store.data.folderList(ItemKind.reminder).any { it.name == "Cooking" })
        assertTrue(store.data.groups.any { it.name == "Groceries" && it.kind == ItemKind.reminder })
        assertTrue(store.data.reminders.any { it.text == "Milk" })
        assertTrue(store.data.reminders.any { it.ridesAlong })
        assertTrue(store.data.notes.any { it.title == "Chicken parmesan" })
        val dinners = store.data.events.filter { it.text.startsWith("Dinner") }
        assertEquals(2, dinners.size)
        for (e in dinners) assertEquals(DayOfWeek.SATURDAY, e.date.dayOfWeek)
        assertTrue(store.data.habits.any { it.name == "Floss" && it.marks.isNotEmpty() })
        assertFalse(store.watchList().sections.isEmpty())   // and it flows to the watch
    }

    @Test fun testListGroupDecodesWithoutAColour() {
        val json = """{"id":"${UUID.randomUUID()}","name":"Old","kind":"reminder","order":2}"""
        val g = Store.json.decodeFromString<ListGroup>(json)
        assertEquals("Old", g.name)
        assertEquals(0, g.color)   // a section from before colours loads at colour 0
    }

    // MARK: - Clear completed

    @Test fun testClearDoneRemovesOnlyTheTickedRows() {
        val store = freshStore()
        store.add(Reminder(text = "open"))
        store.add(Reminder(text = "done a", done = true))
        store.add(Reminder(text = "done b", done = true))
        store.clearDone(folder = null)
        assertEquals(listOf("open"), store.data.reminders.map { it.text })   // ticked go, open stays
    }

    @Test fun testClearDoneIsScopedToTheFolderInView() {
        val store = freshStore()
        store.addFolder("Work", ItemKind.reminder)
        val work = store.data.folderList(ItemKind.reminder).first { it.name == "Work" }.id
        val home = store.data.folderList(ItemKind.reminder).first { it.id != work }.id
        store.add(Reminder(text = "work done", done = true, folder = work))
        store.add(Reminder(text = "home done", done = true, folder = home))
        store.clearDone(folder = work)
        assertTrue(store.data.reminders.none { it.text == "work done" })     // the viewed folder's done rows go
        assertTrue(store.data.reminders.any { it.text == "home done" })      // another folder's are left alone
    }

    @Test fun testUngroupedHabitColour() {
        val store = freshStore()
        assertEquals(3, store.data.habitUngroupedColor)   // a fresh suite has a default ungrouped colour
        store.setUngroupedHabitColor(6)
        assertEquals(6, store.data.habitUngroupedColor)   // and it can be recoloured
    }
}
