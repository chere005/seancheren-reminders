<?php
// Locate the shared lib/ — local dev (../../lib) or NFSN (/home/protected/lib).
$__libDir = null;
foreach ([__DIR__ . '/../../lib', '/home/protected/lib'] as $__c) {
    if (is_file($__c . '/auth.php')) { $__libDir = $__c; break; }
}
require_once $__libDir . '/auth.php';
require_once $__libDir . '/tabbar.php';
require_once $__libDir . '/chrome.php';
require_once $__libDir . '/palette.php';   // the section colour dots
require_login('Habits');

$cfg      = app_config();
$dataFile = user_data_file($cfg['data_dir'], 'habits');
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }

function e(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES); }
function load_habits(string $f): array { return store_read($f); }
function save_habits(string $f, array $h): void { store_write($f, array_values($h)); }
function is_section(array $it): bool { return ($it['type'] ?? '') === 'section'; }

/**
 * A habits section's colour, defaulting by position so a new section is distinct as
 * soon as it exists. Habits has no palette tier of its own — it borrows the lighter
 * "shared" set (app_palette(…, true)), which sits closer to this app's soft violet
 * than the saturated folder colours do. Stored on the section row itself, keyed by id
 * like everything else here, rather than in a side file.
 */
function habits_palette(): array { return app_palette('habits', true); }

function habit_section_color(array $s, int $i): string
{
    $pal = habits_palette();
    $c   = (string) ($s['color'] ?? '');
    return in_array($c, $pal, true) ? $c : $pal[$i % count($pal)];
}

// Render one habit's name bubble + 7 day cells into the grid.
function render_habit_row(array $h, array $days, string $today, string $csrf, int $extra = 0): void { ?>
        <div class="hname" data-id="<?= e($h['id']) ?>" data-section="<?= e($h['section'] ?? '') ?>">
          <span class="hdrag" title="Drag to reorder" aria-hidden="true">&#9776;</span>
          <span class="hlabel"><?= e($h['name'] ?? '') ?></span>
          <form method="post" action="" style="display:inline">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="delete_habit">
            <input type="hidden" name="id" value="<?= e($h['id']) ?>">
            <button class="del needs-confirm" type="submit" title="Delete habit">&times;</button>
          </form>
        </div>
        <?php foreach ($days as $i => $d): $done = !empty($h['done'][$d]); ?>
          <button class="cell <?= $i < $extra ? 'wide-only' : '' ?> <?= $done ? 'done' : '' ?> <?= $d === $today ? 'today' : ($d > $today ? 'ahead' : '') ?>"
                  data-id="<?= e($h['id']) ?>" data-date="<?= $d ?>" aria-label="<?= e(($h['name'] ?? '') . ' ' . $d) ?>"></button>
        <?php endforeach;
}

