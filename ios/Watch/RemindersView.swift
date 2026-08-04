import SwiftUI

/// The wrist's four pages — Today · Reminders · Events · All — swiped horizontally,
/// Today first and default. Today is the phone day panel for today, every kind
/// including notes; the other three walk the week window (seven days, today first),
/// narrowed to their kind ("All" keeps everything). Read-only — the watch shows
/// what's on, and the phone owns the data.
struct WatchRootView: View {
    @StateObject private var link = WatchLinkReceiver()
    @State private var page: WatchPage = .today

    var body: some View {
        TabView(selection: $page) {
            TodayView(list: link.list, synced: link.synced)
                .tag(WatchPage.today)
            WeekView(title: "Reminders", kind: "reminder", list: link.list, synced: link.synced)
                .tag(WatchPage.reminders)
            WeekView(title: "Events", kind: "event", list: link.list, synced: link.synced)
                .tag(WatchPage.events)
            WeekView(title: "All", kind: nil, list: link.list, synced: link.synced)
                .tag(WatchPage.all)
        }
        .tabViewStyle(.page)
    }
}

enum WatchPage: Hashable { case today, reminders, events, all }

/// Everything dated today — events, reminders (overdue and the Calendar riders
/// collect here) and notes — under today's own heading.
struct TodayView: View {
    let list: WatchList
    let synced: Bool

    var body: some View {
        NavigationStack {
            Group {
                if let today = list.days.first, !today.items.isEmpty {
                    List {
                        Section(today.name) {
                            ForEach(today.items) { WatchRow(item: $0) }
                        }
                    }
                } else {
                    WatchEmpty(synced: synced, message: "Nothing today.")
                }
            }
            .navigationTitle("Today")
            .navigationBarTitleDisplayMode(.inline)
        }
    }
}

/// One kind (or all of them) across the week: a section per day, empty days dropped,
/// scrolling out seven days.
struct WeekView: View {
    let title: String
    let kind: String?          // nil = every kind
    let list: WatchList
    let synced: Bool

    private var days: [WatchDay] {
        list.days
            .map { day in
                WatchDay(id: day.id, name: day.name,
                         items: kind == nil ? day.items : day.items.filter { $0.kind == kind })
            }
            .filter { !$0.items.isEmpty }
    }

    var body: some View {
        NavigationStack {
            Group {
                if !days.isEmpty {
                    List {
                        ForEach(days) { day in
                            Section(day.name) {
                                ForEach(day.items) { WatchRow(item: $0) }
                            }
                        }
                    }
                } else if kind == "reminder" && !list.sections.isEmpty {
                    // A phone still sending the old shape has no week window; its
                    // grouped reminder list is better than a blank page.
                    List {
                        ForEach(list.sections) { section in
                            Section(section.name) {
                                ForEach(section.items) { WatchRow(item: $0) }
                            }
                        }
                    }
                } else {
                    WatchEmpty(synced: synced, message: "Nothing this week.")
                }
            }
            .navigationTitle(title)
            .navigationBarTitleDisplayMode(.inline)
        }
    }
}

/// One row: the kind's glyph in the kind's colour — the web legend's calendar / tick
/// box / page — then the text, then the short date/time.
struct WatchRow: View {
    let item: WatchItem

    var body: some View {
        HStack(alignment: .firstTextBaseline, spacing: 6) {
            Image(systemName: glyph)
                .font(.caption2)
                .foregroundStyle(color)
            Text(item.text)
                .font(.body)
                .foregroundStyle(item.overdue ? Color.overdue : Color.primary)
            Spacer(minLength: 4)
            if !item.due.isEmpty {
                Text(item.due)
                    .font(.caption2)
                    .foregroundStyle(.secondary)
            }
        }
    }

    private var glyph: String {
        switch item.kind {
        case "event": return "calendar"
        case "note":  return "doc.text"
        default:      return "square"
        }
    }

    private var color: Color {
        if item.overdue { return .overdue }
        switch item.kind {
        case "event": return .kindEvent
        case "note":  return .kindNote
        default:      return .suiteGreen
        }
    }
}

/// Shown when a page has nothing: "nothing on" once the phone has synced, otherwise a
/// nudge to open the phone app (the two read identically without `synced`).
struct WatchEmpty: View {
    let synced: Bool
    let message: String

    var body: some View {
        VStack(spacing: 8) {
            Image(systemName: synced ? "checklist" : "iphone.and.arrow.forward")
                .font(.title2)
                .foregroundStyle(.secondary)
            Text(synced ? message : "Open CalMind on your phone to sync.")
                .font(.footnote)
                .multilineTextAlignment(.center)
                .foregroundStyle(.secondary)
        }
        .padding()
    }
}

extension Color {
    /// The suite's kind palette — green, overdue orange, event blue, note purple —
    /// the same literals every platform wears (`kind_color_css()` on the web).
    static let suiteGreen = Color(red: 0.204, green: 0.827, blue: 0.600)   // #34d399
    static let overdue    = Color(red: 1.000, green: 0.467, blue: 0.333)   // #ff7755
    static let kindEvent  = Color(red: 0.376, green: 0.647, blue: 0.980)   // #60a5fa
    static let kindNote   = Color(red: 0.545, green: 0.431, blue: 0.941)   // #8b6ef0
}
