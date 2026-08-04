import Foundation

/// What the phone hands the watch.
///
/// Not the whole document — the watch can't edit anything, so it only gets what it
/// draws, with the dates already formatted. Compiled into both targets so the two
/// ends can't drift apart.
///
/// Two shapes ride together: `sections` is the original reminders-by-group list, and
/// `days` is the week window (today first, seven days) the watch's Today / Reminders /
/// Events / All views and the complication draw from. Both ends decode tolerantly, so
/// a phone and a watch on different versions keep working.
struct WatchList: Codable, Hashable {
    var folder = ""
    var sections: [WatchSection] = []
    var days: [WatchDay] = []

    init(folder: String = "", sections: [WatchSection] = [], days: [WatchDay] = []) {
        self.folder = folder
        self.sections = sections
        self.days = days
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        folder   = try c.decodeIfPresent(String.self, forKey: .folder) ?? ""
        sections = try c.decodeIfPresent([WatchSection].self, forKey: .sections) ?? []
        days     = try c.decodeIfPresent([WatchDay].self, forKey: .days) ?? []
    }
}

struct WatchSection: Codable, Hashable, Identifiable {
    var id: String { name }
    var name: String
    var items: [WatchItem]
}

/// One day of the week window: its items in the day panel's order — events (by time),
/// then reminders (undated-first, then date, then time), then notes.
struct WatchDay: Codable, Hashable, Identifiable {
    var id: String        // "2026-08-03"
    var name: String      // "Today · Aug 3", "Tue · Aug 4"
    var items: [WatchItem]
}

struct WatchItem: Codable, Hashable, Identifiable {
    var id: String
    var text: String
    var due: String         // "today", "2pm", "Aug 3", or ""
    var overdue: Bool
    var kind: String        // "reminder" | "event" | "note"

    init(id: String, text: String, due: String, overdue: Bool, kind: String = "reminder") {
        self.id = id
        self.text = text
        self.due = due
        self.overdue = overdue
        self.kind = kind
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id      = try c.decodeIfPresent(String.self, forKey: .id) ?? UUID().uuidString
        text    = try c.decodeIfPresent(String.self, forKey: .text) ?? ""
        due     = try c.decodeIfPresent(String.self, forKey: .due) ?? ""
        overdue = try c.decodeIfPresent(Bool.self, forKey: .overdue) ?? false
        kind    = try c.decodeIfPresent(String.self, forKey: .kind) ?? "reminder"
    }
}

/// "Today · Aug 3" for today, else "Tue · Aug 4" — the web widget's day headings.
func watchDayName(_ date: Date, today: Date) -> String {
    let f = DateFormatter()
    f.locale = Locale(identifier: "en_US_POSIX")
    f.dateFormat = "MMM d"
    let md = f.string(from: date)
    if Calendar.current.isDate(date, inSameDayAs: today) { return "Today · \(md)" }
    f.dateFormat = "EEE"
    return "\(f.string(from: date)) · \(md)"
}

enum WatchLink {
    /// The single key in the WatchConnectivity application context.
    static let listKey = "list"
    /// The app-group defaults suite the watch app and its complication share.
    static let appGroup = "group.com.seancheren.suite"
    /// The key the decoded list is cached under in those defaults.
    static let cacheKey = "watchList"
}
