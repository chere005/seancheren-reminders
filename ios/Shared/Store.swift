import Foundation
import Combine

/// The one place data lives, and the only thing that writes to disk.
///
/// Every view holds this as an `@EnvironmentObject` and mutates `data` directly;
/// saving is debounced off `objectWillChange`, so nothing has to remember to call it.
@MainActor
final class Store: ObservableObject {
    @Published var data: AppData

    private let file: URL
    private var saveTask: Task<Void, Never>?
    private var pushed: (() -> Void)?

    init(file: URL? = nil) {
        self.file = file ?? Store.defaultFile
        if let loaded = Store.read(self.file) {
            data = loaded
        } else {
            data = .starter
        }
    }

    static var defaultFile: URL {
        let dir = FileManager.default.urls(for: .applicationSupportDirectory,
                                           in: .userDomainMask)[0]
        try? FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
        return dir.appendingPathComponent("suite.json")
    }

    private static func read(_ url: URL) -> AppData? {
        guard let bytes = try? Data(contentsOf: url) else { return nil }
        return try? JSONDecoder().decode(AppData.self, from: bytes)
    }

    /// Call after any change. Writes are coalesced — typing a note title shouldn't
    /// rewrite the file on every keystroke — and the watch is told at the same time.
    func touch() {
        objectWillChange.send()
        saveTask?.cancel()
        saveTask = Task { [weak self] in
            try? await Task.sleep(nanoseconds: 400_000_000)
            guard !Task.isCancelled else { return }
            self?.save()
            self?.pushed?()
        }
    }

    /// Somewhere to hang "and tell the watch", without the store knowing what a watch is.
    func onChange(_ block: @escaping () -> Void) { pushed = block }

    /// Back to a first run: everything gone, one General folder each and a calendar.
    func erase() {
        data = .starter
        touch()
    }

    func save() {
        let encoder = JSONEncoder()
        encoder.outputFormatting = [.prettyPrinted, .sortedKeys]
        guard let bytes = try? encoder.encode(data) else { return }
        try? bytes.write(to: file, options: .atomic)
    }

    // MARK: - Folders

    func folderName(_ id: UUID?) -> String { data.folder(id)?.name ?? "All" }

    func addFolder(_ name: String, kind: ItemKind) {
        let clean = name.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !clean.isEmpty,
              !data.folderList(kind).contains(where: { $0.name.caseInsensitiveCompare(clean) == .orderedSame })
        else { return }
        data.folders.append(Folder(name: clean, kind: kind, color: data.folderList(kind).count % 10))
        touch()
    }

    /// Deleting a folder moves its items back to the default rather than destroying them.
    func deleteFolder(_ folder: Folder) {
        guard data.folderList(folder.kind).count > 1 else { return }   // never the last one
        let fallback = data.folderList(folder.kind).first { $0.id != folder.id }?.id
        switch folder.kind {
        case .reminder:
            for i in data.reminders.indices where data.reminders[i].folder == folder.id {
                data.reminders[i].folder = fallback
            }
        case .note:
            for i in data.notes.indices where data.notes[i].folder == folder.id {
                data.notes[i].folder = fallback
            }
        case .habit:
            break
        }
        data.folders.removeAll { $0.id == folder.id }
        if data.defaultFolder[folder.kind.rawValue] == folder.id {
            data.defaultFolder[folder.kind.rawValue] = fallback
        }
        if data.lastFolder[folder.kind.rawValue] == folder.id {
            data.lastFolder[folder.kind.rawValue] = nil
        }
        touch()
    }

    /// Where a new item lands: the folder you're looking at, or the chosen default
    /// when you're on All.
    func target(_ kind: ItemKind, viewing: UUID?) -> UUID? {
        viewing ?? data.defaultFolder[kind.rawValue] ?? data.folderList(kind).first?.id
    }

    // MARK: - Groups

    func addGroup(_ name: String, kind: ItemKind) {
        let clean = name.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !clean.isEmpty,
              !data.groupList(kind).contains(where: { $0.name.caseInsensitiveCompare(clean) == .orderedSame })
        else { return }
        let order = (data.groupList(kind).map(\.order).max() ?? 0) + 1
        data.groups.append(ListGroup(name: clean, kind: kind, order: order))
        touch()
    }

