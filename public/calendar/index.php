<?php
// Locate the shared lib/ — local dev (../../lib) or NFSN (/home/protected/lib).
$__libDir = null;
foreach ([__DIR__ . '/../../lib', '/home/protected/lib'] as $__c) {
    if (is_file($__c . '/auth.php')) { $__libDir = $__c; break; }
}
require_once $__libDir . '/auth.php';
require_once $__libDir . '/tabbar.php';
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

    if ($action === 'add_event' && $text !== '') {
        [$etext, $ptime] = parse_time_from_text($text);   // "Dinner 7pm" -> text "Dinner", time 19:00
        $file = user_data_file($cfg['data_dir'], 'events');
        $list = load_json_list($file);
        $list[] = ['id' => bin2hex(random_bytes(6)), 'text' => mb_substr($etext, 0, 500),
                   'date' => $dateOk ? $date : null, 'time' => $timeOk ? $time : $ptime, 'created' => time()];
        save_json_list($file, $list);
    } elseif ($action === 'add_reminder' && $text !== '') {
        $file = user_data_file($cfg['data_dir'], 'reminders');
        $list = load_json_list($file);
        $list[] = ['id' => bin2hex(random_bytes(6)), 'text' => mb_substr($text, 0, 500),
                   'due' => $dateOk ? $date : null, 'done' => false, 'created' => time()];
        save_json_list($file, $list);
    } elseif ($action === 'add_note' && $text !== '') {
        $file = user_data_file($cfg['data_dir'], 'notes');
        $list = load_json_list($file);
        $list[] = ['id' => bin2hex(random_bytes(6)), 'title' => mb_substr($text, 0, 200),
                   'date' => $dateOk ? $date : null, 'body' => '', 'created' => time(), 'updated' => time()];
        save_json_list($file, $list);
    } elseif ($action === 'toggle_reminder' && $id !== '') {
        $file = user_data_file($cfg['data_dir'], 'reminders');
        $list = load_json_list($file);
        foreach ($list as &$it) {
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
                if ($kind === 'event') { $it['time'] = $timeOk ? $time : null; }
                if ($kind === 'note')  { $it['updated'] = time(); }
                break;
            }
        }
        unset($it);
        save_json_list($file, $list);
    } elseif ($action === 'delete_item' && ($spec = kind_spec($kind)) && $id !== '') {
        $file = user_data_file($cfg['data_dir'], $spec['base']);
        $list = array_values(array_filter(load_json_list($file), fn($it) => ($it['id'] ?? '') !== $id));
        save_json_list($file, $list);
    }

    $loc = _self_path() . '?ym=' . $ym;
    if ($retDay !== '') { $loc .= '&day=' . $retDay; }
    header('Location: ' . $loc);
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

// --- Sync: gather this user's dated reminders + notes for the visible month ---
$monthPrefix = sprintf('%04d-%02d', $year, $month);
$byDay = [];   // 'YYYY-MM-DD' => [ ['kind'=>'reminder'|'note', 'text'=>..., 'done'=>bool], ... ]

