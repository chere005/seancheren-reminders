package com.seancheren.suite.core

import kotlinx.serialization.decodeFromString
import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json
import kotlinx.serialization.modules.SerializersModule
import kotlinx.serialization.modules.contextual
import java.io.File
import java.time.DayOfWeek
import java.time.Instant
import java.time.LocalDate
import java.util.UUID

/**
 * The one place data lives, and the only thing that loads/saves the tree — the Kotlin
 * cousin of ios/Shared/Store.swift.
 *
 * Unlike Swift's ObservableObject, this stays framework-free: it mutates `data` in
 * place, then `touch()` fires an injected listener. The app's SuiteViewModel wires that
 * listener to Compose recomposition + a debounced save + the watch push, so this class
 * needs no coroutines, no UI framework, and `./gradlew :core:test` runs it headless —
 * the twin of `swift test`.
 *
 * @param file       where the tree is stored; null keeps it in memory only.
 * @param firstRunSample on a first run (no file yet), open on buddy's dinner sample
 *                       rather than an empty suite. Tests pass false and get `.starter`.
 */
class Store(private val file: File? = null, firstRunSample: Boolean = false) {

    var data: AppData
    private var changeListener: (() -> Unit)? = null

    init {
        val loaded = file?.let { read(it) }
        data = loaded ?: if (firstRunSample) sampleData() else AppData.starter
        // Calendar sets are gone, as on the web — the `members` field survives only so an
        // old document decodes; leftover set rows are dropped on the next read, exactly
        // like the web's `load_calendars()`.
        data.calendars.removeAll { it.isSet }
    }

    private fun read(f: File): AppData? =
        if (!f.exists()) null
        else try { json.decodeFromString<AppData>(f.readText()) } catch (e: Exception) { null }

    /**
     * Call after any change. The listener (set by the app) triggers recomposition and a
     * debounced save; the core itself does not touch disk here.
     */
    fun touch() { changeListener?.invoke() }

    /** Somewhere to hang "and update the UI / save / tell the watch", without the store knowing about any of it. */
    fun onChange(block: () -> Unit) { changeListener = block }

    /** Back to a first run: everything gone, one General folder each and a calendar. */
    fun erase() { data = AppData.starter; touch() }

    /** Replace everything with buddy's sample data (the Settings button). */
    fun loadSample() { data = sampleData(); touch() }

    /** Atomic save: write a temp file, then rename over the target. */
    fun save() {
        val f = file ?: return
        val text = json.encodeToString(data)
        f.parentFile?.mkdirs()
        val tmp = File(f.parentFile, f.name + ".tmp")
        tmp.writeText(text)
        if (!tmp.renameTo(f)) { f.writeText(text); tmp.delete() }
    }

    // MARK: - Folders

    fun folderName(id: UUID?): String = data.folder(id)?.name ?: "All"

    fun addFolder(name: String, kind: ItemKind) {
        val clean = name.trim()
        if (clean.isEmpty()) return
        if (data.folderList(kind).any { it.name.equals(clean, ignoreCase = true) }) return
        data.folders.add(Folder(name = clean, kind = kind, color = data.folderList(kind).size % 10))
        touch()
    }

    /** Deleting a folder moves its items back to the default rather than destroying them. */
    fun deleteFolder(folder: Folder) {
        if (data.folderList(folder.kind).size <= 1) return          // never the last one
        val fallback = data.folderList(folder.kind).firstOrNull { it.id != folder.id }?.id
        when (folder.kind) {
            ItemKind.reminder -> data.reminders.forEach { if (it.folder == folder.id) it.folder = fallback }
            ItemKind.note -> data.notes.forEach { if (it.folder == folder.id) it.folder = fallback }
            ItemKind.habit -> {}
        }
        data.folders.removeAll { it.id == folder.id }
        if (data.defaultFolder[folder.kind.name] == folder.id) {
            if (fallback != null) data.defaultFolder[folder.kind.name] = fallback
            else data.defaultFolder.remove(folder.kind.name)
        }
        if (data.lastFolder[folder.kind.name] == folder.id) data.lastFolder.remove(folder.kind.name)
        touch()
    }

    /** Where a new item lands: the folder you're looking at, or the chosen default on All. */
    fun target(kind: ItemKind, viewing: UUID?): UUID? =
        viewing ?: data.defaultFolder[kind.name] ?: data.folderList(kind).firstOrNull()?.id

    /**
     * Persist a drag-reorder of a kind's folders (which order by array position, not an
     * `order` field), splicing the new order back without disturbing the other kind's
     * rows — the web's `folders_reorder`, which keeps every folder.
     */
    fun moveFolders(kind: ItemKind, from: Set<Int>, to: Int) {
        val list = data.folderList(kind).toMutableList()
        list.reorder(from, to)
        val next = list.iterator()
        data.folders = data.folders.map { if (it.kind == kind) next.next() else it }.toMutableList()
        touch()
    }

    // MARK: - Folder visibility (the three-gesture picker)
    //
    // Stored as what's hidden, per kind: the box toggles one, a row shows only one, "All"
    // is the master — the web's folder_vis / folder_vis_only / folder_vis_all.

