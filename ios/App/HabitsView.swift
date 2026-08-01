import SwiftUI

/// Habits two ways, the same as the web. **Week** is the tick grid — one row per habit,
/// a tappable circle for each of the seven days, paged whole weeks by the arrows.
/// **Month** draws one pie per day, filled in proportion to how many of that day's habits
/// were ticked and sliced in the sections' own colours. A section filter (the suite's
/// three-gesture picker) decides which sections the pies count, in both views.
struct HabitsView: View {
    @EnvironmentObject private var store: Store

    @State private var editing: Habit?
    @State private var renaming: ListGroup?
    @State private var weekOffset = 0
    @State private var monthAnchor = Date().startOfMonth

    private let cal = Calendar.current
    private var today: Date { Date().day }
    private var month: Bool { store.data.habitsMonth }

    /// Week vs month, remembered per the web's `habits_view`.
    private var monthBinding: Binding<Bool> {
        Binding(get: { store.data.habitsMonth },
                set: { store.data.habitsMonth = $0; store.touch() })
    }

    /// The seven days (Sunday first) of the week the offset lands on.
    private var weekDays: [Date] {
        let base = cal.date(byAdding: .day, value: weekOffset * 7, to: today) ?? today
        let sunday = cal.date(byAdding: .day, value: -(cal.component(.weekday, from: base) - 1), to: base)!.day
        return (0..<7).compactMap { cal.date(byAdding: .day, value: $0, to: sunday)?.day }
    }

