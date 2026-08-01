package com.seancheren.suite.core

import kotlinx.serialization.Serializable

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
)

object WatchLink {
    /** The single key in the phone→watch payload. */
    const val LIST_KEY = "list"
}
