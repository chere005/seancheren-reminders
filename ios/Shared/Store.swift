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
        let usingDefaultFile = (file == nil)
        if let loaded = Store.read(self.file) {
            data = loaded
        } else if usingDefaultFile {
            // First run of the real app (no suite.json yet): open on buddy's dinner sample
            // rather than an empty suite, so there's something to look at straight away.
            // Erase (in Settings) takes it back to empty. A store over an explicit file (the
            // tests) starts empty instead.
            data = Store.sampleData()
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

    /// "Buddy's data" — the dinner-with-friends scenario the website's seed-buddy builds —
    /// so the app (and the watch, which the same push feeds) have a plausible set to look
    /// at without typing it in. Dated relative to today so it never goes stale. Used on the
    /// first run and by the Settings "Load sample data" button.
    static func sampleData() -> AppData {
        let cal = Calendar.current
        let today = Date().day
        func days(_ n: Int) -> Date { cal.date(byAdding: .day, value: n, to: today)?.day ?? today }
        // The next Saturday (which=1) and the one after (which=2) — the two dinners.
        func saturday(_ which: Int) -> Date {
            var d = cal.date(byAdding: .day, value: 1, to: today) ?? today
            while cal.component(.weekday, from: d) != 7 { d = cal.date(byAdding: .day, value: 1, to: d) ?? d }
            return (cal.date(byAdding: .day, value: 7 * (which - 1), to: d) ?? d).day
        }

        var d = AppData.starter                       // one General folder each, one calendar

        // Reminders: a Cooking folder with Groceries + Dinner-prep sections, plus riders.
        let cooking   = Folder(name: "Cooking", kind: .reminder, color: 4)
        d.folders.append(cooking)
        let groceries = ListGroup(name: "Groceries", kind: .reminder, order: 1, color: 1)
        let prep      = ListGroup(name: "Dinner prep", kind: .reminder, order: 2, color: 4)
        d.groups += [groceries, prep]
        let general = d.folderList(.reminder).first!.id
        var ord = 0
        func rem(_ text: String, _ folder: UUID, _ group: GroupRef, due: Date? = nil) {
            ord += 1
            d.reminders.append(Reminder(text: text, due: due, folder: folder, group: group, order: ord))
        }
        for g in ["Milk", "Eggs", "Flour", "Olive oil", "Parmesan", "Fresh basil"] {
            rem(g, cooking.id, .group(groceries.id))
        }
        rem("Marinate the chicken", cooking.id, .group(prep.id), due: saturday(1))
        rem("Chop the vegetables",  cooking.id, .group(prep.id), due: saturday(1))
        rem("Set the table",        cooking.id, .group(prep.id), due: saturday(1))
        rem("Pick up the wine", general, .calendar)   // undated Calendar rider — rides on today
        rem("Text everyone the time", general, .inbox, due: days(1))

        // Notes: a Recipes folder with the recipe.
        let recipes = Folder(name: "Recipes", kind: .note, color: 2)
        d.folders.append(recipes)
        d.notes.append(Note(title: "Chicken parmesan",
                            body: "Serves 4.\n\n- Pound the chicken thin, salt both sides.\n"
                                + "- Dredge: flour, then egg, then breadcrumbs + parmesan.\n"
                                + "- Fry 3 min a side; top with sauce and mozzarella.\n"
                                + "- Bake at 425°F until bubbling, ~15 min.\n\nBuddy's recipe.",
                            folder: recipes.id, order: 1))

        // Calendar: the two dinners, next two Saturdays, 7pm.
        let calId = d.calendars.first!.id
        d.events.append(Event(text: "Dinner with friends", date: saturday(1), minutes: 19 * 60, cal: calId))
        d.events.append(Event(text: "Dinner, round two", date: saturday(2), minutes: 19 * 60, cal: calId))

        // Habits: a few, with the last week or so already ticked in.
        let health = ListGroup(name: "Health", kind: .habit, order: 1, color: 3)
        d.groups.append(health)
        func habit(_ name: String, group: UUID?, ticked offsets: [Int], order: Int) {
            var marks = Set<String>()
            for o in offsets { marks.insert(days(-o).key) }
            d.habits.append(Habit(name: name, group: group, marks: marks, order: order))
        }
        habit("Floss", group: health.id, ticked: [0, 1, 3, 4, 6], order: 1)
        habit("Read 20 min", group: health.id, ticked: [0, 2, 3, 5], order: 2)
        habit("Walk", group: nil, ticked: [1, 2, 4, 5, 6], order: 3)

        return d
    }

    /// Replace everything with buddy's sample data (the Settings button).
    func loadSample() { data = Store.sampleData(); touch() }

    func save() {
        let encoder = JSONEncoder()
        encoder.outputFormatting = [.prettyPrinted, .sortedKeys]
        guard let bytes = try? encoder.encode(data) else { return }
        try? bytes.write(to: file, options: .atomic)
    }

    // MARK: - Folders

    func folderName(_ id: UUID?) -> String { data.folder(id)?.name ?? "All" }

    func addFolder(_ name: String, kind: ItemKind) {
        let clean = cleanName(name)
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

    /// Persist a drag-reorder of a kind's folders (which order by array position, not an
    /// `order` field), splicing the new order back without disturbing the other kind's rows —
    /// the web's `folders_reorder`, which keeps every folder.
    func moveFolders(_ kind: ItemKind, from source: IndexSet, to destination: Int) {
        var list = data.folderList(kind)
        list.reorder(fromOffsets: source, toOffset: destination)
        var next = list.makeIterator()
        data.folders = data.folders.map { $0.kind == kind ? (next.next() ?? $0) : $0 }
        touch()
    }

    // MARK: - Folder visibility (the three-gesture picker)
    //
    // Stored as what's hidden, per kind: the box toggles one, a row shows only one, "All" is
    // the master — the web's folder_vis / folder_vis_only / folder_vis_all.

    /// Whether a folder is currently shown.
    func folderShown(_ id: UUID, kind: ItemKind) -> Bool {
        !(data.hiddenFolders[kind.rawValue] ?? []).contains(id)
    }

    /// The box: toggle just this folder.
    func toggleFolder(_ id: UUID, kind: ItemKind) {
        var hidden = data.hiddenFolders[kind.rawValue] ?? []
        if let i = hidden.firstIndex(of: id) { hidden.remove(at: i) } else { hidden.append(id) }
        data.hiddenFolders[kind.rawValue] = hidden
        touch()
    }

    /// The row: show only this folder, hide the rest.
    func showOnlyFolder(_ id: UUID, kind: ItemKind) {
        data.hiddenFolders[kind.rawValue] = data.folderList(kind).map(\.id).filter { $0 != id }
        touch()
    }

    /// The "All" master: on only when no folder of this kind is hidden.
    func foldersAllShown(_ kind: ItemKind) -> Bool {
        let hidden = Set(data.hiddenFolders[kind.rawValue] ?? [])
        return data.folderList(kind).allSatisfy { !hidden.contains($0.id) }
    }
    func setFoldersAll(_ show: Bool, kind: ItemKind) {
        data.hiddenFolders[kind.rawValue] = show ? [] : data.folderList(kind).map(\.id)
        touch()
    }

    /// The folders on show, in list order — used to filter the list and to decide where a new
    /// item lands when exactly one is showing.
    func shownFolders(_ kind: ItemKind) -> [Folder] {
        let hidden = Set(data.hiddenFolders[kind.rawValue] ?? [])
        return data.folderList(kind).filter { !hidden.contains($0.id) }
    }

    /// Where a new item lands from the list: the one folder on show, else the default — the
    /// web files it in the folder you're focused on, or the default when several show.
    func addTarget(_ kind: ItemKind) -> UUID? {
        let shown = shownFolders(kind)
        if shown.count == 1 { return shown[0].id }
        return data.defaultFolder[kind.rawValue] ?? data.folderList(kind).first?.id
    }

    // MARK: - Groups

    func addGroup(_ name: String, kind: ItemKind) {
        let clean = cleanName(name)
        guard !clean.isEmpty, !isReservedGroupName(clean, kind: kind),
              !data.groupList(kind).contains(where: { $0.name.caseInsensitiveCompare(clean) == .orderedSame })
        else { return }
        let order = (data.groupList(kind).map(\.order).max() ?? 0) + 1
        // Colour by position, like folders, so a new section is distinct straight away.
        data.groups.append(ListGroup(name: clean, kind: kind, order: order,
                                     color: data.groupList(kind).count % 10))
        touch()
    }

    /// Recolour a section from its swatch — the same palette the folder manager uses.
    func setGroupColor(_ id: UUID, to index: Int) {
        guard let i = data.groups.firstIndex(where: { $0.id == id }) else { return }
        data.groups[i].color = index
        touch()
    }

    /// Recolour the ungrouped "Habits" bucket, which has no section row of its own.
    func setUngroupedHabitColor(_ index: Int) {
        data.habitUngroupedColor = index
        touch()
    }

    func renameGroup(_ id: UUID, to name: String) {
        let clean = cleanName(name)
        guard !clean.isEmpty, let i = data.groups.firstIndex(where: { $0.id == id }) else { return }
        let kind = data.groups[i].kind
        guard !isReservedGroupName(clean, kind: kind),
              !data.groupList(kind).contains(where: {
                  $0.id != id && $0.name.caseInsensitiveCompare(clean) == .orderedSame
              }) else { return }
        data.groups[i].name = clean
        touch()
    }

    /// Persist a drag-reorder of a kind's sections — the web reorders sections in its
    /// manager; here the same result renumbers each section's `order`, moving no habits or
    /// rows with them.
    func moveGroups(_ kind: ItemKind, from source: IndexSet, to destination: Int) {
        var list = data.groupList(kind)
        list.reorder(fromOffsets: source, toOffset: destination)
        for (i, g) in list.enumerated() {
            if let idx = data.groups.firstIndex(where: { $0.id == g.id }) { data.groups[idx].order = i }
        }
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
        new.text = String(new.text.prefix(Limits.reminderText))
        new.order = (data.reminders.map(\.order).max() ?? 0) + 1
        data.reminders.append(new)
        touch()
    }

    func update(_ reminder: Reminder) {
        guard let i = data.reminders.firstIndex(where: { $0.id == reminder.id }) else { return }
        var r = reminder
        r.text = String(r.text.prefix(Limits.reminderText))
        data.reminders[i] = r
        touch()
    }

    func delete(_ reminder: Reminder) {
        data.reminders.removeAll { $0.id == reminder.id }
        touch()
    }

    /// Remove the ticked reminders — the web's "clear completed". Scoped to the folder in
    /// view, or every folder on "All". Open rows are untouched.
    func clearDone(folder: UUID?) {
        data.reminders.removeAll { $0.done && (folder == nil || $0.folder == folder) }
        touch()
    }

    /// Take a reminder off the calendar without deleting it — the web's "delete from the
    /// calendar" for a dated reminder: the date comes off, the row stays in its own list.
    func unschedule(_ reminder: Reminder) {
        guard let i = data.reminders.firstIndex(where: { $0.id == reminder.id }) else { return }
        data.reminders[i].due = nil
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

    /// A folder+group as an outline: top-level rows sorted undated-first by date (done
    /// sinking), each carrying the indent-1 subtasks that follow it in stored order. A
    /// subtask never sorts on its own — it travels with its parent, so it can't float up
    /// under whatever row happens to sit above it. With no subtasks this is exactly the
    /// flat sort, one row per block, so nothing changes for a plain list.
    func reminders(folder: UUID?, group: GroupRef) -> [Reminder] {
        let rows = data.reminders
            .filter { $0.group == group && (folder == nil || $0.folder == folder) }
            .sorted { $0.order < $1.order }
        var blocks: [[Reminder]] = []
        for r in rows {
            if r.indent == 0 || blocks.isEmpty { blocks.append([r]) }   // a new top-level block
            else { blocks[blocks.count - 1].append(r) }                 // a subtask joins the last
        }
        let rank = Dictionary(uniqueKeysWithValues:
            sorted(blocks.map { $0[0] }).enumerated().map { ($1.id, $0) })
        return blocks
            .sorted { (rank[$0[0].id] ?? 0) < (rank[$1[0].id] ?? 0) }
            .flatMap { $0 }
    }

    /// The reminders the list shows in a section: one focused folder, or — when `folder` is
    /// nil (the "All" view) — every folder that isn't hidden. The list, Copy-as-Markdown and
    /// the watch all read this, so a hidden folder is hidden everywhere the web hides it.
    func remindersShown(folder: UUID?, group: GroupRef) -> [Reminder] {
        if let folder { return reminders(folder: folder, group: group) }
        let hidden = Set(data.hiddenFolders[ItemKind.reminder.rawValue] ?? [])
        return reminders(folder: nil, group: group).filter { r in
            guard let f = r.folder else { return true }
            return !hidden.contains(f)
        }
    }

    /// Add a blank subtask directly under `parent`: same folder and group, indent 1,
    /// slotted right after it in stored order. Returns it so the view can open it to type;
    /// left empty, the caller deletes it again, exactly like the web.
    @discardableResult
    func addSubtask(under parent: Reminder) -> Reminder {
        let child = Reminder(folder: parent.folder, group: parent.group, indent: 1)
        var rows = data.reminders
            .filter { $0.group == parent.group && $0.folder == parent.folder }
            .sorted { $0.order < $1.order }
        if let pi = rows.firstIndex(where: { $0.id == parent.id }) { rows.insert(child, at: pi + 1) }
        else { rows.append(child) }
        data.reminders.append(child)
        for (i, r) in rows.enumerated() {                               // renumber to match
            if let idx = data.reminders.firstIndex(where: { $0.id == r.id }) { data.reminders[idx].order = i }
        }
        touch()
        return child
    }

    /// Lift a subtask back out to top level (the web's ‹), or push a task in.
    func setIndent(_ reminder: Reminder, to indent: Int) {
        guard let i = data.reminders.firstIndex(where: { $0.id == reminder.id }) else { return }
        data.reminders[i].indent = Swift.max(0, Swift.min(1, indent))
        touch()
    }

    /// Persist a drag-reorder within a group: `ordered` is the group as arranged now.
    /// Display still sorts undated-first by date, so `order` only breaks ties — matching
    /// the web, where dragging within a section is largely cosmetic.
    func moveReminders(_ ordered: [Reminder], from: IndexSet, to: Int) {
        var rows = ordered
        rows.reorder(fromOffsets: from, toOffset: to)
        for (i, r) in rows.enumerated() {
            if let idx = data.reminders.firstIndex(where: { $0.id == r.id }) { data.reminders[idx].order = i }
        }
        touch()
    }

    // MARK: - Notes

    func add(_ note: Note) {
        var new = note
        new.title = String(new.title.prefix(Limits.noteTitle))
        new.order = (data.notes.map(\.order).max() ?? 0) + 1   // new notes sort to the end until dragged
        data.notes.append(new)
        touch()
    }
    func update(_ note: Note) {
        guard let i = data.notes.firstIndex(where: { $0.id == note.id }) else { return }
        data.notes[i] = note
        data.notes[i].title = String(note.title.prefix(Limits.noteTitle))
        data.notes[i].updated = Date()
        touch()
    }
    func delete(_ note: Note)  { data.notes.removeAll { $0.id == note.id }; touch() }

    /// Take a note off the calendar without deleting it — its `date` comes off, the note stays.
    func unschedule(_ note: Note) {
        guard let i = data.notes.firstIndex(where: { $0.id == note.id }) else { return }
        data.notes[i].date = nil
        touch()
    }

    /// Notes in a folder+group, in drag order (stored `order`), like the web suite.
    func notes(folder: UUID?, group: UUID?) -> [Note] {
        data.notes
            .filter { $0.group == group && (folder == nil || $0.folder == folder) }
            .sorted { $0.order < $1.order }
    }

    /// The notes the list shows in a group: one focused folder, or every non-hidden folder on
    /// "All" — the notes twin of `remindersShown`.
    func notesShown(folder: UUID?, group: UUID?) -> [Note] {
        if let folder { return notes(folder: folder, group: group) }
        let hidden = Set(data.hiddenFolders[ItemKind.note.rawValue] ?? [])
        return notes(folder: nil, group: group).filter { n in
            guard let f = n.folder else { return true }
            return !hidden.contains(f)
        }
    }

    /// Persist a drag-reorder: `ordered` is the group as the user just arranged it.
    func moveNotes(_ ordered: [Note], from: IndexSet, to: Int) {
        var rows = ordered
        rows.reorder(fromOffsets: from, toOffset: to)
        for (i, n) in rows.enumerated() {
            if let idx = data.notes.firstIndex(where: { $0.id == n.id }) { data.notes[idx].order = i }
        }
        touch()
    }

    // MARK: - Events

    func add(_ event: Event)    { data.events.append(event); touch() }
    func update(_ event: Event) {
        guard let i = data.events.firstIndex(where: { $0.id == event.id }) else { return }
        data.events[i] = event
        touch()
    }
    func delete(_ event: Event) { data.events.removeAll { $0.id == event.id }; touch() }

    /// Real calendars (not sets) and calendar sets, from the one stored list.
    var calendarsOnly: [Cal] { data.calendars.filter { !$0.isSet } }
    var calSets: [Cal]       { data.calendars.filter { $0.isSet } }

    func addCalendar(_ name: String) {
        let clean = name.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !clean.isEmpty else { return }
        let made = Cal(name: clean, color: calendarsOnly.count % 10)
        data.calendars.append(made)
        if data.defaultCal == nil { data.defaultCal = made.id }
        touch()
    }

    /// A set is a saved view over several calendars' ids.
    func addSet(_ name: String, members: [UUID]) {
        let clean = name.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !clean.isEmpty, !members.isEmpty else { return }
        data.calendars.append(Cal(name: clean, color: data.calendars.count % 10, members: members))
        touch()
    }

    /// A deleted calendar takes its events with it — unlike a folder, there's nowhere
    /// sensible for them to land, and an event with no calendar has no colour. A deleted
    /// set is just dropped, and any calendar removed is scrubbed from the sets too.
    func deleteCalendar(_ cal: Cal) {
        if cal.isSet {
            data.calendars.removeAll { $0.id == cal.id }
            touch(); return
        }
        guard calendarsOnly.count > 1 else { return }
        data.events.removeAll { $0.cal == cal.id }
        for i in data.calendars.indices {
            if var m = data.calendars[i].members { m.removeAll { $0 == cal.id }; data.calendars[i].members = m }
        }
        data.calendars.removeAll { $0.id == cal.id }
        if data.defaultCal == cal.id { data.defaultCal = calendarsOnly.first?.id }
        touch()
    }

    /// Which calendar ids a selection covers: nil (show all), a single calendar, or a
    /// set's members (validated against calendars that still exist).
    func calScope(_ selection: UUID?) -> Set<UUID>? {
        guard let selection, let c = data.cal(selection) else { return nil }
        if let m = c.members { return Set(m).intersection(Set(calendarsOnly.map(\.id))) }
        return [selection]
    }

    // MARK: - Calendar visibility (the three-gesture picker)
    //
    // The calendar's twin of folder visibility, over the web's `hidden_cals`.

    /// Whether a calendar is currently shown.
    func calShown(_ id: UUID) -> Bool { !data.hiddenCals.contains(id) }

    /// The box: toggle just this calendar.
    func toggleCal(_ id: UUID) {
        if let i = data.hiddenCals.firstIndex(of: id) { data.hiddenCals.remove(at: i) }
        else { data.hiddenCals.append(id) }
        touch()
    }

    /// The row: show only this calendar.
    func showOnlyCal(_ id: UUID) { showOnlyCalendars([id]) }

    /// Show only the given calendars, hiding the rest — the row gesture, and how a saved set
    /// (a named group of calendars) applies in the visibility model.
    func showOnlyCalendars(_ ids: [UUID]) {
        let keep = Set(ids)
        data.hiddenCals = calendarsOnly.map(\.id).filter { !keep.contains($0) }
        touch()
    }

    /// The "All" master: on only when no calendar is hidden.
    func calsAllShown() -> Bool {
        let hidden = Set(data.hiddenCals)
        return calendarsOnly.allSatisfy { !hidden.contains($0.id) }
    }
    func setCalsAll(_ show: Bool) {
        data.hiddenCals = show ? [] : calendarsOnly.map(\.id)
        touch()
    }

    /// The visible-calendar filter for the grid and day panel: nil when everything shows (no
    /// filtering), otherwise the ids still on. Stale hidden ids are ignored.
    var shownCalScope: Set<UUID>? {
        let all = Set(calendarsOnly.map(\.id))
        let hidden = Set(data.hiddenCals).intersection(all)
        return hidden.isEmpty ? nil : all.subtracting(hidden)
    }

    // MARK: - Reminder folders on the calendar (the web's rf_mode)
    //
    // Separate from the Reminders picker's visibility: a folder can show in the list but be
    // switched off for the calendar, so its reminders don't clutter the month.

    /// Whether a reminder folder's items reach the calendar (off is the web's rf_mode 'none').
    func calFolderShown(_ id: UUID) -> Bool { !data.calHiddenFolders.contains(id) }
    func toggleCalFolder(_ id: UUID) {
        if let i = data.calHiddenFolders.firstIndex(of: id) { data.calHiddenFolders.remove(at: i) }
        else { data.calHiddenFolders.append(id) }
        touch()
    }

    /// Events on one day, repeats expanded, optionally narrowed to a calendar scope. An
    /// event with no calendar counts as the default one.
    func events(on date: Date, scope: Set<UUID>? = nil) -> [Event] {
        let day = date.day
        return data.events.filter { event in
            if let scope {
                let ec = event.cal ?? data.defaultCal
                guard let ec, scope.contains(ec) else { return false }
            }
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
        let calHidden = Set(data.calHiddenFolders)
        let rows = data.reminders.filter { r in
            guard !r.done else { return false }
            if let f = r.folder, calHidden.contains(f) { return false }   // folder off for the calendar
            if r.ridesAlong { return day == today }
            guard let due = r.due else { return false }
            if due.day == day { return true }
            if due < today && day == today { return true }        // overdue rides on today
            if let rule = r.recurrence {
                return !rule.dates(start: due, from: day, to: day).isEmpty
            }
            return false
        }
        // The calendar day's own order — undated riders first, then by date, then by time of
        // day, stored order breaking the rest. The Reminders list sorts by stored order for
        // ties (see `sorted`); a day adds a time tiebreak, matching the web's day panel.
        return rows.sorted { a, b in
            switch (a.due, b.due) {
            case (nil, nil):       break
            case (nil, _):         return true
            case (_, nil):         return false
            case (let x?, let y?): if x.day != y.day { return x.day < y.day }
            }
            if (a.minutes ?? -1) != (b.minutes ?? -1) { return (a.minutes ?? -1) < (b.minutes ?? -1) }
            return a.order < b.order
        }
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

    /// Persist a drag-reorder: `ordered` is the group as the user just arranged it.
    func moveHabits(_ ordered: [Habit], from: IndexSet, to: Int) {
        var rows = ordered
        rows.reorder(fromOffsets: from, toOffset: to)
        for (i, h) in rows.enumerated() {
            if let idx = data.habits.firstIndex(where: { $0.id == h.id }) { data.habits[idx].order = i }
        }
        touch()
    }

    // MARK: - Habits: the month view and its section filter
    //
    // The month pies are "how much of a day got done", which only means something over the
    // sections you meant — so the filter decides which sections feed them. It's the suite's
    // three-gesture picker: a box toggles one, a row makes it the only one, All is master.

    /// Whether a section counts toward the pies (ungrouped is `nil`).
    func habitSectionShown(_ group: UUID?) -> Bool {
        if let group { return !data.habitHidden.contains(group) }
        return !data.habitHideUngrouped
    }

    /// The box: toggle just this section.
    func toggleHabitSection(_ group: UUID?) {
        if let group {
            if data.habitHidden.contains(group) { data.habitHidden.remove(group) }
            else { data.habitHidden.insert(group) }
        } else {
            data.habitHideUngrouped.toggle()
        }
        touch()
    }

    /// The row: count only this one, everything else off.
    func onlyHabitSection(_ group: UUID?) {
        data.habitHidden = Set(data.groupList(.habit).map(\.id))
        data.habitHideUngrouped = true
        if let group { data.habitHidden.remove(group) } else { data.habitHideUngrouped = false }
        touch()
    }

    /// The "All" master: on when every section counts.
    var habitAllShown: Bool {
        !data.habitHideUngrouped && data.groupList(.habit).allSatisfy { !data.habitHidden.contains($0.id) }
    }
    func setHabitAll(_ show: Bool) {
        if show { data.habitHidden.removeAll(); data.habitHideUngrouped = false }
        else { data.habitHidden = Set(data.groupList(.habit).map(\.id)); data.habitHideUngrouped = true }
        touch()
    }

    /// The habits the pies count: those in shown sections.
    func habitsCounted() -> [Habit] { data.habits.filter { habitSectionShown($0.group) } }

    /// For a month, each day's ticks among the counted habits, broken down by section, so
    /// a day's pie can be filled in the sections' own colours. Key is the "yyyy-MM-dd" day;
    /// value maps a section (nil = ungrouped) to how many of its habits were ticked.
    func habitMonthFill(_ month: Date) -> [String: [UUID?: Int]] {
        let prefix = String(month.startOfMonth.key.prefix(7))          // "yyyy-MM"
        var out: [String: [UUID?: Int]] = [:]
        for h in habitsCounted() {
            for key in h.marks where key.hasPrefix(prefix) {
                out[key, default: [:]][h.group, default: 0] += 1
            }
        }
        return out
    }

    // MARK: - Copy as Markdown

    /// The reminders in a folder (all of them, on "All") as Markdown: a heading per group,
    /// each row a checkbox with its date/time/repeat, subtasks indented — the web's
    /// "Copy as Markdown", from a hidden textarea there and the share sheet here.
    func markdown(folder: UUID?, includeDone: Bool = false) -> String {
        let today = Date().day
        var out: [String] = []
        func emit(_ ref: GroupRef, _ title: String) {
            let rows = remindersShown(folder: folder, group: ref).filter { includeDone || !$0.done }
            guard !rows.isEmpty else { return }
            out.append("## \(title)")
            for r in rows {
                let box = r.done ? "[x]" : "[ ]"
                var line = String(repeating: "  ", count: r.indent) + "- \(box) \(r.text)"
                var meta: [String] = []
                if let due = r.due { meta.append(dayLabel(due, today: today)) }
                if let m = r.minutes { meta.append(timeLabel(m)) }
                if let rule = r.recurrence { meta.append(rule.label) }
                if !meta.isEmpty { line += " (" + meta.joined(separator: " · ") + ")" }
                out.append(line)
            }
            out.append("")
        }
        emit(.calendar, "Calendar")
        emit(.inbox, "Reminders")
        for g in data.groupList(.reminder) { emit(.group(g.id), g.name) }
        return out.joined(separator: "\n").trimmingCharacters(in: .whitespacesAndNewlines) + "\n"
    }

    // MARK: - The watch's list

    /// The list the watch draws: the same groups in the same order as the Reminders
    /// screen, open items only, dates already turned into short strings. Built here
    /// rather than on the watch so the two can't grow apart.
    func watchList() -> WatchList {
        let today = Date().day
        func items(_ ref: GroupRef) -> [WatchItem] {
            remindersShown(folder: nil, group: ref)
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
