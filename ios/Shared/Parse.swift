import Foundation

/// What was typed, once a date and a time have been lifted out of it.
struct Parsed {
    var text: String
    var date: Date?
    var minutes: Int?
}

/// Reads "Vet 8/3 2pm" as a vet appointment on 3 August at two — the twin of the web's
/// `parse_when_from_text()` and Android's `parseWhen`, kept regex-for-regex identical
/// and locked together by spec/parse.json.
///
/// Times are `2pm` / `2:30 pm`. Dates are slash-only and US-order — `m/d`, `m/d/yy`,
/// `m/d/yyyy` — deliberately narrow so they can't wander into other numbers in the
/// sentence. It still can't tell a date from a fraction, so "2/3 cup" parses as
/// 3 February; an explicit date field always wins over this. The date is lifted out
/// first, exactly as the web does, so "8/3pm" reads as a date and no time.
func parseWhen(_ raw: String, now: Date = Date()) -> Parsed {
    let (afterDate, date) = parseDateFromText(raw, today: now)
    let (text, minutes)   = parseTimeFromText(afterDate)
    return Parsed(text: text, date: date, minutes: minutes)
}

/// Pull a numeric date out of text; returns the cleaned text and the date (nil when
/// nothing lifted). A bare m/d means the next occurrence — this year, or next year if
/// it's past. An impossible calendar date (2/30, 2/29 off-leap) lifts nothing and
/// leaves the text untouched, the web's `checkdate()` behaviour.
private func parseDateFromText(_ text: String, today: Date) -> (String, Date?) {
    guard let hit = firstMatch(#"(?<![\d/])(\d{1,2})/(\d{1,2})(?:/(\d{2}|\d{4}))?(?![\d/])"#, in: text) else {
        return (text, nil)
    }
    let mo = Int(hit.group(1, text) ?? "") ?? 0
    let dy = Int(hit.group(2, text) ?? "") ?? 0
    if mo < 1 || mo > 12 || dy < 1 || dy > 31 { return (text, nil) }

    let cal = Calendar.current
    let todayStr = ymd(today)
    var yr: Int
    if let yearS = hit.group(3, text), let y = Int(yearS) {
        yr = yearS.count == 2 ? 2000 + y : y
    } else {
        // No year given: this year, unless that date has already gone by.
        yr = Int(todayStr.prefix(4)) ?? 0
        if String(format: "%04d-%02d-%02d", yr, mo, dy) < todayStr { yr += 1 }
    }
    let parts = DateComponents(calendar: cal, year: yr, month: mo, day: dy)
    guard parts.isValidDate, let made = parts.date else { return (text, nil) }

    return (strip(hit.range, from: text), made.day)
}

/// Pull a time like "2pm" / "2:30 pm" out of text; returns the cleaned text and the
/// minutes past midnight (nil when nothing lifted). 12am is midnight, 12pm noon.
private func parseTimeFromText(_ text: String) -> (String, Int?) {
    guard let hit = firstMatch(#"\b(\d{1,2})(?::(\d{2}))?\s*([apAP])\.?[mM]\.?\b"#, in: text) else {
        return (text, nil)
    }
    var h      = Int(hit.group(1, text) ?? "") ?? 0
    let min    = Int(hit.group(2, text) ?? "0") ?? 0
    let ap     = (hit.group(3, text) ?? "").lowercased()
    guard h >= 1, h <= 12, min < 60 else { return (text, nil) }
    if ap == "p" && h < 12  { h += 12 }
    if ap == "a" && h == 12 { h = 0 }
    return (strip(hit.range, from: text), h * 60 + min)
}

// MARK: - Plumbing

/// "YYYY-MM-DD" in the current calendar — the storage shape, used for the string
/// comparisons the web parser makes.
private func ymd(_ date: Date) -> String {
    let p = Calendar.current.dateComponents([.year, .month, .day], from: date)
    return String(format: "%04d-%02d-%02d", p.year ?? 0, p.month ?? 0, p.day ?? 0)
}

private func firstMatch(_ pattern: String, in text: String) -> NSTextCheckingResult? {
    guard let re = try? NSRegularExpression(pattern: pattern) else { return nil }
    return re.firstMatch(in: text, range: NSRange(text.startIndex..., in: text))
}

/// Remove the match and tidy what's left — the web's str_replace + collapse of any
/// doubled whitespace, so the cleaned text is byte-identical across platforms.
private func strip(_ range: NSRange, from text: String) -> String {
    guard let r = Range(range, in: text) else { return text }
    let removed = text.replacingCharacters(in: r, with: "")
    let squashed = removed.replacingOccurrences(of: #"\s{2,}"#, with: " ", options: .regularExpression)
    return squashed.trimmingCharacters(in: .whitespacesAndNewlines)
}

private extension NSTextCheckingResult {
    func group(_ i: Int, _ text: String) -> String? {
        guard i < numberOfRanges, let r = Range(range(at: i), in: text) else { return nil }
        return String(text[r])
    }
}