    var body: some View {
        NavigationStack {
            VStack(spacing: 0) {
                viewBar
                if month { monthGrid } else { weekHeader }
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
                ToolbarItem(placement: .topBarTrailing) { EditButton() }
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

    // MARK: - The bar over the grid: Week / Month, the filter, and the range arrows

    private var viewBar: some View {
        HStack(spacing: 10) {
            Picker("", selection: monthBinding) {
                Text("Week").tag(false)
                Text("Month").tag(true)
            }
            .pickerStyle(.segmented)
            .frame(width: 150)

            HabitFilterMenu()

            Spacer()

            Button { step(-1) } label: { Image(systemName: "chevron.left") }
                .buttonStyle(.borderless)
            Text(rangeLabel).font(.caption).foregroundStyle(.secondary)
                .frame(minWidth: 96)
            Button { step(1) } label: { Image(systemName: "chevron.right") }
                .buttonStyle(.borderless)
        }
        .padding(.horizontal)
        .padding(.vertical, 6)
    }

    private var rangeLabel: String {
        if month {
            let f = DateFormatter(); f.dateFormat = "MMMM yyyy"; return f.string(from: monthAnchor)
        }
        if weekOffset == 0 { return "This week" }
        let f = DateFormatter(); f.dateFormat = "MMM d"
        return "\(f.string(from: weekDays.first!)) – \(f.string(from: weekDays.last!))"
    }

    private func step(_ dir: Int) {
        if month { monthAnchor = cal.date(byAdding: .month, value: dir, to: monthAnchor)?.startOfMonth ?? monthAnchor }
        else { weekOffset += dir }
    }

    // MARK: - Week: the tick grid

    /// The weekday letters and dates, aligned over the circle columns below.
    private var weekHeader: some View {
        HStack(spacing: 0) {
            Spacer(minLength: 0)
            ForEach(weekDays, id: \.self) { day in
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

    // MARK: - Month: a pie per day

    private var monthGrid: some View {
        let fill = store.habitMonthFill(monthAnchor)
        let total = store.habitsCounted().count
        let sections = countedSections
        return VStack(spacing: 8) {
            LazyVGrid(columns: Array(repeating: GridItem(.flexible(), spacing: 6), count: 7), spacing: 6) {
                ForEach(["S", "M", "T", "W", "T", "F", "S"].indices, id: \.self) { i in
                    Text(["S", "M", "T", "W", "T", "F", "S"][i])
                        .font(.caption2).foregroundStyle(.secondary)
                }
                ForEach(Array(monthCells().enumerated()), id: \.offset) { _, cellDay in
                    if let day = cellDay {
                        let byGroup = fill[day.key] ?? [:]
                        let slices: [PieSlice] = total == 0 ? [] : sections.compactMap { sec in
                            let c = byGroup[sec.id] ?? 0
                            return c > 0 ? PieSlice(color: sec.color, fraction: Double(c) / Double(total)) : nil
                        }
                        VStack(spacing: 2) {
                            MonthPie(slices: slices, ahead: day > today)
                                .frame(height: 26)
                                .overlay {
                                    if cal.isDate(day, inSameDayAs: today) {
                                        Circle().strokeBorder(Theme.reminder, lineWidth: 2)
                                    }
                                }
                            Text("\(cal.component(.day, from: day))")
                                .font(.system(size: 10))
                                .foregroundStyle(cal.isDate(day, inSameDayAs: today) ? Theme.reminder : .secondary)
                        }
                    } else {
                        Color.clear.frame(height: 26)
                    }
                }
            }
            legend(total: total, sections: sections)
        }
        .padding(.horizontal)
        .padding(.bottom, 4)
    }

    private func legend(total: Int, sections: [(id: UUID?, color: Color)]) -> some View {
        Text(total == 0 ? "No sections counted."
             : "Each day filled by how many of \(total) habit\(total == 1 ? "" : "s") were ticked.")
            .font(.caption2)
            .foregroundStyle(.secondary)
            .frame(maxWidth: .infinity)
    }

    /// The counted sections in draw order — ungrouped first, then each group — with the
    /// colour its slices take. The ungrouped bucket wears its own stored colour, so its dot
    /// and its pie wedge match.
    private var countedSections: [(id: UUID?, color: Color)] {
        var out: [(UUID?, Color)] = []
        if store.habitSectionShown(nil) { out.append((nil, Theme.color(store.data.habitUngroupedColor))) }
        for g in store.data.groupList(.habit) where store.habitSectionShown(g.id) {
            out.append((g.id, Theme.color(g.color)))
        }
        return out
    }

    /// The month laid out Sunday-first, with leading blanks (nil) before the 1st.
    private func monthCells() -> [Date?] {
        let first = monthAnchor.startOfMonth
        let lead = cal.component(.weekday, from: first) - 1
        let days = cal.range(of: .day, in: .month, for: first)?.count ?? 30
        var cells = [Date?](repeating: nil, count: lead)
        for d in 0..<days { cells.append(cal.date(byAdding: .day, value: d, to: first)?.day) }
        return cells
    }

    // MARK: - The habit list (both views)

    @ViewBuilder
    private func section(_ group: UUID?, title: String, model: ListGroup? = nil) -> some View {
        let rows = store.habits(group: group)
        Section {
            ForEach(rows) { row($0) }
                .onMove { store.moveHabits(rows, from: $0, to: $1) }
                .onDelete { idx in idx.forEach { store.deleteHabit(rows[$0]) } }
        } header: {
            HStack(spacing: 12) {
                Button { newHabit(group: group) } label: { Image(systemName: "plus") }
                    .buttonStyle(.borderless)
                if let model {
                    SectionColorDot(group: model)
                } else {
                    // The ungrouped bucket has no section row, so its colour lives on AppData.
                    ColorDot(selected: store.data.habitUngroupedColor) { store.setUngroupedHabitColor($0) }
                }
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
            // The tick columns belong to the week view; the month view is the pies above.
            if !month {
                ForEach(weekDays, id: \.self) { day in
                    Button { store.toggleHabit(habit, on: day) } label: {
                        Image(systemName: habit.on(day) ? "checkmark.circle.fill" : "circle")
                            .foregroundStyle(habit.on(day) ? Theme.reminder : .secondary)
                    }
                    .buttonStyle(.borderless)
                    .frame(width: 30)
                }
            }
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

// MARK: - The section filter (three gestures)

/// The month filter: a menu with a box per section (toggles just that one) plus an "All"
/// master and a "count only this" shortcut. Shown in both views, like the web, because it
/// sets a remembered preference that drives the month pies.
struct HabitFilterMenu: View {
    @EnvironmentObject private var store: Store

    var body: some View {
        Menu {
            Button { store.setHabitAll(!store.habitAllShown) } label: {
                Label("All sections", systemImage: store.habitAllShown ? "checkmark.circle.fill" : "circle")
            }
            Divider()
            row(nil, "Ungrouped")
            ForEach(store.data.groupList(.habit)) { g in row(g.id, g.name) }
        } label: {
            Image(systemName: store.habitAllShown ? "line.3.horizontal.decrease.circle"
                                                  : "line.3.horizontal.decrease.circle.fill")
                .foregroundStyle(store.habitAllShown ? Color.secondary : Theme.reminder)
        }
    }

    @ViewBuilder
    private func row(_ id: UUID?, _ name: String) -> some View {
        Menu(name) {
            Button { store.toggleHabitSection(id) } label: {
                Label(store.habitSectionShown(id) ? "Counted" : "Not counted",
                      systemImage: store.habitSectionShown(id) ? "checkmark.circle.fill" : "circle")
            }
            Button("Count only this") { store.onlyHabitSection(id) }
        }
    }
}

// MARK: - A day's pie

struct PieSlice: Hashable { var color: Color; var fraction: Double }

/// One day's pie: coloured wedges for each counted section's ticks, the rest left the
/// empty ring. Future days are dimmed — there's nothing to have done yet.
struct MonthPie: View {
    let slices: [PieSlice]
    let ahead: Bool

    var body: some View {
        Canvas { context, size in
            let rect = CGRect(origin: .zero, size: size)
            let centre = CGPoint(x: size.width / 2, y: size.height / 2)
            let radius = min(size.width, size.height) / 2
            context.fill(Path(ellipseIn: rect), with: .color(Color(hex: 0x1b1726)))
            var start = -90.0
            for slice in slices where slice.fraction > 0 {
                let end = start + min(1, slice.fraction) * 360
                var wedge = Path()
                wedge.move(to: centre)
                wedge.addArc(center: centre, radius: radius,
                             startAngle: .degrees(start), endAngle: .degrees(end), clockwise: false)
                wedge.closeSubpath()
                context.fill(wedge, with: .color(slice.color))
                start = end
            }
        }
        .aspectRatio(1, contentMode: .fit)
        .opacity(ahead ? 0.4 : 1)
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