    /** Whether a folder is currently shown. */
    fun folderShown(id: UUID, kind: ItemKind): Boolean =
        !(data.hiddenFolders[kind.name] ?: emptyList()).contains(id)

    /** The box: toggle just this folder. */
    fun toggleFolder(id: UUID, kind: ItemKind) {
        val hidden = (data.hiddenFolders[kind.name] ?: emptyList()).toMutableList()
        if (!hidden.remove(id)) hidden.add(id)
        data.hiddenFolders[kind.name] = hidden
        touch()
    }

    /** The row: show only this folder, hide the rest. */
    fun showOnlyFolder(id: UUID, kind: ItemKind) {
        data.hiddenFolders[kind.name] =
            data.folderList(kind).map { it.id }.filter { it != id }.toMutableList()
        touch()
    }

    /** The "All" master: on only when no folder of this kind is hidden. */
    fun foldersAllShown(kind: ItemKind): Boolean {
        val hidden = (data.hiddenFolders[kind.name] ?: emptyList()).toSet()
        return data.folderList(kind).none { hidden.contains(it.id) }
    }

    fun setFoldersAll(show: Boolean, kind: ItemKind) {
        data.hiddenFolders[kind.name] =
            if (show) mutableListOf() else data.folderList(kind).map { it.id }.toMutableList()
        touch()
    }

    /** The folders on show, in list order — used to filter the list and to decide where a
     *  new item lands when exactly one is showing. */
    fun shownFolders(kind: ItemKind): List<Folder> {
        val hidden = (data.hiddenFolders[kind.name] ?: emptyList()).toSet()
        return data.folderList(kind).filter { !hidden.contains(it.id) }
    }

    /** Where a new item lands from the list: the one folder on show, else the default —
     *  the web files it in the folder you're focused on, or the default when several show. */
    fun addTarget(kind: ItemKind): UUID? {
        val shown = shownFolders(kind)
        if (shown.size == 1) return shown[0].id
        return data.defaultFolder[kind.name] ?: data.folderList(kind).firstOrNull()?.id
    }

    // MARK: - Groups

    fun addGroup(name: String, kind: ItemKind) {
        val clean = name.trim()
        if (clean.isEmpty()) return
        if (data.groupList(kind).any { it.name.equals(clean, ignoreCase = true) }) return
        val order = (data.groupList(kind).maxOfOrNull { it.order } ?: 0) + 1
        data.groups.add(ListGroup(name = clean, kind = kind, order = order, color = data.groupList(kind).size % 10))
        touch()
    }

    /** Recolour a section from its swatch — the same palette the folder manager uses. */
    fun setGroupColor(id: UUID, index: Int) {
        val g = data.groups.firstOrNull { it.id == id } ?: return
        g.color = index
        touch()
    }

    fun renameGroup(id: UUID, name: String) {
        val clean = name.trim()
        if (clean.isEmpty()) return
        val g = data.groups.firstOrNull { it.id == id } ?: return
        if (data.groupList(g.kind).any { it.id != id && it.name.equals(clean, ignoreCase = true) }) return
        g.name = clean
        touch()
    }

    /** Recolour the ungrouped "Habits" bucket, which has no section row of its own. */
    fun setUngroupedHabitColor(index: Int) {
        data.habitUngroupedColor = index
        touch()
    }

    /**
     * Persist a drag-reorder of a kind's sections — the web reorders sections in its
     * manager; here the same result renumbers each section's `order`, moving no habits
     * or rows with them.
     */
    fun moveGroups(kind: ItemKind, from: Set<Int>, to: Int) {
        val list = data.groupList(kind).toMutableList()
        list.reorder(from, to)
        list.forEachIndexed { i, g ->
            data.groups.firstOrNull { it.id == g.id }?.order = i
        }
        touch()
    }

    /** Deleting a group empties it into the ungrouped catch-all — nothing is lost. */
    fun deleteGroup(group: ListGroup) {
        when (group.kind) {
            ItemKind.reminder -> data.reminders.forEach { if (it.group == GroupRef.Group(group.id)) it.group = GroupRef.Inbox }
            ItemKind.note -> data.notes.forEach { if (it.group == group.id) it.group = null }
            ItemKind.habit -> data.habits.forEach { if (it.group == group.id) it.group = null }
        }
        data.groups.removeAll { it.id == group.id }
        touch()
    }

    // MARK: - Reminders

    /**
     * Ticking a repeating reminder rolls it to its next date instead of finishing it,
     * so the row always sits on the next date it owes.
     */
    fun toggle(reminder: Reminder) {
        val r = data.reminders.firstOrNull { it.id == reminder.id } ?: return
        val rule = r.recurrence
        val due = r.due
        if (rule != null && due != null && !r.done) {
            r.due = rule.next(start = due, after = maxOf(due, LocalDate.now()))
        } else {
            r.done = !r.done
        }
        touch()
    }

    fun add(reminder: Reminder) {
        val new = reminder.copy(order = (data.reminders.maxOfOrNull { it.order } ?: 0) + 1)
        data.reminders.add(new)
        touch()
    }

    fun update(reminder: Reminder) {
        val i = data.reminders.indexOfFirst { it.id == reminder.id }
        if (i < 0) return
        data.reminders[i] = reminder
        touch()
    }

