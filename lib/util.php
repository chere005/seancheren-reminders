<?php
/** Small shared helpers used by more than one app. */

/**
 * The permanent Reminders section whose undated items ride along on the Calendar.
 * Anything filed here with no due date shows up under today, every day, until it's
 * ticked off. Reminders renders it; the Calendar reads it.
 */
const CALENDAR_SECTION = 'Calendar';

/** Pull a time like "2pm" / "2:30 pm" out of text; returns [cleanedText, "HH:MM"|null]. */
function parse_time_from_text(string $text): array
{
    if (preg_match('/\b(\d{1,2})(?::(\d{2}))?\s*([apAP])\.?[mM]\.?\b/', $text, $m)) {
        $h   = (int) $m[1];
        $min = ($m[2] ?? '') !== '' ? (int) $m[2] : 0;
        $ap  = strtolower($m[3]);
        if ($h >= 1 && $h <= 12 && $min < 60) {
            if ($ap === 'p' && $h < 12) { $h += 12; }
            if ($ap === 'a' && $h === 12) { $h = 0; }
            $clean = trim(preg_replace('/\s{2,}/', ' ', str_replace($m[0], '', $text)));
            return [$clean, sprintf('%02d:%02d', $h, $min)];
        }
    }
    return [$text, null];
}

/**
 * Pull a numeric date out of text; returns [cleanedText, "YYYY-MM-DD"|null].
 * Accepts m/d, m/d/yy and m/d/yyyy, with one or two digits for month and day.
 * A bare m/d means the next occurrence — this year, or next year if it's past.
 * Deliberately US-order and slash-only, so it can't swallow other numbers.
 */
function parse_date_from_text(string $text): array
{
    if (!preg_match('#(?<![\d/])(\d{1,2})/(\d{1,2})(?:/(\d{2}|\d{4}))?(?![\d/])#', $text, $m)) {
        return [$text, null];
    }
    $mo = (int) $m[1];
    $dy = (int) $m[2];
    if ($mo < 1 || $mo > 12 || $dy < 1 || $dy > 31) {
        return [$text, null];
    }

    if (($m[3] ?? '') !== '') {
        $yr = (int) $m[3];
        if (strlen($m[3]) === 2) { $yr += 2000; }
    } else {
        // No year given: this year, unless that date has already gone by.
        $yr = (int) date('Y');
        if (sprintf('%04d-%02d-%02d', $yr, $mo, $dy) < date('Y-m-d')) { $yr++; }
    }
    if (!checkdate($mo, $dy, $yr)) {
        return [$text, null];
    }

    $clean = trim(preg_replace('/\s{2,}/', ' ', str_replace($m[0], '', $text)));
    return [$clean, sprintf('%04d-%02d-%02d', $yr, $mo, $dy)];
}

/** Both parsers at once: returns [cleanedText, "YYYY-MM-DD"|null, "HH:MM"|null]. */
function parse_when_from_text(string $text): array
{
    [$text, $date] = parse_date_from_text($text);
    [$text, $time] = parse_time_from_text($text);
    return [$text, $date, $time];
}
