import SwiftUI

@main
struct CalMindApp: App {
    // The one store, made once and shared with every screen. It also drives the watch:
    // whenever the data changes, the connectivity object ships a fresh list over.
    @StateObject private var store = Store()
    @StateObject private var link = PhoneConnectivity()

    var body: some Scene {
        WindowGroup {
            RootView()
                .environmentObject(store)
                .preferredColorScheme(.dark)   // the suite is dark-only
                .onAppear { link.bind(to: store) }   // pushes now + on every change/activation
        }
    }
}
