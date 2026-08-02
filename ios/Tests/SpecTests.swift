import XCTest
@testable import SuiteCore

/// The shared behavior contract: spec/*.json at the repo root — the same vectors the web
/// (tools/test.php, the `spec` area) and Android (SpecTest.kt) replay. A behavior change
/// starts in spec/ and is done only when every platform's suite is green again.
@MainActor
final class SpecTests: XCTestCase {

    private let cal = Calendar.current

    // MARK: - Loading the vectors (repo-relative, so `swift test` finds them from ios/)

    private func specURL(_ name: String) -> URL {
        URL(fileURLWithPath: #filePath)          // …/ios/Tests/SpecTests.swift
            .deletingLastPathComponent()         // …/ios/Tests
            .deletingLastPathComponent()         // …/ios
            .deletingLastPathComponent()         // repo root
            .appendingPathComponent("spec").appendingPathComponent(name)
    }

    private func load<T: Decodable>(_ name: String) throws -> T {
        try JSONDecoder().decode(T.self, from: Data(contentsOf: specURL(name)))
    }

    // MARK: - The storage shapes ("YYYY-MM-DD", "HH:MM") to and from native types

    private func day(_ ymd: String) -> Date {
        let p = ymd.split(separator: "-").map { Int($0) ?? 0 }
        return cal.date(from: DateComponents(year: p[0], month: p[1], day: p[2]))!.day
    }

    private func ymd(_ date: Date) -> String {
        let p = cal.dateComponents([.year, .month, .day], from: date)
        return String(format: "%04d-%02d-%02d", p.year ?? 0, p.month ?? 0, p.day ?? 0)
    }

    private func hhmm(_ minutes: Int) -> String {
        String(format: "%02d:%02d", minutes / 60, minutes % 60)
    }

    // MARK: - parse.json

    private struct ParseCase: Decodable {
        let name: String, input: String, today: String
        let text: String, date: String?, time: String?
    }

    func testEveryParseVectorHolds() throws {
        for c in try load("parse.json") as [ParseCase] {
            let got = parseWhen(c.input, now: day(c.today))
            XCTAssertEqual(got.text, c.text, "\(c.name): text")
            XCTAssertEqual(got.date.map(ymd), c.date, "\(c.name): date")
            XCTAssertEqual(got.minutes.map(hhmm), c.time, "\(c.name): time")
        }
    }

    // MARK: - repeats.json

    private struct RepeatRule: Decodable { let n: Int; let unit: String }
    private struct StepCase: Decodable {
        let name: String, start: String, unit: String
        let n: Int, i: Int
        let expect: String
    }
    private struct WindowCase: Decodable {
        let name: String, start: String
        let rule: RepeatRule?
        let from: String, to: String, expect: [String]
        enum CodingKeys: String, CodingKey { case name, start, rule = "repeat", from, to, expect }
    }
    private struct NextCase: Decodable {
        let name: String, start: String, unit: String
        let n: Int
        let after: String, expect: String
    }
    private struct RepeatSpec: Decodable {
        let step: [StepCase], window: [WindowCase], next: [NextCase]
    }

    private func recurrence(_ n: Int, _ unit: String) -> Recurrence {
        Recurrence(n: n, unit: Recurrence.Unit(rawValue: unit)!)
    }

    func testEveryRepeatVectorHolds() throws {
        let spec: RepeatSpec = try load("repeats.json")
        for c in spec.step {
            // occurrence(start, i) is the start-anchored seam all three cores define —
            // the web's repeat_step — so a month repeat springs back to the 31st instead
            // of drifting to wherever a shorter month clamped it.
            let got = recurrence(c.n, c.unit).occurrence(day(c.start), c.i)
            XCTAssertEqual(ymd(got), c.expect, c.name)
        }
        for c in spec.window {
            let got: [Date]
            if let r = c.rule {
                got = recurrence(r.n, r.unit).dates(start: day(c.start), from: day(c.from), to: day(c.to))
            } else {
                // No rule: a one-off is itself when it falls inside the window. (The
                // Store's calendar read does this branch; the spec keeps it honest.)
                let s = day(c.start)
                got = (day(c.from) <= day(c.to) && s >= day(c.from) && s <= day(c.to)) ? [s] : []
            }
            XCTAssertEqual(got.map(ymd), c.expect, c.name)
        }
        for c in spec.next {
            let got = recurrence(c.n, c.unit).next(from: day(c.start), after: day(c.after))
            XCTAssertEqual(ymd(got), c.expect, c.name)
        }
    }

    // MARK: - sort.json

    private struct SortRow: Decodable { let id: String; let due: String?; let indent: Int? }
    private struct SortCase: Decodable { let name: String; let rows: [SortRow]; let expect: [String] }

    func testEverySortVectorHolds() throws {
        for c in try load("sort.json") as [SortCase] {
            let url = FileManager.default.temporaryDirectory
                .appendingPathComponent("suitespec-\(UUID().uuidString).json")
            let store = Store(file: url)
            for (i, row) in c.rows.enumerated() {
                var r = Reminder()
                r.text = row.id            // the vector id rides in the text
                r.due = row.due.map(day)
                r.indent = row.indent ?? 0
                r.order = i
                store.data.reminders.append(r)
            }
            let got = store.reminders(folder: nil, group: .inbox).map(\.text)
            XCTAssertEqual(got, c.expect, c.name)
        }
    }
}
