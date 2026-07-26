import SwiftUI

/// Habits as a grid: one row per habit, one tappable circle for each of the last seven
/// days, filled green on the days it was done. Groups divide the list into sections,
/// the same as the other apps.
struct HabitsView: View {
    @EnvironmentObject private var store: Store

    @State private var editing: Habit?
    @State private var renaming: ListGroup?

    private let cal = Calendar.current
    private var today: Date { Date().day }

    /// The seven days on show, oldest first, ending today.
    private var days: [Date] {
        (0..<7).reversed().compactMap { cal.date(byAdding: .day, value: -$0, to: today)?.day }
    }

    var body: some View {
        NavigationStack {
            VStack(spacing: 0) {
                header
                List {
                    section(nil, title: "Habits")
                    ForEach(store.data.groupList(.habit)) { group in
                        section(group.id, title: group.name, model: group)
                    }
                }
                .listStyle(.insetGrouped)
            }
            .navigationTitle("Habits")
            .toolbar {
                ToolbarItem(placement: .topBarTrailing) {
                    Menu {
                        Button("New habit", systemImage: "plus") { newHabit(group: nil) }
                        Button("New group", systemImage: "plus.rectangle.on.folder") {
                            store.addGroup("Group", kind: .habit)
                        }
                    } label: {
                        Image(systemName: "ellipsis.circle")
                    }
                }
            }
            .sheet(item: $editing) { HabitDetail(habit: $0) }
            .alert("Rename group", isPresented: Binding(get: { renaming != nil },
                                                        set: { if !$0 { renaming = nil } })) {
                RenameGroupField(group: renaming, kind: .habit) { renaming = nil }
            }
        }
    }

    /// The weekday letters and dates, aligned over the circle columns below.
    private var header: some View {
        HStack(spacing: 0) {
            Spacer(minLength: 0)
            ForEach(days, id: \.self) { day in
                VStack(spacing: 1) {
                    Text(weekday(day)).font(.caption2).foregroundStyle(.secondary)
                    Text("\(cal.component(.day, from: day))")
                        .font(.caption2)
                        .foregroundStyle(cal.isDate(day, inSameDayAs: today) ? Theme.reminder : .secondary)
                }
                .frame(width: 30)
            }
        }
        .padding(.horizontal)
        .padding(.trailing, 20)   // clears the list's row inset
        .padding(.vertical, 6)
    }

    @ViewBuilder
    private func section(_ group: UUID?, title: String, model: ListGroup? = nil) -> some View {
        let rows = store.habits(group: group)
        Section {
            ForEach(rows) { row($0) }
        } header: {
            HStack(spacing: 12) {
                Button { newHabit(group: group) } label: { Image(systemName: "plus") }
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

    private func row(_ habit: Habit) -> some View {
        HStack(spacing: 0) {
            Text(habit.name)
                .lineLimit(1)
                .frame(maxWidth: .infinity, alignment: .leading)
                .contentShape(Rectangle())
                .onTapGesture { editing = habit }
            ForEach(days, id: \.self) { day in
                Button { store.toggleHabit(habit, on: day) } label: {
                    Image(systemName: habit.on(day) ? "checkmark.circle.fill" : "circle")
                        .foregroundStyle(habit.on(day) ? Theme.reminder : .secondary)
                }
                .buttonStyle(.borderless)
                .frame(width: 30)
            }
        }
        .swipeActions(edge: .trailing) {
            Button("Delete", systemImage: "trash", role: .destructive) { store.deleteHabit(habit) }
        }
    }

    private func weekday(_ day: Date) -> String {
        let f = DateFormatter(); f.dateFormat = "EEEEE"; return f.string(from: day)
    }

    private func newHabit(group: UUID?) {
        store.addHabit("New habit", group: group)
        if let made = store.habits(group: group).last { editing = made }
    }
}

// MARK: - Editing one habit

struct HabitDetail: View {
    @EnvironmentObject private var store: Store
    @Environment(\.dismiss) private var dismiss
    @State private var draft: Habit
    @State private var armed = false

    init(habit: Habit) { _draft = State(initialValue: habit) }

    var body: some View {
        NavigationStack {
            Form {
                TextField("Habit", text: $draft.name)

                Section {
                    Picker("Group", selection: $draft.group) {
                        Text("Habits").tag(UUID?.none)
                        ForEach(store.data.groupList(.habit)) { group in
                            Text(group.name).tag(UUID?.some(group.id))
                        }
                    }
                }

                Section {
                    Button(role: .destructive) {
                        if armed { store.deleteHabit(draft); dismiss() } else { armed = true }
                    } label: {
                        Text("Delete")
                            .foregroundStyle(armed ? Color.white : Color.red)
                            .frame(maxWidth: .infinity, alignment: .leading)
                    }
                    .listRowBackground(armed ? Color.red.opacity(0.7) : nil)
                }
            }
            .navigationTitle("Habit")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .confirmationAction) {
                    Button("Done") { store.updateHabit(draft); dismiss() }
                }
            }
        }
    }
}