    fun delete(reminder: Reminder) { data.reminders.removeAll { it.id == reminder.id }; touch() }

    /**
     * Remove the ticked reminders — the web's "clear completed". Scoped to the folder in
     * view, or every folder on "All". Open rows are untouched.
     */
    fun clearDone(folder: UUID?) {
        data.reminders.removeAll { it.done && (folder == null || it.folder == folder) }
        touch()
    }

    /**
     * Copy a reminder's whole outline block — the row and, for a top-level row, the
     * subtasks under it — with fresh ids, directly under the original block. The web's
     * duplicate button, glyph for glyph. Twin of Store.duplicate (Swift).
     */
    fun duplicate(reminder: Reminder) {
        val anchor = data.reminders.firstOrNull { it.id == reminder.id } ?: return
        val inList = data.reminders
            .filter { it.group == anchor.group && it.folder == anchor.folder }
            .sortedBy { it.order }
        val i = inList.indexOfFirst { it.id == anchor.id }
        if (i < 0) return
        val block = mutableListOf(inList[i])
        if (inList[i].indent == 0) {
            var j = i + 1
            while (j < inList.size && inList[j].indent > 0) { block.add(inList[j]); j++ }
        }
        val copies = block.map { it.copy(id = UUID.randomUUID()) }
        val all = data.reminders.sortedBy { it.order }.toMutableList()
        val at = all.indexOfFirst { it.id == block.last().id }
        if (at < 0) return
        all.addAll(at + 1, copies)
        all.forEachIndexed { n, r -> r.order = n }
        data.reminders = all
        touch()
    }

    /** A plain copy of a note, fresh id, directly under the original. */
    fun duplicate(note: Note) {
        data.notes.firstOrNull { it.id == note.id } ?: return
        val all = data.notes.sortedBy { it.order }.toMutableList()
        val at = all.indexOfFirst { it.id == note.id }
        if (at < 0) return
        all.add(at + 1, all[at].copy(id = UUID.randomUUID()))
        all.forEachIndexed { n, x -> x.order = n }
        data.notes = all
        touch()
    }

    /** A plain copy of an event, fresh id. */
    fun duplicate(event: Event) {
        val i = data.events.indexOfFirst { it.id == event.id }
        if (i < 0) return
        data.events.add(i + 1, data.events[i].copy(id = UUID.randomUUID()))
        touch()
    }

    // MARK: - Theme

    /** The suite theme, validated by name — an unknown name is refused, like the web's
     *  `theme_set()`. */
    fun setTheme(name: String) {
        if (name !in suiteThemes) return
        data.theme = name
        touch()
    }

    // MARK: - Kind conversion (the edit window's Event / Reminder / Note row)
    //
    // The web's rules, exactly: conversion is one-way into notes (a note never converts
    // out); converting a reminder that has subtasks leaves it behind as their home.

    /** Whether a top-level reminder has subtasks under it (they can't ride along on a
     *  conversion, so their parent stays). */
    fun hasSubtasks(reminder: Reminder): Boolean {
        if (reminder.indent != 0) return false
        val inList = data.reminders
            .filter { it.group == reminder.group && it.folder == reminder.folder }
            .sortedBy { it.order }
        val i = inList.indexOfFirst { it.id == reminder.id }
        if (i < 0) return false
        return i + 1 < inList.size && inList[i + 1].indent > 0
    }

    /** Reminder → Event: the calendar is re-validated (a stray id falls back to the
     *  default), an undated reminder converts onto today. The reminder is removed —
     *  unless it has subtasks, which stay behind with it. */
    fun convertToEvent(reminder: Reminder, cal: UUID? = null) {
        val r = data.reminders.firstOrNull { it.id == reminder.id } ?: return
        val calId = cal?.takeIf { id -> calendarsOnly.any { it.id == id } } ?: data.defaultCal
        data.events.add(Event(text = r.text, date = r.due ?: LocalDate.now(),
                              minutes = r.minutes, cal = calId, recurrence = r.recurrence))
        if (!hasSubtasks(r)) data.reminders.removeAll { it.id == r.id }
        touch()
    }

    /** Reminder → Note: the text becomes a new note's title in the picked folder/group
     *  (validated, falling back to the notes default); the repeat drops away — notes
     *  don't repeat. Same subtask rule as above. */
    fun convertToNote(reminder: Reminder, folder: UUID? = null, group: UUID? = null) {
        val r = data.reminders.firstOrNull { it.id == reminder.id } ?: return
        add(Note(title = r.text, date = r.due,
                 folder = validNoteFolder(folder), group = validNoteGroup(group)))
        if (!hasSubtasks(r)) data.reminders.removeAll { it.id == r.id }
        touch()
    }

    /** Event → Reminder: text, date, time and repeat carry over into the picked
     *  folder/group; the event moves out entirely. */
    fun convertToReminder(event: Event, folder: UUID? = null, group: GroupRef = GroupRef.Inbox) {
        val e = data.events.firstOrNull { it.id == event.id } ?: return
        add(Reminder(text = e.text, due = e.date, minutes = e.minutes,
                     folder = validReminderFolder(folder), group = group, recurrence = e.recurrence))
        data.events.removeAll { it.id == e.id }
        touch()
    }

