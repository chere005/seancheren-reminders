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

    /** Notes in a folder+group, in drag order (stored `order`). */
    fun notes(folder: UUID?, group: UUID?): List<Note> =
        data.notes.filter { it.group == group && (folder == null || it.folder == folder) }.sortedBy { it.order }

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

    /** Real calendars (not sets) and calendar sets, from the one stored list. */
    val calendarsOnly: List<Cal> get() = data.calendars.filter { !it.isSet }
    val calSets: List<Cal> get() = data.calendars.filter { it.isSet }

    fun addCalendar(name: String) {
        val clean = name.trim()
        if (clean.isEmpty()) return
        val made = Cal(name = clean, color = calendarsOnly.size % 10)
        data.calendars.add(made)
        if (data.defaultCal == null) data.defaultCal = made.id
        touch()
    }

    /** A set is a saved view over several calendars' ids. */
    fun addSet(name: String, members: List<UUID>) {
        val clean = name.trim()
        if (clean.isEmpty() || members.isEmpty()) return
        data.calendars.add(Cal(name = clean, color = data.calendars.size % 10, members = members))
        touch()
    }

    /**
     * A deleted calendar takes its events with it — there's nowhere sensible for them to
     * land. A deleted set is just dropped, and any calendar removed is scrubbed from sets.
     */
    fun deleteCalendar(cal: Cal) {
        if (cal.isSet) {
            data.calendars.removeAll { it.id == cal.id }
            touch(); return
        }
        if (calendarsOnly.size <= 1) return
        data.events.removeAll { it.cal == cal.id }
        data.calendars.forEach { c ->
            val m = c.members
            if (m != null) c.members = m.filter { it != cal.id }
        }
        data.calendars.removeAll { it.id == cal.id }
        if (data.defaultCal == cal.id) data.defaultCal = calendarsOnly.firstOrNull()?.id
        touch()
    }

    /**
     * Which calendar ids a selection covers: null (show all), a single calendar, or a
     * set's members (validated against calendars that still exist).
     */
    fun calScope(selection: UUID?): Set<UUID>? {
        val sel = selection ?: return null
        val c = data.cal(sel) ?: return null
        val members = c.members ?: return setOf(sel)
        return members.toSet().intersect(calendarsOnly.map { it.id }.toSet())
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
    fun reminders(on: LocalDate, today: LocalDate): List<Reminder> =
        sorted(data.reminders.filter { r ->
            if (r.done) return@filter false
            if (r.ridesAlong) return@filter on == today
            val due = r.due ?: return@filter false
            if (due == on) return@filter true
            if (due < today && on == today) return@filter true          // overdue rides on today
            val rule = r.recurrence
            if (rule != null) rule.dates(start = due, from = on, to = on).isNotEmpty() else false
        })

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
            val rows = reminders(folder = folder, group = ref).filter { includeDone || !it.done }
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
            reminders(folder = null, group = ref)
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
        return WatchList(folder = "", sections = sections.filter { it.items.isNotEmpty() })
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
