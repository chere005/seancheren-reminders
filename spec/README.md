# The behavior spec — one contract, three implementations

These JSON vectors are the **shared source of truth** for the behaviors every platform
must implement identically: the web suite (PHP, `lib/`), iOS (Swift, `ios/Shared/`) and
Android (Kotlin, `android/core/`). The three cores share no code — they share *this*.

Each platform's suite loads these files and replays every case:

- web — `tools/test.php`, the `spec` area
- iOS — `ios/Tests/SpecTests.swift` (`swift test`)
- Android — `android/core/src/test/…/SpecTest.kt` (`./gradlew :core:test`)

**Changing a behavior starts here.** Add or amend a case, then make all three suites
green. A case added on one platform's suite alone is drift waiting to happen; a case
added here is enforced everywhere. Dates are `YYYY-MM-DD` strings, times `HH:MM`
(24-hour) — the storage shapes — and each harness converts to its native types at the
edge.

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

`sort_by_date` (PHP, `lib/util.php`) and the mobile `Store`s' outline read
(`reminders(folder:group:)`). Rows sort in **outline blocks**: a top-level row carries
every `indent > 0` row that follows it in stored order. Blocks order undated-first, then
by date, stored order breaking ties. Every vector here uses open rows only, because
**sinking completed rows is a display rule, not an ordering rule**: the visible outcome —
done at the bottom — is identical everywhere, but the web leaves the data alone and sinks
them with CSS, while the mobile comparators add a done-first key (they have no CSS layer).
