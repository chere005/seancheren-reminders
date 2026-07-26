import Foundation
import WatchConnectivity

/// The other end of the phone's token hand-off. Whatever arrives is written to
/// `UserDefaults` straight away, so the watch app still knows it after a restart and
/// doesn't depend on the phone being nearby to show a list.
final class WatchLinkReceiver: NSObject, ObservableObject, WCSessionDelegate {
    @Published private(set) var token = Site.token

    override init() {
        super.init()
        guard WCSession.isSupported() else { return }
        WCSession.default.delegate = self
        WCSession.default.activate()
    }

    private func absorb(_ context: [String: Any]) {
        let incoming = (context[WatchLink.tokenKey] as? String) ?? ""
        let base     = (context[WatchLink.baseKey] as? String) ?? ""
        DispatchQueue.main.async {
            if !base.isEmpty { UserDefaults.standard.set(base, forKey: Site.Key.base) }
            guard !incoming.isEmpty, incoming != self.token else { return }
            UserDefaults.standard.set(incoming, forKey: Site.Key.token)
            self.token = incoming
        }
    }

    func session(_ session: WCSession, activationDidCompleteWith state: WCSessionActivationState,
                 error: Error?) {
        // Whatever the phone last sent is waiting here, even if it was sent while
        // the watch app wasn't running.
        absorb(session.receivedApplicationContext)
    }

    func session(_ session: WCSession, didReceiveApplicationContext context: [String: Any]) {
        absorb(context)
    }
}
