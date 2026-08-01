# Android

A native **Kotlin + Jetpack Compose** app — the Android cousin of the iOS app in
`../ios` and the web suite in `../public`/`../lib`. Reminders · Calendar · Notes ·
Habits, as native tabs. No web view, no login, no token, **no network**.

Open `android/` in Android Studio (Giraffe or newer), let it sync, and run the
`app` configuration on an emulator or device. From the CLI (JDK 17 + Android SDK):

```sh
cd android
./gradlew :core:test          # the pure-JVM logic suite — the twin of `swift test`, no emulator
./gradlew :app:assembleDebug  # compile + package the Compose app
./gradlew :app:installDebug   # onto a running emulator/device
```

> The Gradle **wrapper** (`gradlew` + jar) is committed and pinned to **Gradle 8.9**
> (the tested pairing for AGP 8.7.3). On a bare CLI, point it at a JDK 17 and the
> Android SDK — `export JAVA_HOME=<jdk17>` and `export ANDROID_HOME=<sdk>`, or an
> `android/local.properties` with `sdk.dir=…` (gitignored). Android Studio sets both.

## Completely independent of the website and the iOS app

The three look alike **on purpose** and share **no code**. This app is its own
native codebase with its own local data; changing it never touches the others.

- **All data is local.** One serializable tree (`AppData` in
  `core/…/Model.kt`) is saved to a single `suite.json` in the app's private files
  dir, through `Store` (`core/…/Store.kt`). Writes are debounced by the
  `SuiteViewModel`, so typing doesn't rewrite the file on every keystroke.
- **No accounts.** Nothing to sign into. On first run the app opens on "buddy's"
  dinner sample (so there's something to look at); **Settings → Erase all data**
  takes it back to one empty General folder each, one calendar, nothing in them.
- **Notes are plain text**, like the iOS app.

## The two-layer split (this is the whole design)

Mirrors iOS's `Shared` (core) + `App` (shell), and the web's `lib` (core) +
`public` (shell):

```
android/
  settings.gradle.kts, build.gradle.kts, gradle/libs.versions.toml
  core/   Kotlin/JVM library — NO Android dependency. The data + all behaviour.
          Model.kt · Parse.kt · Store.kt · WatchPayload.kt        (cousins of ios/Shared/*)
          src/test/…  CoreTest · FeatureTest · SmokeTest          (twins of ios/Tests/*)
  app/    Compose UI. Thin — it calls core, never reimplements behaviour.
          MainActivity · RootScreen · SuiteViewModel · Theme · Chrome
          RemindersScreen · NotesScreen · CalendarScreen · HabitsScreen · SettingsScreen
  wear/   (future) Wear OS read-only list — the cousin of ios/Watch
```

`core/` is a **plain JVM module with no Android dependency**, so its logic
compiles and unit-tests in seconds with no emulator — the same fast contract as
the website's `php tools/test.php` and iOS's `swift test`. The
`SuiteViewModel` is the only place that knows about Android: it gives `Store` the
file path, bumps a Compose revision on every change (so screens recompose), and
debounces the atomic save.

## Parity with iOS — the map

Everything here is a line-by-line translation of the Swift core, same type and
function names, so a change ports in one sitting. See the `android-ios-parity`
skill for the full Swift→Kotlin table; the essentials:

| iOS (Swift) | Android (Kotlin) |
| --- | --- |
| `struct … : Codable` | `@Serializable data class` (every field defaulted) |
| `enum GroupRef { case group(UUID) }` | `sealed interface GroupRef` + custom serializer |
| `UUID`, day-granular `Date` + `minutes` | `java.util.UUID`, `java.time.LocalDate` + `minutes` |
| `Store` (ObservableObject) | `Store` (framework-free) + `SuiteViewModel` |
| `parseWhen`, `Recurrence.step/dates/next`, `Store.reminders/sorted/watchList/markdown` | same names, same behaviour |

The deliberate behaviours are matched and tested identically on both sides:
month/year repeats **day-clamp** (Jan 31 → Feb 28), **undated-first** ordering, a
**subtask travels with its parent**, the **slash-only US-order** parser, ticking a
**repeat** rolls its date, an undated **Calendar** reminder **rides along on
today**, and **tolerant decoding** (an old `suite.json` missing new keys still
loads). Each has a twin test — `CoreTest`/`FeatureTest` mirror
`ios/Tests/CoreTests`/`FeatureTests`.

## Status

- **Verified building.** `./gradlew :core:test` (34 tests, all green) and
  `./gradlew :app:assembleDebug` (debug APK) both pass on JDK 17 + SDK 35.
- **`core/` + its tests are complete and faithful** — the parity contract.
- **The Compose UI is a first pass**: the main flows (folders, sections, add,
  toggle, two-press delete, the month grid + day panel, the habit week grid and
  month pies, plain-text notes, load-sample/erase) are wired to the real `Store`.
  Gestures and polish (long-press edit mode, drag-to-reorder, swipe-to-delete,
  per-section recolour, the shared top-bar picker) are the on-device refinement
  work, exactly as they are the by-eye column for the web and iOS.
- **Wear OS** is a later job, read-only, fed by `Store.watchList()` — mirroring
  the Apple Watch.

## Notes

- Nothing here is deployed. `deploy.sh` sends only `public/` and `lib/`.
- Application id `com.seancheren.suite`, matching the iOS bundle id. `minSdk 26`
  (so `java.time` is native), recent `compileSdk`/`targetSdk`.
- Info/build config is in `app/build.gradle.kts`; versions in
  `gradle/libs.versions.toml`. Add a dependency only after checking core + Compose
  can't already do it — iOS has none beyond the SDK.
