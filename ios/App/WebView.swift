import SwiftUI
import WebKit

/// One tab's web view.
///
/// Held by the model rather than made inside `makeUIView`, so a tab keeps its page —
/// scroll position, a half-typed note — while you're off in another one. All four
/// share `WKWebsiteDataStore.default()`, so signing in once signs you into the whole
/// suite and it survives quitting the app, exactly as one session does in Safari.
final class WebTab: NSObject, ObservableObject, WKNavigationDelegate, WKUIDelegate {
    let app: Site.App
    let webView: WKWebView

    @Published var loading = false
    @Published var failure: String?

    private var started = false

    init(app: Site.App) {
        self.app = app

        let cfg = WKWebViewConfiguration()
        cfg.websiteDataStore = .default()
        cfg.allowsInlineMediaPlayback = true
        // The native tab bar replaces the site's own, and the page pads the body to
        // clear it — hide both, or every app ends in an inch of dead space.
        cfg.userContentController.addUserScript(WKUserScript(source: """
            (function () {
              var s = document.createElement('style');
              s.textContent = '.tabbar{display:none !important}'
                            + 'body{padding-bottom:env(safe-area-inset-bottom,0px) !important}';
              document.documentElement.appendChild(s);
            })();
            """, injectionTime: .atDocumentEnd, forMainFrameOnly: true))

        let wv = WKWebView(frame: .zero, configuration: cfg)
        wv.allowsBackForwardNavigationGestures = true
        wv.isOpaque = false
        wv.backgroundColor = UIColor(red: 0.067, green: 0.067, blue: 0.067, alpha: 1)   // #111
        wv.scrollView.backgroundColor = wv.backgroundColor
        self.webView = wv

        super.init()
        wv.navigationDelegate = self
        wv.uiDelegate = self

        let refresh = UIRefreshControl()
        refresh.tintColor = .gray
        refresh.addTarget(self, action: #selector(pulled), for: .valueChanged)
        wv.scrollView.refreshControl = refresh
    }

    /// First look at this tab: load it. Later looks leave whatever page you were on.
    func start() {
        guard !started else { return }
        started = true
        load()
    }

    /// Back to the app's own front page — what changing the site URL or signing out
    /// needs, since the current page may no longer exist or no longer be yours.
    func reset() {
        started = true
        load()
    }

    private func load() {
        failure = nil
        webView.load(URLRequest(url: Site.url(for: app)))
    }

    @objc private func pulled() {
        webView.reload()
    }

    // MARK: - WKNavigationDelegate

    func webView(_ webView: WKWebView, didStartProvisionalNavigation navigation: WKNavigation!) {
        loading = true
    }

    func webView(_ webView: WKWebView, didFinish navigation: WKNavigation!) {
        loading = false
        failure = nil
        webView.scrollView.refreshControl?.endRefreshing()
    }

    func webView(_ webView: WKWebView, didFail navigation: WKNavigation!, withError error: Error) {
        finish(error)
    }

    func webView(_ webView: WKWebView, didFailProvisionalNavigation navigation: WKNavigation!,
                 withError error: Error) {
        finish(error)
    }

    private func finish(_ error: Error) {
        loading = false
        webView.scrollView.refreshControl?.endRefreshing()
        // -999 is "you navigated away before this finished", which isn't a failure.
        if (error as NSError).code != NSURLErrorCancelled { failure = error.localizedDescription }
    }

    /// Anything pointing off the site opens in Safari; the suite's own links stay put.
    func webView(_ webView: WKWebView, decidePolicyFor action: WKNavigationAction,
                 decisionHandler: @escaping (WKNavigationActionPolicy) -> Void) {
        if action.navigationType == .linkActivated,
           let url = action.request.url,
           url.host != Site.base.host {
            UIApplication.shared.open(url)
            decisionHandler(.cancel)
            return
        }
        decisionHandler(.allow)
    }

    // MARK: - WKUIDelegate

    /// A target="_blank" link has no window to open in here, so it opens in place.
    func webView(_ webView: WKWebView, createWebViewWith configuration: WKWebViewConfiguration,
                 for action: WKNavigationAction, windowFeatures: WKWindowFeatures) -> WKWebView? {
        if action.targetFrame == nil, let url = action.request.url { webView.load(URLRequest(url: url)) }
        return nil
    }
}

/// The web view as a SwiftUI view, plus the one bit of chrome the shell owns: a line
/// across the bottom when a page couldn't load, since a blank tab tells you nothing.
struct WebPane: View {
    @ObservedObject var tab: WebTab

    var body: some View {
        WebViewRep(webView: tab.webView)
            .overlay(alignment: .bottom) {
                if let failure = tab.failure {
                    Text(failure)
                        .font(.footnote)
                        .foregroundStyle(.white)
                        .padding(.vertical, 8)
                        .frame(maxWidth: .infinity)
                        .background(Color(red: 0.6, green: 0.2, blue: 0.2))
                }
            }
            .onAppear { tab.start() }
    }
}

private struct WebViewRep: UIViewRepresentable {
    let webView: WKWebView
    func makeUIView(context: Context) -> WKWebView { webView }
    func updateUIView(_ uiView: WKWebView, context: Context) {}
}
