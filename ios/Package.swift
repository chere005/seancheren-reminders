// swift-tools-version: 6.0
import PackageDescription

// A CLI-testable package over the app's Shared core (Model, Store, Parse, WatchPayload).
// Those files are pure Foundation/Combine — no SwiftUI, no UIKit — so `swift test` builds
// and exercises them on macOS without a simulator. The App/ and Watch/ SwiftUI targets are
// built by CalMind.xcodeproj, not here; this package exists purely to test the logic the
// whole suite rests on. Nothing in ios/ is ever deployed to the website.
let package = Package(
    name: "SuiteCore",
    platforms: [.macOS(.v13)],
    targets: [
        .target(
            name: "SuiteCore",
            path: "Shared",
            swiftSettings: [.swiftLanguageMode(.v5)]
        ),
        .testTarget(
            name: "SuiteCoreTests",
            dependencies: ["SuiteCore"],
            path: "Tests",
            swiftSettings: [.swiftLanguageMode(.v5)]
        ),
    ]
)
