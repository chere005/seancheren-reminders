import Foundation

/// Where the suite lives, and the few settings the phone and the watch both read.
///
/// The phone app is a shell around the live site, so what it shows comes from
/// `base` — every feature of Reminders, Calendar, Notes and Habits is the real page,
/// which is why there is nothing here describing them. The watch can't hold a login,
/// so it talks to the JSON endpoint with the token from the calendar widget's setup
/// page instead.
enum Site {
    static let defaultBase = "https://seancheren.com"

    enum Key {
        static let base  = "siteBase"
        static let token = "watchToken"
    }

    static var baseString: String {
        UserDefaults.standard.string(forKey: Key.base) ?? defaultBase
    }

    static var base: URL {
        URL(string: baseString) ?? URL(string: defaultBase)!
    }

    static var token: String {
        UserDefaults.standard.string(forKey: Key.token) ?? ""
    }

    /// One of the four apps, as a tab in the shell.
    struct App: Identifiable, Hashable {
        let id: String          // also the path segment: /reminders/, /calendar/, …
        let title: String
        let symbol: String
    }

    static let apps: [App] = [
        App(id: "reminders", title: "Reminders", symbol: "checklist"),
        App(id: "calendar",  title: "Calendar",  symbol: "calendar"),
        App(id: "notes",     title: "Notes",     symbol: "note.text"),
        App(id: "habits",    title: "Habits",    symbol: "flame"),
    ]

    /// Built by hand rather than with `appendingPathComponent`, which won't reliably
    /// leave the trailing slash on — and `/reminders` without it is a redirect.
    static func url(_ path: String, base: URL = Site.base) -> URL {
        let root = base.absoluteString.hasSuffix("/")
            ? String(base.absoluteString.dropLast())
            : base.absoluteString
        return URL(string: root + path) ?? base
    }

    static func url(for app: App, base: URL = Site.base) -> URL {
        url("/\(app.id)/", base: base)
    }
}
