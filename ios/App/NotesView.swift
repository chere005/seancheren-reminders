import SwiftUI

/// Notes, plain text. A note is a title and a body; the body is an ordinary string
/// edited in a plain `TextEditor`, not rich text — there's nothing to render, so there's
/// nothing to sanitise. Folders filter, groups divide the list into sections.
struct NotesView: View {
    @EnvironmentObject private var store: Store

    @State private var folder: UUID?
    @State private var editing: Note?
    @State private var renaming: ListGroup?
    @State private var restored = false

    var body: some View {
        NavigationStack {
            List {
                section(nil, title: "Notes")
                ForEach(store.data.groupList(.note)) { group in
                    section(group.id, title: group.name, model: group)
                }
            }
            .listStyle(.insetGrouped)
            .navigationTitle("Notes")
            .toolbar {
                ToolbarItem(placement: .topBarLeading) {
                    FolderMenu(kind: .note, selection: $folder)
                }
                ToolbarItem(placement: .topBarTrailing) { EditButton() }
                ToolbarItem(placement: .topBarTrailing) {
                    Menu {
                        Button("New note", systemImage: "square.and.pencil") { newNote(group: nil) }
                        Button("New group", systemImage: "plus") { store.addGroup("Group", kind: .note) }
                    } label: {
                        Image(systemName: "ellipsis.circle")
                    }
                }
            }
            .sheet(item: $editing) { note in NoteDetail(note: note) }
            .alert("Rename group", isPresented: Binding(get: { renaming != nil },
                                                        set: { if !$0 { renaming = nil } })) {
                RenameGroupField(group: renaming, kind: .note) { renaming = nil }
            }
        }
        .onAppear {
            guard !restored else { return }
            folder = store.data.lastFolder[ItemKind.note.rawValue]
            restored = true
        }
        .onChange(of: folder) {
            guard restored else { return }
            store.data.lastFolder[ItemKind.note.rawValue] = folder
            store.touch()
        }
    }

    @ViewBuilder
    private func section(_ group: UUID?, title: String, model: ListGroup? = nil) -> some View {
        let rows = store.notes(folder: folder, group: group)
        Section {
            ForEach(rows) { row($0) }
                .onMove { store.moveNotes(rows, from: $0, to: $1) }
                .onDelete { idx in idx.forEach { store.delete(rows[$0]) } }
        } header: {
            HStack(spacing: 12) {
                Button { newNote(group: group) } label: { Image(systemName: "plus") }
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

    private func row(_ note: Note) -> some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(note.title.isEmpty ? firstLine(note.body) : note.title)
                .foregroundStyle(note.title.isEmpty && note.body.isEmpty ? .secondary : .primary)
            let preview = note.title.isEmpty ? "" : firstLine(note.body)
            if !preview.isEmpty {
                Text(preview).font(.caption).foregroundStyle(.secondary).lineLimit(1)
            }
        }
        .contentShape(Rectangle())
        .onTapGesture { editing = note }
    }

    private func firstLine(_ body: String) -> String {
        let line = body.split(separator: "\n", maxSplits: 1).first.map(String.init) ?? ""
        return line.isEmpty ? "New note" : line
    }

    private func newNote(group: UUID?) {
        let note = Note(folder: store.target(.note, viewing: folder), group: group)
        store.add(note)
        editing = note
    }
}

// MARK: - Editing one note

struct NoteDetail: View {
    @EnvironmentObject private var store: Store
    @Environment(\.dismiss) private var dismiss
    @State private var draft: Note
    @State private var armed = false

    init(note: Note) { _draft = State(initialValue: note) }

    var body: some View {
        NavigationStack {
            Form {
                TextField("Title", text: $draft.title)
                    .font(.headline)

                Section {
                    TextEditor(text: $draft.body)
                        .frame(minHeight: 220)
                        .font(.body)
                }

                Section {
                    Picker("Folder", selection: $draft.folder) {
                        ForEach(store.data.folderList(.note)) { folder in
                            Text(folder.name).tag(UUID?.some(folder.id))
                        }
                    }
                    Picker("Group", selection: $draft.group) {
                        Text("Notes").tag(UUID?.none)
                        ForEach(store.data.groupList(.note)) { group in
                            Text(group.name).tag(UUID?.some(group.id))
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
            .navigationTitle("Note")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .confirmationAction) {
                    Button("Done") { store.update(draft); dismiss() }
                }
            }
        }
    }
}

/// Shared by Notes and anywhere else that renames a group from an alert.
struct RenameGroupField: View {
    let group: ListGroup?
    let kind: ItemKind
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