    func renameGroup(_ id: UUID, to name: String) {
        let clean = name.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !clean.isEmpty, let i = data.groups.firstIndex(where: { $0.id == id }) else { return }
        let kind = data.groups[i].kind
        guard !data.groupList(kind).contains(where: {
            $0.id != id && $0.name.caseInsensitiveCompare(clean) == .orderedSame
        }) else { return }
        data.groups[i].name = clean
        touch()
    }

    /// Deleting a group empties it into the ungrouped catch-all — nothing is lost.
    func deleteGroup(_ group: ListGroup) {
        switch group.kind {
        case .reminder:
            for i in data.reminders.indices where data.reminders[i].group == .group(group.id) {
                data.reminders[i].group = .inbox
            }
        case .note:
            for i in data.notes.indices where data.notes[i].group == group.id {
                data.notes[i].group = nil
            }
        case .habit:
            for i in data.habits.indices where data.habits[i].group == group.id {
                data.habits[i].group = nil
            }
        }
        data.groups.removeAll { $0.id == group.id }
        touch()
    }

    // MARK: - Reminders

    /// Ticking a repeating reminder rolls it to its next date instead of finishing it,
    /// so the row always sits on the next date it owes.
    func toggle(_ reminder: Reminder) {
        guard let i = data.reminders.firstIndex(where: { $0.id == reminder.id }) else { return }
        if let rule = data.reminders[i].recurrence, let due = data.reminders[i].due,
           !data.reminders[i].done {
            data.reminders[i].due = rule.next(from: due, after: max(due, Date().day))
        } else {
            data.reminders[i].done.toggle()
        }
        touch()
    }

    func add(_ reminder: Reminder) {
        var new = reminder
        new.order = (data.reminders.map(\.order).max() ?? 0) + 1
        data.reminders.append(new)
        touch()
    }

    func update(_ reminder: Reminder) {
        guard let i = data.reminders.firstIndex(where: { $0.id == reminder.id }) else { return }
        data.reminders[i] = reminder
        touch()
    }

    func delete(_ reminder: Reminder) {
        data.reminders.removeAll { $0.id == reminder.id }
        touch()
    }

    /// Display order inside a group: undated first, then by date, stored order breaking
    /// ties. Completed items sink to the bottom.
    func sorted(_ rows: [Reminder]) -> [Reminder] {
        rows.sorted { a, b in
            if a.done != b.done { return !a.done }
            switch (a.due, b.due) {
            case (nil, nil):            return a.order < b.order
            case (nil, _):              return true
            case (_, nil):              return false
            case (let x?, let y?):      return x == y ? a.order < b.order : x < y
            }
        }
    }

    func reminders(folder: UUID?, group: GroupRef) -> [Reminder] {
        sorted(data.reminders.filter {
            $0.group == group && (folder == nil || $0.folder == folder)
        })
    }

    // MARK: - Notes

    func add(_ note: Note)     { data.notes.append(note); touch() }
    func update(_ note: Note) {
        guard let i = data.notes.firstIndex(where: { $0.id == note.id }) else { return }
        data.notes[i] = note
        data.notes[i].updated = Date()
        touch()
    }
    func delete(_ note: Note)  { data.notes.removeAll { $0.id == note.id }; touch() }

    func notes(folder: UUID?, group: UUID?) -> [Note] {
        data.notes
            .filter { $0.group == group && (folder == nil || $0.folder == folder) }
            .sorted { $0.updated > $1.updated }
    }

    // MARK: - Events

    func add(_ event: Event)    { data.events.append(event); touch() }
    func update(_ event: Event) {
        guard let i = data.events.firstIndex(where: { $0.id == event.id }) else { return }
        data.events[i] = event
        touch()
    }
    func delete(_ event: Event) { data.events.removeAll { $0.id == event.id }; touch() }

