import Foundation

/// The two keys the phone puts in the WatchConnectivity application context. Shared
/// so the pair can't drift apart — a typo on one side would just look like a watch
/// that never gets its token.
enum WatchLink {
    static let tokenKey = "token"
    static let baseKey  = "base"
}
