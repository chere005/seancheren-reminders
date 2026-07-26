import Foundation

/// Everything the app owns.
///
/// There is no server, no login and no account: one JSON file in Application Support
/// is the whole database. It's a plain `Codable` tree rather than Core Data or
/// SwiftData because it's small, it's readable if you ever go looking, and it can be
/// handed to the watch as-is.

// MARK: - Dates

extension Date {
    /// Midnight local. Every stored date is day-granular; a time of day is a separate
    /// `minutes` field, so moving a date can't quietly move the time with it.
    var day: Date { Calendar.current.startOfDay(for: self) }

    /// Midnight on the first of this month — the anchor the calendar grid is built from.
    var startOfMonth: Date {
        let cal = Calendar.current
        return cal.date(from: cal.dateComponents([.year, .month], from: self)) ?? day
    }

    /// "2026-07-25" — how a habit's ticked days are keyed.
    var key: String { DayKey.formatter.string(from: self) }
}

enum DayKey {
    static let formatter: DateFormatter = {
        let f = DateFormatter()
        f.calendar = Calendar(identifier: .gregorian)
        f.locale = Locale(identifier: "en_US_POSIX")
        f.dateFormat = "yyyy-MM-dd"
        return f
    }()
}

/// "2:30 pm" from minutes-since-midnight.
func timeLabel(_ minutes: Int) -> String {
    let h24 = minutes / 60, m = minutes % 60
    let h = h24 % 12 == 0 ? 12 : h24 % 12
    return m == 0 ? "\(h)\(h24 < 12 ? "am" : "pm")"
                  : String(format: "%d:%02d%@", h, m, h24 < 12 ? "am" : "pm")
}

// MARK: - Repeats

/// How often something comes back. Absent means once.
struct Recurrence: Codable, Hashable {
    var n = 1
    var unit: Unit = .week

    enum Unit: String, Codable, CaseIterable, Identifiable {
        case day, week, month, year
        var id: String { rawValue }
    }

    var label: String { n == 1 ? "every \(unit.rawValue)" : "every \(n) \(unit.rawValue)s" }

    /// One step on. Month and year keep the day of the month and clamp it — the 31st
    /// repeats as the 30th, the 28th — rather than sliding into the next month the way
    /// naive date arithmetic does.
    func step(_ date: Date) -> Date {
        let cal = Calendar.current
        let step = max(1, n)
        switch unit {
        case .day:   return cal.date(byAdding: .day, value: step, to: date) ?? date
        case .week:  return cal.date(byAdding: .day, value: step * 7, to: date) ?? date
        case .month: return clamped(date, months: step)
        case .year:  return clamped(date, months: step * 12)
        }
    }

    private func clamped(_ date: Date, months: Int) -> Date {
        let cal = Calendar.current
        var parts = cal.dateComponents([.year, .month, .day], from: date)
        let wanted = parts.day ?? 1
        parts.day = 1
        guard let first = cal.date(from: parts),
              let moved = cal.date(byAdding: .month, value: months, to: first),
              let span = cal.range(of: .day, in: .month, for: moved)
        else { return date }
        return cal.date(byAdding: .day, value: min(wanted, span.count) - 1, to: moved) ?? date
    }

    /// Every occurrence inside the window being drawn. There's only ever the one stored
    /// row — this expands it for whatever range the caller is showing.
    func dates(start: Date, from: Date, to: Date) -> [Date] {
        guard start <= to else { return [] }
        var out: [Date] = []
        var d = start.day
        var hops = 0
        while d <= to, hops < 400 {
            if d >= from { out.append(d) }
            d = step(d).day
            hops += 1
        }
        return out
    }

    /// Where a repeat lands next once it's been ticked off.
    func next(from start: Date, after: Date) -> Date {
        var d = start.day
        var hops = 0
        while d <= after, hops < 400 { d = step(d).day; hops += 1 }
        return d
    }
}

// MARK: - Entities

enum ItemKind: String, Codable, CaseIterable, Identifiable {
    case reminder, note, habit
    var id: String { rawValue }
}

