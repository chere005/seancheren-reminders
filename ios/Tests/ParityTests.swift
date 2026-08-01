import XCTest
@testable import SuiteCore

/// Web-suite behaviours ported one-for-one, so the two apps stay in feature parity. Each
/// test names the web `t(...)` it mirrors; `PARITY.md` is the full map. These join the core
/// coverage in CoreTests/FeatureTests rather than replacing it.
@MainActor
final class ParityTests: XCTestCase {

    private func freshStore() -> Store {
        let url = FileManager.default.temporaryDirectory
            .appendingPathComponent("suiteparity-\(UUID().uuidString).json")
        return Store(file: url)
    }
    private let cal = Calendar.current
    private func day(_ y: Int, _ m: Int, _ d: Int) -> Date {
        cal.date(from: DateComponents(year: y, month: m, day: d))!.day
    }

    // MARK: - Name hygiene ("folder names are cleaned on the way in")

    func testCleanNameTrimsCollapsesStripsAndClips() {
        XCTAssertEqual(cleanName("  Work  "), "Work", "trimmed")
        XCTAssertEqual(cleanName("a\tb"), "a b", "whitespace collapses")
        XCTAssertFalse(cleanName("a\u{1F}b").contains("\u{1F}"), "the picker separator cannot survive")
        XCTAssertFalse(cleanName("a\u{0}b\u{7}c").unicodeScalars.contains { $0.value < 0x20 },
                       "no control characters survive")
        XCTAssertEqual(cleanName(String(repeating: "x", count: 80)).count, 40, "clipped to 40")
    }

    func testAddFolderCleansAndRefusesEmpty() {
        let store = freshStore()
        let before = store.data.folderList(.reminder).count
        store.addFolder("   ", kind: .reminder)
        XCTAssertEqual(store.data.folderList(.reminder).count, before, "a whitespace-only name is refused")
        store.addFolder("  Trips  ", kind: .reminder)
        XCTAssertTrue(store.data.folderList(.reminder).contains { $0.name == "Trips" }, "a real one is cleaned")
    }

    func testDuplicateFolderAndGroupNamesAreRefused() {
        let store = freshStore()
        store.addFolder("Trips", kind: .reminder)
        store.addFolder("trips", kind: .reminder)                 // case-insensitive dupe
        XCTAssertEqual(store.data.folderList(.reminder).filter { $0.name.lowercased() == "trips" }.count, 1)
        store.addGroup("Errands", kind: .reminder)
        store.addGroup("ERRANDS", kind: .reminder)
        XCTAssertEqual(store.data.groupList(.reminder).count, 1, "a duplicate section name is refused")
    }

    // MARK: - Reserved section names ("the \"Notes\" catch-all name is reserved")

    func testReservedGroupNamesAreRefused() {
        let store = freshStore()
        store.addGroup("Notes", kind: .note)
        XCTAssertTrue(store.data.groupList(.note).isEmpty, "a note section may not be called Notes")
        store.addGroup("Calendar", kind: .reminder)
        store.addGroup("Reminders", kind: .reminder)
        XCTAssertTrue(store.data.groupList(.reminder).isEmpty, "Calendar and Reminders are reserved")
        store.addGroup("Errands", kind: .reminder)
        XCTAssertEqual(store.data.groupList(.reminder).map(\.name), ["Errands"], "a normal name is fine")
    }

    // MARK: - Reordering ("folders reorder and keep every folder"; the manager reorder)

    func testMoveFoldersKeepsEveryFolder() {
        let store = freshStore()
        ["A", "B", "C"].forEach { store.addFolder($0, kind: .reminder) }
        let before = store.data.folderList(.reminder).map(\.name)
        store.moveFolders(.reminder, from: IndexSet(integer: 0), to: before.count)   // first → last
        let after = store.data.folderList(.reminder).map(\.name)
        XCTAssertEqual(Set(before), Set(after), "no folder is lost")
        XCTAssertEqual(after.first, before[1], "and the order really moved")
    }

    func testMoveGroupsReordersWithoutDisturbingRows() {
        let store = freshStore()
        ["One", "Two", "Three"].forEach { store.addGroup($0, kind: .habit) }
        store.addHabit("floss", group: store.data.groupList(.habit).first!.id)
        let habits = store.data.habits.map(\.name).sorted()
        store.moveGroups(.habit, from: IndexSet(integer: 0), to: 3)                  // first section → last
        XCTAssertEqual(store.data.groupList(.habit).map(\.name), ["Two", "Three", "One"], "sections reordered")
        XCTAssertEqual(store.data.habits.map(\.name).sorted(), habits, "no habit moved with them")
    }

    // MARK: - Folder visibility ("the picker box / row / All", folder_vis*)

    func testFolderVisibilityThreeGestures() {
        let store = freshStore()
        ["Work", "Home"].forEach { store.addFolder($0, kind: .reminder) }
        let ids = store.data.folderList(.reminder).map(\.id)      // General, Work, Home
        XCTAssertTrue(store.foldersAllShown(.reminder), "everything shows to begin with")

        store.toggleFolder(ids[1], kind: .reminder)              // box: hide Work
        XCTAssertFalse(store.folderShown(ids[1], kind: .reminder))
        XCTAssertFalse(store.foldersAllShown(.reminder), "All is off when one is hidden")

        store.showOnlyFolder(ids[2], kind: .reminder)            // row: only Home
        XCTAssertEqual(store.shownFolders(.reminder).map(\.id), [ids[2]], "only Home shows")

        store.setFoldersAll(false, kind: .reminder)              // All off
        XCTAssertTrue(store.shownFolders(.reminder).isEmpty)
        store.setFoldersAll(true, kind: .reminder)               // All on
        XCTAssertTrue(store.foldersAllShown(.reminder))
        XCTAssertEqual(store.shownFolders(.reminder).count, 3)
    }

