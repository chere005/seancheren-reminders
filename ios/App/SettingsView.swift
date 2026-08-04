import SwiftUI

/// Local settings. There's no account, no site and no token any more — everything is
/// on this device — so this is really just the counts, a link to fix up the watch, and
/// a two-press erase.
struct SettingsView: View {
    @EnvironmentObject private var store: Store
    @Environment(\.dismiss) private var dismiss
    @State private var armed = false
    @State private var armedSeed = false

    var body: some View {
        NavigationStack {
            Form {
                Section("On this device") {
                    row("Reminders", store.data.reminders.count)
                    row("Notes", store.data.notes.count)
                    row("Events", store.data.events.count)
                    row("Habits", store.data.habits.count)
                }

                // The web settings window's theme picker: one swatch per theme — the
                // page colour with the accent as a dot — the chosen one ringed.
                Section("Theme") {
                    HStack(spacing: 18) {
                        ForEach(Theme.suite) { t in
                            Button { store.setTheme(t.name) } label: {
                                VStack(spacing: 5) {
                                    ZStack {
                                        Circle().fill(t.bg)
                                            .overlay(Circle().strokeBorder(.quaternary, lineWidth: 1))
                                        Circle().fill(t.accent).frame(width: 13, height: 13)
                                    }
                                    .frame(width: 34, height: 34)
                                    .overlay {
                                        if store.data.theme == t.name {
                                            Circle().strokeBorder(t.accent, lineWidth: 2)
                                                .frame(width: 42, height: 42)
                                        }
                                    }
                                    Text(t.label)
                                        .font(.system(size: 9))
                                        .foregroundStyle(store.data.theme == t.name ? .primary : .secondary)
                                        .lineLimit(1)
                                }
                                .frame(maxWidth: .infinity)
                            }
                            .buttonStyle(.borderless)
                        }
                    }
                    .padding(.vertical, 6)
                }

                Section {
                    // Two presses, like the erase below — this replaces everything.
                    Button {
                        if armedSeed { store.loadSample(); armedSeed = false; dismiss() }
                        else { armedSeed = true }
                    } label: {
                        Text(armedSeed ? "Tap again — this replaces everything"
                                       : "Load sample data (Buddy's dinner)")
                            .foregroundStyle(armedSeed ? Color.white : Theme.reminder)
                            .frame(maxWidth: .infinity, alignment: .leading)
                    }
                    .listRowBackground(armedSeed ? Theme.reminder.opacity(0.7) : nil)
                } footer: {
                    Text("Fills the app with a plausible demo set — the dinner-with-friends "
                         + "scenario — dated around today. It replaces what's here and syncs to the watch.")
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
