import SwiftUI

/// The suite as native tabs. Every screen reads and writes the one local `Store`;
/// there is no web view and no network behind any of this.
struct RootView: View {
    var body: some View {
        TabView {
            RemindersView()
                .tabItem { Label("Reminders", systemImage: "checklist") }
            CalendarView()
                .tabItem { Label("Calendar", systemImage: "calendar") }
            NotesView()
                .tabItem { Label("Notes", systemImage: "note.text") }
            HabitsView()
                .tabItem { Label("Habits", systemImage: "repeat") }
            SettingsView()
                .tabItem { Label("Settings", systemImage: "gearshape") }
        }
        .tint(Theme.reminder)
    }
}
