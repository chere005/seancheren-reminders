import Foundation
import WatchConnectivity
import WidgetKit

/// The watch end of the hand-off. Whatever list the phone last sent is decoded and
/// kept in `UserDefaults`, so the watch still shows it after a restart and doesn't
/// depend on the phone being nearby.
@MainActor
final class WatchLinkReceiver: NSObject, ObservableObject, WCSessionDelegate {
    @Published private(set) var list: WatchList
    /// Whether the phone has ever handed us a list. Lets the empty view tell "nothing's due"
    /// apart from "the phone hasn't synced yet", which read identically before.
    @Published private(set) var synced: Bool

    private static let key = "watchList"

    override init() {
        let cached = WatchLinkReceiver.stored()
        list = cached ?? WatchList()
        synced = cached != nil
        super.init()
        guard WCSession.isSupported() else { return }
        WCSession.default.delegate = self
        WCSession.default.activate()
    }

    private static func stored() -> WatchList? {
        guard let bytes = UserDefaults.standard.data(forKey: key) else { return nil }
        return try? JSONDecoder().decode(WatchList.self, from: bytes)
    }

    /// Mirror the list into the shared app-group defaults and wake the complication —
    /// the widget extension is a separate process, so `UserDefaults.standard` can't
    /// reach it. Skipped silently when the app group isn't provisioned.
    private static func shareWithComplication(_ bytes: Data) {
        UserDefaults(suiteName: WatchLink.appGroup)?.set(bytes, forKey: WatchLink.cacheKey)
        WidgetCenter.shared.reloadAllTimelines()
    }

    /// Decode a pushed list (from either delivery path), cache it, and show it. A payload
    /// without our key — an empty context before the phone has sent anything — is ignored.
    private func absorb(_ payload: [String: Any]) {
        guard let bytes = payload[WatchLink.listKey] as? Data,
              let incoming = try? JSONDecoder().decode(WatchList.self, from: bytes) else { return }
        Task { @MainActor in
            UserDefaults.standard.set(bytes, forKey: Self.key)
            Self.shareWithComplication(bytes)
            self.list = incoming
            self.synced = true
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

    // The reliable backstop: a queued user-info transfer, delivered even when application
    // context isn't (notably in the simulator). Same payload, absorbed the same way.
    nonisolated func session(_ session: WCSession, didReceiveUserInfo userInfo: [String: Any]) {
        Task { @MainActor in self.absorb(userInfo) }
    }
}
