<?php
// A page served under /test/ (the sandbox mirror) loads lib-test/ instead of lib/, and one
// served under /dev/ (a second, fixed sandbox slot) loads lib-dev/ — each mirror
// isolated in code, config and data. Cross-app links carry the same prefix via suite_base();
// _self_path() redirects already stay put. Keep this preamble identical when adding a page.
$__test   = strpos(__DIR__, '/test/') !== false
         || strncmp($_SERVER['REQUEST_URI'] ?? '', '/test/', 6) === 0;
$__dev    = strpos(__DIR__, '/dev/') !== false
         || strncmp($_SERVER['REQUEST_URI'] ?? '', '/dev/', 5) === 0;
$__libDir = null;
$__cands  = $__dev
    ? [__DIR__ . '/../../../lib-dev', '/home/protected/lib-dev']
    : ($__test
        ? [__DIR__ . '/../../../lib-test', '/home/protected/lib-test']
        : [__DIR__ . '/../../lib',         '/home/protected/lib']);
foreach ($__cands as $__c) {
    if (is_file($__c . '/auth.php')) { $__libDir = $__c; break; }
}
require_once $__libDir . '/auth.php';
require_once $__libDir . '/tabbar.php';
require_once $__libDir . '/chrome.php';
require_once $__libDir . '/palette.php';   // the section colour dots
require_once $__libDir . '/folders.php';   // folder_nav_styles() — the picker's look
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
function habits_palette(): array { return app_palette('habits'); }   // its own tier now (a similar shade to the other apps)

function habit_section_color(array $s, int $i): string
{
    $pal = habits_palette();
    $c   = (string) ($s['color'] ?? '');
    return in_array($c, $pal, true) ? $c : $pal[$i % count($pal)];
}

/**
 * The month view's section filter.
 *
 * A month cell is a pie of "how much of that day got done", which is only a useful
 * number if it's over the habits you meant. The filter decides which sections feed it.
 * It is stored as the *hidden* set — a list of section ids in prefs-<user>.json beside
 * the theme and the chosen view — so a section added later is counted straight away
 * rather than silently missing until someone finds this menu.
 */

/** Every key the filter can hold, in the order the menu draws them. */
function msec_keys(array $habits): array
{
    $keys = [];
    foreach ($habits as $it) { if (is_section($it)) { $keys[] = (string) $it['id']; } }
    return $keys;
}

/** The hidden set, re-validated against the sections that still exist. */
function msec_hidden(string $prefsFile, array $habits): array
{
    $all = msec_keys($habits);
    $raw = store_read($prefsFile)['habits_msec'] ?? [];
    return array_values(array_intersect(is_array($raw) ? array_map('strval', $raw) : [], $all));
}

function msec_hidden_set(string $prefsFile, array $hidden): void
{
    $p = store_read($prefsFile);
    $p['habits_msec'] = array_values(array_unique($hidden));
    store_write($prefsFile, $p);
}

/**
 * The "+ Habit" that closes each run of habits, adding into that section. It's a grid
 * child spanning every column, left-aligned under the names, and edit mode only — the
 * same button-that-becomes-a-field the rest of the suite uses, one per section so a new
 * habit lands where you were looking rather than in a footer far below.
 */
// The "+" that adds a habit to a section — it rides in the section header, right of the
// name, and shows whether or not you're in edit mode. Tapping it swaps the button for an
// inline name field (wireAdd), the way "+ Section" does elsewhere.
function render_habit_add(string $section, string $csrf, string $color = ''): void
{
    $id = 'addh-' . $section;
    // The + wears the section's own colour, so it reads as "add to *this* section".
    $style = preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? ' style="color:' . e($color) . ';border-color:' . e($color . '66') . '"' : '';
    ?>
    <button type="button" class="hsec-add addhabit"<?= $style ?> data-target="<?= e($id) ?>"
            title="Add habit" aria-label="Add habit"><?= plus_icon_svg(13) ?></button>
    <form method="post" action="" class="hadd-form" id="<?= e($id) ?>" hidden
          onsubmit="return this.name.value.trim()!==''">
      <input type="hidden" name="csrf" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="add_habit">
      <input type="hidden" name="section" value="<?= e($section) ?>">
      <input type="text" name="name" placeholder="+ Habit" maxlength="40" autocomplete="off">
    </form>
    <?php
}

/**
 * Render one habit's name bubble + its day cells into the grid.
 *
 * $color is the colour of the section the habit sits in, carried into the row as a --hc
 * custom property: the name bubble takes it as a wash and a ticked square fills with it,
 * so a section reads as one band down the grid rather than as a heading you have to keep
 * glancing back up at. (Every habit is in a section now, so a colour is always passed.)
 */
function render_habit_row(array $h, array $days, string $today, string $csrf, int $extra = 0,
                          string $color = ''): void {
    // Three properties rather than one, because CSS can't append an alpha channel to a
    // custom property and color-mix() is newer than some of the phones this runs on:
    // the colour itself for a ticked square and the name, a wash for an empty square,
    // and a line for the borders. Same trick as folder_tint().
    $hc = '';
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        $hc = ' style="--hc:' . e($color) . ';--hc-soft:' . e($color . '24')
            . ';--hc-line:' . e($color . '66') . '"';
    } ?>
        <div class="hname"<?= $hc ?> data-id="<?= e($h['id']) ?>" data-section="<?= e($h['section'] ?? '') ?>">
          <span class="hdrag" title="Drag to reorder" aria-hidden="true">&#9776;</span>
          <span class="hlabel"><?= e($h['name'] ?? '') ?></span>
          <form method="post" action="" class="hdel-form">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="delete_habit">
            <input type="hidden" name="id" value="<?= e($h['id']) ?>">
            <button class="del needs-confirm" type="submit" title="Delete habit">&times;</button>
          </form>
        </div>
        <?php foreach ($days as $i => $d): $done = !empty($h['done'][$d]); ?>
          <button class="cell <?= $i < $extra ? 'wide-only' : '' ?> <?= $done ? 'done' : '' ?> <?= $d === $today ? 'today' : ($d > $today ? 'ahead' : '') ?>"<?= $hc ?>
                  data-id="<?= e($h['id']) ?>" data-section="<?= e($h['section'] ?? '') ?>" data-date="<?= $d ?>" aria-label="<?= e(($h['name'] ?? '') . ' ' . $d) ?>"></button>
        <?php endforeach;
}

/**
 * Sections are required in Habits: there is always at least one, and every habit lives in
 * one. This makes a default "Habits" section when there are none, and re-homes any habit
 * whose section no longer exists (or was ungrouped) into the first section. Callers persist
 * the result when it changed, so the default section keeps a stable id across requests.
 */
function habits_normalize(array $habits): array
{
    $sections = array_values(array_filter($habits, 'is_section'));
    if (!$sections) {
        $def = ['id' => bin2hex(random_bytes(6)), 'type' => 'section', 'name' => 'Habits', 'created' => time()];
        array_unshift($habits, $def);
        $sections = [$def];
    }
    $secIds = array_map(fn($s) => (string) $s['id'], $sections);
    $first  = $secIds[0];
    foreach ($habits as &$it) {
        if (is_section($it)) { continue; }
        if (!in_array((string) ($it['section'] ?? ''), $secIds, true)) { $it['section'] = $first; }
    }
    unset($it);
    return $habits;
}

