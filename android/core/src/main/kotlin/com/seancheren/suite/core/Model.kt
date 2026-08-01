@file:UseContextualSerialization(UUID::class, LocalDate::class, Instant::class)

package com.seancheren.suite.core

import kotlinx.serialization.KSerializer
import kotlinx.serialization.Serializable
import kotlinx.serialization.UseContextualSerialization
import kotlinx.serialization.descriptors.PrimitiveKind
import kotlinx.serialization.descriptors.PrimitiveSerialDescriptor
import kotlinx.serialization.descriptors.SerialDescriptor
import kotlinx.serialization.encoding.Decoder
import kotlinx.serialization.encoding.Encoder
import java.time.Instant
import java.time.LocalDate
import java.time.YearMonth
import java.time.format.DateTimeFormatter
import java.util.Locale
import java.util.UUID

/**
 * Everything the app owns — the Kotlin cousin of ios/Shared/Model.swift.
 *
 * There is no server, no login and no account: one JSON file in the app's files
 * dir is the whole database. It's a plain @Serializable tree because it's small,
 * readable, and can be handed to a watch as-is. Every field carries a default, so
 * a document written before a field existed still loads (see the Json config in
 * Store.kt) rather than resetting to empty — the twin of Swift's decodeIfPresent.
 */

// MARK: - Dates
//
// Every stored date is day-granular (LocalDate); a time of day is a separate
// `minutes` field, so moving a date can't quietly move the time with it. LocalDate
// is genuinely date-only, so unlike Swift there's no startOfDay dance to get wrong.

/** "2026-07-25" — how a habit's ticked days are keyed. ISO_LOCAL_DATE, always padded. */
val LocalDate.key: String get() = toString()

/** Midnight on the first of this month — the anchor the calendar grid is built from. */
val LocalDate.startOfMonth: LocalDate get() = withDayOfMonth(1)

/** "2:30pm" from minutes-since-midnight. */
fun timeLabel(minutes: Int): String {
    val h24 = minutes / 60
    val m = minutes % 60
    val h = if (h24 % 12 == 0) 12 else h24 % 12
    val suffix = if (h24 < 12) "am" else "pm"
    return if (m == 0) "$h$suffix" else String.format(Locale.US, "%d:%02d%s", h, m, suffix)
}

// MARK: - Serializers (the small ones the model needs)

object UuidSerializer : KSerializer<UUID> {
    override val descriptor: SerialDescriptor = PrimitiveSerialDescriptor("UUID", PrimitiveKind.STRING)
    override fun serialize(encoder: Encoder, value: UUID) = encoder.encodeString(value.toString())
    override fun deserialize(decoder: Decoder): UUID = UUID.fromString(decoder.decodeString())
}

object LocalDateSerializer : KSerializer<LocalDate> {
    override val descriptor: SerialDescriptor = PrimitiveSerialDescriptor("LocalDate", PrimitiveKind.STRING)
    override fun serialize(encoder: Encoder, value: LocalDate) = encoder.encodeString(value.toString())
    override fun deserialize(decoder: Decoder): LocalDate = LocalDate.parse(decoder.decodeString())
}

object InstantSerializer : KSerializer<Instant> {
    override val descriptor: SerialDescriptor = PrimitiveSerialDescriptor("Instant", PrimitiveKind.LONG)
    override fun serialize(encoder: Encoder, value: Instant) = encoder.encodeLong(value.toEpochMilli())
    override fun deserialize(decoder: Decoder): Instant = Instant.ofEpochMilli(decoder.decodeLong())
}

// MARK: - Repeats

@Serializable
enum class RepeatUnit { day, week, month, year }

