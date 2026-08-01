import XCTest
@testable import SuiteCore

/// Proves the harness compiles the Shared core and can reach its internals.
final class SmokeTests: XCTestCase {
    func testStarterIsOneEmptySuite() {
        let d = AppData.starter
        XCTAssertEqual(d.folderList(.reminder).count, 1, "one reminder folder")
        XCTAssertEqual(d.folderList(.note).count, 1, "one note folder")
        XCTAssertEqual(d.calendars.count, 1, "one calendar")
        XCTAssertNotNil(d.defaultCal, "with a default")
        XCTAssertTrue(d.reminders.isEmpty && d.notes.isEmpty && d.habits.isEmpty, "and nothing in it")
    }
}
