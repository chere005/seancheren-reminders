---
name: ios-watch-dev
description: >-
  Build and modify the native iOS + Apple Watch app in `ios/` — a SwiftUI, no-
  network, local-only Codable app (Reminders/Calendar/Notes/Habits on iOS 17+,
  a read-only reminder list on watchOS 10+). Use when writing or reviewing Swift/
  SwiftUI, changing the `Store`/`AppData` data layer, the phone↔watch
  WatchConnectivity bridge, or the hand-maintained `Seancheren.xcodeproj`
  (project.pbxproj, targets, signing, bundle ids, generated Info.plists). This
  app shares no code with the PHP website — editing one never touches the other.
---

# iOS + Apple Watch app (`ios/`)

A fully native SwiftUI app: **no web view, no login, no token, no network.** All
data is one `Codable` tree (`AppData`) saved to a single `suite.json` in
Application Support. Two targets in one project (`Seancheren.xcodeproj`): the
iOS app `Seancheren` (iOS 17+) and the embedded watch app `SeancherenWatch`
(watchOS 10+). Read `ios/README.md` — it's the authoritative map.

**This is a separate codebase from the PHP suite.** They look alike on purpose
but share nothing; a change here never affects the website and vice versa. Don't
"reuse" website code or reach for the network.

## Architecture in one breath

- **`Shared/Model.swift`** — the whole `AppData` tree (folders, reminders,
  events, notes, habits, calendars) as plain `Codable` structs. Value types,
  `UUID` ids, day-granular `Date` + a separate `minutes` field so moving a date
  can't move the time.
- **`Shared/Store.swift`** — `@MainActor final class Store: ObservableObject`
  with `@Published var data`. It is the single source of truth and the only
  writer to disk. Views hold it as `@EnvironmentObject` and mutate `data`
  directly.
- **`Shared/Parse.swift`** — the slash-only US-order text parser, ported from the
  web suite's `parse_when_from_text`.
- **`App/…`** — one SwiftUI view per tab, plus `PhoneConnectivity` (the watch
  bridge), `Pickers`, `Theme`, `RootView`, `SeancherenApp`.
- **`Watch/…`** — the watchOS target: decode the pushed list and draw it.
- **`Shared/WatchPayload.swift`** — the tiny `WatchList` the two ends exchange,
  compiled into **both** targets so they can't drift apart.

## The Store pattern — follow it exactly

```swift
// Mutate data directly, then call touch(). Never write to disk yourself.
store.data.reminders.append(newReminder)
store.touch()
```

- **`touch()`** sends `objectWillChange`, debounces the save ~400ms (so typing a
  title doesn't rewrite the file per keystroke), and pushes the fresh list to the
  watch — all in one call. After any mutation, call `touch()`; never call
  `save()` directly from a view.
- Saves are **atomic** (`write(to:options:.atomic)`), pretty-printed with
  `.sortedKeys` so the JSON diffs cleanly if you ever inspect it.
- `Store` is `@MainActor` — all mutation is on the main actor. Don't hop off it
  to touch `data`.
- New data goes through `AppData.starter` on first run; `erase()` returns there.

## SwiftUI conventions here

- One `@EnvironmentObject var store: Store` per view; read `store.data.…`, write
  then `store.touch()`. Don't duplicate state into `@State` that mirrors the
  store — derive it.
- Keep views value-driven and small; push list logic into `Store` helper methods
  (`folderName(_:)`, `addFolder(_:kind:)`, `watchList()`) rather than fattening
  the view.
- Match the ported web behaviors precisely: repeats with **month/year day-
  clamping** (Jan 31 → Feb 28, not Mar 3), **undated-first** ordering, **two-
  press delete**, the **slash-only US-order** parser. These are deliberate; keep
  them identical to `Shared/Parse.swift` and the model.
- `@MainActor` for anything touching the store or UI. Use `Task { }` for async;
  respect Swift concurrency (no data races — the compiler with strict
  concurrency will tell you).
- Theme/colors live in `App/Theme.swift` — go through it, don't hardcode.

## The watch is read-only

The watch can't hold the database, so the phone hands it a ready-made
`WatchList` (open items only, dates pre-formatted) as the WatchConnectivity
**application context**. The watch decodes, caches in `UserDefaults`, and draws.
Ticking from the wrist is a future job — it needs a message *back* to the phone,
since **the phone is the only writer.** Details: `references/watch-connectivity.md`.

## Editing the Xcode project is manual

`project.pbxproj` is hand-maintained with stable numeric ids, and Info.plists are
generated from build settings (no `.plist` files to edit). Adding a source file
means editing four places in the pbxproj — and a *shared* file needs entries in
**both** targets. Get this wrong and the build breaks in confusing ways. Full
procedure and the bundle-id rule: `references/xcode-project.md`.

## Building / checking a change

- Open `ios/Seancheren.xcodeproj`, set your team under Signing & Capabilities
  for **both** targets, run `Seancheren` (that installs the embedded watch app
  too).
- From the CLI you can typecheck/build with `xcodebuild` (see
  `references/xcode-project.md`) if Xcode's toolchain is installed — the fastest
  way to confirm Swift compiles without opening the IDE.
- Nothing in `ios/` is deployed — `deploy.sh` only sends `public/` and `lib/`.

## Reference files (read on demand)

- **`references/watch-connectivity.md`** — the phone↔watch application-context
  bridge, `WatchList` shape, keeping the two ends in sync, out-of-range behavior.
- **`references/xcode-project.md`** — hand-editing `project.pbxproj`, targets and
  membership, bundle ids, generated Info.plists, signing, `xcodebuild`.
