---
name: android-dev
description: >-
  Build and modify the native Android app in `android/` — a Kotlin + Jetpack
  Compose, no-network, local-only app that mirrors the iOS suite (Reminders/
  Calendar/Notes/Habits). Use when writing or reviewing Kotlin/Compose, the
  `Store`/`AppData` data layer, kotlinx.serialization storage, or the Gradle
  build (modules, version catalog, `build.gradle.kts`). This app shares no code
  with the PHP website or the iOS app — it is a line-by-line cousin of `ios/`,
  not a fork of it. For keeping it in lockstep with iOS, pair this with
  [[android-ios-parity]].
---

# Android app (`android/`)

A fully native **Kotlin + Jetpack Compose** app: no web view, no login, no token,
**no network.** All data is one serializable tree (`AppData`) saved to a single
`suite.json` in the app's private files dir, through a single `Store`. It is the
Android twin of the iOS app in `ios/` — same features, same behaviors, same type
and function names — built with Android idioms rather than shared code.

**This is a separate codebase.** It shares nothing with the PHP suite or the
Swift app; the three look alike on purpose. Don't reach for the network, a login,
a web view, or "reuse" of website code. Read `ios/README.md` and the iOS source —
it is the authoritative spec for what this app must do, and the web app
(`public/`, `lib/`) is the most complete reference for *behavior*.

## The two-layer split — mirror iOS exactly

Every platform here is **core** (data + behavior, no UI framework) plus **shell**
(the UI). Android keeps the split as two Gradle modules:

- **`core/`** — a pure **Kotlin/JVM** library (`java-library` + `kotlin("jvm")`,
  **no Android dependency**). Holds `Model.kt`, `Parse.kt`, `Store.kt`,
  `WatchPayload.kt` — a line-by-line cousin of `ios/Shared/`. It compiles and
  unit-tests on the plain JVM with no emulator, exactly as `ios/Shared/` tests
  headless via `swift test`. This is what makes side-by-side dev cheap.
- **`app/`** — the `com.android.application` module: Compose UI, one screen per
  tab, plus the `SuiteViewModel` that adapts `core`'s `Store` to Compose state
  and the disk. A cousin of `ios/App/`. Thin — it *calls* the core, it doesn't
  reimplement behavior.

Keeping `core` free of Android imports is the load-bearing rule: the moment a
sort or a repeat calculation needs an emulator to test, the twin suite stops
being a fast contract. Inject anything platform-specific (the file location, the
"data changed" callback) into `core` from `app`.

## Layout

```
android/
  settings.gradle.kts, build.gradle.kts, gradle/libs.versions.toml
  core/                                   # pure Kotlin/JVM — mirrors ios/Shared
    build.gradle.kts                      #   java-library, kotlin.jvm, kotlinx.serialization
    src/main/kotlin/com/seancheren/suite/core/{Model,Parse,Store,WatchPayload}.kt
    src/test/kotlin/com/seancheren/suite/core/{CoreTest,FeatureTest,SmokeTest}.kt   # twins of ios/Tests
  app/                                    # Compose app — mirrors ios/App
    build.gradle.kts                      #   com.android.application, compose
    src/main/AndroidManifest.xml
    src/main/kotlin/com/seancheren/suite/app/
      MainActivity.kt, RootScreen.kt, SuiteViewModel.kt, Theme.kt,
      RemindersScreen.kt, NotesScreen.kt, CalendarScreen.kt, HabitsScreen.kt, SettingsScreen.kt
  wear/                                   # (future) Wear OS read-only list — mirrors ios/Watch
```

Application id `com.seancheren.suite`, matching the iOS bundle id. Wear OS is the
Apple-Watch analogue and, like it, is **read-only and a later job** — the phone
owns the data and hands the wrist a ready-made `WatchList` (`Store.watchList()`).

## The `Store` pattern — the single writer

`Store` (in `core/`) is the source of truth and the only thing that loads/saves
the tree, mirroring the Swift `Store`. It stays framework-free: it holds
`var data: AppData`, exposes `load`/`save`, and after every mutation calls
`touch()`, which just fires an injected `onChange` listener. It does **not** own
the debounce, the coroutines, or Compose state — that is the `app/` layer's job:

```kotlin
// core: mutate data, then touch(). No disk write, no timer here.
fun toggle(reminder: Reminder) {
    val i = data.reminders.indexOfFirst { it.id == reminder.id }.takeIf { it >= 0 } ?: return
    // …a repeat rolls its due to the next date; a plain one flips done…
    touch()
}
```