    /** Event → Note: the text becomes a note's title, dated the event's day; the event
     *  moves out entirely. */
    fun convertToNote(event: Event, folder: UUID? = null, group: UUID? = null) {
        val e = data.events.firstOrNull { it.id == event.id } ?: return
        add(Note(title = e.text, date = e.date,
                 folder = validNoteFolder(folder), group = validNoteGroup(group)))
        data.events.removeAll { it.id == e.id }
        touch()
    }

    private fun validNoteFolder(id: UUID?): UUID? {
        if (id != null && data.folderList(ItemKind.note).any { it.id == id }) return id
        return data.defaultFolder[ItemKind.note.name] ?: data.folderList(ItemKind.note).firstOrNull()?.id
    }
    private fun validNoteGroup(id: UUID?): UUID? =
        if (id != null && data.groupList(ItemKind.note).any { it.id == id }) id else null
    private fun validReminderFolder(id: UUID?): UUID? {
        if (id != null && data.folderList(ItemKind.reminder).any { it.id == id }) return id
        return data.defaultFolder[ItemKind.reminder.name] ?: data.folderList(ItemKind.reminder).firstOrNull()?.id
    }

    /**
     * Take a reminder off the calendar without deleting it — the web's "delete from the
     * calendar" for a dated reminder: the date comes off, the row stays in its own list.
     */
    fun unschedule(reminder: Reminder) {
        val r = data.reminders.firstOrNull { it.id == reminder.id } ?: return
        r.due = null
        touch()
    }

    /**
     * Display order inside a group: undated first, then by date, stored order breaking
     * ties. Completed items sink to the bottom.
     */
    fun sorted(rows: List<Reminder>): List<Reminder> = rows.sortedWith(reminderOrder)

    private val reminderOrder = Comparator<Reminder> { a, b ->
        if (a.done != b.done) return@Comparator if (a.done) 1 else -1
        val ad = a.due
        val bd = b.due
        when {
            ad == null && bd == null -> a.order - b.order
            ad == null -> -1
            bd == null -> 1
            ad != bd -> ad.compareTo(bd)
            else -> a.order - b.order
        }
    }

    /**
     * The order a calendar *day* shows its reminders: undated-first, then by date, then by
     * TIME, then stored order — the web's day-panel order, which the list sort (above) omits
     * because within a list only the date matters.
     */
    private val dayOrder = Comparator<Reminder> { a, b ->
        val ad = a.due
        val bd = b.due
        val byDate = when {
            ad == null && bd == null -> 0
            ad == null -> -1
            bd == null -> 1
            else -> ad.compareTo(bd)
        }
        if (byDate != 0) byDate
        else {
            val byTime = (a.minutes ?: -1).compareTo(b.minutes ?: -1)
            if (byTime != 0) byTime else a.order - b.order
        }
    }

    /**
     * A folder+group as an outline: top-level rows sorted undated-first by date (done
     * sinking), each carrying the indent-1 subtasks that follow it in stored order. A
     * subtask never sorts on its own — it travels with its parent. With no subtasks this
     * is exactly the flat sort, one row per block.
     */
    fun reminders(folder: UUID?, group: GroupRef): List<Reminder> {
        val rows = data.reminders
            .filter { it.group == group && (folder == null || it.folder == folder) }
            .sortedBy { it.order }
        val blocks = ArrayList<MutableList<Reminder>>()
        for (r in rows) {
            if (r.indent == 0 || blocks.isEmpty()) blocks.add(mutableListOf(r))   // a new top-level block
            else blocks.last().add(r)                                             // a subtask joins the last
        }
        val rank = HashMap<UUID, Int>()
        sorted(blocks.map { it.first() }).forEachIndexed { i, h -> rank[h.id] = i }
        return blocks.sortedBy { rank[it.first().id] ?: 0 }.flatten()
    }

    /**
     * The reminders the list shows in a group: one focused folder, or — when `folder` is
     * null (the "All" view) — every folder that isn't hidden. The list, Markdown and the
     * watch all read this, so a hidden folder is hidden everywhere the web hides it.
     */
    fun remindersShown(folder: UUID?, group: GroupRef): List<Reminder> {
        if (folder != null) return reminders(folder = folder, group = group)
        val hidden = (data.hiddenFolders[ItemKind.reminder.name] ?: emptyList()).toSet()
        return reminders(folder = null, group = group).filter { r ->
            val f = r.folder ?: return@filter true
            !hidden.contains(f)
        }
    }

    /**
     * Add a blank subtask directly under `parent`: same folder and group, indent 1,
     * slotted right after it in stored order. Returns it so the view can open it to type;
     * left empty, the caller deletes it again, like the web.
     */
    fun addSubtask(parent: Reminder): Reminder {
        val child = Reminder(folder = parent.folder, group = parent.group, indent = 1)
        val rows = data.reminders
            .filter { it.group == parent.group && it.folder == parent.folder }
            .sortedBy { it.order }
            .toMutableList()
        val pi = rows.indexOfFirst { it.id == parent.id }
        if (pi >= 0) rows.add(pi + 1, child) else rows.add(child)
        data.reminders.add(child)
        rows.forEachIndexed { i, r ->                                            // renumber to match
            val idx = data.reminders.indexOfFirst { it.id == r.id }
            if (idx >= 0) data.reminders[idx].order = i
        }
        touch()
        return child
    }