    func addCalendar(_ name: String) {
        let clean = name.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !clean.isEmpty else { return }
        let made = Cal(name: clean, color: data.calendars.count % 10)
        data.calendars.append(made)
        if data.defaultCal == nil { data.defaultCal = made.id }
        touch()
    }

    /// A deleted calendar takes its events with it — unlike a folder, there's nowhere
    /// sensible for them to land, and an event with no calendar has no colour.
    func deleteCalendar(_ cal: Cal) {
        guard data.calendars.count > 1 else { return }
        data.events.removeAll { $0.cal == cal.id }
        data.calendars.removeAll { $0.id == cal.id }
        if data.defaultCal == cal.id { data.defaultCal = data.calendars.first?.id }
        touch()
    }

    /// Everything landing on one day: events and dated reminders, repeats expanded.
    func events(on date: Date) -> [Event] {
        let day = date.day
        return data.events.filter { event in
            if let rule = event.recurrence {
                return !rule.dates(start: event.date, from: day, to: day).isEmpty
            }
            return event.date.day == day
        }
        .sorted { ($0.minutes ?? -1) < ($1.minutes ?? -1) }
    }

    /// Reminders showing on a day: its own date, any repeat landing there, an overdue
    /// one rolled onto today, and the Calendar group's undated riders.
    func reminders(on date: Date, today: Date) -> [Reminder] {
        let day = date.day
        return sorted(data.reminders.filter { r in
            guard !r.done else { return false }
            if r.ridesAlong { return day == today }
            guard let due = r.due else { return false }
            if due.day == day { return true }
            if due < today && day == today { return true }        // overdue rides on today
            if let rule = r.recurrence {
                return !rule.dates(start: due, from: day, to: day).isEmpty
            }
            return false
        })
    }

    func notes(on date: Date) -> [Note] {
        data.notes.filter { $0.date?.day == date.day }
    }

    // MARK: - Habits

    func addHabit(_ name: String, group: UUID?) {
        let clean = name.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !clean.isEmpty else { return }
        let order = (data.habits.map(\.order).max() ?? 0) + 1
        data.habits.append(Habit(name: clean, group: group, order: order))
        touch()
    }

    func toggleHabit(_ habit: Habit, on date: Date) {
        guard let i = data.habits.firstIndex(where: { $0.id == habit.id }) else { return }
        let key = date.key
        if data.habits[i].marks.contains(key) { data.habits[i].marks.remove(key) }
        else { data.habits[i].marks.insert(key) }
        touch()
    }

    func updateHabit(_ habit: Habit) {
        guard let i = data.habits.firstIndex(where: { $0.id == habit.id }) else { return }
        data.habits[i] = habit
        touch()
    }

    func deleteHabit(_ habit: Habit) {
        data.habits.removeAll { $0.id == habit.id }
        touch()
    }

    func habits(group: UUID?) -> [Habit] {
        data.habits.filter { $0.group == group }.sorted { $0.order < $1.order }
    }

    // MARK: - The watch's list

    /// The list the watch draws: the same groups in the same order as the Reminders
    /// screen, open items only, dates already turned into short strings. Built here
    /// rather than on the watch so the two can't grow apart.
    func watchList() -> WatchList {
        let today = Date().day
        func items(_ ref: GroupRef) -> [WatchItem] {
            reminders(folder: nil, group: ref)
                .filter { !$0.done }
                .map { r in
                    var bits: [String] = []
                    if let due = r.due { bits.append(dayLabel(due, today: today)) }
                    else if r.ridesAlong { bits.append("today") }
                    if let m = r.minutes { bits.append(timeLabel(m)) }
                    return WatchItem(id: r.id.uuidString,
                                     text: r.text,
                                     due: bits.joined(separator: " "),
                                     overdue: r.overdue(today: today))
                }
        }
        var sections = [WatchSection(name: "Calendar", items: items(.calendar)),
                        WatchSection(name: "Reminders", items: items(.inbox))]
        for group in data.groupList(.reminder) {
            sections.append(WatchSection(name: group.name, items: items(.group(group.id))))
        }
        return WatchList(folder: "", sections: sections.filter { !$0.items.isEmpty })
    }
}
