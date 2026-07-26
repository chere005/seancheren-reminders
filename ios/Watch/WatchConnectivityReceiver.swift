import Foundation
import WatchConnectivity

/// The watch end of the hand-off. Whatever list the phone last sent is decoded and
/// kept in `UserDefaults`, so the watch still shows it after a restart and doesn't
/// depend on the phone being nearby.
@MainActor
final class WatchLinkReceiver: NSObject, ObservableObject, WCSessionDelegate {
    @Published private(set) var list: WatchList

    private static let key = "watchList"

    override init() {
        list = WatchLinkReceiver.stored() ?? WatchList()
        super.init()
        guard WCSession.isSupported() else { return }
        WCSession.default.delegate = self
        WCSession.default.activate()
    }

    private static func stored() -> WatchList? {
        guard let bytes = UserDefaults.standard.data(forKey: key) else { return nil }
        return try? JSONDecoder().decode(WatchList.self, from: bytes)
    }

    private func absorb(_ context: [String: Any]) {
        guard let bytes = context[WatchLink.listKey] as? Data,
              let incoming = try? JSONDecoder().decode(WatchList.self, from: bytes) else { return }
        Task { @MainActor in
            UserDefaults.standard.set(bytes, forKey: Self.key)
            self.list = incoming
        }
    }

    nonisolated func session(_ session: WCSession, activationDidCompleteWith state: WCSessionActivationState,
                             error: Error?) {
        // Whatever the phone last sent is waiting here, even if it arrived while the
        // watch app wasn't running.
        let context = session.receivedApplicationContext
        Task { @MainActor in self.absorb(context) }
    }

    nonisolated func session(_ session: WCSession, didReceiveApplicationContext context: [String: Any]) {
        Task { @MainActor in self.absorb(context) }
    }
}
