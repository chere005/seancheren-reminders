import SwiftUI

/// The suite's item palettes — the web's leaned tiers, one per kind: the same six hues
/// (blue, red, green, orange, purple, grey), each kind wearing them at its own
/// unmistakable shade. The values are `app_palette()`'s live output (lib/palette.php),
/// frozen here; folders, calendars and sections store an index into their kind's tier,
/// so re-theming stays one edit and an old index just re-hues.
enum Theme {
    enum Tier { case reminder, calendar, note, habit }

    static let reminderPalette = [0x4c8bf0, 0xea5853, 0x66d695, 0xf39849, 0x9e5ce0, 0x929aaa].map { Color(hex: UInt32($0)) }   // the vivid anchor
    static let calendarPalette = [0x0379f6, 0xed0d10, 0x2ad05f, 0xfa6800, 0x803be7, 0x677289].map { Color(hex: UInt32($0)) }   // electric deep
    static let notePalette     = [0x7dc2ed, 0xe9818a, 0x8fdb9d, 0xefa37b, 0xa088e2, 0xadb2bd].map { Color(hex: UInt32($0)) }   // sky, leaned back
    static let habitPalette    = [0x4357ef, 0xe44525, 0x3ecb9f, 0xf09a19, 0xb131d8, 0x7d8699].map { Color(hex: UInt32($0)) }   // full-strength jewel

    static func palette(_ tier: Tier) -> [Color] {
        switch tier {
        case .reminder: return reminderPalette
        case .calendar: return calendarPalette
        case .note:     return notePalette
        case .habit:    return habitPalette
        }
    }

    /// The tier a stored kind's colours come from (calendars aren't an ItemKind; they
    /// ask for `.calendar` directly).
    static func tier(_ kind: ItemKind) -> Tier {
        switch kind {
        case .reminder: return .reminder
        case .note:     return .note
        case .habit:    return .habit
        }
    }

    static func color(_ index: Int, _ tier: Tier) -> Color {
        let p = palette(tier)
        return p[((index % p.count) + p.count) % p.count]
    }

    /// One colour per kind, the same everywhere — a dot, a chip and a tag all read
    /// these rather than a literal. The event blue is deliberately a blue and not a
    /// cyan, so at dot size it can't be mistaken for the green.
    static let reminder = Color(hex: 0x34d399)
    static let event    = Color(hex: 0x60a5fa)
    static let note     = Color(hex: 0x8b6ef0)
    static let overdue  = Color(hex: 0xff7755)
}

extension Color {
    init(hex: UInt32) {
        self.init(.sRGB,
                  red:   Double((hex >> 16) & 0xff) / 255,
                  green: Double((hex >> 8) & 0xff) / 255,
                  blue:  Double(hex & 0xff) / 255,
                  opacity: 1)
    }
}

/// The folder / calendar picker in a title bar: a round dot in the thing's colour
/// that drops a menu of everything, "All" first.
struct PickerDot: View {
    let color: Color?
    var body: some View {
        Circle()
            .fill(color ?? .clear)
            .overlay {
                if color == nil {
                    // The "everything" dot: the vivid anchor tier as a colour wheel.
                    Circle().fill(AngularGradient(colors: Theme.reminderPalette, center: .center))
                }
            }
            .frame(width: 16, height: 16)
    }
}
