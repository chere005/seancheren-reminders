import XCTest
@testable import SuiteCore

/// The features brought over from the web app: subtasks, section colours, the Habits
/// month filter, Copy as Markdown — and that old documents still load once the model has
/// grown fields they never had.
@MainActor
final class FeatureTests: XCTestCase {

    private func freshStore() -> Store {
        let url = FileManager.default.temporaryDirectory
            .appendingPathComponent("suitefeat-\(UUID().uuidString).json")
        return Store(file: url)
    }
    private let cal = Calendar.current
    private func day(_ y: Int, _ m: Int, _ d: Int) -> Date {
        cal.date(from: DateComponents(year: y, month: m, day: d))!.day
    }

    // MARK: - Subtasks / the outline sort

    func testOutlineSortKeepsASubtaskUnderItsParent() {
        let store = freshStore()
        // The subtask is undated; a flat undated-first sort would float it to the very top,
        // stranding it under whatever row landed there. The outline sort keeps it put.
        store.data.reminders = [
            Reminder(text: "P", due: day(2026, 1, 20), order: 0, indent: 0),
            Reminder(text: "s", due: nil,               order: 1, indent: 1),
            Reminder(text: "O", due: day(2026, 1, 10), order: 2, indent: 0),
        ]
        XCTAssertEqual(store.reminders(folder: nil, group: .inbox).map(\.text), ["O", "P", "s"])
    }

    func testAddSubtaskSlotsUnderParentAtIndentOne() {
        let store = freshStore()
        store.add(Reminder(text: "Parent"))
        let parent = store.data.reminders.first { $0.text == "Parent" }!
        let child = store.addSubtask(under: parent)
        XCTAssertEqual(child.indent, 1)
        XCTAssertEqual(child.group, parent.group)
        let ordered = store.data.reminders.sorted { $0.order < $1.order }
        let pi = ordered.firstIndex { $0.id == parent.id }!
        XCTAssertEqual(ordered[pi + 1].id, child.id, "it sits right after its parent in stored order")
    }

    func testSetIndentIsOneLevelOnly() {
        let store = freshStore()
        store.add(Reminder(text: "x"))
        let r = store.data.reminders.first { $0.text == "x" }!
        store.setIndent(r, to: 5)
        XCTAssertEqual(store.data.reminders.first { $0.id == r.id }!.indent, 1, "clamped to one level")
        store.setIndent(r, to: 0)
        XCTAssertEqual(store.data.reminders.first { $0.id == r.id }!.indent, 0)
    }

    // MARK: - Section colours

    func testSectionColoursByPositionThenBySetter() {
        let store = freshStore()
        store.addGroup("A", kind: .reminder)
        store.addGroup("B", kind: .reminder)
        let a = store.data.groupList(.reminder).first { $0.name == "A" }!
        let b = store.data.groupList(.reminder).first { $0.name == "B" }!
        XCTAssertEqual(a.color, 0)
        XCTAssertEqual(b.color, 1)
        store.setGroupColor(a.id, to: 4)
        XCTAssertEqual(store.data.groups.first { $0.id == a.id }!.color, 4)
    }

    // MARK: - Habits month filter

    func testHabitFilterThreeGestures() {
        let store = freshStore()
        store.addGroup("Morning", kind: .habit)
        store.addGroup("Evening", kind: .habit)
        let m = store.data.groupList(.habit).first { $0.name == "Morning" }!
        let e = store.data.groupList(.habit).first { $0.name == "Evening" }!
        store.addHabit("floss", group: m.id)
        store.addHabit("read", group: e.id)
        store.addHabit("loose", group: nil)

        XCTAssertTrue(store.habitAllShown)
        XCTAssertEqual(store.habitsCounted().count, 3)

        store.toggleHabitSection(m.id)                        // box: Morning off
        XCTAssertFalse(store.habitSectionShown(m.id))
        XCTAssertEqual(store.habitsCounted().count, 2)

        store.onlyHabitSection(e.id)                          // row: only Evening
        XCTAssertEqual(store.habitsCounted().map(\.name), ["read"])
        XCTAssertFalse(store.habitSectionShown(nil), "ungrouped is off when isolating a section")

        store.setHabitAll(false)                              // All: everything off
        XCTAssertEqual(store.habitsCounted().count, 0)
        store.setHabitAll(true)                               // All: everything on
        XCTAssertTrue(store.habitAllShown)
        XCTAssertEqual(store.habitsCounted().count, 3)
    }

