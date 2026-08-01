import SwiftUI

/// Local settings. There's no account, no site and no token any more — everything is
/// on this device — so this is really just the counts, a link to fix up the watch, and
/// a two-press erase.
struct SettingsView: View {
    @EnvironmentObject private var store: Store
    @Environment(\.dismiss) private var dismiss
    @State private var armed = false

    var body: some View {
        NavigationStack {
            Form {
                Section("On this device") {
                    row("Reminders", store.data.reminders.count)
                    row("Notes", store.data.notes.count)
                    row("Events", store.data.events.count)
                    row("Habits", store.data.habits.count)
                }

                Section {
                    // Two presses, like deleting anywhere else in the suite: the first
                    // arms it red, the second goes through. No alert box.
                    Button(role: .destructive) {
                        if armed { store.erase(); armed = false } else { armed = true }
                    } label: {
                        Text("Erase everything")
                            .foregroundStyle(armed ? Color.white : Color.red)
                            .frame(maxWidth: .infinity, alignment: .leading)
                    }
                    .listRowBackground(armed ? Color.red.opacity(0.7) : nil)
                } footer: {
                    Text("Deletes every reminder, note, event and habit on this device and "
                         + "starts fresh. There is no copy anywhere else.")
                }
            }
            .navigationTitle("Settings")
            .toolbar {
                ToolbarItem(placement: .confirmationAction) { Button("Done") { dismiss() } }
            }
        }
    }

    private func row(_ name: String, _ count: Int) -> some View {
        HStack {
            Text(name)
            Spacer()
            Text("\(count)").foregroundStyle(.secondary)
        }
    }
}
