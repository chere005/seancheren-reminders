import SwiftUI

/// The suite as native tabs — the same five as the website's bottom bar: Reminders,
/// Calendar, Add (the middle +), Notes, Habits. There is no Chat, Bookshelf or Tarot here
/// (those are networked, server-side web apps), and no Settings tab — settings live behind
/// the gear in the Reminders menu, the way the site keeps them in the user menu. Every
/// screen reads and writes the one local `Store`; no web view, no network.
struct RootView: View {
    var body: some View {
        TabView {
            RemindersView()
                .tabItem { Label("Reminders", systemImage: "checklist") }
            CalendarView()
                .tabItem { Label("Calendar", systemImage: "calendar") }
            AddView()
                .tabItem { Label("Add", systemImage: "plus.circle.fill") }
            NotesView()
                .tabItem { Label("Notes", systemImage: "note.text") }
            HabitsView()
                .tabItem { Label("Habits", systemImage: "repeat") }
        }
        .tint(Theme.reminder)
    }
}

/// The middle **Add** tab: one line of text, a type, and where it goes — the website's Add
/// app. The text runs through the same slash-only parser, so "Vet 8/3 2pm" lands dated and
/// timed. Each destination defaults to that app's own default and stays put between adds,
/// so a list can be entered in one go.
struct AddView: View {
    @EnvironmentObject private var store: Store

    enum Kind: String, CaseIterable, Identifiable {
        case reminder = "Reminder", event = "Event", note = "Note"
        var id: String { rawValue }
    }

    @State private var text = ""
    @State private var kind: Kind = .reminder
    @State private var reminderFolder: UUID?
    @State private var reminderGroup: GroupRef = .inbox
    @State private var noteFolder: UUID?
    @State private var noteGroup: UUID?
    @State private var calendar: UUID?
    @State private var eventDate = Date().day
    @State private var flashed = false
    @FocusState private var focused: Bool

    var body: some View {
        NavigationStack {
            Form {
                Section {
                    TextField(placeholder, text: $text, axis: .vertical)
                        .focused($focused)
                    Picker("Type", selection: $kind) {
                        ForEach(Kind.allCases) { Text($0.rawValue).tag($0) }
                    }
                    .pickerStyle(.segmented)
                }

                destination

                Section {
                    Button(action: add) {
                        Label(flashed ? "Added" : "Add",
                              systemImage: flashed ? "checkmark.circle.fill" : "plus.circle.fill")
                            .frame(maxWidth: .infinity)
                    }
                    .disabled(text.trimmingCharacters(in: .whitespaces).isEmpty)
                }
            }
            .navigationTitle("Add")
        }
        .onAppear(perform: resetDefaults)
    }

    /// The destination rows follow the type: a reminder or a note picks a folder and a
    /// group; an event picks a calendar and a date. Whose file it lands in is never a guess.
    @ViewBuilder
    private var destination: some View {
        switch kind {
        case .reminder:
            Section {
                folderPicker(.reminder, selection: $reminderFolder)
                Picker("Group", selection: $reminderGroup) {
                    Text("Reminders").tag(GroupRef.inbox)
                    Text("Calendar").tag(GroupRef.calendar)
                    ForEach(store.data.groupList(.reminder)) { g in Text(g.name).tag(GroupRef.group(g.id)) }
                }
            }
        case .note:
            Section {
                folderPicker(.note, selection: $noteFolder)
                Picker("Group", selection: $noteGroup) {
                    Text("Notes").tag(UUID?.none)
                    ForEach(store.data.groupList(.note)) { g in Text(g.name).tag(UUID?.some(g.id)) }
                }
            }
        case .event:
            Section {
                Picker("Calendar", selection: $calendar) {
                    ForEach(store.calendarsOnly) { c in Text(c.name).tag(UUID?.some(c.id)) }
                }
                DatePicker("Date", selection: $eventDate, displayedComponents: .date)
            }
        }
    }

    private func folderPicker(_ kind: ItemKind, selection: Binding<UUID?>) -> some View {
        Picker("Folder", selection: selection) {
            ForEach(store.data.folderList(kind)) { f in Text(f.name).tag(UUID?.some(f.id)) }
        }
    }

    private var placeholder: String {
        switch kind {
        case .reminder: return "Reminder — try \"Vet 8/3 2pm\""
        case .event:    return "Event"
        case .note:     return "Note title"
        }
    }

    private func resetDefaults() {
        reminderFolder = store.target(.reminder, viewing: nil)
        noteFolder = store.target(.note, viewing: nil)
        calendar = store.data.defaultCal ?? store.calendarsOnly.first?.id
    }

    private func add() {
        let raw = text.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !raw.isEmpty else { return }
        switch kind {
        case .reminder:
            let p = parseWhen(raw)
            store.add(Reminder(text: p.text, due: p.date, minutes: p.minutes,
                               folder: reminderFolder, group: reminderGroup))
        case .note:
            store.add(Note(title: raw, folder: noteFolder, group: noteGroup))
        case .event:
            let p = parseWhen(raw)                     // a typed date wins over the picker
            store.add(Event(text: p.text, date: p.date ?? eventDate, minutes: p.minutes, cal: calendar))
        }
        text = ""
        focused = false
        withAnimation { flashed = true }
        DispatchQueue.main.asyncAfter(deadline: .now() + 0.9) { flashed = false }
    }
}