// --- Mutations ---
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action'])) {
    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400); exit('Bad request (invalid CSRF token).');
    }
    $habits = habits_normalize(load_habits($dataFile));

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

    // The month view's section filter (AJAX, from the picker in the top bar). Three
    // gestures, the same three every picker in the suite has: the box toggles one, a row
    // tap makes it the only one counted, and "All" turns everything on — or, when it
    // already is, off. The keys are worked out here rather than taken from the post, so
    // a stale page can't name a section that no longer exists.
    if (in_array($_POST['action'], ['msec_vis', 'msec_only', 'msec_all'], true)) {
        $prefs  = theme_file();          // prefs-<user>.json, where the view lives too
        $all    = msec_keys($habits);
        $hidden = msec_hidden($prefs, $habits);
        $name   = (string) ($_POST['name'] ?? '');
        $show   = !empty($_POST['show']);
        if ($_POST['action'] === 'msec_all') {
            $hidden = $show ? [] : $all;
        } elseif (in_array($name, $all, true)) {
            if ($_POST['action'] === 'msec_only') {
                $hidden = array_values(array_diff($all, [$name]));
            } else {
                $hidden = $show ? array_values(array_diff($hidden, [$name]))
                                : array_values(array_unique(array_merge($hidden, [$name])));
            }
        }
        msec_hidden_set($prefs, $hidden);
        header('Content-Type: application/json');
        // Answer with the whole authoritative set, the way every other AJAX handler here
        // does, rather than a bare ok.
        echo json_encode(['ok' => true, 'hidden' => $hidden]);
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
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . (empty($_POST['mgr']) ? '?edit=1' : ''));
        exit;
    }

    // Hand edit mode back only if the control was used while editing (keep_edit_script
    // posts `edit`). The always-visible "+ Habit" and the section manager (mgr) must not
    // drag you into edit mode when you add from outside it.
    $stay = (!empty($_POST['edit']) && empty($_POST['mgr'])) ? '?edit=1' : '';
    if ($_POST['action'] === 'add_habit') {
        $name = trim(preg_replace('/\s+/', ' ', (string) ($_POST['name'] ?? '')));
        $section = (string) ($_POST['section'] ?? '');
        // Every habit lives in a section. Keep the posted one only if it exists, else fall
        // back to the first section ($habits is normalized, so there is always one).
        $validSection = '';
        $firstSection = '';
        foreach ($habits as $it) {
            if (!is_section($it)) { continue; }
            if ($firstSection === '') { $firstSection = (string) $it['id']; }
            if (($it['id'] ?? '') === $section) { $validSection = $section; }
        }
        if ($validSection === '') { $validSection = $firstSection; }
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
        // At least one section always stays — the last is undeletable, the way the folder
        // manager pins its last folder. Its habits move to the first remaining section
        // (sections are required, so they're never left ungrouped) rather than thrown away.
        $id = (string) ($_POST['id'] ?? '');
        $secCount = count(array_filter($habits, 'is_section'));
        if ($secCount > 1) {
            $habits = array_values(array_filter($habits, fn($it) => !(is_section($it) && ($it['id'] ?? '') === $id)));
            $firstRemaining = '';
            foreach ($habits as $it) { if (is_section($it)) { $firstRemaining = (string) $it['id']; break; } }
            foreach ($habits as &$it) { if (!is_section($it) && ($it['section'] ?? '') === $id) { $it['section'] = $firstRemaining; } }
            unset($it);
            save_habits($dataFile, $habits);
        }
    } elseif ($_POST['action'] === 'delete_habit') {
        $id = (string) ($_POST['id'] ?? '');
        $habits = array_values(array_filter($habits, fn($h) => is_section($h) || ($h['id'] ?? '') !== $id));
        save_habits($dataFile, $habits);
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . $stay);
    exit;
}

// --- Render ---
// Normalize on read and persist if it changed, so a fresh account gets its default section
// (with a stable id) the first time it opens Habits, and no habit is left sectionless.
$habits = load_habits($dataFile);
$normalized = habits_normalize($habits);
if ($normalized !== $habits) { save_habits($dataFile, $normalized); $habits = $normalized; }
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

// Split sections from habits; group habits under their section (there's always ≥1 section
// and every habit belongs to one, so there is no ungrouped bucket).
$sections   = array_values(array_filter($habits, 'is_section'));
$habitItems = array_values(array_filter($habits, fn($h) => !is_section($h)));
$sectionIds = array_map(fn($s) => $s['id'], $sections);
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
// Only the sections the filter leaves on feed the pies. Everything else on the page —
// the week grid, the lists, the drag order — is untouched by it: this is a question
// about the month's arithmetic, not about which habits exist.
$mHidden    = msec_hidden($prefsFile, $habits);
$mKeys      = msec_keys($habits);
$mShown     = array_values(array_diff($mKeys, $mHidden));
$mFiltered  = $mHidden !== [];
$monthItems = array_values(array_filter($habitItems, function ($h) use ($sectionIds, $mHidden) {
    $sec = (string) ($h['section'] ?? '');
    $key = $sec;                                 // every habit is in a section now
    return !in_array($key, $mHidden, true);
}));
$habitTotal = count($monthItems);
// Per day: the total counted habits ticked, plus the breakdown by section, so each day's
// pie can be filled in the sections' own colours instead of one flat accent.
$monthDone = [];   // 'YYYY-MM-DD' => total ticked
$monthSec  = [];   // 'YYYY-MM-DD' => [sectionKey => ticked]
foreach ($monthItems as $h) {
    $sec = (string) ($h['section'] ?? '');
    $key = $sec;                                 // every habit is in a section now
    foreach ((array) ($h['done'] ?? []) as $d => $on) {
        if ($on && strncmp((string) $d, $mym, 7) === 0) {
            $monthDone[(string) $d]      = ($monthDone[(string) $d] ?? 0) + 1;
            $monthSec[(string) $d][$key] = ($monthSec[(string) $d][$key] ?? 0) + 1;
        }
    }
}
// Section key -> its colour, so a day's slices can be drawn in them. The section index
// matches the picker's, so a section shows the same colour in the pie, the menu and its
// own header.
$secColors = [];
$secNames  = [];   // same key -> the section's name, for the month legend
$sci = 0;
foreach ($habits as $it) {
    if (is_section($it)) {
        $secColors[(string) $it['id']] = habit_section_color($it, $sci);
        $secNames[(string) $it['id']]  = (string) ($it['name'] ?? 'Section');
        $sci++;
    }
}

/**
 * The filter's picker: a round button in the top bar dropping a menu of tick boxes, the
 * same shape and the same three gestures as the folder and calendar pickers (box toggles
 * one, row tap makes it the only one, "All" turns the lot on or off). Its dot is a pie of
 * the counted sections' colours, so the button says what the month is measuring without
 * opening it. Month view only — in the week grid there is nothing for it to filter.
 */
