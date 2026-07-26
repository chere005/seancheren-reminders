import SwiftUI

/// The suite's colours. Folders and calendars store an index into `palette` rather
/// than a hex string, so re-theming later is one edit here.
enum Theme {
    static let palette: [Color] = [
        Color(hex: 0x60a5fa), Color(hex: 0x34d399), Color(hex: 0xf472b6),
        Color(hex: 0xc084fc), Color(hex: 0xfb923c), Color(hex: 0xfacc15),
        Color(hex: 0xfb7185), Color(hex: 0x22d3ee), Color(hex: 0xa3e635),
        Color(hex: 0x94a3b8),
    ]

    static func color(_ index: Int) -> Color {
        palette[((index % palette.count) + palette.count) % palette.count]
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
                    Circle().fill(AngularGradient(colors: Theme.palette, center: .center))
                }
            }
            .frame(width: 16, height: 16)
    }
}