    func testHabitMonthFillCountsBySection() {
        let store = freshStore()
        store.addGroup("Morning", kind: .habit)
        let m = store.data.groupList(.habit).first!
        store.addHabit("floss", group: m.id)
        store.addHabit("water", group: nil)
        let floss = store.habits(group: m.id).first!
        let water = store.habits(group: nil).first!
        let d = day(2026, 4, 10)
        store.toggleHabit(floss, on: d)
        store.toggleHabit(water, on: d)

        let fill = store.habitMonthFill(day(2026, 4, 1))
        XCTAssertEqual(fill[d.key]?[m.id], 1, "one Morning habit ticked that day")
        XCTAssertEqual(fill[d.key]?[nil], 1, "and one ungrouped")
        XCTAssertNil(fill[day(2026, 4, 11).key], "a day with nothing ticked isn't in the map")

        store.toggleHabitSection(m.id)                        // hide Morning
        XCTAssertNil(store.habitMonthFill(day(2026, 4, 1))[d.key]?[m.id],
                     "a hidden section drops out of the pies")
    }

    // MARK: - Copy as Markdown

    func testMarkdownExport() {
        let store = freshStore()
        store.add(Reminder(text: "Buy milk", due: nil, group: .calendar))
        store.add(Reminder(text: "Taxes", due: day(2026, 1, 2), group: .inbox))
        let parent = store.data.reminders.first { $0.text == "Taxes" }!
        let child = store.addSubtask(under: parent)
        store.update({ var c = child; c.text = "file online"; return c }())

        let md = store.markdown(folder: nil)
        XCTAssertTrue(md.contains("## Calendar"), "a heading per group")
        XCTAssertTrue(md.contains("- [ ] Buy milk"))
        XCTAssertTrue(md.contains("## Reminders"))
        XCTAssertTrue(md.contains("- [ ] Taxes ("), "a dated row carries its date in parens")
        XCTAssertTrue(md.contains("  - [ ] file online"), "a subtask is indented two spaces")
    }

    // MARK: - Clear completed

    func testClearDoneRemovesOnlyTheTickedRows() {
        let store = freshStore()
        store.add(Reminder(text: "open"))
        store.add(Reminder(text: "done a", done: true))
        store.add(Reminder(text: "done b", done: true))
        store.clearDone(folder: nil)
        let names = store.data.reminders.map(\.text)
        XCTAssertEqual(names, ["open"], "the ticked rows go, the open one stays")
    }

    func testClearDoneIsScopedToTheFolderInView() {
        let store = freshStore()
        store.addFolder("Work", kind: .reminder)
        let work = store.data.folderList(.reminder).first { $0.name == "Work" }!.id
        let home = store.data.folderList(.reminder).first { $0.id != work }!.id
        store.add(Reminder(text: "work done", done: true, folder: work))
        store.add(Reminder(text: "home done", done: true, folder: home))
        store.clearDone(folder: work)
        XCTAssertNil(store.data.reminders.first { $0.text == "work done" }, "the viewed folder's done rows go")
        XCTAssertNotNil(store.data.reminders.first { $0.text == "home done" }, "another folder's are left alone")
    }

    // MARK: - Habit sections

    func testDeletingAHabitSectionLeavesItsHabitsUngrouped() {
        let store = freshStore()
        store.addGroup("Morning", kind: .habit)
        let sec = store.data.groupList(.habit).first!
        store.addHabit("floss", group: sec.id)
        store.deleteGroup(sec)
        let floss = store.data.habits.first { $0.name == "floss" }
        XCTAssertNotNil(floss, "the habit survives its section")
        XCTAssertNil(floss?.group, "and falls back to ungrouped")
        XCTAssertNil(store.data.groups.first { $0.id == sec.id }, "the section is gone")
    }