function render_msec_pick(array $habits, array $hidden, string $csrf): void
{
    $opts = [];
    $i = 0;
    foreach ($habits as $it) {
        if (!is_section($it)) { continue; }
        $opts[] = [(string) $it['id'], (string) ($it['name'] ?? 'Section'), habit_section_color($it, $i)];
        $i++;
    }
    $on = array_values(array_filter($opts, fn($o) => !in_array($o[0], $hidden, true)));
    $pie = '';
    if (count($on) > 1) {
        $n = count($on); $stops = [];
        foreach ($on as $k => $o) {
            $stops[] = e($o[2]) . ' ' . round($k * 100 / $n, 2) . '% ' . round(($k + 1) * 100 / $n, 2) . '%';
        }
        $pie = 'conic-gradient(' . implode(',', $stops) . ')';
    } elseif (count($on) === 1) {
        $pie = e($on[0][2]);
    }
    $label = $hidden === [] ? 'Counting every section'
           : (count($on) . ' of ' . count($opts) . ' sections counted');
    ?>
    <div class="folderpick">
      <button type="button" class="folderpick-btn" id="msecBtn" aria-haspopup="listbox"
              aria-expanded="false" title="<?= e($label) ?>" aria-label="<?= e($label) ?>">
        <span class="fdot<?= $pie === '' ? '' : '' ?>"<?= $pie === '' ? '' : ' style="background:' . $pie . '"' ?>></span>
      </button>
      <div class="folderpick-menu" id="msecMenu" role="listbox" hidden>
        <button type="button" class="folderpick-opt msec-opt" data-key="">
          <span class="fvis fvis-all<?= $hidden === [] ? ' on' : '' ?>" role="checkbox"
                aria-checked="<?= $hidden === [] ? 'true' : 'false' ?>"
                title="Count every section" aria-label="Count every section"></span>
          <span class="fdot all"></span><span class="fpick-name">All</span>
        </button>
        <?php foreach ($opts as [$key, $name, $col]): $isOn = !in_array($key, $hidden, true); ?>
          <button type="button" class="folderpick-opt msec-opt" data-key="<?= e($key) ?>">
            <span class="fvis<?= $isOn ? ' on' : '' ?>" role="checkbox" data-key="<?= e($key) ?>"
                  aria-checked="<?= $isOn ? 'true' : 'false' ?>"
                  title="Count in the pies" aria-label="Count <?= e($name) ?> in the pies"></span>
            <span class="fdot" style="background:<?= e($col) ?>"></span>
            <span class="fpick-name"><?= e($name) ?></span>
          </button>
        <?php endforeach; ?>
        <?php // The last row opens the section manager, the way the folder pickers' last
              // row opens theirs; it isn't a .msec-opt, so the filter handler ignores it. ?>
        <button type="button" class="folderpick-opt folderpick-manage" id="habitSecMgr">
          <span class="fpick-gear" aria-hidden="true"><?= folder_icon_svg() ?></span><span>Manage sections</span>
        </button>
      </div>
    </div>
    <script>(function(){
      var b = document.getElementById('msecBtn'), m = document.getElementById('msecMenu');
      if (!b || !m) { return; }
      var CSRF = <?= json_encode($csrf) ?>;
      b.addEventListener('click', function (e) {
        e.stopPropagation();
        m.hidden = !m.hidden;
        b.setAttribute('aria-expanded', m.hidden ? 'false' : 'true');
      });
      document.addEventListener('click', function (e) {
        if (!m.hidden && !m.contains(e.target)) { m.hidden = true; b.setAttribute('aria-expanded', 'false'); }
      });
      // Ticking reloads (the pies have to be redrawn server-side), so remember that the
      // menu was open and put it back — otherwise it folds away between every tap.
      try { if (sessionStorage.getItem('msecOpen') === '1') { sessionStorage.removeItem('msecOpen');
        m.hidden = false; b.setAttribute('aria-expanded', 'true'); } } catch (_) {}
      var post = function (params) {
        try { sessionStorage.setItem('msecOpen', '1'); } catch (_) {}
        fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: new URLSearchParams(params) })
          .then(function () { location.reload(); })
          .catch(function () { location.reload(); });
      };
      m.addEventListener('click', function (e) {
        var row = e.target.closest('.msec-opt');
        if (!row) { return; }
        // stopPropagation as well: in a home-screen PWA tabbar.php listens for clicks on
        // document and would leave the page before the POST had gone anywhere.
        e.preventDefault(); e.stopPropagation();
        var box = row.querySelector('.fvis'), all = box.classList.contains('fvis-all');
        var onNow = box.classList.contains('on');
        // The box toggles just its own; the row makes it the only one counted. "All" does
        // the same thing either way — everything on, or everything off if it already was.
        var viaBox = !!e.target.closest('.fvis');
        if (all) { post({ csrf: CSRF, action: 'msec_all', show: onNow ? '' : '1' }); }
        else if (viaBox) { post({ csrf: CSRF, action: 'msec_vis', name: box.dataset.key, show: onNow ? '' : '1' }); }
        else { post({ csrf: CSRF, action: 'msec_only', name: box.dataset.key }); }
      });
    })();</script>
    <?php
}

/**
 * The section manager — the window the filter dropdown's "Manage sections" row opens.
 * It borrows the folder manager's look (.foldermodal and friends, already on the page via
 * folder_nav_styles()) and its shape: an add row with a green +, a draggable list of rows
 * each carrying a colour swatch, the name and a delete ×, then Done. Add and delete are
 * plain POST→redirect (they carry mgr=1 so they don't flip on edit mode and reopen the
 * window rather than closing it); recolour and reorder post in the background so the
 * window stays put. At least one section always stays, so the last row shows no ×.
 */
