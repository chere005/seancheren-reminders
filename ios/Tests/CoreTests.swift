import XCTest
@testable import SuiteCore

/// The suite's logic, exercised the way tools/test.php exercises the website: build a
/// fresh store over a throwaway file, drive the real Store/Model/Parse, assert on what
/// comes back. No SwiftUI here — this is the layer the whole app rests on.
@MainActor
final class CoreTests: XCTestCase {

    // A store backed by a temp file, so a test can never touch the real suite.json.
    private func freshStore() -> Store {
        let url = FileManager.default.temporaryDirectory
            .appendingPathComponent("suitecore-\(UUID().uuidString).json")
        return Store(file: url)
    }

    private let cal = Calendar.current
    private func day(_ y: Int, _ m: Int, _ d: Int) -> Date {
        cal.date(from: DateComponents(year: y, month: m, day: d))!.day
    }

    // MARK: - Repeats

    func testRepeatStepsByUnit() {
        let start = day(2026, 1, 10)
        XCTAssertEqual(Recurrence(n: 3, unit: .day).step(start), day(2026, 1, 13))
        XCTAssertEqual(Recurrence(n: 2, unit: .week).step(start), day(2026, 1, 24))
        XCTAssertEqual(Recurrence(n: 1, unit: .month).step(start), day(2026, 2, 10))
        XCTAssertEqual(Recurrence(n: 1, unit: .year).step(start), day(2027, 1, 10))
    }

    func testMonthRepeatClampsShortMonths() {
        // The 31st repeats as the last day of a shorter month, not the 1st of the next.
        XCTAssertEqual(Recurrence(n: 1, unit: .month).step(day(2026, 1, 31)), day(2026, 2, 28))
        XCTAssertEqual(Recurrence(n: 1, unit: .month).step(day(2026, 3, 31)), day(2026, 4, 30))
        // A leap February keeps the 29th.
        XCTAssertEqual(Recurrence(n: 1, unit: .month).step(day(2024, 1, 31)), day(2024, 2, 29))
    }

    func testYearRepeatClampsALeapDay() {
        // Feb 29 + 1 year has no Feb 29 to land on, so it clamps to Feb 28, never Mar 1.
        XCTAssertEqual(Recurrence(n: 1, unit: .year).step(day(2024, 2, 29)), day(2025, 2, 28))
    }

    func testRepeatDatesExpandOverWindow() {
        let rule = Recurrence(n: 1, unit: .week)
        let dates = rule.dates(start: day(2026, 1, 1), from: day(2026, 1, 1), to: day(2026, 1, 31))
        XCTAssertEqual(dates, [day(2026, 1, 1), day(2026, 1, 8), day(2026, 1, 15),
                               day(2026, 1, 22), day(2026, 1, 29)])
        // A window before the start yields nothing.
        XCTAssertTrue(rule.dates(start: day(2026, 2, 1), from: day(2026, 1, 1), to: day(2026, 1, 31)).isEmpty)
    }

    func testRepeatNextRollsPastToday() {
        let rule = Recurrence(n: 1, unit: .day)
        let next = rule.next(from: day(2026, 1, 1), after: day(2026, 1, 5))
        XCTAssertEqual(next, day(2026, 1, 6), "the next occurrence strictly after the given day")
    }

    // MARK: - Reminder flags

    func testOverdueAndRidesAlong() {
        let today = day(2026, 6, 15)
        var r = Reminder(text: "late", due: day(2026, 6, 10))
        XCTAssertTrue(r.overdue(today: today))
        r.done = true
        XCTAssertFalse(r.overdue(today: today), "done is never overdue")

        var rider = Reminder(text: "milk", due: nil, group: .calendar)
        XCTAssertTrue(rider.ridesAlong)
        rider.due = today
        XCTAssertFalse(rider.ridesAlong, "a dated Calendar item doesn't ride along")
    }

    // MARK: - Sort order

    func testSortedUndatedFirstThenByDateThenDoneSinks() {
        let store = freshStore()
        let undated = Reminder(text: "u", due: nil, order: 5)
        let early   = Reminder(text: "e", due: day(2026, 1, 2), order: 1)
        let late    = Reminder(text: "l", due: day(2026, 1, 9), order: 2)
        let doneOne = Reminder(text: "d", due: day(2026, 1, 1), done: true, order: 0)
        let out = store.sorted([late, doneOne, early, undated]).map(\.text)
        XCTAssertEqual(out, ["u", "e", "l", "d"], "undated, then by date; done sinks to the end")
    }

