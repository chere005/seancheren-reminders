import SwiftUI

/// The folder picker in a list's title bar: a colour dot dropping the three-gesture
/// visibility menu — the ticks are what's on screen, like the web. "All" is the master
/// (tick shows the lot, untick hides it); each folder's submenu toggles just it or shows
/// only it. The last row opens the folder manager.
struct FolderMenu: View {
    let kind: ItemKind
    @State private var managing = false
    @EnvironmentObject private var store: Store

    var body: some View {
        Menu {
            Button { store.setFoldersAll(!store.foldersAllShown(kind), kind: kind) } label: {
                Label("All", systemImage: store.foldersAllShown(kind) ? "checkmark.circle.fill" : "circle")
            }
            Divider()
            ForEach(store.data.folderList(kind)) { folder in
                Menu {
                    Button { store.toggleFolder(folder.id, kind: kind) } label: {
                        Label(store.folderShown(folder.id, kind: kind) ? "Showing" : "Hidden",
                              systemImage: store.folderShown(folder.id, kind: kind)
                                  ? "checkmark.circle.fill" : "circle")
                    }
                    Button("Show only this") { store.showOnlyFolder(folder.id, kind: kind) }
                } label: {
                    Text(folder.name)
                }
            }
            Divider()
            Button("Folders…", systemImage: "folder") { managing = true }
        } label: {
            // One folder on show wears its colour; several (or none) show the all-colours dot.
            PickerDot(color: store.shownFolders(kind).count == 1
                      ? Theme.color(store.shownFolders(kind)[0].color, Theme.tier(kind)) : nil)
        }
        .sheet(isPresented: $managing) { FolderManager(kind: kind) }
    }
}

/// Add, recolour, delete, and choose where new items land. Same shape as the
/// calendar manager: a title, an add row with a green +, rows with an ×, then Done.
struct FolderManager: View {
    let kind: ItemKind
    @EnvironmentObject private var store: Store
    @Environment(\.dismiss) private var dismiss
    @State private var name = ""
    @State private var arming: UUID?

    var body: some View {
        NavigationStack {
            List {
                Section {
                    HStack {
                        TextField("New folder", text: $name)
                            .onSubmit(add)
                        Button("Add", systemImage: "plus", action: add)
                            .labelStyle(.iconOnly)
                            .disabled(name.trimmingCharacters(in: .whitespaces).isEmpty)
                    }
                }
                Section {
                    ForEach(store.data.folderList(kind)) { folder in
                        HStack {
                            ColorDot(selected: folder.color, tier: Theme.tier(folder.kind), size: 18) { recolour(folder, to: $0) }
                            Text(folder.name)
                            Spacer()
                            if store.data.defaultFolder[kind.rawValue] == folder.id {
                                Text("default").font(.caption2).foregroundStyle(.secondary)
                            }
                            // Two presses: the first arms it red, the second goes through.
                            Button {
                                if arming == folder.id { store.deleteFolder(folder); arming = nil }
                                else { arming = folder.id }
                            } label: {
                                Image(systemName: "xmark")
                                    .foregroundStyle(arming == folder.id ? Color.white : Color.secondary)
                                    .padding(4)
                                    .background(arming == folder.id ? Color.red : .clear, in: Circle())
                            }
                            .buttonStyle(.borderless)
                            .disabled(store.data.folderList(kind).count < 2)
                        }
                        .contentShape(Rectangle())
                        .onTapGesture { setDefault(folder) }
                    }
                    .onMove { store.moveFolders(kind, from: $0, to: $1) }
                } footer: {
                    Text("Tap a folder to make it where new items land, or drag to reorder in "
                         + "Edit. Deleting one moves its items to the first folder rather than "
                         + "throwing them away.")
                }
            }
            .navigationTitle("Folders")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .topBarLeading) { EditButton() }
                ToolbarItem(placement: .confirmationAction) { Button("Done") { dismiss() } }
            }
        }
    }

    private func add() {
        store.addFolder(name, kind: kind)
        name = ""
    }

    private func recolour(_ folder: Folder, to index: Int) {
        guard let i = store.data.folders.firstIndex(where: { $0.id == folder.id }) else { return }
        store.data.folders[i].color = index
        store.touch()
    }

    private func setDefault(_ folder: Folder) {
        store.data.defaultFolder[kind.rawValue] = folder.id
        store.touch()
    }
}