    // MARK: - Backward compatibility

    /// Strip keys that post-date the first version from a parsed JSON tree, recursively —
    /// simulating a suite.json written before those fields existed.
    private static func stripKeys(_ keys: Set<String>, from obj: Any) -> Any {
        if var dict = obj as? [String: Any] {
            for k in keys { dict.removeValue(forKey: k) }
            for (k, v) in dict { dict[k] = stripKeys(keys, from: v) }
            return dict
        }
        if let arr = obj as? [Any] { return arr.map { stripKeys(keys, from: $0) } }
        return obj
    }

    func testOldDocumentWithoutNewKeysStillLoads() throws {
        let store = freshStore()
        store.add(Reminder(text: "keep me"))
        store.addGroup("Sec", kind: .habit)

        let encoded = try JSONEncoder().encode(store.data)
        let tree = try JSONSerialization.jsonObject(with: encoded)
        // These keys did not exist in the first version; a document without them must load,
        // not reset the whole suite to empty because one key was absent.
        let stripped = Self.stripKeys(["indent", "habitHidden", "habitHideUngrouped", "habitsMonth",
                                       "habitUngroupedColor", "hiddenFolders", "hiddenCals",
                                       "calHiddenFolders"],
                                      from: tree)
        let bytes = try JSONSerialization.data(withJSONObject: stripped)
        let restored = try JSONDecoder().decode(AppData.self, from: bytes)

        XCTAssertTrue(restored.reminders.contains { $0.text == "keep me" }, "the data survived")
        XCTAssertEqual(restored.reminders.first { $0.text == "keep me" }?.indent, 0, "indent defaulted")
        XCTAssertTrue(restored.habitHidden.isEmpty, "the filter defaulted to empty")
        XCTAssertEqual(restored.habitUngroupedColor, 3, "the ungrouped habits colour defaulted")
    }

    func testUngroupedHabitColour() {
        let store = freshStore()
        XCTAssertEqual(store.data.habitUngroupedColor, 3, "a fresh suite has a default ungrouped colour")
        store.setUngroupedHabitColor(6)
        XCTAssertEqual(store.data.habitUngroupedColor, 6, "and it can be recoloured")
    }

    // MARK: - Sample data ("buddy's data")

    func testLoadSamplePopulatesBuddysData() {
        let store = freshStore()
        store.loadSample()
        XCTAssertTrue(store.data.folderList(.reminder).contains { $0.name == "Cooking" }, "a Cooking folder")
        XCTAssertTrue(store.data.groups.contains { $0.name == "Groceries" && $0.kind == .reminder }, "a section")
        XCTAssertTrue(store.data.reminders.contains { $0.text == "Milk" }, "a grocery item")
        XCTAssertTrue(store.data.reminders.contains { $0.ridesAlong }, "a Calendar-folder rider")
        XCTAssertTrue(store.data.notes.contains { $0.title == "Chicken parmesan" }, "the recipe note")
        let dinners = store.data.events.filter { $0.text.hasPrefix("Dinner") }
        XCTAssertEqual(dinners.count, 2, "two dinners")
        for e in dinners {
            XCTAssertEqual(cal.component(.weekday, from: e.date), 7, "each dinner on a Saturday")
        }
        XCTAssertTrue(store.data.habits.contains { $0.name == "Floss" && !$0.marks.isEmpty }, "a ticked habit")
        XCTAssertFalse(store.watchList().sections.isEmpty, "and it flows to the watch")
    }

    func testListGroupDecodesWithoutAColour() throws {
        let json = "{\"id\":\"\(UUID().uuidString)\",\"name\":\"Old\",\"kind\":\"reminder\",\"order\":2}"
        let g = try JSONDecoder().decode(ListGroup.self, from: Data(json.utf8))
        XCTAssertEqual(g.name, "Old")
        XCTAssertEqual(g.color, 0, "a section from before colours loads at colour 0")
    }
}
