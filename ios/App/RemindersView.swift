import SwiftUI

struct RemindersView: View {
    @EnvironmentObject private var store: Store

    @State private var folder: UUID?
    @State private var showCompleted = false
    @State private var editing: Reminder?
    @State private var renaming: ListGroup?
    @State private var arming: UUID?
    @State private var restored = false

    // The inline "type it and hit return" row, which belongs to one group at a time.
    @State private var drafting: GroupRef?
    @State private var draft = ""
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
                    FolderMenu(kind: .reminder, selection: $folder)
                }
                ToolbarItem(placement: .topBarTrailing) { EditButton() }
                ToolbarItem(placement: .topBarTrailing) {
                    Menu {
                        Toggle("Completed", systemImage: "checkmark.square", isOn: $showCompleted)
                        Button("New group", systemImage: "plus") { store.addGroup("Group", kind: .reminder) }
                    } label: {
                        Image(systemName: "ellipsis.circle")
                    }
                }
            }
            .sheet(item: $editing) { ReminderDetail(reminder: $0) }
            .alert("Rename group", isPresented: Binding(get: { renaming != nil },
                                                        set: { if !$0 { renaming = nil } })) {
                RenameField(group: renaming) { renaming = nil }
            }
        }
        .onAppear {
            guard !restored else { return }
            folder = store.data.lastFolder[ItemKind.reminder.rawValue]
            restored = true
        }
        .onChange(of: folder) {
            guard restored else { return }
            store.data.lastFolder[ItemKind.reminder.rawValue] = folder
            store.touch()
        }
    }

    // MARK: - One group

    /// A group stays on screen with nothing under it — a group you've just made has no
    /// rows yet, and vanishing would look like it hadn't been created.
    @ViewBuilder
    private func section(_ ref: GroupRef, title: String, model: ListGroup? = nil) -> some View {
        let rows = store.reminders(folder: folder, group: ref)
            .filter { showCompleted || !$0.done }

        Section {
            ForEach(rows) { row($0) }
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
                           folder: store.target(.reminder, viewing: folder),
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

    init(reminder: Reminder) { _draft = State(initialValue: reminder) }

    var body: some View {
        NavigationStack {
            Form {
                TextField("Reminder", text: $draft.text, axis: .vertical)

                Section { WhenPicker(date: $draft.due, minutes: $draft.minutes) }
                Section { RepeatPicker(rule: $draft.recurrence) }

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
                    Button("Save") { store.update(draft); dismiss() }
                }
            }
        }
    }
}

/// "today", "tomorrow", "Aug 3" — short, because it sits under the text.
func dayLabel(_ date: Date, today: Date) -> String {
    let cal = Calendar.current
    if cal.isDate(date, inSameDayAs: today) { return "today" }
    if let tomorrow = cal.date(byAdding: .day, value: 1, to: today),
       cal.isDate(date, inSameDayAs: tomorrow) { return "tomorrow" }
    let f = DateFormatter()
    f.dateFormat = cal.component(.year, from: date) == cal.component(.year, from: today)
        ? "MMM d" : "MMM d, yyyy"
    return f.string(from: date)
}
