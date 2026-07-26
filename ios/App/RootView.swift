import SwiftUI

/// Which tab is showing, and the web view behind each one. The views are made once
/// and kept, so switching tabs doesn't throw away the page you were on.
final class ShellModel: ObservableObject {
    @Published var selection: String = Site.apps[0].id
    private var tabs: [String: WebTab] = [:]

    func tab(for app: Site.App) -> WebTab {
        if let existing = tabs[app.id] { return existing }
        let made = WebTab(app: app)
        tabs[app.id] = made
        return made
    }

    /// Send every tab back to its front page — after the site URL changes, or a sign-out.
    func resetAll() {
        tabs.values.forEach { $0.reset() }
    }
}

/// The four apps as native tabs. Each one is the real page, so everything Reminders,
/// Calendar, Notes and Habits can do, this can do — there is no second copy of any of
/// it to keep in step.
struct RootView: View {
    @StateObject private var shell = ShellModel()

    var body: some View {
        TabView(selection: $shell.selection) {
            ForEach(Site.apps) { app in
                WebPane(tab: shell.tab(for: app))
                    .tabItem { Label(app.title, systemImage: app.symbol) }
                    .tag(app.id)
            }
            SettingsView(shell: shell)
                .tabItem { Label("Settings", systemImage: "gearshape") }
                .tag("settings")
        }
        .tint(Color.suiteGreen)
    }
}

extension Color {
    /// #34d399 — the suite's green, so the tab bar matches the pages above it.
    static let suiteGreen = Color(red: 0.204, green: 0.827, blue: 0.600)
}
