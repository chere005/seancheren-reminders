import SwiftUI
import WebKit

/// The shell's own settings — which site it points at, and the token the watch reads
/// its reminders with. Everything else you'd expect to find here (password, theme,
/// folders) lives in the pages themselves, behind the ⋮ next to your name.
struct SettingsView: View {
    @ObservedObject var shell: ShellModel
    @EnvironmentObject private var link: PhoneConnectivity

    @AppStorage(Site.Key.base)  private var base  = Site.defaultBase
    @AppStorage(Site.Key.token) private var token = ""

    @State private var armedSignOut = false
    @State private var sent = false

    var body: some View {
        NavigationStack {
            Form {
                Section("Site") {
                    TextField("https://seancheren.com", text: $base)
                        .textInputAutocapitalization(.never)
                        .autocorrectionDisabled()
                        .keyboardType(.URL)
                    Button("Reload apps") { shell.resetAll() }
                }

                Section {
                    HStack {
                        TextField("token", text: $token)
                            .textInputAutocapitalization(.never)
                            .autocorrectionDisabled()
                            .font(.system(.body, design: .monospaced))
                        if UIPasteboard.general.hasStrings {
                            Button("Paste") { token = (UIPasteboard.general.string ?? token).trimmed }
                                .buttonStyle(.borderless)
                        }
                    }
                    Button(sent ? "Sent to Watch" : "Send to Watch") {
                        link.push(token: token, base: base)
                        sent = true
                    }
                    .disabled(token.isEmpty)
                } header: {
                    Text("Watch")
                } footer: {
                    Text("The watch shows your reminders on its own, so it needs a read token: "
                         + "open Calendar → the widget setup page, copy the token out of the feed "
                         + "URL, paste it here and send it over.")
                }

                Section {
                    // Two presses, like deleting anywhere else in the suite: the first
                    // arms it, the second goes through. No alert box.
                    Button(role: .destructive) {
                        if armedSignOut { signOut() } else { armedSignOut = true }
                    } label: {
                        Text("Sign out")
                            .foregroundStyle(armedSignOut ? Color.white : Color.red)
                            .frame(maxWidth: .infinity, alignment: .leading)
                    }
                    .listRowBackground(armedSignOut ? Color.red.opacity(0.7) : nil)
                } footer: {
                    Text("Clears the session for every app. You'll sign in again on the next tab you open.")
                }
            }
            .navigationTitle("Settings")
        }
        .onChange(of: token) { sent = false }   // a different token hasn't been sent yet
    }

    /// Drop the cookies the whole suite shares, then send every tab home so it
    /// lands on the login page instead of a stale one.
    private func signOut() {
        armedSignOut = false
        let store = WKWebsiteDataStore.default()
        store.fetchDataRecords(ofTypes: WKWebsiteDataStore.allWebsiteDataTypes()) { records in
            store.removeData(ofTypes: WKWebsiteDataStore.allWebsiteDataTypes(),
                             for: records) { shell.resetAll() }
        }
    }
}

private extension String {
    var trimmed: String { trimmingCharacters(in: .whitespacesAndNewlines) }
}
