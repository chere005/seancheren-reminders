package com.seancheren.suite.core

import kotlinx.serialization.Serializable
import java.time.LocalDate
import java.time.format.DateTimeFormatter
import java.util.Locale

/**
 * What the phone hands a watch — the cousin of ios/Shared/WatchPayload.swift.
 *
 * Not the whole document — the watch can't edit anything, so it only gets what it
 * draws, with the dates already formatted. Built by Store.watchList() so the two ends
 * can't drift apart. (Wear OS is a later job; this shape is ready for it.)
 */
@Serializable
data class WatchList(
    var folder: String = "",
    var sections: List<WatchSection> = emptyList(),
    var days: List<WatchDay> = emptyList(),
)

/**
 * One day of the week window (today first, seven days): its items in the day panel's
 * order — events (by time), then reminders (undated-first, then date, then time), then
 * notes. The watch's Today / Reminders / Events / All views and the complication draw
 * from these.
 */
@Serializable
data class WatchDay(
    var id: String,       // "2026-08-03"
    var name: String,     // "Today · Aug 3", "Tue · Aug 4"
    var items: List<WatchItem>,
)

@Serializable
data class WatchSection(
    var name: String,
    var items: List<WatchItem>,
) {
    val id: String get() = name
}

@Serializable
data class WatchItem(
    var id: String,
    var text: String,
    var due: String,        // "today", "2pm", "Aug 3", or ""
    var overdue: Boolean,
    var kind: String = "reminder",   // "reminder" | "event" | "note" — defaulted, so an old payload decodes
)

/** "Today · Aug 3" for today, else "Tue · Aug 4" — the web widget's day headings. */
fun watchDayName(date: LocalDate, today: LocalDate): String {
    val md = date.format(DateTimeFormatter.ofPattern("MMM d", Locale.US))
    if (date == today) return "Today · $md"
    return "${date.format(DateTimeFormatter.ofPattern("EEE", Locale.US))} · $md"
}

object WatchLink {
    /** The single key in the phone→watch payload. */
    const val LIST_KEY = "list"
}