    // MARK: - Toggle

    func testToggleFinishesAPlainReminderButRollsARepeat() {
        let store = freshStore()
        var plain = Reminder(text: "plain")
        store.add(plain)
        plain = store.data.reminders.first { $0.text == "plain" }!
        store.toggle(plain)
        XCTAssertTrue(store.data.reminders.first { $0.text == "plain" }!.done)

        let due = day(2020, 1, 1)   // safely in the past, so next() rolls forward from today
        var rep = Reminder(text: "rep", due: due, recurrence: Recurrence(n: 1, unit: .day))
        store.add(rep)
        rep = store.data.reminders.first { $0.text == "rep" }!
        store.toggle(rep)
        let after = store.data.reminders.first { $0.text == "rep" }!
        XCTAssertFalse(after.done, "a repeat is never marked done")
        XCTAssertGreaterThan(after.due!, due, "its due date rolled forward instead")
    }

    // MARK: - Folders

    func testAddFolderDedupesAndColoursByPosition() {
        let store = freshStore()
        let before = store.data.folderList(.reminder).count
        store.addFolder("Work", kind: .reminder)
        store.addFolder("work", kind: .reminder)   // case-insensitive dupe, ignored
        XCTAssertEqual(store.data.folderList(.reminder).count, before + 1)
        XCTAssertEqual(store.data.folderList(.reminder).last?.color, before % 10)
    }

    func testDeleteFolderMovesItemsToFallbackNeverTheLast() {
        let store = freshStore()
        store.addFolder("Work", kind: .reminder)
        let work = store.data.folderList(.reminder).first { $0.name == "Work" }!
        store.add(Reminder(text: "task", folder: work.id))
        store.deleteFolder(work)
        XCTAssertNil(store.data.folderList(.reminder).first { $0.id == work.id }, "folder gone")
        XCTAssertEqual(store.data.reminders.first { $0.text == "task" }?.folder,
                       store.data.folderList(.reminder).first?.id, "its task moved to the fallback")
        // The last remaining folder can't be deleted.
        let last = store.data.folderList(.reminder).first!
        store.deleteFolder(last)
        XCTAssertEqual(store.data.folderList(.reminder).count, 1, "the last folder is undeletable")
    }

    func testTargetFolder() {
        let store = freshStore()
        let def = store.data.defaultFolder[ItemKind.reminder.rawValue]
        XCTAssertEqual(store.target(.reminder, viewing: nil), def, "on All, new items land in the default")
        let some = UUID()
        XCTAssertEqual(store.target(.reminder, viewing: some), some, "in a folder, they land there")
    }

    // MARK: - Groups

    func testGroupAddRenameDeleteEmptiesToInbox() {
        let store = freshStore()
        store.addGroup("Errands", kind: .reminder)
        let g = store.data.groupList(.reminder).first { $0.name == "Errands" }!
        store.add(Reminder(text: "x", group: .group(g.id)))
        store.renameGroup(g.id, to: "Chores")
        XCTAssertEqual(store.data.groups.first { $0.id == g.id }?.name, "Chores")
        store.deleteGroup(store.data.groups.first { $0.id == g.id }!)
        XCTAssertNil(store.data.groups.first { $0.id == g.id }, "group gone")
        XCTAssertEqual(store.data.reminders.first { $0.text == "x" }?.group, .inbox,
                       "its reminder fell back to the ungrouped catch-all")
    }

    // MARK: - Calendars

    func testCalendarAddSetScopeAndDelete() {
        let store = freshStore()
        store.addCalendar("Work")
        store.addCalendar("Home")
        let work = store.calendarsOnly.first { $0.name == "Work" }!
        let home = store.calendarsOnly.first { $0.name == "Home" }!
        store.addSet("Both", members: [work.id, home.id])
        let set = store.calSets.first { $0.name == "Both" }!

        XCTAssertNil(store.calScope(nil), "no selection means every calendar")
        XCTAssertEqual(store.calScope(work.id), [work.id])
        XCTAssertEqual(store.calScope(set.id), Set([work.id, home.id]), "a set expands to its members")

        store.add(Event(text: "meeting", date: day(2026, 5, 1), cal: work.id))
        store.deleteCalendar(work)
        XCTAssertNil(store.calendarsOnly.first { $0.id == work.id }, "calendar gone")
        XCTAssertTrue(store.data.events.isEmpty, "its events went with it")
        XCTAssertFalse(store.calScope(set.id)?.contains(work.id) ?? false, "and it's scrubbed from the set")
    }