    /** Lift a subtask back out to top level (the web's ‹), or push a task in. One level only. */
    fun setIndent(reminder: Reminder, indent: Int) {
        val r = data.reminders.firstOrNull { it.id == reminder.id } ?: return
        r.indent = indent.coerceIn(0, 1)
        touch()
    }

    /**
     * Persist a drag-reorder within a group: `ordered` is the group as arranged now.
     * Display still sorts undated-first by date, so `order` only breaks ties.
     */
    fun moveReminders(ordered: List<Reminder>, from: Set<Int>, to: Int) {
        val rows = ordered.toMutableList()
        rows.reorder(from, to)
        rows.forEachIndexed { i, r ->
            val idx = data.reminders.indexOfFirst { it.id == r.id }
            if (idx >= 0) data.reminders[idx].order = i
        }
        touch()
    }

    // MARK: - Notes

    fun add(note: Note) {
        val new = note.copy(order = (data.notes.maxOfOrNull { it.order } ?: 0) + 1)
        data.notes.add(new)
        touch()
    }

    fun update(note: Note) {
        val i = data.notes.indexOfFirst { it.id == note.id }
        if (i < 0) return
        data.notes[i] = note.copy(updated = Instant.now())
        touch()
    }

    fun delete(note: Note) { data.notes.removeAll { it.id == note.id }; touch() }

    /** Take a note off the calendar without deleting it — its `date` comes off, the note stays. */
    fun unschedule(note: Note) {
        val n = data.notes.firstOrNull { it.id == note.id } ?: return
        n.date = null
        touch()
    }

    /** Notes in a folder+group, in drag order (stored `order`). */
    fun notes(folder: UUID?, group: UUID?): List<Note> =
        data.notes.filter { it.group == group && (folder == null || it.folder == folder) }.sortedBy { it.order }

    /** The notes the list shows in a group: one focused folder, or every non-hidden
     *  folder on "All" — the notes twin of `remindersShown`. */
    fun notesShown(folder: UUID?, group: UUID?): List<Note> {
        if (folder != null) return notes(folder = folder, group = group)
        val hidden = (data.hiddenFolders[ItemKind.note.name] ?: emptyList()).toSet()
        return notes(folder = null, group = group).filter { n ->
            val f = n.folder ?: return@filter true
            !hidden.contains(f)
        }
    }

    fun moveNotes(ordered: List<Note>, from: Set<Int>, to: Int) {
        val rows = ordered.toMutableList()
        rows.reorder(from, to)
        rows.forEachIndexed { i, n ->
            val idx = data.notes.indexOfFirst { it.id == n.id }
            if (idx >= 0) data.notes[idx].order = i
        }
        touch()
    }

    // MARK: - Events

    fun add(event: Event) { data.events.add(event); touch() }

    fun update(event: Event) {
        val i = data.events.indexOfFirst { it.id == event.id }
        if (i < 0) return
        data.events[i] = event
        touch()
    }

    fun delete(event: Event) { data.events.removeAll { it.id == event.id }; touch() }

    /** The calendars themselves. (`isSet` rows can't reach here — they're dropped on read,
     *  the way the web's `load_calendars()` drops leftover set rows.) */
    val calendarsOnly: List<Cal> get() = data.calendars.filter { !it.isSet }

    fun addCalendar(name: String) {
        val clean = name.trim()
        if (clean.isEmpty()) return
        val made = Cal(name = clean, color = calendarsOnly.size % 10)
        data.calendars.add(made)
        if (data.defaultCal == null) data.defaultCal = made.id
        touch()
    }

    /**
     * A deleted calendar takes its events with it — there's nowhere sensible for them to
     * land, and an event with no calendar has no colour.
     */
    fun deleteCalendar(cal: Cal) {
        if (calendarsOnly.size <= 1) return
        data.events.removeAll { it.cal == cal.id }
        data.calendars.removeAll { it.id == cal.id }
        if (data.defaultCal == cal.id) data.defaultCal = calendarsOnly.firstOrNull()?.id
        touch()
    }

    // MARK: - Calendar visibility (the three-gesture picker)
    //
    // The calendar's twin of folder visibility, over the web's `hidden_cals`.

    /** Whether a calendar is currently shown. */
    fun calShown(id: UUID): Boolean = !data.hiddenCals.contains(id)

    /** The box: toggle just this calendar. */
    fun toggleCal(id: UUID) {
        if (!data.hiddenCals.remove(id)) data.hiddenCals.add(id)
        touch()
    }

    /** The row: show only this calendar. */
    fun showOnlyCal(id: UUID) = showOnlyCalendars(listOf(id))

    /** Show only the given calendars, hiding the rest — the row gesture, and how a saved
     *  set (a named group of calendars) applies in the visibility model. */
    fun showOnlyCalendars(ids: List<UUID>) {
        val keep = ids.toSet()
        data.hiddenCals = calendarsOnly.map { it.id }.filter { !keep.contains(it) }.toMutableList()
        touch()
    }

