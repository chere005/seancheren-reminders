<?php
/** Small shared helpers used by more than one app. */

/**
 * The two permanent reminder *folders*. They used to be permanent sections that sat
 * inside every folder at once, which meant a reminder had a folder and a group and the
 * group was sometimes really a folder. Now they're just folders, undeletable, always
 * first in the list:
 *
 *   Reminders — the catch-all a reminder lands in when nothing else is chosen.
 *   Calendar  — an undated reminder here rides along on the calendar under *today*,
 *               every day, until it's ticked off (it is not overdue; it isn't late).
 *
 * A folder holds user-made sections the same way it always did.
 */
const FOLDER_REMINDERS = 'Reminders';
const FOLDER_CALENDAR  = 'Calendar';

/** The old permanent section name, kept only so the migration can recognise it. */
const CALENDAR_SECTION = 'Calendar';

/**
 * Bring a reminders list up to the folder model above. Idempotent, and cheap enough to
 * run on every read — the readers that don't own the file (the Calendar, the widget
 * feed, quick.php, the API) normalise in memory and never write.
 *
 *   section "Calendar"      -> folder "Calendar", ungrouped
 *   folder "General"/absent -> folder "Reminders"
 *   the old "Calendar" section header rows are dropped; there is no such section now.
 */
function reminders_folder_migrate(array $list): array
{
    $out = [];
    foreach ($list as $it) {
        $isSec   = ($it['type'] ?? '') === 'section';
        $section = (string) ($it['section'] ?? '');
        $folder  = (string) ($it['folder'] ?? '');
        if ($isSec && strcasecmp((string) ($it['name'] ?? ''), CALENDAR_SECTION) === 0) {
            continue;                                  // the permanent section is a folder now
        }
        if (!$isSec && strcasecmp($section, CALENDAR_SECTION) === 0) {
            $it['folder']  = FOLDER_CALENDAR;
            $it['section'] = '';
        } elseif ($folder === '' || $folder === SECTION_DEFAULT_FOLDER) {
            $it['folder'] = FOLDER_REMINDERS;
        }
        $out[] = $it;
    }
    return $out;
}

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

/**
 * Repeating reminders and events.
 *
 * A rule is ['n' => 2, 'unit' => 'week'] — "every 2 weeks" — stored on the item
 * itself as `repeat`; absent or null means it happens once. There is only ever the
 * one stored row: the calendar expands it into the days it lands on, and ticking a
 * repeating reminder rolls its due date to the next occurrence rather than marking
 * the series done.
 *
 * Month and year steps keep the day of the month and clamp it to the target month,
 * so the 31st repeats monthly as the 30th, the 28th and so on rather than sliding
 * into the following month the way strtotime('+1 month') does.
 */
const REPEAT_UNITS = ['day', 'week', 'month', 'year'];
const REPEAT_MAX   = 400;   // a hard stop, so a bad rule can't spin

/** A rule from posted fields, or null when there isn't one. */
function repeat_clean($unit, $n): ?array
{
    $unit = (string) $unit;
    if (!in_array($unit, REPEAT_UNITS, true)) {
        return null;
    }
    $n = (int) $n;
    if ($n < 1)   { $n = 1; }
    if ($n > 999) { $n = 999; }
    return ['n' => $n, 'unit' => $unit];
}

/** A stored rule, validated on read like every other stored value. */
function repeat_get(array $item): ?array
{
    $rep = $item['repeat'] ?? null;
    return is_array($rep) ? repeat_clean($rep['unit'] ?? '', $rep['n'] ?? 1) : null;
}

/** The occurrence $i steps after "YYYY-MM-DD" $start. */
function repeat_step(string $start, array $rep, int $i): string
{
    [$y, $m, $d] = array_map('intval', explode('-', $start));
    $n = $rep['n'] * $i;
    switch ($rep['unit']) {
        case 'day':   return date('Y-m-d', mktime(0, 0, 0, $m, $d + $n, $y));
        case 'week':  return date('Y-m-d', mktime(0, 0, 0, $m, $d + $n * 7, $y));
        case 'month': $m += $n; break;
        case 'year':  $y += $n; break;
    }
    $first = mktime(0, 0, 0, $m, 1, $y);
    return date('Y-m-d', mktime(0, 0, 0, (int) date('n', $first), min($d, (int) date('t', $first)), (int) date('Y', $first)));
}

/**
 * Every occurrence of $start's rule landing in [$from, $to] (all YYYY-MM-DD).
 * A one-off is just itself when it falls in the window.
 */
