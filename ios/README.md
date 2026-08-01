# iOS + Apple Watch

Two targets in one Xcode project, `Seancheren.xcodeproj`:

| Target | Platform | What it is |
| --- | --- | --- |
| `Seancheren` | iOS 17+ | Reminders · Calendar · Notes · Habits · Settings, as native tabs |
| `SeancherenWatch` | watchOS 10+ | Your reminder list on the wrist, read-only |

Open `ios/Seancheren.xcodeproj`, pick your team under **Signing & Capabilities** for
both targets, and run. The watch app is embedded in the phone app, so building and
running `Seancheren` installs both — that's why they're one project rather than two.

## Completely independent of the website

This app shares nothing with `seancheren.com` — no web view, no login, no token, no
network. It is its own native app with its own local data. The site suite is a separate
thing that happens to look the same; changing one doesn't touch the other.

- **All data is local.** One `Codable` tree (`AppData` in `Shared/Model.swift`) is
  saved to a single `suite.json` in Application Support, through `Store`
  (`Shared/Store.swift`). Writes are debounced, so typing doesn't rewrite the file on
  every keystroke.
- **No accounts.** There is nothing to sign into. The app starts empty — one "General"
  folder for reminders and one for notes, one calendar, and nothing in them.
- **Notes are plain text.** A note is a title and a plain `String` body edited in a
  `TextEditor` — there's nothing to render, so nothing to sanitise. A note can also carry
  an optional day (`DateOnlyPicker`), which puts it on the calendar under that date; the
  title is never date-parsed, unlike a reminder's.

Everything the site suite does that makes sense on a phone is reimplemented natively:
folders and groups, repeats (with month/year day-clamping), the undated-first ordering,
the slash-only US-order text parser (`Shared/Parse.swift`), the two-press delete, plus
**subtasks** (one level, swipe a task left to add one, a subtask left to lift it out),
**per-section colours**, the Habits **week and month views** — the month drawn as a pie
per day, sliced in the sections' own colours, behind a section filter — and Reminders'
**Copy as Markdown**.

## Tests

`Shared/` (Model, Store, Parse, WatchPayload) is pure Foundation — no SwiftUI — so it
runs on macOS. `Package.swift` wraps it as a `SuiteCore` module with a `Tests/` XCTest
suite you drive from the command line, the way the website's `tools/test.php` runs:

```sh
cd ios
# The logic layer (repeats, the outline sort, folders/groups, calendars, day queries,
# parsing, the watch list, Copy-as-Markdown, the Habits filter, tolerant decoding):
DEVELOPER_DIR=/Applications/Xcode.app/Contents/Developer swift test

# Compile-check the SwiftUI app — no simulator needed, it builds for a generic device:
DEVELOPER_DIR=/Applications/Xcode.app/Contents/Developer \
  xcodebuild -project Seancheren.xcodeproj -scheme Seancheren \
  -destination 'generic/platform=iOS' -configuration Debug \
  CODE_SIGNING_ALLOWED=NO build
```

`DEVELOPER_DIR` points the tools at full Xcode when `xcode-select` is on the Command Line
Tools (which don't ship XCTest for SwiftPM). The suite also proves a `suite.json` written
before a field existed still loads — the model decodes tolerantly (`decodeIfPresent` +
defaults) rather than resetting the whole document to empty over one missing key.

## The watch app

A watch can't hold the database, so the phone hands it a ready-made list. Whenever the
store changes, `PhoneConnectivity` (`App/PhoneConnectivity.swift`) builds a `WatchList`
(`Store.watchList()`) — the same groups in the same order as the Reminders screen, open
items only, dates already turned into short strings — and ships it as the
WatchConnectivity *application context*. `WatchLinkReceiver` on the watch decodes it,
keeps a copy in `UserDefaults`, and draws it. The context is redelivered whenever the
watch next wakes, so it keeps working with the phone out of range.

**It is read-only.** The watch shows what's on; the phone owns the data. Ticking things
off from the wrist is a future job (it needs a message back to the phone, since the
phone is the only writer).

## Layout

```
ios/
  Seancheren.xcodeproj/
  App/      SeancherenApp, RootView, RemindersView, NotesView, CalendarView,
            HabitsView, Pickers, Theme, SettingsView, PhoneConnectivity      (iOS)
  Watch/    WatchApp, RemindersView, WatchConnectivityReceiver               (watchOS)
  Shared/   Model, Parse, Store, WatchPayload
  Package.swift + Tests/   CLI test package over Shared/ (SuiteCore + XCTest); not in the
                           Xcode project — a separate SwiftPM build, so the two don't clash
```

`Shared/Model.swift`, `Parse.swift` and `Store.swift` are the iOS app's data layer.
`Shared/WatchPayload.swift` defines the small `WatchList` the two ends exchange and is
the one file compiled into **both** targets, so the phone and watch can't drift apart on
its shape.

## Notes

- Nothing here is deployed. `deploy.sh` only sends `public/` and `lib/`.
- Bundle ids are `com.seancheren.suite` and `com.seancheren.suite.watchkitapp`. The
  watch id **must** stay a `.watchkitapp` suffix of the phone's or the pair won't
  install together.
- Info.plists are generated from build settings (`GENERATE_INFOPLIST_FILE`), so there
  are no `.plist` files to edit — change the `INFOPLIST_KEY_*` settings instead.
- `project.pbxproj` is hand-maintained with stable numeric ids. When you add a source
  file, give it a `PBXFileReference`, a `PBXBuildFile`, a line in the right `PBXGroup`,
  and a line in the right target's `Sources` phase (a file shared with the watch needs a
  second `PBXBuildFile` and a line in *both* phases).