// --- Mutations ---
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action'])) {
    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400); exit('Bad request (invalid CSRF token).');
    }
    $habits = load_habits($dataFile);

    if ($_POST['action'] === 'toggle') {                 // AJAX: flip a day cell
        $id = (string) ($_POST['id'] ?? '');
        $d  = (string) ($_POST['date'] ?? '');
        $now = false;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            foreach ($habits as &$h) {
                if (($h['id'] ?? '') === $id) {
                    if (!empty($h['done'][$d])) { unset($h['done'][$d]); $now = false; }
                    else { $h['done'][$d] = true; $now = true; }
                    break;
                }
            }
            unset($h);
            save_habits($dataFile, $habits);
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'done' => $now]);
        exit;
    }

    // Inline rename of a habit (AJAX, from a double-tap or an edit-mode tap on its name).
    if ($_POST['action'] === 'rename_habit') {
        $id   = (string) ($_POST['id'] ?? '');
        $name = trim(preg_replace('/\s+/', ' ', (string) ($_POST['name'] ?? '')));
        if ($name !== '') {
            foreach ($habits as &$h) {
                if (!is_section($h) && ($h['id'] ?? '') === $id) { $h['name'] = mb_substr($name, 0, 40); break; }
            }
            unset($h);
            save_habits($dataFile, $habits);
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }

    // Recolour a section (AJAX, from the swatch on its header). Posts in the background
    // and recolours in place, the way the folder manager's swatch does, so the page
    // never reloads out from under an edit-mode tap.
    if ($_POST['action'] === 'set_section_color') {
        $id  = (string) ($_POST['id'] ?? '');
        $col = (string) ($_POST['color'] ?? '');
        if (in_array($col, habits_palette(), true)) {
            foreach ($habits as &$it) {
                if (is_section($it) && ($it['id'] ?? '') === $id) { $it['color'] = $col; break; }
            }
            unset($it);
            save_habits($dataFile, $habits);
        }
        // Answer with the colour that was actually stored, not the one that was asked
        // for: a colour off the palette is refused above, and the swatch has to go back
        // to what's really there rather than sit showing a change that didn't happen.
        $now = ''; $i = 0;
        foreach ($habits as $it) {
            if (!is_section($it)) { continue; }
            if (($it['id'] ?? '') === $id) { $now = habit_section_color($it, $i); break; }
            $i++;
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'color' => $now]);
        exit;
    }

    // Drag-reorder of habits and sections (AJAX). The stored order *is* the display
    // order here — ungrouped habits first, then each section row followed by its own —
    // so the whole list is rewritten from what the drag ended up with rather than
    // patched in place. Anything the client didn't mention keeps its place at the end,
    // so a stale page can't quietly drop a habit.
    if ($_POST['action'] === 'reorder') {
        $order = json_decode((string) ($_POST['order'] ?? ''), true);       // [{id, section}, …]
        $secs  = json_decode((string) ($_POST['sections'] ?? ''), true);    // [section id, …]
        if (is_array($order) && is_array($secs)) {
            $byId = [];
            foreach ($habits as $it) { $byId[(string) ($it['id'] ?? '')] = $it; }
            $secIds = [];
            foreach ($habits as $it) { if (is_section($it)) { $secIds[] = (string) $it['id']; } }
            // Only ids we actually hold, and each one only once.
            $secs = array_values(array_unique(array_filter(array_map('strval', $secs),
                fn($id) => in_array($id, $secIds, true))));
            foreach ($secIds as $id) { if (!in_array($id, $secs, true)) { $secs[] = $id; } }

            $want = [];   // section id ('' = ungrouped) => [habit id, …]
            foreach ($order as $row) {
                $id  = (string) ($row['id'] ?? '');
                $sec = (string) ($row['section'] ?? '');
                if (!isset($byId[$id]) || is_section($byId[$id])) { continue; }
                if ($sec !== '' && !in_array($sec, $secs, true)) { $sec = ''; }
                $want[$sec][] = $id;
            }
            $placed = [];
            foreach ($want as $rows) { foreach ($rows as $id) { $placed[$id] = true; } }
            // Whatever the drag never mentioned stays where it was, under its own section.
            foreach ($habits as $it) {
                if (is_section($it) || isset($placed[(string) $it['id']])) { continue; }
                $sec = (string) ($it['section'] ?? '');
                if ($sec !== '' && !in_array($sec, $secs, true)) { $sec = ''; }
                $want[$sec][] = (string) $it['id'];
            }

            $out = [];
            foreach ($want[''] ?? [] as $id) { $h = $byId[$id]; $h['section'] = ''; $out[] = $h; }
            foreach ($secs as $sid) {
                $out[] = $byId[$sid];
                foreach ($want[$sid] ?? [] as $id) { $h = $byId[$id]; $h['section'] = $sid; $out[] = $h; }
            }
            save_habits($dataFile, $out);
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }

    // Nothing destructive happens without the confirmed second press.
    if (in_array($_POST['action'], ['delete_habit', 'delete_section'], true)
        && empty($_POST['confirm'])) {
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?edit=1');
        exit;
    }

    $stay = '?edit=1';   // these are all edit-mode controls; hand edit mode back
    if ($_POST['action'] === 'add_habit') {
        $name = trim(preg_replace('/\s+/', ' ', (string) ($_POST['name'] ?? '')));
        $section = (string) ($_POST['section'] ?? '');
        // Only keep a section id that actually exists.
        $validSection = '';
        foreach ($habits as $it) { if (is_section($it) && ($it['id'] ?? '') === $section) { $validSection = $section; break; } }
        if ($name !== '') {
            $habits[] = ['id' => bin2hex(random_bytes(6)), 'name' => mb_substr($name, 0, 40), 'done' => new stdClass(), 'section' => $validSection, 'created' => time()];
            save_habits($dataFile, $habits);
        }
    } elseif ($_POST['action'] === 'add_section') {
        $name = mb_substr(trim(preg_replace('/\s+/', ' ', (string) ($_POST['name'] ?? ''))), 0, 40);
        $exists = false;
        foreach ($habits as $it) { if (is_section($it) && mb_strtolower($it['name'] ?? '') === mb_strtolower($name)) { $exists = true; break; } }
        if ($name !== '' && !$exists) {
            $habits[] = ['id' => bin2hex(random_bytes(6)), 'type' => 'section', 'name' => $name, 'created' => time()];
            save_habits($dataFile, $habits);
        }
    } elseif ($_POST['action'] === 'rename_section') {
        // Sections are keyed by id here, so the rows don't need re-pointing.
        $id   = (string) ($_POST['id'] ?? '');
        $name = trim(preg_replace('/\s+/', ' ', (string) ($_POST['newname'] ?? '')));
        if ($name !== '') {
            foreach ($habits as &$it) {
                if (is_section($it) && ($it['id'] ?? '') === $id) { $it['name'] = mb_substr($name, 0, 40); break; }
            }
            unset($it);
            save_habits($dataFile, $habits);
        }
    } elseif ($_POST['action'] === 'delete_section') {
        $id = (string) ($_POST['id'] ?? '');
        $habits = array_values(array_filter($habits, fn($it) => !(is_section($it) && ($it['id'] ?? '') === $id)));
        foreach ($habits as &$it) { if (($it['section'] ?? '') === $id) { $it['section'] = ''; } }
        unset($it);
        save_habits($dataFile, $habits);
    } elseif ($_POST['action'] === 'delete_habit') {
        $id = (string) ($_POST['id'] ?? '');
        $habits = array_values(array_filter($habits, fn($h) => is_section($h) || ($h['id'] ?? '') !== $id));
        save_habits($dataFile, $habits);
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . $stay);
    exit;
}

// --- Render ---
$habits = load_habits($dataFile);
$today  = date('Y-m-d');

/**
 * Two views, picked from the bar at the top and remembered per user in prefs-<user>.json
 * (the same little settings file the theme lives in), so the app reopens on the one you
 * last used rather than always on the week.
 *
 *   week  — the tick grid: habits down the side, days across the top.
 *   month — one calendar cell per day, each a pie of how much of that day got ticked.
 */
$prefsFile = theme_file();
if (isset($_GET['v']) && in_array($_GET['v'], ['week', 'month'], true)) {
    $pr = store_read($prefsFile);
    if (($pr['habits_view'] ?? '') !== $_GET['v']) { $pr['habits_view'] = $_GET['v']; store_write($prefsFile, $pr); }
    $hView = (string) $_GET['v'];
} else {
    $hView = (string) (store_read($prefsFile)['habits_view'] ?? 'week');
    if (!in_array($hView, ['week', 'month'], true)) { $hView = 'week'; }
}

