<?php
// Locate the shared lib/ — local dev (../../lib) or NFSN (/home/protected/lib).
$__libDir = null;
foreach ([__DIR__ . '/../../lib', '/home/protected/lib'] as $__c) {
    if (is_file($__c . '/auth.php')) { $__libDir = $__c; break; }
}
require_once $__libDir . '/auth.php';
require_once $__libDir . '/tabbar.php';
require_once $__libDir . '/chrome.php';
require_once $__libDir . '/folders.php';
require_once $__libDir . '/sharing.php';
require_login('Calendar');   // same login as Reminders

$cfg = app_config();

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES);
}

function load_json_list(string $file): array { return store_read($file); }
/** A reminders list, brought up to the permanent-folder model (see lib/util.php).
 *  Read-only here: the Calendar normalises in memory and never rewrites the file. */
function load_reminder_list(string $file): array { return reminders_folder_migrate(store_read($file)); }
function save_json_list(string $file, array $list): void { store_write($file, array_values($list)); }

/** File + field names for each item kind. */
function kind_spec(string $kind): ?array
{
    return [
        'reminder' => ['base' => 'reminders', 'textField' => 'text',  'dateField' => 'due'],
        'note'     => ['base' => 'notes',     'textField' => 'title', 'dateField' => 'date'],
        'event'    => ['base' => 'events',    'textField' => 'text',  'dateField' => 'date'],
    ][$kind] ?? null;
}

/** Palette offered when tapping a calendar's colour square — the calendar tier of the
 *  suite palette (blue, red, green, orange, purple, grey). See lib/palette.php. */
define('CAL_COLORS', app_palette('calendar'));

/**
 * A calendar is ['id','name','color']; list order is display order. There used to be a
 * second kind of row here — a "calendar set", a saved group of calendars to view
 * together — and it earned its keep nowhere: the picker, the manage window, the widget
 * and the day panel each had a branch for it. Any set rows still in a file are dropped
 * on the next load. This predicate is only kept to recognise and discard them.
 */
function is_calset(array $it): bool { return ($it['type'] ?? '') === 'set'; }

/** Always hands back at least one calendar, creating the default one on first use. */
function load_calendars(string $file): array
{
    $list  = store_read($file);
    $clean = array_values(array_filter($list, fn($it) => !is_calset($it)));   // drop legacy sets
    foreach ($clean as &$it) { if (isset($it['color'])) { $it['color'] = cal_color_fix($it['color']); } }
    unset($it);
    if (!$clean) {
        $clean[] = ['id' => bin2hex(random_bytes(6)), 'name' => 'Personal',
                    'color' => CAL_COLORS[0], 'created' => time()];
    }
    if (count($clean) !== count($list)) { store_write($file, $clean); }
    return $clean;
}

$calFile = user_data_file($cfg['data_dir'], 'calendars');
$calList = load_calendars($calFile);

// Reminder folders the user has switched off for the calendar. Kept in its own
// little settings file rather than in the calendar list, which is strictly items.
$prefFile   = user_data_file($cfg['data_dir'], 'calprefs');
$calPrefs   = store_read($prefFile);
$hidFolders = array_values(array_filter((array) ($calPrefs['hidden_folders'] ?? []), 'is_string'));
$hidShared  = array_values(array_filter((array) ($calPrefs['hidden_shared_folders'] ?? []), 'is_string'));
$remFolders = folders_load($cfg['data_dir'])['reminders'];

/**
 * How much of a reminder folder shows on the calendar: 'all' (its dated reminders plus
 * its undated ones riding along under today), 'dated' (only dated) or 'none'. The
 * permanent Calendar folder defaults to 'all' — the ride-along is its whole point — and
 * every other folder to 'dated'; a folder switched off under the old model reads as 'none'.
 */
function rf_mode(array $prefs, string $folder, bool $shared = false): string
{
    $m = $prefs[$shared ? 'rf_mode_shared' : 'rf_mode'][$folder] ?? null;
    if (in_array($m, ['all', 'dated', 'none'], true)) { return $m; }
    $hidden = (array) ($prefs[$shared ? 'hidden_shared_folders' : 'hidden_folders'] ?? []);
    if (in_array($folder, $hidden, true)) { return 'none'; }
    return (!$shared && $folder === FOLDER_CALENDAR) ? 'all' : 'dated';
}

// --- Sharing: the other person's calendars and reminder folders, if they shared any ---
$me          = current_user() ?? '';
$partner     = share_partner();
$myShares    = $partner ? shares_load($cfg['data_dir'], $me) : ['calendars' => [], 'folders' => []];
$theirShares = $partner ? shares_load($cfg['data_dir'], $partner) : ['calendars' => [], 'folders' => []];

// Their whole calendar list (needed to resolve an event with no calendar), and the shared slice.
$theirCals   = $partner ? array_values(array_filter(store_read(user_data_file($cfg['data_dir'], 'calendars', $partner)),
                                                    fn($c) => !is_calset($c))) : [];   // their legacy sets too
$theirCalIds = array_column($theirCals, 'id');
$sharedCals  = array_values(array_filter($theirCals, fn($c) => in_array($c['id'] ?? '', $theirShares['calendars'], true)));
$sharedIds   = array_column($sharedCals, 'id');
// Their folders that they shared and I haven't switched off.
$sharedFolders = $partner
    ? array_values(array_intersect(folders_load($cfg['data_dir'], $partner)['reminders'], $theirShares['folders']))
    : [];

// --- Quick add / edit / delete from the calendar (POST -> redirect -> GET), CSRF protected ---
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action'])) {
    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Bad request (invalid CSRF token).');
    }
    $action  = (string) $_POST['action'];
    $date    = (string) ($_POST['date'] ?? '');
    $dateOk  = (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
    $time    = trim((string) ($_POST['time'] ?? ''));
    $timeOk  = (bool) preg_match('/^\d{2}:\d{2}$/', $time);
    $text    = trim((string) ($_POST['text'] ?? ''));
    $kind    = (string) ($_POST['kind'] ?? '');
    $id      = (string) ($_POST['id'] ?? '');
    // Stay on the day you were looking at, even if the edit moved the item to another
    // one — jumping the calendar out from under you is more disorienting than useful.
    // Only fall back to the item's own date when we don't know which day was open.
    $dayParam = (string) ($_POST['day'] ?? '');
    $retDay   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dayParam) ? $dayParam : ($dateOk ? $date : '');
    $ym       = $retDay !== '' ? substr($retDay, 0, 7) : ((string) ($_POST['ym'] ?? date('Y-m')));
    $stay = '';   // extra query bits for the redirect (e.g. keep edit mode on)

    // --- Manage calendars (AJAX: answers with the fresh list, no reload) ---
    if (in_array($action, ['cal_add', 'cal_delete', 'cal_color', 'cal_reorder', 'cal_default'], true)) {
        $calIdsNow = array_column($calList, 'id');
        $name      = mb_substr(trim(preg_replace('/\s+/', ' ', (string) ($_POST['name'] ?? ''))), 0, 40);

        if ($action === 'cal_add' && $name !== '') {
            $calList[] = ['id' => bin2hex(random_bytes(6)), 'name' => $name,
                          'color' => CAL_COLORS[count($calIdsNow) % count(CAL_COLORS)], 'created' => time()];
        } elseif ($action === 'cal_delete' && count($calIdsNow) > 1 && !empty($_POST['confirm'])) {
            $calList = array_values(array_filter($calList, fn($c) => ($c['id'] ?? '') !== $id));
            $evFile  = user_data_file($cfg['data_dir'], 'events');   // its events fall back to the first calendar
            $evs     = load_json_list($evFile);
            $touched = false;
            foreach ($evs as &$ev) { if (($ev['cal'] ?? '') === $id) { $ev['cal'] = ''; $touched = true; } }
            unset($ev);
            if ($touched) { save_json_list($evFile, $evs); }
        } elseif ($action === 'cal_color') {
            $color = (string) ($_POST['color'] ?? '');
            if (in_array($color, CAL_COLORS, true)) {
                foreach ($calList as &$c) {
                    if (($c['id'] ?? '') === $id) { $c['color'] = $color; break; }
                }
                unset($c);
            }
        } elseif ($action === 'cal_reorder') {
            // The posted order interleaves my own calendars with the partner's shared ones,
            // so it's kept whole in calprefs (cal_order) — it drives the manage list and the
            // same-time event order. My own list is also reordered by its subset, since some
            // readers (the default picker) still walk the own-only list.
            $ord = array_values(array_filter((array) (json_decode((string) ($_POST['order'] ?? '[]'), true) ?: []), 'is_string'));
            $valid = array_merge($calIdsNow, $sharedIds);
            $ord = array_values(array_filter($ord, fn($x) => in_array($x, $valid, true)));
            $calPrefs['cal_order'] = $ord;
            store_write($prefFile, $calPrefs);
            $pos = array_flip($ord);
            usort($calList, fn($a, $b) => ($pos[$a['id']] ?? 999) <=> ($pos[$b['id']] ?? 999));
        } elseif ($action === 'cal_default') {
            // Which calendar new events land in. Kept in calprefs, not the calendar list.
            if (in_array($id, $calIdsNow, true)) {
                $calPrefs['default_cal'] = $id;
                store_write($prefFile, $calPrefs);
            }
        }
        save_json_list($calFile, $calList);
        // The chosen default may have just been deleted; fall back to the first calendar.
        $idsAfter = array_column($calList, 'id');
        $defNow   = (string) ($calPrefs['default_cal'] ?? '');
        if (!in_array($defNow, $idsAfter, true)) { $defNow = $idsAfter[0] ?? ''; }
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'list' => array_values($calList), 'default' => $defNow,
                          'order' => array_values(array_filter((array) ($calPrefs['cal_order'] ?? []), 'is_string'))]);
        exit;
    }

    // --- Show/hide a reminder folder on the calendar (AJAX, same answer-with-the-truth style) ---
    if ($action === 'folder_vis') {
        $fname  = (string) ($_POST['name'] ?? '');
        $isTheirs = !empty($_POST['shared']);
        $pool   = $isTheirs ? $sharedFolders : $remFolders;
        $key    = $isTheirs ? 'hidden_shared_folders' : 'hidden_folders';
        if (in_array($fname, $pool, true)) {
            $cur = array_values(array_filter($isTheirs ? $hidShared : $hidFolders, fn($f) => $f !== $fname));
            if (empty($_POST['show'])) { $cur[] = $fname; }
            if ($isTheirs) { $hidShared = $cur; } else { $hidFolders = $cur; }
            $calPrefs[$key] = $cur;
            store_write($prefFile, $calPrefs);
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'hidden' => $hidFolders, 'hiddenShared' => $hidShared]);
        exit;
    }

    // --- How much of a reminder folder shows on the calendar: all / dated / none (AJAX) ---
    if ($action === 'rf_mode') {
        $fname    = (string) ($_POST['name'] ?? '');
        $mode     = in_array($_POST['mode'] ?? '', ['all', 'dated', 'none'], true) ? $_POST['mode'] : 'dated';
        $isTheirs = !empty($_POST['shared']);
        $pool     = $isTheirs ? $sharedFolders : $remFolders;
        $key      = $isTheirs ? 'rf_mode_shared' : 'rf_mode';
        if (in_array($fname, $pool, true)) {
            $modes = is_array($calPrefs[$key] ?? null) ? $calPrefs[$key] : [];
            $modes[$fname] = $mode;
            $calPrefs[$key] = $modes;
            store_write($prefFile, $calPrefs);
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }

    // --- Show/hide a calendar in the "all" view (AJAX), the same as a reminder folder ---
    if ($action === 'cal_vis') {
        $cid = (string) ($_POST['name'] ?? '');
        $cur = array_values(array_filter((array) ($calPrefs['hidden_cals'] ?? []), fn($x) => $x !== $cid));
        if ($cid !== '' && empty($_POST['show'])) { $cur[] = $cid; }
        $calPrefs['hidden_cals'] = $cur;
        store_write($prefFile, $calPrefs);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }
    // --- The "All calendars" master checkbox: show or hide every calendar at once ---
    if ($action === 'cal_vis_all') {
        $allIds = array_values(array_merge(array_column($calList, 'id'), $sharedIds));
        $calPrefs['hidden_cals'] = empty($_POST['show']) ? $allIds : [];
        store_write($prefFile, $calPrefs);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }

    // --- Share one of my calendars / reminder folders with the other person ---
    if ($action === 'share_set' && $partner) {
        share_handle_set($cfg['data_dir'], $me,
                         array_column($calList, 'id'),
                         $remFolders, folders_load($cfg['data_dir'])['notes']);
    }

    // An event's calendar, ignored unless it names one that exists.
    $calIds  = array_column($calList, 'id');
    $evCal   = (string) ($_POST['cal'] ?? '');
    $evCalOk = in_array($evCal, $calIds, true) ? $evCal : '';

    // "Dinner 8/3 7pm" -> text "Dinner", date 2026-08-03, time 19:00. An explicit
    // date or time from the window always wins over what was typed.
    [$ptext, $pdate, $ptime] = parse_when_from_text($text);
    $effDate = $dateOk ? $date : $pdate;

    // "Every 2 weeks" from the window's repeat row; null when it happens once.
    $rep = repeat_clean($_POST['rep_unit'] ?? '', $_POST['rep_n'] ?? 1);

    /**
     * Whatever was just added has to be *visible* afterwards, or adding it reads as
     * having failed. The view is a filter — one calendar, or one of the partner's
     * shared folders — and a new item lands wherever the add window said, which is
     * very often outside it. Rather than refuse the add or silently drop it behind the
     * filter, widen the view back to "all" and un-hide the folder it went into.
     */
    $showAgain = function (?string $cal, string $folder = '') use (&$calPrefs, $prefFile, $hidFolders) {
        $dirty = false;
        $view  = (string) ($calPrefs['last_cal'] ?? 'all');
        // $cal null = "not a calendar thing" (a reminder): only a shared-folder view,
        // which hides everything of mine, has to give way.
        $hides = $cal === null ? strncmp($view, 'f:', 2) === 0 : ($view !== 'all' && $view !== $cal);
        if ($hides) { $calPrefs['last_cal'] = 'all'; $dirty = true; }
        if ($folder !== '' && in_array($folder, $hidFolders, true)) {
            $calPrefs['hidden_folders'] = array_values(array_filter($hidFolders, fn($f) => $f !== $folder));
            $dirty = true;
        }
        if ($dirty) { store_write($prefFile, $calPrefs); }
    };

    if ($action === 'add_event' && $text !== '') {
        // New items always go into my own files — the partner's data is read-only to me,
        // so the window only asks which of *my* calendars to add the event to.
        $file = user_data_file($cfg['data_dir'], 'events');
        $list = load_json_list($file);
        $list[] = ['id' => bin2hex(random_bytes(6)), 'text' => mb_substr($ptext, 0, 500),
                   'date' => $effDate, 'time' => $timeOk ? $time : $ptime,
                   'cal' => $evCalOk, 'repeat' => $rep, 'created' => time()];
        save_json_list($file, $list);
        $showAgain($evCalOk !== '' ? $evCalOk : ($calIds[0] ?? ''));
    } elseif ($action === 'add_reminder' && $text !== '') {
        // A reminder belongs to a folder and a group, never to a calendar. The one picker
        // carries "folder\x1Fgroup" for one of *my* folders; both halves are re-validated.
        $splitSec = function (string $v): array {
            return strpos($v, "\x1F") !== false ? explode("\x1F", $v, 2) : ['', $v];
        };
        [$remFolder, $section] = $splitSec((string) ($_POST['section'] ?? ''));
        if (!in_array($remFolder, $remFolders, true)) {
            $remFolder = folder_default_get($cfg['data_dir'], 'reminders');
        }
        $file = user_data_file($cfg['data_dir'], 'reminders');
        $list = load_reminder_list($file);
        // Sections are per-folder, so only that folder's sections are valid here.
        $secOk = [];
        foreach ($list as $it) {
            if (($it['type'] ?? '') === 'section' && ($it['folder'] ?? '') === $remFolder) {
                $secOk[(string) $it['name']] = true;
            }
        }
        if ($section !== '' && !isset($secOk[$section])) { $section = ''; }
        $list[] = ['id' => bin2hex(random_bytes(6)), 'text' => mb_substr($ptext, 0, 500),
                   'due' => $effDate, 'time' => $timeOk ? $time : $ptime, 'done' => false,
                   'folder' => $remFolder,
                   'section' => $section, 'repeat' => $rep, 'created' => time()];
        save_json_list($file, $list);
        // Reminders aren't filtered by calendar, but the folder they landed in may be switched off.
        $showAgain(null, $remFolder);
    } elseif ($action === 'add_note' && $text !== '') {
        $file  = user_data_file($cfg['data_dir'], 'notes');
        $list  = load_json_list($file);
        $newId = bin2hex(random_bytes(6));
        $list[] = ['id' => $newId, 'title' => mb_substr($ptext, 0, 200),
                   'date' => $effDate, 'time' => $timeOk ? $time : $ptime,
                   'body' => '', 'created' => time(), 'updated' => time()];
        save_json_list($file, $list);
        header('Location: /notes/?id=' . $newId);   // jump straight to the note editor
        exit;
    } elseif ($action === 'toggle_reminder' && $id !== '') {
        // A reminder shown from a shared folder still lives in its owner's file.
        $owner = ($partner && ($_POST['owner'] ?? '') === $partner) ? $partner : null;
        $file = user_data_file($cfg['data_dir'], 'reminders', $owner);
        $list = load_reminder_list($file);
        foreach ($list as &$it) {
            // Only ever reach into a folder they actually shared.
            if ($owner !== null && !in_array($it['folder'] ?? '', $sharedFolders, true)) { continue; }
            if (($it['id'] ?? '') === $id) {
                $rr = repeat_get($it);
                if ($rr !== null && empty($it['done']) && !empty($it['due'])) {
                    // A repeat never finishes: ticking it moves it to the next date.
                    // Never backwards, so a long-overdue one lands on its next future day.
                    $it['due'] = repeat_next($it['due'], $rr, max($it['due'], date('Y-m-d')));
                } else {
                    $it['done'] = empty($it['done']);
                }
                break;
            }
        }
        unset($it);
        save_json_list($file, $list);
    } elseif ($action === 'edit_item' && ($spec = kind_spec($kind)) && $id !== '' && $text !== '') {
        $file = user_data_file($cfg['data_dir'], $spec['base']);
        $list = load_json_list($file);
        foreach ($list as &$it) {
            if (($it['id'] ?? '') === $id) {
                // Same reading as adding one: a date or time typed into the text counts
                // ("Vet 8/3 2pm"), and the window's own fields win over what was typed.
                $it[$spec['textField']] = mb_substr($ptext, 0, $kind === 'note' ? 200 : 500);
                $it[$spec['dateField']] = $effDate;
                $it['time'] = $timeOk ? $time : $ptime;               // reminders, notes and events can carry a time
                if ($kind !== 'note')  { $it['repeat'] = $rep; }
                if ($kind === 'event') { $it['cal'] = $evCalOk; }
                if ($kind === 'note')  { $it['updated'] = time(); }
                break;
            }
        }
        unset($it);
        save_json_list($file, $list);
    } elseif ($action === 'delete_item' && ($spec = kind_spec($kind)) && $id !== ''
              && !empty($_POST['confirm'])) {   // only the confirmed second press deletes
        $file = user_data_file($cfg['data_dir'], $spec['base']);
        $list = load_json_list($file);
        $list = array_values(array_filter($list, fn($it) => ($it['id'] ?? '') !== $id));
        save_json_list($file, $list);
        $stay = '&edit=1';   // deleting is edit-mode only; hand it back
    }

    $loc = _self_path() . '?ym=' . $ym;
    if ($retDay !== '') { $loc .= '&day=' . $retDay; }
    header('Location: ' . $loc . $stay);
    exit;
}

