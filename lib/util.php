<?php
/** Small shared helpers used by more than one app. */

/**
 * The permanent Reminders section whose undated items ride along on the Calendar.
 * Anything filed here with no due date shows up under today, every day, until it's
 * ticked off. Reminders renders it; the Calendar reads it.
 */
const CALENDAR_SECTION = 'Calendar';

/**
 * One colour per kind of thing, for every app that draws a dot, a chip or a tag.
 * Reminders are the suite's green, events blue, notes purple — the blue is a proper
 * blue rather than a cyan so it can't be mistaken for the green at dot size.
 * Emit this inside a page's <style> and use var(--k-event) and friends.
 */
const KIND_BLUE = '#60a5fa';   // also CAL_COLORS[0]; see cal_color_fix()

function kind_color_css(): string
{
    $blue = KIND_BLUE;
    return <<<CSS
    :root {
      --k-reminder: #34d399; --k-reminder-bg: #06251b; --k-reminder-soft: #14332a;
      --k-event: {$blue};    --k-event-bg: #10233f;    --k-event-soft: #9ec5fb;
      --k-note: #8b6ef0;     --k-note-bg: #241a3a;     --k-note-soft: #b9a7f5;
      --k-overdue: #f0a860;  --k-overdue-bg: #3a2410;
      --k-done: #555;
    }
    CSS;
}

/**
 * Calendars stored the old cyan before the palette moved to a truer blue. Their colour
 * is remapped as it's read, so a calendar made back then matches the rest of the suite
 * and still lines up with a swatch in the picker. The next colour change writes it out.
 */
function cal_color_fix(?string $color): string
{
    $color = (string) $color;
    return strcasecmp($color, '#38bdf8') === 0 ? KIND_BLUE : $color;
}

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