// ?w= steps the week grid whole weeks at a time (0 = the one ending tomorrow). Swiping
// sideways and the ‹ › arrows both go through this, so they can't disagree.
$weekOff = (int) ($_GET['w'] ?? 0);
if ($weekOff < -520 || $weekOff > 520) { $weekOff = 0; }

$days = [];
// Seven days back through tomorrow (eight columns), so today sits second from the
// right and you can tick something off a day early. A narrow screen only has room for
// five, so the three oldest are rendered anyway and hidden by CSS — the grid is one
// layout with a column count that changes, not two renders.
for ($i = 6; $i >= -1; $i--) {
    $days[] = date('Y-m-d', strtotime(($weekOff * 7 - $i) . ' days'));
}
$extraDays = count($days) - 5;   // the columns only a wide screen shows
$csrf   = htmlspecialchars($_SESSION['csrf'], ENT_QUOTES);

// Split sections from habits; group habits under their section (ungrouped first).
$sections   = array_values(array_filter($habits, 'is_section'));
$habitItems = array_values(array_filter($habits, fn($h) => !is_section($h)));
$sectionIds = array_map(fn($s) => $s['id'], $sections);
$ungrouped  = array_values(array_filter($habitItems, fn($h) => !in_array($h['section'] ?? '', $sectionIds, true)));
$bySection  = fn(string $sid) => array_values(array_filter($habitItems, fn($h) => ($h['section'] ?? '') === $sid));

