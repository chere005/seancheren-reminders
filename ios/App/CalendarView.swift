import SwiftUI

/// A month at a time, with a panel underneath for the selected day. Each cell shows a
/// dot per event in its calendar's colour, then at most one reminder dot (the worst
/// state of the day) and one note dot — enough to read "how much is on" without a long
/// list crowding the events out.
struct CalendarView: View {
    @EnvironmentObject private var store: Store

    @State private var month = Date().startOfMonth
    @State private var selected = Date().day
    @State private var editingEvent: Event?
    @State private var editingReminder: Reminder?
    @State private var editingNote: Note?
    @State private var managing = false
    @State private var drafting = false
    @State private var draft = ""
    @FocusState private var draftFocused: Bool

    // Day-panel groups fold independently, remembered per kind like the web's `calFold_*`.
    @AppStorage("calFoldEvents") private var foldEvents = false
    @AppStorage("calFoldReminders") private var foldReminders = false
    @AppStorage("calFoldNotes") private var foldNotes = false

    // Week vs month view (remembered). Which calendars show is the three-gesture visibility.
    @AppStorage("calWeekMode") private var weekMode = false

    private let cal = Calendar.current
    private var today: Date { Date().day }
    private let weekdays = ["S", "M", "T", "W", "T", "F", "S"]