`SuiteViewModel` (`app/`) wires it up: `store.onChange { rev++; scheduleSave() }`
bumps a Compose `mutableStateOf` revision (so screens recompose) and debounces the
save ~400 ms so typing a title doesn't rewrite the file per keystroke. Saves are
**atomic** (write a temp file, then `renameTo`) — the twin of the Swift
`.atomic` + `.sortedKeys` save. New data starts from `AppData.starter` (an
explicit-file `Store`, i.e. the tests) or `Store.sampleData()` (buddy's dinner
sample, the real first run), exactly as iOS chooses.

## kotlinx.serialization mirrors Swift `Codable`

- `@Serializable data class` for every model type, every field with a **default**.
  The `Json` is configured `encodeDefaults = true`, `prettyPrint = true`,
  `ignoreUnknownKeys = true`.
- **Tolerant decoding comes for free** here and must stay that way: with defaults
  on every field, a missing key falls back to its default, and
  `ignoreUnknownKeys` drops keys a newer version added — so a `suite.json`
  written before a field existed still loads instead of resetting to empty. This
  is the same guarantee iOS proves with `decodeIfPresent ?? default`; carry the
  twin test (`SmokeTest`/`FeatureTest`). **Never add a field without a default** —
  that reintroduces the crash the tolerance exists to prevent.
- Ids are `java.util.UUID` via a contextual `UuidSerializer`; dates are
  `java.time.LocalDate` (day-granular) with a separate `minutes: Int?` so moving a
  date can't move the time — the same split as Swift's day-granular `Date` +
  `minutes`. `GroupRef` is a sealed type with a custom serializer (see
  [[android-ios-parity]] for the exact type-by-type mapping).

## Compose conventions to match

- **Unidirectional data flow / state hoisting.** A screen reads
  `vm.rev` (to subscribe to changes) then queries `vm.store.…`, and calls
  `vm.store.…` mutators to change things; it holds no `remember`/`mutableStateOf`
  copy of model data that mirrors the store — derive it. Local-only UI state (the
  text being edited, which section is collapsed) belongs in the composable.
- Keep composables small and value-driven; push list logic into `core` helpers
  (`reminders(folder, group)`, `sorted(rows)`, `watchList()`) so Android and iOS
  share the same helper *shapes* and the Wear/watch lists come out identical.
- **Match the ported behaviors exactly** — they are specs, not accidents, and
  each already has an iOS test to copy: month/year repeats **day-clamp** (Jan 31
  → Feb 28, never Mar 3), **undated-first** ordering, the **slash-only US-order**
  parser, **two-press delete**, subtasks travel with their parent, the Habits
  week/month views. See [[android-ios-parity]] and [[cross-platform-architect]].
- Theme/colors go through one `Theme.kt` (dark `#111`/`#eee`/accent `#34d399`,
  pill controls) — don't hardcode a colour in a screen. Respect system insets the
  way the PWAs respect `env(safe-area-inset-*)`.
- Give `LazyColumn` items a stable `key = { it.id }` so reorders and ticks don't
  rebind the wrong row — the Compose analogue of SwiftUI's `Identifiable`.

## Building / checking a change

Nothing under `android/` is deployed — `deploy.sh` only sends `public/` and
`lib/`, so these builds are purely for your own verification. They need a JDK 17+
and the Android SDK (Android Studio provides both); the repo ships the Gradle
wrapper so no system Gradle is required.

```sh
cd android
./gradlew :core:test          # the JVM logic suite — the twin of `swift test`, seconds, no emulator
./gradlew :app:assembleDebug  # compile-check the Compose app — the twin of the xcodebuild build
./gradlew lint                # Android lint on the app module
```

`:core:test` is the fast feedback loop and the cross-platform contract: a
behavior change is only "done" when it and its iOS twin are both green. Prove a
Compose screen by running it on an emulator/device — Gradle only tells you it
compiled, exactly as the web harness can't see a gesture.

## Reference files (read on demand)

- **`references/gradle-project.md`** — the multi-module Gradle setup, the version
  catalog, why `core` must stay Android-free, the build/test commands, and what
  is (never) deployed.
- **`references/compose.md`** — Compose UI patterns for this app: state hoisting,
  the `SuiteViewModel` + revision pattern, `LazyColumn` lists and keys,
  long-press/drag/swipe gestures, and the traps that bite (recomposition, leaked
  state, mutating the store off the main thread).
