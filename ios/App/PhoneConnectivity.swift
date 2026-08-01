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

    // A way to build the *current* list on demand, so activation and reachability changes
    // can push fresh state rather than whatever happened to be sent last. Set by bind().
    private var provider: (() -> WatchList)?

    override init() {
        super.init()
        guard WCSession.isSupported() else { return }
        WCSession.default.delegate = self
        WCSession.default.activate()
    }

    /// Wire the store in: push now, and push again on every change. Calling this is all the
    /// app has to do — the initial hand-off and the resend-on-activation are handled here,
    /// so the watch always has a context waiting even on a cold first run.
    func bind(to store: Store) {
        provider = { [weak store] in store?.watchList() ?? WatchList() }
        store.onChange { [weak self] in self?.pushCurrent() }
        pushCurrent()
    }

    /// Push the latest list. A no-op until the session is activated — the activation
    /// callback pushes again, so nothing is lost by an early call.
    func pushCurrent() {
        guard let list = provider?() else { return }
        guard WCSession.isSupported() else { return }
        let session = WCSession.default
        guard session.activationState == .activated else { return }
        do {
            let bytes = try JSONEncoder().encode(list)
            try session.updateApplicationContext([WatchLink.listKey: bytes])
            // Application-context delivery is unreliable in the simulator (the classic
            // "application context is nil" on the watch). A user-info transfer is queued and
            // delivered reliably, so send one too; cancel any still in flight first so only
            // the latest is queued — keeping the "here's the current list" semantics.
            session.outstandingUserInfoTransfers.forEach { $0.cancel() }
            session.transferUserInfo([WatchLink.listKey: bytes])
        } catch {
            // Surface it — a swallowed error here is exactly why "application context is
            // nil" on the watch is so hard to chase.
            print("watch: application-context push failed — \(error.localizedDescription)")
        }
    }

    // MARK: - WCSessionDelegate

    nonisolated func session(_ session: WCSession, activationDidCompleteWith state: WCSessionActivationState,
                             error: Error?) {
        if let error { print("watch: session activation failed — \(error.localizedDescription)") }
        Task { @MainActor in
            self.paired = session.isPaired && session.isWatchAppInstalled
            self.pushCurrent()          // the link is live now — send fresh state
        }
    }

    // The watch app being (re)installed or the pair changing is the other moment the
    // context needs re-sending, since a fresh install starts with an empty one.
    nonisolated func sessionWatchStateDidChange(_ session: WCSession) {
        Task { @MainActor in
            self.paired = session.isPaired && session.isWatchAppInstalled
            self.pushCurrent()
        }
    }

    nonisolated func sessionReachabilityDidChange(_ session: WCSession) {
        Task { @MainActor in self.pushCurrent() }
    }

    // Both required on iOS: the pair can be handed to a different watch, and the session
    // has to be woken up again for the new one.
    nonisolated func sessionDidBecomeInactive(_ session: WCSession) {}
    nonisolated func sessionDidDeactivate(_ session: WCSession) { WCSession.default.activate() }
}