    // MARK: - Events on a day

    func testEventsOnDayWithRepeatAndScope() {
        let store = freshStore()
        store.addCalendar("Work")
        let work = store.calendarsOnly.first { $0.name == "Work" }!
        let other = store.calendarsOnly.first { $0.id != work.id }!
        store.add(Event(text: "standup", date: day(2026, 3, 2),
                        cal: work.id, recurrence: Recurrence(n: 1, unit: .day)))
        store.add(Event(text: "one-off", date: day(2026, 3, 5), cal: other.id))

        let all = store.events(on: day(2026, 3, 5)).map(\.text)
        XCTAssertTrue(all.contains("standup") && all.contains("one-off"), "repeat expands onto the day")

        let scoped = store.events(on: day(2026, 3, 5), scope: [work.id]).map(\.text)
        XCTAssertEqual(scoped, ["standup"], "the scope narrows to one calendar")
    }

    // MARK: - Reminders on a day

    func testRemindersOnDayCollectsDueOverdueAndRiders() {
        let store = freshStore()
        let today = day(2026, 7, 20)
        store.add(Reminder(text: "due-today", due: today))
        store.add(Reminder(text: "overdue", due: day(2026, 7, 1)))
        store.add(Reminder(text: "rider", due: nil, group: .calendar))
        store.add(Reminder(text: "future", due: day(2026, 7, 25)))

        let onToday = store.reminders(on: today, today: today).map(\.text)
        XCTAssertTrue(onToday.contains("due-today"), "its own date")
        XCTAssertTrue(onToday.contains("overdue"), "an overdue one rides onto today")
        XCTAssertTrue(onToday.contains("rider"), "an undated Calendar item rides along")
        XCTAssertFalse(onToday.contains("future"), "a future one does not")

        let laterDay = day(2026, 7, 25)
        let onLater = store.reminders(on: laterDay, today: today).map(\.text)
        XCTAssertEqual(onLater, ["future"], "a future day shows only what's due then")
    }

    func testNotesOnDay() {
        let store = freshStore()
        store.add(Note(title: "n1", date: day(2026, 8, 3)))
        store.add(Note(title: "n2", date: day(2026, 8, 4)))
        store.add(Note(title: "undated"))                      // no date: rides no day
        XCTAssertEqual(store.notes(on: day(2026, 8, 3)).map(\.title), ["n1"])
        XCTAssertFalse(store.notes(on: day(2026, 8, 4)).contains { $0.title == "undated" },
                       "an undated note never lands on the calendar")
    }

    func testNoteCarriesFolderGroupAndDate() {
        let store = freshStore()
        store.addFolder("Recipes", kind: .note)
        store.addGroup("Mains", kind: .note)
        let folder = store.data.folderList(.note).first { $0.name == "Recipes" }!.id
        let group  = store.data.groupList(.note).first!.id
        store.add(Note(title: "Ragu", date: day(2026, 9, 1), folder: folder, group: group))
        let n = store.data.notes.first { $0.title == "Ragu" }!
        XCTAssertEqual(n.folder, folder)
        XCTAssertEqual(n.group, group)
        XCTAssertEqual(n.date, day(2026, 9, 1))
        XCTAssertEqual(store.notes(folder: folder, group: group).map(\.title), ["Ragu"], "and it lists there")
    }

    // The web's "an unknown id is a no-op, not a crash" edge, at the Store layer: acting on a
    // row that isn't there changes nothing rather than throwing or inventing one.
    func testUnknownIdIsANoOp() {
        let store = freshStore()
        store.add(Reminder(text: "real"))
        store.addGroup("Sec", kind: .reminder)
        let count = store.data.reminders.count
        let ghost = Reminder(text: "ghost")           // never added to the store
        store.toggle(ghost)
        store.update(ghost)
        store.setIndent(ghost, to: 1)
        store.delete(ghost)
        store.setGroupColor(UUID(), to: 4)            // no such group
        XCTAssertEqual(store.data.reminders.count, count, "nothing was added or removed")
        XCTAssertEqual(store.data.reminders.first { $0.text == "real" }?.indent, 0, "and the real row is untouched")
    }

    // MARK: - Habits

