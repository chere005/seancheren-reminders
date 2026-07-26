import Foundation
import WatchConnectivity

/// Getting the watch its token.
///
/// There's no typing a 32-character hex string on a watch, so the phone hands it
/// over: the token and site URL go out as the *application context*, which
/// WatchConnectivity keeps and redelivers if the watch was asleep or out of range.
final class PhoneConnectivity: NSObject, ObservableObject, WCSessionDelegate {
    @Published var paired = false

    override init() {
        super.init()
        guard WCSession.isSupported() else { return }
        WCSession.default.delegate = self
        WCSession.default.activate()
    }

    func push(token: String, base: String) {
        guard WCSession.isSupported(), WCSession.default.activationState == .activated else { return }
        try? WCSession.default.updateApplicationContext([WatchLink.tokenKey: token,
                                                         WatchLink.baseKey: base])
    }

    // MARK: - WCSessionDelegate

    func session(_ session: WCSession, activationDidCompleteWith state: WCSessionActivationState,
                 error: Error?) {
        DispatchQueue.main.async { self.paired = session.isPaired && session.isWatchAppInstalled }
    }

    // Both required on iOS: the pair can be handed to a different watch, and the
    // session has to be woken up again for the new one.
    func sessionDidBecomeInactive(_ session: WCSession) {}

    func sessionDidDeactivate(_ session: WCSession) {
        WCSession.default.activate()
    }
}
