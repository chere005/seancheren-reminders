# Web ↔ iOS test parity

The website's suite (`tools/test.php`) and the native app's suite (`ios/Tests/*`) are meant
to be near mirror-images: the same behaviours, encoded under parallel names, so a change
lands everywhere. This file walks **every** web test section and records the iOS status, so
a behaviour that exists in neither list can't hide.

Legend:

- ✅ **covered** — an iOS test exercises the same behaviour (test named).
- 🟰 **covered differently** — the app achieves the same behaviour through a different
  model, so the web's exact test doesn't translate 1:1 (noted).
- 👁 **by-eye** — a gesture / rendering the headless suite can't drive (true on *both*
  platforms; the web can't run JS either).
- ⛔ **out of scope** — a web-only concern the native app deliberately doesn't have
  (no network, no login, no server).
- ⚠️ **gap** — a real difference worth a decision; listed again at the bottom.

## The parity-relevant sections (the app's four screens + the core)

### 4. reminders
| web behaviour | iOS |
|---|---|
| add a reminder into a folder/section, undated | ✅ `testTargetFolder`, `testGroupAddRenameDeleteEmptiesToInbox` |
| a date/time typed into the text is parsed out | ✅ `testParseTimeAndDate` (+ `AddView`/`RemindersView` use `parseWhen`) |
| ticking a plain reminder marks it done | ✅ `testToggleFinishesAPlainReminderButRollsARepeat` |
| ticking a repeat rolls it forward instead | ✅ same test; `testRepeatNextRollsPastToday` |
| editing a reminder's text | ✅ `store.update` via `testMarkdownExport` (and `testUnknownIdIsANoOp`) |
| deleting needs the confirmed second press | 👁 the two-press lives in the view's `armed` state, not the Store |
| sections: add, rename, delete → items fall to inbox | ✅ `testGroupAddRenameDeleteEmptiesToInbox` |
| the subtask **+** makes a child, doesn't indent the parent | ✅ `testAddSubtaskSlotsUnderParentAtIndentOne` |
| a subtask lifts back out to a task | ✅ `testSetIndentIsOneLevelOnly` |
| a section can never be indented | 🟰 iOS sections are `ListGroup`s, not indentable rows — impossible by construction |
| `clear_done` removes only the ticked rows | ✅ **added** `testClearDoneRemovesOnlyTheTickedRows`, `testClearDoneIsScopedToTheFolderInView` (+ `store.clearDone`, the Reminders menu's "Clear completed") |
| list renders undated-first, then oldest date | ✅ `testSortedUndatedFirstThenByDateThenDoneSinks` |

### 5. folders
| web behaviour | iOS |
|---|---|
| add / delete a folder, items fall back | ✅ `testAddFolderDedupesAndColoursByPosition`, `testDeleteFolderMovesItemsToFallbackNeverTheLast` |
| the permanent folders can't be deleted | 🟰 iOS models Calendar/Reminders as permanent **groups** (`GroupRef.calendar/.inbox`), not folders; the "never the last folder" guard is tested |
| a folder colour must come from the palette | 🟰 iOS colours are palette **indices** — off-palette is impossible by construction |
| picker box / row / All visibility (three gestures) | ⚠️ **gap** — iOS folder picker is single-select (All / one folder), no per-folder show/hide |
| the default folder is where a new item lands on All | ✅ `testTargetFolder` |
| the folder heading wears its colour as a wash | 👁 rendering |

### 6. notes
| web behaviour | iOS |
|---|---|
| adding a note opens it in the editor | 👁 `newNote` adds+opens (view) |
| a note carries folder, section **and date**, can be deleted | ✅ `testNoteCarriesFolderGroupAndDate`, `testNotesOnDay` (date **added** this pass) |
| note sections add/rename/delete per folder | ✅ shares the group machinery — `testGroupAddRenameDeleteEmptiesToInbox` |
| a note body is sanitised (`rt_sanitize`, allowlist) | 🟰 iOS notes are **plain text** — nothing is rendered, so nothing to sanitise |
| the "Notes" catch-all name is reserved | 🟰 iOS catch-all is the `nil` group, not a named row — no name to collide |
| a note folder colour from the notes palette | 🟰 index-based |

### 7. calendar
| web behaviour | iOS |
|---|---|
| the day panel groups by day | 👁 `CalendarView`; core reads tested (`events/reminders/notes(on:)`) |
| a day's reminders sort undated-first, oldest, **then time** | ✅ `testCalendarDaySortsUndatedFirstThenDateThenTime` (`reminders(on:)` adds the time tiebreak) |
| events before reminders within a day | 👁 the day panel renders collapsible kind groups — Events → Reminders → Notes — the web's `.dp-group`, folded state remembered per kind |
| an undated Calendar rider shows on today, not overdue | ✅ `testOverdueAndRidesAlong`, `testRemindersOnDayCollectsDueOverdueAndRiders` |
| add an event from the day panel | ✅ `testEventsOnDayWithRepeatAndScope` (+ the single **+ Add** menu, this pass) |
| edit / delete a calendar item (event deleted outright) | 👁 via `EventDetail`; `store.delete` covered elsewhere |
| deleting a reminder/note from the calendar only unschedules it | ⚠️ **gap** — iOS deletes it from its list (no unschedule-in-place) |
| calendars: add, recolour, default, delete | ✅ `testCalendarAddSetScopeAndDelete` (recolour/default are view actions) |
| tapping a calendar row leaves only it showing | 🟰 iOS calendar scope is single-select (`calSel`), like the folder picker |
| ticking a reminder from the calendar rolls a repeat | ✅ `testToggleFinishesAPlainReminderButRollsARepeat` (shared `store.toggle`) |

### 8. habits
| web behaviour | iOS |
|---|---|
| ticking a day | ✅ `testHabitToggleAndGrouping` |
| habits add / rename / delete | ✅ add+toggle tested; rename via `updateHabit`, delete via `deleteHabit` |
| a section colour from the palette, echoed back | ✅ `testSectionColoursByPositionThenBySetter` (+ the fixed swatch picker + `testUngroupedHabitColour`) |
| both views render and draw real cells | 👁 rendering |
| the section manager / reorder sections without disturbing habits | ⚠️ **gap** — iOS has no section (group) reordering |
| the last section is undeletable | 🟰 iOS always keeps the `nil` "Habits" bucket, so there is always a section |
| deleting a section leaves its habits ungrouped | ✅ **added** `testDeletingAHabitSectionLeavesItsHabitsUngrouped` |
| month view counts a day against ticked habits | ✅ `testHabitMonthFillCountsBySection` |
| a day's pie is drawn in section colours | ✅ same test + `countedSections` |
| the week grid pages whole weeks | 👁 `weekDays` |
| the filter's three gestures; changes pies not the grid | ✅ `testHabitFilterThreeGestures` |
| the chosen view is remembered per user | 🟰 `AppData.habitsMonth` persists it (round-trip covered by `testSaveAndReadRoundTrip`) |

### 9. the Add app
| web behaviour | iOS |
|---|---|
| Add a reminder into the chosen folder/section | 👁 `AddView`; targeting tested via `testTargetFolder` |
| Add an event into the chosen calendar (undated → today) | 👁 `AddView`; `Event.date` defaults to today |
| Add a note into the chosen note folder (**+ date**) | 👁 `AddView` (optional date **added** this pass) |
| a bogus destination falls back rather than trusted | 🟰 iOS pickers only offer real folders/calendars — can't pick a ghost |
| Add reads date/time out of the line | ✅ `testParseTimeAndDate` |

### 12 & 21. lib units
| web behaviour | iOS |
|---|---|
| the parser is slash-only, US-order | ✅ `testParseTimeAndDate`, `testBareDateIsNextOccurrence` |
| a date-like fraction is a documented limitation | ✅ `testFractionParsesAsADateAsDocumented` |
| month repeats clamp the day | ✅ `testMonthRepeatClampsShortMonths` |
| year repeats clamp a leap day | ✅ **added** `testYearRepeatClampsALeapDay` |
| `repeat_next` moves to the next occurrence | ✅ `testRepeatNextRollsPastToday` |
| times parse in every documented shape (9am, 12:05am…) | ✅ **strengthened** `testParseTimeAndDate` |
| dates parse in every documented shape | ✅ `testParseTimeAndDate`, `testBareDateIsNextOccurrence` |
| a repeat spec is cleaned or refused | 🟰 iOS `Recurrence.Unit` is an enum — an unknown unit can't exist |
| folder names are cleaned (trim, collapse, strip control, clip 40) | ⚠️ **gap** — iOS `addFolder`/`addGroup` only trim ends |
| folders reorder and keep every folder | ⚠️ **gap** — no folder reordering in iOS |
| folder tint / plus-SVG / kind-CSS / escaping-on-output | ⛔ web rendering & XSS — N/A to a native app |

### 20. edges
| web behaviour | iOS |
|---|---|
| an unknown id is a no-op, not a crash | ✅ **added** `testUnknownIdIsANoOp` |
| a malformed JSON payload is ignored | 🟰 iOS reorders via typed `IndexSet`, never parses posted JSON |
| unicode + long text survive a round trip | ✅ strings are native/`Codable`; ⚠️ iOS does **not** clip to 500/200 chars |
| an empty / whitespace-only add is refused | 👁 guarded in the views (`AddView`, `commit`); the Store allows empty on purpose (blank subtasks) |
| the same section name in two folders | 🟰 iOS groups are **global** across folders, not per-folder |

## Out of scope (web-only — the native app has no network, login or server)
Sections **1 seeding-parity, 2 auth, 3 storage/encryption, 10 sharing, 11 widget/api,
13 pages, 15 security sweeps, 19 widget feed**, and the tail (`sign-up`, `settings window`,
`token auth`, `chat`, `Aki's Bookshelf`, `recolour a share`, `public front`,
`quick add / widget tick`, `deploy script`) all cover things the native app deliberately
doesn't do. The watch hand-off is the native analogue of the widget and is exercised by
`testWatchListHasOpenItemsInGroupsAndDropsEmpties`.

## Open gaps (⚠️) — decisions for later
These are genuine differences, not oversights. None is a bug; each is a scope call:

1. **Multi-folder / multi-calendar visibility.** The web's picker has per-item show/hide with
   the three-gesture "All" master; iOS uses a single-select picker. Adding it is a real
   feature + model change (a `hidden` set), not just a test.
2. **No section (group) or folder reordering.** iOS reorders rows within a section, not the
   sections or folders themselves. `store.moveFolders/moveGroups` + drag UI would close it.
3. **Delete-from-calendar unschedules on the web; iOS deletes.** Different mental model.
4. **No text-length cap (500/200) or control-char scrub on names.** Minor input hygiene the
   web enforces server-side; a native keyboard makes it far less pressing.

Deep, deliberate model differences (not gaps to "fix"): permanent Calendar/Reminders as
**groups** not folders; **global** sections rather than per-folder; **plain-text** notes;
**index** colours rather than validated hex.
