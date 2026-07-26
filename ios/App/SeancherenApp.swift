import SwiftUI

@main
struct SeancherenApp: App {
    @StateObject private var link = PhoneConnectivity()

    var body: some Scene {
        WindowGroup {
            RootView()
                .environmentObject(link)
                .preferredColorScheme(.dark)   // the suite is dark-only
        }
    }
}