    /** The "All" master: on only when no calendar is hidden. */
    fun calsAllShown(): Boolean {
        val hidden = data.hiddenCals.toSet()
        return calendarsOnly.none { hidden.contains(it.id) }
    }

    fun setCalsAll(show: Boolean) {
        data.hiddenCals = if (show) mutableListOf() else calendarsOnly.map { it.id }.toMutableList()
        touch()
    }

    /** The visible-calendar filter for the grid and day panel: null when everything shows
     *  (no filtering), otherwise the ids still on. Stale hidden ids are ignored. */
    val shownCalScope: Set<UUID>?
        get() {
            val all = calendarsOnly.map { it.id }.toSet()
            val hidden = data.hiddenCals.toSet().intersect(all)
            return if (hidden.isEmpty()) null else all.subtract(hidden)
        }

    // MARK: - Reminder folders on the calendar (the web's rf_mode)
    //
    // Separate from the Reminders picker's visibility: a folder can show in the list but
    // be switched off for the calendar, so its reminders don't clutter the month.

    /** Whether a reminder folder's items reach the calendar (off is the web's rf_mode 'none'). */
    fun calFolderShown(id: UUID): Boolean = !data.calHiddenFolders.contains(id)

    fun toggleCalFolder(id: UUID) {
        if (!data.calHiddenFolders.remove(id)) data.calHiddenFolders.add(id)
        touch()
    }

    /** Which calendar ids a selection covers: null (show all) or a single calendar. */
    fun calScope(selection: UUID?): Set<UUID>? {
        val sel = selection ?: return null
        data.cal(sel) ?: return null
        return setOf(sel)
    }

    /**
     * Events on one day, repeats expanded, optionally narrowed to a calendar scope. An
     * event with no calendar counts as the default one.
     */
    fun events(on: LocalDate, scope: Set<UUID>? = null): List<Event> =
        data.events.filter { event ->
            if (scope != null) {
                val ec = event.cal ?: data.defaultCal
                if (ec == null || !scope.contains(ec)) return@filter false
            }
            val rule = event.recurrence
            if (rule != null) rule.dates(start = event.date, from = on, to = on).isNotEmpty()
            else event.date == on
        }.sortedBy { it.minutes ?: -1 }

    /**
     * Reminders showing on a day: its own date, any repeat landing there, an overdue one
     * rolled onto today, and the Calendar group's undated riders.
     */
    fun reminders(on: LocalDate, today: LocalDate): List<Reminder> {
        val calHidden = data.calHiddenFolders.toSet()
        return data.reminders.filter { r ->
            if (r.done) return@filter false
            val f = r.folder
            if (f != null && calHidden.contains(f)) return@filter false   // folder off for the calendar
            if (r.ridesAlong) return@filter on == today
            val due = r.due ?: return@filter false
            if (due == on) return@filter true
            if (due < today && on == today) return@filter true          // overdue rides on today
            val rule = r.recurrence
            if (rule != null) rule.dates(start = due, from = on, to = on).isNotEmpty() else false
        }.sortedWith(dayOrder)
    }

    fun notes(on: LocalDate): List<Note> = data.notes.filter { it.date == on }

    // MARK: - Habits

    fun addHabit(name: String, group: UUID?) {
        val clean = name.trim()
        if (clean.isEmpty()) return
        val order = (data.habits.maxOfOrNull { it.order } ?: 0) + 1
        data.habits.add(Habit(name = clean, group = group, order = order))
        touch()
    }

    fun toggleHabit(habit: Habit, on: LocalDate) {
        val h = data.habits.firstOrNull { it.id == habit.id } ?: return
        val key = on.key
        if (h.marks.contains(key)) h.marks.remove(key) else h.marks.add(key)
        touch()
    }

    fun updateHabit(habit: Habit) {
        val i = data.habits.indexOfFirst { it.id == habit.id }
        if (i < 0) return
        data.habits[i] = habit
        touch()
    }

    fun deleteHabit(habit: Habit) { data.habits.removeAll { it.id == habit.id }; touch() }

    fun habits(group: UUID?): List<Habit> = data.habits.filter { it.group == group }.sortedBy { it.order }

    fun moveHabits(ordered: List<Habit>, from: Set<Int>, to: Int) {
        val rows = ordered.toMutableList()
        rows.reorder(from, to)
        rows.forEachIndexed { i, h ->
            val idx = data.habits.indexOfFirst { it.id == h.id }
            if (idx >= 0) data.habits[idx].order = i
        }
        touch()
    }

    // MARK: - Habits: the month view and its section filter
    //
    // The month pies are "how much of a day got done", which only means something over
    // the sections you meant — so the filter decides which feed them. It's the suite's
    // three-gesture picker: a box toggles one, a row makes it the only one, All is master.

    /** Whether a section counts toward the pies (ungrouped is null). */
    fun habitSectionShown(group: UUID?): Boolean =
        if (group != null) !data.habitHidden.contains(group) else !data.habitHideUngrouped

    /** The box: toggle just this section. */
    fun toggleHabitSection(group: UUID?) {
        if (group != null) {
            if (data.habitHidden.contains(group)) data.habitHidden.remove(group) else data.habitHidden.add(group)
        } else {
            data.habitHideUngrouped = !data.habitHideUngrouped
        }
        touch()
    }