/// A folder filters one kind of thing. Reminders and notes keep separate sets.
struct Folder: Identifiable, Codable, Hashable {
    var id = UUID()
    var name: String
    var kind: ItemKind
    var color = 0
}

/// A named group inside a list — the website calls these sections.
struct ListGroup: Identifiable, Codable, Hashable {
    var id = UUID()
    var name: String
    var kind: ItemKind
    var order = 0
}

/// Which group a reminder sits in. Two of them aren't rows you can delete: the
/// ungrouped catch-all everything starts in, and Calendar, whose undated items ride
/// along on today, every day, until they're ticked off.
enum GroupRef: Codable, Hashable {
    case inbox
    case calendar
    case group(UUID)

    var groupID: UUID? { if case .group(let id) = self { return id }; return nil }
}

struct Reminder: Identifiable, Codable, Hashable {
    var id = UUID()
    var text = ""
    var due: Date?                    // nil = undated
    var minutes: Int?                 // time of day, if it has one
    var done = false
    var folder: UUID?
    var group: GroupRef = .inbox
    var recurrence: Recurrence?
    var order = 0

    /// Late, and not just "hasn't happened yet today".
    func overdue(today: Date) -> Bool {
        guard let due, !done else { return false }
        return due < today
    }

    /// An undated item in the Calendar group isn't late — it's meant to keep showing.
    var ridesAlong: Bool { due == nil && group == .calendar }
}

struct Note: Identifiable, Codable, Hashable {
    var id = UUID()
    var title = ""
    var body = ""
    var date: Date?
    var folder: UUID?
    var group: UUID?
    var order = 0
    var updated = Date()
}

struct Cal: Identifiable, Codable, Hashable {
    var id = UUID()
    var name: String
    var color = 0
}

struct Event: Identifiable, Codable, Hashable {
    var id = UUID()
    var text = ""
    var date = Date().day
    var minutes: Int?
    var cal: UUID?
    var recurrence: Recurrence?
}

struct Habit: Identifiable, Codable, Hashable {
    var id = UUID()
    var name = ""
    var group: UUID?
    var marks: Set<String> = []       // day keys, so a Set is cheap to test
    var order = 0

    func on(_ date: Date) -> Bool { marks.contains(date.key) }
}

// MARK: - The document

struct AppData: Codable {
    var reminders: [Reminder] = []
    var notes: [Note] = []
    var events: [Event] = []
    var habits: [Habit] = []
    var calendars: [Cal] = []
    var folders: [Folder] = []
    var groups: [ListGroup] = []

    /// Where new things land, and what to reopen on. Keyed by `ItemKind.rawValue`
    /// so one dictionary covers reminders and notes alike.
    var defaultFolder: [String: UUID] = [:]
    var lastFolder: [String: UUID] = [:]
    var defaultCal: UUID?

    /// A first run: one General folder each, one calendar, nothing in them.
    static var starter: AppData {
        var d = AppData()
        let reminderFolder = Folder(name: "General", kind: .reminder, color: 1)
        let noteFolder     = Folder(name: "General", kind: .note, color: 1)
        let calendar       = Cal(name: "Personal", color: 0)
        d.folders    = [reminderFolder, noteFolder]
        d.calendars  = [calendar]
        d.defaultCal = calendar.id
        d.defaultFolder = [ItemKind.reminder.rawValue: reminderFolder.id,
                           ItemKind.note.rawValue: noteFolder.id]
        return d
    }

    // Named apart from the stored arrays above so a call site always reads clearly as
    // one or the other.
    func folderList(_ kind: ItemKind) -> [Folder] { folders.filter { $0.kind == kind } }
    func groupList(_ kind: ItemKind) -> [ListGroup] {
        groups.filter { $0.kind == kind }.sorted { $0.order < $1.order }
    }
    func folder(_ id: UUID?) -> Folder? { folders.first { $0.id == id } }
    func cal(_ id: UUID?) -> Cal? { calendars.first { $0.id == id } }
}
