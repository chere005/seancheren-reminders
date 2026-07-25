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

/** Palette offered when tapping a calendar's colour square. */
const CAL_COLORS = ['#38bdf8', '#34d399', '#f0a860', '#f472b6', '#8b6ef0',
                    '#facc15', '#fb7185', '#22d3ee', '#a3e635', '#94a3b8'];

/**
 * Calendars and calendar sets share one list, the way sections share a list elsewhere:
 *   calendar -> ['id','name','color']            set -> ['id','type'=>'set','name','cals'=>[ids]]
 * List order is display order.
 */
function is_calset(array $it): bool { return ($it['type'] ?? '') === 'set'; }

/** Always hands back at least one calendar, creating the default one on first use. */
function load_calendars(string $file): array
{
    $list = store_read($file);
    foreach ($list as $it) { if (!is_calset($it)) { return $list; } }
    $list[] = ['id' => bin2hex(random_bytes(6)), 'name' => 'Personal', 'color' => CAL_COLORS[0], 'created' => time()];
    store_write($file, array_values($list));
    return $list;
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

// --- Sharing: the other person's calendars and reminder folders, if they shared any ---
$me          = current_user() ?? '';
$partner     = share_partner();
$myShares    = $partner ? shares_load($cfg['data_dir'], $me) : ['calendars' => [], 'folders' => []];
$theirShares = $partner ? shares_load($cfg['data_dir'], $partner) : ['calendars' => [], 'folders' => []];

// Their whole calendar list (needed to resolve an event with no calendar), and the shared slice.
$theirCals   = $partner ? array_values(array_filter(store_read(user_data_file($cfg['data_dir'], 'calendars', $partner)),
                                                    fn($c) => !is_calset($c))) : [];
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
    // Return to the item's day (so the bottom panel shows it), else the panel's day.
    $dayParam = (string) ($_POST['day'] ?? '');
    $retDay   = $dateOk ? $date : (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dayParam) ? $dayParam : '');
    $ym       = $retDay !== '' ? substr($retDay, 0, 7) : ((string) ($_POST['ym'] ?? date('Y-m')));
    $undoFlag = '';   // set after a delete so the Undo button appears

    // --- Manage calendars / calendar sets (AJAX: answers with the fresh list, no reload) ---
    if (in_array($action, ['cal_add', 'cal_delete', 'cal_color', 'cal_reorder',
                           'set_add', 'set_delete', 'set_members', 'cal_default'], true)) {
        $calIdsNow = array_column(array_values(array_filter($calList, fn($c) => !is_calset($c))), 'id');
        $name      = mb_substr(trim(preg_replace('/\s+/', ' ', (string) ($_POST['name'] ?? ''))), 0, 40);

        if ($action === 'cal_add' && $name !== '') {
            $calList[] = ['id' => bin2hex(random_bytes(6)), 'name' => $name,
                          'color' => CAL_COLORS[count($calIdsNow) % count(CAL_COLORS)], 'created' => time()];
        } elseif ($action === 'cal_delete' && count($calIdsNow) > 1) {
            $calList = array_values(array_filter($calList, fn($c) => is_calset($c) || ($c['id'] ?? '') !== $id));
            foreach ($calList as &$s) {   // drop it from any set that listed it
                if (is_calset($s)) { $s['cals'] = array_values(array_filter($s['cals'] ?? [], fn($c) => $c !== $id)); }
            }
            unset($s);
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
                    if (!is_calset($c) && ($c['id'] ?? '') === $id) { $c['color'] = $color; break; }
                }
                unset($c);
            }
        } elseif ($action === 'cal_reorder') {
            $pos  = array_flip((array) (json_decode((string) ($_POST['order'] ?? '[]'), true) ?: []));
            $cals = array_values(array_filter($calList, fn($c) => !is_calset($c)));
            $sets = array_values(array_filter($calList, 'is_calset'));
            usort($cals, fn($a, $b) => ($pos[$a['id']] ?? 999) <=> ($pos[$b['id']] ?? 999));
            $calList = array_merge($cals, $sets);
        } elseif ($action === 'set_add' && $name !== '') {
            $calList[] = ['id' => bin2hex(random_bytes(6)), 'type' => 'set', 'name' => $name,
                          'cals' => [], 'created' => time()];
        } elseif ($action === 'set_delete') {
            $calList = array_values(array_filter($calList, fn($c) => !(is_calset($c) && ($c['id'] ?? '') === $id)));
        } elseif ($action === 'cal_default') {
            // Which calendar new events land in. Kept in calprefs, not the calendar list.
            if (in_array($id, $calIdsNow, true)) {
                $calPrefs['default_cal'] = $id;
                store_write($prefFile, $calPrefs);
            }
        } elseif ($action === 'set_members') {
            // A set may hold the other person's shared calendars alongside my own.
            $pool = array_merge($calIdsNow, $sharedIds);
            $want = (array) (json_decode((string) ($_POST['cals'] ?? '[]'), true) ?: []);
            foreach ($calList as &$s) {
                if (is_calset($s) && ($s['id'] ?? '') === $id) {
                    $s['cals'] = array_values(array_intersect($pool, $want));
                    break;
                }
            }
            unset($s);
        }
        save_json_list($calFile, $calList);
        // The chosen default may have just been deleted; fall back to the first calendar.
        $idsAfter = array_column(array_values(array_filter($calList, fn($c) => !is_calset($c))), 'id');
        $defNow   = (string) ($calPrefs['default_cal'] ?? '');
        if (!in_array($defNow, $idsAfter, true)) { $defNow = $idsAfter[0] ?? ''; }
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'list' => array_values($calList), 'default' => $defNow]);
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

    // --- Share one of my calendars / reminder folders with the other person ---
    if ($action === 'share_set' && $partner) {
        $kind = (string) ($_POST['kind'] ?? '');
        $key  = (string) ($_POST['key'] ?? '');
        $pool = $kind === 'calendar'
            ? array_column(array_values(array_filter($calList, fn($c) => !is_calset($c))), 'id')
            : $remFolders;
        if (in_array($kind, ['calendar', 'folder'], true) && in_array($key, $pool, true)) {
            $myShares = shares_toggle($cfg['data_dir'], $me, $kind, $key, !empty($_POST['on']));
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'shares' => $myShares]);
        exit;
    }

    // An event's calendar, ignored unless it names one that exists.
    $calIds  = array_column(array_values(array_filter($calList, fn($c) => !is_calset($c))), 'id');
    $evCal   = (string) ($_POST['cal'] ?? '');
    $evCalOk = in_array($evCal, $calIds, true) ? $evCal : '';

    // "Dinner 8/3 7pm" -> text "Dinner", date 2026-08-03, time 19:00. An explicit
    // date or time from the window always wins over what was typed.
    [$ptext, $pdate, $ptime] = parse_when_from_text($text);
    $effDate = $dateOk ? $date : $pdate;

    if ($action === 'add_event' && $text !== '') {
        $file = user_data_file($cfg['data_dir'], 'events');
        $list = load_json_list($file);
        $list[] = ['id' => bin2hex(random_bytes(6)), 'text' => mb_substr($ptext, 0, 500),
                   'date' => $effDate, 'time' => $timeOk ? $time : $ptime,
                   'cal' => $evCalOk, 'created' => time()];
        save_json_list($file, $list);
    } elseif ($action === 'add_reminder' && $text !== '') {
        $file = user_data_file($cfg['data_dir'], 'reminders');
        $list = load_json_list($file);
        $list[] = ['id' => bin2hex(random_bytes(6)), 'text' => mb_substr($ptext, 0, 500),
                   'due' => $effDate, 'time' => $ptime, 'done' => false, 'created' => time()];
        save_json_list($file, $list);
    } elseif ($action === 'add_note' && $text !== '') {
        $file  = user_data_file($cfg['data_dir'], 'notes');
        $list  = load_json_list($file);
        $newId = bin2hex(random_bytes(6));
        $list[] = ['id' => $newId, 'title' => mb_substr($ptext, 0, 200),
                   'date' => $effDate, 'body' => '', 'created' => time(), 'updated' => time()];
        save_json_list($file, $list);
        header('Location: /notes/?id=' . $newId);   // jump straight to the note editor
        exit;
    } elseif ($action === 'toggle_reminder' && $id !== '') {
        // A reminder shown from a shared folder still lives in its owner's file.
        $owner = ($partner && ($_POST['owner'] ?? '') === $partner) ? $partner : null;
        $file = user_data_file($cfg['data_dir'], 'reminders', $owner);
        $list = load_json_list($file);
        foreach ($list as &$it) {
            // Only ever reach into a folder they actually shared.
            if ($owner !== null && !in_array($it['folder'] ?? FOLDER_DEFAULT, $sharedFolders, true)) { continue; }
            if (($it['id'] ?? '') === $id) { $it['done'] = empty($it['done']); break; }
        }
        unset($it);
        save_json_list($file, $list);
    } elseif ($action === 'edit_item' && ($spec = kind_spec($kind)) && $id !== '' && $text !== '') {
        $file = user_data_file($cfg['data_dir'], $spec['base']);
        $list = load_json_list($file);
        foreach ($list as &$it) {
            if (($it['id'] ?? '') === $id) {
                $it[$spec['textField']] = mb_substr($text, 0, $kind === 'note' ? 200 : 500);
                $it[$spec['dateField']] = $dateOk ? $date : null;
                if ($kind === 'event') { $it['time'] = $timeOk ? $time : null; $it['cal'] = $evCalOk; }
                if ($kind === 'note')  { $it['updated'] = time(); }
                break;
            }
        }
        unset($it);
        save_json_list($file, $list);
    } elseif ($action === 'delete_item' && ($spec = kind_spec($kind)) && $id !== '') {
        $file = user_data_file($cfg['data_dir'], $spec['base']);
        $list = load_json_list($file);
        foreach ($list as $it) { if (($it['id'] ?? '') === $id) { $_SESSION['undo_cal'] = ['base' => $spec['base'], 'item' => $it]; break; } }
        $list = array_values(array_filter($list, fn($it) => ($it['id'] ?? '') !== $id));
        save_json_list($file, $list);
        $undoFlag = '&undo=1&edit=1';   // deleting is edit-mode only; hand it back
    } elseif ($action === 'undo_item') {
        $undoFlag = '&edit=1';
        if (!empty($_SESSION['undo_cal'])) {
            $u      = $_SESSION['undo_cal'];
            $file   = user_data_file($cfg['data_dir'], $u['base']);
            $list   = load_json_list($file);
            $list[] = $u['item'];
            save_json_list($file, $list);
            unset($_SESSION['undo_cal']);
        }
    }

    $loc = _self_path() . '?ym=' . $ym;
    if ($retDay !== '') { $loc .= '&day=' . $retDay; }
    header('Location: ' . $loc . $undoFlag);
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