    /** The row: count only this one, everything else off. */
    fun onlyHabitSection(group: UUID?) {
        data.habitHidden = data.groupList(ItemKind.habit).map { it.id }.toMutableSet()
        data.habitHideUngrouped = true
        if (group != null) data.habitHidden.remove(group) else data.habitHideUngrouped = false
        touch()
    }

    /** The "All" master: on when every section counts. */
    val habitAllShown: Boolean
        get() = !data.habitHideUngrouped && data.groupList(ItemKind.habit).all { !data.habitHidden.contains(it.id) }

    fun setHabitAll(show: Boolean) {
        if (show) {
            data.habitHidden.clear(); data.habitHideUngrouped = false
        } else {
            data.habitHidden = data.groupList(ItemKind.habit).map { it.id }.toMutableSet(); data.habitHideUngrouped = true
        }
        touch()
    }

    /** The habits the pies count: those in shown sections. */
    fun habitsCounted(): List<Habit> = data.habits.filter { habitSectionShown(it.group) }

    /**
     * For a month, each day's ticks among the counted habits, broken down by section, so
     * a day's pie can be filled in the sections' own colours. Key is "yyyy-MM-dd"; value
     * maps a section (null = ungrouped) to how many of its habits were ticked.
     */
    fun habitMonthFill(month: LocalDate): Map<String, Map<UUID?, Int>> {
        val prefix = month.startOfMonth.key.substring(0, 7)             // "yyyy-MM"
        val out = HashMap<String, HashMap<UUID?, Int>>()
        for (h in habitsCounted()) {
            for (mark in h.marks) if (mark.startsWith(prefix)) {
                val day = out.getOrPut(mark) { HashMap() }
                day[h.group] = (day[h.group] ?: 0) + 1
            }
        }
        return out
    }

    // MARK: - Copy as Markdown

    /**
     * The reminders in a folder (all of them, on "All") as Markdown: a heading per group,
     * each row a checkbox with its date/time/repeat, subtasks indented — the web's "Copy
     * as Markdown".
     */
    fun markdown(folder: UUID?, includeDone: Boolean = false): String {
        val today = LocalDate.now()
        val out = ArrayList<String>()
        fun emit(ref: GroupRef, title: String) {
            val rows = remindersShown(folder = folder, group = ref).filter { includeDone || !it.done }
            if (rows.isEmpty()) return
            out.add("## $title")
            for (r in rows) {
                val box = if (r.done) "[x]" else "[ ]"
                var line = "  ".repeat(r.indent) + "- $box ${r.text}"
                val meta = ArrayList<String>()
                r.due?.let { meta.add(dayLabel(it, today)) }
                r.minutes?.let { meta.add(timeLabel(it)) }
                r.recurrence?.let { meta.add(it.label) }
                if (meta.isNotEmpty()) line += " (" + meta.joinToString(" · ") + ")"
                out.add(line)
            }
            out.add("")
        }
        emit(GroupRef.Calendar, "Calendar")
        emit(GroupRef.Inbox, "Reminders")
        for (g in data.groupList(ItemKind.reminder)) emit(GroupRef.Group(g.id), g.name)
        return out.joinToString("\n").trim() + "\n"
    }

    // MARK: - The watch's list

    /**
     * The list a watch draws: the same groups in the same order as the Reminders screen,
     * open items only, dates already turned into short strings. Built here so the two ends
     * can't grow apart.
     */
    fun watchList(): WatchList {
        val today = LocalDate.now()
        fun items(ref: GroupRef): List<WatchItem> =
            remindersShown(folder = null, group = ref)
                .filter { !it.done }
                .map { r ->
                    val bits = ArrayList<String>()
                    val due = r.due
                    if (due != null) bits.add(dayLabel(due, today))
                    else if (r.ridesAlong) bits.add("today")
                    r.minutes?.let { bits.add(timeLabel(it)) }
                    WatchItem(id = r.id.toString(), text = r.text, due = bits.joinToString(" "), overdue = r.overdue(today))
                }
        val sections = ArrayList<WatchSection>()
        sections.add(WatchSection("Calendar", items(GroupRef.Calendar)))
        sections.add(WatchSection("Reminders", items(GroupRef.Inbox)))
        for (g in data.groupList(ItemKind.reminder)) sections.add(WatchSection(g.name, items(GroupRef.Group(g.id))))
        return WatchList(folder = "",
                         sections = sections.filter { it.items.isNotEmpty() },
                         days = watchDays(today))
    }

