import SwiftUI

struct RemindersView: View {
    @EnvironmentObject private var store: Store

    @State private var showCompleted = false
    @State private var editing: Reminder?
    @State private var renaming: ListGroup?
    @State private var arming: UUID?
    @State private var confirmClear = false

    // The inline "type it and hit return" row, which belongs to one group at a time.
    @State private var drafting: GroupRef?
    @State private var draft = ""
    @State private var showSettings = false
    @FocusState private var draftFocused: Bool

    private var today: Date { Date().day }

    var body: some View {
        NavigationStack {
            List {
                section(.calendar, title: "Calendar")
                section(.inbox, title: "Reminders")
                ForEach(store.data.groupList(.reminder)) { group in
                    section(.group(group.id), title: group.name, model: group)
                }
            }
            .listStyle(.insetGrouped)
            .navigationTitle("Reminders")
            .toolbar {
                ToolbarItem(placement: .topBarLeading) {
                    FolderMenu(kind: .reminder)
                }
                ToolbarItem(placement: .topBarTrailing) { EditButton() }
                ToolbarItem(placement: .topBarTrailing) {
                    Menu {
                        Toggle("Completed", systemImage: "checkmark.square", isOn: $showCompleted)
                        Button("Clear completed", systemImage: "trash", role: .destructive) {
                            confirmClear = true
                        }
                        Button("New group", systemImage: "plus") { store.addGroup("Group", kind: .reminder) }
                        Divider()
                        // "Copy as Markdown" — the visible list to the clipboard, the same
                        // export the web puts behind its share icon.
                        Button("Copy as Markdown", systemImage: "doc.on.doc") {
                            UIPasteboard.general.string = store.markdown(folder: nil,
                                                                         includeDone: showCompleted)
                        }
                        ShareLink("Share as Markdown",
                                  item: store.markdown(folder: nil, includeDone: showCompleted))
                        Divider()
                        // Settings has no tab of its own — it lives here, the way the site
                        // keeps it in the user menu rather than the app bar.
                        Button("Settings", systemImage: "gearshape") { showSettings = true }
                    } label: {
                        Image(systemName: "ellipsis.circle")
                    }
                }
            }
            .sheet(item: $editing) { ReminderDetail(reminder: $0) }
            .sheet(isPresented: $showSettings) { SettingsView() }
            .confirmationDialog("Delete every completed reminder?",
                                isPresented: $confirmClear, titleVisibility: .visible) {
                Button("Clear completed", role: .destructive) { store.clearDone(folder: nil) }
            }
            .alert("Rename group", isPresented: Binding(get: { renaming != nil },
                                                        set: { if !$0 { renaming = nil } })) {
                RenameField(group: renaming) { renaming = nil }
            }
        }
    }

    // MARK: - One group

    /// A group stays on screen with nothing under it — a group you've just made has no
    /// rows yet, and vanishing would look like it hadn't been created.
    @ViewBuilder
    private func section(_ ref: GroupRef, title: String, model: ListGroup? = nil) -> some View {
        let rows = store.remindersShown(folder: nil, group: ref)
            .filter { showCompleted || !$0.done }

        Section {
            ForEach(rows) { r in
                row(r)
                    // A left swipe adds a subtask under a task, or lifts a subtask back out
                    // — the web's + and ‹, one level only.
                    .swipeActions(edge: .leading, allowsFullSwipe: false) {
                        if r.indent == 0 {
                            Button { editing = store.addSubtask(under: r) } label: {
                                Label("Subtask", systemImage: "arrow.turn.down.right")
                            }.tint(Theme.reminder)
                            // The web row's two-squares button: copy the whole block,
                            // subtasks along, directly under the original.
                            Button { store.duplicate(r) } label: {
                                Label("Duplicate", systemImage: "square.on.square")
                            }.tint(.gray)
                        } else {
                            Button { store.setIndent(r, to: 0) } label: {
                                Label("Promote", systemImage: "arrow.turn.up.left")
                            }.tint(.gray)
                        }
                    }
            }
            .onMove { store.moveReminders(rows, from: $0, to: $1) }
            .onDelete { idx in idx.forEach { store.delete(rows[$0]) } }
            if drafting == ref {
                HStack(spacing: 10) {
                    Image(systemName: "circle").foregroundStyle(.tertiary)
                    TextField("New reminder", text: $draft)
                        .focused($draftFocused)
                        .submitLabel(.done)
                        .onSubmit { commit(into: ref) }
                }
            }
        } header: {
            HStack(spacing: 12) {
                Button {
                    draft = ""
                    drafting = ref
                    draftFocused = true
                } label: {
                    Image(systemName: "plus")
                }
                .buttonStyle(.borderless)

                if let model { SectionColorDot(group: model) }
                Text(title)
                Spacer()

                if let model {
                    Menu {
                        Button("Rename", systemImage: "pencil") { renaming = model }
                        Button("Delete", systemImage: "trash", role: .destructive) {
                            store.deleteGroup(model)
                        }
                    } label: {
                        Image(systemName: "ellipsis")
                    }
                    .buttonStyle(.borderless)
                }
            }
            .textCase(nil)
        }
    }

