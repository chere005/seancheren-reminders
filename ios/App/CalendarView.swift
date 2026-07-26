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

    private let cal = Calendar.current
    private var today: Date { Date().day }
    private let weekdays = ["S", "M", "T", "W", "T", "F", "S"]

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
                ToolbarItem(placement: .topBarTrailing) {
                    Menu {
                        Button("Calendars…", systemImage: "calendar") { managing = true }
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
        let events = store.events(on: day)
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
            Text(dayTitle).font(.headline)

            let events = store.events(on: selected)
            let reminders = store.reminders(on: selected, today: today)
            let notes = store.notes(on: selected)

            if events.isEmpty && reminders.isEmpty && notes.isEmpty && !drafting {
                Text("Nothing on this day.").font(.callout).foregroundStyle(.secondary)
            }

            ForEach(events) { eventRow($0) }
            ForEach(reminders) { reminderRow($0) }
            ForEach(notes) { noteRow($0) }

            if drafting {
                HStack(spacing: 8) {
                    Image(systemName: "circle").foregroundStyle(.tertiary)
                    TextField("New reminder", text: $draft)
                        .focused($draftFocused)
                        .submitLabel(.done)
                        .onSubmit(commitReminder)
                }
            }

            HStack(spacing: 10) {
                Button { addEvent() } label: { Label("Event", systemImage: "plus") }
                    .buttonStyle(.bordered).tint(Theme.event)
                Button { drafting = true; draft = ""; draftFocused = true } label: {
                    Label("Reminder", systemImage: "plus")
                }
                .buttonStyle(.bordered).tint(Theme.reminder)
            }
            .font(.callout)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
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
    }

    private func noteRow(_ n: Note) -> some View {
        HStack(spacing: 8) {
            Circle().fill(Theme.note).frame(width: 8, height: 8)
            Text(n.title.isEmpty ? "Note" : n.title)
            Spacer()
        }
        .contentShape(Rectangle())
        .onTapGesture { editingNote = n }
    }

    // MARK: - Actions

    private func addEvent() {
        editingEvent = Event(date: selected, cal: store.data.defaultCal)
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

    private func step(_ months: Int) {
        month = cal.date(byAdding: .month, value: months, to: month)?.startOfMonth ?? month
    }

    private func goToday() {
        month = Date().startOfMonth
        selected = today
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
                        ForEach(store.data.calendars) { c in
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
                    ForEach(store.data.calendars) { c in
                        HStack {
                            Menu {
                                ForEach(Theme.palette.indices, id: \.self) { i in
                                    Button { recolour(c, to: i) } label: {
                                        Label("Colour \(i + 1)", systemImage: "circle.fill")
                                    }
                                }
                            } label: {
                                Circle().fill(Theme.color(c.color)).frame(width: 18, height: 18)
                            }
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
                            .disabled(store.data.calendars.count < 2)
                        }
                        .contentShape(Rectangle())
                        .onTapGesture { store.data.defaultCal = c.id; store.touch() }
                    }
                } footer: {
                    Text("Tap a calendar to make it where new events land. Deleting one takes "
                         + "its events with it.")
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
