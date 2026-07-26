import SwiftUI

@MainActor
final class RemindersModel: ObservableObject {
    @Published var list: ReminderList?
    @Published var error: String?
    @Published var loading = false

    func load(token: String) async {
        loading = true
        defer { loading = false }
        do {
            list  = try await RemindersAPI.fetch(token: token)
            error = nil
        } catch {
            self.error = error.localizedDescription
        }
    }
}

/// The list as it stands on the phone: the same groups, the same order, completed
/// items left out. Read-only — the token can look but not touch.
struct RemindersView: View {
    @StateObject private var model = RemindersModel()
    @StateObject private var linkUp = WatchLinkReceiver()

    var body: some View {
        NavigationStack {
            Group {
                if let list = model.list, !list.sections.isEmpty {
                    rows(list)
                } else if let error = model.error {
                    empty(error)
                } else if model.list != nil {
                    empty("Nothing on the list.")
                } else {
                    ProgressView()
                }
            }
            .navigationTitle("Reminders")
        }
        .task(id: linkUp.token) { await model.load(token: linkUp.token) }
        .refreshable { await model.load(token: linkUp.token) }
    }

    private func rows(_ list: ReminderList) -> some View {
        List {
            ForEach(list.sections) { section in
                let open = section.items.filter { !$0.done }
                if !open.isEmpty {
                    Section(section.name) {
                        ForEach(open) { item in
                            row(item, today: list.today)
                        }
                    }
                }
            }
        }
    }

    private func row(_ item: Reminder, today: String) -> some View {
        HStack(alignment: .firstTextBaseline, spacing: 6) {
            Circle()
                .fill(item.overdue ? Color.overdue : Color.suiteGreen)
                .frame(width: 6, height: 6)
            Text(item.text)
                .font(.body)
                .foregroundStyle(item.overdue ? Color.overdue : Color.primary)
            Spacer(minLength: 4)
            let due = item.dueLabel(today: today)
            if !due.isEmpty {
                Text(due)
                    .font(.caption2)
                    .foregroundStyle(.secondary)
            }
        }
    }

    private func empty(_ message: String) -> some View {
        VStack(spacing: 8) {
            Text(message)
                .font(.footnote)
                .multilineTextAlignment(.center)
                .foregroundStyle(.secondary)
            Button("Try again") { Task { await model.load(token: linkUp.token) } }
                .font(.caption)
        }
        .padding()
    }
}

extension Color {
    /// #34d399 and #ff7755 — the suite's green and the colour an overdue item wears.
    static let suiteGreen = Color(red: 0.204, green: 0.827, blue: 0.600)
    static let overdue    = Color(red: 1.000, green: 0.467, blue: 0.333)
}