    func testHabitToggleAndGrouping() {
        let store = freshStore()
        store.addHabit("Floss", group: nil)
        var h = store.habits(group: nil).first { $0.name == "Floss" }!
        let d = day(2026, 2, 2)
        store.toggleHabit(h, on: d)
        XCTAssertTrue(store.data.habits.first { $0.id == h.id }!.on(d), "ticked")
        h = store.data.habits.first { $0.id == h.id }!
        store.toggleHabit(h, on: d)
        XCTAssertFalse(store.data.habits.first { $0.id == h.id }!.on(d), "tapping again clears it")
    }

    // MARK: - Parsing

    func testParseTimeAndDate() {
        let now = day(2026, 1, 1)
        let p = parseWhen("Vet 8/3 2pm", now: now)
        XCTAssertEqual(p.text, "Vet")
        XCTAssertEqual(p.date, day(2026, 8, 3))
        XCTAssertEqual(p.minutes, 14 * 60)

        XCTAssertEqual(parseWhen("Call 2:30 pm", now: now).minutes, 14 * 60 + 30)
        XCTAssertEqual(parseWhen("Gym 9am", now: now).minutes, 9 * 60, "a bare am hour")
        XCTAssertEqual(parseWhen("Wake 12:05 am", now: now).minutes, 5, "12:05 am is five past midnight")
        XCTAssertEqual(parseWhen("Noon 12pm", now: now).minutes, 12 * 60, "12pm is midday")
        XCTAssertEqual(parseWhen("Trip 3/4/27", now: now).date, day(2027, 3, 4))
        XCTAssertEqual(parseWhen("Trip 3/4/2027", now: now).date, day(2027, 3, 4))
    }

    func testBareDateIsNextOccurrence() {
        let now = day(2026, 6, 15)
        // A month/day already gone this year rolls to next year.
        XCTAssertEqual(parseWhen("Taxes 1/2", now: now).date, day(2027, 1, 2))
        // One still ahead stays this year.
        XCTAssertEqual(parseWhen("Fair 9/1", now: now).date, day(2026, 9, 1))
    }

    func testFractionParsesAsADateAsDocumented() {
        // The parser can't tell a date from a fraction — this is the known limitation.
        let p = parseWhen("2/3 cup flour", now: day(2026, 1, 1))
        XCTAssertEqual(p.date, day(2026, 2, 3), "2/3 reads as Feb 3, as the web does")
    }

    // MARK: - The watch list

    func testWatchListHasOpenItemsInGroupsAndDropsEmpties() {
        let store = freshStore()
        store.add(Reminder(text: "buy milk", due: nil, group: .calendar))
        store.add(Reminder(text: "call bank", due: Date().day, group: .inbox))
        store.add(Reminder(text: "done one", due: Date().day, done: true, group: .inbox))
        let list = store.watchList()
        let names = list.sections.map(\.name)
        XCTAssertTrue(names.contains("Calendar") && names.contains("Reminders"))
        let inbox = list.sections.first { $0.name == "Reminders" }!
        XCTAssertEqual(inbox.items.map(\.text), ["call bank"], "open items only, done dropped")
    }

    // MARK: - Persistence

    func testSaveAndReadRoundTrip() {
        let url = FileManager.default.temporaryDirectory
            .appendingPathComponent("suitecore-rt-\(UUID().uuidString).json")
        let a = Store(file: url)
        a.addFolder("Persisted", kind: .reminder)
        a.add(Reminder(text: "written"))
        a.save()   // skip the debounce
        let b = Store(file: url)
        XCTAssertTrue(b.data.folderList(.reminder).contains { $0.name == "Persisted" })
        XCTAssertTrue(b.data.reminders.contains { $0.text == "written" })
    }

    // MARK: - The reorder helper

    func testReorderMatchesDragSemantics() {
        var xs = [0, 1, 2, 3, 4]
        xs.reorder(fromOffsets: IndexSet(integer: 0), toOffset: 3)
        XCTAssertEqual(xs, [1, 2, 0, 3, 4], "moving the first element to before index 3")
    }

    func testDayLabel() {
        let today = day(2026, 5, 10)
        XCTAssertEqual(dayLabel(today, today: today), "today")
        XCTAssertEqual(dayLabel(day(2026, 5, 11), today: today), "tomorrow")
        XCTAssertEqual(dayLabel(day(2026, 5, 9), today: today), "yesterday")
        XCTAssertEqual(dayLabel(day(2026, 12, 25), today: today), "Dec 25")
    }
}