    /**
     * The watch's week window: seven days starting today, each holding what the phone's
     * day panel would show — events (by time), then reminders (undated riders and overdue
     * collect on today, undated-first then date then time), then notes — so the wrist and
     * the phone can never disagree about a day. Twin of Store.watchDays (Swift).
     */
    fun watchDays(today: LocalDate): List<WatchDay> {
        val scope = shownCalScope
        val days = ArrayList<WatchDay>()
        for (offset in 0 until 7) {
            val d = today.plusDays(offset.toLong())
            val items = ArrayList<WatchItem>()
            for (e in events(on = d, scope = scope)) {
                items.add(WatchItem(id = e.id.toString(), text = e.text,
                                    due = e.minutes?.let { timeLabel(it) } ?: "",
                                    overdue = false, kind = "event"))
            }
            for (r in reminders(on = d, today = today)) {
                val bits = ArrayList<String>()
                val due = r.due
                if (due != null && due < today) bits.add(dayLabel(due, today))
                r.minutes?.let { bits.add(timeLabel(it)) }
                items.add(WatchItem(id = r.id.toString(), text = r.text,
                                    due = bits.joinToString(" "),
                                    overdue = r.overdue(today), kind = "reminder"))
            }
            for (n in notes(on = d)) {
                items.add(WatchItem(id = n.id.toString(), text = n.title,
                                    due = "", overdue = false, kind = "note"))
            }
            days.add(WatchDay(id = d.toString(), name = watchDayName(d, today), items = items))
        }
        return days
    }

    companion object {
        /** The one JSON config: pretty, defaults written, unknown keys ignored (tolerant decode). */
        val json = Json {
            prettyPrint = true
            encodeDefaults = true
            ignoreUnknownKeys = true
            serializersModule = SerializersModule {
                contextual(UUID::class, UuidSerializer)
                contextual(LocalDate::class, LocalDateSerializer)
                contextual(Instant::class, InstantSerializer)
            }
        }

        /**
         * "Buddy's data" — the dinner-with-friends scenario the website's seed-buddy builds
         * — so the app and watch have a plausible set to look at without typing it in. Dated
         * relative to today so it never goes stale.
         */
        fun sampleData(): AppData {
            val today = LocalDate.now()
            fun days(n: Int) = today.plusDays(n.toLong())
            // The next Saturday (which=1) and the one after (which=2) — the two dinners.
            fun saturday(which: Int): LocalDate {
                var s = today.plusDays(1)
                while (s.dayOfWeek != DayOfWeek.SATURDAY) s = s.plusDays(1)
                return s.plusDays(7L * (which - 1))
            }

            val d = AppData.starter                       // one General folder each, one calendar

            // Reminders: a Cooking folder with Groceries + Dinner-prep sections, plus riders.
            val cooking = Folder(name = "Cooking", kind = ItemKind.reminder, color = 4)
            d.folders.add(cooking)
            val groceries = ListGroup(name = "Groceries", kind = ItemKind.reminder, order = 1, color = 1)
            val prep = ListGroup(name = "Dinner prep", kind = ItemKind.reminder, order = 2, color = 4)
            d.groups.addAll(listOf(groceries, prep))
            val general = d.folderList(ItemKind.reminder).first().id
            var ord = 0
            fun rem(text: String, folder: UUID, group: GroupRef, due: LocalDate? = null) {
                ord += 1
                d.reminders.add(Reminder(text = text, due = due, folder = folder, group = group, order = ord))
            }
            for (g in listOf("Milk", "Eggs", "Flour", "Olive oil", "Parmesan", "Fresh basil"))
                rem(g, cooking.id, GroupRef.Group(groceries.id))
            rem("Marinate the chicken", cooking.id, GroupRef.Group(prep.id), saturday(1))
            rem("Chop the vegetables", cooking.id, GroupRef.Group(prep.id), saturday(1))
            rem("Set the table", cooking.id, GroupRef.Group(prep.id), saturday(1))
            rem("Pick up the wine", general, GroupRef.Calendar)   // undated Calendar rider — rides on today
            rem("Text everyone the time", general, GroupRef.Inbox, days(1))

            // Notes: a Recipes folder with the recipe.
            val recipes = Folder(name = "Recipes", kind = ItemKind.note, color = 2)
            d.folders.add(recipes)
            d.notes.add(
                Note(
                    title = "Chicken parmesan",
                    body = "Serves 4.\n\n- Pound the chicken thin, salt both sides.\n" +
                        "- Dredge: flour, then egg, then breadcrumbs + parmesan.\n" +
                        "- Fry 3 min a side; top with sauce and mozzarella.\n" +
                        "- Bake at 425°F until bubbling, ~15 min.\n\nBuddy's recipe.",
                    folder = recipes.id, order = 1,
                )
            )

            // Calendar: the two dinners, next two Saturdays, 7pm.
            val calId = d.calendars.first().id
            d.events.add(Event(text = "Dinner with friends", date = saturday(1), minutes = 19 * 60, cal = calId))
            d.events.add(Event(text = "Dinner, round two", date = saturday(2), minutes = 19 * 60, cal = calId))

            // Habits: a few, with the last week or so already ticked in.
            val health = ListGroup(name = "Health", kind = ItemKind.habit, order = 1, color = 3)
            d.groups.add(health)
            fun habit(name: String, group: UUID?, ticked: List<Int>, order: Int) {
                val marks = ticked.map { days(-it).key }.toMutableSet()
                d.habits.add(Habit(name = name, group = group, marks = marks, order = order))
            }
            habit("Floss", health.id, listOf(0, 1, 3, 4, 6), 1)
            habit("Read 20 min", health.id, listOf(0, 2, 3, 5), 2)
            habit("Walk", null, listOf(1, 2, 4, 5, 6), 3)

            return d
        }
    }
}
