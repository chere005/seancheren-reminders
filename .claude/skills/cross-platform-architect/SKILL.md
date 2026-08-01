---
name: cross-platform-architect
description: >-
  Design and review systems so the same product can be built side by side across
  platforms (web, iOS, Android) with a shared or near-identical codebase. Use when
  adding a platform, deciding what to share vs mirror, keeping platforms in step as
  behavior changes, or reviewing a change for cross-platform parity. Grounded in this
  repo, where the PHP web suite and the native iOS/watch app deliberately mirror each
  other's ideas and behaviors while sharing no code — the goal is to make that mirroring
  cheap and hard to get wrong, and to extend it to Android the same way.
---

# Cross-platform architect

The aim: **one product, several platforms, minimal drift, cheap to keep in step.** Whether
two platforms literally share code or just mirror each other, a developer should move
between them and feel at home, and a behavior change should land everywhere in one motion.

## The one rule: split the **core** from the **shell**

Every platform is two layers:

- **Core** — the data model plus all *behavior*: how repeats step and clamp, how a list
  sorts, how text is parsed, what "done" does. Pure logic, **no UI framework, no network,
  no platform APIs** beyond dates/strings. This is what makes side-by-side dev cheap: it
  compiles and tests headless, with fast feedback and no browser/simulator.
- **Shell** — the UI and platform glue (SwiftUI, Compose, HTML/CSS/JS). Thin. It *calls*
  the core; it doesn't reimplement behavior.

In this repo: web = `lib/` (core) + `public/` (shell); iOS = `ios/Shared/` (core) +
`ios/App/` (shell). A third platform (Android) is a Kotlin core module + a Compose shell,
laid out the same way.

## Share code when you can; mirror structure when you can't

- **Same language / runtime →** share a real module. One implementation, many shells.
- **Different languages (the usual case) →** you can't share the code, so **share the
  shape**: same module boundaries, same type names, same function names, same test names.
  Diffs between platforms then read as translations, and a reviewer can hold two files
  side by side. In this repo `parse_when_from_text` (PHP) and `parseWhen` (Swift) are the
  same function twice; `Store.reminders(...)` mirrors the PHP readers.
- Don't force sharing across a boundary that fights it (a web view pretending to be
  native, an abstraction layer nobody likes). Parallel-but-idiomatic beats shared-but-awkward.

## Behavior is specified once, implemented per platform

Write the behavior down **once** — in prose and, above all, in **tests** — and implement it
identically everywhere. The deliberate behaviors here: month/year repeats **day-clamp**
(Jan 31 → Feb 28, never Mar 3), **undated-first** ordering, the **slash-only US-order**
parser, **two-press delete**, subtasks travel with their parent. Each is a spec, not an
accident; each platform matches it exactly.

**Tests are the cross-platform contract.** Each core carries a suite that encodes the same
cases, and the suites are near-mirror-images — `tools/test.php` (web) and
`ios/Tests/*` (iOS) test the same behaviors under parallel names. A behavior change is
"done" only when **every** platform's suite is green again. Keep the core dependency-light
so the suite runs in seconds without the UI (web: `php tools/test.php`; iOS: `swift test`).

## Keep the data model portable

- **One serialization format** the platforms agree on (here: plain JSON — the web's
  encrypted store and the iOS `suite.json` are the same tree). Value types, stable ids
  (UUID/string), day-granular dates with time as a separate field so moving a date can't
  move the time.
- **Tolerant deserialization** so platforms can evolve out of step: read missing fields as
  defaults (`decodeIfPresent ?? default`) rather than failing the whole document. A field
  one platform adds must not break another reading the file. There's a test for this.
- Model changes are a cross-platform event: add the field to every core, defaulted, with a
  backward-compat test, before any shell uses it.

## The workflow for a cross-platform change

1. Change (or add) the behavior in **one** core, with its test.
2. Port the same change + test to the other platforms' cores. Match names.
3. Wire each shell to the new core capability.
4. Run **every** platform's suite; all green.
5. Ship per each platform's own pipeline (see [[deploy-workflow-policy]] for the web).

If a change only touches a shell (a color, a layout), it's platform-local — but ask whether
the *intent* belongs in all shells (consistent UX) even though the code is separate.

## Reviewing for parity — quick checklist

- Does new behavior live in the **core**, not the shell? (If logic crept into a view, pull
  it down so the other platforms can mirror it.)
- Is there a **test** encoding the behavior, and does it have a twin on the other platform?
- Do the **names** match across platforms so the two files read as translations?
- Does the **serialized model** stay compatible (tolerant reads, defaulted new fields)?
- Is anything shared across a boundary that would be cleaner as parallel-idiomatic (or vice
  versa)?

## Adding Android (or any third platform)

Mirror the two-layer split: a **Kotlin core module** (data classes + logic + a JVM unit
suite) that is a line-by-line cousin of `ios/Shared/` and `lib/`, and a **Compose** shell
that is a cousin of `ios/App/` and `public/`. Same module names, same function names, same
test names. Port the behavior specs and their tests first; build the shell second. The core
should compile and test on the JVM with no Android framework, exactly as `ios/Shared`
compiles headless via `swift test`.