/// The palette as tappable colour swatches. This exists because an SF Symbol inside a
/// `Menu` is drawn in the menu's single tint — ten `circle.fill`s all came out the same
/// colour, so the picker was unusable. Real `Circle` fills in a grid show the true colours.
struct ColorSwatchGrid: View {
    let selected: Int
    let tier: Theme.Tier
    let choose: (Int) -> Void

    var body: some View {
        LazyVGrid(columns: Array(repeating: GridItem(.fixed(34), spacing: 12), count: 3),
                  spacing: 12) {
            ForEach(Theme.palette(tier).indices, id: \.self) { i in
                Button { choose(i) } label: {
                    Circle()
                        .fill(Theme.color(i, tier))
                        .frame(width: 30, height: 30)
                        .overlay {
                            if i == selected {
                                Image(systemName: "checkmark")
                                    .font(.caption.bold())
                                    .foregroundStyle(.white)
                                    .shadow(color: .black.opacity(0.5), radius: 1)   // legible on a pale swatch
                            }
                        }
                }
                .buttonStyle(.plain)
            }
        }
        .padding()
    }
}

/// A colour swatch that opens the palette in a popover — the one control for choosing a
/// colour, used by sections, folders and calendars. `size` is the dot's diameter (11 for a
/// section heading, 18 in the manager rows).
struct ColorDot: View {
    let selected: Int
    let tier: Theme.Tier
    var size: CGFloat = 11
    let choose: (Int) -> Void
    @State private var picking = false

    var body: some View {
        Button { picking = true } label: {
            Circle().fill(Theme.color(selected, tier)).frame(width: size, height: size)
        }
        .buttonStyle(.borderless)
        .popover(isPresented: $picking) {
            ColorSwatchGrid(selected: selected, tier: tier) { choose($0); picking = false }
                .presentationCompactAdaptation(.popover)
        }
    }
}

/// A section's colour swatch, left of its name. Shared by Reminders, Notes and Habits.
struct SectionColorDot: View {
    let group: ListGroup
    @EnvironmentObject private var store: Store

    var body: some View {
        ColorDot(selected: group.color, tier: Theme.tier(group.kind)) { store.setGroupColor(group.id, to: $0) }
    }
}

/// A date and an optional time of day, as one row that collapses to "None".
struct WhenPicker: View {
    @Binding var date: Date?
    @Binding var minutes: Int?

    var body: some View {
        Toggle("Date", isOn: Binding(get: { date != nil },
                                     set: { date = $0 ? Date().day : nil }))
        if let bound = Binding($date) {
            DatePicker("On", selection: bound, displayedComponents: .date)
            Toggle("Time", isOn: Binding(get: { minutes != nil },
                                         set: { minutes = $0 ? 9 * 60 : nil }))
            if minutes != nil {
                DatePicker("At", selection: Binding(
                    get: { Calendar.current.date(bySettingHour: (minutes ?? 0) / 60,
                                                 minute: (minutes ?? 0) % 60,
                                                 second: 0, of: Date()) ?? Date() },
                    set: { picked in
                        let parts = Calendar.current.dateComponents([.hour, .minute], from: picked)
                        minutes = (parts.hour ?? 0) * 60 + (parts.minute ?? 0)
                    }), displayedComponents: .hourAndMinute)
            }
        }
    }
}

/// An optional day for a note. A dated note rides on the calendar under that day; an
/// undated one lives only in its folder. Date-only — a note has no time of day, unlike a
/// reminder or an event, so there's no `minutes` sibling here.
struct DateOnlyPicker: View {
    @Binding var date: Date?

    var body: some View {
        Toggle("Date", isOn: Binding(get: { date != nil },
                                     set: { date = $0 ? Date().day : nil }))
        if let bound = Binding($date) {
            DatePicker("On", selection: bound, displayedComponents: .date)
        }
    }
}

/// How often it comes back. Off means once.
struct RepeatPicker: View {
    @Binding var rule: Recurrence?

    var body: some View {
        Toggle("Repeats", isOn: Binding(get: { rule != nil },
                                        set: { rule = $0 ? Recurrence() : nil }))
        if let bound = Binding($rule) {
            Stepper("Every \(bound.wrappedValue.n)", value: Binding(
                get: { bound.wrappedValue.n },
                set: { bound.wrappedValue.n = max(1, $0) }), in: 1...30)
            Picker("Unit", selection: Binding(get: { bound.wrappedValue.unit },
                                              set: { bound.wrappedValue.unit = $0 })) {
                ForEach(Recurrence.Unit.allCases) { unit in
                    Text(unit.rawValue + "s").tag(unit)
                }
            }
        }
    }
}