/** How often something comes back. Absent (null) means once. */
@Serializable
data class Recurrence(
    var n: Int = 1,
    var unit: RepeatUnit = RepeatUnit.week,
) {
    val label: String
        get() = if (n == 1) "every ${unit.name}" else "every $n ${unit.name}s"

    /**
     * One step on. Month and year keep the day of the month and clamp it — the 31st
     * repeats as the 30th, the 28th — rather than sliding into the next month.
     */
    fun step(date: LocalDate): LocalDate {
        val s = maxOf(1, n)
        return when (unit) {
            RepeatUnit.day -> date.plusDays(s.toLong())
            RepeatUnit.week -> date.plusDays(s.toLong() * 7)
            RepeatUnit.month -> clamped(date, s)
            RepeatUnit.year -> clamped(date, s * 12)
        }
    }

    private fun clamped(date: LocalDate, months: Int): LocalDate {
        val wanted = date.dayOfMonth
        val moved = YearMonth.from(date).plusMonths(months.toLong())
        return moved.atDay(minOf(wanted, moved.lengthOfMonth()))
    }

    /**
     * Every occurrence inside the window being drawn. There's only ever the one stored
     * row — this expands it for whatever range the caller is showing.
     */
    fun dates(start: LocalDate, from: LocalDate, to: LocalDate): List<LocalDate> {
        if (start > to) return emptyList()
        val out = ArrayList<LocalDate>()
        var d = start
        var hops = 0
        while (d <= to && hops < 400) {
            if (d >= from) out.add(d)
            d = step(d)
            hops++
        }
        return out
    }

    /** Where a repeat lands next once it's been ticked off. */
    fun next(start: LocalDate, after: LocalDate): LocalDate {
        var d = start
        var hops = 0
        while (d <= after && hops < 400) { d = step(d); hops++ }
        return d
    }
}

// MARK: - Entities

@Serializable
enum class ItemKind { reminder, note, habit }

/** A folder filters one kind of thing. Reminders and notes keep separate sets. */
@Serializable
data class Folder(
    var id: UUID = UUID.randomUUID(),
    var name: String = "",
    var kind: ItemKind = ItemKind.reminder,
    var color: Int = 0,
)

/**
 * A named group inside a list — the website calls these sections. `color` is a
 * palette index shown as a dot left of the name; defaults by position so a new
 * section is distinct at once. Missing in an old document → colour 0 (the default).
 */
@Serializable
data class ListGroup(
    var id: UUID = UUID.randomUUID(),
    var name: String = "",
    var kind: ItemKind = ItemKind.reminder,
    var order: Int = 0,
    var color: Int = 0,
)

/**
 * Which group a reminder sits in. Two aren't rows you can delete: the ungrouped
 * catch-all everything starts in (Inbox), and Calendar, whose undated items ride
 * along on today, every day, until they're ticked off.
 */
@Serializable(with = GroupRefSerializer::class)
sealed interface GroupRef {
    data object Inbox : GroupRef
    data object Calendar : GroupRef
    data class Group(val id: UUID) : GroupRef

    val groupId: UUID? get() = (this as? Group)?.id
}

/** Compact string form: "inbox", "calendar", or the group's UUID (never equal to those). */
object GroupRefSerializer : KSerializer<GroupRef> {
    override val descriptor: SerialDescriptor = PrimitiveSerialDescriptor("GroupRef", PrimitiveKind.STRING)
    override fun serialize(encoder: Encoder, value: GroupRef) = encoder.encodeString(
        when (value) {
            GroupRef.Inbox -> "inbox"
            GroupRef.Calendar -> "calendar"
            is GroupRef.Group -> value.id.toString()
        }
    )
    override fun deserialize(decoder: Decoder): GroupRef = when (val s = decoder.decodeString()) {
        "inbox" -> GroupRef.Inbox
        "calendar" -> GroupRef.Calendar
        else -> GroupRef.Group(UUID.fromString(s))
    }
}

@Serializable
data class Reminder(
    var id: UUID = UUID.randomUUID(),
    var text: String = "",
    var due: LocalDate? = null,          // null = undated
    var minutes: Int? = null,            // time of day, if it has one
    var done: Boolean = false,
    var folder: UUID? = null,
    var group: GroupRef = GroupRef.Inbox,
    var recurrence: Recurrence? = null,
    var order: Int = 0,
    /**
     * Outline depth. 0 is a top-level reminder; 1 is a subtask sitting under the row
     * above it. One level only, like the web. A subtask travels with its parent when
     * the list is sorted, so it never floats up under whatever sits above it.
     */
    var indent: Int = 0,
) {
    /** Late, and not just "hasn't happened yet today". */
    fun overdue(today: LocalDate): Boolean {
        val d = due ?: return false
        return !done && d < today
    }

    /** An undated item in the Calendar group isn't late — it's meant to keep showing. */
    val ridesAlong: Boolean get() = due == null && group == GroupRef.Calendar
}

@Serializable
data class Note(
    var id: UUID = UUID.randomUUID(),
    var title: String = "",
    var body: String = "",
    var date: LocalDate? = null,
    var folder: UUID? = null,
    var group: UUID? = null,
    var order: Int = 0,
    var updated: Instant = Instant.now(),
)

@Serializable
data class Cal(
    var id: UUID = UUID.randomUUID(),
    var name: String = "",
    var color: Int = 0,
    /**
     * Non-null marks this row as a *set* — a saved view over several calendars' ids —
     * rather than a calendar of its own. Kept in the same list, the way the web does.
     */
    var members: List<UUID>? = null,
) {
    val isSet: Boolean get() = members != null
}

