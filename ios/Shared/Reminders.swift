import Foundation

/// The shape public/api/reminders.php sends back: the list you'd see opening the
/// Reminders app, already grouped and ordered by the server so the watch doesn't
/// have to know the rules.
struct ReminderList: Decodable {
    let user: String
    let today: String
    let folder: String
    let sections: [ReminderSection]
}

struct ReminderSection: Decodable, Identifiable, Hashable {
    var id: String { name }
    let name: String
    let items: [Reminder]
}

struct Reminder: Decodable, Identifiable, Hashable {
    let id: String
    let text: String
    let due: String        // "" when undated
    let time: String       // "" when no time of day
    let done: Bool
    let overdue: Bool
    let repeats: Bool

    /// "today", "Aug 3", or "" — short, because a watch has no room for more.
    func dueLabel(today: String) -> String {
        guard !due.isEmpty else { return "" }
        if due == today { return time.isEmpty ? "today" : timeLabel }
        let parts = due.split(separator: "-").compactMap { Int($0) }
        guard parts.count == 3 else { return due }
        var c = DateComponents()
        c.year = parts[0]; c.month = parts[1]; c.day = parts[2]
        guard let d = Calendar.current.date(from: c) else { return due }
        let f = DateFormatter()
        f.dateFormat = "MMM d"
        return f.string(from: d)
    }

    /// "2:30 pm" from the stored 24-hour "14:30".
    var timeLabel: String {
        let parts = time.split(separator: ":").compactMap { Int($0) }
        guard parts.count >= 2 else { return time }
        let h = parts[0] % 12 == 0 ? 12 : parts[0] % 12
        let m = parts[1] == 0 ? "" : String(format: ":%02d", parts[1])
        return "\(h)\(m)\(parts[0] < 12 ? "am" : "pm")"
    }
}

enum RemindersError: LocalizedError {
    case noToken
    case status(Int)
    case notJSON

    var errorDescription: String? {
        switch self {
        case .noToken:      return "No token yet. Open the iPhone app and paste one in Settings."
        case .status(403):  return "That token isn't valid any more."
        case .status(let c): return "Server said \(c)."
        case .notJSON:      return "Couldn't read the reply."
        }
    }
}

/// Fetching the list. Read-only on purpose: the token is the whole credential and it
/// was handed out as a read key, so the watch can look but not touch. Ticking things
/// off still happens on the phone.
enum RemindersAPI {
    static func fetch(base: URL = Site.base, token: String = Site.token) async throws -> ReminderList {
        guard !token.isEmpty else { throw RemindersError.noToken }
        var comps = URLComponents(url: base.appendingPathComponent("api/reminders.php"),
                                  resolvingAgainstBaseURL: false)!
        comps.queryItems = [URLQueryItem(name: "token", value: token)]

        var req = URLRequest(url: comps.url!)
        req.cachePolicy = .reloadIgnoringLocalCacheData
        req.timeoutInterval = 15

        let (data, resp) = try await URLSession.shared.data(for: req)
        if let http = resp as? HTTPURLResponse, http.statusCode != 200 {
            throw RemindersError.status(http.statusCode)
        }
        do { return try JSONDecoder().decode(ReminderList.self, from: data) }
        catch { throw RemindersError.notJSON }
    }
}