$prev = date('Y-m', mktime(0, 0, 0, $month - 1, 1, $year));
$next = date('Y-m', mktime(0, 0, 0, $month + 1, 1, $year));

// --- Which calendar (or set) is on screen? ?cal= picks it; the choice sticks in the session. ---
$calsOnly = array_values(array_filter($calList, fn($c) => !is_calset($c)));
$setsOnly = array_values(array_filter($calList, 'is_calset'));
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
    foreach ($setsOnly as $s) {
        // $pickIds, not $calIds — a set can hold shared calendars too.
        if (($s['id'] ?? '') === $calView) { $visibleCals = array_values(array_intersect($pickIds, $s['cals'] ?? [])); break; }
    }
    if ($visibleCals === null) { $calView = 'all'; }   // stale id (deleted calendar/set)
}

// --- Sync: gather this user's dated reminders + notes for the visible month ---
$monthPrefix = sprintf('%04d-%02d', $year, $month);
$byDay = [];   // 'YYYY-MM-DD' => [ ['kind'=>'reminder'|'note', 'text'=>..., 'done'=>bool], ... ]

foreach ($onlyFolder === null ? load_json_list(user_data_file($cfg['data_dir'], 'reminders')) : [] as $r) {
    // Undated items in the permanent "Calendar" section ride along under today.
    $rides = empty($r['due']) && strcasecmp((string) ($r['section'] ?? ''), CALENDAR_SECTION) === 0;
    if (empty($r['due']) && !$rides) { continue; }
    if (in_array($r['folder'] ?? FOLDER_DEFAULT, $hidFolders, true)) { continue; }   // folder switched off
    $done = !empty($r['done']);                                    // done are hidden until "Show Completed"
    $eff  = $rides ? $todayYmd
          : ((!$done && $r['due'] < $todayYmd) ? $todayYmd : $r['due']);   // overdue rolls onto today; done/future stay
    if (strpos($eff, $monthPrefix) === 0) {
        // A riding item isn't late — it just lives on today — so don't mark it overdue.
        $byDay[$eff][] = ['kind' => 'reminder', 'id' => $r['id'] ?? '', 'text' => $r['text'] ?? '',
                          'done' => $done, 'rolled' => (!$rides && $eff !== $r['due']),
                          'due' => $r['due'] ?? null];
    }
}
$evList = load_json_list(user_data_file($cfg['data_dir'], 'events'));
usort($evList, fn($a, $b) => ((($a['time'] ?? '') ?: '99:99')) <=> ((($b['time'] ?? '') ?: '99:99')));
foreach ($evList as $ev) {
    // An event with no (or a stale) calendar belongs to the first one.
    $ec = in_array($ev['cal'] ?? '', $calIds, true) ? $ev['cal'] : $defCal;
    if ($visibleCals !== null && !in_array($ec, $visibleCals, true)) { continue; }
    if (!empty($ev['date']) && strpos($ev['date'], $monthPrefix) === 0) {
        $byDay[$ev['date']][] = ['kind' => 'event', 'id' => $ev['id'] ?? '', 'text' => $ev['text'] ?? '',
                                 'time' => $ev['time'] ?? '', 'done' => false,
                                 'cal' => $ec, 'color' => $calColor[$ec] ?? CAL_COLORS[0]];
    }
}