@Serializable
data class Event(
    var id: UUID = UUID.randomUUID(),
    var text: String = "",
    var date: LocalDate = LocalDate.now(),
    var minutes: Int? = null,
    var cal: UUID? = null,
    var recurrence: Recurrence? = null,
)

@Serializable
data class Habit(
    var id: UUID = UUID.randomUUID(),
    var name: String = "",
    var group: UUID? = null,
    var marks: MutableSet<String> = mutableSetOf(),   // day keys, so a Set is cheap to test
    var order: Int = 0,
) {
    fun on(date: LocalDate): Boolean = marks.contains(date.key)
}

// MARK: - The document

@Serializable
data class AppData(
    var reminders: MutableList<Reminder> = mutableListOf(),
    var notes: MutableList<Note> = mutableListOf(),
    var events: MutableList<Event> = mutableListOf(),
    var habits: MutableList<Habit> = mutableListOf(),
    var calendars: MutableList<Cal> = mutableListOf(),
    var folders: MutableList<Folder> = mutableListOf(),
    var groups: MutableList<ListGroup> = mutableListOf(),

    /** Where new things land, and what to reopen on. Keyed by ItemKind.name. */
    var defaultFolder: MutableMap<String, UUID> = mutableMapOf(),
    var lastFolder: MutableMap<String, UUID> = mutableMapOf(),
    var defaultCal: UUID? = null,
    /** The calendar or set the Calendar screen last had selected (null = all). */
    var lastCal: UUID? = null,

    /**
     * The Habits month pies count every section unless it's in here; the ungrouped run
     * has no id, so it gets its own flag. Stored as what's *hidden*, so a section added
     * later counts without anyone touching this.
     */
    var habitHidden: MutableSet<UUID> = mutableSetOf(),
    var habitHideUngrouped: Boolean = false,
    /** Habits opens on the view you left it on: the tick grid (false) or the month (true). */
    var habitsMonth: Boolean = false,
) {
    fun folderList(kind: ItemKind): List<Folder> = folders.filter { it.kind == kind }
    fun groupList(kind: ItemKind): List<ListGroup> = groups.filter { it.kind == kind }.sortedBy { it.order }
    fun folder(id: UUID?): Folder? = folders.firstOrNull { it.id == id }
    fun cal(id: UUID?): Cal? = calendars.firstOrNull { it.id == id }

    companion object {
        /** A first run: one General folder each, one calendar, nothing in them. */
        val starter: AppData
            get() {
                val d = AppData()
                val reminderFolder = Folder(name = "General", kind = ItemKind.reminder, color = 1)
                val noteFolder = Folder(name = "General", kind = ItemKind.note, color = 1)
                val calendar = Cal(name = "Personal", color = 0)
                d.folders = mutableListOf(reminderFolder, noteFolder)
                d.calendars = mutableListOf(calendar)
                d.defaultCal = calendar.id
                d.defaultFolder = mutableMapOf(
                    ItemKind.reminder.name to reminderFolder.id,
                    ItemKind.note.name to noteFolder.id,
                )
                return d
            }
    }
}

// MARK: - Core helpers
//
// Kept in the core, on the plain JVM alone (no Compose), so `./gradlew :core:test`
// can exercise the logic without an emulator — the twin of `swift test`.

/**
 * Reorder in place: the same result as SwiftUI's move(fromOffsets:toOffset:), ported
 * onto MutableList so the core doesn't depend on any UI framework. `to` is an index
 * into the list *before* the moved rows are removed, exactly as drag callbacks give it.
 */
fun <T> MutableList<T>.reorder(from: Set<Int>, to: Int) {
    val moving = from.sorted().map { this[it] }
    val target = to - from.count { it < to }
    from.sortedDescending().forEach { removeAt(it) }
    addAll(target.coerceIn(0, size), moving)
}

/**
 * "today", "tomorrow", "Aug 3" — a short date, because it sits under the row's text.
 * In the core because the watch list and the Calendar summaries build it too.
 */
fun dayLabel(date: LocalDate, today: LocalDate): String = when (date) {
    today -> "today"
    today.plusDays(1) -> "tomorrow"
    today.minusDays(1) -> "yesterday"
    else -> {
        val pattern = if (date.year == today.year) "MMM d" else "MMM d, yyyy"
        date.format(DateTimeFormatter.ofPattern(pattern, Locale.US))
    }
}