    private func row(_ reminder: Reminder) -> some View {
        let late = reminder.overdue(today: today)
        return HStack(spacing: 10) {
            Button { store.toggle(reminder) } label: {
                Image(systemName: reminder.done ? "checkmark.circle.fill" : "circle")
                    .font(.title3)
                    .foregroundStyle(reminder.done ? Theme.reminder : (late ? Theme.overdue : .secondary))
            }
            .buttonStyle(.borderless)

            VStack(alignment: .leading, spacing: 1) {
                Text(reminder.text)
                    .strikethrough(reminder.done)
                    .foregroundStyle(reminder.done ? .secondary : .primary)
                if let sub = subtitle(reminder) {
                    Text(sub)
                        .font(.caption)
                        .foregroundStyle(late ? Theme.overdue : .secondary)
                }
            }
            Spacer(minLength: 0)
        }
        .padding(.leading, CGFloat(reminder.indent) * 18)   // a subtask sits in one level
        .contentShape(Rectangle())
        .onTapGesture { editing = reminder }
    }

    private func subtitle(_ reminder: Reminder) -> String? {
        var bits: [String] = []
        if let due = reminder.due { bits.append(dayLabel(due, today: today)) }
        if let minutes = reminder.minutes { bits.append(timeLabel(minutes)) }
        if let rule = reminder.recurrence { bits.append(rule.label) }
        return bits.isEmpty ? nil : bits.joined(separator: " · ")
    }

    /// Whatever was typed becomes a reminder, with any date and time lifted out of it.
    /// The field stays open, so a list can be entered in one go.
    private func commit(into ref: GroupRef) {
        let parsed = parseWhen(draft)
        guard !parsed.text.isEmpty else { drafting = nil; return }
        store.add(Reminder(text: parsed.text,
                           due: parsed.date,
                           minutes: parsed.minutes,
                           folder: store.addTarget(.reminder),
                           group: ref))
        draft = ""
        draftFocused = true
    }
}

/// The rename box. Pulled out because an alert's text field needs its own state.
private struct RenameField: View {
    let group: ListGroup?
    let done: () -> Void
    @EnvironmentObject private var store: Store
    @State private var name = ""

    var body: some View {
        TextField("Name", text: $name)
        Button("Cancel", role: .cancel, action: done)
        Button("Rename") {
            if let group { store.renameGroup(group.id, to: name) }
            done()
        }
        .onAppear { name = group?.name ?? "" }
    }
}

// MARK: - Editing one reminder

struct ReminderDetail: View {
    @EnvironmentObject private var store: Store
    @Environment(\.dismiss) private var dismiss
    @State private var draft: Reminder
    @State private var armed = false

    /// The web edit window's kind row — Event / Reminder / Note. Saving as another kind
    /// converts (one-way into notes); a reminder with subtasks stays behind as their home.
    enum SaveKind: String, CaseIterable, Identifiable {
        case event = "Event", reminder = "Reminder", note = "Note"
        var id: String { rawValue }
    }
    @State private var kind: SaveKind = .reminder
    @State private var eventCal: UUID?
    @State private var noteFolder: UUID?
    @State private var noteGroup: UUID?

    init(reminder: Reminder) { _draft = State(initialValue: reminder) }

    var body: some View {
        NavigationStack {
            Form {
                TextField("Reminder", text: $draft.text, axis: .vertical)

                Section {
                    Picker("Type", selection: $kind) {
                        ForEach(SaveKind.allCases) { Text($0.rawValue).tag($0) }
                    }
                    .pickerStyle(.segmented)
                } footer: {
                    if kind != .reminder && store.hasSubtasks(draft) {
                        Text("Its subtasks can't ride along — the reminder stays behind as their home.")
                    }
                }

                Section { WhenPicker(date: $draft.due, minutes: $draft.minutes) }
                if kind != .note {   // notes don't repeat, so the row folds away
                    Section { RepeatPicker(rule: $draft.recurrence) }
                }

                destination

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
            .navigationTitle("Reminder")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("Cancel") { dismiss() } }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Save", action: save)
                }
            }
            .onAppear {
                eventCal = store.data.defaultCal
                noteFolder = store.target(.note, viewing: nil)
            }
        }
    }

    /// "Goes in" follows the chosen kind, like the web's modal: a reminder keeps its
    /// folder/group pickers, an event picks a calendar, a note a note folder/group.
    @ViewBuilder
    private var destination: some View {
        switch kind {
        case .reminder:
            Section {
                Picker("Folder", selection: $draft.folder) {
                    ForEach(store.data.folderList(.reminder)) { folder in
                        Text(folder.name).tag(UUID?.some(folder.id))
                    }
                }
                Picker("Group", selection: $draft.group) {
                    Text("Reminders").tag(GroupRef.inbox)
                    Text("Calendar").tag(GroupRef.calendar)
                    ForEach(store.data.groupList(.reminder)) { group in
                        Text(group.name).tag(GroupRef.group(group.id))
                    }
                }
            }
        case .event:
            Section {
                Picker("Calendar", selection: $eventCal) {
                    ForEach(store.calendarsOnly) { c in Text(c.name).tag(UUID?.some(c.id)) }
                }
            }
        case .note:
            Section {
                Picker("Folder", selection: $noteFolder) {
                    ForEach(store.data.folderList(.note)) { f in Text(f.name).tag(UUID?.some(f.id)) }
                }
                Picker("Group", selection: $noteGroup) {
                    Text("Notes").tag(UUID?.none)
                    ForEach(store.data.groupList(.note)) { g in Text(g.name).tag(UUID?.some(g.id)) }
                }
            }
        }
    }

    private func save() {
        store.update(draft)
        switch kind {
        case .reminder: break
        case .event:    store.convertToEvent(draft, cal: eventCal)
        case .note:     store.convertToNote(draft, folder: noteFolder, group: noteGroup)
        }
        dismiss()
    }
}
