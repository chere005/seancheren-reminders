import Foundation

/// What was typed, once a date and a time have been lifted out of it.
struct Parsed {
    var text: String
    var date: Date?
    var minutes: Int?
}

/// Reads "Vet 8/3 2pm" as a vet appointment on 3 August at two.
///
/// Times are `2pm` / `2:30 pm`. Dates are slash-only and US-order — `m/d`, `m/d/yy`,
/// `m/d/yyyy` — deliberately narrow so they can't wander into other numbers in the
/// sentence. It still can't tell a date from a fraction, so "2/3 cup" parses as
/// 3 February; an explicit date field always wins over this.
func parseWhen(_ raw: String, now: Date = Date()) -> Parsed {
    var text = raw
    var minutes: Int?
    var date: Date?

    if let hit = firstMatch(#"(?<![\d:])(\d{1,2})(?::([0-5]\d))?\s*([ap])\.?m\.?"#, in: text) {
        let hour = Int(hit.group(1, text) ?? "") ?? 0
        let mins = Int(hit.group(2, text) ?? "0") ?? 0
        let pm   = (hit.group(3, text) ?? "").lowercased() == "p"
        if hour >= 1 && hour <= 12 {
            let h24 = pm ? (hour % 12) + 12 : hour % 12
            minutes = h24 * 60 + mins
            text = strip(hit.range, from: text)
        }
    }

    if let hit = firstMatch(#"(?<![\d/])(\d{1,2})/(\d{1,2})(?:/(\d{2,4}))?(?![\d/])"#, in: text) {
        let month = Int(hit.group(1, text) ?? "") ?? 0
        let day   = Int(hit.group(2, text) ?? "") ?? 0
        let yearS = hit.group(3, text)
        let cal   = Calendar.current
        if month >= 1, month <= 12, day >= 1, day <= 31 {
            var parts = DateComponents()
            parts.month = month
            parts.day = day
            if let yearS, let y = Int(yearS) {
                parts.year = yearS.count <= 2 ? 2000 + y : y
            } else {
                parts.year = cal.component(.year, from: now)
            }
            if var made = cal.date(from: parts) {
                // A bare m/d means the next one coming, not one that's already gone.
                if yearS == nil, made.day < now.day {
                    parts.year = (parts.year ?? 0) + 1
                    made = cal.date(from: parts) ?? made
                }
                date = made.day
                text = strip(hit.range, from: text)
            }
        }
    }

    return Parsed(text: text.trimmingCharacters(in: .whitespacesAndNewlines)
                            .replacingOccurrences(of: "  ", with: " "),
                  date: date, minutes: minutes)
}

// MARK: - Regex plumbing

private func firstMatch(_ pattern: String, in text: String) -> NSTextCheckingResult? {
    guard let re = try? NSRegularExpression(pattern: pattern, options: [.caseInsensitive]) else {
        return nil
    }
    return re.firstMatch(in: text, range: NSRange(text.startIndex..., in: text))
}

private func strip(_ range: NSRange, from text: String) -> String {
    guard let r = Range(range, in: text) else { return text }
    return text.replacingCharacters(in: r, with: " ")
}

private extension NSTextCheckingResult {
    func group(_ i: Int, _ text: String) -> String? {
        guard i < numberOfRanges, let r = Range(range(at: i), in: text) else { return nil }
        return String(text[r])
    }
}