// --- Which month are we viewing? (?ym=YYYY-MM, default: current) ---
$ym = (string) ($_GET['ym'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
    $ym = date('Y-m');
}
[$year, $month] = array_map('intval', explode('-', $ym));

$firstTs   = mktime(0, 0, 0, $month, 1, $year);
$daysInMo  = (int) date('t', $firstTs);
$leadBlank = (int) date('w', $firstTs);                 // 0=Sun .. 6=Sat
$monthName = date('F Y', $firstTs);
$todayYmd  = date('Y-m-d');
$csrf      = htmlspecialchars($_SESSION['csrf'], ENT_QUOTES);

// The grid fills its first and last rows with the neighbouring months' days.
$prevDays  = (int) date('t', mktime(0, 0, 0, $month - 1, 1, $year));
$tailBlank = (7 - ($leadBlank + $daysInMo) % 7) % 7;

$prev = date('Y-m', mktime(0, 0, 0, $month - 1, 1, $year));
$next = date('Y-m', mktime(0, 0, 0, $month + 1, 1, $year));

// --- Which calendar is on screen? ?cal= picks it; the choice sticks in calprefs. ---
$calsOnly = $calList;
$calIds   = array_column($calsOnly, 'id');
// New events land here when you aren't looking at one calendar in particular.
// Chosen in the manage window; falls back to the first calendar.
$defCal   = (string) ($calPrefs['default_cal'] ?? '');
if (!in_array($defCal, $calIds, true)) { $defCal = $calIds[0] ?? ''; }
$calColor = array_column($calsOnly, 'color', 'id');

// Shared calendars join the picker and the colour map; their ids stay distinct from mine.
$pickIds  = array_merge($calIds, $sharedIds);
$calColor = array_merge($calColor, array_column($sharedCals, 'color', 'id'));

// The choice sticks in calprefs, so it survives closing the app — not just the session.
if (isset($_GET['cal'])) {
    $calPrefs['last_cal'] = (string) $_GET['cal'];
    store_write($prefFile, $calPrefs);
}
// Calendars switched off in the picker (by id). "All" then means every calendar I
// haven't hidden — the same show/hide model the reminder folders use.
$hiddenCals  = array_values(array_filter((array) ($calPrefs['hidden_cals'] ?? []), 'is_string'));
$calView     = (string) ($calPrefs['last_cal'] ?? 'all');
$visibleCals = null;                                  // null = show every calendar
$onlyFolder  = null;                                  // set = show just this shared folder's reminders
if (strncmp($calView, 'f:', 2) === 0) {
    $f = substr($calView, 2);
    if (in_array($f, $sharedFolders, true)) { $onlyFolder = $f; $visibleCals = []; }   // no events at all
    else { $calView = 'all'; }
} elseif (in_array($calView, $pickIds, true)) {
    $visibleCals = [$calView];
} elseif ($calView !== 'all') {
    $calView = 'all';   // stale id (a deleted calendar, or one of the old sets)
}
// In the "all" view, honour the per-calendar checkboxes: show every calendar that isn't
// switched off. (A single-calendar view already narrowed to just that one above.)
if ($calView === 'all') {
    $visibleCals = array_values(array_filter($pickIds, fn($id) => !in_array($id, $hiddenCals, true)));
}

// --- Sync: gather this user's dated reminders + notes for the visible month ---
// The window is the whole grid, not just the month: the greyed days either side are
// tappable too, so they need their items loaded like any other cell.
$monthFrom   = date('Y-m-d', mktime(0, 0, 0, $month, 1 - $leadBlank, $year));
$monthTo     = date('Y-m-d', mktime(0, 0, 0, $month, $daysInMo + $tailBlank, $year));
$byDay = [];   // 'YYYY-MM-DD' => [ ['kind'=>'reminder'|'note', 'text'=>..., 'done'=>bool], ... ]

// Folder colours, so a day's reminder and note dots wear their folder's colour (a
// partner's shared folders use the lighter "shared" palette). Resolved into each entry.
$remFolderColor   = folder_colors($cfg['data_dir'], 'reminders');
$noteFolderColor  = folder_colors($cfg['data_dir'], 'notes');
$remFolderTheirs  = $partner ? folder_colors($cfg['data_dir'], 'reminders', $partner) : [];

/**
 * The days a reminder shows on this month: its own (possibly rolled-forward) due
 * date, then any later repeats. Each entry is [ymd, wasRolled]. A repeat's earlier
 * occurrences aren't drawn — ticking one moves the stored due on, so the row always
 * sits on the next date it owes.
 */
$remDays = function (string $due, ?array $rep, string $eff) use ($monthFrom, $monthTo): array {
    $out = ($eff >= $monthFrom && $eff <= $monthTo) ? [[$eff, $eff !== $due]] : [];
    foreach (repeat_dates($due, $rep, $monthFrom, $monthTo) as $d) {
        if ($d > $eff) { $out[] = [$d, false]; }
    }
    return $out;
};

foreach ($onlyFolder === null ? load_reminder_list(user_data_file($cfg['data_dir'], 'reminders')) : [] as $r) {
    if (($r['type'] ?? '') === 'section') { continue; }
    $rfolder = $r['folder'] ?? folder_fallback('reminders');
    $mode    = rf_mode($calPrefs, $rfolder);
    if ($mode === 'none') { continue; }                       // folder switched off
    // Undated reminders ride along under today only when the folder is set to "All".
    $rides = empty($r['due']) && $mode === 'all';
    if (empty($r['due']) && !$rides) { continue; }
    $done = !empty($r['done']);                                    // done are hidden until "Completed"
    $eff  = $rides ? $todayYmd
          : ((!$done && $r['due'] < $todayYmd) ? $todayYmd : $r['due']);   // overdue rolls onto today; done/future stay
    $rep = repeat_get($r);
    foreach ($rides ? [[$todayYmd, false]] : $remDays((string) $r['due'], $rep, $eff) as [$d, $wasRolled]) {
        // A riding item isn't late — it just lives on today — so don't mark it overdue.
        $byDay[$d][] = ['kind' => 'reminder', 'id' => $r['id'] ?? '', 'text' => $r['text'] ?? '',
                        'done' => $done, 'rolled' => (!$rides && $wasRolled),
                        'due' => $r['due'] ?? null, 'rep' => $rep,
                        'color' => $remFolderColor[$r['folder'] ?? ''] ?? app_palette('reminders')[0]];
    }
}
$evList = load_json_list(user_data_file($cfg['data_dir'], 'events'));
usort($evList, fn($a, $b) => ((($a['time'] ?? '') ?: '99:99')) <=> ((($b['time'] ?? '') ?: '99:99')));
foreach ($evList as $ev) {
    // An event with no (or a stale) calendar belongs to the first one.
    $ec = in_array($ev['cal'] ?? '', $calIds, true) ? $ev['cal'] : $defCal;
    if ($visibleCals !== null && !in_array($ec, $visibleCals, true)) { continue; }
    foreach (repeat_dates((string) ($ev['date'] ?? ''), repeat_get($ev), $monthFrom, $monthTo) as $d) {
        $byDay[$d][] = ['kind' => 'event', 'id' => $ev['id'] ?? '', 'text' => $ev['text'] ?? '',
                        'time' => $ev['time'] ?? '', 'done' => false, 'rep' => repeat_get($ev),
                        'start' => $ev['date'] ?? '',
                        'cal' => $ec, 'color' => $calColor[$ec] ?? CAL_COLORS[0]];
    }
}

// --- The other person's shared calendars and reminder folders, read from their files ---
if ($partner) {
    $theirDef = $theirCalIds[0] ?? '';
    foreach (load_reminder_list(user_data_file($cfg['data_dir'], 'reminders', $partner)) as $r) {
        if (($r['type'] ?? '') === 'section') { continue; }
        $f = $r['folder'] ?? '';
        if (!in_array($f, $sharedFolders, true)) { continue; }
        // Picking a folder in the dropdown shows just that one; otherwise the All/Dated/None
        // mode for their folder decides, the same as for my own.
        $smode = $onlyFolder !== null ? ($f === $onlyFolder ? 'all' : 'none') : rf_mode($calPrefs, $f, true);
        if ($smode === 'none') { continue; }
        $srides = empty($r['due']) && $smode === 'all';
        if (empty($r['due']) && !$srides) { continue; }
        $done = !empty($r['done']);
        $eff  = $srides ? $todayYmd : ((!$done && $r['due'] < $todayYmd) ? $todayYmd : $r['due']);
        $rep = repeat_get($r);
        foreach ($srides ? [[$todayYmd, false]] : $remDays((string) $r['due'], $rep, $eff) as [$d, $wasRolled]) {
            $byDay[$d][] = ['kind' => 'reminder', 'id' => $r['id'] ?? '', 'text' => $r['text'] ?? '',
                            'done' => $done, 'rolled' => (!$srides && $wasRolled), 'due' => $r['due'] ?? null,
                            'rep' => $rep, 'owner' => $partner,
                            'color' => $remFolderTheirs[$f] ?? app_palette('reminders', true)[0]];
        }
    }
    if ($sharedIds) {
        $theirEvs = load_json_list(user_data_file($cfg['data_dir'], 'events', $partner));
        usort($theirEvs, fn($a, $b) => ((($a['time'] ?? '') ?: '99:99')) <=> ((($b['time'] ?? '') ?: '99:99')));
        foreach ($theirEvs as $ev) {
            $ec = in_array($ev['cal'] ?? '', $theirCalIds, true) ? $ev['cal'] : $theirDef;
            if (!in_array($ec, $sharedIds, true)) { continue; }              // not shared with me
            if ($visibleCals !== null && !in_array($ec, $visibleCals, true)) { continue; }
            foreach (repeat_dates((string) ($ev['date'] ?? ''), repeat_get($ev), $monthFrom, $monthTo) as $d) {
                $byDay[$d][] = ['kind' => 'event', 'id' => $ev['id'] ?? '', 'text' => $ev['text'] ?? '',
                                'time' => $ev['time'] ?? '', 'done' => false, 'rep' => repeat_get($ev),
                                'start' => $ev['date'] ?? '',
                                'cal' => $ec, 'color' => $calColor[$ec] ?? CAL_COLORS[0],
                                'owner' => $partner];
            }
        }
    }
}

foreach ($onlyFolder === null ? load_json_list(user_data_file($cfg['data_dir'], 'notes')) : [] as $n) {
    if (!empty($n['date']) && $n['date'] >= $monthFrom && $n['date'] <= $monthTo) {
        $byDay[$n['date']][] = ['kind' => 'note', 'id' => $n['id'] ?? '', 'text' => $n['title'] ?? 'Untitled note',
                                'done' => false,
                                'color' => $noteFolderColor[$n['folder'] ?? FOLDER_DEFAULT] ?? app_palette('notes')[0]];
    }
}
ksort($byDay);
// Within a day, keep the legend's order — events, then reminders, then notes — so the
// dots and the day panel read the same way. A stable sort (PHP 8+) leaves events in
// time order and reminders in the order they were gathered.
$kindRank = ['event' => 0, 'reminder' => 1, 'note' => 2];
// Same-time events fall in the calendar order set in Manage calendars (cal_order, which
// interleaves mine and the partner's); reminders keep their gathered order (stable sort).
$calRank = array_flip(array_values(array_filter((array) ($calPrefs['cal_order'] ?? []), 'is_string')));
foreach ($byDay as $d => $list) {
    usort($list, function ($a, $b) use ($kindRank, $calRank) {
        $k = ($kindRank[$a['kind']] ?? 9) <=> ($kindRank[$b['kind']] ?? 9);
        if ($k !== 0) { return $k; }
        if (($a['kind'] ?? '') === 'event') {
            $t = ((($a['time'] ?? '') ?: '99:99')) <=> ((($b['time'] ?? '') ?: '99:99'));
            if ($t !== 0) { return $t; }
            return ($calRank[$a['cal'] ?? ''] ?? 999) <=> ($calRank[$b['cal'] ?? ''] ?? 999);
        }
        return 0;
    });
    $byDay[$d] = $list;
}

// Which day starts selected? An explicit ?day= wins; otherwise today when the viewed
// month is the current one, and the first of the month for any other month — so paging
// to another month lands you on its 1st rather than on nothing.
$selDay = (string) ($_GET['day'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selDay) || $selDay < $monthFrom || $selDay > $monthTo) {
    $selDay = (substr($todayYmd, 0, 7) === sprintf('%04d-%02d', $year, $month))
        ? $todayYmd
        : sprintf('%04d-%02d-01', $year, $month);
}
$itemsJson = json_encode($byDay, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>Calendar</title>
  <meta name="theme-color" content="#111111">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black">
  <meta name="apple-mobile-web-app-title" content="Calendar">
  <link rel="apple-touch-icon" href="/reminders/icon-180.png">
  <link rel="icon" href="/reminders/icon-192.png">
  <link rel="manifest" href="/reminders/manifest.webmanifest?v=2">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { height: 100%; }
    body {
      font-family: system-ui, sans-serif; background: #111; color: #eee;
      display: flex; flex-direction: column; height: 100dvh; overflow: hidden;
    }
    /* Top: the calendar */
    .cal-top {
      flex: 0 0 auto; max-height: 60vh; overflow-y: auto; overscroll-behavior: contain;
      padding: 1.5rem 1rem 0.5rem;   /* same top offset as the other apps */
    }
    .cal-top .wrap { max-width: 640px; margin: 0 auto; }
    /* Bottom: the selected-day agenda */
    .daypanel {
      flex: 1 1 auto; min-height: 0; overflow-y: auto; overscroll-behavior: contain;
      border-top: 1px solid #2a2a2a; background: #141414;
      padding: 0.9rem 1rem calc(84px + env(safe-area-inset-bottom, 0px));
    }
    .daypanel .wrap { max-width: 640px; margin: 0 auto; }
    .wrap { max-width: 640px; margin: 0 auto; }
    header {
      display: flex; align-items: center; justify-content: space-between;
    }
    header h1 { font-size: 1.35rem; }
    header .titlebar { display: flex; align-items: center; gap: 0.85rem; }
    /* Widget lives in the manager's button row now, dressed like Share. */
    .modal .buttons .widgetlink {
      display: inline-flex; align-items: center; background: #2a2a2a; border: none; color: #ccc;
      text-decoration: none; padding: 0.55rem 1.1rem; border-radius: 6px; font-size: 0.95rem;
      font-weight: 600; line-height: 1.2;
    }
    .modal .buttons .widgetlink:hover { background: #333; color: #fff; }
    header nav a { color: #888; text-decoration: none; margin-left: 1rem; font-size: 0.85rem; }
    header nav a:hover { color: #fff; }
    header nav .who {
      color: var(--accent); font-size: 0.8rem; border: 1px solid #2a4a3d;
      border-radius: 999px; padding: 0.15rem 0.6rem;
    }

    /* Visible-calendar picker, under the back button / title. Hand-built rather than a
       <select> so each entry can carry its calendar's colour dot. */
    .calpick { position: relative; display: inline-flex; }   /* rides in the title bar, right of the + */
    /* Closed, the picker is one round button wearing the selected calendar's colour. */
    .calpick-btn {
      display: inline-flex; align-items: center; justify-content: center;
      width: 32px; height: 32px; padding: 0;
      background: #1a1a1a; border: 1px solid #333; border-radius: 50%; cursor: pointer;
    }
    .calpick-btn:hover { border-color: #888; }
    .calpick .cdot {
      flex: 0 0 auto; width: 9px; height: 9px; border-radius: 50%; background: #555;
    }
    /* After the rule above, or it would shrink the button's dot back to menu size. */
    .calpick-btn .cdot { width: 16px; height: 16px; }
    .calpick .cdot.all {
      background: conic-gradient(var(--k-event), var(--k-reminder), #facc15, #f472b6, var(--k-event));
    }
    .calpick-menu {
      position: fixed; z-index: 90; min-width: 210px;
      max-width: min(320px, 90vw); max-height: 60vh; overflow-y: auto;
      background: #1c1c1c; border: 1px solid #333; border-radius: 10px;
      box-shadow: 0 8px 22px rgba(0,0,0,0.6); padding: 0.3rem;
    }
    .calpick-menu[hidden] { display: none; }
    .calpick-group {
      color: #777; font-size: 0.65rem; font-weight: 700; text-transform: uppercase;
      letter-spacing: 0.06em; padding: 0.5rem 0.6rem 0.25rem;
    }
    .calpick-opt {
      display: flex; align-items: center; gap: 0.5rem; padding: 0.45rem 0.6rem;
      border-radius: 7px; color: #ddd; text-decoration: none; font-size: 0.92rem;
    }
    .calpick-opt .cpick-name { flex: 1; min-width: 0; }
    .calpick-opt:hover { background: #262626; color: #fff; }
    /* Show/hide checkbox per calendar — a drawn box, exactly like the folder picker's, so
       cancelling the row's link click doesn't fight a real checkbox's toggle. */
    .calpick-opt .cvis {
      flex: 0 0 auto; width: 14px; height: 14px; border: 1px solid #555; border-radius: 3px;
      cursor: pointer; position: relative; box-sizing: border-box;
    }
    .calpick-opt .cvis.on { background: var(--accent); border-color: var(--accent); }
    .calpick-opt .cvis.on::after {
      content: ''; position: absolute; left: 4px; top: 1px; width: 4px; height: 8px;
      border: solid var(--accent-ink); border-width: 0 2px 2px 0; transform: rotate(45deg);
    }
    .calpick-opt .cvis-pad { flex: 0 0 auto; width: 14px; }
    .calpick-opt .cshared-badge {
      flex: 0 0 auto; font-size: 0.68rem; color: #cbb8ff; background: #2a2440;
      border: 1px solid #3d3559; border-radius: 999px; padding: 0.05rem 0.4rem;
    }
    /* "Manage calendars", the last row of the picker menu. */
    .calpick-manage {
      width: 100%; background: none; border: none; border-top: 1px solid #333;
      margin-top: 0.25rem; padding-top: 0.55rem; cursor: pointer; font-family: inherit;
      text-align: left; color: #bbb; font-size: 0.92rem;
    }
    .calpick-manage .cpick-gear { display: inline-flex; width: 16px; justify-content: center; color: #888; }
    .calpick-manage:hover { color: #fff; }

    .monthnav {
      display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.75rem;
    }
    .monthnav a {
      color: #eee; text-decoration: none; border: 1px solid #333; border-radius: 8px;
      padding: 0.4rem 1.1rem; font-size: 1.3rem; line-height: 1; background: #1a1a1a;
      user-select: none;
    }
    .monthnav a:hover { border-color: var(--accent); color: var(--accent); }
    .monthnav a:active { background: #242424; }
    .monthnav .label {
      font-size: 1.05rem; color: #ddd; font-weight: 600; background: none; border: none;
      cursor: pointer; font-family: inherit; padding: 0.2rem 0.3rem; border-radius: 6px;
    }
    .monthnav .label:hover { color: #fff; background: #1e1e1e; }
    /* Today sits just left of the month name. */
    .monthnav .mlabel { display: flex; align-items: center; gap: 0.6rem; min-width: 0; }
    .monthnav .todaybtn {
      flex: 0 0 auto; background: none; border: 1px solid #333; color: #888; border-radius: 999px;
      padding: 0.2rem 0.7rem; font-size: 0.78rem; text-decoration: none; line-height: 1.3;
    }
    .monthnav .todaybtn:hover { border-color: var(--accent); color: var(--accent); background: var(--accent-soft); }
    /* Month/year jump menu, opened from the label above. Same shape as the calendar picker. */
    .ym-menu { padding: 0.6rem; }
    .ym-row { display: flex; gap: 0.5rem; margin-bottom: 0.5rem; }
    .ym-row select {
      flex: 1; min-width: 0; padding: 0.4rem 0.5rem; background: #222; border: 1px solid #3a3a3a;
      border-radius: 6px; color: #eee; font-size: 16px; color-scheme: dark; font-family: inherit;
    }
    .ym-go {
      width: 100%; background: var(--accent); color: var(--accent-ink); border: none;
      border-radius: 6px; padding: 0.45rem; font-size: 0.9rem; font-weight: 700;
      cursor: pointer; font-family: inherit;
    }
    .ym-go:hover { background: #52e0ac; }

    .dow, .grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 6px; }
    .dow { margin-bottom: 6px; }
    .dow span { text-align: center; font-size: 0.7rem; color: #666; padding: 0.25rem 0; }
    .cell {
      min-height: 40px; background: #171717; border: 1px solid #242424; border-radius: 6px;
      padding: 4px 4px 3px; cursor: pointer; position: relative;
      display: flex; flex-direction: column; align-items: center; gap: 3px;
    }
    .cell:not(.blank):hover { border-color: #3a5a4d; background: #1b1f1d; }
    .cell.blank { background: transparent; border-color: transparent; cursor: default; }
    /* The neighbouring months: there, but clearly not this month. */
    .cell.other { background: #131313; border-color: #1c1c1c; }
    .cell.other .num { color: #4a4a4a; }
    .cell .num { font-size: 0.82rem; color: #999; }
    .cell.today { border-color: var(--accent); }
    .cell.today .num { color: var(--accent); font-weight: 700; }
    .cell.selected { border-color: #eee; background: #22262a; }
    .cell .dots { display: flex; gap: 3px; flex-wrap: wrap; justify-content: center; min-height: 6px; }
    .cell .dot { width: 6px; height: 6px; border-radius: 50%; }
    .cell .dot.reminder { background: var(--k-reminder); }
    .cell .dot.reminder.overdue { background: var(--k-overdue); }
    .cell .dot.reminder.done { background: var(--k-done); }
    body:not(.show-done) .cell .dot.reminder.done { display: none; }
    .cell .dot.note { background: var(--k-note); }
    .cell .dot.event { background: var(--k-event); }
    /* Week mode (swipe up): two weeks of grid, and the chrome around it steps aside. */
    .cell.wk-hide { display: none; }

    /* Day panel (bottom) */
    .dp-head { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.6rem; }
    .dp-head .dp-date { font-size: 1.05rem; font-weight: 600; min-width: 0; }
    .dp-head .dp-gap { flex: 1; }          /* pushes Completed/Edit/Add to the right */
    /* Completed, Edit and + Add share a height — set here so they can't drift apart. */
    .dp-head button {
      padding: 0.35rem 0.9rem; font-size: 0.9rem; line-height: 1.2; border-radius: 999px;
      font-family: inherit; white-space: nowrap; cursor: pointer;
    }
    /* Completed sits just left of + Add — an icon, so narrower than the text buttons. */
    .dp-head #calShowAll {
      background: none; border: 1px solid #333; color: #888; border-radius: 999px;
      padding: 0.35rem 0.6rem; font-size: 0.95rem; cursor: pointer; font-family: inherit; white-space: nowrap;
    }
    .dp-head #calShowAll:hover { border-color: #888; color: #ccc; }
    /* Edit is an icon too, so it doesn't need the text buttons' side padding. */
    .dp-head .hedit { padding: 0.35rem 0.6rem; font-size: 0.95rem; }
    body.show-done .dp-head #calShowAll { color: var(--accent); border-color: var(--accent); font-weight: 700; }
    .dp-item .dp-del { display: none; background: none; border: 1px solid #444; color: #999; border-radius: 6px;
      padding: 0.2rem 0.5rem; font-size: 0.9rem; line-height: 1; cursor: pointer; margin-left: 0.3rem; }
    body.editing .dp-item .dp-del { display: inline-block; }
    .dp-item .dp-del:hover { border-color: #f66; color: #f66; }
    .dp-head .dp-add {
      background: var(--accent); color: var(--accent-ink); border: none; border-radius: 999px;
      padding: 0.35rem 0.9rem; font-size: 0.9rem; font-weight: 700; cursor: pointer;
    }
    .dp-head .dp-add:hover { background: #52e0ac; }
    .dp-head .dp-add[disabled] { opacity: 0.4; cursor: default; }
    .dp-list { display: flex; flex-direction: column; gap: 0.4rem; }
    /* Kind groups: a small heading with a chevron that folds the list under it. */
    .dp-group { display: flex; flex-direction: column; gap: 0.4rem; }
    .dp-ghead {
      display: flex; align-items: center; gap: 0.35rem; align-self: flex-start;
      background: none; border: none; padding: 0.2rem 0; cursor: pointer; font-family: inherit;
      color: #777; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em;
    }
    .dp-ghead:hover { color: #aaa; }
    /* The partner's groups sit a shade back, so mine read first at a glance. */
    .dp-group.shared .dp-ghead { color: #6a6a7a; }
    .dp-group.shared .dp-ghead:hover { color: #9a9aae; }
    .dp-gchev { display: inline-block; transform: rotate(90deg); transition: transform 0.12s; font-size: 0.85rem; }
    .dp-group.folded .dp-gchev { transform: rotate(0deg); }
    .dp-glist { display: flex; flex-direction: column; gap: 0.4rem; }
    .dp-group.folded .dp-glist { display: none; }
    .dp-item {
      display: flex; align-items: center; gap: 0.6rem; padding: 0.6rem 0.7rem;
      background: #1b1b1b; border: 1px solid #262626; border-radius: 8px; cursor: default;
    }
    body.editing .dp-item { cursor: pointer; }
    body.editing .dp-item:hover { border-color: #444; }
    .dp-item .tag {
      font-size: 0.6rem; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 700;
      padding: 0.15rem 0.4rem; border-radius: 4px; white-space: nowrap;
    }
    .dp-item .tag.reminder { color: var(--k-reminder); background: var(--k-reminder-soft); }
    .dp-item .tag.event { color: var(--k-event-soft); background: var(--k-event-bg); }
    .dp-item .tag.note { color: var(--k-note-soft); background: var(--k-note-bg); }
    .dp-item .dp-check { width: 17px; height: 17px; accent-color: var(--accent); cursor: pointer; flex: 0 0 auto; }
    .dp-item .cdot { width: 8px; height: 8px; border-radius: 50%; flex: 0 0 auto; }
    .dp-item .txt { flex: 1; font-size: 0.95rem; word-break: break-word; }
    .dp-item .origdate { font-size: 0.72rem; color: #666; white-space: nowrap; }
    .dp-item .evtime { font-size: 0.75rem; color: var(--k-event-soft); font-weight: 600; white-space: nowrap; }
    .dp-item.done .txt { color: #666; text-decoration: line-through; }
    /* The pencil (and the tap-to-open it stands for) only appears in edit mode. */
    .dp-item .chev { color: #555; font-size: 0.9rem; display: none; }
    body.editing .dp-item .chev { display: inline; }
    /* Someone else's item, shown here but owned (and edited) over in their app. Whose
       it is comes from the group heading now, so the row carries no name chip. */
    .dp-item.shared { cursor: default; }
    .dp-empty { color: #666; font-size: 0.9rem; padding: 1rem 0; text-align: center; }
    .dp-none { color: #555; font-size: 0.9rem; padding: 1rem 0; text-align: center; }

    /* Quick-add modal */
    .modal-backdrop {
      position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 60;
      display: none; align-items: center; justify-content: center; padding: 1rem;
    }
    .modal-backdrop.open { display: flex; }
    .modal {
      background: #1a1a1a; border: 1px solid #333; border-radius: 12px;
      width: 100%; max-width: 380px; padding: 1.25rem;
    }
    .modal h2 { font-size: 1.05rem; margin-bottom: 1rem; }
    .modal h2 span { color: var(--accent); }
    .modal input[type=text] {
      width: 100%; padding: 0.6rem 0.75rem; background: #222; border: 1px solid #3a3a3a;
      border-radius: 6px; color: #eee; font-size: 1rem; margin-bottom: 0.85rem;
    }
    .modal input:focus { outline: none; border-color: #888; }
    .modal .kind { display: flex; gap: 0.5rem; margin-bottom: 1rem; }
    .modal .kind label {
      flex: 1; text-align: center; padding: 0.5rem; border: 1px solid #3a3a3a;
      border-radius: 6px; font-size: 0.9rem; color: #aaa; cursor: pointer; user-select: none;
    }
    .modal .kind input { display: none; }
    .modal .kind input:checked + span { color: var(--accent); font-weight: 700; }
    .modal .kind label:has(input:checked) { border-color: var(--accent); background: var(--accent-soft); }
    .modal .daterow { margin-bottom: 1rem; }
    .modal .timerow { margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; }
    .modal .timerow .tlabel { font-size: 0.85rem; color: #aaa; }
    .modal .timerow input[type=time] {
      flex: 1; padding: 0.5rem 0.6rem; background: #222; border: 1px solid #3a3a3a;
      border-radius: 6px; color: #eee; font-size: 0.95rem; color-scheme: dark;
    }
    .modal .adddate {
      background: none; border: 1px dashed #3a5a4d; color: var(--accent); border-radius: 6px;
      padding: 0.45rem 0.8rem; font-size: 0.9rem; cursor: pointer;
    }
    .modal .adddate:hover { background: var(--accent-soft); }
    .modal .datewrap { display: flex; align-items: center; gap: 0.5rem; }
    .modal .datewrap input[type=date] {
      flex: 1; padding: 0.5rem 0.6rem; background: #222; border: 1px solid #3a3a3a;
      border-radius: 6px; color: #eee; font-size: 0.95rem; color-scheme: dark;
    }
    /* One X for both rows: clearing the time looks like clearing the date. */
    .modal .cleardate {
      background: none; border: 1px solid #3a3a3a; color: #999; border-radius: 6px;
      padding: 0.45rem 0.6rem; font-size: 0.9rem; cursor: pointer; line-height: 1;
    }
    .modal .cleardate:hover { border-color: #f66; color: #f66; }
    .modal .buttons { display: flex; gap: 0.5rem; justify-content: flex-end; align-items: center; }
    .modal .buttons .del {
      margin-right: auto; background: none; border: none; color: #666;
      font-size: 0.85rem; cursor: pointer;
    }
    .modal .buttons .del:hover { color: #f66; }
    .modal .buttons button {
      padding: 0.55rem 1.1rem; border: none; border-radius: 6px; font-size: 0.95rem;
      font-weight: 600; cursor: pointer;
    }
    .modal .buttons .cancel { background: #2a2a2a; color: #ccc; }
    .modal .buttons .ok { background: var(--accent); color: var(--accent-ink); }
    /* Share sits on the left of the manager's button row. */
    .modal .buttons .share { margin-right: auto; background: #2a2a2a; color: #ccc; }
    .modal .buttons .share:hover { background: #333; color: #fff; }
    .modal .calrow { margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; }
    /* [hidden] has to win over the flex above, or every row shows on every kind. */
    .modal .calrow[hidden], .modal .timerow[hidden], .modal .repevery[hidden] { display: none; }
    .modal .calrow select {
      flex: 1; padding: 0.5rem 0.6rem; background: #222; border: 1px solid #3a3a3a;
      border-radius: 6px; color: #eee; font-size: 16px; font-family: inherit;
    }
    /* A fixed-width label so every row's control starts at the same x — Time, Repeat,
       Calendar and "Goes in" line up down the window. */
    .modal .calrow .tlabel { font-size: 0.85rem; color: #aaa; flex: 0 0 auto; width: 4.75rem; }
    .modal .calrow .secnote { font-size: 0.78rem; color: #777; white-space: nowrap; }
    /* The repeat unit keeps its natural width so "every [N]" sits right beside it and the
       number field lines up with the row rather than being pushed to the far edge. */
    .modal .reprow select { flex: 0 0 auto; }
    .modal .reprow .repevery { display: inline-flex; align-items: center; gap: 0.4rem; font-size: 0.85rem; color: #aaa; }
    .modal .reprow .repevery input {
      width: 48px; text-align: center; padding: 0.5rem 0.5rem; background: #222; border: 1px solid #444;
      border-radius: 6px; color: #eee; font-size: 16px; font-family: inherit;
    }
    .modal .reprow .repevery input:focus { outline: none; border-color: #888; }
    /* A repeating row says so next to its time. */
    .dp-item .rep { font-size: 0.7rem; color: #777; white-space: nowrap; }

    /* --- Manage-calendars modal --- */
    .calmodal { max-height: 85vh; overflow-y: auto; }
    .calmodal h2 { margin-bottom: 0.7rem; }
    .calmodal .cdiv { border: none; border-top: 1px solid #333; margin: 1.2rem 0 1rem; }
    /* Collapsible manager sections: tap the heading to fold Calendars / Sets / Folders. */
    .calmodal .cm-section + .cm-section { border-top: 1px solid #333; margin-top: 1.1rem; padding-top: 0.9rem; }
    .calmodal .cm-head { display: flex; align-items: center; gap: 0.5rem; cursor: pointer; user-select: none; margin-bottom: 0.7rem; }
    .calmodal .cm-chev { color: #888; font-size: 0.7rem; margin-left: auto; transition: transform 0.15s ease; }
    .calmodal .cm-section.collapsed .cm-chev { transform: rotate(-90deg); }
    .calmodal .cm-section.collapsed .cm-body { display: none; }
    .calmodal .cm-section.collapsed .cm-head { margin-bottom: 0; }
    .addrow { display: flex; gap: 0.5rem; margin-bottom: 0.8rem; }
    .addrow input[type=text] { flex: 1; margin-bottom: 0; font-size: 16px; }
    .addrow .plus {
      flex: 0 0 auto; width: 40px; background: var(--accent); color: var(--accent-ink); border: none;
      border-radius: 6px; font-size: 1.2rem; font-weight: 700; cursor: pointer; font-family: inherit;
    }
    .addrow .plus:hover { background: #52e0ac; }
    .callist { list-style: none; display: flex; flex-direction: column; gap: 0.4rem; }
    .callist li {
      display: flex; align-items: center; gap: 0.6rem; padding: 0.5rem 0.6rem;
      background: #222; border: 1px solid #333; border-radius: 8px;
    }
    .callist li.dragging { opacity: 0.6; border-color: var(--accent); }
    .callist .chandle { color: #666; cursor: grab; touch-action: none; user-select: none; font-size: 1rem; }
    .callist .cname { flex: 1; font-size: 0.95rem; word-break: break-word; }
    .callist .cswatch {
      flex: 0 0 auto; width: 24px; height: 24px; border-radius: 6px; border: 1px solid #444;
      cursor: pointer; padding: 0;
    }
    /* A set's swatch is a pie of its members' colours, so it wants to be a circle. */
    /* Read-only colour dot for a partner's shared calendar (no swatch button). */
    .callist .cdot-ro { flex: 0 0 auto; width: 16px; height: 16px; border-radius: 50%; border: 1px solid #444; }
    /* Badge marking a shared calendar as the partner's, in place of an owner heading. */
    .callist .cshared-badge {
      flex: 0 0 auto; font-size: 0.68rem; color: #cbb8ff; background: #2a2440;
      border: 1px solid #3d3559; border-radius: 999px; padding: 0.05rem 0.4rem;
    }
    .callist .cdel {
      flex: 0 0 auto; background: none; border: 1px solid #444; color: #999; border-radius: 6px;
      padding: 0.15rem 0.45rem; font-size: 0.9rem; line-height: 1; cursor: pointer; font-family: inherit;
    }
    .callist .cdel:hover { border-color: #f66; color: #f66; }
    .callist .cmember { width: 20px; height: 20px; accent-color: var(--accent); cursor: pointer; flex: 0 0 auto; }
    /* Per-folder All/Dated/None dropdown: an icon-only button that drops a worded menu. */
    .rfmode { position: relative; flex: 0 0 auto; }
    .rfmode-btn {
      width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center;
      background: #1a1a1a; border: 1px solid #444; border-radius: 999px; color: var(--accent);
      font-size: 0.95rem; line-height: 1; cursor: pointer; font-family: inherit; padding: 0;
    }
    .rfmode-btn.off { color: #777; }
    .rfmode-btn:hover { border-color: #888; }
    .rfmode-menu {
      position: absolute; right: 0; top: 36px; z-index: 20; background: #1c1c1c;
      border: 1px solid #333; border-radius: 8px; padding: 0.25rem; min-width: 118px;
      box-shadow: 0 8px 20px rgba(0,0,0,0.5);
    }
    .rfmode-menu[hidden] { display: none; }
    .rfmode-opt {
      display: flex; align-items: center; gap: 0.5rem; width: 100%; text-align: left;
      background: none; border: none; color: #ddd; padding: 0.45rem 0.5rem; border-radius: 6px;
      font-size: 0.9rem; font-family: inherit; cursor: pointer;
    }
    .rfmode-opt:hover { background: #262626; color: #fff; }
    .rfmode-opt .rfmode-ic { width: 16px; text-align: center; color: var(--accent); }
    .calempty { color: #666; font-size: 0.85rem; padding: 0.4rem 0.1rem; }
    /* Default-calendar picker, under the calendar list. */
    .defrow { display: flex; align-items: center; gap: 0.6rem; margin-top: 0.8rem; }
    .defrow label { font-size: 0.85rem; color: #999; white-space: nowrap; }
    .defrow select {
      flex: 1; min-width: 0; padding: 0.4rem 0.6rem; background: #222; border: 1px solid #3a3a3a;
      border-radius: 6px; color: #eee; font-size: 16px; font-family: inherit; cursor: pointer;
    }
    .defrow select:focus { outline: none; border-color: #888; }
    .calmodal .chint { color: #777; font-size: 0.78rem; margin: -0.4rem 0 0.7rem; }
    /* Colour palette popover */
    .swatches {
      position: fixed; z-index: 80; background: #1c1c1c; border: 1px solid #444; border-radius: 10px;
      padding: 0.5rem; display: grid; grid-template-columns: repeat(5, 26px); gap: 0.4rem;
      box-shadow: 0 8px 20px rgba(0,0,0,0.6);
    }
    .swatches[hidden] { display: none; }
    .swatches button { width: 26px; height: 26px; border-radius: 6px; border: 1px solid #444; cursor: pointer; padding: 0; }
<?= tabbar_styles() ?>
<?= kind_color_css() ?>
<?= share_modal_styles() ?>
<?= chrome_styles() ?>
    body { padding-bottom: 0; }   /* panel handles the tab-bar clearance */
  </style>
</head>
<body>
<div class="cal-top">
 <div class="wrap">
  <?php
    // Custom picker rather than a <select>: a native option can't carry a colour dot.
    // Only calendars belong in this picker — reminder folders are controlled by the
    // All/Dated/None dropdowns in "Manage calendars", not here.
    $pickGroups = [[share_name($me), array_map(fn($c) => [$c['id'], $c['name'], $c['color'] ?? CAL_COLORS[0]], $calsOnly)]];
    if ($sharedCals) {
      $pickGroups[] = [share_name($partner),
        array_map(fn($c) => [$c['id'], $c['name'], $c['color'] ?? CAL_COLORS[0]], $sharedCals)];
    }
    // What the closed button shows.
    $curName = 'All calendars'; $curColor = '';
    foreach ($pickGroups as [$gl, $opts]) {
      foreach ($opts as [$v, $n, $col]) { if ($calView === $v) { $curName = $n; $curColor = $col; } }
    }
  ?>
  <header>
    <div class="hleft">
      <?= back_button() ?>
      <div class="titlebar">
        <h1>Calendar</h1>
      </div>
    </div>
    <?php
      // The calendar picker rides on the right by the ⋮; "Manage calendars" is the last
      // row of its dropdown rather than a button of its own.
      ob_start();
    ?>
      <div class="calpick">
        <?php // Just the selected calendar's colour, round: the name is in the menu it opens. ?>
        <button type="button" class="calpick-btn" id="calSelBtn" aria-haspopup="listbox" aria-expanded="false"
                title="<?= e($curName) ?>" aria-label="<?= e($curName) ?>">
          <span class="cdot<?= $curColor === '' ? ' all' : '' ?>"<?= $curColor === '' ? '' : ' style="background:' . e($curColor) . '"' ?>></span>
        </button>
        <?php // One flat list with a show/hide checkbox on every calendar — the same shape
              // and behaviour as the reminders folder picker. "All calendars" carries a
              // master checkbox that shows or hides them all; a shared calendar wears a
              // badge instead of an owner heading. ?>
        <div class="calpick-menu" id="calSelMenu" role="listbox" hidden>
          <a class="calpick-opt" href="?cal=all">
            <span class="cvis cvis-all<?= empty($hiddenCals) ? ' on' : '' ?>" role="checkbox"
                  tabindex="0" aria-checked="<?= empty($hiddenCals) ? 'true' : 'false' ?>"
                  title="Show every calendar" aria-label="Show every calendar"></span>
            <span class="cdot all"></span><span>All calendars</span>
          </a>
          <?php foreach ($pickGroups as [$glabel, $opts]): ?>
            <?php foreach ($opts as [$val, $name, $col]): ?>
              <?php $isCal = in_array($val, $pickIds, true); $isShared = in_array($val, $sharedIds, true); ?>
              <a class="calpick-opt" href="?cal=<?= urlencode($val) ?>">
                <?php if ($isCal): ?>
                  <span class="cvis<?= in_array($val, $hiddenCals, true) ? '' : ' on' ?>" role="checkbox"
                        tabindex="0" aria-checked="<?= in_array($val, $hiddenCals, true) ? 'false' : 'true' ?>"
                        data-cal="<?= e($val) ?>" title="Show on the calendar" aria-label="Show <?= e($name) ?>"></span>
                <?php else: ?><span class="cvis-pad" aria-hidden="true"></span><?php endif; ?>
                <span class="cdot" style="background:<?= e($col) ?>"></span><span class="cpick-name"><?= e($name) ?></span>
                <?php if ($isShared): ?><span class="cshared-badge"><?= e(share_name($partner)) ?></span><?php endif; ?>
              </a>
            <?php endforeach; ?>
          <?php endforeach; ?>
          <button type="button" class="calpick-opt calpick-manage" id="calMgr">
            <span class="cpick-gear" aria-hidden="true"><?= folder_icon_svg() ?></span><span>Manage calendars</span>
          </button>
        </div>
      </div>
    <?php $titleControls = ob_get_clean(); ?>
    <?php // Widget now sits in the shared settings footer, so no app-specific extra here. ?>
    <?= render_user_menu(false, 'editBtn', '', (bool) $partner, $titleControls) ?>
  </header>


  <div class="monthnav">
    <a href="?ym=<?= $prev ?>" title="Previous month">&#8592;</a>
    <div class="mlabel">
      <a class="todaybtn" href="?ym=<?= date('Y-m') ?>&amp;day=<?= $todayYmd ?>" title="Jump to today">Today</a>
      <button type="button" class="label" id="ymBtn" aria-haspopup="true" aria-expanded="false"
              title="Jump to month"><?= e($monthName) ?></button>
    </div>
    <a href="?ym=<?= $next ?>" title="Next month">&#8594;</a>
  </div>

  <?php // Tapping the month/year label opens a quick jump-to menu. ?>
  <div class="calpick-menu ym-menu" id="ymMenu" hidden>
    <div class="ym-row">
      <select id="ymMonthSel" aria-label="Month">
        <?php foreach (['January','February','March','April','May','June','July','August',
                        'September','October','November','December'] as $i => $mn): ?>
          <option value="<?= $i + 1 ?>"<?= ($i + 1) === $month ? ' selected' : '' ?>><?= $mn ?></option>
        <?php endforeach; ?>
      </select>
      <select id="ymYearSel" aria-label="Year">
        <?php for ($y = $year - 8; $y <= $year + 8; $y++): ?>
          <option value="<?= $y ?>"<?= $y === $year ? ' selected' : '' ?>><?= $y ?></option>
        <?php endfor; ?>
      </select>
    </div>
    <button type="button" class="ym-go" id="ymGo">Go</button>
  </div>

  <div class="dow">
    <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $d): ?>
      <span><?= $d ?></span>
    <?php endforeach; ?>
  </div>

  <?php
    // Every cell carries the row it sits on, so week mode can hide all but two of
    // them without the grid needing a different shape.
    $cellNo = 0;
    $weekOf = function () use (&$cellNo) { return intdiv($cellNo++, 7); };
  ?>
  <?php
    // One month cell: the number, then at most two event dots in their calendars'
    // colours, one for the day's reminders and one for its notes. $other greys the
    // days either side of the month — they're still tappable.
    $cell = function (string $ymd, int $num, bool $other, int $week) use ($byDay, $todayYmd): string {
        $events  = $byDay[$ymd] ?? [];
        $remDots = array_values(array_filter($events, fn($ev) => $ev['kind'] === 'reminder'));
        $hasNote = (bool) array_filter($events, fn($ev) => $ev['kind'] === 'note');
        // The single reminder dot takes the worst state of the day's reminders:
        // overdue beats open, and it only goes grey once every one of them is ticked.
        $remCls = '';
        if ($remDots) {
            $open    = array_filter($remDots, fn($ev) => !$ev['done']);
            $overdue = array_filter($open, fn($ev) => $ymd < $todayYmd || !empty($ev['rolled']));
            $remCls  = !$open ? ' done' : ($overdue ? ' overdue' : '');
        }
        // One dot per distinct calendar colour that day, not one per event — several
        // events on the same calendar read as a single dot in that colour.
        $dots = '';
        $evColors = [];
        foreach ($events as $ev) {
            if ($ev['kind'] !== 'event') { continue; }
            $col = (string) ($ev['color'] ?? '');
            if (!in_array($col, $evColors, true)) { $evColors[] = $col; }
        }
        foreach ($evColors as $col) {
            $dots .= '<span class="dot event" style="background:' . e($col) . '"></span>';
        }
        if ($remDots) {
            // Colour the single reminder dot by the folder of the "worst" reminder that
            // day (overdue, else open, else done); state still rides in the class.
            $repRem = null;
            foreach ($remDots as $rd) { if (!$rd['done'] && ($ymd < $todayYmd || !empty($rd['rolled']))) { $repRem = $rd; break; } }
            if (!$repRem) { foreach ($remDots as $rd) { if (!$rd['done']) { $repRem = $rd; break; } } }
            if (!$repRem) { $repRem = $remDots[0]; }
            $rc = (string) ($repRem['color'] ?? '');
            $dots .= '<span class="dot reminder' . $remCls . '"'
                   . ($rc !== '' ? ' style="background:' . e($rc) . '"' : '') . '></span>';
        }
        if ($hasNote) {
            $noteColor = '';
            foreach ($events as $ev) { if ($ev['kind'] === 'note') { $noteColor = (string) ($ev['color'] ?? ''); break; } }
            $dots .= '<span class="dot note"'
                   . ($noteColor !== '' ? ' style="background:' . e($noteColor) . '"' : '') . '></span>';
        }
        $cls = 'cell' . ($other ? ' other' : '') . ($ymd === $todayYmd ? ' today' : '');
        return '<div class="' . $cls . '" data-date="' . $ymd . '" data-week="' . $week . '"'
             . ' role="button" tabindex="0"><div class="num">' . $num . '</div>'
             . '<div class="dots">' . $dots . '</div></div>';
    };
  ?>
  <div class="grid" id="calGrid">
    <?php // The week either side is greyed rather than left empty, so the month reads
          // as part of a continuous calendar — and it's tappable like any other day,
          // since its items are loaded with the rest. Tapping one doesn't turn the page. ?>
    <?php for ($i = $leadBlank; $i > 0; $i--): ?>
      <?php $ymd = date('Y-m-d', mktime(0, 0, 0, $month, 1 - $i, $year)); ?>
      <?= $cell($ymd, $prevDays - $i + 1, true, $weekOf()) ?>
    <?php endfor; ?>

    <?php for ($day = 1; $day <= $daysInMo; $day++): ?>
      <?= $cell(sprintf('%04d-%02d-%02d', $year, $month, $day), $day, false, $weekOf()) ?>
    <?php endfor; ?>

    <?php for ($day = 1; $day <= $tailBlank; $day++): ?>
      <?php $ymd = date('Y-m-d', mktime(0, 0, 0, $month, $daysInMo + $day, $year)); ?>
      <?= $cell($ymd, $day, true, $weekOf()) ?>
    <?php endfor; ?>
  </div>
 </div>
</div>

<div class="daypanel">
 <div class="wrap">
  <div class="dp-head">
    <span class="dp-date" id="dpDate">Select a day</span>
    <span class="dp-gap"></span>
    <button type="button" id="calShowAll" title="Completed" aria-label="Completed">&#9745;&#65038;</button>
    <button class="dp-add" id="dpAdd" disabled>+ Add</button>
  </div>
  <div class="dp-list" id="dpList">
    <p class="dp-none">Tap a day above to see its items.</p>
  </div>
 </div>
</div>

<div class="modal-backdrop" id="itemModal">
  <form class="modal" method="post" action="" id="mForm">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="action" id="mAction" value="add_reminder">
    <input type="hidden" name="id" id="mId" value="">
    <input type="hidden" name="kind" id="mKind" value="reminder">
    <input type="hidden" name="ym" value="<?= e($ym) ?>">
    <input type="hidden" name="day" id="mDay" value="">
    <h2 id="mHeading">Add</h2>
    <input type="text" name="text" id="mText" placeholder="What is it?" maxlength="500" required>
    <div class="kind" id="mKindRow">
      <label><input type="radio" name="kindchoice" value="event" checked><span>&#128197; Event</span></label>
      <label><input type="radio" name="kindchoice" value="reminder"><span>&#9745; Reminder</span></label>
      <label><input type="radio" name="kindchoice" value="note"><span>&#128221; Note</span></label>
    </div>
    <div class="daterow">
      <button type="button" class="adddate" id="mAddDate">+ Add date</button>
      <div class="datewrap" id="mDateWrap" hidden>
        <input type="date" name="date" id="mDate" value="">
        <button type="button" class="cleardate" id="mClearDate" title="Remove date">&times;</button>
      </div>
    </div>
    <div class="timerow" id="mTimeRow" hidden>
      <span class="tlabel">Time</span>
      <input type="time" name="time" id="mTime" value="">
      <button type="button" class="cleardate" id="mClearTime" title="Remove time">&times;</button>
    </div>
    <?php // "Repeat every 2 weeks". Never is the default; picking a unit reveals the
          // count, which starts at 1 — so choosing "week(s)" alone means every week. ?>
    <div class="calrow reprow" id="mRepRow" hidden>
      <span class="tlabel">Repeat</span>
      <select name="rep_unit" id="mRepUnit">
        <option value="">Never</option>
        <option value="day">day(s)</option>
        <option value="week">week(s)</option>
        <option value="month">month(s)</option>
        <option value="year">year(s)</option>
      </select>
      <span class="repevery" id="mRepEvery" hidden>every
        <?php // Plain text, not a number spinner: the little arrows were only ever in the
              // way, and repeat_clean() already turns anything unparseable into 1. ?>
        <input type="text" name="rep_n" id="mRepN" value="1" maxlength="3"
               inputmode="numeric" autocomplete="off">
      </span>
    </div>
    <?php // Mine and the partner's stay in separate pickers rather than one merged list,
          // so it's always obvious whose file a new item is about to land in. Picking from
          // one clears the other (see the JS below); an empty "theirs" means "mine". ?>
    <?php // Which of my calendars the event goes in — new items are always mine. ?>
    <div class="calrow" id="mCalRow" hidden>
      <span class="tlabel">Calendar</span>
      <select name="cal" id="mCal">
        <?php foreach ($calsOnly as $c): ?>
          <option value="<?= e($c['id']) ?>"><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php
      // A reminder answers to a folder and a group, never a calendar. Since "Calendar"
      // and "Reminders" became folders, one dropdown of the default folder's sections
      // wasn't enough — an undated ride-along has to be filed in the Calendar *folder*.
      // So mine is the same shape as the partner's: every folder, each with its groups,
      // the value carrying "folder\x1Fgroup" (\x1F can't survive folder_clean()).
      $remDefFolder = folder_default_get($cfg['data_dir'], 'reminders');
      $myRemGroups  = [];
      $myRem        = load_reminder_list(user_data_file($cfg['data_dir'], 'reminders'));
      foreach ($remFolders as $mf) {
          $myRemGroups[$mf] = [[$mf . "\x1F", 'Reminders']];   // the folder's catch-all
          foreach ($myRem as $it) {
              if (($it['type'] ?? '') !== 'section' || ($it['folder'] ?? '') !== $mf) { continue; }
              $nm = (string) ($it['name'] ?? '');
              if ($nm !== '') { $myRemGroups[$mf][] = [$mf . "\x1F" . $nm, $nm]; }
          }
      }
    ?>
    <?php
      // The partner's shared reminder folders, each with its own groups. Value carries
      // both, since a shared reminder needs a folder as well as a group; \x1F can't
      // occur in a folder_clean()ed name, and both halves are re-validated server-side.
      $sharedRemGroups = [];
      if ($sharedFolders) {
          $theirRem = load_reminder_list(user_data_file($cfg['data_dir'], 'reminders', $partner));
          foreach ($sharedFolders as $sf) {
              $sharedRemGroups[$sf] = [[$sf . "\x1F", 'Reminders']];   // the folder's catch-all
              foreach ($theirRem as $it) {
                  if (($it['type'] ?? '') !== 'section') { continue; }
                  if (($it['folder'] ?? '') !== $sf) { continue; }
                  $nm = (string) ($it['name'] ?? '');
                  if ($nm !== '') { $sharedRemGroups[$sf][] = [$sf . "\x1F" . $nm, $nm]; }
              }
          }
      }
    ?>
    <?php // Which of my folders/groups the reminder goes in — new items are always mine. ?>
    <div class="calrow" id="mSecRow" hidden>
      <span class="tlabel">Goes in</span>
      <select name="section" id="mSec">
        <?php foreach ($myRemGroups as $mf => $opts): ?>
          <optgroup label="<?= e($mf) ?>">
            <?php foreach ($opts as [$val, $label]): ?>
              <option value="<?= e($val) ?>"<?= $mf === $remDefFolder && substr($val, -1) === "\x1F" ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </optgroup>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="buttons">
      <button type="button" class="del needs-confirm" id="mDelete" hidden>Delete</button>
      <button type="button" class="cancel" id="mCancel">Cancel</button>
      <button type="submit" class="ok" id="mOk">Add</button>
    </div>
  </form>
</div>

<!-- Manage calendars (opened from "Manage calendars" in the picker dropdown) -->
<div class="modal-backdrop" id="calModal">
  <div class="modal calmodal">
    <div class="cm-section" data-cm="cals">
      <h2 class="cm-head">Calendars<span class="cm-chev" aria-hidden="true">&#9662;</span></h2>
      <div class="cm-body">
        <div class="addrow">
          <input type="text" id="calName" placeholder="New calendar" maxlength="40" autocomplete="off">
          <button type="button" class="plus" id="calAdd" title="Add calendar">+</button>
        </div>
        <ul class="callist" id="calRows"></ul>
        <div class="defrow">
          <label for="calDefault">New events go to</label>
          <select id="calDefault"></select>
        </div>
      </div>
    </div>
    <div class="cm-section" data-cm="folders">
      <h2 class="cm-head">Reminder folders<span class="cm-chev" aria-hidden="true">&#9662;</span></h2>
      <div class="cm-body">
        <p class="chint">Which folders' reminders show up on the calendar.</p>
        <ul class="callist" id="folderRows"></ul>
      </div>
    </div>
    <div class="buttons" style="margin-top:1.1rem">
      <button type="button" class="ok" id="calDone">Done</button>
    </div>
  </div>
</div>

<?php if ($partner) { echo share_modal_html($partner); } ?>

<div class="swatches" id="swatchPop" hidden></div>

<!-- Hidden form to check reminders off directly from the day panel -->
<form id="toggleForm" method="post" action="" style="display:none">
  <input type="hidden" name="csrf" value="<?= $csrf ?>">
  <input type="hidden" name="action" value="toggle_reminder">
  <input type="hidden" name="id" id="tgId" value="">
  <input type="hidden" name="owner" id="tgOwner" value="">
  <input type="hidden" name="ym" value="<?= e($ym) ?>">
  <input type="hidden" name="day" id="tgDay" value="">
</form>

<!-- Hidden form to quick-delete an item from the day panel (Edit mode) -->
<form id="delItemForm" method="post" action="" style="display:none">
  <input type="hidden" name="csrf" value="<?= $csrf ?>">
  <input type="hidden" name="action" value="delete_item">
  <input type="hidden" name="confirm" value="1">   <!-- only reachable via the armed second press -->
  <input type="hidden" name="kind" id="diKind" value="">
  <input type="hidden" name="id" id="diId" value="">
  <input type="hidden" name="ym" value="<?= e($ym) ?>">
  <input type="hidden" name="day" id="diDay" value="">
</form>

<?php render_tabbar('calendar'); ?>
<script>
  if (localStorage.getItem('calShowDone') === '1') document.body.classList.add('show-done');
  const modal    = document.getElementById('itemModal');
  const form     = document.getElementById('mForm');
  const mAction  = document.getElementById('mAction');
  const mId      = document.getElementById('mId');
  const mKind    = document.getElementById('mKind');
  const mHeading = document.getElementById('mHeading');
  const mText    = document.getElementById('mText');
  const mKindRow = document.getElementById('mKindRow');
  const mAddDate = document.getElementById('mAddDate');
  const mDateWrap= document.getElementById('mDateWrap');
  const mDate    = document.getElementById('mDate');
  const mDelete  = document.getElementById('mDelete');
  const mOk      = document.getElementById('mOk');

  // Show/hide the optional date field.
  const showDate = (val) => {
    mDate.value = val || '';
    mDateWrap.hidden = false;
    mAddDate.hidden = true;
  };
  const hideDate = () => {           // "no date"
    mDate.value = '';
    mDateWrap.hidden = true;
    mAddDate.hidden = false;
  };
  const TODAY = '<?= date('Y-m-d') ?>';
  mAddDate.addEventListener('click', () => { showDate(TODAY); mDate.focus(); if (mDate.showPicker) try { mDate.showPicker(); } catch(_){} });
  document.getElementById('mClearDate').addEventListener('click', hideDate);

  // Time — events only.
  const mTimeRow = document.getElementById('mTimeRow');
  const mTime    = document.getElementById('mTime');
  const mCalRow  = document.getElementById('mCalRow');
  const mCal     = document.getElementById('mCal');
  const mSecRow  = document.getElementById('mSecRow');   // reminders file under a group, not a calendar
  // Time and calendar are event-only fields, so they show and hide together. A
  // reminder gets the group picker in their place — it belongs to a folder, not a calendar.
  const mRepRow  = document.getElementById('mRepRow');
  const mRepUnit = document.getElementById('mRepUnit');
  const mRepN    = document.getElementById('mRepN');
  const mRepEvery = document.getElementById('mRepEvery');
  // Notes don't repeat — they're a page, not something that happens again.
  const showRep = (kind, rep) => {
    mRepRow.hidden = (kind === 'note');
    mRepUnit.value = (rep && rep.unit) || '';
    mRepN.value    = (rep && rep.n) || 1;
    mRepEvery.hidden = mRepUnit.value === '';
  };
  mRepUnit.addEventListener('change', () => {
    mRepEvery.hidden = mRepUnit.value === '';
    // Anything that isn't a number (or is empty) means "every 1", same as the server.
    if (!mRepEvery.hidden && !(parseInt(mRepN.value, 10) > 0)) { mRepN.value = 1; }
  });
  // Time applies to every kind now; only the calendar (events) and group (reminders)
  // rows are kind-specific.
  // New items are always mine, so the window offers only my calendar / my folder — there
  // are no partner pickers to keep in sync any more.
  const mSec = document.getElementById('mSec');
  const showTime = (val) => {
    mTime.value = val || ''; mTimeRow.hidden = false;
    mCalRow.hidden = false; mSecRow.hidden = true;
  };
  const hideTime = (kind) => {
    mTimeRow.hidden = false; mCalRow.hidden = true; mSecRow.hidden = kind !== 'reminder';
  };
  document.getElementById('mClearTime').addEventListener('click', () => { mTime.value = ''; });
  document.querySelectorAll('input[name=kindchoice]').forEach(r => {
    r.addEventListener('change', () => {
      if (!r.checked) { return; }
      r.value === 'event' ? showTime(mTime.value) : hideTime(r.value);
      showRep(r.value, { unit: mRepUnit.value, n: mRepN.value });
    });
  });
  const fmtTime = (t) => {
    const [h, m] = t.split(':').map(Number);
    return ((h % 12) || 12) + ':' + String(m).padStart(2, '0') + ' ' + (h < 12 ? 'AM' : 'PM');
  };

  const closeModal = () => modal.classList.remove('open');

  // ADD mode — create a new reminder/note (date pre-filled to the selected day).
  const openAdd = date => {
    mHeading.textContent = 'New item';
    mAction.value = 'add_event';           // finalized on submit from the kind choice
    mId.value = '';
    mDay.value = date || '';
    mKindRow.hidden = false;
    mDelete.hidden = true;
    mOk.textContent = 'Add';
    mText.value = '';
    document.querySelector('input[name=kindchoice][value=event]').checked = true;
    mCal.value = newEventCal();            // the calendar you're looking at, else the default
    showTime('');
    showRep('event', null);
    if (date) showDate(date); else hideDate();
    modal.classList.add('open');
    setTimeout(() => mText.focus(), 30);
  };

  // EDIT mode — from tapping an item in the day panel.
  const openEdit = (id, kind, text, date, time, cal, rep) => {
    mHeading.textContent = 'Edit ' + kind;
    mAction.value = 'edit_item';
    mId.value = id;
    mKind.value = kind;
    mDay.value = date || '';
    mKindRow.hidden = true;                // kind is fixed when editing
    mDelete.hidden = false;
    mOk.textContent = 'Save';
    mText.value = text;
    if (kind === 'event') { mCal.value = cal || newEventCal(); showTime(time); }
    else { mTime.value = time || ''; hideTime(kind); }
    showRep(kind, rep);
    if (date) showDate(date); else hideDate();
    modal.classList.add('open');
    setTimeout(() => mText.focus(), 30);
  };

  // Finalize the action for ADD (reminder vs note) right before submit.
  form.addEventListener('submit', () => {
    if (mAction.value !== 'edit_item') {
      const kc = document.querySelector('input[name=kindchoice]:checked').value;
      mAction.value = 'add_' + kc;   // add_event | add_reminder | add_note
    }
  });

  // Hidden form used to check a reminder off straight from the day panel.
  const toggleForm = document.getElementById('toggleForm');

  // Delete submits with its own action.
  mDelete.addEventListener('click', () => {
    mAction.value = 'delete_item';
    mText.removeAttribute('required');     // don't block submit on empty text
    form.submit();
  });

  // ---- Split view: tap a day (top) -> list its items (bottom) ----
  const ITEMS   = <?= $itemsJson ?: '{}' ?>;
  const dpDate  = document.getElementById('dpDate');
  const dpAdd   = document.getElementById('dpAdd');
  const dpList  = document.getElementById('dpList');
  const mDay    = document.getElementById('mDay');
  let selected  = null;

  const prettyDate = ymd => {
    const d = new Date(ymd + 'T00:00:00');
    return d.toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric' });
  };

  const renderPanel = date => {
    dpDate.textContent = prettyDate(date);
    dpAdd.disabled = false;
    const items = (ITEMS[date] || []);      // already ordered: reminders first, then notes
    dpList.innerHTML = '';
    if (!items.length) {
      const p = document.createElement('p');
      p.className = 'dp-empty';
      p.textContent = 'No items on this day. Tap + Add to create one.';
      dpList.appendChild(p);
      return;
    }
    // One collapsible group per kind *and per owner*, so my events and the partner's
    // never share a heading — whose day you're looking at is never a guess. Built up
    // front in a fixed order (mine before theirs, within the legend's kind order) so the
    // panel doesn't reshuffle depending on what the day happens to hold. Whether a group
    // is folded is a display preference, remembered per group.
    const showDone = document.body.classList.contains('show-done');
    const groups   = {};
    const gkey  = it => it.kind + (it.owner ? ':' + it.owner : '');
    const KNAME = { reminder: 'Reminders', event: 'Events', note: 'Notes' };
    // Every owner present on this day, mine first, then whoever else's items are here.
    const owners = [''];
    for (const it of items) {
      if (it.owner && !owners.includes(it.owner)) owners.push(it.owner);
    }
    for (const kind of ['event', 'reminder', 'note']) {   // legend order: events, reminders, notes
      for (const owner of owners) {
        const key = kind + (owner ? ':' + owner : '');
        if (!items.some(it => gkey(it) === key && (showDone || !it.done))) continue;
        const wrap = document.createElement('div');
        wrap.className = 'dp-group' + (owner ? ' shared' : '');
        const head = document.createElement('button');
        head.type = 'button';
        head.className = 'dp-ghead';
        const chev = document.createElement('span');
        chev.className = 'dp-gchev';
        chev.textContent = '›';                // ›, rotated down by CSS when open
        const label = document.createElement('span');
        // "Events" for mine, "Aki's events" for theirs — the CSS upper-cases it.
        label.textContent = owner
          ? owner.charAt(0).toUpperCase() + owner.slice(1) + '’s ' + KNAME[kind].toLowerCase()
          : KNAME[kind];
        head.append(chev, label);
        const body = document.createElement('div');
        body.className = 'dp-glist';
        if (localStorage.getItem('calFold_' + key) === '1') wrap.classList.add('folded');
        head.addEventListener('click', () => {
          const folded = wrap.classList.toggle('folded');
          localStorage.setItem('calFold_' + key, folded ? '1' : '0');
        });
        wrap.append(head, body);
        dpList.appendChild(wrap);
        groups[key] = body;
      }
    }

    for (const it of items) {
      if (it.done && !document.body.classList.contains('show-done')) continue;   // hidden unless "Completed"
      const overdue = it.kind === 'reminder' && !it.done && (date < TODAY || it.rolled);
      const row = document.createElement('div');
      row.className = 'dp-item' + (it.done ? ' done' : '');
      // Reminders can be checked off right here.
      if (it.kind === 'reminder') {
        const cb = document.createElement('input');
        cb.type = 'checkbox'; cb.className = 'dp-check'; cb.checked = !!it.done;
        cb.title = it.done ? 'Mark not done' : 'Mark done';
        cb.addEventListener('click', e => e.stopPropagation());   // don't open the editor
        cb.addEventListener('change', () => {
          document.getElementById('tgId').value = it.id;
          document.getElementById('tgDay').value = date;
          document.getElementById('tgOwner').value = it.owner || '';   // shared: write to their file
          toggleForm.submit();
        });
        row.appendChild(cb);
      }
      // Notes get a marker ("N"), events a dot in their calendar's colour; reminders have the checkbox.
      let tag = null;
      if (it.kind === 'note') {
        tag = document.createElement('span');
        tag.className = 'tag note';
        tag.textContent = 'N';
      } else if (it.kind === 'event' && it.color) {
        tag = document.createElement('span');
        tag.className = 'cdot';
        tag.style.background = it.color;
      }
      const txt = document.createElement('span');
      txt.className = 'txt';
      txt.textContent = it.text;
      const chev = document.createElement('span');
      chev.className = 'chev'; chev.textContent = '✎';
      if (tag) row.appendChild(tag);
      if (it.kind === 'event' && it.time) {    // events: show the time
        const tm = document.createElement('span');
        tm.className = 'evtime';
        tm.textContent = fmtTime(it.time);
        row.appendChild(tm);
      }
      row.appendChild(txt);
      if (it.rep) {
        const rp = document.createElement('span');
        rp.className = 'rep';
        rp.textContent = it.rep.n > 1 ? 'Every ' + it.rep.n + ' ' + it.rep.unit + 's' : 'Every ' + it.rep.unit;
        row.appendChild(rp);
      }
      if (overdue && it.due) {                 // overdue reminder: show its original date in grey
        const od = document.createElement('span');
        od.className = 'origdate';
        od.textContent = new Date(it.due + 'T00:00:00').toLocaleDateString([], { month: 'short', day: 'numeric' });
        row.appendChild(od);
      }
      // Someone else's item: don't offer to edit or delete it here. Whose it is comes
      // from the group heading above it now, so the row doesn't repeat the name.
      if (it.owner) {
        row.classList.add('shared');
        chev.textContent = '';
      }
      row.appendChild(chev);
      if (!it.owner) {
        // swipe-row: swiping it left reveals the × without turning edit mode on.
        // The partner's items don't get one — there's nothing here to delete.
        row.classList.add('swipe-row');
        const del = document.createElement('button');
        del.className = 'dp-del needs-confirm'; del.textContent = '×'; del.title = 'Delete';
        del.addEventListener('click', (ev) => {
          ev.stopPropagation();
          document.getElementById('diKind').value = it.kind;
          document.getElementById('diId').value = it.id;
          document.getElementById('diDay').value = date;
          document.getElementById('delItemForm').submit();
        });
        row.appendChild(del);
        // Open a row for editing: notes go to the Notes tab, everything else to the modal.
        // There's no Edit button any more — a long-press (touch) or double-click (desktop)
        // turns edit mode on and opens the row straight away.
        const openRow = () => {
          if (it.kind === 'note') { location.href = '/notes/?id=' + encodeURIComponent(it.id); return; }
          document.body.classList.add('editing');
          // Editing any occurrence edits the series — there's only the one stored row.
          openEdit(it.id, it.kind, it.text, it.start || it.due || date, it.time || '', it.cal || '', it.rep || null);
        };
        row.addEventListener('click', () => {
          // A plain tap only opens the row while already editing; otherwise the panel is
          // read-only and the checkboxes are the only thing you can hit by accident.
          if (document.body.classList.contains('editing')) openRow();
        });
        row.addEventListener('dblclick', (e) => { e.preventDefault(); openRow(); });
        let lpT = null, lpX = 0, lpY = 0;
        row.addEventListener('pointerdown', (e) => {
          if (e.pointerType === 'mouse' || document.body.classList.contains('editing')) return;
          if (e.target.closest('.dp-del, .dp-check, button, a, input')) return;
          lpX = e.clientX; lpY = e.clientY;
          lpT = setTimeout(() => { lpT = null; if (navigator.vibrate) navigator.vibrate(12); openRow(); }, 500);
        });
        const lpCancel = (e) => {
          if (!lpT) return;
          if (e.type === 'pointermove' && Math.abs(e.clientX - lpX) < 10 && Math.abs(e.clientY - lpY) < 10) return;
          clearTimeout(lpT); lpT = null;
        };
        row.addEventListener('pointermove', lpCancel);
        row.addEventListener('pointerup', lpCancel);
        row.addEventListener('pointercancel', lpCancel);
      }
      groups[gkey(it)].appendChild(row);
    }
    // Everything on the day may have been filtered out (all done, "Completed" off).
    if (!dpList.children.length) {
      const p = document.createElement('p');
      p.className = 'dp-empty';
      p.textContent = 'Nothing to show on this day.';
      dpList.appendChild(p);
    }
  };

  const selectDay = date => {
    selected = date;
    document.querySelectorAll('.cell.selected').forEach(c => c.classList.remove('selected'));
    const cell = document.querySelector('.cell[data-date="' + date + '"]');
    if (cell) cell.classList.add('selected');
    renderPanel(date);
  };

  // A day is selected by a *tap* and nothing else. Watching `click` meant every swipe
  // across the grid selected whichever cell the finger happened to lift off, so the
  // month you paged to opened on a day you never picked. A tap is: pointer down and up
  // on the same cell, without the finger travelling — anything else is a swipe, and the
  // swipe handler below is welcome to it.
  (function () {
    const TAP = 10;                       // px of travel still counts as a tap
    let downCell = null, downX = 0, downY = 0;
    const grid = document.getElementById('calGrid');
    grid.addEventListener('pointerdown', e => {
      downCell = e.target.closest('.cell[data-date]');
      downX = e.clientX; downY = e.clientY;
    });
    grid.addEventListener('pointerup', e => {
      const cell = e.target.closest('.cell[data-date]');
      const from = downCell; downCell = null;
      if (!cell || cell !== from) { return; }
      if (Math.abs(e.clientX - downX) > TAP || Math.abs(e.clientY - downY) > TAP) { return; }
      selectDay(cell.dataset.date);
    });
    grid.addEventListener('pointercancel', () => { downCell = null; });
  })();
  document.querySelectorAll('.cell[data-date]').forEach(cell => {
    cell.addEventListener('keydown', e => {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); selectDay(cell.dataset.date); }
    });
  });

  // + Add in the panel -> create modal for the selected day.
  dpAdd.addEventListener('click', () => { if (selected) openAdd(selected); });

  // Start on the selected day (today, or ?day=).
  const INITIAL_DAY = '<?= e($selDay) ?>';
  if (INITIAL_DAY) selectDay(INITIAL_DAY);

  document.getElementById('calShowAll').addEventListener('click', () => {
    const on = document.body.classList.toggle('show-done');
    localStorage.setItem('calShowDone', on ? '1' : '0');
    if (selected) renderPanel(selected);
  });

  // Day-panel Edit mode: reveal × to quick-delete items. There's no Edit button any
  // more — a long-press (touch) or double-click (desktop) on a row turns it on and opens
  // that row. Always starts off; a delete redirects back with ?edit=1 so quick-deleting
  // several things in a row keeps the mode on.
  if (new URLSearchParams(location.search).get('edit') === '1') {
    document.body.classList.add('editing');
    const u = new URL(location.href); u.searchParams.delete('edit');
    history.replaceState(null, '', u);
  }
  // Leave edit mode by tapping empty space in the panel (no Edit button to press).
  document.getElementById('dpList').addEventListener('click', (e) => {
    if (!document.body.classList.contains('editing')) return;
    if (e.target.closest('.dp-item, button, a, input, select')) return;
    document.body.classList.remove('editing');
  });

  // ---- Calendars & calendar sets ----
  const CSRF     = '<?= $csrf ?>';
  const PALETTE  = <?= json_encode(CAL_COLORS) ?>;
  // New events land in the calendar you're looking at; when that's "all" or a set,
  // they land in the default chosen in the manage window.
  let DEFAULT_CAL  = '<?= e($defCal) ?>';
  const VIEWED_CAL = '<?= e(in_array($calView, $calIds, true) ? $calView : '') ?>';
  const newEventCal = () => VIEWED_CAL || DEFAULT_CAL;
  let CALS   = <?= json_encode(array_values($calList), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  // The interleaved manage order (mine + the partner's shared ids), driving the manage list.
  let CAL_ORDER = <?= json_encode(array_values(array_filter((array) ($calPrefs['cal_order'] ?? []), 'is_string'))) ?>;
  const FOLDERS = <?= json_encode(array_values($remFolders), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  const PARTNER = <?= json_encode($partner ? share_name($partner) : null) ?>;
  const SHARED_FOLDERS = <?= json_encode(array_values($sharedFolders), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  // id/name/colour only — enough to offer them as members of a set.
  const SHARED_CALS = <?= json_encode(array_map(fn($c) => ['id' => $c['id'], 'name' => $c['name'], 'color' => $c['color'] ?? CAL_COLORS[0]], $sharedCals), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  let SHARES = <?= json_encode($myShares, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  let HIDDEN = <?= json_encode(array_values($hidFolders), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  let HIDDEN_SHARED = <?= json_encode(array_values($hidShared), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  // How much of each reminder folder shows on the calendar: 'all' | 'dated' | 'none'.
  const RF_MODE = <?= json_encode(array_combine($remFolders, array_map(fn($f) => rf_mode($calPrefs, $f), $remFolders)) ?: (object) []) ?>;
  const RF_MODE_SHARED = <?= json_encode($sharedFolders ? array_combine($sharedFolders, array_map(fn($f) => rf_mode($calPrefs, $f, true), $sharedFolders)) : (object) []) ?>;
  let calDirty = false;          // something changed -> reload on close so dots/colours catch up

  const calModal = document.getElementById('calModal');
  const calRows  = document.getElementById('calRows');
  const swatchPop= document.getElementById('swatchPop');
  const onlyCals = () => CALS;

  // Every change posts here and gets the whole list back, so the UI never guesses.
  const calApi = (action, extra) => {
    const body = new URLSearchParams(Object.assign({ csrf: CSRF, action }, extra || {}));
    return fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body })
      .then(r => r.json())
      .then(j => {
        if (!j) return;
        if (j.default !== undefined) { DEFAULT_CAL = j.default; }
        if (j.order)  { CAL_ORDER = j.order; }   // keep the interleaved order in step after a drag
        if (j.list)   { CALS = j.list; calDirty = true; renderCals(); renderDefault(); if (PARTNER) renderShare(); }
        if (j.hidden) { HIDDEN = j.hidden; HIDDEN_SHARED = j.hiddenShared || []; calDirty = true; renderFolders(); }
        if (j.shares) { SHARES = j.shares; calDirty = true; renderShare(); }
      })
      .catch(() => location.reload());
  };

  function renderCals() {
    calRows.innerHTML = '';
    // One interleaved list, mine and the partner's together in the saved order — no owner
    // heading. Mine are fully editable (swatch, delete) and drag; the partner's are
    // read-only (a badge marks them) but still drag, since reordering theirs is my own view
    // preference and only decides the order of same-time events on the calendar.
    const mine   = onlyCals();
    const shared = SHARED_CALS.map(c => ({ id: c.id, name: c.name, color: c.color, shared: true }));
    const byId = {};
    mine.forEach(c => { byId[c.id] = c; });
    shared.forEach(c => { byId[c.id] = c; });
    const seen = {}, ordered = [];
    CAL_ORDER.forEach(id => { if (byId[id] && !seen[id]) { seen[id] = 1; ordered.push(byId[id]); } });
    mine.forEach(c => { if (!seen[c.id]) { seen[c.id] = 1; ordered.push(c); } });
    shared.forEach(c => { if (!seen[c.id]) { seen[c.id] = 1; ordered.push(c); } });
    const canDelete = mine.length > 1;   // never leave the user with no calendar of their own

    ordered.forEach(c => {
      const li = document.createElement('li');
      li.dataset.id = c.id;
      const handle = document.createElement('span');
      handle.className = 'chandle'; handle.textContent = '☰'; handle.title = 'Drag to reorder';
      li.appendChild(handle);

      if (c.shared) {
        const dot = document.createElement('span');
        dot.className = 'cdot-ro'; dot.style.background = c.color;
        const name = document.createElement('span'); name.className = 'cname'; name.textContent = c.name;
        const badge = document.createElement('span'); badge.className = 'cshared-badge'; badge.textContent = PARTNER;
        li.append(dot, name, badge);
      } else {
        const sw = document.createElement('button');
        sw.type = 'button'; sw.className = 'cswatch'; sw.style.background = c.color; sw.title = 'Choose colour';
        sw.addEventListener('click', e => { e.stopPropagation(); openSwatches(sw, c.id); });
        const name = document.createElement('span'); name.className = 'cname'; name.textContent = c.name;
        li.append(sw, name);
        if (canDelete) {
          const del = document.createElement('button');
          del.type = 'button'; del.className = 'cdel needs-confirm'; del.textContent = '×'; del.title = 'Delete calendar';
          del.addEventListener('click', () => { calApi('cal_delete', { id: c.id, confirm: 1 }); });
          li.appendChild(del);
        }
      }
      calRows.appendChild(li);
    });
  }

  // Which calendar new events default to, when you aren't viewing one in particular.
  const calDefault = document.getElementById('calDefault');
  function renderDefault() {
    calDefault.innerHTML = '';
    onlyCals().forEach(c => {
      const o = document.createElement('option');
      o.value = c.id; o.textContent = c.name;
      if (c.id === DEFAULT_CAL) o.selected = true;
      calDefault.appendChild(o);
    });
  }
  calDefault.addEventListener('change', () => {
    DEFAULT_CAL = calDefault.value;
    calApi('cal_default', { id: DEFAULT_CAL });
  });


  function subHead(text) {
    const li = document.createElement('li');
    li.className = 'calempty'; li.style.background = 'none'; li.style.border = 'none';
    li.textContent = text;
    return li;
  }

  // Reminder folders: each carries an All / Dated / None dropdown deciding how much of it
  // shows on the calendar. The button is icon-only (● all, 🗓 dated, ✕ none) so it stays
  // small; the words appear in the menu it drops.
  const RF_ICON = { all: '●', dated: '🗓', none: '✕' };
  const RF_LABEL = { all: 'All', dated: 'Dated', none: 'None' };
  function modeRow(label, mode, shared) {
    const li = document.createElement('li');
    const name = document.createElement('span'); name.className = 'cname'; name.textContent = label;
    const dd = document.createElement('div'); dd.className = 'rfmode';
    const btn = document.createElement('button'); btn.type = 'button'; btn.className = 'rfmode-btn';
    let cur = RF_LABEL[mode] ? mode : 'dated';
    const paint = () => { btn.textContent = RF_ICON[cur]; btn.title = RF_LABEL[cur]; btn.classList.toggle('off', cur === 'none'); };
    const menu = document.createElement('div'); menu.className = 'rfmode-menu'; menu.hidden = true;
    ['all', 'dated', 'none'].forEach(m => {
      const o = document.createElement('button'); o.type = 'button'; o.className = 'rfmode-opt';
      o.innerHTML = '<span class="rfmode-ic">' + RF_ICON[m] + '</span>' + RF_LABEL[m];
      o.addEventListener('click', e => {
        e.stopPropagation(); cur = m; paint(); menu.hidden = true;
        calApi('rf_mode', shared ? { name: label, mode: m, shared: 1 } : { name: label, mode: m });
      });
      menu.appendChild(o);
    });
    btn.addEventListener('click', e => { e.stopPropagation(); document.querySelectorAll('.rfmode-menu').forEach(x => { if (x !== menu) x.hidden = true; }); menu.hidden = !menu.hidden; });
    paint();
    dd.append(btn, menu); li.append(name, dd);
    return li;
  }
  document.addEventListener('click', () => document.querySelectorAll('.rfmode-menu').forEach(x => x.hidden = true));
  const folderRows = document.getElementById('folderRows');
  function renderFolders() {
    folderRows.innerHTML = '';
    FOLDERS.forEach(f => folderRows.appendChild(modeRow(f, RF_MODE[f] || 'dated', false)));
    if (SHARED_FOLDERS.length) {
      folderRows.appendChild(subHead(PARTNER + '’s folders'));
      SHARED_FOLDERS.forEach(f => folderRows.appendChild(modeRow(f, RF_MODE_SHARED[f] || 'dated', true)));
    }
  }

  // The share window itself lives in lib/sharing.php — the Reminders "+" opens the
  // same one. It asks for the current lists through this, so adding a calendar and
  // then opening Share shows it straight away.
  window.shareData = () => ({
    cals: onlyCals().map(c => [c.id, c.name]),
    folders: FOLDERS,
    notefolders: <?= json_encode($partner ? folders_load($cfg['data_dir'])['notes'] : []) ?>,
    shares: SHARES
  });
  window.onSharesChanged = (s) => { SHARES = s; calDirty = true; window.shareRender(); };
  const renderShare = () => { if (window.shareRender) { window.shareRender(); } };

  // Colour palette popover, anchored to the swatch that opened it.
  function openSwatches(anchor, id) {
    swatchPop.innerHTML = '';
    PALETTE.forEach(col => {
      const b = document.createElement('button');
      b.type = 'button'; b.style.background = col; b.title = col;
      b.addEventListener('click', () => { swatchPop.hidden = true; calApi('cal_color', { id, color: col }); });
      swatchPop.appendChild(b);
    });
    swatchPop.hidden = false;
    const r = anchor.getBoundingClientRect();
    const w = swatchPop.offsetWidth, h = swatchPop.offsetHeight;
    swatchPop.style.left = Math.max(8, Math.min(r.left, window.innerWidth  - w - 8)) + 'px';
    swatchPop.style.top  = Math.max(8, Math.min(r.bottom + 6, window.innerHeight - h - 8)) + 'px';
  }
  document.addEventListener('click', e => {
    if (!swatchPop.hidden && !swatchPop.contains(e.target) && !e.target.closest('.cswatch')) swatchPop.hidden = true;
  });


  // Drag to reorder the calendar list (pointer events, so touch works).
  const wireReorder = (list, action) => {
    let dragging = null;
    list.addEventListener('pointerdown', e => {
      const h = e.target.closest('.chandle'); if (!h) return;
      e.preventDefault();
      dragging = h.closest('li'); dragging.classList.add('dragging');
      try { h.setPointerCapture(e.pointerId); } catch (_) {}
    });
    list.addEventListener('pointermove', e => {
      if (!dragging) return;
      const el   = document.elementFromPoint(e.clientX, e.clientY);
      const over = el && el.closest('li');
      if (over && over !== dragging && over.parentNode === list) {
        const r = over.getBoundingClientRect();
        list.insertBefore(dragging, (e.clientY < r.top + r.height / 2) ? over : over.nextSibling);
      }
    });
    const end = () => {
      if (!dragging) return;
      dragging.classList.remove('dragging'); dragging = null;
      calApi(action, { order: JSON.stringify([...list.querySelectorAll('li[data-id]')].map(li => li.dataset.id)) });
    };
    list.addEventListener('pointerup', end);
    list.addEventListener('pointercancel', end);
  };
  wireReorder(calRows, 'cal_reorder');

  const addCal = () => {
    const i = document.getElementById('calName');
    if (i.value.trim()) { calApi('cal_add', { name: i.value.trim() }); i.value = ''; }
  };
  document.getElementById('calAdd').addEventListener('click', addCal);
  document.getElementById('calName').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); addCal(); } });

  document.getElementById('calMgr').addEventListener('click', () => {
    renderCals(); renderDefault(); renderFolders();
    calModal.classList.add('open');
  });
  // Collapse/expand the manager's sections (Calendars, Sets, Reminder folders); remembered.
  document.querySelectorAll('#calModal .cm-head').forEach(h => {
    const sec = h.closest('.cm-section'), key = 'cmcollapse:' + sec.dataset.cm;
    if (localStorage.getItem(key) === '1') sec.classList.add('collapsed');
    h.addEventListener('click', () => {
      localStorage.setItem(key, sec.classList.toggle('collapsed') ? '1' : '0');
    });
  });
  const closeCalModal = () => {
    calModal.classList.remove('open');
    if (calDirty) location.reload();   // colours, names and the picker all live in the page
  };
  document.getElementById('calDone').addEventListener('click', closeCalModal);
  calModal.addEventListener('click', e => { if (e.target === calModal) closeCalModal(); });

  // Picking the visible calendar / set. Hand-built popover rather than a <select>,
  // so each entry can carry its calendar's colour dot.
  (function () {
    const btn = document.getElementById('calSelBtn'), menu = document.getElementById('calSelMenu');
    const close = () => { menu.hidden = true; btn.setAttribute('aria-expanded', 'false'); };
    btn.addEventListener('click', e => {
      e.stopPropagation();
      menu.hidden = !menu.hidden;
      if (!menu.hidden) {
        // Fixed-positioned so it overlays the events below instead of being clipped
        // inside the scrolling calendar; anchor it under the button, to its right edge.
        const r = btn.getBoundingClientRect();
        menu.style.top = (r.bottom + 5) + 'px';
        menu.style.right = (window.innerWidth - r.right) + 'px';
      }
      btn.setAttribute('aria-expanded', menu.hidden ? 'false' : 'true');
    });
    document.addEventListener('click', e => { if (!menu.hidden && !menu.contains(e.target)) close(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
    // Toggling a calendar's visibility keeps the menu open (we reopen it after the reload
    // that re-filters the grid), so you can flip several without it closing on you.
    const open = () => {
      menu.hidden = false;
      const r = btn.getBoundingClientRect();
      menu.style.top = (r.bottom + 5) + 'px';
      menu.style.right = (window.innerWidth - r.right) + 'px';
      btn.setAttribute('aria-expanded', 'true');
    };
    try { if (sessionStorage.getItem('calPickOpen') === '1') { sessionStorage.removeItem('calPickOpen'); open(); } } catch (_) {}
    menu.addEventListener('click', e => {
      const cb = e.target.closest('.cvis'); if (!cb) return;
      e.preventDefault(); e.stopPropagation();
      const on = !cb.classList.contains('on');
      cb.classList.toggle('on', on); cb.setAttribute('aria-checked', on ? 'true' : 'false');
      const body = cb.classList.contains('cvis-all')
        ? { csrf: CSRF, action: 'cal_vis_all', show: on ? 1 : '' }
        : { csrf: CSRF, action: 'cal_vis', name: cb.dataset.cal, show: on ? 1 : '' };
      try { sessionStorage.setItem('calPickOpen', '1'); } catch (_) {}
      fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' },
                  body: new URLSearchParams(body) })
        .then(() => location.reload()).catch(() => location.reload());
    });
    // Carry the open day across, the way the old select did. A tap on the checkbox is
    // handled above, so it never navigates.
    menu.querySelectorAll('.calpick-opt').forEach(a => {
      a.addEventListener('click', e => {
        if (e.target.closest('.cvis')) { return; }
        if (a.classList.contains('calpick-manage')) { close(); return; }   // opens the manager, not a nav
        e.preventDefault();
        const u = new URL(a.href, location.href);
        if (selected) u.searchParams.set('day', selected);
        u.searchParams.set('ym', '<?= e($ym) ?>');
        location.href = u.toString();
      });
    });
  })();

  // Tapping the "July 2026" label opens a small menu to jump straight to any month/year.
  (function () {
    const btn = document.getElementById('ymBtn'), menu = document.getElementById('ymMenu');
    if (!btn || !menu) return;
    const close = () => { menu.hidden = true; btn.setAttribute('aria-expanded', 'false'); };
    btn.addEventListener('click', e => {
      e.stopPropagation();
      menu.hidden = !menu.hidden;
      if (!menu.hidden) {
        const r = btn.getBoundingClientRect();
        menu.style.top = (r.bottom + 5) + 'px';
        menu.style.left = Math.max(8, r.left - 20) + 'px';
      }
      btn.setAttribute('aria-expanded', menu.hidden ? 'false' : 'true');
    });
    document.addEventListener('click', e => { if (!menu.hidden && !menu.contains(e.target) && e.target !== btn) close(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
    const go = () => {
      const m = document.getElementById('ymMonthSel').value.padStart(2, '0');
      const y = document.getElementById('ymYearSel').value;
      location.href = '?ym=' + y + '-' + m;
    };
    document.getElementById('ymGo').addEventListener('click', go);
  })();

  document.getElementById('mCancel').addEventListener('click', closeModal);
  modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });
  const shareModalEl = document.getElementById('shareModal');
  const anyModalOpen = () => modal.classList.contains('open')
    || calModal.classList.contains('open')
    || (shareModalEl && shareModalEl.classList.contains('open'));
  document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    if (shareModalEl && shareModalEl.classList.contains('open')) { shareModalEl.classList.remove('open'); return; }
    if (calModal.classList.contains('open')) { closeCalModal(); return; }
    if (modal.classList.contains('open')) { closeModal(); return; }
    if (document.body.classList.contains('editing')) { document.body.classList.remove('editing'); return; }
    closeModal();
  });

  // ---- Week mode: swipe up on the calendar to keep this week and the next ----
  // The month is still what the server renders; week mode just hides the other rows
  // and re-points the arrows, so nothing about the grid or the day panel changes.
  (function () {
    const grid  = document.getElementById('calGrid');
    const cells = [...grid.querySelectorAll('.cell')];
    const lastWeek = Math.max(...cells.map(c => +c.dataset.week));
    const weekOfToday = () => {
      const t = grid.querySelector('.cell.today');
      return t ? +t.dataset.week : 0;
    };
    // Landing here from an arrow press: 'first' or 'last' says which end to open on.
    const wkParam = new URLSearchParams(location.search).get('wk');
    let anchor = wkParam === 'last' ? Math.max(0, lastWeek - 1)
               : wkParam === 'first' ? 0
               : weekOfToday();
    let weekMode = localStorage.getItem('calWeekMode') === '1';

    const apply = () => {
      document.body.classList.toggle('weekmode', weekMode);
      if (anchor > lastWeek - 1) { anchor = Math.max(0, lastWeek - 1); }
      cells.forEach(c => {
        const w = +c.dataset.week;
        c.classList.toggle('wk-hide', weekMode && w !== anchor && w !== anchor + 1);
      });
    };
    const setMode = (on) => {
      if (weekMode === on) { return; }
      weekMode = on;
      localStorage.setItem('calWeekMode', on ? '1' : '0');
      if (on) { anchor = weekOfToday(); }
      apply();
    };
    apply();

    // In week mode the arrows step a week at a time, rolling into the next month
    // (opening on its first or last week) when they run off this one.
    const step = (dir) => {
      const target = anchor + dir;
      if (target < 0)               { location.href = '?ym=<?= $prev ?>&wk=last';  return true; }
      if (target > lastWeek - 1)    { location.href = '?ym=<?= $next ?>&wk=first'; return true; }
      anchor = target; apply(); return true;
    };
    document.querySelectorAll('.monthnav > a').forEach((a, i) => {
      a.addEventListener('click', (e) => {
        if (!weekMode) { return; }        // month mode: the plain ?ym= link
        e.preventDefault();
        step(i === 0 ? -1 : 1);
      });
    });

    // Swipe up on the calendar half to collapse it to a week, down to open it back out;
    // sideways pages. Day selection is a tap and lives in its own handler, so a swipe
    // never has to be filtered back out of a click here.
    let sy = null, sx = null;
    const wrap = document.querySelector('.cal-top') || grid.parentElement;
    wrap.addEventListener('touchstart', (e) => {
      if (e.touches.length !== 1) { sy = null; return; }
      sy = e.touches[0].clientY; sx = e.touches[0].clientX;
    }, { passive: true });
    wrap.addEventListener('touchend', (e) => {
      if (sy === null) { return; }
      const t = e.changedTouches[0], dy = t.clientY - sy, dx = t.clientX - sx;
      sy = null;
      // A firm sideways swipe pages: left = forward, right = back. It matches whatever
      // the arrows do — a week at a time in week mode, a month in month mode — so the
      // gesture and the buttons never disagree about how far one step is.
      if (Math.abs(dx) > 55 && Math.abs(dx) > Math.abs(dy)) {
        if (weekMode) { step(dx < 0 ? 1 : -1); }
        else { location.href = dx < 0 ? '?ym=<?= $next ?>' : '?ym=<?= $prev ?>'; }
        return;
      }
      if (Math.abs(dy) < 45 || Math.abs(dy) < Math.abs(dx)) { return; }   // a tap, or a small drag
      setMode(dy < 0);
    }, { passive: true });
  })();

  // Left/right arrow keys cycle months (when no modal is open).
  document.addEventListener('keydown', e => {
    if (anyModalOpen() || /^(INPUT|SELECT|TEXTAREA)$/.test(document.activeElement.tagName)) return;
    if (e.key === 'ArrowLeft')  location.href = '?ym=<?= $prev ?>';
    if (e.key === 'ArrowRight') location.href = '?ym=<?= $next ?>';
  });
</script>
<?= chrome_script() ?>
<?php if ($partner) { echo share_modal_script($csrf); } ?>
</body>
</html>
