package com.seancheren.suite.core

import java.time.LocalDate

/** What was typed, once a date and a time have been lifted out of it. */
data class Parsed(
    val text: String,
    val date: LocalDate? = null,
    val minutes: Int? = null,
)

// Times are 2pm / 2:30 pm. Dates are slash-only and US-order — m/d, m/d/yy, m/d/yyyy —
// deliberately narrow so they can't wander into other numbers in the sentence. The
// lookbehind/lookahead keep them from biting into a longer number.
private val timeRegex = Regex("""(?<![\d:])(\d{1,2})(?::([0-5]\d))?\s*([ap])\.?m\.?""", RegexOption.IGNORE_CASE)
private val dateRegex = Regex("""(?<![\d/])(\d{1,2})/(\d{1,2})(?:/(\d{2}|\d{4}))?(?![\d/])""")

/**
 * Reads "Vet 8/3 2pm" as a vet appointment on 3 August at two — the cousin of
 * ios/Shared/Parse.swift's parseWhen. It still can't tell a date from a fraction, so
 * "2/3 cup" parses as 3 February; an explicit date field always wins over this.
 */
fun parseWhen(raw: String, now: LocalDate = LocalDate.now()): Parsed {
    var text = raw
    var minutes: Int? = null
    var date: LocalDate? = null

    timeRegex.find(text)?.let { m ->
        val hour = m.groupValues[1].toIntOrNull() ?: 0
        val mins = m.groupValues[2].toIntOrNull() ?: 0
        val pm = m.groupValues[3].lowercase() == "p"
        if (hour in 1..12) {
            val h24 = if (pm) (hour % 12) + 12 else hour % 12
            minutes = h24 * 60 + mins
            text = text.replaceRange(m.range, " ")
        }
    }

    dateRegex.find(text)?.let { m ->
        val month = m.groupValues[1].toIntOrNull() ?: 0
        val day = m.groupValues[2].toIntOrNull() ?: 0
        val yearS = m.groupValues[3].ifEmpty { null }
        if (month in 1..12 && day in 1..31) {
            var year = when {
                yearS == null -> now.year
                yearS.length <= 2 -> 2000 + yearS.toInt()
                else -> yearS.toInt()
            }
            var made = safeDate(year, month, day)
            if (made != null) {
                // A bare m/d means the next one coming, not one that's already gone.
                if (yearS == null && made < now) {
                    year += 1
                    made = safeDate(year, month, day)
                }
                if (made != null) {
                    date = made
                    text = text.replaceRange(m.range, " ")
                }
            }
        }
    }

    return Parsed(
        text = text.trim().replace("  ", " "),
        date = date,
        minutes = minutes,
    )
}

private fun safeDate(year: Int, month: Int, day: Int): LocalDate? =
    try { LocalDate.of(year, month, day) } catch (e: Exception) { null }
