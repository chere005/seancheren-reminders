import SwiftUI

/// The reminder list as it stands on the phone: the same groups, the same order, open
/// items only. Read-only — the watch shows what's on, and the phone owns the data.
struct RemindersView: View {
    @StateObject private var link = WatchLinkReceiver()

    var body: some View {
        NavigationStack {
            Group {
                if link.list.sections.isEmpty {
                    empty(synced: link.synced)
                } else {
                    rows(link.list)
                }
            }
            // No big "Reminders" title: the list already carries a "Reminders" section
            // header, and on the small screen the large nav title only ate a row of space.
        }
    }

    private func rows(_ list: WatchList) -> some View {
        List {
            ForEach(list.sections) { section in
                Section(section.name) {
                    ForEach(section.items) { row($0) }
                }
            }
        }
    }

    private func row(_ item: WatchItem) -> some View {
        HStack(alignment: .firstTextBaseline, spacing: 6) {
            Circle()
                .fill(item.overdue ? Color.overdue : Color.suiteGreen)
                .frame(width: 6, height: 6)
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

    /// Shown when the list has no sections: "nothing on the list" once the phone has synced,
    /// otherwise a nudge to open the phone app (the two read identically without `synced`).
    private func empty(synced: Bool) -> some View {
        VStack(spacing: 8) {
            Image(systemName: synced ? "checklist" : "iphone.and.arrow.forward")
                .font(.title2)
                .foregroundStyle(.secondary)
            Text(synced ? "Nothing on the list." : "Open Seancheren on your phone to sync.")
                .font(.footnote)
                .multilineTextAlignment(.center)
                .foregroundStyle(.secondary)
        }
        .padding()
    }
}

extension Color {
    /// #34d399 and #ff7755 — the suite's green and the colour an overdue item wears.
    static let suiteGreen = Color(red: 0.204, green: 0.827, blue: 0.600)
    static let overdue    = Color(red: 1.000, green: 0.467, blue: 0.333)
}
