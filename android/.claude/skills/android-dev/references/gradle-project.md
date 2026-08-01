# The Gradle project (`android/`)

A two-module Gradle build with the Kotlin DSL (`.kts`). The whole point of the
split is that **`core` is a plain JVM library with no Android dependency**, so its
logic compiles and unit-tests in seconds without an emulator — the twin of the
website's `php tools/test.php` and iOS's `swift test`.

## Modules

- **`:core`** — `id("java-library")` + `kotlin("jvm")` +
  `kotlin("plugin.serialization")`. Depends only on the Kotlin stdlib and
  `kotlinx-serialization-json`. **No `com.android.*` plugin, no `android {}`
  block, no `androidx.*` dependency.** If you find yourself importing anything
  Android here, it belongs in `:app` instead. Uses `java.time` (`LocalDate`)
  freely — it's the desktop JVM.
- **`:app`** — `id("com.android.application")` + `kotlin("android")` +
  `kotlin("plugin.serialization")` (for any app-level serialization) + Compose.
  `depends on project(":core")`. `minSdk 26` (so `java.time` is available
  natively, no desugaring), a recent `compileSdk`/`targetSdk`. Application id
  `com.seancheren.suite`.

## The version catalog

Versions live in `gradle/libs.versions.toml` so the two modules can't drift and a
bump is one edit. Reference them as `libs.kotlinx.serialization.json`, etc. Keep
the Compose BOM (`androidx.compose:compose-bom`) as the single source of Compose
versions; pull individual Compose artifacts without their own version so the BOM
pins them together.

## Building and testing

```sh
cd android
./gradlew :core:test           # JVM unit suite — the parity contract, no emulator
./gradlew :app:assembleDebug   # compile + package the Compose app (debug)
./gradlew :app:installDebug    # onto a running emulator/device
./gradlew lint                 # Android lint
./gradlew build                # everything
```

Needs a JDK 17+ and the Android SDK. Android Studio ships both and writes
`local.properties` with `sdk.dir=…` on first open (that file is machine-local and
**gitignored** — never commit it). From a bare CLI, set `ANDROID_HOME` /
`ANDROID_SDK_ROOT` or create `local.properties` by hand. The Gradle **wrapper**
(`gradlew`, `gradle/wrapper/`) is committed, so no system Gradle is needed — the
first run downloads the pinned Gradle.

`:core:test` needs **no** SDK (it's pure JVM), so it's the fastest way to prove a
behavior change and the one to run on every edit to `core/`.

## What's committed vs generated

- **Committed:** all `*.gradle.kts`, `gradle/libs.versions.toml`,
  `gradle/wrapper/gradle-wrapper.properties`, `gradlew`/`gradlew.bat`, the source
  and tests, `AndroidManifest.xml`, resources.
- **Gitignored:** `.gradle/`, `build/`, `local.properties`, `*.iml`, `.idea/`
  (bar shared bits), `captures/`. There's an `android/.gitignore` for this.
- **`gradle-wrapper.jar`** is part of the wrapper; if it's missing, regenerate the
  wrapper with a system Gradle (`gradle wrapper --gradle-version <v>`) or copy it
  from another project — a wrapper without its jar can't bootstrap.

## Never deployed

`deploy.sh` sends only `public/` and `lib/` to the server. Nothing under
`android/` ships anywhere from this repo — these builds are purely local
verification, exactly like `ios/`. Don't wire the Android build into `deploy.sh`.

## Adding a dependency — resist it

The app is deliberately dependency-light: Kotlin, Compose, kotlinx.serialization,
and the AndroidX essentials (activity-compose, lifecycle-viewmodel-compose). Before
adding anything, ask whether `core` + Compose already do it. A new dependency is a
cross-platform question too — iOS has none beyond the SDK, so a behavior that
needs a library on Android probably wants rethinking, not the library.
