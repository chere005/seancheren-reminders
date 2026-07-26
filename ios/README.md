# iOS + Apple Watch

Two targets in one Xcode project, `Seancheren.xcodeproj`:

| Target | Platform | What it is |
| --- | --- | --- |
| `Seancheren` | iOS 17+ | The whole suite — Reminders, Calendar, Notes, Habits — as four native tabs |
| `SeancherenWatch` | watchOS 10+ | Your reminder list on the wrist, read-only |

Open `ios/Seancheren.xcodeproj`, pick your team under **Signing & Capabilities** for
both targets, and run. The watch app is embedded in the phone app, so building and
running `Seancheren` installs both — that's why they're one project rather than two.

## Why the phone app is a web view

The four apps are the pages. `WebTab` (`App/WebView.swift`) puts each one in its own
`WKWebView` and the shell wraps them in a native `TabView`, so **every feature works
the day it ships on the site** — repeats, sharing, folders, drag-reorder, the rich note
editor, the habit grid — with no second implementation to keep in step. A native
rewrite of four apps would be four more copies of every rule in `lib/`, and the first
one to drift would be a bug you only see on the phone.

What the shell adds on top:

- **One session across all four.** They share `WKWebsiteDataStore.default()`, so you
  sign in once and it survives quitting.
- **Native tabs.** The site's own bottom bar is hidden by an injected stylesheet
  (`.tabbar{display:none}`) along with the body padding that cleared it.
- **Pull to refresh**, back/forward swipes, and off-site links opening in Safari.
- **Settings** — the fifth tab: which site to point at, the watch's token, and a
  two-press Sign out that drops the shared cookies. Your password, theme and folders
  still live in the pages themselves, behind the ⋮ next to your name.

## The watch app

A watch has nowhere to keep a login, so it reads `GET /api/reminders.php?token=…`
instead — the same token as the calendar widget, out of `data/token-<user>.json`. The
endpoint returns the list you'd see opening the app: the same groups, the same order,
undated first and then by date.

**It is read-only.** That token is handed out as a read key on the widget setup page,
so anything already copied into a Scriptable script would gain the power to write the
moment the endpoint accepted a POST. Ticking things off stays on the phone until
that's a deliberate decision (a separate write token, most likely).

Getting the token across:

1. On the phone, open **Calendar → the widget setup page** (`/calendar/feed.php`) and
   copy the `token=` value out of the feed URL.
2. Paste it into **Settings → Watch** in the phone app and tap **Send to Watch**.

It goes over as the WatchConnectivity *application context*, which is redelivered if
the watch was asleep, and the watch writes it to `UserDefaults` — so it keeps working
with the phone out of range.

## Layout

```
ios/
  Seancheren.xcodeproj/
  App/      SeancherenApp, RootView, WebView, SettingsView, PhoneConnectivity   (iOS)
  Watch/    WatchApp, RemindersView, WatchConnectivityReceiver                  (watchOS)
  Shared/   Site, Reminders, WatchLink            (compiled into both targets)
```

`Shared/Site.swift` holds the base URL and the four apps; `Shared/Reminders.swift` is
the model and the one network call. Both are members of both targets — one file, two
build phases, so there is no copy to keep in sync.

## Notes

- Nothing here is deployed. `deploy.sh` only sends `public/` and `lib/`.
- Bundle ids are `com.seancheren.suite` and `com.seancheren.suite.watchkitapp`. The
  watch id **must** stay a `.watchkitapp` suffix of the phone's or the pair won't
  install together.
- Pointing Settings at a plain-`http` server (e.g. `php -S` on your Mac) needs an ATS
  exception in the iOS target's Info.plist settings; over https it just works.
- Info.plists are generated from build settings (`GENERATE_INFOPLIST_FILE`), so
  there are no `.plist` files to edit — change the `INFOPLIST_KEY_*` settings instead.