// --- The other person's shared calendars and reminder folders, read from their files ---
if ($partner) {
    $theirDef = $theirCalIds[0] ?? '';
    foreach (load_json_list(user_data_file($cfg['data_dir'], 'reminders', $partner)) as $r) {
        if (empty($r['due'])) { continue; }
        $f = $r['folder'] ?? FOLDER_DEFAULT;
        if (!in_array($f, $sharedFolders, true)) { continue; }
        // Picking a folder in the dropdown overrides the show/hide checkboxes.
        if ($onlyFolder !== null ? $f !== $onlyFolder : in_array($f, $hidShared, true)) { continue; }
        $done = !empty($r['done']);
        $eff  = (!$done && $r['due'] < $todayYmd) ? $todayYmd : $r['due'];
        if (strpos($eff, $monthPrefix) === 0) {
            $byDay[$eff][] = ['kind' => 'reminder', 'id' => $r['id'] ?? '', 'text' => $r['text'] ?? '',
                              'done' => $done, 'rolled' => ($eff !== $r['due']), 'due' => $r['due'],
                              'owner' => $partner];
        }
    }
    if ($sharedIds) {
        $theirEvs = load_json_list(user_data_file($cfg['data_dir'], 'events', $partner));
        usort($theirEvs, fn($a, $b) => ((($a['time'] ?? '') ?: '99:99')) <=> ((($b['time'] ?? '') ?: '99:99')));
        foreach ($theirEvs as $ev) {
            $ec = in_array($ev['cal'] ?? '', $theirCalIds, true) ? $ev['cal'] : $theirDef;
            if (!in_array($ec, $sharedIds, true)) { continue; }              // not shared with me
            if ($visibleCals !== null && !in_array($ec, $visibleCals, true)) { continue; }
            if (!empty($ev['date']) && strpos($ev['date'], $monthPrefix) === 0) {
                $byDay[$ev['date']][] = ['kind' => 'event', 'id' => $ev['id'] ?? '', 'text' => $ev['text'] ?? '',
                                         'time' => $ev['time'] ?? '', 'done' => false,
                                         'cal' => $ec, 'color' => $calColor[$ec] ?? CAL_COLORS[0],
                                         'owner' => $partner];
            }
        }
    }
}

foreach ($onlyFolder === null ? load_json_list(user_data_file($cfg['data_dir'], 'notes')) : [] as $n) {
    if (!empty($n['date']) && strpos($n['date'], $monthPrefix) === 0) {
        $byDay[$n['date']][] = ['kind' => 'note', 'id' => $n['id'] ?? '', 'text' => $n['title'] ?? 'Untitled note', 'done' => false];
    }
}
ksort($byDay);