    /// The set of calendar ids in scope (nil = all show), from the visibility picker.
    private var scope: Set<UUID>? { store.shownCalScope }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: 16) {
                    monthHeader
                    grid
                    Divider()
                    dayPanel
                }
                .padding()
            }
            .navigationTitle("Calendar")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .topBarLeading) { scopeMenu }
                ToolbarItem(placement: .topBarTrailing) {
                    Menu {
                        Toggle("Week view", systemImage: "calendar.day.timeline.left", isOn: $weekMode)
                        Button("Calendars…", systemImage: "calendar") { managing = true }
                        // Which reminder folders reach the calendar (the web's rf_mode) — a
                        // folder can show in Reminders yet be switched off here.
                        Menu("Reminder folders") {
                            ForEach(store.data.folderList(.reminder)) { f in
                                Button { store.toggleCalFolder(f.id) } label: {
                                    Label(f.name, systemImage: store.calFolderShown(f.id)
                                          ? "checkmark.circle.fill" : "circle")
                                }
                            }
                        }
                        Button("Today", systemImage: "arrow.uturn.backward") { goToday() }
                    } label: {
                        Image(systemName: "ellipsis.circle")
                    }
                }
            }
            .sheet(item: $editingEvent) { EventDetail(event: $0) }
            .sheet(item: $editingReminder) { ReminderDetail(reminder: $0) }
            .sheet(item: $editingNote) { NoteDetail(note: $0) }
            .sheet(isPresented: $managing) { CalendarManager() }
        }
    }

    /// The calendar filter, the suite's three gestures: tick "All", toggle one calendar or
    /// show only it (its submenu), or apply a saved set (show only its calendars). The ticks
    /// are what's drawn, like the web's `hidden_cals`.
    private var scopeMenu: some View {
        Menu {
            Button { store.setCalsAll(!store.calsAllShown()) } label: {
                Label("All calendars", systemImage: store.calsAllShown() ? "checkmark.circle.fill" : "circle")
            }
            Divider()
            ForEach(store.calendarsOnly) { c in
                Menu {
                    Button { store.toggleCal(c.id) } label: {
                        Label(store.calShown(c.id) ? "Showing" : "Hidden",
                              systemImage: store.calShown(c.id) ? "checkmark.circle.fill" : "circle")
                    }
                    Button("Show only this") { store.showOnlyCal(c.id) }
                } label: {
                    Text(c.name)
                }
            }
            if !store.calSets.isEmpty {
                Divider()
                ForEach(store.calSets) { s in
                    Button(s.name) { store.showOnlyCalendars(s.members ?? []) }
                }
            }
            Divider()
            Button("Calendars…", systemImage: "calendar") { managing = true }
        } label: {
            // One calendar on show wears its colour; several (or none) show the all-colours dot.
            PickerDot(color: shownCals.count == 1 ? Theme.color(shownCals[0].color) : nil)
        }
    }

    private var shownCals: [Cal] { store.calendarsOnly.filter { store.calShown($0.id) } }

    // MARK: - Month header and grid

    private var monthHeader: some View {
        HStack {
            Button { step(-1) } label: { Image(systemName: "chevron.left") }
            Spacer()
            Text(monthTitle).font(.headline)
            Spacer()
            Button { step(1) } label: { Image(systemName: "chevron.right") }
        }
        .buttonStyle(.borderless)
    }

    private var grid: some View {
        VStack(spacing: 6) {
            HStack {
                ForEach(weekdays.indices, id: \.self) { i in
                    Text(weekdays[i]).font(.caption2).foregroundStyle(.secondary)
                        .frame(maxWidth: .infinity)
                }
            }
            if weekMode {
                HStack(spacing: 0) { ForEach(weekDays(), id: \.self) { cell($0) } }
            } else {
                let days = monthGrid()
                ForEach(0..<6, id: \.self) { week in
                    HStack(spacing: 0) {
                        ForEach(0..<7, id: \.self) { wd in
                            cell(days[week * 7 + wd])
                        }
                    }
                }
            }
        }
    }

    private func cell(_ day: Date) -> some View {
        let inMonth = cal.isDate(day, equalTo: month, toGranularity: .month)
        let isToday = cal.isDate(day, inSameDayAs: today)
        let isSel = cal.isDate(day, inSameDayAs: selected)
        return VStack(spacing: 3) {
            Text("\(cal.component(.day, from: day))")
                .font(.callout)
                .foregroundStyle(inMonth ? (isToday ? Theme.event : .primary) : .secondary)
                .fontWeight(isToday ? .bold : .regular)
            dots(day).frame(height: 5)
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, 6)
        .background(isSel ? Theme.event.opacity(0.18) : .clear, in: RoundedRectangle(cornerRadius: 8))
        .contentShape(Rectangle())
        .onTapGesture {
            selected = day
            if !inMonth { month = day.startOfMonth }
        }
    }

    private func dots(_ day: Date) -> some View {
        let events = store.events(on: day, scope: scope)
        let reminders = store.reminders(on: day, today: today)
        let hasNotes = !store.notes(on: day).isEmpty
        return HStack(spacing: 2) {
            ForEach(events.prefix(3)) { e in
                Circle().fill(store.data.cal(e.cal).map { Theme.color($0.color) } ?? Theme.event)
                    .frame(width: 5, height: 5)
            }
            if !reminders.isEmpty {
                Circle().fill(reminders.contains { $0.overdue(today: today) } ? Theme.overdue : Theme.reminder)
                    .frame(width: 5, height: 5)
            }
            if hasNotes {
                Circle().fill(Theme.note).frame(width: 5, height: 5)
            }
        }
    }

    // MARK: - Day panel

    private var dayPanel: some View {
        VStack(alignment: .leading, spacing: 12) {
            // The date, with "+ Add" to its right — one menu, the kind picked inside, each
            // landing on the selected day: an event opens its sheet, a reminder the inline
            // row, a note its sheet, all pre-dated here.
            HStack {
                Text(dayTitle).font(.headline)
                Spacer()
                Menu {
                    Button { addEvent() } label: { Label("Event", systemImage: "calendar") }
                    Button { startReminder() } label: { Label("Reminder", systemImage: "checklist") }
                    Button { addNote() } label: { Label("Note", systemImage: "note.text") }
                } label: {
                    Label("Add", systemImage: "plus")
                }
                .buttonStyle(.bordered)
                .tint(Theme.reminder)
                .font(.callout)
            }

            let events = store.events(on: selected, scope: scope)
            let reminders = store.reminders(on: selected, today: today)
            let notes = store.notes(on: selected)

            if drafting {
                HStack(spacing: 8) {
                    Image(systemName: "circle").foregroundStyle(.tertiary)
                    TextField("New reminder", text: $draft)
                        .focused($draftFocused)
                        .submitLabel(.done)
                        .onSubmit(commitReminder)
                }
            }

            if events.isEmpty && reminders.isEmpty && notes.isEmpty && !drafting {
                Text("Nothing on this day.").font(.callout).foregroundStyle(.secondary)
            }

            // One collapsible group per kind, like the web's day panel (events, then
            // reminders, then notes). An empty kind draws no heading.
            dayGroup("Events", count: events.count, collapsed: $foldEvents) {
                ForEach(events) { eventRow($0) }
            }
            dayGroup("Reminders", count: reminders.count, collapsed: $foldReminders) {
                ForEach(reminders) { reminderRow($0) }
            }
            dayGroup("Notes", count: notes.count, collapsed: $foldNotes) {
                ForEach(notes) { noteRow($0) }
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }

    /// A collapsible kind group — a chevron, its name and a count that fold the rows away,
    /// the web's `.dp-group`. Folded state is remembered per kind; an empty group is hidden.
    @ViewBuilder
    private func dayGroup<Rows: View>(_ title: String, count: Int, collapsed: Binding<Bool>,
                                      @ViewBuilder rows: () -> Rows) -> some View {
        if count > 0 {
            VStack(alignment: .leading, spacing: 8) {
                Button {
                    withAnimation(.easeInOut(duration: 0.15)) { collapsed.wrappedValue.toggle() }
                } label: {
                    HStack(spacing: 6) {
                        Image(systemName: collapsed.wrappedValue ? "chevron.right" : "chevron.down")
                            .font(.caption2).foregroundStyle(.secondary).frame(width: 10)
                        Text(title).font(.subheadline.weight(.semibold))
                        Text("\(count)").font(.caption).foregroundStyle(.secondary)
                        Spacer()
                    }
                    .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
                if !collapsed.wrappedValue { rows() }
            }
        }
    }

    private func eventRow(_ e: Event) -> some View {
        HStack(spacing: 8) {
            Circle().fill(store.data.cal(e.cal).map { Theme.color($0.color) } ?? Theme.event)
                .frame(width: 8, height: 8)
            Text(e.text)
            Spacer()
            if let m = e.minutes { Text(timeLabel(m)).font(.caption).foregroundStyle(.secondary) }
        }
        .contentShape(Rectangle())
        .onTapGesture { editingEvent = e }
    }

    private func reminderRow(_ r: Reminder) -> some View {
        HStack(spacing: 8) {
            Button { store.toggle(r) } label: {
                Image(systemName: r.done ? "checkmark.circle.fill" : "circle")
                    .foregroundStyle(r.done ? Theme.reminder
                                     : (r.overdue(today: today) ? Theme.overdue : .secondary))
            }
            .buttonStyle(.borderless)
            Text(r.text)
            Spacer()
            if let m = r.minutes { Text(timeLabel(m)).font(.caption).foregroundStyle(.secondary) }
        }
        .contentShape(Rectangle())
        .onTapGesture { editingReminder = r }
        .contextMenu {
            // A dated reminder rides the calendar; "remove from this day" takes the date off
            // and keeps the row in its list — the web's delete-from-calendar. Deleting outright
            // stays behind the two-press in the detail.
            if r.due != nil {
                Button("Remove from this day", systemImage: "calendar.badge.minus") { store.unschedule(r) }
            }
            Button("Edit", systemImage: "pencil") { editingReminder = r }
        }
    }

    private func noteRow(_ n: Note) -> some View {
        HStack(spacing: 8) {
            Circle().fill(Theme.note).frame(width: 8, height: 8)
            Text(n.title.isEmpty ? "Note" : n.title)
            Spacer()
        }
        .contentShape(Rectangle())
        .onTapGesture { editingNote = n }
        .contextMenu {
            Button("Remove from this day", systemImage: "calendar.badge.minus") { store.unschedule(n) }
            Button("Edit", systemImage: "pencil") { editingNote = n }
        }
    }

    // MARK: - Actions

    private func addEvent() {
        editingEvent = Event(date: selected, cal: store.data.defaultCal)
    }

    /// Open the inline reminder row (committed on return, dated to the selected day).
    private func startReminder() {
        drafting = true
        draft = ""
        draftFocused = true
    }

    /// Add a note dated to the selected day so it rides this day on the calendar, and open
    /// it to type — the same add-then-edit the Notes tab uses.
    private func addNote() {
        let note = Note(date: selected, folder: store.target(.note, viewing: nil), group: nil)
        store.add(note)
        editingNote = note
    }

    private func commitReminder() {
        let parsed = parseWhen(draft)
        guard !parsed.text.isEmpty else { drafting = false; return }
        store.add(Reminder(text: parsed.text,
                           due: parsed.date ?? selected,
                           minutes: parsed.minutes,
                           folder: store.data.defaultFolder[ItemKind.reminder.rawValue],
                           group: .inbox))
        draft = ""
        draftFocused = true
    }

    /// Arrows step a week at a time in week mode, a month at a time otherwise.
    private func step(_ dir: Int) {
        if weekMode {
            selected = cal.date(byAdding: .day, value: 7 * dir, to: selected)?.day ?? selected
            month = selected.startOfMonth
        } else {
            month = cal.date(byAdding: .month, value: dir, to: month)?.startOfMonth ?? month
        }
    }

    private func goToday() {
        month = Date().startOfMonth
        selected = today
    }

    /// The seven days of the week containing the selected day (Sunday first).
    private func weekDays() -> [Date] {
        let wd = cal.component(.weekday, from: selected)   // 1 = Sunday
        let start = cal.date(byAdding: .day, value: -(wd - 1), to: selected) ?? selected
        return (0..<7).compactMap { cal.date(byAdding: .day, value: $0, to: start)?.day }
    }

    // MARK: - Grid maths

    private var monthTitle: String {
        let f = DateFormatter(); f.dateFormat = "MMMM yyyy"; return f.string(from: month)
    }

    private var dayTitle: String {
        let f = DateFormatter(); f.dateFormat = "EEEE, MMM d"; return f.string(from: selected)
    }

    /// 42 days (6 weeks) starting on the Sunday on or before the first of the month.
    private func monthGrid() -> [Date] {
        let first = month.startOfMonth
        let weekday = cal.component(.weekday, from: first)   // 1 = Sunday
        let start = cal.date(byAdding: .day, value: -(weekday - 1), to: first) ?? first
        return (0..<42).compactMap { cal.date(byAdding: .day, value: $0, to: start)?.day }
    }
}

// MARK: - Editing one event

struct EventDetail: View {
    @EnvironmentObject private var store: Store
    @Environment(\.dismiss) private var dismiss
    @State private var draft: Event
    @State private var hasTime: Bool
    @State private var armed = false
    private let isNew: Bool

    init(event: Event) {
        _draft = State(initialValue: event)
        _hasTime = State(initialValue: event.minutes != nil)
        isNew = event.text.isEmpty
    }

    var body: some View {
        NavigationStack {
            Form {
                TextField("Event", text: $draft.text, axis: .vertical)

                Section {
                    DatePicker("On", selection: $draft.date, displayedComponents: .date)
                    Toggle("Time", isOn: $hasTime)
                    if hasTime {
                        DatePicker("At", selection: Binding(
                            get: {
                                cal.date(bySettingHour: (draft.minutes ?? 540) / 60,
                                         minute: (draft.minutes ?? 540) % 60,
                                         second: 0, of: Date()) ?? Date()
                            },
                            set: {
                                let p = cal.dateComponents([.hour, .minute], from: $0)
                                draft.minutes = (p.hour ?? 0) * 60 + (p.minute ?? 0)
                            }), displayedComponents: .hourAndMinute)
                    }
                }

                Section {
                    Picker("Calendar", selection: $draft.cal) {
                        ForEach(store.calendarsOnly) { c in
                            Text(c.name).tag(UUID?.some(c.id))
                        }
                    }
                }
                Section { RepeatPicker(rule: $draft.recurrence) }

                if !isNew {
                    Section {
                        Button(role: .destructive) {
                            if armed { store.delete(draft); dismiss() } else { armed = true }
                        } label: {
                            Text("Delete")
                                .foregroundStyle(armed ? Color.white : Color.red)
                                .frame(maxWidth: .infinity, alignment: .leading)
                        }
                        .listRowBackground(armed ? Color.red.opacity(0.7) : nil)
                    }
                }
            }
            .navigationTitle(isNew ? "New Event" : "Event")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("Cancel") { dismiss() } }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Save") { save() }.disabled(draft.text.trimmingCharacters(in: .whitespaces).isEmpty)
                }
            }
        }
    }

    private var cal: Calendar { Calendar.current }

    private func save() {
        draft.date = draft.date.day
        if !hasTime { draft.minutes = nil }
        if isNew { store.add(draft) } else { store.update(draft) }
        dismiss()
    }
}