foreach (load_json_list(user_data_file($cfg['data_dir'], 'reminders')) as $r) {
    if (empty($r['due'])) { continue; }
    $done = !empty($r['done']);                                    // done are hidden until "Show All"
    $eff  = (!$done && $r['due'] < $todayYmd) ? $todayYmd : $r['due'];   // overdue rolls onto today; done/future stay
    if (strpos($eff, $monthPrefix) === 0) {
        $byDay[$eff][] = ['kind' => 'reminder', 'id' => $r['id'] ?? '', 'text' => $r['text'] ?? '',
                          'done' => $done, 'rolled' => ($eff !== $r['due']), 'due' => $r['due']];
    }
}
$evList = load_json_list(user_data_file($cfg['data_dir'], 'events'));
usort($evList, fn($a, $b) => ((($a['time'] ?? '') ?: '99:99')) <=> ((($b['time'] ?? '') ?: '99:99')));
foreach ($evList as $ev) {
    if (!empty($ev['date']) && strpos($ev['date'], $monthPrefix) === 0) {
        $byDay[$ev['date']][] = ['kind' => 'event', 'id' => $ev['id'] ?? '', 'text' => $ev['text'] ?? '',
                                 'time' => $ev['time'] ?? '', 'done' => false];
    }
}
foreach (load_json_list(user_data_file($cfg['data_dir'], 'notes')) as $n) {
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
  <link rel="manifest" href="/reminders/manifest.webmanifest">
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
    header .titlebar { display: flex; align-items: baseline; gap: 0.7rem; }
    header .widgetlink {
      color: #38bdf8; text-decoration: none; font-size: 0.78rem;
      border: 1px solid #24506a; border-radius: 999px; padding: 0.12rem 0.6rem;
    }
    header .widgetlink:hover { background: #10222e; color: #7dd3fc; }
    body.show-done header #calShowAll { color: #34d399; border-color: #34d399; font-weight: 700; }
    header nav a { color: #888; text-decoration: none; margin-left: 1rem; font-size: 0.85rem; }
    header nav a:hover { color: #fff; }
    header nav .who {
      color: #34d399; font-size: 0.8rem; border: 1px solid #2a4a3d;
      border-radius: 999px; padding: 0.15rem 0.6rem;
    }

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
    .dp-head .dp-date { font-size: 1.05rem; font-weight: 600; flex: 1; }
    .dp-head .dp-edit { background: none; border: 1px solid #333; color: #ccc; border-radius: 999px;
      padding: 0.35rem 0.9rem; font-size: 0.9rem; cursor: pointer; }
    .dp-head .dp-edit:hover { border-color: #888; color: #fff; }
    body.editing .dp-head .dp-edit { background: #34d399; border-color: #34d399; color: #06251b; font-weight: 700; }
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
    .dp-item .txt { flex: 1; font-size: 0.95rem; word-break: break-word; }
    .dp-item .origdate { font-size: 0.72rem; color: #666; white-space: nowrap; }
    .dp-item .evtime { font-size: 0.75rem; color: #7dd3fc; font-weight: 600; white-space: nowrap; }
    .dp-item.done .txt { color: #666; text-decoration: line-through; }
    .dp-item .chev { color: #555; font-size: 0.9rem; }
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
<?= tabbar_styles() ?>
    body { padding-bottom: 0; }   /* panel handles the tab-bar clearance */
  </style>
</head>
<body>
<div class="cal-top">
 <div class="wrap">
  <header>
    <div class="titlebar">
      <h1>Calendar</h1>
      <a class="widgetlink" href="/calendar/feed.php">Widget</a>
      <button type="button" id="calShowAll" class="widgetlink" style="background:none;cursor:pointer;">Show All</button>
    </div>
    <nav>
      <span class="who"><?= e(current_user() ?? '') ?></span>
      <a href="/reminders/?logout">Log out</a>
    </nav>
  </header>

  <div class="monthnav">
    <a href="?ym=<?= $prev ?>" title="Previous month">&#8592;</a>
    <span class="label"><?= e($monthName) ?></span>
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
            ?>
            <span class="dot <?= $dcls ?>"></span>
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
    <button class="dp-edit" id="dpEdit" type="button">Edit</button>
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
    <div class="buttons">
      <button type="button" class="del" id="mDelete" hidden>Delete</button>
      <button type="button" class="cancel" id="mCancel">Cancel</button>
      <button type="submit" class="ok" id="mOk">Add</button>
    </div>
  </form>
</div>

<!-- Hidden form to check reminders off directly from the day panel -->
<form id="toggleForm" method="post" action="" style="display:none">
  <input type="hidden" name="csrf" value="<?= $csrf ?>">
  <input type="hidden" name="action" value="toggle_reminder">
  <input type="hidden" name="id" id="tgId" value="">
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
  const showTime = (val) => { mTime.value = val || ''; mTimeRow.hidden = false; };
  const hideTime = () => { mTime.value = ''; mTimeRow.hidden = true; };
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
    showTime('');
    if (date) showDate(date); else hideDate();
    modal.classList.add('open');
    setTimeout(() => mText.focus(), 30);
  };

  // EDIT mode — from tapping an item in the day panel.
  const openEdit = (id, kind, text, date, time) => {
    mHeading.textContent = 'Edit ' + kind;
    mAction.value = 'edit_item';
    mId.value = id;
    mKind.value = kind;
    mDay.value = date || '';
    mKindRow.hidden = true;                // kind is fixed when editing
    mDelete.hidden = false;
    mOk.textContent = 'Save';
    mText.value = text;
    (kind === 'event') ? showTime(time) : hideTime();
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
    return d.toLocaleDateString([], { weekday: 'long', month: 'long', day: 'numeric' });
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
      if (it.done && !document.body.classList.contains('show-done')) continue;   // hidden unless "Show All"
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
          toggleForm.submit();
        });
        row.appendChild(cb);
      }
      // Only notes get a marker ("N"). Reminders have the checkbox; events show nothing.
      let tag = null;
      if (it.kind === 'note') {
        tag = document.createElement('span');
        tag.className = 'tag note';
        tag.textContent = 'N';
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
      row.appendChild(chev);
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
      row.addEventListener('click', () => openEdit(it.id, it.kind, it.text, date, it.time || ''));
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
  const dpEdit = document.getElementById('dpEdit');
  if (localStorage.getItem('calEditing') === '1') document.body.classList.add('editing');
  dpEdit.textContent = document.body.classList.contains('editing') ? 'Done' : 'Edit';
  dpEdit.addEventListener('click', () => {
    const on = document.body.classList.toggle('editing');
    dpEdit.textContent = on ? 'Done' : 'Edit';
    localStorage.setItem('calEditing', on ? '1' : '0');
  });

  document.getElementById('mCancel').addEventListener('click', closeModal);
  modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

  // Left/right arrow keys cycle months (when modal closed).
  document.addEventListener('keydown', e => {
    if (modal.classList.contains('open')) return;
    if (e.key === 'ArrowLeft')  location.href = '?ym=<?= $prev ?>';
    if (e.key === 'ArrowRight') location.href = '?ym=<?= $next ?>';
  });
</script>
</body>
</html>
