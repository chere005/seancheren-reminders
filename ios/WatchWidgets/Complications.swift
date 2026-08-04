import WidgetKit
import SwiftUI

/// The watch-face complication: what's on **today**, straight from the week window the
/// phone last pushed. The watch app mirrors each incoming list into the shared
/// app-group defaults (`WatchLink.appGroup`) and reloads these timelines; this
/// extension only reads that cache — it can't reach the phone itself.
///
/// Families: `accessoryRectangular` is the Modular face's big centre slot (up to three
/// of today's rows, kind-glyphed like the app); `accessoryCircular` a count in a ring;
/// `accessoryInline` one line of text.

struct TodayEntry: TimelineEntry {
    let date: Date
    let day: WatchDay?
}

struct TodayProvider: TimelineProvider {
    func placeholder(in context: Context) -> TodayEntry {
        TodayEntry(date: Date(), day: WatchDay(id: "", name: "Today", items: [
            WatchItem(id: "1", text: "Standup", due: "9am", overdue: false, kind: "event"),
            WatchItem(id: "2", text: "Buy milk", due: "", overdue: false, kind: "reminder"),
        ]))
    }

    func getSnapshot(in context: Context, completion: @escaping (TodayEntry) -> Void) {
        completion(current())
    }

    func getTimeline(in context: Context, completion: @escaping (Timeline<TodayEntry>) -> Void) {
        // One entry — the cache only changes when the phone pushes (which reloads us).
        // Refresh at midnight anyway, so a stale "today" never survives the date line.
        let midnight = Calendar.current.nextDate(after: Date(), matching: DateComponents(hour: 0),
                                                 matchingPolicy: .nextTime) ?? Date().addingTimeInterval(3600)
        completion(Timeline(entries: [current()], policy: .after(midnight)))
    }

    private func current() -> TodayEntry {
        let bytes = UserDefaults(suiteName: WatchLink.appGroup)?.data(forKey: WatchLink.cacheKey)
        let list = bytes.flatMap { try? JSONDecoder().decode(WatchList.self, from: $0) }
        // The pushed window starts on the phone's "today"; re-anchor on ours in case the
        // watch crossed midnight since — match by the day id, never blindly take first.
        let key = DateFormatter.dayKey.string(from: Date())
        let day = list?.days.first { $0.id == key } ?? list?.days.first
        return TodayEntry(date: Date(), day: day)
    }
}

extension DateFormatter {
    static let dayKey: DateFormatter = {
        let f = DateFormatter()
        f.locale = Locale(identifier: "en_US_POSIX")
        f.dateFormat = "yyyy-MM-dd"
        return f
    }()
}

struct ComplicationView: View {
    @Environment(\.widgetFamily) private var family
    let entry: TodayEntry

    private var items: [WatchItem] { entry.day?.items ?? [] }

    var body: some View {
        Group {
            switch family {
            case .accessoryRectangular: rectangular
            case .accessoryInline:      inline
            default:                    circular
            }
        }
        .containerBackground(.clear, for: .widget)
    }

    /// The Modular face's centre slot: today's first rows, glyphed by kind.
    private var rectangular: some View {
        VStack(alignment: .leading, spacing: 1) {
            if items.isEmpty {
                Text("Nothing today")
                    .font(.caption2)
                    .foregroundStyle(.secondary)
            } else {
                ForEach(items.prefix(3)) { item in
                    HStack(spacing: 4) {
                        Image(systemName: glyph(item))
                            .font(.system(size: 10))
                            .foregroundStyle(color(item))
                        Text(item.text)
                            .font(.caption2)
                            .lineLimit(1)
                        if !item.due.isEmpty {
                            Spacer(minLength: 2)
                            Text(item.due)
                                .font(.system(size: 10))
                                .foregroundStyle(.secondary)
                        }
                    }
                }
                if items.count > 3 {
                    Text("+\(items.count - 3) more")
                        .font(.system(size: 10))
                        .foregroundStyle(.secondary)
                }
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }

    private var circular: some View {
        ZStack {
            Circle().stroke(.tertiary, lineWidth: 2)
            VStack(spacing: 0) {
                Text("\(items.count)")
                    .font(.title3.bold())
                Text("today")
                    .font(.system(size: 9))
                    .foregroundStyle(.secondary)
            }
        }
    }

    private var inline: some View {
        Text(items.isEmpty
             ? "Nothing today"
             : (items.count == 1 ? items[0].text : "\(items.count) today · \(items[0].text)"))
    }

    private func glyph(_ item: WatchItem) -> String {
        switch item.kind {
        case "event": return "calendar"
        case "note":  return "doc.text"
        default:      return "square"
        }
    }

    private func color(_ item: WatchItem) -> Color {
        if item.overdue { return Color(red: 1.000, green: 0.467, blue: 0.333) }   // overdue orange
        switch item.kind {
        case "event": return Color(red: 0.376, green: 0.647, blue: 0.980)         // event blue
        case "note":  return Color(red: 0.545, green: 0.431, blue: 0.941)         // note purple
        default:      return Color(red: 0.204, green: 0.827, blue: 0.600)         // suite green
        }
    }
}

struct TodayComplication: Widget {
    var body: some WidgetConfiguration {
        StaticConfiguration(kind: "com.seancheren.suite.today", provider: TodayProvider()) {
            ComplicationView(entry: $0)
        }
        .configurationDisplayName("Today")
        .description("Everything on today — events, reminders and notes.")
        .supportedFamilies([.accessoryRectangular, .accessoryCircular, .accessoryInline])
    }
}

@main
struct CalMindWatchWidgets: WidgetBundle {
    var body: some Widget {
        TodayComplication()
    }
}
