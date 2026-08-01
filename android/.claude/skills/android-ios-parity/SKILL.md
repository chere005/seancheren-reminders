---
name: android-ios-parity
description: >-
  Keep the Android app (`android/`, Kotlin + Compose) and the iOS app (`ios/`,
  Swift + SwiftUI) clones of each other — same data model, same behavior, same
  names — so a change lands on both cheaply and a reviewer can read the two files
  side by side as translations. Use when porting a feature between iOS and
  Android, adding a model field, writing the twin of a test, or reviewing either
  app for drift. This is the Swift↔Kotlin translation craft on top of the general
  [[cross-platform-architect]] rules; the web app (`public/`, `lib/`) is the most
  complete behavior reference of the three.
---

# Android ⇄ iOS parity

The goal: **`android/` and `ios/` are the same app written twice.** They share no
code — one is Kotlin, the other Swift — so they share the *shape* instead: the
same module boundaries, the same type names, the same function names, the same
test names. A diff between `Store.kt` and `Store.swift` should read as a
translation, and a behavior change should be portable in one sitting.

Three implementations exist. **The web app (`public/` + `lib/`) is the most
complete and is the behavior source of truth; iOS (`ios/`) is the native
reference Android mirrors most closely** (both are local-only, no-network,
single-`Store` apps). When the three disagree, the web app is usually right about
*what* should happen; iOS shows *how* a native app expresses it.

## The layer map — what mirrors what

| Concept | Web (PHP) | iOS (Swift) | Android (Kotlin) |
| --- | --- | --- | --- |
| Data + behavior (core) | `lib/` | `ios/Shared/` | `android/core/src/main/kotlin/…/core/` |
| UI shell | `public/*/index.php` | `ios/App/` | `android/app/…/app/` |
| The document | JSON via `store_read/write` | `AppData` in `Model.swift` | `AppData` in `Model.kt` |
| The one writer | each page | `Store` (`Store.swift`) | `Store` (`Store.kt`) |
| Text parser | `parse_when_from_text` | `parseWhen` (`Parse.swift`) | `parseWhen` (`Parse.kt`) |
| Watch/widget list | `feed.php` | `WatchList` (`WatchPayload.swift`) | `WatchList` (`WatchPayload.kt`) |
| Core tests | `tools/test.php` | `ios/Tests/*` | `android/core/src/test/…` |

`Store.swift` and `Store.kt` carry the **same method names**: `toggle`, `add`,
`sorted`, `reminders(folder:group:)`, `addSubtask`, `setIndent`, `moveReminders`,
`events(on:)`, `reminders(on:today:)`, `habitMonthFill`, `markdown`, `watchList`,
… A method on one that has no twin on the other is drift — add it or explain it.

## The Swift → Kotlin translation table

The mechanics are in `references/swift-to-kotlin.md`; the essentials:

| Swift | Kotlin |
| --- | --- |
| `struct Foo: Codable` (value type) | `@Serializable data class Foo` |
| `enum E: String, Codable` | `enum class E { … }` (+ raw-value map if needed) |
| `enum G { case a; case g(UUID) }` (assoc. values) | `sealed interface G { object A; data class Grp(id) }` + custom serializer |
| `Codable`, `decodeIfPresent ?? default` | `@Serializable` + **a default on every field** + `ignoreUnknownKeys` (tolerant decode is then automatic) |
| `UUID` | `java.util.UUID` (contextual `UuidSerializer`) |
| day-granular `Date` (`.day`), `+ minutes` | `java.time.LocalDate`, `+ minutes: Int?` |
| `[T]` / `Set<T>` / `[K:V]` | `List<T>` / `Set<T>` / `Map<K,V>` |
| computed `var x: T { … }` | `val x: T get() = …` |
| `ObservableObject` + `@Published` | plain `Store` + `SuiteViewModel` (`mutableStateOf` revision) |
| `store.data.reminders[i].done.toggle()` | `var` fields + `MutableList` so the same in-place mutation reads across |
| SwiftUI `move(fromOffsets:toOffset:)` | the ported `MutableList.reorder(from, to)` in `core` |

## Behavior is specified once, matched everywhere

These are **specs, not accidents**. Each has a test on every platform; keep them
identical:

- Month/year repeats **day-clamp** (Jan 31 → Feb 28, never Mar 3).
- **Undated-first** ordering, then by date, done sinking; a **subtask travels with
  its parent** (the outline sort, not a flat sort).
- The **slash-only US-order** parser — `m/d`, `m/d/yy`, `m/d/yyyy`; a bare `m/d`
  is the next occurrence; `2/3 cup` parses as Feb 3 (the documented limitation).
- **Two-press delete**, no dialog.
- An undated **Calendar-group** reminder **rides along on today** and is never
  overdue.
- Ticking a **repeat** rolls its due to the next date instead of finishing it.
- Tolerant decode: an old document missing new keys **still loads**, defaulted,
  never resets to empty.

## Tests are the contract

Each core carries a suite encoding the same cases under **parallel names**, and a
behavior change is "done" only when **every** platform's suite is green. The
Android tests (`core/src/test/…`) are near-mirror-images of `ios/Tests/*`:
`testMonthRepeatClampsShortMonths`, `testOutlineSortKeepsASubtaskUnderItsParent`,
`testHabitFilterThreeGestures`, `testOldDocumentWithoutNewKeysStillLoads`, … Same
name, same setup, same assertion. When you add a case to one, add it to the other.

## The workflow for a parity change

1. Land the behavior (or field) in **one** core with its test.
2. Port the same change + test to the other core(s). **Match names.** Add the
   field to every model **defaulted**, with the backward-compat test.
3. Wire each shell (SwiftUI / Compose / PHP) to the new core capability.
4. Run **every** suite green: `php tools/test.php`, `swift test`,
   `./gradlew :core:test`.
5. Ship per each pipeline. Android and iOS aren't deployed by `deploy.sh`; the web
   follows [[deploy-workflow-policy]].

A change that only touches one shell (a color, a layout) is platform-local — but
ask whether the *intent* belongs in all three shells even though the code is
separate.

## Reviewing for parity — checklist

- Does new behavior live in the **core**, not a screen? (If logic crept into a
  view, pull it down so the other platform can mirror it.)
- Is there a **test** with a **twin name** on the other platform?
- Do the **type and function names** match, so the two files read as translations?
- Is the serialized model still **tolerant** (defaults on new fields,
  `ignoreUnknownKeys`) so old documents load?
- Did a Swift `struct`/`enum` map to the right Kotlin shape (`data class` /
  `enum class` / `sealed interface`)? See `references/swift-to-kotlin.md`.

## Reference files (read on demand)

- **`references/swift-to-kotlin.md`** — the full translation reference: value
  types, associated-value enums, `Codable` → kotlinx.serialization (UUID/date/
  sealed serializers, tolerant decode), computed properties, `ObservableObject` →
  ViewModel, the reorder helper, and the JSON-shape caveats.