// --- Month view: one cell per day, filled in proportion to that day's ticks ---
// ?m=YYYY-MM picks the month; the fraction is (habits ticked that day) / (habits), so a
// full circle is a day where everything got done and an empty one is a day where nothing
// did. Future days are drawn as outlines — there's nothing to have done yet.
$mym = (string) ($_GET['m'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $mym)) { $mym = date('Y-m'); }
[$mYear, $mMon] = array_map('intval', explode('-', $mym));
$mFirstTs   = mktime(0, 0, 0, $mMon, 1, $mYear);
$mDays      = (int) date('t', $mFirstTs);
$mLead      = (int) date('w', $mFirstTs);
$mName      = date('F Y', $mFirstTs);
$mPrev      = date('Y-m', mktime(0, 0, 0, $mMon - 1, 1, $mYear));
$mNext      = date('Y-m', mktime(0, 0, 0, $mMon + 1, 1, $mYear));
$habitTotal = count($habitItems);
$monthDone  = [];   // 'YYYY-MM-DD' => how many habits were ticked that day
foreach ($habitItems as $h) {
    foreach ((array) ($h['done'] ?? []) as $d => $on) {
        if ($on && strncmp((string) $d, $mym, 7) === 0) { $monthDone[(string) $d] = ($monthDone[(string) $d] ?? 0) + 1; }
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>Habits</title>
  <meta name="theme-color" content="#111111">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black">
  <meta name="apple-mobile-web-app-title" content="Habits">
  <link rel="apple-touch-icon" href="/reminders/icon-180.png">
  <link rel="manifest" href="/reminders/manifest.webmanifest?v=2">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: system-ui, sans-serif; background: #111; color: #eee; min-height: 100vh; padding: 1.5rem 1rem; overscroll-behavior-y: none; }
    .wrap { max-width: 640px; margin: 0 auto; }   /* same column as Reminders + Calendar */
    header { display: flex; align-items: center; justify-content: space-between; }
    header h1 { font-size: 1.35rem; }   /* same as the Calendar's */
    header .titlebar { display: flex; align-items: center; gap: 0.85rem; }
    header nav { display: flex; align-items: center; gap: 0.5rem; }
    header nav a { color: #888; text-decoration: none; font-size: 0.85rem; }
    header nav a:hover { color: #fff; }
    header nav .who { color: var(--accent); font-size: 0.8rem; border: 1px solid #2a4a3d; border-radius: 999px; padding: 0.15rem 0.6rem; }

    .bar { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1.25rem; flex-wrap: wrap; padding-left: 0; }
    body:not(.editing) .bar { justify-content: flex-end; }   /* Edit keeps the right edge */
    .bar form.addh { flex: 1 1 220px; }
    body:not(.editing) .bar form.addh { display: none; }   /* edit mode only */
    .bar input[type=text] {
      width: 100%; padding: 0.6rem 0.75rem; background: #1a1a1a; border: 1px dashed #4a3f6a;
      border-radius: 8px; color: #b9a7f5; font-size: 1rem;
    }
    .bar input::placeholder { color: #b9a7f5; opacity: 0.75; }
    .bar input:focus { outline: none; border-style: solid; border-color: #8b6ef0; }
    .bar .hsel { padding: 0.55rem 0.6rem; background: #1a1a1a; border: 1px solid #333; color: #ccc; border-radius: 999px; font-size: 16px; }

    /* + Section — left-aligned amber pill above the day grid. */
    .newsection { margin: 0 0 1.1rem; }
    body:not(.editing) .newsection { display: none; }   /* edit mode only */
    .newsection input {
      width: 220px; max-width: 100%; padding: 0.45rem 0.85rem; background: #1a1a1a;
      border: 1px dashed #4a3f6a; border-radius: 999px; color: #b9a7f5; font-size: 16px;
    }
    .newsection input::placeholder { color: #b9a7f5; opacity: 0.8; }
    .newsection input:focus { outline: none; border-style: solid; border-color: #8b6ef0; }

    /* Grid: name column + day columns. The name column takes at least half the width
       and absorbs the rest; the day squares are capped small so the habit name has
       room to read rather than being squeezed by the grid. */
    .grid { display: grid; grid-template-columns: minmax(120px, 1fr) repeat(8, minmax(0, 50px)); gap: 6px; align-items: center; width: 100%; }
    /* Five days is all a phone has room for; the three oldest columns are in the
       DOM either way, so this is one grid with a different column count. */
    @media (max-width: 640px) {
      .grid { grid-template-columns: minmax(96px, 1fr) repeat(5, minmax(0, 44px)); }
      .wide-only { display: none; }
    }
    .colhead {
      text-align: center; font-family: ui-monospace, Menlo, monospace; font-size: 0.8rem;
      color: #888; padding-bottom: 0.4rem; border-radius: 8px 8px 0 0;
    }
    /* Today's column has to be findable at a glance on a phone, where five columns of
       small squares look much alike. The 6px grid gap means a background tint can't
       actually join the column up — it draws as one faint patch behind the head and
       nothing under it — so today is marked twice instead: a filled pill on the head,
       and an accent ring on every cell below it (see .cell.today). */
    .colhead.today {
      color: var(--accent-ink); font-weight: 700; background: var(--accent);
      border-radius: 8px; padding: 0.3rem 0 0.35rem;
    }
    .colhead.ahead { color: #666; }        /* tomorrow, ticked off early */
    .colhead .num { display: block; font-size: 0.95rem; margin-top: 0.1rem; }
    .corner { }

    /* Section header row spans the full grid width. */
    .hsection {
      grid-column: 1 / -1; display: flex; align-items: center; gap: 0.5rem;
      margin: 0.9rem 0 0.1rem; padding: 0 0.1rem 0.35rem 0.1rem;   /* flush with the grid */
      color: #b9a7f5; font-weight: 700; font-size: 0.95rem; border-bottom: 1px solid #2c2540;
    }
    .hsection .hslabel { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    /* The drag handle on a habit and on a section. Hidden with visibility rather than
       display, as everywhere else, so turning edit mode on doesn't shove the names
       sideways. Nothing else on the grid moves. */
    .hdrag {
      flex: 0 0 auto; width: 16px; color: #6a5f8c; cursor: grab; visibility: hidden;
      display: inline-flex; align-items: center; justify-content: center; font-size: 0.9rem;
      line-height: 1; touch-action: none; user-select: none; -webkit-user-select: none;
    }
    body.editing .hdrag { visibility: visible; }
    .hdrag:active { cursor: grabbing; color: var(--accent); }
    /* What is being dragged dims; where it will land is a single accent line, because
       shuffling a CSS grid live made it impossible to see what you were about to get. */
    .hname.hdragging, .hsection.hdragging { opacity: 0.4; }
    .hdrop { outline: 2px solid var(--accent); outline-offset: 2px; border-radius: 8px; }
    /* The section's colour dot, left of its name — the same swatch-opens-a-palette
       control the folder manager uses, shrunk to the size of the folder heading's dot.
       It's the same element in and out of edit mode, so turning editing on shifts
       nothing; out of edit mode it simply doesn't answer taps. */
    .hsection .hcolor { flex: 0 0 auto; position: relative; }
    .hsection .hcolor summary {
      position: relative; width: 11px; height: 11px; border-radius: 50%;
      border: 1px solid #3d3559; cursor: pointer; list-style: none;
    }
    .hsection .hcolor summary::-webkit-details-marker { display: none; }
    body:not(.editing) .hsection .hcolor { pointer-events: none; }
    body:not(.editing) .hsection .hcolor summary { cursor: default; }
    /* An 11px dot is far too small to hit with a thumb, so in edit mode it gets an
       invisible 27px tap target around it rather than growing and moving the name. */
    body.editing .hsection .hcolor summary::after { content: ''; position: absolute; inset: -8px; }
    .hsection .hswatches {
      position: absolute; z-index: 5; top: calc(100% + 8px); left: -6px;
      background: #1c1c1c; border: 1px solid #444; border-radius: 10px; padding: 0.5rem;
      display: grid; grid-template-columns: repeat(6, 22px); gap: 0.4rem;   /* all six on one row */
      box-shadow: 0 8px 20px rgba(0,0,0,0.6);
    }
    .hsection .hswatches button {
      width: 22px; height: 22px; border-radius: 50%; border: 1px solid #444;
      cursor: pointer; padding: 0;
    }
    .hsection .del { display: none; margin-left: auto; background: none; border: 1px solid #444; color: #ccc; border-radius: 6px; padding: 0.1rem 0.45rem; font-size: 0.9rem; line-height: 1; cursor: pointer; }
    body.editing .hsection .del { display: inline-block; }
    .hsection .del:hover { border-color: #f66; color: #f66; }

    /* The name bubble hugs the text and centres itself in the column rather than filling
       it, so it reads as a label on a pill, not a big empty input. The name centres, wraps
       to at most two lines and hyphenates a word too long to fit. */
    .hname {
      position: relative; background: #1b1726; border: 1px solid #2c2540; border-radius: 8px;
      padding: 0.3rem 0.6rem; min-height: 28px; display: flex; align-items: center;
      gap: 0.35rem; justify-content: center; justify-self: center; width: fit-content; max-width: 100%;
    }
    .hname .hlabel {
      color: #d9d2f0; font-size: 1.02rem; text-align: center; line-height: 1.15;
      overflow-wrap: anywhere; hyphens: auto; -webkit-hyphens: auto;
      display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
    }
    .hname .del { display: none; flex: 0 0 auto; background: none; border: 1px solid #444; color: #ccc; border-radius: 6px; padding: 0.15rem 0.45rem; font-size: 0.9rem; line-height: 1; cursor: pointer; }
    body.editing .hname .del { display: inline-block; }
    .hname .del:hover { border-color: #f66; color: #f66; }
    /* In edit mode the name is a field; a double-tap opens it outside edit mode too. */
    body.editing .hname .hlabel { cursor: text; }
    /* The edit field is sized to its text (a `size` set in JS), centred, and can wrap to a
       second line — a box just around the name rather than a full-width input. */
    .hname .hedit-name {
      flex: 0 1 auto; min-width: 2ch; max-width: 100%; font-size: 1.02rem; font-family: inherit;
      text-align: center; padding: 0.1rem 0.3rem; line-height: 1.15;
      background: #241f33; border: 1px solid #4a3f6a; border-radius: 4px; color: #d9d2f0;
    }
    .hname .hedit-name:focus { outline: none; border-color: #b9a7f5; }

    .cell {
      aspect-ratio: 1 / 1; min-height: 0; background: #1b1726; border: 1px solid #2c2540;
      border-radius: 8px; cursor: pointer; padding: 0; transition: background 0.1s;
    }
    /* 2px rather than 1px, and box-sizing keeps the square exactly the same size, so
       today's column doesn't shift the grid by a pixel as the date rolls over. */
    .cell.today { border: 2px solid var(--accent); background: var(--accent-soft); }
    .cell.ahead { opacity: 0.55; }         /* tomorrow reads as not-yet */
    .cell.done { background: var(--accent); border-color: var(--accent); }
    /* A ticked cell is already accent-filled, so today's ring has to be the light one. */
    .cell.done.today { border-color: #eafff6; }
    .cell:active { transform: scale(0.94); }

    .empty { color: #666; text-align: center; padding: 2rem 0; }

    /* The view bar: Week / Month on the left, the range and its arrows on the right.
       One row, the same 32px as everything else on the top bar. */
    .viewbar { display: flex; align-items: center; gap: 0.5rem; margin: 0 0 1rem; }
    .segpick { display: inline-flex; background: #0e0e0e; border: 1px solid #2a2a2a; border-radius: 999px; padding: 3px; }
    .segpick a {
      padding: 0.3rem 0.85rem; border-radius: 999px; text-decoration: none; color: #888;
      font-size: 0.82rem; font-weight: 600;
    }
    .segpick a.on { background: #2a2a2a; color: var(--accent); }
    .viewbar .range { margin-left: auto; display: inline-flex; align-items: center; gap: 0.4rem; }
    .viewbar .range .lbl { font-size: 0.85rem; color: #aaa; min-width: 7.5rem; text-align: center; }
    .viewbar .range a {
      width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center;
      border: 1px solid #333; border-radius: 999px; color: #ccc; text-decoration: none; font-size: 1rem;
    }
    .viewbar .range a:hover { border-color: #888; color: #fff; }

    /* Month view: a pie per day. The filled slice is how much of that day got ticked. */
    .mgrid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 6px; touch-action: pan-y; }
    .mgrid .dow { text-align: center; font-size: 0.7rem; color: #666; padding-bottom: 0.2rem; }
    .mgrid .mcell {
      aspect-ratio: 1 / 1; display: flex; flex-direction: column; align-items: center;
      justify-content: center; gap: 0.2rem; border-radius: 8px; border: 1px solid transparent;
    }
    /* Same as the week grid: a 2px accent ring, which reads at this size where a 1px
       one on a transparent border disappeared against the pies. */
    .mgrid .mcell.today { border: 2px solid var(--accent); background: var(--accent-soft); }
    .mgrid .mcell.blank { visibility: hidden; }
    .mgrid .mcell .pie {
      width: 60%; max-width: 34px; aspect-ratio: 1 / 1; border-radius: 50%;
      border: 1px solid #2c2540; background: #1b1726;
    }
    .mgrid .mcell.ahead .pie { opacity: 0.4; }
    .mgrid .mcell .dnum { font-size: 0.65rem; color: #777; line-height: 1; }
    /* Today's number is a filled chip, the one thing on the month that isn't a circle
       or a bare numeral, so the eye lands on it without hunting for the ring. */
    .mgrid .mcell.today .dnum {
      color: var(--accent-ink); font-weight: 700; background: var(--accent);
      border-radius: 999px; padding: 0.1rem 0.4rem;
    }
    .mlegend { margin-top: 0.9rem; font-size: 0.78rem; color: #666; text-align: center; }

    /* "+ Section" at the bottom of the habits, the same button-that-becomes-a-field the
       other apps use. Edit mode only, like every other structural control here. */
    .secfoot { margin: 1.1rem 0 0; display: flex; gap: 0.5rem; align-items: center; justify-content: center; flex-wrap: wrap; }
    .secfoot .newsection { margin: 0; }
    body:not(.editing) .secfoot { display: none; }
    /* Same grey outlined "+ Section" pill as Notes and Reminders, for consistency. */
    .secfoot button.newsecbtn {
      height: 32px; padding: 0 0.9rem; background: none; border: 1px solid #333;
      color: #ccc; border-radius: 999px; font-size: 0.9rem; font-family: inherit; cursor: pointer;
      display: inline-flex; align-items: center; justify-content: center; line-height: 1;
    }
    .secfoot button.newsecbtn:hover { border-color: #888; color: #fff; }
<?= tabbar_styles() ?>
<?= chrome_styles() ?>
  </style>
</head>
<body>
<div class="wrap">
  <header>
    <div class="hleft">
      <?= back_button() ?>
      <div class="titlebar">
        <h1>Habits</h1>
      </div>
    </div>
    <?php
      // The Edit pencil rides on the right, gathered by the ⋮.
      $titleControls = '<button type="button" id="editBtn" class="titlebtn edit-toggle" title="Edit"'
                     . ' aria-label="Edit">&#9998;&#65038;</button>';
    ?>
    <?= render_user_menu(false, 'editBtn', '', false, $titleControls) ?>
  </header>

  <?php // Week or month, and the arrows that step whichever one is showing. ?>
  <div class="viewbar">
    <div class="segpick">
      <a href="?v=week"<?= $hView === 'week' ? ' class="on"' : '' ?>>Week</a>
      <a href="?v=month"<?= $hView === 'month' ? ' class="on"' : '' ?>>Month</a>
    </div>
    <div class="range">
      <?php if ($hView === 'week'): ?>
        <a href="?w=<?= $weekOff - 1 ?>" id="wPrev" aria-label="Previous week">&lsaquo;</a>
        <span class="lbl"><?= $weekOff === 0 ? 'This week'
              : date('M j', strtotime($days[0])) . ' – ' . date('M j', strtotime(end($days))) ?></span>
        <a href="?w=<?= $weekOff + 1 ?>" id="wNext" aria-label="Next week">&rsaquo;</a>
      <?php else: ?>
        <a href="?m=<?= $mPrev ?>" id="mPrev" aria-label="Previous month">&lsaquo;</a>
        <span class="lbl"><?= e($mName) ?></span>
        <a href="?m=<?= $mNext ?>" id="mNext" aria-label="Next month">&rsaquo;</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($hView === 'month'): ?>
    <div class="mgrid" id="mGrid">
      <?php foreach (['S', 'M', 'T', 'W', 'T', 'F', 'S'] as $dw): ?>
        <div class="dow"><?= $dw ?></div>
      <?php endforeach; ?>
      <?php for ($i = 0; $i < $mLead; $i++): ?><div class="mcell blank"></div><?php endfor; ?>
      <?php for ($d = 1; $d <= $mDays; $d++):
              $ymd  = sprintf('%04d-%02d-%02d', $mYear, $mMon, $d);
              $done = $monthDone[$ymd] ?? 0;
              $frac = $habitTotal > 0 ? min(1, $done / $habitTotal) : 0;
              $pct  = round($frac * 100, 1);
              // A whole circle when everything's ticked, otherwise a wedge of the accent
              // over the empty colour — the same conic-gradient trick the calendar's
              // multi-colour dots use.
              $bg   = $frac <= 0 ? '#1b1726'
                    : ($frac >= 1 ? 'var(--accent)'
                    : 'conic-gradient(var(--accent) 0 ' . $pct . '%, #1b1726 ' . $pct . '% 100%)');
              $cls  = $ymd === $today ? ' today' : ($ymd > $today ? ' ahead' : '');
      ?>
        <div class="mcell<?= $cls ?>" title="<?= $done ?> of <?= $habitTotal ?> on <?= $ymd ?>">
          <span class="pie" style="background:<?= $bg ?>"></span>
          <span class="dnum"><?= $d ?></span>
        </div>
      <?php endfor; ?>
    </div>
    <p class="mlegend">Each day is filled in proportion to how many of your
      <?= $habitTotal ?> habit<?= $habitTotal === 1 ? '' : 's' ?> were ticked.</p>
  <?php elseif (!$habitItems && !$sections): ?>
    <p class="empty">No habits yet. Tap Edit to add one, then tap a day to mark it done.</p>
  <?php else: ?>
    <div class="grid" id="wGrid">
      <div class="corner"></div>
      <?php foreach ($days as $i => $d): $ts = strtotime($d); ?>
        <div class="colhead <?= $i < $extraDays ? 'wide-only' : '' ?> <?= $d === $today ? 'today' : ($d > $today ? 'ahead' : '') ?>">
          <?= substr(date('D', $ts), 0, 2) ?><span class="num"><?= (int) date('j', $ts) ?></span>
        </div>
      <?php endforeach; ?>

      <?php foreach ($ungrouped as $h) render_habit_row($h, $days, $today, $csrf, $extraDays); ?>

      <?php foreach ($sections as $si => $s): $scol = habit_section_color($s, $si); ?>
        <div class="hsection" data-section="<?= e($s['id']) ?>">
          <span class="hdrag" title="Drag to reorder" aria-hidden="true">&#9776;</span>
          <?php // The section's colour. Out of edit mode it's just a dot; in edit mode the
                // dot opens the palette under it, exactly as a folder's swatch does. It's
                // the same element either way, so entering edit mode shifts nothing. ?>
          <details class="hcolor">
            <summary style="background:<?= e($scol) ?>" title="Colour"></summary>
            <form class="hswatches" method="post" action="">
              <input type="hidden" name="csrf" value="<?= $csrf ?>">
              <input type="hidden" name="action" value="set_section_color">
              <input type="hidden" name="id" value="<?= e($s['id']) ?>">
              <?php foreach (habits_palette() as $col): ?>
                <button type="submit" name="color" value="<?= e($col) ?>"
                        style="background:<?= e($col) ?>" title="<?= e($col) ?>"></button>
              <?php endforeach; ?>
            </form>
          </details>
          <?= section_title_html($s['name'], $csrf, '', false, 'rename_section',
                '<input type="hidden" name="id" value="' . e($s['id']) . '">') ?>
          <form method="post" action="" style="display:inline">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="delete_section">
            <input type="hidden" name="id" value="<?= e($s['id']) ?>">
            <button class="del needs-confirm" type="submit" title="Delete section">&times;</button>
          </form>
        </div>
        <?php foreach ($bySection($s['id']) as $h) render_habit_row($h, $days, $today, $csrf, $extraDays); ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php // + Section sits under the habits, where you'd add one. ?>
  <?php if ($hView !== 'month'): ?>
    <div class="secfoot">
      <?php // + Habit and + Section, both buttons-that-become-a-field (edit mode only). ?>
      <button type="button" class="newsecbtn" id="newHabitBtn">+ Habit</button>
      <form method="post" action="" class="newsection" id="newHabitForm" hidden
            onsubmit="return this.name.value.trim()!==''">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="add_habit">
        <input type="text" name="name" placeholder="+ Habit" maxlength="40" autocomplete="off">
      </form>
      <button type="button" class="newsecbtn" id="newSecBtn">+ Section</button>
      <form method="post" action="" class="newsection" id="newSecForm" hidden
            onsubmit="return this.name.value.trim()!==''">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="add_section">
        <input type="text" name="name" placeholder="+ Section" maxlength="40" autocomplete="off">
      </form>
    </div>
  <?php endif; ?>
</div>


<?php render_tabbar('habits'); ?>
<script>
  const CSRF = '<?= $csrf ?>';

  // Edit mode (persisted, like the other tabs).
  const editBtn = document.getElementById('editBtn');
  const setEdit = (on) => document.body.classList.toggle('editing', on);
  // Always starts off; a structural change redirects back with ?edit=1 to keep it on.
  setEdit(new URLSearchParams(location.search).get('edit') === '1');
  editBtn.addEventListener('click', () => setEdit(!document.body.classList.contains('editing')));

  // Tap a cell -> toggle that day for the habit (no reload).
  document.querySelectorAll('.cell').forEach(cell => {
    cell.addEventListener('click', () => {
      if (document.body.classList.contains('editing')) return;   // don't toggle while editing
      const body = new URLSearchParams({ csrf: CSRF, action: 'toggle', id: cell.dataset.id, date: cell.dataset.date });
      fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body })
        .then(r => r.json())
        .then(d => { if (d && d.ok) cell.classList.toggle('done', d.done); })
        .catch(() => {});
    });
  });

  // ----- Rename a habit inline -----
  // Two ways in, matching the rest of the suite: a double-click / long-press any time,
  // or a single tap on the name while the Edit pencil is on. Saves over AJAX, so the
  // grid never reloads and nothing scrolls.
  const editing = () => document.body.classList.contains('editing');
  function startRename(box) {
    const span = box.querySelector('.hlabel');
    if (!span || box.querySelector('.hedit-name')) return;
    const id = box.dataset.id, cur = span.textContent;
    const inp = document.createElement('input');
    inp.type = 'text'; inp.className = 'hedit-name'; inp.value = cur; inp.maxLength = 40;
    // Size the field to its text so it's a box around the name, not a full-width input.
    const sizeIt = () => { inp.size = Math.max(3, Math.min(24, (inp.value || '').length || 3)); };
    sizeIt(); inp.addEventListener('input', sizeIt);
    span.replaceWith(inp); inp.focus();
    try { inp.setSelectionRange(cur.length, cur.length); } catch (_) {}
    let done = false;
    const commit = (save) => {
      if (done) return; done = true;
      const val = inp.value.trim();
      const ns = document.createElement('span'); ns.className = 'hlabel';
      ns.textContent = (save && val) ? val : cur;
      inp.replaceWith(ns);
      if (save && val && val !== cur) {
        const body = new URLSearchParams({ csrf: CSRF, action: 'rename_habit', id, name: val });
        fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body }).catch(() => location.reload());
      }
    };
    inp.addEventListener('keydown', e => {
      if (e.key === 'Enter') { e.preventDefault(); commit(true); }
      else if (e.key === 'Escape') { e.preventDefault(); commit(false); }
    });
    inp.addEventListener('blur', () => commit(true));
  }
  document.addEventListener('dblclick', e => {
    const box = e.target.closest('.hname'); if (!box) return;
    if (!editing()) setEdit(true);
    startRename(box);
  });
  document.addEventListener('click', e => {
    if (!editing()) return;
    if (e.target.closest('.del, button, form')) return;
    const box = e.target.closest('.hname'); if (!box) return;
    startRename(box);
  });
  // Touch: a long press opens the name the same way a double-click does.
  let lpT = null, lpX = 0, lpY = 0, lpBox = null;
  const clearLp = () => { if (lpT) { clearTimeout(lpT); lpT = null; } };
  document.addEventListener('pointerdown', e => {
    if (e.pointerType === 'mouse') return;
    const box = e.target.closest('.hname'); if (!box) return;
    if (e.target.closest('.del, button, form')) return;
    lpBox = box; lpX = e.clientX; lpY = e.clientY;
    lpT = setTimeout(() => {
      lpT = null;
      if (navigator.vibrate) navigator.vibrate(12);
      if (!editing()) setEdit(true);
      startRename(lpBox);
    }, 500);
  });
  document.addEventListener('pointermove', e => {
    if (lpT && (Math.abs(e.clientX - lpX) > 10 || Math.abs(e.clientY - lpY) > 10)) clearLp();
  });
  document.addEventListener('pointerup', clearLp);
  document.addEventListener('pointercancel', clearLp);

  // ----- "+ Habit" / "+ Section" each swap themselves for a name field, as elsewhere -----
  const wireAdd = (btnId, formId) => {
    const btn = document.getElementById(btnId), form = document.getElementById(formId);
    if (!btn || !form) { return; }
    const field = form.querySelector('input[name=name]');
    btn.addEventListener('click', () => { btn.hidden = true; form.hidden = false; field.focus(); });
    field.addEventListener('blur', () => {          // left empty: put the button back
      if (field.value.trim() === '') { form.hidden = true; btn.hidden = false; }
    });
    field.addEventListener('keydown', e => { if (e.key === 'Escape') { field.value = ''; field.blur(); } });
  };
  wireAdd('newHabitBtn', 'newHabitForm');
  wireAdd('newSecBtn', 'newSecForm');

  // ----- Drag to reorder habits and sections (edit mode) -----
  // The grid is one flat CSS grid: a habit is a name cell plus its day cells, and a
  // section spans the full width. Live-shuffling that is a mess, so nothing moves until
  // the drop — the target is outlined, and on release the new order is posted and the
  // page reloads to draw it. Same bargain the Reminders drag makes.
  (function () {
    const grid = document.getElementById('wGrid');
    if (!grid) { return; }
    let drag = null, over = null, pid = null;

    const rowsNow = () => [...grid.querySelectorAll('.hname[data-id]')];
    const secsNow = () => [...grid.querySelectorAll('.hsection[data-section]')];
    const clearOver = () => { if (over) { over.classList.remove('hdrop'); over = null; } };

    // Where a thing sits in the single top-to-bottom sequence of the grid.
    const seq = () => [...grid.querySelectorAll('.hname[data-id], .hsection[data-section]')];

    grid.addEventListener('pointerdown', (e) => {
      if (!document.body.classList.contains('editing')) { return; }
      if (!e.target.closest('.hdrag')) { return; }
      const host = e.target.closest('.hname[data-id], .hsection[data-section]');
      if (!host) { return; }
      e.preventDefault();
      drag = host; pid = e.pointerId;
      host.classList.add('hdragging');
      try { host.setPointerCapture(pid); } catch (_) {}
      if (navigator.vibrate) { navigator.vibrate(12); }
    });

    document.addEventListener('pointermove', (e) => {
      if (!drag) { return; }
      e.preventDefault();
      const under = document.elementFromPoint(e.clientX, e.clientY);
      const host  = under && under.closest('.hname[data-id], .hsection[data-section]');
      if (!host || host === drag) { clearOver(); return; }
      // A section only ever lands among sections; a habit lands anywhere.
      if (drag.classList.contains('hsection') && !host.classList.contains('hsection')) { return; }
      if (host !== over) { clearOver(); over = host; over.classList.add('hdrop'); }
    }, { passive: false });

    const drop = () => {
      if (!drag) { return; }
      const moved = drag, target = over;
      drag.classList.remove('hdragging'); clearOver(); drag = null;
      if (!target) { return; }

      const list = seq();
      const from = list.indexOf(moved), to = list.indexOf(target);
      if (from < 0 || to < 0) { return; }
      list.splice(from, 1);
      list.splice(to > from ? to : to, 0, moved);      // land where the outline was

      // Read the intended order straight back out of that sequence: a section opens a
      // new group, and every habit after it belongs to it until the next one.
      const order = [], sections = [];
      let cur = '';
      list.forEach(el => {
        if (el.classList.contains('hsection')) { cur = el.dataset.section; sections.push(cur); }
        else { order.push({ id: el.dataset.id, section: cur }); }
      });
      const body = new URLSearchParams({ csrf: CSRF, action: 'reorder',
        order: JSON.stringify(order), sections: JSON.stringify(sections) });
      fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body })
        .then(() => location.reload()).catch(() => location.reload());
    };
    document.addEventListener('pointerup', drop);
    document.addEventListener('pointercancel', () => {
      if (drag) { drag.classList.remove('hdragging'); clearOver(); drag = null; }
    });
  })();

  // ----- A section's colour swatch: post in the background, recolour in place -----
  // Same shape as the folder manager's, so the grid never reloads mid-edit.
  document.addEventListener('click', e => {
    const sw = e.target.closest('.hswatches button[name=color]');
    if (!sw) { return; }
    e.preventDefault();
    const det = sw.closest('details'), body = new URLSearchParams(new FormData(sw.form));
    body.set('color', sw.value);
    const sum = det && det.querySelector('summary');
    if (sum) { sum.style.background = sw.value; }      // optimistic, then corrected below
    if (det) { det.open = false; }
    fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body })
      .then(r => r.json())
      // Redraw from what the server actually stored, so a refused colour snaps back
      // instead of leaving the dot showing a change that never happened.
      .then(s => { if (sum && s && s.color) { sum.style.background = s.color; } })
      .catch(() => {});
  });
  // Tapping anywhere else closes an open palette, so one can't sit over the grid.
  document.addEventListener('click', e => {
    document.querySelectorAll('.hcolor[open]').forEach(d => {
      if (!d.contains(e.target)) { d.open = false; }
    });
  });

  // ----- Swipe sideways to page -----
  // Left goes forward, right goes back, exactly like the arrows in the bar above (and
  // like the Calendar). A swipe has to be clearly sideways, so scrolling the page still
  // scrolls it, and the habit cells' own taps are untouched.
  (function () {
    const box = document.getElementById('wGrid') || document.getElementById('mGrid');
    if (!box) { return; }
    const prev = document.getElementById('wPrev') || document.getElementById('mPrev');
    const next = document.getElementById('wNext') || document.getElementById('mNext');
    if (!prev || !next) { return; }
    let sx = null, sy = 0;
    box.addEventListener('touchstart', e => {
      if (e.touches.length !== 1) { sx = null; return; }
      sx = e.touches[0].clientX; sy = e.touches[0].clientY;
    }, { passive: true });
    box.addEventListener('touchend', e => {
      if (sx === null) { return; }
      const t = e.changedTouches[0], dx = t.clientX - sx, dy = t.clientY - sy;
      sx = null;
      if (Math.abs(dx) < 55 || Math.abs(dx) < Math.abs(dy)) { return; }
      location.href = (dx < 0 ? next : prev).getAttribute('href');
    }, { passive: true });
  })();
</script>
<?= chrome_script() ?>
</body>
</html>