// Which day starts selected? ?day= param, else today if this month, else none.
$selDay = (string) ($_GET['day'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selDay) || strpos($selDay, $monthPrefix) !== 0) {
    $selDay = (strpos($todayYmd, $monthPrefix) === 0) ? $todayYmd : '';
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
      flex: 0 0 auto; max-height: 60vh; overflow-y: auto;
      padding: 1.25rem 1rem 0.5rem;
    }
    .cal-top .wrap { max-width: 640px; margin: 0 auto; }
    /* Bottom: the selected-day agenda */
    .daypanel {
      flex: 1 1 auto; min-height: 0; overflow-y: auto;
      border-top: 1px solid #2a2a2a; background: #141414;
      padding: 0.9rem 1rem calc(84px + env(safe-area-inset-bottom, 0px));
    }
    .daypanel .wrap { max-width: 640px; margin: 0 auto; }
    .wrap { max-width: 640px; margin: 0 auto; }
    header {
      display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem;
    }
    header h1 { font-size: 1.35rem; }
    header .titlebar { display: flex; align-items: center; gap: 0.6rem; }
    header .widgetlink {
      color: #38bdf8; text-decoration: none; font-size: 0.78rem;
      border: 1px solid #24506a; border-radius: 999px; padding: 0.12rem 0.6rem;
    }
    header .widgetlink:hover { background: #10222e; color: #7dd3fc; }
    body:not(.editing) header .widgetlink { display: none; }   /* edit mode only */
    /* + beside the word Calendar — manage calendars, edit mode only. */
    .calplus {
      display: none; background: none; border: 1px solid #333; color: #ccc; border-radius: 999px;
      width: 26px; height: 26px; font-size: 1.05rem; line-height: 1; cursor: pointer; font-family: inherit;
    }
    body.editing .calplus { display: inline-flex; align-items: center; justify-content: center; }
    .calplus:hover { border-color: #34d399; color: #34d399; }
    header nav a { color: #888; text-decoration: none; margin-left: 1rem; font-size: 0.85rem; }
    header nav a:hover { color: #fff; }
    header nav .who {
      color: #34d399; font-size: 0.8rem; border: 1px solid #2a4a3d;
      border-radius: 999px; padding: 0.15rem 0.6rem;
    }

    /* Visible-calendar picker, under the back button / title. */
    .calpick { margin: -0.5rem 0 0.9rem; }
    .calpick select {
      background: #1a1a1a; border: 1px solid #333; color: #ccc; border-radius: 999px;
      padding: 0.3rem 0.7rem; font-size: 16px; font-family: inherit; max-width: 100%;
    }
    .calpick select:focus { outline: none; border-color: #888; }

    .monthnav {
      display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.75rem;
    }
    .monthnav a {
      color: #eee; text-decoration: none; border: 1px solid #333; border-radius: 8px;
      padding: 0.4rem 1.1rem; font-size: 1.3rem; line-height: 1; background: #1a1a1a;
      user-select: none;
    }
    .monthnav a:hover { border-color: #34d399; color: #34d399; }
    .monthnav a:active { background: #242424; }
    .monthnav .label { font-size: 1.05rem; color: #ddd; font-weight: 600; }
    /* Today sits just left of the month name. */
    .monthnav .mlabel { display: flex; align-items: center; gap: 0.6rem; min-width: 0; }
    .monthnav .todaybtn {
      flex: 0 0 auto; background: none; border: 1px solid #333; color: #888; border-radius: 999px;
      padding: 0.2rem 0.7rem; font-size: 0.78rem; text-decoration: none; line-height: 1.3;
    }
    .monthnav .todaybtn:hover { border-color: #34d399; color: #34d399; background: #14251f; }

    .dow, .grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; }
    .dow { margin-bottom: 4px; }
    .dow span { text-align: center; font-size: 0.7rem; color: #666; padding: 0.25rem 0; }
    .cell {
      min-height: 46px; background: #171717; border: 1px solid #242424; border-radius: 6px;
      padding: 4px 4px 3px; cursor: pointer; position: relative;
      display: flex; flex-direction: column; align-items: center; gap: 3px;
    }
    .cell:not(.blank):hover { border-color: #3a5a4d; background: #1b1f1d; }
    .cell.blank { background: transparent; border-color: transparent; cursor: default; }
    .cell .num { font-size: 0.82rem; color: #999; }
    .cell.today { border-color: #34d399; }
    .cell.today .num { color: #34d399; font-weight: 700; }
    .cell.selected { border-color: #eee; background: #22262a; }
    .cell .dots { display: flex; gap: 3px; flex-wrap: wrap; justify-content: center; min-height: 6px; }
    .cell .dot { width: 6px; height: 6px; border-radius: 50%; }
    .cell .dot.reminder { background: #34d399; }
    .cell .dot.reminder.overdue { background: #f0a860; }
    .cell .dot.reminder.done { background: #555; }
    body:not(.show-done) .cell .dot.reminder.done { display: none; }
    .cell .dot.note { background: #8b6ef0; }
    .cell .dot.event { background: #38bdf8; }
    .legend { display: flex; gap: 1rem; margin-top: 0.7rem; font-size: 0.72rem; color: #888; }
    .legend .dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 4px; vertical-align: middle; }
    .legend .dot.reminder { background: #34d399; }
    .legend .dot.event { background: #38bdf8; }
    .legend .dot.note { background: #8b6ef0; }

    /* Day panel (bottom) */
    .dp-head { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.6rem; }
    .dp-head .dp-date { font-size: 1.05rem; font-weight: 600; min-width: 0; }
    .dp-head .dp-gap { flex: 1; }          /* pushes Undo/Edit/Add to the right */
    /* Show Completed sits right of the day, styled to line up with the Edit button. */
    .dp-head #calShowAll {
      background: none; border: 1px solid #333; color: #888; border-radius: 999px;
      padding: 0.35rem 0.9rem; font-size: 0.9rem; cursor: pointer; font-family: inherit; white-space: nowrap;
    }
    .dp-head #calShowAll:hover { border-color: #888; color: #ccc; }
    body.show-done .dp-head #calShowAll { color: #34d399; border-color: #34d399; font-weight: 700; }
    .dp-head .dp-undo { display: none; background: none; border: 1px solid #444; color: #ccc; border-radius: 999px;
      padding: 0.35rem 0.9rem; font-size: 0.9rem; cursor: pointer; }
    .dp-head .dp-undo:hover { border-color: #888; color: #fff; }
    body.can-undo .dp-head .dp-undo { display: inline-block; }   /* only right after a delete */
    .dp-item .dp-del { display: none; background: none; border: 1px solid #444; color: #999; border-radius: 6px;
      padding: 0.2rem 0.5rem; font-size: 0.9rem; line-height: 1; cursor: pointer; margin-left: 0.3rem; }
    body.editing .dp-item .dp-del { display: inline-block; }
    .dp-item .dp-del:hover { border-color: #f66; color: #f66; }
    .dp-head .dp-add {
      background: #34d399; color: #06251b; border: none; border-radius: 999px;
      padding: 0.35rem 0.9rem; font-size: 0.9rem; font-weight: 700; cursor: pointer;
    }
    .dp-head .dp-add:hover { background: #52e0ac; }
    .dp-head .dp-add[disabled] { opacity: 0.4; cursor: default; }
    .dp-list { display: flex; flex-direction: column; gap: 0.4rem; }
    .dp-item {
      display: flex; align-items: center; gap: 0.6rem; padding: 0.6rem 0.7rem;
      background: #1b1b1b; border: 1px solid #262626; border-radius: 8px; cursor: pointer;
    }
    .dp-item:hover { border-color: #444; }
    .dp-item .tag {
      font-size: 0.6rem; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 700;
      padding: 0.15rem 0.4rem; border-radius: 4px; white-space: nowrap;
    }
    .dp-item .tag.reminder { color: #34d399; background: #14332a; }
    .dp-item .tag.event { color: #7dd3fc; background: #0c2a3a; }
    .dp-item .tag.note { color: #b9a7f5; background: #241a3a; }
    .dp-item .dp-check { width: 20px; height: 20px; accent-color: #34d399; cursor: pointer; flex: 0 0 auto; }
    .dp-item .cdot { width: 8px; height: 8px; border-radius: 50%; flex: 0 0 auto; }
    .dp-item .txt { flex: 1; font-size: 0.95rem; word-break: break-word; }
    .dp-item .origdate { font-size: 0.72rem; color: #666; white-space: nowrap; }
    .dp-item .evtime { font-size: 0.75rem; color: #7dd3fc; font-weight: 600; white-space: nowrap; }
    .dp-item.done .txt { color: #666; text-decoration: line-through; }
    .dp-item .chev { color: #555; font-size: 0.9rem; }
    /* Someone else's item, shown here but owned (and edited) over in their app. */
    .dp-item.shared { cursor: default; }
    .dp-item .owner {
      font-size: 0.68rem; color: #888; border: 1px solid #3a3a3a; border-radius: 999px;
      padding: 0.05rem 0.45rem; white-space: nowrap;
    }
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
    .modal h2 span { color: #34d399; }
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
    .modal .kind input:checked + span { color: #34d399; font-weight: 700; }
    .modal .kind label:has(input:checked) { border-color: #34d399; background: #14251f; }
    .modal .daterow { margin-bottom: 1rem; }
    .modal .timerow { margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; }
    .modal .timerow .tlabel { font-size: 0.85rem; color: #aaa; }
    .modal .timerow input[type=time] {
      flex: 1; padding: 0.5rem 0.6rem; background: #222; border: 1px solid #3a3a3a;
      border-radius: 6px; color: #eee; font-size: 0.95rem; color-scheme: dark;
    }
    .modal .adddate {
      background: none; border: 1px dashed #3a5a4d; color: #34d399; border-radius: 6px;
      padding: 0.45rem 0.8rem; font-size: 0.9rem; cursor: pointer;
    }
    .modal .adddate:hover { background: #14251f; }
    .modal .datewrap { display: flex; align-items: center; gap: 0.5rem; }
    .modal .datewrap input[type=date] {
      flex: 1; padding: 0.5rem 0.6rem; background: #222; border: 1px solid #3a3a3a;
      border-radius: 6px; color: #eee; font-size: 0.95rem; color-scheme: dark;
    }
    .modal .datewrap .cleardate {
      background: none; border: 1px solid #3a3a3a; color: #999; border-radius: 6px;
      padding: 0.45rem 0.6rem; font-size: 0.9rem; cursor: pointer; line-height: 1;
    }
    .modal .datewrap .cleardate:hover { border-color: #f66; color: #f66; }
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
    .modal .buttons .ok { background: #34d399; color: #06251b; }
    /* Share sits on the left of the manager's button row. */
    .modal .buttons .share { margin-right: auto; background: #2a2a2a; color: #ccc; }
    .modal .buttons .share:hover { background: #333; color: #fff; }
    .modal .calrow { margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; }
    .modal .calrow select {
      flex: 1; padding: 0.5rem 0.6rem; background: #222; border: 1px solid #3a3a3a;
      border-radius: 6px; color: #eee; font-size: 16px; font-family: inherit;
    }

    /* --- Manage-calendars modal --- */
    .calmodal { max-height: 85vh; overflow-y: auto; }
    .calmodal h2 { margin-bottom: 0.7rem; }
    .calmodal .cdiv { border: none; border-top: 1px solid #333; margin: 1.2rem 0 1rem; }
    .addrow { display: flex; gap: 0.5rem; margin-bottom: 0.8rem; }
    .addrow input[type=text] { flex: 1; margin-bottom: 0; font-size: 16px; }
    .addrow .plus {
      flex: 0 0 auto; width: 40px; background: #34d399; color: #06251b; border: none;
      border-radius: 6px; font-size: 1.2rem; font-weight: 700; cursor: pointer; font-family: inherit;
    }
    .addrow .plus:hover { background: #52e0ac; }
    .callist { list-style: none; display: flex; flex-direction: column; gap: 0.4rem; }
    .callist li {
      display: flex; align-items: center; gap: 0.6rem; padding: 0.5rem 0.6rem;
      background: #222; border: 1px solid #333; border-radius: 8px;
    }
    .callist li.dragging { opacity: 0.6; border-color: #34d399; }
    .callist .chandle { color: #666; cursor: grab; touch-action: none; user-select: none; font-size: 1rem; }
    .callist .cname { flex: 1; font-size: 0.95rem; word-break: break-word; }
    .callist .cswatch {
      flex: 0 0 auto; width: 24px; height: 24px; border-radius: 6px; border: 1px solid #444;
      cursor: pointer; padding: 0;
    }
    .callist .cdel {
      flex: 0 0 auto; background: none; border: 1px solid #444; color: #999; border-radius: 6px;
      padding: 0.15rem 0.45rem; font-size: 0.9rem; line-height: 1; cursor: pointer; font-family: inherit;
    }
    .callist .cdel:hover { border-color: #f66; color: #f66; }
    .callist li.setrow { cursor: pointer; }
    .callist .ccount { font-size: 0.75rem; color: #666; white-space: nowrap; }
    .callist .cmember { width: 20px; height: 20px; accent-color: #34d399; cursor: pointer; flex: 0 0 auto; }
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
<?= chrome_styles() ?>
    body { padding-bottom: 0; }   /* panel handles the tab-bar clearance */
  </style>
</head>
<body>
<div class="cal-top">
 <div class="wrap">
  <header>
    <div class="hleft">
      <?= back_button() ?>
      <div class="titlebar">
        <h1>Calendar</h1>
        <button type="button" id="calMgr" class="calplus" title="Manage calendars" aria-label="Manage calendars">+</button>
      </div>
    </div>
    <div class="hright">
      <a class="widgetlink" href="/calendar/feed.php">Widget</a>
      <?= render_user_menu(true, 'dpEdit') ?>
    </div>
  </header>

  <div class="calpick">
    <select id="calSel" aria-label="Visible calendar">
      <option value="all"<?= $calView === 'all' ? ' selected' : '' ?>>All calendars</option>
      <?php $anyShared = $sharedCals || $sharedFolders; ?>
      <optgroup label="<?= e(share_name($me)) ?>">
        <?php foreach ($calsOnly as $c): ?>
          <option value="<?= e($c['id']) ?>"<?= $calView === $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </optgroup>
      <?php if ($setsOnly): ?>
        <optgroup label="Calendar sets">
          <?php foreach ($setsOnly as $s): ?>
            <option value="<?= e($s['id']) ?>"<?= $calView === $s['id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option>
          <?php endforeach; ?>
        </optgroup>
      <?php endif; ?>
      <?php if ($anyShared): ?>
        <optgroup label="<?= e(share_name($partner)) ?>">
          <?php foreach ($sharedCals as $c): ?>
            <option value="<?= e($c['id']) ?>"<?= $calView === $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option>
          <?php endforeach; ?>
          <?php foreach ($sharedFolders as $f): ?>
            <option value="f:<?= e($f) ?>"<?= $calView === 'f:' . $f ? ' selected' : '' ?>><?= e($f) ?></option>
          <?php endforeach; ?>
        </optgroup>
      <?php endif; ?>
    </select>
  </div>

  <div class="monthnav">
    <a href="?ym=<?= $prev ?>" title="Previous month">&#8592;</a>
    <div class="mlabel">
      <a class="todaybtn" href="?ym=<?= date('Y-m') ?>&amp;day=<?= $todayYmd ?>" title="Jump to today">Today</a>
      <span class="label"><?= e($monthName) ?></span>
    </div>
    <a href="?ym=<?= $next ?>" title="Next month">&#8594;</a>
  </div>

  <div class="dow">
    <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $d): ?>
      <span><?= $d ?></span>
    <?php endforeach; ?>
  </div>

  <div class="grid">
    <?php for ($i = 0; $i < $leadBlank; $i++): ?>
      <div class="cell blank"></div>
    <?php endfor; ?>

    <?php for ($day = 1; $day <= $daysInMo; $day++): ?>
      <?php
        $ymd     = sprintf('%04d-%02d-%02d', $year, $month, $day);
        $isToday = $ymd === $todayYmd;
        $events  = $byDay[$ymd] ?? [];
      ?>
      <div class="cell<?= $isToday ? ' today' : '' ?>" data-date="<?= $ymd ?>" role="button" tabindex="0">
        <div class="num"><?= $day ?></div>
        <div class="dots">
          <?php foreach ($events as $ev): ?>
            <?php
              $dcls = $ev['kind'];
              if ($ev['kind'] === 'reminder') {
                  $dcls .= $ev['done'] ? ' done' : (($ymd < $todayYmd || !empty($ev['rolled'])) ? ' overdue' : '');
              }
              // Events are tinted with their calendar's colour.
              $dsty = $ev['kind'] === 'event' ? ' style="background:' . e($ev['color']) . '"' : '';
            ?>
            <span class="dot <?= $dcls ?>"<?= $dsty ?>></span>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endfor; ?>
  </div>

  <div class="legend">
    <span><span class="dot reminder"></span>Reminders</span>
    <span><span class="dot event"></span>Events</span>
    <span><span class="dot note"></span>Notes</span>
  </div>
 </div>
</div>

<div class="daypanel">
 <div class="wrap">
  <div class="dp-head">
    <span class="dp-date" id="dpDate">Select a day</span>
    <button type="button" id="calShowAll">Show Completed</button>
    <span class="dp-gap"></span>
    <button class="dp-undo" id="dpUndo" type="button">Undo</button>
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
    <div class="calrow" id="mCalRow" hidden>
      <span class="tlabel">Calendar</span>
      <select name="cal" id="mCal">
        <?php foreach ($calsOnly as $c): ?>
          <option value="<?= e($c['id']) ?>"><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="buttons">
      <button type="button" class="del" id="mDelete" hidden>Delete</button>
      <button type="button" class="cancel" id="mCancel">Cancel</button>
      <button type="submit" class="ok" id="mOk">Add</button>
    </div>
  </form>
</div>

<!-- Manage calendars + calendar sets (opened by the + beside "Calendar" in edit mode) -->
<div class="modal-backdrop" id="calModal">
  <div class="modal calmodal">
    <h2>Calendars</h2>
    <div class="addrow">
      <input type="text" id="calName" placeholder="New calendar" maxlength="40" autocomplete="off">
      <button type="button" class="plus" id="calAdd" title="Add calendar">+</button>
    </div>
    <ul class="callist" id="calRows"></ul>
    <div class="defrow">
      <label for="calDefault">New events go to</label>
      <select id="calDefault"></select>
    </div>
    <hr class="cdiv">
    <h2>Calendar sets</h2>
    <div class="addrow">
      <input type="text" id="setName" placeholder="New set" maxlength="40" autocomplete="off">
      <button type="button" class="plus" id="setAdd" title="Add set">+</button>
    </div>
    <ul class="callist" id="setRows"></ul>
    <hr class="cdiv">
    <h2>Reminder folders</h2>
    <p class="chint">Which folders' reminders show up on the calendar.</p>
    <ul class="callist" id="folderRows"></ul>
    <div class="buttons" style="margin-top:1.1rem">
      <?php if ($partner): ?>
        <button type="button" class="share" id="shareBtn">Share</button>
      <?php endif; ?>
      <button type="button" class="ok" id="calDone">Done</button>
    </div>
  </div>
</div>

<?php if ($partner): ?>
<!-- What I let the other person see -->
<div class="modal-backdrop" id="shareModal">
  <div class="modal calmodal">
    <h2>Shared with <?= e(share_name($partner)) ?></h2>
    <p class="chint">Ticked calendars and folders show up on <?= e(share_name($partner)) ?>&rsquo;s calendar.</p>
    <h2>Calendars</h2>
    <ul class="callist" id="shareCals"></ul>
    <hr class="cdiv">
    <h2>Reminders</h2>
    <ul class="callist" id="shareFolders"></ul>
    <div class="buttons" style="margin-top:1.1rem">
      <button type="button" class="ok" id="shareDone">Done</button>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Which calendars belong to a set -->
<div class="modal-backdrop" id="setModal">
  <div class="modal">
    <h2 id="setHeading">Set</h2>
    <ul class="callist" id="setMembers"></ul>
    <div class="buttons" style="margin-top:1.1rem">
      <button type="button" class="cancel" id="setCancel">Cancel</button>
      <button type="button" class="ok" id="setSave">Save</button>
    </div>
  </div>
</div>

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
  <input type="hidden" name="kind" id="diKind" value="">
  <input type="hidden" name="id" id="diId" value="">
  <input type="hidden" name="ym" value="<?= e($ym) ?>">
  <input type="hidden" name="day" id="diDay" value="">
</form>

<!-- Hidden form to undo the last day-panel delete -->
<form id="undoItemForm" method="post" action="" style="display:none">
  <input type="hidden" name="csrf" value="<?= $csrf ?>">
  <input type="hidden" name="action" value="undo_item">
  <input type="hidden" name="ym" value="<?= e($ym) ?>">
  <input type="hidden" name="day" id="uiDay" value="">
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
  // Time and calendar are event-only fields, so they show and hide together.
  const showTime = (val) => { mTime.value = val || ''; mTimeRow.hidden = false; mCalRow.hidden = false; };
  const hideTime = () => { mTime.value = ''; mTimeRow.hidden = true; mCalRow.hidden = true; };
  document.getElementById('mClearTime').addEventListener('click', () => { mTime.value = ''; });
  document.querySelectorAll('input[name=kindchoice]').forEach(r => {
    r.addEventListener('change', () => { if (r.checked) (r.value === 'event' ? showTime(mTime.value) : hideTime()); });
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
    if (date) showDate(date); else hideDate();
    modal.classList.add('open');
    setTimeout(() => mText.focus(), 30);
  };

  // EDIT mode — from tapping an item in the day panel.
  const openEdit = (id, kind, text, date, time, cal) => {
    mHeading.textContent = 'Edit ' + kind;
    mAction.value = 'edit_item';
    mId.value = id;
    mKind.value = kind;
    mDay.value = date || '';
    mKindRow.hidden = true;                // kind is fixed when editing
    mDelete.hidden = false;
    mOk.textContent = 'Save';
    mText.value = text;
    if (kind === 'event') { mCal.value = cal || newEventCal(); showTime(time); } else { hideTime(); }
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
    if (!confirm('Delete this item?')) return;
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
    for (const it of items) {
      if (it.done && !document.body.classList.contains('show-done')) continue;   // hidden unless "Show Completed"
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
      if (overdue && it.due) {                 // overdue reminder: show its original date in grey
        const od = document.createElement('span');
        od.className = 'origdate';
        od.textContent = new Date(it.due + 'T00:00:00').toLocaleDateString([], { month: 'short', day: 'numeric' });
        row.appendChild(od);
      }
      // Someone else's item: say whose, and don't offer to edit or delete it here.
      if (it.owner) {
        row.classList.add('shared');
        chev.textContent = '';
        const who = document.createElement('span');
        // Their name, capitalised here rather than read from PARTNER — that const is
        // declared further down, and this runs during the first render.
        who.className = 'owner'; who.textContent = it.owner.charAt(0).toUpperCase() + it.owner.slice(1);
        row.appendChild(who);
      }
      row.appendChild(chev);
      if (!it.owner) {
        const del = document.createElement('button');
        del.className = 'dp-del'; del.textContent = '×'; del.title = 'Delete';
        del.addEventListener('click', (ev) => {
          ev.stopPropagation();
          document.getElementById('diKind').value = it.kind;
          document.getElementById('diId').value = it.id;
          document.getElementById('diDay').value = date;
          document.getElementById('delItemForm').submit();
        });
        row.appendChild(del);
        row.addEventListener('click', () => {
          if (it.kind === 'note') { location.href = '/notes/?id=' + encodeURIComponent(it.id); return; }   // notes open in the Notes tab
          openEdit(it.id, it.kind, it.text, date, it.time || '', it.cal || '');
        });
      }
      dpList.appendChild(row);
    }
  };

  const selectDay = date => {
    selected = date;
    document.querySelectorAll('.cell.selected').forEach(c => c.classList.remove('selected'));
    const cell = document.querySelector('.cell[data-date="' + date + '"]');
    if (cell) cell.classList.add('selected');
    renderPanel(date);
  };

  document.querySelectorAll('.cell[data-date]').forEach(cell => {
    cell.addEventListener('click', () => selectDay(cell.dataset.date));
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

  // Day-panel Edit mode: reveal × to quick-delete items.
  // Always starts off — opening the app or switching tabs never lands you in edit mode.
  // A delete redirects back with ?edit=1 so quick-deleting several things in a row works.
  const dpEdit = document.getElementById('dpEdit');
  if (new URLSearchParams(location.search).get('edit') === '1') {
    document.body.classList.add('editing');
    const u = new URL(location.href); u.searchParams.delete('edit');
    history.replaceState(null, '', u);
  }
  dpEdit.textContent = document.body.classList.contains('editing') ? 'Done' : 'Edit';
  dpEdit.addEventListener('click', () => {
    const on = document.body.classList.toggle('editing');
    if (!on) document.body.classList.remove('can-undo');   // tapping Done clears the Undo button
    dpEdit.textContent = on ? 'Done' : 'Edit';
  });
  // Undo shows only right after a delete (server redirects back with ?undo=1).
  if (new URLSearchParams(location.search).get('undo') === '1') {
    document.body.classList.add('can-undo');
    const u = new URL(location.href); u.searchParams.delete('undo');
    history.replaceState(null, '', u);
  }
  document.getElementById('dpUndo').addEventListener('click', () => {
    document.getElementById('uiDay').value = selected || '';
    document.getElementById('undoItemForm').submit();
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
  const FOLDERS = <?= json_encode(array_values($remFolders), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  const PARTNER = <?= json_encode($partner ? share_name($partner) : null) ?>;
  const SHARED_FOLDERS = <?= json_encode(array_values($sharedFolders), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  // id/name/colour only — enough to offer them as members of a set.
  const SHARED_CALS = <?= json_encode(array_map(fn($c) => ['id' => $c['id'], 'name' => $c['name'], 'color' => $c['color'] ?? CAL_COLORS[0]], $sharedCals), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  let SHARES = <?= json_encode($myShares, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  let HIDDEN = <?= json_encode(array_values($hidFolders), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  let HIDDEN_SHARED = <?= json_encode(array_values($hidShared), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  let calDirty = false;          // something changed -> reload on close so dots/colours catch up

  const calModal = document.getElementById('calModal');
  const setModal = document.getElementById('setModal');
  const calRows  = document.getElementById('calRows');
  const setRows  = document.getElementById('setRows');
  const swatchPop= document.getElementById('swatchPop');
  const isSet    = c => c.type === 'set';
  const onlyCals = () => CALS.filter(c => !isSet(c));
  const onlySets = () => CALS.filter(isSet);

  // Every change posts here and gets the whole list back, so the UI never guesses.
  const calApi = (action, extra) => {
    const body = new URLSearchParams(Object.assign({ csrf: CSRF, action }, extra || {}));
    return fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body })
      .then(r => r.json())
      .then(j => {
        if (!j) return;
        if (j.default !== undefined) { DEFAULT_CAL = j.default; }
        if (j.list)   { CALS = j.list; calDirty = true; renderCals(); renderSets(); renderDefault(); if (PARTNER) renderShare(); }
        if (j.hidden) { HIDDEN = j.hidden; HIDDEN_SHARED = j.hiddenShared || []; calDirty = true; renderFolders(); }
        if (j.shares) { SHARES = j.shares; calDirty = true; renderShare(); }
      })
      .catch(() => location.reload());
  };

  function renderCals() {
    calRows.innerHTML = '';
    const cals = onlyCals();
    cals.forEach(c => {
      const li = document.createElement('li');
      li.dataset.id = c.id;

      const handle = document.createElement('span');
      handle.className = 'chandle'; handle.textContent = '☰'; handle.title = 'Drag to reorder';

      const sw = document.createElement('button');
      sw.type = 'button'; sw.className = 'cswatch'; sw.style.background = c.color; sw.title = 'Choose colour';
      sw.addEventListener('click', e => { e.stopPropagation(); openSwatches(sw, c.id); });

      const name = document.createElement('span');
      name.className = 'cname'; name.textContent = c.name;

      li.append(handle, sw, name);
      if (cals.length > 1) {                 // never leave the user with no calendar
        const del = document.createElement('button');
        del.type = 'button'; del.className = 'cdel'; del.textContent = '×'; del.title = 'Delete calendar';
        del.addEventListener('click', () => {
          if (confirm('Delete "' + c.name + '"? Its events move to ' + cals[0].name + '.')) calApi('cal_delete', { id: c.id });
        });
        li.appendChild(del);
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

  function renderSets() {
    setRows.innerHTML = '';
    const sets = onlySets();
    if (!sets.length) {
      const p = document.createElement('li');
      p.className = 'calempty'; p.style.background = 'none'; p.style.border = 'none';
      p.textContent = 'No sets yet. A set shows several calendars at once.';
      setRows.appendChild(p);
      return;
    }
    sets.forEach(s => {
      const li = document.createElement('li');
      li.className = 'setrow'; li.dataset.id = s.id;
      const name = document.createElement('span');
      name.className = 'cname'; name.textContent = s.name;
      const count = document.createElement('span');
      count.className = 'ccount';
      const n = (s.cals || []).length;
      count.textContent = n + (n === 1 ? ' calendar' : ' calendars');
      const del = document.createElement('button');
      del.type = 'button'; del.className = 'cdel'; del.textContent = '×'; del.title = 'Delete set';
      del.addEventListener('click', e => {
        e.stopPropagation();
        if (confirm('Delete the set "' + s.name + '"?')) calApi('set_delete', { id: s.id });
      });
      li.append(name, count, del);
      li.addEventListener('click', () => openSetPicker(s));
      setRows.appendChild(li);
    });
  }

  // A checkbox row: ticked/unticked calls back with the new state.
  function checkRow(label, checked, onChange) {
    const li = document.createElement('li');
    const cb = document.createElement('input');
    cb.type = 'checkbox'; cb.className = 'cmember'; cb.checked = checked;
    cb.addEventListener('change', () => onChange(cb.checked));
    const name = document.createElement('span');
    name.className = 'cname'; name.textContent = label;
    li.append(cb, name);
    li.addEventListener('click', e => { if (e.target !== cb) { cb.checked = !cb.checked; cb.dispatchEvent(new Event('change')); } });
    return li;
  }
  function subHead(text) {
    const li = document.createElement('li');
    li.className = 'calempty'; li.style.background = 'none'; li.style.border = 'none';
    li.textContent = text;
    return li;
  }

  // Reminder folders: ticked means that folder's reminders appear on the calendar.
  const folderRows = document.getElementById('folderRows');
  function renderFolders() {
    folderRows.innerHTML = '';
    FOLDERS.forEach(f => folderRows.appendChild(
      checkRow(f, HIDDEN.indexOf(f) === -1, on => calApi('folder_vis', { name: f, show: on ? 1 : 0 }))));
    if (SHARED_FOLDERS.length) {
      folderRows.appendChild(subHead(PARTNER + '’s folders'));
      SHARED_FOLDERS.forEach(f => folderRows.appendChild(
        checkRow(f, HIDDEN_SHARED.indexOf(f) === -1,
                 on => calApi('folder_vis', { name: f, shared: 1, show: on ? 1 : 0 }))));
    }
  }

  // What I let the other person see. Ticking posts straight away.
  function renderShare() {
    if (!PARTNER) return;
    const cals = document.getElementById('shareCals');
    const fols = document.getElementById('shareFolders');
    cals.innerHTML = ''; fols.innerHTML = '';
    onlyCals().forEach(c => cals.appendChild(
      checkRow(c.name, (SHARES.calendars || []).indexOf(c.id) !== -1,
               on => calApi('share_set', { kind: 'calendar', key: c.id, on: on ? 1 : 0 }))));
    FOLDERS.forEach(f => fols.appendChild(
      checkRow(f, (SHARES.folders || []).indexOf(f) !== -1,
               on => calApi('share_set', { kind: 'folder', key: f, on: on ? 1 : 0 }))));
  }
  if (PARTNER) {
    const shareModal = document.getElementById('shareModal');
    document.getElementById('shareBtn').addEventListener('click', () => { renderShare(); shareModal.classList.add('open'); });
    document.getElementById('shareDone').addEventListener('click', () => shareModal.classList.remove('open'));
    shareModal.addEventListener('click', e => { if (e.target === shareModal) shareModal.classList.remove('open'); });
  }

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

  // Which calendars are in this set.
  let editingSet = null;
  function openSetPicker(s) {
    editingSet = s.id;
    document.getElementById('setHeading').textContent = s.name;
    const box = document.getElementById('setMembers');
    box.innerHTML = '';
    const memberRow = c => {
      const li = document.createElement('li');
      const cb = document.createElement('input');
      cb.type = 'checkbox'; cb.className = 'cmember'; cb.value = c.id;
      cb.checked = (s.cals || []).indexOf(c.id) !== -1;
      const sw = document.createElement('span');
      sw.className = 'cswatch'; sw.style.background = c.color; sw.style.cursor = 'default';
      const name = document.createElement('span');
      name.className = 'cname'; name.textContent = c.name;
      li.append(cb, sw, name);
      li.addEventListener('click', e => { if (e.target !== cb) cb.checked = !cb.checked; });
      return li;
    };
    onlyCals().forEach(c => box.appendChild(memberRow(c)));
    // The other person's calendars can go in a set too — they stay theirs, we just show them.
    if (SHARED_CALS.length) {
      box.appendChild(subHead(PARTNER + '’s calendars'));
      SHARED_CALS.forEach(c => box.appendChild(memberRow(c)));
    }
    setModal.classList.add('open');
  }
  document.getElementById('setSave').addEventListener('click', () => {
    const ids = [...document.querySelectorAll('#setMembers .cmember')].filter(c => c.checked).map(c => c.value);
    calApi('set_members', { id: editingSet, cals: JSON.stringify(ids) }).then(() => setModal.classList.remove('open'));
  });
  document.getElementById('setCancel').addEventListener('click', () => setModal.classList.remove('open'));
  setModal.addEventListener('click', e => { if (e.target === setModal) setModal.classList.remove('open'); });

  // Drag to reorder calendars (pointer events, so it works by touch).
  let dragCal = null;
  calRows.addEventListener('pointerdown', e => {
    const h = e.target.closest('.chandle'); if (!h) return;
    e.preventDefault();
    dragCal = h.closest('li'); dragCal.classList.add('dragging');
    try { h.setPointerCapture(e.pointerId); } catch (_) {}
  });
  calRows.addEventListener('pointermove', e => {
    if (!dragCal) return;
    const el   = document.elementFromPoint(e.clientX, e.clientY);
    const over = el && el.closest('#calRows li');
    if (over && over !== dragCal) {
      const r = over.getBoundingClientRect();
      calRows.insertBefore(dragCal, (e.clientY < r.top + r.height / 2) ? over : over.nextSibling);
    }
  });
  const endCalDrag = () => {
    if (!dragCal) return;
    dragCal.classList.remove('dragging'); dragCal = null;
    calApi('cal_reorder', { order: JSON.stringify([...calRows.querySelectorAll('li')].map(li => li.dataset.id)) });
  };
  calRows.addEventListener('pointerup', endCalDrag);
  calRows.addEventListener('pointercancel', endCalDrag);

  const addCal = () => {
    const i = document.getElementById('calName');
    if (i.value.trim()) { calApi('cal_add', { name: i.value.trim() }); i.value = ''; }
  };
  const addSet = () => {
    const i = document.getElementById('setName');
    if (i.value.trim()) { calApi('set_add', { name: i.value.trim() }); i.value = ''; }
  };
  document.getElementById('calAdd').addEventListener('click', addCal);
  document.getElementById('setAdd').addEventListener('click', addSet);
  document.getElementById('calName').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); addCal(); } });
  document.getElementById('setName').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); addSet(); } });

  document.getElementById('calMgr').addEventListener('click', () => {
    renderCals(); renderSets(); renderDefault(); renderFolders();
    calModal.classList.add('open');
  });
  const closeCalModal = () => {
    calModal.classList.remove('open');
    if (calDirty) location.reload();   // colours, names and the picker all live in the page
  };
  document.getElementById('calDone').addEventListener('click', closeCalModal);
  calModal.addEventListener('click', e => { if (e.target === calModal) closeCalModal(); });

  // Picking the visible calendar / set.
  document.getElementById('calSel').addEventListener('change', function () {
    const u = new URL(location.href);
    u.searchParams.set('cal', this.value);
    if (selected) u.searchParams.set('day', selected);
    location.href = u.toString();
  });

  document.getElementById('mCancel').addEventListener('click', closeModal);
  modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });
  const shareModalEl = document.getElementById('shareModal');
  const anyModalOpen = () => modal.classList.contains('open')
    || calModal.classList.contains('open') || setModal.classList.contains('open')
    || (shareModalEl && shareModalEl.classList.contains('open'));
  document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    if (shareModalEl && shareModalEl.classList.contains('open')) { shareModalEl.classList.remove('open'); return; }
    if (setModal.classList.contains('open')) { setModal.classList.remove('open'); return; }
    if (calModal.classList.contains('open')) { closeCalModal(); return; }
    closeModal();
  });

  // Left/right arrow keys cycle months (when no modal is open).
  document.addEventListener('keydown', e => {
    if (anyModalOpen() || /^(INPUT|SELECT|TEXTAREA)$/.test(document.activeElement.tagName)) return;
    if (e.key === 'ArrowLeft')  location.href = '?ym=<?= $prev ?>';
    if (e.key === 'ArrowRight') location.href = '?ym=<?= $next ?>';
  });
</script>
<?= chrome_script() ?>
</body>
</html>
