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
    /// A palette index, shown as a dot left of the section's name, the same swatch the
    /// folder manager uses. Defaults by position so a new section is distinct at once.
    var color = 0

    init(id: UUID = UUID(), name: String, kind: ItemKind, order: Int = 0, color: Int = 0) {
        self.id = id; self.name = name; self.kind = kind; self.order = order; self.color = color
    }

    // Tolerant decode — see Reminder's note. Sections written before colours existed load
    // with colour 0 rather than failing the whole document.
    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id    = try c.decodeIfPresent(UUID.self, forKey: .id) ?? UUID()
        name  = try c.decode(String.self, forKey: .name)
        kind  = try c.decode(ItemKind.self, forKey: .kind)
        order = try c.decodeIfPresent(Int.self, forKey: .order) ?? 0
        color = try c.decodeIfPresent(Int.self, forKey: .color) ?? 0
    }
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
    /// Outline depth. 0 is a top-level reminder; 1 is a subtask sitting under the row
    /// above it. One level only, like the web. A subtask travels with its parent when the
    /// list is sorted, so it never floats up under whatever happens to sit above it.
    var indent = 0

    /// Late, and not just "hasn't happened yet today".
    func overdue(today: Date) -> Bool {
        guard let due, !done else { return false }
        return due < today
    }

    /// An undated item in the Calendar group isn't late — it's meant to keep showing.
    var ridesAlong: Bool { due == nil && group == .calendar }
}

extension Reminder {
    // Tolerant decode: a suite.json written before a field existed is missing that key,
    // and the synthesized decoder would throw on it — which would drop the user back to an
    // empty suite. decodeIfPresent + the property's default keeps old data loading.
    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        self.init()
        id         = try c.decodeIfPresent(UUID.self,       forKey: .id) ?? id
        text       = try c.decodeIfPresent(String.self,     forKey: .text) ?? text
        due        = try c.decodeIfPresent(Date.self,       forKey: .due)
        minutes    = try c.decodeIfPresent(Int.self,        forKey: .minutes)
        done       = try c.decodeIfPresent(Bool.self,       forKey: .done) ?? done
        folder     = try c.decodeIfPresent(UUID.self,       forKey: .folder)
        group      = try c.decodeIfPresent(GroupRef.self,   forKey: .group) ?? group
        recurrence = try c.decodeIfPresent(Recurrence.self, forKey: .recurrence)
        order      = try c.decodeIfPresent(Int.self,        forKey: .order) ?? order
        indent     = try c.decodeIfPresent(Int.self,        forKey: .indent) ?? indent
    }
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
    /// Non-nil marks this row as a *set* — a saved view over several calendars' ids —
    /// rather than a calendar of its own. Kept in the same list, the way the web does.
    var members: [UUID]? = nil
    var isSet: Bool { members != nil }
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
    /// The calendar or set the Calendar screen last had selected (nil = all).
    var lastCal: UUID?

    /// The Habits month pies count every section unless it's in here; the ungrouped run
    /// has no id, so it gets its own flag. Stored as what's *hidden*, so a section added
    /// later counts without anyone touching this.
    var habitHidden: Set<UUID> = []
    var habitHideUngrouped = false
    /// Habits opens on the view you left it on: the tick grid (false) or the month (true).
    var habitsMonth = false

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

extension AppData {
    // Tolerant decode — see Reminder's note. A document written before any of the prefs
    // below existed still loads, with the field at its default, rather than resetting the
    // whole suite to empty because one key was missing.
    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        self.init()
        reminders         = try c.decodeIfPresent([Reminder].self,   forKey: .reminders) ?? []
        notes             = try c.decodeIfPresent([Note].self,       forKey: .notes) ?? []
        events            = try c.decodeIfPresent([Event].self,      forKey: .events) ?? []
        habits            = try c.decodeIfPresent([Habit].self,      forKey: .habits) ?? []
        calendars         = try c.decodeIfPresent([Cal].self,        forKey: .calendars) ?? []
        folders           = try c.decodeIfPresent([Folder].self,     forKey: .folders) ?? []
        groups            = try c.decodeIfPresent([ListGroup].self,  forKey: .groups) ?? []
        defaultFolder     = try c.decodeIfPresent([String: UUID].self, forKey: .defaultFolder) ?? [:]
        lastFolder        = try c.decodeIfPresent([String: UUID].self, forKey: .lastFolder) ?? [:]
        defaultCal        = try c.decodeIfPresent(UUID.self,         forKey: .defaultCal)
        lastCal           = try c.decodeIfPresent(UUID.self,         forKey: .lastCal)
        habitHidden       = try c.decodeIfPresent(Set<UUID>.self,    forKey: .habitHidden) ?? []
        habitHideUngrouped = try c.decodeIfPresent(Bool.self,        forKey: .habitHideUngrouped) ?? false
        habitsMonth       = try c.decodeIfPresent(Bool.self,         forKey: .habitsMonth) ?? false
    }
}

// MARK: - Core helpers
//
// Kept here, in a file every target already compiles, so the logic layer stands on
// Foundation alone — no SwiftUI, no view code — and `swift test` can exercise it.

extension Array {
    /// Reorder in place: the same result as SwiftUI's `move(fromOffsets:toOffset:)`, but
    /// defined on Foundation so the core doesn't depend on SwiftUI (and can be unit-tested).
    /// `destination` is an index into the collection *before* the moved rows are removed,
    /// exactly as the drag callbacks hand it over.
    mutating func reorder(fromOffsets source: IndexSet, toOffset destination: Int) {
        let moving = source.map { self[$0] }
        let target = destination - source.filter { $0 < destination }.count
        for i in source.sorted(by: >) { remove(at: i) }
        insert(contentsOf: moving, at: Swift.max(0, Swift.min(target, count)))
    }
}

/// "today", "tomorrow", "Aug 3" — a short date, because it sits under the row's text.
/// Lives in the core because the watch list and the Calendar summaries build it too, not
/// only the Reminders screen.
func dayLabel(_ date: Date, today: Date) -> String {
    let cal = Calendar.current
    if cal.isDate(date, inSameDayAs: today) { return "today" }
    if let tomorrow = cal.date(byAdding: .day, value: 1, to: today),
       cal.isDate(date, inSameDayAs: tomorrow) { return "tomorrow" }
    if let yesterday = cal.date(byAdding: .day, value: -1, to: today),
       cal.isDate(date, inSameDayAs: yesterday) { return "yesterday" }
    let f = DateFormatter()
    f.dateFormat = cal.component(.year, from: date) == cal.component(.year, from: today)
        ? "MMM d" : "MMM d, yyyy"
    return f.string(from: date)
}