// MARK: - Calendars manager

struct CalendarManager: View {
    @EnvironmentObject private var store: Store
    @Environment(\.dismiss) private var dismiss
    @State private var name = ""
    @State private var arming: UUID?

    var body: some View {
        NavigationStack {
            List {
                Section {
                    HStack {
                        TextField("New calendar", text: $name).onSubmit(add)
                        Button("Add", systemImage: "plus", action: add)
                            .labelStyle(.iconOnly)
                            .disabled(name.trimmingCharacters(in: .whitespaces).isEmpty)
                    }
                }
                Section {
                    ForEach(store.calendarsOnly) { c in
                        HStack {
                            ColorDot(selected: c.color, size: 18) { recolour(c, to: $0) }
                            Text(c.name)
                            Spacer()
                            if store.data.defaultCal == c.id {
                                Text("default").font(.caption2).foregroundStyle(.secondary)
                            }
                            Button {
                                if arming == c.id { store.deleteCalendar(c); arming = nil }
                                else { arming = c.id }
                            } label: {
                                Image(systemName: "xmark")
                                    .foregroundStyle(arming == c.id ? Color.white : Color.secondary)
                                    .padding(4)
                                    .background(arming == c.id ? Color.red : .clear, in: Circle())
                            }
                            .buttonStyle(.borderless)
                            .disabled(store.calendarsOnly.count < 2)
                        }
                        .contentShape(Rectangle())
                        .onTapGesture { store.data.defaultCal = c.id; store.touch() }
                    }
                } footer: {
                    Text("Tap a calendar to make it where new events land. Deleting one takes "
                         + "its events with it.")
                }

                // Sets: saved views over several calendars at once.
                Section("Sets") {
                    ForEach(store.calSets) { s in
                        HStack {
                            Circle().fill(Theme.color(s.color)).frame(width: 18, height: 18)
                            Text(s.name)
                            Spacer()
                            Text("\(s.members?.count ?? 0)").font(.caption2).foregroundStyle(.secondary)
                            Button {
                                if arming == s.id { store.deleteCalendar(s); arming = nil }
                                else { arming = s.id }
                            } label: {
                                Image(systemName: "xmark")
                                    .foregroundStyle(arming == s.id ? Color.white : Color.secondary)
                                    .padding(4)
                                    .background(arming == s.id ? Color.red : .clear, in: Circle())
                            }
                            .buttonStyle(.borderless)
                        }
                    }
                    if store.calendarsOnly.count >= 2 {
                        NavigationLink { SetEditor() } label: {
                            Label("New set", systemImage: "plus")
                        }
                    }
                }
            }
            .navigationTitle("Calendars")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement: .confirmationAction) { Button("Done") { dismiss() } } }
        }
    }

    private func add() { store.addCalendar(name); name = "" }

    private func recolour(_ c: Cal, to index: Int) {
        guard let i = store.data.calendars.firstIndex(where: { $0.id == c.id }) else { return }
        store.data.calendars[i].color = index
        store.touch()
    }
}

// MARK: - Making a calendar set

/// Name a set and tick the calendars it gathers.
struct SetEditor: View {
    @EnvironmentObject private var store: Store
    @Environment(\.dismiss) private var dismiss
    @State private var name = ""
    @State private var members: Set<UUID> = []

    var body: some View {
        Form {
            Section { TextField("Set name", text: $name) }
            Section("Calendars") {
                ForEach(store.calendarsOnly) { c in
                    Button {
                        if members.contains(c.id) { members.remove(c.id) } else { members.insert(c.id) }
                    } label: {
                        HStack {
                            Circle().fill(Theme.color(c.color)).frame(width: 16, height: 16)
                            Text(c.name).foregroundStyle(.primary)
                            Spacer()
                            if members.contains(c.id) {
                                Image(systemName: "checkmark").foregroundStyle(Theme.event)
                            }
                        }
                    }
                }
            }
        }
        .navigationTitle("New Set")
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            ToolbarItem(placement: .confirmationAction) {
                Button("Save") { store.addSet(name, members: Array(members)); dismiss() }
                    .disabled(name.trimmingCharacters(in: .whitespaces).isEmpty || members.isEmpty)
            }
        }
    }
}
