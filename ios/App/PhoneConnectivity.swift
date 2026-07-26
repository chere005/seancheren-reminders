import Foundation
import WatchConnectivity

/// Keeps the watch's copy of the reminder list up to date.
///
/// There's no login and no network any more, so the watch can't fetch anything on its
/// own — the phone hands it a ready-made `WatchList` as the WatchConnectivity
/// *application context*, which is redelivered whenever the watch next wakes, even if it
/// was asleep or out of range when the change happened.
@MainActor
final class PhoneConnectivity: NSObject, ObservableObject, WCSessionDelegate {
    @Published var paired = false
    private var last: WatchList?

    override init() {
        super.init()
        guard WCSession.isSupported() else { return }
        WCSession.default.delegate = self
        WCSession.default.activate()
    }

    /// Push a fresh list every time the store changes.
    func bind(to store: Store) {
        store.onChange { [weak self, weak store] in
            guard let store else { return }
            self?.push(store.watchList())
        }
    }

    func push(_ list: WatchList) {
        last = list
        guard WCSession.isSupported(), WCSession.default.activationState == .activated,
              let bytes = try? JSONEncoder().encode(list) else { return }
        try? WCSession.default.updateApplicationContext([WatchLink.listKey: bytes])
    }

    // MARK: - WCSessionDelegate

    nonisolated func session(_ session: WCSession, activationDidCompleteWith state: WCSessionActivationState,
                             error: Error?) {
        Task { @MainActor in
            self.paired = session.isPaired && session.isWatchAppInstalled
            if let last = self.last { self.push(last) }   // resend once the link is live
        }
    }

    // Both required on iOS: the pair can be handed to a different watch, and the
    // session has to be woken up again for the new one.
    nonisolated func sessionDidBecomeInactive(_ session: WCSession) {}

    nonisolated func sessionDidDeactivate(_ session: WCSession) {
        WCSession.default.activate()
    }
}