function render_habit_section_modal(array $sections, string $csrf): void
{
    $csrf = htmlspecialchars($csrf, ENT_QUOTES);
    $only = count($sections) < 2;   // the last section is undeletable
    ?>
    <div class="modal-backdrop" id="hsecModal">
      <div class="foldermodal">
        <h2>Sections</h2>
        <form class="addrow" method="post" action="" onsubmit="return this.name.value.trim()!==''">
          <input type="hidden" name="csrf" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="add_section">
          <input type="hidden" name="mgr" value="1">
          <input type="text" name="name" placeholder="New section" maxlength="40" autocomplete="off">
          <button type="submit" class="plus" title="Add section" aria-label="Add section"><?= plus_icon_svg(18, 3) ?></button>
        </form>
        <ul class="flist" id="hsecReorder">
          <?php foreach ($sections as $si => $s): $scol = habit_section_color($s, $si); $sid = (string) ($s['id'] ?? ''); ?>
            <li data-section="<?= htmlspecialchars($sid, ENT_QUOTES) ?>">
              <span class="fhandle" title="Drag to reorder" aria-hidden="true">&#9776;</span>
              <details class="fcolor">
                <summary style="background:<?= htmlspecialchars($scol, ENT_QUOTES) ?>" title="Colour"></summary>
                <form class="fswatches" method="post" action="">
                  <input type="hidden" name="csrf" value="<?= $csrf ?>">
                  <input type="hidden" name="action" value="set_section_color">
                  <input type="hidden" name="id" value="<?= htmlspecialchars($sid, ENT_QUOTES) ?>">
                  <?php foreach (habits_palette() as $col): ?>
                    <button type="submit" name="color" value="<?= htmlspecialchars($col, ENT_QUOTES) ?>"
                            style="background:<?= htmlspecialchars($col, ENT_QUOTES) ?>" title="<?= htmlspecialchars($col, ENT_QUOTES) ?>"></button>
                  <?php endforeach; ?>
                </form>
              </details>
              <?php // The name reads as plain text; the pencil in the actions turns it into a
                    // field that posts rename_section by id (mgr=1). The last section shows the
                    // pencil only — it can be renamed but not deleted. ?>
              <span class="fname-cell">
                <span class="fname frename-label"><?= htmlspecialchars((string) ($s['name'] ?? 'Section'), ENT_QUOTES) ?></span>
                <form method="post" action="" class="frename-form" hidden>
                  <input type="hidden" name="csrf" value="<?= $csrf ?>">
                  <input type="hidden" name="action" value="rename_section">
                  <input type="hidden" name="mgr" value="1">
                  <input type="hidden" name="id" value="<?= htmlspecialchars($sid, ENT_QUOTES) ?>">
                  <input type="hidden" name="name" value="<?= htmlspecialchars((string) ($s['name'] ?? ''), ENT_QUOTES) ?>">
                  <input class="fname frename" name="newname" value="<?= htmlspecialchars((string) ($s['name'] ?? 'Section'), ENT_QUOTES) ?>"
                         maxlength="40" autocomplete="off" aria-label="Section name">
                </form>
              </span>
              <span class="frow-actions">
                <button type="button" class="frename-edit" title="Rename" aria-label="Rename"><?= pencil_icon_svg() ?></button>
                <?php if (!$only): ?>
                  <form method="post" action="" style="display:inline">
                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="delete_section">
                    <input type="hidden" name="mgr" value="1">
                    <input type="hidden" name="id" value="<?= htmlspecialchars($sid, ENT_QUOTES) ?>">
                    <button type="submit" class="fdel needs-confirm" title="Delete section">&times;</button>
                  </form>
                <?php endif; ?>
              </span>
            </li>
          <?php endforeach; ?>
        </ul>
        <p class="fhint">Drag a row to reorder. At least one section stays — the last can't be removed.</p>
        <div class="frow"><button type="button" class="fdone" id="hsecDone">Done</button></div>
      </div>
    </div>
    <script>(function(){
      var b = document.getElementById('habitSecMgr'), m = document.getElementById('hsecModal'),
          d = document.getElementById('hsecDone'), menu = document.getElementById('msecMenu');
      if (!b || !m) { return; }
      var CSRF = <?= json_encode($csrf) ?>, dirty = false;
      var open  = function () { m.classList.add('open'); var i = m.querySelector('.addrow input[type=text]'); if (i) i.focus(); };
      var close = function () { if (dirty) { location.reload(); return; } m.classList.remove('open'); };
      // Opening from the filter dropdown: fold that menu away behind the window.
      b.addEventListener('click', function (e) {
        e.preventDefault(); e.stopPropagation();
        if (menu) { menu.hidden = true; var mb = document.getElementById('msecBtn'); if (mb) mb.setAttribute('aria-expanded', 'false'); }
        open();
      });
      // Add/delete reload the page; remember the window was open so it comes back rather
      // than folding away — the same trick msec_* uses for the filter menu.
      m.addEventListener('submit', function () { try { sessionStorage.setItem('hsecOpen', '1'); } catch (_) {} });
      // The rename field commits with a programmatic form.submit() (no submit event fires),
      // so flag the reopen the moment it's typed into — the reload that follows finds it.
      m.addEventListener('input', function (e) {
        if (e.target.classList && e.target.classList.contains('frename')) {
          try { sessionStorage.setItem('hsecOpen', '1'); } catch (_) {}
        }
      });
      try { if (sessionStorage.getItem('hsecOpen') === '1') { sessionStorage.removeItem('hsecOpen'); open(); } } catch (_) {}
      if (d) d.addEventListener('click', close);
      m.addEventListener('click', function (e) { if (e.target === m) close(); });
      document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && m.classList.contains('open')) close(); });

      // A swatch recolours in the background, so the window stays open.
      m.addEventListener('click', function (e) {
        var sw = e.target.closest('.fswatches button[name=color]'); if (!sw) return;
        e.preventDefault();
        var col = sw.value, det = sw.closest('details'), f = sw.form;
        var body = new URLSearchParams(new FormData(f)); body.set('color', col);
        fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: body }).catch(function () {});
        var sum = det && det.querySelector('summary'); if (sum) sum.style.background = col;
        if (det) det.open = false;
        // Repaint the grid live — the section's name wash and every row/cell that carries its
        // colour — so a recolour applies without a refresh.
        try {
          var sid = f.querySelector('input[name=id]').value;
          var tint = /^#[0-9a-fA-F]{6}$/.test(col) ? col + '33' : 'transparent';
          document.querySelectorAll('.hsection[data-section="' + sid + '"] .hsec-wash').forEach(function (el) { el.style.background = tint; });
          document.querySelectorAll('.hname[data-section="' + sid + '"], .cell[data-section="' + sid + '"]').forEach(function (el) {
            el.style.setProperty('--hc', col); el.style.setProperty('--hc-soft', col + '24'); el.style.setProperty('--hc-line', col + '66');
          });
          document.querySelectorAll('.hsec-add[data-target="addh-' + sid + '"]').forEach(function (b) { b.style.color = col; b.style.borderColor = col + '66'; });
        } catch (_) {}
        dirty = true;
      });

      // Drag a row by its handle to reorder the sections; on drop, POST the new order
      // through the ordinary reorder action (empty habit order = leave the habits be).
      var list = document.getElementById('hsecReorder'); if (!list) return;
      var drag = null;
      var clr  = function () { list.querySelectorAll('.fdrop-before,.fdrop-after').forEach(function (li) { li.classList.remove('fdrop-before', 'fdrop-after'); }); };
      var rows = function () { return [].slice.call(list.querySelectorAll('li')); };
      list.addEventListener('pointerdown', function (e) {
        var h = e.target.closest('.fhandle'); if (!h) return;
        drag = h.closest('li'); if (!drag) return;
        e.preventDefault(); drag.classList.add('dragging'); h.setPointerCapture(e.pointerId);
      });
      list.addEventListener('pointermove', function (e) {
        if (!drag) return; e.preventDefault(); clr();
        var y = e.clientY, tgt = null, after = false;
        rows().forEach(function (li) { if (li === drag) return; var r = li.getBoundingClientRect(); if (y >= r.top) { tgt = li; after = y > (r.top + r.height / 2); } });
        if (tgt) tgt.classList.add(after ? 'fdrop-after' : 'fdrop-before');
      });
      var drop = function () {
        if (!drag) return;
        var mk = list.querySelector('.fdrop-before,.fdrop-after'), moved = false;
        if (mk) { if (mk.classList.contains('fdrop-after')) mk.after(drag); else mk.before(drag); moved = true; }
        clr(); drag.classList.remove('dragging'); drag = null; if (!moved) return;
        var order = rows().map(function (li) { return li.dataset.section; });
        var body = new URLSearchParams(); body.set('csrf', CSRF); body.set('action', 'reorder');
        body.set('order', '[]'); body.set('sections', JSON.stringify(order));
        fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: body }).catch(function () {});
        dirty = true;
      };
      list.addEventListener('pointerup', drop);
      list.addEventListener('pointercancel', function () { clr(); if (drag) { drag.classList.remove('dragging'); drag = null; } });
    })();</script>
    <?php
}
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>Habits</title>
  <meta name="theme-color" content="<?= e(theme_bg()) ?>">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black">
  <meta name="apple-mobile-web-app-title" content="Habits">
  <link rel="apple-touch-icon" href="<?= suite_base() ?>/reminders/icon-180.png">
  <link rel="manifest" href="<?= suite_base() ?>/reminders/manifest.webmanifest?v=2">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: system-ui, sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; padding: 1.5rem 1rem; overscroll-behavior-y: none; }
    .wrap { max-width: 640px; margin: 0 auto; }   /* same column as Reminders + Calendar */
    header { display: flex; align-items: center; justify-content: space-between; }
    header h1 { font-size: 1.35rem; }   /* same as the Calendar's */
    header .titlebar { display: flex; align-items: center; gap: 0.85rem; }
    header nav { display: flex; align-items: center; gap: 0.5rem; }
    header nav a { color: var(--muted); text-decoration: none; font-size: 0.85rem; }
    header nav a:hover { color: var(--text); }
    header nav .who { color: var(--accent); font-size: 0.8rem; border: 1px solid #2a4a3d; border-radius: 999px; padding: 0.15rem 0.6rem; }

    .bar { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1.25rem; flex-wrap: wrap; padding-left: 0; }
    body:not(.editing) .bar { justify-content: flex-end; }   /* Edit keeps the right edge */
    .bar form.addh { flex: 1 1 220px; }
    body:not(.editing) .bar form.addh { display: none; }   /* edit mode only */
    .bar input[type=text] {
      width: 100%; padding: 0.6rem 0.75rem; background: var(--surface); border: 1px dashed #4a3f6a;
      border-radius: 8px; color: #b9a7f5; font-size: 1rem;
    }
    .bar input::placeholder { color: #b9a7f5; opacity: 0.75; }
    .bar input:focus { outline: none; border-style: solid; border-color: #8b6ef0; }
    .bar .hsel { padding: 0.55rem 0.6rem; background: var(--surface); border: 1px solid var(--line); color: var(--text-dim); border-radius: 999px; font-size: 16px; }

    /* + Section — left-aligned amber pill above the day grid. */
    /* The + that adds a habit to a section: a small round pill right of the section name,
       shown whether or not you're in edit mode, and the field it swaps to. */
    .hsection .hsec-add {
      flex: 0 0 auto; align-self: center; background: none; border: 1px solid #3a3350;
      color: #b9a7f5; border-radius: 999px; width: 22px; height: 22px; margin-left: 0.1rem;
      font-size: 0.95rem; line-height: 1; cursor: pointer; font-family: inherit;
      display: inline-flex; align-items: center; justify-content: center; padding: 0;
    }
    .hsection .hsec-add:hover { border-color: #b9a7f5; color: var(--text); }
    .hsection .hsec-del { margin-left: auto; }   /* push the delete × to the far right */
    .hadd-form { margin: 0; display: inline-flex; align-items: center; gap: 0.4rem; }
    .hadd-form[hidden] { display: none; }
    .hadd-form input {
      width: 150px; max-width: 100%; padding: 0.25rem 0.7rem; background: var(--surface);
      border: 1px dashed #4a3f6a; border-radius: 999px; color: #b9a7f5; font-size: 16px;
    }
    .hadd-form input::placeholder { color: #b9a7f5; opacity: 0.8; }
    .hadd-form input:focus { outline: none; border-style: solid; border-color: #8b6ef0; }

    /* Grid: name column + day columns. The name column takes at least half the width
       and absorbs the rest; the day squares are capped small so the habit name has
       room to read rather than being squeezed by the grid. */
    .grid { display: grid; grid-template-columns: minmax(120px, 1fr) repeat(8, minmax(0, 46px)); gap: 9px; align-items: center; width: 100%; }
    /* Five days is all a phone has room for; the three oldest columns are in the
       DOM either way, so this is one grid with a different column count. */
    @media (max-width: 640px) {
      .grid { grid-template-columns: minmax(96px, 1fr) repeat(5, minmax(0, 40px)); }
      .wide-only { display: none; }
    }
    .colhead {
      text-align: center; font-family: ui-monospace, Menlo, monospace; font-size: 0.8rem;
      color: var(--muted); padding-bottom: 0.4rem; border-radius: 8px 8px 0 0;
    }
    /* Today's column has to be findable at a glance on a phone, where five columns of
       small squares look much alike. The grid gap means a background tint can't
       actually join the column up — it draws as one faint patch behind the head and
       nothing under it — so today is marked twice instead: a filled pill on the head,
       and an accent ring on every cell below it (see .cell.today). */
    .colhead.today {
      color: var(--accent-ink); font-weight: 700; background: var(--accent);
      border-radius: 8px; padding: 0.3rem 0 0.35rem;
    }
    .colhead.ahead { color: var(--muted); }        /* tomorrow, ticked off early */
    .colhead .num { display: block; font-size: 0.95rem; margin-top: 0.1rem; }
    /* The corner cell holds the collapse-all button; the grid's align-items:center lines it
       up vertically with the day-of-week labels beside it, and it sits at the left edge. */
    .corner { display: flex; align-items: center; justify-content: flex-start; }

    /* Section header row spans the full grid width. */
    .hsection {
      grid-column: 1 / -1; display: flex; align-items: center; gap: 0.5rem;
      margin: 0.9rem 0 0.1rem; padding: 0 0.1rem 0.35rem 0.1rem;   /* flush with the grid */
      color: #b9a7f5; font-weight: 700; font-size: 0.95rem; border-bottom: 1px solid #2c2540;
    }
    .hsection .hslabel { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    /* The section's colour as a rounded wash behind its name (like a folder heading), rather
       than a dot. The name goes near-white on the wash, since the colour identity is the
       chip now — the same reasoning folder_tint() uses for folder names. */
    .hsec-wash { display: inline-flex; align-items: center; min-width: 0; border-radius: 999px; padding: 0.05rem 0.6rem; }
    .hsection .sectitle { color: var(--text); font-weight: 700; }
    /* Collapse-all bar above the top section, left-aligned under the back button. */
    /* The section's collapse chevron picks up the section's violet; it points down when
       open, right when the section is folded. Its rows leave the grid entirely when folded. */
    .hsection .sec-collapse { color: #6a5f8c; }
    .hsection .sec-collapse:hover { color: #b9a7f5; }
    .hsection.collapsed .sec-collapse { transform: rotate(0deg); }
    .hname.hrow-folded, .cell.hrow-folded { display: none; }
    /* Holding a habit or a section to enter edit mode must not paint the text blue as if it
       were being selected — so the names don't take a selection. The rename fields (real
       inputs) opt back in, so you can still select while typing. */
    .hname, .hsection { -webkit-user-select: none; user-select: none; -webkit-touch-callout: none; }
    .hname input, .hsection input { -webkit-user-select: text; user-select: text; }
    /* The drag handle on a habit and on a section. Hidden with visibility rather than
       display, as everywhere else, so turning edit mode on doesn't shove the names
       sideways. Nothing else on the grid moves. */
    /* Out of edit mode the handle and the delete form leave the flow entirely (not just
       hidden), so the name box hugs the label and the text sits dead-centre in its border
       rather than pushed off by an invisible handle. In edit mode both return. */
    .hdrag {
      flex: 0 0 auto; width: 16px; color: #6a5f8c; cursor: grab; display: none;
      align-items: center; justify-content: center; font-size: 0.9rem;
      line-height: 1; touch-action: none; user-select: none; -webkit-user-select: none;
    }
    body.editing .hdrag { display: inline-flex; }
    .hname .hdel-form { display: none; }
    body.editing .hname .hdel-form { display: inline-flex; align-items: center; }
    .hdrag:active { cursor: grabbing; color: var(--accent); }
    /* What is being dragged dims; where it will land is a single accent line, because
       shuffling a CSS grid live made it impossible to see what you were about to get. */
    .hname.hdragging, .hsection.hdragging { opacity: 0.4; }
    /* The same zero-height accent line the other apps drop against, made a grid child of
       its own so it spans every column and sits *between* two rows instead of taking a
       cell. Zero height keeps the grid from jumping as it moves. */
    .grid .drop-line {
      grid-column: 1 / -1; height: 0; margin: 0; padding: 0; border: none;
      border-top: 2px solid var(--accent); box-shadow: 0 0 6px var(--accent-soft);
      pointer-events: none;
    }
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
      background: var(--surface); border: 1px solid var(--line); border-radius: 10px; padding: 0.5rem;
      display: grid; grid-template-columns: repeat(6, 22px); gap: 0.4rem;   /* all six on one row */
      box-shadow: 0 8px 20px rgba(0,0,0,0.6);
    }
    .hsection .hswatches button {
      width: 22px; height: 22px; border-radius: 50%; border: 1px solid var(--line);
      cursor: pointer; padding: 0;
    }
    .hsection .del { display: none; margin-left: auto; background: none; border: 1px solid var(--line); color: var(--text-dim); border-radius: 6px; padding: 0.1rem 0.45rem; font-size: 0.9rem; line-height: 1; cursor: pointer; }
    body.editing .hsection .del { display: inline-block; }
    .hsection .del:hover { border-color: #f66; color: #f66; }

    /* The name bubble hugs the text and centres itself in the column rather than filling
       it, so it reads as a label on a pill, not a big empty input. The name centres, wraps
       to at most two lines and hyphenates a word too long to fit. */
    /* --hc is the section's colour, set inline per row. Everything below falls back to
       the app's violet when a habit is in no section, so an ungrouped list looks exactly
       as it did. color-mix() is avoided here for the same reason as the folder tint. */
    .hname {
      position: relative; background: #1b1726; border: 1px solid #2c2540; border-radius: 8px;
      padding: 0.3rem 0.6rem; min-height: 28px; display: flex; align-items: center;
      gap: 0.35rem; justify-content: center; justify-self: center; width: fit-content; max-width: 100%;
    }
    .hname[style*="--hc"] { border-color: var(--hc-line); background: var(--hc-soft); }
    .hname[style*="--hc"] .hlabel { color: var(--hc); }
    .hname .hlabel {
      color: #d9d2f0; font-size: 1.02rem; text-align: center; line-height: 1.15;
      overflow-wrap: anywhere; hyphens: auto; -webkit-hyphens: auto;
      display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
    }
    .hname .del { display: none; flex: 0 0 auto; background: none; border: 1px solid var(--line); color: var(--text-dim); border-radius: 6px; padding: 0.15rem 0.45rem; font-size: 0.9rem; line-height: 1; cursor: pointer; }
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
    /* A section's colour themes the whole row, not just the ticks: an empty square takes
       the wash and the line, a ticked one fills solid. The section reads as a band down
       the grid whether or not anything in it has been done yet. */
    .cell[style*="--hc"] { background: var(--hc-soft); border-color: var(--hc-line); }
    .cell.done[style*="--hc"] { background: var(--hc); border-color: var(--hc); }
    /* Today keeps the accent ring on top of all of it — the one thing you look for on
       this grid must never be repainted by whichever section it happens to sit in. */
    .cell.today[style*="--hc"] { border: 2px solid var(--accent); }
    .cell.done.today[style*="--hc"] { border-color: #eafff6; }
    /* A ticked cell is already accent-filled, so today's ring has to be the light one. */
    .cell.done.today { border-color: #eafff6; }
    .cell:active { transform: scale(0.94); }

    .empty { color: var(--muted); text-align: center; padding: 2rem 0; }

    /* The view bar: Week / Month on the left, the range and its arrows on the right.
       One row, the same 32px as everything else on the top bar. */
    .viewbar { display: flex; align-items: center; gap: 0.5rem; margin: 0 0 1rem; }
    .segpick { display: inline-flex; background: var(--surface-2); border: 1px solid var(--line-soft); border-radius: 999px; padding: 3px; }
    .segpick a {
      padding: 0.3rem 0.85rem; border-radius: 999px; text-decoration: none; color: var(--muted);
      font-size: 0.82rem; font-weight: 600;
    }
    .segpick a.on { background: var(--surface-2); color: var(--accent); }
    .viewbar .range { margin-left: auto; display: inline-flex; align-items: center; gap: 0.4rem; }
    .viewbar .range .lbl { font-size: 0.85rem; color: var(--text-dim); min-width: 7.5rem; text-align: center; }
    .viewbar .range a {
      width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center;
      border: 1px solid var(--line); border-radius: 999px; color: var(--text-dim); text-decoration: none; font-size: 1rem;
    }
    .viewbar .range a:hover { border-color: var(--muted); color: #fff; }

    /* Month view: a pie per day. The filled slice is how much of that day got ticked. */
    .mgrid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 6px; touch-action: pan-y; }
    .mgrid .dow { text-align: center; font-size: 0.7rem; color: var(--muted); padding-bottom: 0.2rem; }
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
    .mgrid .mcell .dnum { font-size: 0.65rem; color: var(--muted); line-height: 1; }
    /* Today's number is a filled chip, the one thing on the month that isn't a circle
       or a bare numeral, so the eye lands on it without hunting for the ring. */
    .mgrid .mcell.today .dnum {
      color: var(--accent-ink); font-weight: 700; background: var(--accent);
      border-radius: 999px; padding: 0.1rem 0.4rem;
    }
    /* The section colour key under the month grid: a dot and name per counted section,
       wrapping and centred, in the same order the pie slices are drawn. */
    .mleg {
      list-style: none; margin: 0.9rem 0 0; padding: 0; display: flex; flex-wrap: wrap;
      justify-content: center; gap: 0.4rem 0.9rem;
    }
    .mleg li { display: inline-flex; align-items: center; gap: 0.4rem; font-size: 0.8rem; color: var(--text-dim); }
    .mleg-dot { flex: 0 0 auto; width: 10px; height: 10px; border-radius: 50%; }
    .mlegend { margin-top: 0.7rem; font-size: 0.78rem; color: var(--muted); text-align: center; }

<?= folder_nav_styles() ?>
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
      // No Edit pencil: holding a habit's name or a section's gets you into edit mode,
      // the same gesture as everywhere else in the suite.
      //
      // The section filter (which sections feed the Month pies) rides in the same top-bar
      // slot every other app's picker does — the round button by the ⋮ — captured with
      // ob_start() and handed to render_user_menu(), the way render_folder_pick() is.
      ob_start();
      render_msec_pick($habits, $mHidden, $csrf);
      $titleControls = ob_get_clean();
    ?>
    <?= render_user_menu(false, '', '', false, $titleControls) ?>
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
              // The pie is the day's ticks sliced out of the whole counted set: each
              // section's slice in its own colour, the unfinished remainder left the empty
              // colour. So a full circle is a day where everything got done, and its
              // colours say which sections; a day with nothing done is a bare ring.
              if ($habitTotal <= 0 || $done <= 0) {
                  $bg = '#1b1726';
              } else {
                  $stops = []; $acc = 0;
                  foreach ($mShown as $key) {           // menu order, counted sections only
                      $c = $monthSec[$ymd][$key] ?? 0;
                      if ($c <= 0) { continue; }
                      $from = round($acc / $habitTotal * 100, 3); $acc += $c;
                      $to   = round($acc / $habitTotal * 100, 3);
                      $stops[] = e($secColors[$key] ?? '#8b7fd4') . " $from% $to%";
                  }
                  if ($acc < $habitTotal) {              // the part of the day not yet done
                      $stops[] = '#1b1726 ' . round($acc / $habitTotal * 100, 3) . '% 100%';
                  }
                  $bg = 'conic-gradient(' . implode(',', $stops) . ')';
              }
              $cls  = $ymd === $today ? ' today' : ($ymd > $today ? ' ahead' : '');
      ?>
        <div class="mcell<?= $cls ?>" title="<?= $done ?> of <?= $habitTotal ?> on <?= $ymd ?>">
          <span class="pie" style="background:<?= $bg ?>"></span>
          <span class="dnum"><?= $d ?></span>
        </div>
      <?php endfor; ?>
    </div>
    <?php // A key to the pie colours: each counted section with its own dot, in the same
          // order the slices are drawn, so you can read a day's pie back to its sections. ?>
    <?php if ($mShown): ?>
      <ul class="mleg" aria-label="Section colours">
        <?php foreach ($mShown as $key): ?>
          <li><span class="mleg-dot" style="background:<?= e($secColors[$key] ?? '#8b7fd4') ?>"></span><?= e($secNames[$key] ?? 'Section') ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <p class="mlegend">Each day is filled in proportion to how many of
      <?= $mFiltered ? 'the' : 'your' ?> <?= $habitTotal ?> habit<?= $habitTotal === 1 ? '' : 's' ?>
      <?= $mFiltered ? 'in the ' . count($mShown) . ' section' . (count($mShown) === 1 ? '' : 's')
                       . " you're counting" : '' ?> were ticked.</p>
  <?php else: ?>
    <div class="grid" id="wGrid">
      <?php // Collapse-all sits in the grid's corner cell, so it lines up on the day-label
            // row (and at the left edge, under the back button); it folds every section's
            // habits away, or expands them all on a second press. ?>
      <div class="corner"><?= collapse_all_button('', 'hCollapseAll') ?></div>
      <?php foreach ($days as $i => $d): $ts = strtotime($d); ?>
        <div class="colhead <?= $i < $extraDays ? 'wide-only' : '' ?> <?= $d === $today ? 'today' : ($d > $today ? 'ahead' : '') ?>">
          <?= substr(date('D', $ts), 0, 2) ?><span class="num"><?= (int) date('j', $ts) ?></span>
        </div>
      <?php endforeach; ?>

      <?php // Every habit lives in a section now; each section header carries the "+" that
            // adds one, so there is no ungrouped run. ?>
      <?php foreach ($sections as $si => $s): $scol = habit_section_color($s, $si); ?>
        <div class="hsection" data-section="<?= e($s['id']) ?>">
          <?php // Collapse chevron (out of edit mode; the drag handle takes its slot while
                // editing) — folds this section's habits away, remembered per page. ?>
          <?= section_collapse_button() ?>
          <span class="hdrag" title="Drag to reorder" aria-hidden="true">&#9776;</span>
          <?php // The section's colour is the wash behind its name now, like a folder's — not
                // a dot beside it. The colour itself is changed in the "Manage sections" window. ?>
          <span class="hsec-wash" style="background:<?= e(folder_tint($scol)) ?>">
            <?= section_title_html($s['name'], $csrf, '', false, 'rename_section',
                  '<input type="hidden" name="id" value="' . e($s['id']) . '">') ?>
          </span>
          <?php // The "+" to add a habit to this section, right of the name, always shown. ?>
          <?php render_habit_add((string) $s['id'], $csrf, $scol); ?>
          <form method="post" action="" class="hsec-del" style="display:inline">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="delete_section">
            <input type="hidden" name="id" value="<?= e($s['id']) ?>">
            <button class="del needs-confirm" type="submit" title="Delete section">&times;</button>
          </form>
        </div>
        <?php foreach ($bySection($s['id']) as $h) render_habit_row($h, $days, $today, $csrf, $extraDays, $scol); ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php // No "+ Section" here any more: sections are added (and reordered, and recoloured)
        // from "Manage sections" in the filter dropdown, so a new one always gets a colour. ?>
</div>

<?php render_habit_section_modal($sections, $csrf); ?>

<?php render_tabbar('habits'); ?>
<script>
  const CSRF = '<?= $csrf ?>';

  // Edit mode (persisted, like the other tabs).
  // The Edit pencil is gone — the gesture is the way in — but chrome_script() still
  // looks for #editBtn, so everything here has to cope with it not being there.
  const editBtn = document.getElementById('editBtn');
  const setEdit = (on) => document.body.classList.toggle('editing', on);
  // Always starts off; a structural change redirects back with ?edit=1 to keep it on.
  setEdit(new URLSearchParams(location.search).get('edit') === '1');
  if (editBtn) { editBtn.addEventListener('click', () => setEdit(!document.body.classList.contains('editing'))); }

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
  // Opening a section's name is what the gesture on a section head is for.
  const openSectionName = head => {
    const f = head && head.querySelector('.sectitle');
    if (f) { setTimeout(() => { f.focus(); try { f.select(); } catch (_) {} }, 0); }
  };
  document.addEventListener('dblclick', e => {
    const head = e.target.closest('.hsection');
    if (head) { if (!editing()) setEdit(true); openSectionName(head); return; }
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
  // Leave edit mode by tapping away from what you're editing — the same gesture the
  // other apps have. A tap stays in edit on anything that *is* editing: a habit, a
  // section, a day square (still tickable in edit mode), the add buttons, a field, or
  // any of the windows layered over the page.
  document.addEventListener('click', (e) => {
    if (!editing()) { return; }
    if (e.target.closest('.hname, .hsection, .cell, .hdrag, .hcolor, .hswatches,'
        + ' .viewbar, .bar, button, a, input, textarea, select,'
        + ' .setmodal-backdrop, .modal-backdrop, .tabbar')) { return; }
    setEdit(false);
  });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && editing()) { setEdit(false); } });

  // Touch: a long press opens the name the same way a double-click does.
  let lpT = null, lpX = 0, lpY = 0, lpBox = null;
  const clearLp = () => { if (lpT) { clearTimeout(lpT); lpT = null; } };
  document.addEventListener('pointerdown', e => {
    if (e.pointerType === 'mouse') return;
    const box = e.target.closest('.hname, .hsection'); if (!box) return;
    if (e.target.closest('.del, .hdrag, button, form, input')) return;
    lpBox = box; lpX = e.clientX; lpY = e.clientY;
    lpT = setTimeout(() => {
      lpT = null;
      if (navigator.vibrate) navigator.vibrate(12);
      if (!editing()) setEdit(true);
      if (lpBox.classList.contains('hsection')) { openSectionName(lpBox); }
      else { startRename(lpBox); }
    }, 500);
  });
  document.addEventListener('pointermove', e => {
    if (lpT && (Math.abs(e.clientX - lpX) > 10 || Math.abs(e.clientY - lpY) > 10)) clearLp();
  });
  document.addEventListener('pointerup', clearLp);
  document.addEventListener('pointercancel', clearLp);

  // ----- Each "+ Habit" swaps itself for a name field, as elsewhere -----
  const wireAdd = (btnRef, formRef) => {
    const btn  = typeof btnRef  === 'string' ? document.getElementById(btnRef)  : btnRef;
    const form = typeof formRef === 'string' ? document.getElementById(formRef) : formRef;
    if (!btn || !form) { return; }
    const field = form.querySelector('input[name=name]');
    btn.addEventListener('click', () => { btn.hidden = true; form.hidden = false; field.focus(); });
    field.addEventListener('blur', () => {          // left empty: put the button back
      if (field.value.trim() === '') { form.hidden = true; btn.hidden = false; }
    });
    field.addEventListener('keydown', e => { if (e.key === 'Escape') { field.value = ''; field.blur(); } });
  };
  // One "+ Habit" per section, so they are wired by data-target rather than by id.
  document.querySelectorAll('.addhabit[data-target]').forEach(btn => {
    wireAdd(btn, document.getElementById(btn.dataset.target));
  });

  // ----- Drag to reorder habits and sections (edit mode) -----
  // Same bargain as the Reminders drag: nothing moves until the drop, and the only
  // feedback is one line saying where it will land. The grid is flat — a habit is a name
  // cell plus its day cells, a section spans the full width — so the line is a grid child
  // of its own spanning every column, sitting *between* two rows rather than on one.
  //
  // A section travels with the habits under it, the way a level-0 block does in
  // Reminders: moving the header alone would silently re-parent them, since a habit
  // belongs to whichever header last preceded it.
  (function () {
    const grid = document.getElementById('wGrid');
    if (!grid) { return; }
    let drag = null, line = null, pid = null;

    const HOSTS = '.hname[data-id], .hsection[data-section]';
    const seq   = () => [...grid.querySelectorAll(HOSTS)];
    const isSec = el => el.classList.contains('hsection');

    // A section's block is the header and every habit under it, up to the next header.
    const blockOf = (host) => {
      const list = seq(), i = list.indexOf(host);
      if (!isSec(host)) { return [host]; }
      const out = [host];
      for (let k = i + 1; k < list.length && !isSec(list[k]); k++) { out.push(list[k]); }
      return out;
    };

    const clearLine = () => { if (line) { line.remove(); line = null; } };
    // Put the line immediately before `host`, or at the very end when host is null.
    const putLine = (host) => {
      if (!line) {
        line = document.createElement('div');
        line.className = 'drop-line';
        line.setAttribute('aria-hidden', 'true');
      }
      if (host) { grid.insertBefore(line, host); } else { grid.appendChild(line); }
    };

    grid.addEventListener('pointerdown', (e) => {
      if (!document.body.classList.contains('editing')) { return; }
      if (!e.target.closest('.hdrag')) { return; }
      const host = e.target.closest(HOSTS);
      if (!host) { return; }
      e.preventDefault();
      drag = host; pid = e.pointerId;
      blockOf(host).forEach(el => el.classList.add('hdragging'));
      try { host.setPointerCapture(pid); } catch (_) {}
      if (navigator.vibrate) { navigator.vibrate(12); }
    });

    document.addEventListener('pointermove', (e) => {
      if (!drag) { return; }
      e.preventDefault();
      const under = document.elementFromPoint(e.clientX, e.clientY);
      const host  = under && under.closest(HOSTS);
      const list  = seq();
      if (!host) { return; }

      // Which gap the pointer is nearest: before this row, or after it.
      const r      = host.getBoundingClientRect();
      const after  = e.clientY > r.top + r.height / 2;
      let idx = list.indexOf(host) + (after ? 1 : 0);

      // A section can only land where another section starts, or at the very end —
      // anywhere else and it would swallow the rows it landed among.
      if (isSec(drag)) {
        const stops = [];
        list.forEach((el, i) => { if (isSec(el)) { stops.push(i); } });
        stops.push(list.length);
        idx = stops.reduce((best, v) => Math.abs(v - idx) < Math.abs(best - idx) ? v : best, stops[0]);
      }

      // Never draw the line on either edge of what's being dragged — that's a no-op move
      // and the line sitting there reads as though something will happen.
      const block = blockOf(drag);
      const bFrom = list.indexOf(block[0]), bTo = bFrom + block.length;
      if (idx >= bFrom && idx <= bTo) { clearLine(); return; }

      putLine(idx < list.length ? list[idx] : null);
    }, { passive: false });

    const drop = () => {
      if (!drag) { return; }
      const moved = drag;
      const list  = seq();
      // Read the line's place *before* taking it out, since it is a child of the grid.
      let to = null;
      if (line) {
        const after = [...grid.children].slice([...grid.children].indexOf(line) + 1);
        const next  = after.find(el => el.matches(HOSTS));
        to = next ? list.indexOf(next) : list.length;
      }
      blockOf(moved).forEach(el => el.classList.remove('hdragging'));
      clearLine();
      drag = null;
      if (to === null) { return; }

      const block = blockOf(moved);
      const from  = list.indexOf(block[0]);
      const rest  = list.filter(el => !block.includes(el));
      // Landing after the block means the removal has shifted everything left.
      const at    = to > from ? to - block.length : to;
      rest.splice(at, 0, ...block);

      // Read the intended grouping back out: a section opens a group, and every habit
      // after it belongs to that group until the next one.
      const order = [], sections = [];
      let cur = '';
      rest.forEach(el => {
        if (isSec(el)) { cur = el.dataset.section; sections.push(cur); }
        else { order.push({ id: el.dataset.id, section: cur }); }
      });
      const body = new URLSearchParams({ csrf: CSRF, action: 'reorder',
        order: JSON.stringify(order), sections: JSON.stringify(sections) });
      fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body })
        .then(() => location.reload()).catch(() => location.reload());
    };
    document.addEventListener('pointerup', drop);
    document.addEventListener('pointercancel', () => {
      if (drag) { blockOf(drag).forEach(el => el.classList.remove('hdragging')); clearLine(); drag = null; }
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

  // Collapse habit sections (week view). The grid is flat, so folding a section hides its
  // habit rows — the name bubbles and their day cells, both tagged with the section id —
  // while its header stays put. Remembered per page; the collapse-all button folds every
  // section or, on a second press, expands them all.
  (function () {
    var grid = document.getElementById('wGrid'); if (!grid) { return; }
    var KEY = 'habitcollapsed:' + location.pathname;
    function load() { try { return new Set(JSON.parse(localStorage.getItem(KEY) || '[]')); } catch (_) { return new Set(); } }
    function save(s) { try { localStorage.setItem(KEY, JSON.stringify([].slice.call(s))); } catch (_) {} }
    var state = load();
    function apply() {
      document.querySelectorAll('.hsection').forEach(function (h) { h.classList.toggle('collapsed', state.has(h.dataset.section)); });
      grid.querySelectorAll('.hname, .cell').forEach(function (el) { el.classList.toggle('hrow-folded', state.has(el.dataset.section)); });
      var cab = document.getElementById('hCollapseAll'), secs = document.querySelectorAll('.hsection');
      if (cab) { cab.classList.toggle('all-collapsed', secs.length > 0 && [].every.call(secs, function (h) { return state.has(h.dataset.section); })); }
    }
    document.addEventListener('click', function (e) {
      var chev = e.target.closest && e.target.closest('.hsection .sec-collapse');
      if (chev) {
        e.preventDefault(); e.stopPropagation();
        var id = chev.closest('.hsection').dataset.section;
        if (state.has(id)) { state.delete(id); } else { state.add(id); }
        save(state); apply(); return;
      }
      if (e.target.closest && e.target.closest('#hCollapseAll')) {
        e.preventDefault(); e.stopPropagation();
        var all = [].slice.call(document.querySelectorAll('.hsection'));
        var collapse = all.some(function (h) { return !state.has(h.dataset.section); });
        all.forEach(function (h) { if (collapse) { state.add(h.dataset.section); } else { state.delete(h.dataset.section); } });
        save(state); apply();
      }
    });
    apply();
  })();
</script>
<?= chrome_script() ?>
</body>
</html>
