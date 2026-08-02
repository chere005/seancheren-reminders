# The behavior spec — one contract, two replaying cores, one reference

These JSON vectors are the **shared source of truth** for the behaviors the mobile cores
must implement identically to the web: iOS (Swift, `ios/Shared/`) and Android (Kotlin,
`android/core/`) load these files and replay every case —

- iOS — `ios/Tests/SpecTests.swift` (`swift test`)
- Android — `android/core/src/test/…/SpecTest.kt` (`./gradlew :core:test`)

**The web is the reference, not a replayer.** The vectors were transcribed from what
`lib/util.php` actually does (it passed every one untouched when they were written), and
the web's own suite (`php tools/test.php`) keeps covering those behaviors natively — its
files carry no spec harness, so the finished web app stays exactly as it is.

**Changing a behavior starts here.** Amend the vector, then make both mobile suites green
and check the web's own suite still encodes the same expectation. A case added on one
platform's suite alone is drift waiting to happen. Dates are `YYYY-MM-DD` strings, times
`HH:MM` (24-hour) — the storage shapes — and each harness converts to its native types at
the edge.

## `parse.json` — the slash-only US-order text parser

`parse_when_from_text` (PHP) = `parseWhen` (Swift, Kotlin). Each case:

```json
{ "name": "…", "input": "Vet 8/3 2pm", "today": "2026-08-01",
  "text": "Vet", "date": "2026-08-03", "time": "14:00" }
```

`today` anchors the bare-`m/d` next-occurrence rule so the case is deterministic;
`date`/`time` are `null` when nothing should be lifted out. The documented limitation is
itself a case: `2/3 cup` parses as February 3. An **invalid calendar date** (`2/30`,
`2/29` in a non-leap year) lifts nothing and leaves the text untouched.

## `repeats.json` — repeat stepping, expansion and rolling

`repeat_step` / `repeat_dates` / `repeat_next` and their mobile twins. Month and year
steps keep the day of the month and **clamp** it to the target month (Jan 31 repeats
monthly as Feb 28, never Mar 3). `next` cases are strictly-after (ticking a repeat rolls
to the next occurrence). A window with `to < from` is empty.

## `sort.json` — the outline sort inside a section

`sort_by_date` (PHP, in the Reminders page) and the mobile `Store`s' outline read
(`reminders(folder:group:)`). Rows sort in **outline blocks**: a top-level row carries
every `indent > 0` row that follows it in stored order. Blocks order undated-first, then
by date, stored order breaking ties. Every vector here uses open rows only, because
**sinking completed rows is a display rule, not an ordering rule**: the visible outcome —
done at the bottom — is identical everywhere, but the web leaves the data alone and sinks
them with CSS, while the mobile comparators add a done-first key (they have no CSS layer).
