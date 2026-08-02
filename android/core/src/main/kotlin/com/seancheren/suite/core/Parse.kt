package com.seancheren.suite.core

import java.time.LocalDate

/** What was typed, once a date and a time have been lifted out of it. */
data class Parsed(
    val text: String,
    val date: LocalDate? = null,
    val minutes: Int? = null,
)

// The web's own regexes, kept character-for-character: times are 2pm / 2:30 pm, dates
// are slash-only and US-order — m/d, m/d/yy, m/d/yyyy — deliberately narrow so they
// can't wander into other numbers in the sentence.
private val timeRegex = Regex("""\b(\d{1,2})(?::(\d{2}))?\s*([apAP])\.?[mM]\.?\b""")
private val dateRegex = Regex("""(?<![\d/])(\d{1,2})/(\d{1,2})(?:/(\d{2}|\d{4}))?(?![\d/])""")

/**
 * Reads "Vet 8/3 2pm" as a vet appointment on 3 August at two — the twin of the web's
 * `parse_when_from_text()` and iOS's `parseWhen`, locked together by spec/parse.json.
 * It still can't tell a date from a fraction, so "2/3 cup" parses as 3 February; an
 * explicit date field always wins over this. The date is lifted out first, exactly as
 * the web does, so "8/3pm" reads as a date and no time.
 */
fun parseWhen(raw: String, now: LocalDate = LocalDate.now()): Parsed {
    val (afterDate, date) = parseDateFromText(raw, now)
    val (text, minutes) = parseTimeFromText(afterDate)
    return Parsed(text = text, date = date, minutes = minutes)
}

/**
 * Pull a numeric date out of text: the cleaned text and the date (null when nothing
 * lifted). A bare m/d means the next occurrence — this year, or next year if it's past.
 * An impossible calendar date (2/30, 2/29 off-leap) lifts nothing and leaves the text
 * untouched, the web's `checkdate()` behaviour.
 */
private fun parseDateFromText(text: String, today: LocalDate): Pair<String, LocalDate?> {
    val m = dateRegex.find(text) ?: return text to null
    val mo = m.groupValues[1].toIntOrNull() ?: 0
    val dy = m.groupValues[2].toIntOrNull() ?: 0
    if (mo < 1 || mo > 12 || dy < 1 || dy > 31) return text to null

    val yearS = m.groupValues[3].ifEmpty { null }
    var yr: Int
    if (yearS != null) {
        yr = yearS.toInt()
        if (yearS.length == 2) yr += 2000
    } else {
        // No year given: this year, unless that date has already gone by.
        yr = today.year
        if (String.format("%04d-%02d-%02d", yr, mo, dy) < today.toString()) yr++
    }
    val made = safeDate(yr, mo, dy) ?: return text to null

    return strip(text, m.range) to made
}

/**
 * Pull a time like "2pm" / "2:30 pm" out of text: the cleaned text and the minutes past
 * midnight (null when nothing lifted). 12am is midnight, 12pm noon.
 */
private fun parseTimeFromText(text: String): Pair<String, Int?> {
    val m = timeRegex.find(text) ?: return text to null
    var h = m.groupValues[1].toIntOrNull() ?: 0
    val min = m.groupValues[2].toIntOrNull() ?: 0
    val ap = m.groupValues[3].lowercase()
    if (h < 1 || h > 12 || min >= 60) return text to null
    if (ap == "p" && h < 12) h += 12
    if (ap == "a" && h == 12) h = 0
    return strip(text, m.range) to (h * 60 + min)
}

// MARK: - Plumbing

private val doubledSpace = Regex("""\s{2,}""")

/** Remove the match and tidy what's left — the web's str_replace + collapse of any
 *  doubled whitespace, so the cleaned text is byte-identical across platforms. */
private fun strip(text: String, range: IntRange): String =
    text.removeRange(range).replace(doubledSpace, " ").trim()

private fun safeDate(year: Int, month: Int, day: Int): LocalDate? =
    try { LocalDate.of(year, month, day) } catch (e: Exception) { null }
