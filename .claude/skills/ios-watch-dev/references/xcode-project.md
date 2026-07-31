# Editing `Seancheren.xcodeproj` by hand

The project is **hand-maintained** with stable numeric ids — treat `project.pbxproj`
as source you edit deliberately, not a black box Xcode regenerates. Info.plists
are generated from build settings, so there are no `.plist` files to edit.

## Adding a source file (the four edits)

For a new Swift file to compile, `project.pbxproj` needs, all consistent:

1. A **`PBXFileReference`** — declares the file exists (path + type).
2. A **`PBXBuildFile`** — says "compile this reference."
3. A line in the correct **`PBXGroup`** — where it shows in the navigator (match
   the folder: `App`, `Watch`, or `Shared`).
4. A line in the target's **`Sources` build phase** (`PBXSourcesBuildPhase`).

**A file shared between phone and watch** (anything in `Shared/`) needs a
**second `PBXBuildFile`** and a line in **both** targets' `Sources` phases —
otherwise only one target sees it and the other fails to compile. `WatchPayload.swift`
is the canonical example: it's in both.

Give new ids numbers that don't collide with existing ones and keep the pattern
of the surrounding entries. After editing, open the project (or run `xcodebuild`)
to confirm it parses — a malformed pbxproj fails with an opaque error.

## Targets, bundle ids, and the watch suffix

- Two targets: `Seancheren` (iOS) and `SeancherenWatch` (watchOS), the watch
  embedded in the phone app — that's why they're one project. Building/running
  `Seancheren` installs both.
- Bundle ids: `com.seancheren.suite` (phone) and
  `com.seancheren.suite.watchkitapp` (watch). **The watch id must stay a
  `.watchkitapp` suffix of the phone's** — break that relationship and the pair
  won't install together.
- Deployment targets: iOS 17+, watchOS 10+. Don't raise a minimum without reason.

## Info.plist is generated — change build settings

`GENERATE_INFOPLIST_FILE = YES`, so plist values come from **build settings**, not
a file. To set something plist-shaped, edit the `INFOPLIST_KEY_*` build settings
(e.g. `INFOPLIST_KEY_UILaunchScreen_Generation`, display name, supported
orientations). Adding a raw capability that needs a real plist key means adding
the matching `INFOPLIST_KEY_…` (or a custom entry) in build settings.

## Signing & capabilities

- Under **Signing & Capabilities**, set your development **team on both targets**
  before running on device (Automatically manage signing is fine for local dev).
- Add a capability (e.g. HealthKit, notifications) to the target that uses it;
  that writes an `.entitlements` file and a build-setting reference — keep both.

## Building from the CLI

To typecheck/build without opening Xcode (fastest feedback that Swift compiles):

```sh
cd ios
xcodebuild -project Seancheren.xcodeproj -scheme Seancheren \
  -destination 'generic/platform=iOS' build      # or an installed simulator

# List what's available if a scheme/destination name is uncertain:
xcodebuild -list -project Seancheren.xcodeproj
xcrun simctl list devices
```

Signing can block a device build from the CLI; target a simulator destination
(`-destination 'platform=iOS Simulator,name=iPhone 15'`) to just confirm it
compiles. Nothing in `ios/` is deployed by `deploy.sh`, so CLI builds are purely
for your own verification.

## Don't

- Don't let Xcode "fix" or reorder the whole pbxproj in a way that explodes the
  diff — keep changes surgical and reviewable.
- Don't add a networking layer, a login, or a dependency on the PHP suite. This
  app is intentionally standalone and offline.
- Don't add third-party packages casually — there's no package manager set up
  here and the app is deliberately dependency-free.