    func testHiddenFolderDropsOutOfTheListAndWatch() {
        let store = freshStore()
        store.addFolder("Work", kind: .reminder)
        let work = store.data.folderList(.reminder).first { $0.name == "Work" }!.id
        let general = store.data.folderList(.reminder).first { $0.id != work }!.id
        store.add(Reminder(text: "work task", folder: work, group: .inbox))
        store.add(Reminder(text: "home task", folder: general, group: .inbox))
        XCTAssertEqual(store.remindersShown(folder: nil, group: .inbox).map(\.text).sorted(),
                       ["home task", "work task"])
        store.toggleFolder(work, kind: .reminder)
        XCTAssertEqual(store.remindersShown(folder: nil, group: .inbox).map(\.text), ["home task"],
                       "the hidden folder's rows drop out of the list")
        XCTAssertFalse(store.markdown(folder: nil).contains("work task"), "and out of Copy as Markdown")
    }

    func testNotesHiddenFolderDropsOut() {
        let store = freshStore()
        store.addFolder("Private", kind: .note)
        let priv = store.data.folderList(.note).first { $0.name == "Private" }!.id
        let general = store.data.folderList(.note).first { $0.id != priv }!.id
        store.add(Note(title: "secret", folder: priv))
        store.add(Note(title: "shopping", folder: general))
        store.toggleFolder(priv, kind: .note)
        XCTAssertEqual(store.notesShown(folder: nil, group: nil).map(\.title), ["shopping"])
    }

    func testAddTargetIsTheOneShownFolderElseDefault() {
        let store = freshStore()
        store.addFolder("Work", kind: .reminder)
        let ids = store.data.folderList(.reminder).map(\.id)     // General, Work
        XCTAssertEqual(store.addTarget(.reminder), store.data.defaultFolder[ItemKind.reminder.rawValue],
                       "with several shown, a new item lands in the default")
        store.showOnlyFolder(ids[1], kind: .reminder)
        XCTAssertEqual(store.addTarget(.reminder), ids[1], "with one shown, it lands there")
    }

    // MARK: - Calendar visibility ("tapping a calendar row leaves only it showing", cal_vis*)

    func testCalendarVisibilityThreeGestures() {
        let store = freshStore()
        store.addCalendar("Work")
        store.addCalendar("Home")
        let ids = store.calendarsOnly.map(\.id)                  // Personal, Work, Home
        XCTAssertTrue(store.calsAllShown())
        XCTAssertNil(store.shownCalScope, "no filter when everything shows")

        store.toggleCal(ids[0])                                  // hide Personal
        XCTAssertFalse(store.calShown(ids[0]))
        XCTAssertEqual(store.shownCalScope, Set([ids[1], ids[2]]), "the scope narrows to the shown ones")

        store.showOnlyCal(ids[1])                                // only Work
        XCTAssertEqual(store.shownCalScope, Set([ids[1]]))

        store.setCalsAll(false); XCTAssertEqual(store.shownCalScope, Set<UUID>(), "All off shows nothing")
        store.setCalsAll(true);  XCTAssertNil(store.shownCalScope); XCTAssertTrue(store.calsAllShown())
    }

    // MARK: - Delete from the calendar unschedules ("... only unschedules it")

    func testDeletingFromCalendarUnschedulesButKeepsTheItem() {
        let store = freshStore()
        let today = Date().day
        store.add(Reminder(text: "cal rem", due: today))
        store.add(Note(title: "cal note", date: today))
        let r = store.data.reminders.first { $0.text == "cal rem" }!
        let n = store.data.notes.first { $0.title == "cal note" }!
        store.unschedule(r)
        store.unschedule(n)
        XCTAssertNotNil(store.data.reminders.first { $0.id == r.id }, "the reminder still exists")
        XCTAssertNil(store.data.reminders.first { $0.id == r.id }?.due, "but its date is gone")
        XCTAssertNotNil(store.data.notes.first { $0.id == n.id }, "the note still exists")
        XCTAssertNil(store.data.notes.first { $0.id == n.id }?.date, "but its date is gone")
    }

    // MARK: - Persistence

    func testVisibilitySurvivesASaveAndRead() {
        let url = FileManager.default.temporaryDirectory
            .appendingPathComponent("suiteparity-rt-\(UUID().uuidString).json")
        let a = Store(file: url)
        a.addFolder("Work", kind: .reminder)
        let work = a.data.folderList(.reminder).first { $0.name == "Work" }!.id
        a.toggleFolder(work, kind: .reminder)
        a.addCalendar("Extra")
        let extra = a.calendarsOnly.first { $0.name == "Extra" }!.id
        a.toggleCal(extra)
        a.save()
        let b = Store(file: url)
        XCTAssertFalse(b.folderShown(work, kind: .reminder), "a hidden folder survives a reload")
        XCTAssertFalse(b.calShown(extra), "a hidden calendar survives a reload")
    }
}
