import Foundation

/// What the phone hands the watch.
///
/// Not the whole document — the watch can't edit anything, so it only gets what it
/// draws, with the dates already formatted. Compiled into both targets so the two
/// ends can't drift apart.
struct WatchList: Codable, Hashable {
    var folder = ""
    var sections: [WatchSection] = []
}

struct WatchSection: Codable, Hashable, Identifiable {
    var id: String { name }
    var name: String
    var items: [WatchItem]
}

struct WatchItem: Codable, Hashable, Identifiable {
    var id: String
    var text: String
    var due: String         // "today", "2pm", "Aug 3", or ""
    var overdue: Bool
}

enum WatchLink {
    /// The single key in the WatchConnectivity application context.
    static let listKey = "list"
}