function repeat_dates(string $start, ?array $rep, string $from, string $to): array
{
    if ($start === '' || $to < $from) {
        return [];
    }
    if ($rep === null) {
        return ($start >= $from && $start <= $to) ? [$start] : [];
    }
    $out = [];
    for ($i = 0; $i < REPEAT_MAX; $i++) {
        $d = repeat_step($start, $rep, $i);
        if ($d > $to)    { break; }
        if ($d >= $from) { $out[] = $d; }
    }
    return $out;
}

/** The first occurrence strictly after $after, for rolling a ticked reminder on. */
function repeat_next(string $start, array $rep, string $after): string
{
    for ($i = 1; $i < REPEAT_MAX; $i++) {
        $d = repeat_step($start, $rep, $i);
        if ($d > $after) { return $d; }
    }
    return $start;
}

/** "Every 2 weeks" / "Every day", for showing on a row. */
function repeat_label(?array $rep): string
{
    if ($rep === null) {
        return '';
    }
    return $rep['n'] === 1
        ? 'Every ' . $rep['unit']
        : 'Every ' . $rep['n'] . ' ' . $rep['unit'] . 's';
}

/**
 * Rename a section in one of the sectioned lists (reminders, notes, habits, book
 * notes — they all store a header row plus a `section` on each item), taking its
 * rows with it. Returns the list unchanged if the new name is empty or taken.
 */
/**
 * Rename a section. Sections belong to a folder, so a rename is scoped to one: pass
 * $folder to rename only that folder's section (and re-point only its items). $folder
 * null keeps the old suite-wide behaviour (used where sections aren't foldered, e.g.
 * anything that hasn't been migrated).
 */
function section_rename(array $list, string $old, string $new, ?string $folder = null): array
{
    $new = trim(preg_replace('/\s+/', ' ', $new));
    if ($new === '' || $new === $old) { return $list; }
    $inFolder = fn($it) => $folder === null
        || ($it['folder'] ?? SECTION_DEFAULT_FOLDER) === $folder;
    foreach ($list as $it) {
        if (($it['type'] ?? '') === 'section' && strcasecmp((string) ($it['name'] ?? ''), $new) === 0
            && $inFolder($it)) {
            return $list;   // that name is already a section in this folder
        }
    }
    foreach ($list as &$it) {
        if (($it['type'] ?? '') === 'section') {
            if (($it['name'] ?? '') === $old && $inFolder($it)) { $it['name'] = $new; }
        } elseif (($it['section'] ?? '') === $old && $inFolder($it)) {
            $it['section'] = $new;
        }
    }
    unset($it);
    return $list;
}

/** Default folder a section falls back to when a row or item doesn't name one. */
const SECTION_DEFAULT_FOLDER = 'General';

/**
 * Bring an old list up to per-folder sections. Legacy section rows carried no `folder`
 * and were shown in every folder; here each is replaced by one row per folder that
 * actually uses it (so existing grouping is preserved), falling back to General when a
 * section has no items. Idempotent: a list with no legacy rows is returned untouched.
 */
function sections_migrate(array $list, string $defaultFolder = SECTION_DEFAULT_FOLDER): array
{
    $hasLegacy = false;
    foreach ($list as $it) {
        if (($it['type'] ?? '') === 'section' && !array_key_exists('folder', $it)) { $hasLegacy = true; break; }
    }
    if (!$hasLegacy) { return $list; }

    $items = array_filter($list, fn($it) => ($it['type'] ?? '') !== 'section');
    $have  = [];   // "folder\x1Fname" of section rows that already exist, tagged
    $out   = [];
    foreach ($list as $it) {
        if (($it['type'] ?? '') === 'section') {
            if (!array_key_exists('folder', $it)) { continue; }   // drop legacy; re-add below
            $have[$it['folder'] . "\x1F" . $it['name']] = true;
        }
        $out[] = $it;
    }
    foreach ($list as $sec) {
        if (($sec['type'] ?? '') !== 'section' || array_key_exists('folder', $sec)) { continue; }
        $name = (string) ($sec['name'] ?? '');
        $folders = [];
        foreach ($items as $r) {
            if ((string) ($r['section'] ?? '') === $name) { $folders[$r['folder'] ?? $defaultFolder] = true; }
        }
        if (!$folders) { $folders[$defaultFolder] = true; }
        foreach (array_keys($folders) as $f) {
            if (isset($have[$f . "\x1F" . $name])) { continue; }
            $out[] = ['id' => bin2hex(random_bytes(6)), 'type' => 'section', 'name' => $name,
                      'folder' => $f, 'created' => $sec['created'] ?? time()];
            $have[$f . "\x1F" . $name] = true;
        }
    }
    return $out;
}
