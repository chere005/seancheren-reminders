<?php
/**
 * Quick-add app — the middle "+" tab. For now it's basically the Calendar's add button
 * on its own page: type a line, pick Reminder / Event / Note, and it's filed for today
 * (or whatever date the text names). It reuses the suite's text parser, so "Vet 8/3 2pm"
 * lands on Aug 3 at 2pm. Reminders go to the fallback folder, events to the default
 * calendar, notes to the default note folder — the same defaults the widget's quick-add uses.
 */
$__libDir = null;
foreach ([__DIR__ . '/../../lib', '/home/protected/lib'] as $__c) {
    if (is_file($__c . '/auth.php')) { $__libDir = $__c; break; }
}
require_once $__libDir . '/auth.php';
require_once $__libDir . '/folders.php';
require_once $__libDir . '/chrome.php';
require_once $__libDir . '/tabbar.php';
require_login('Add');

$cfg   = app_config();
$today = date('Y-m-d');
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
$csrf  = htmlspecialchars($_SESSION['csrf'], ENT_QUOTES);

function e(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES); }

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
    $text = trim((string) ($_POST['text'] ?? ''));
    $act  = (string) ($_POST['action'] ?? '');
    // "Vet 8/3 2pm" -> text "Vet", date Aug 3, time 14:00. An explicit date/time field
    // from the dropdowns wins over anything parsed from the text.
    [$ptext, $pdate, $ptime] = parse_when_from_text($text);
    $fDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['date'] ?? '')) ? $_POST['date'] : null;
    $fTime = preg_match('/^\d{2}:\d{2}$/', (string) ($_POST['time'] ?? '')) ? $_POST['time'] : null;
    $date  = $fDate ?? $pdate;                       // may be null: reminders/notes need no date
    $time  = $fTime ?? $ptime;
    $flash = '';
    if ($text !== '' && $act === 'add_reminder') {
        // A reminder has no default date — undated is fine (it just isn't on the calendar).
        $f = user_data_file($cfg['data_dir'], 'reminders');
        $l = store_read($f);
        $l[] = ['id' => bin2hex(random_bytes(6)), 'text' => mb_substr($ptext, 0, 500),
                'due' => $date ?? '', 'time' => $time, 'done' => false,
                'folder' => folder_fallback('reminders'), 'section' => '', 'created' => time()];
        store_write($f, array_values($l));
        $flash = 'Reminder added' . ($date ? ' · ' . date('D, M j', strtotime($date)) : '');
    } elseif ($text !== '' && $act === 'add_event') {
        // An event lives on the calendar, so it does default to today when no date is given.
        $f = user_data_file($cfg['data_dir'], 'events');
        $l = store_read($f);
        $l[] = ['id' => bin2hex(random_bytes(6)), 'text' => mb_substr($ptext, 0, 500),
                'date' => $date ?? $today, 'time' => $time, 'created' => time()];
        store_write($f, array_values($l));
        $flash = 'Event added' . ($time ? ' · ' . date('g:ia', strtotime($time)) : '');
    } elseif ($text !== '' && $act === 'add_note') {
        // Notes have no default date either.
        $f = user_data_file($cfg['data_dir'], 'notes');
        $l = store_read($f);
        $l[] = ['id' => bin2hex(random_bytes(6)), 'title' => mb_substr($ptext, 0, 200),
                'body' => '', 'folder' => FOLDER_DEFAULT, 'section' => '',
                'date' => $date ?? '', 'created' => time()];
        store_write($f, array_values($l));
        $flash = 'Note added';
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?ok=' . rawurlencode($flash));
    exit;
}
$flash = isset($_GET['ok']) ? (string) $_GET['ok'] : '';
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>Add</title>
  <meta name="theme-color" content="#111111">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black">
  <meta name="apple-mobile-web-app-title" content="Add">
  <style>
    <?= kind_color_css() ?>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root { --accent: #34d399; --accent-ink: #06251b; }
    body { font-family: system-ui, sans-serif; background: #111; color: #eee; min-height: 100vh; }
    <?= tabbar_styles() ?>
    .wrap { width: 100%; max-width: 460px; margin: 0 auto; padding: 3rem 1rem 1rem; }
    h1 { font-size: 1.5rem; margin-bottom: 0.25rem; }
    h1 .day { font-size: 0.85rem; color: #888; font-weight: 400; margin-left: 0.4rem; }
    .flash { background: #14251f; border: 1px solid var(--accent); color: var(--accent); border-radius: 8px;
      padding: 0.55rem 0.8rem; font-size: 0.9rem; margin: 0.75rem 0; }
    .bar input[type=text] { width: 100%; padding: 0.85rem 0.9rem; background: #1a1a1a; border: 1px solid #333;
      border-radius: 10px; color: #eee; font-size: 16px; margin: 1rem 0; }
    .bar input:focus { outline: none; border-color: #888; }
    .btns { display: flex; gap: 0.6rem; }
    .qb { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 0.25rem;
      padding: 0.9rem 0.5rem; border: 1px solid #333; border-radius: 12px; font-size: 0.9rem; font-weight: 600;
      cursor: pointer; background: #1a1a1a; font-family: inherit; }
    .qb :first-child { font-size: 1.6rem; line-height: 1; }
    .qb.rem { color: var(--k-reminder); border-color: #2a4a3d; }
    .qb.evt { color: var(--k-event); border-color: #24506a; }
    .qb.note { color: var(--k-note); border-color: #5a4a24; }
    .dtbtn { margin-top: 0.9rem; padding: 0.5rem 0.9rem; background: none; border: 1px solid #333;
      color: #ccc; border-radius: 999px; font-size: 0.9rem; font-family: inherit; cursor: pointer; }
    .dtbtn:hover { border-color: #888; color: #fff; }
    .dtrow[hidden] { display: none; }
    .dtrow { display: flex; gap: 0.75rem; margin-top: 0.9rem; }
    .dtrow label { flex: 1; display: flex; flex-direction: column; gap: 0.3rem; font-size: 0.78rem; color: #999; }
    .dtrow input { padding: 0.6rem 0.7rem; background: #1a1a1a; border: 1px solid #333; border-radius: 8px;
      color: #eee; font-size: 16px; font-family: inherit; }
    .dtrow input:focus { outline: none; border-color: #888; }
    .syntax { margin-top: 1.25rem; color: #999; font-size: 0.82rem; }
    .syntax .shead { color: #888; margin-bottom: 0.4rem; }
    .syntax ul { list-style: none; margin: 0 0 0.9rem; padding: 0; }
    .syntax li { padding: 0.2rem 0; }
    .syntax li::before { content: '·'; color: #666; margin-right: 0.5rem; }
    .syntax b { color: #cfcfcf; font-weight: 600; }
  </style>
</head>
<body>
<div class="wrap">
  <h1>Add <span class="day"><?= e(date('D, M j')) ?></span></h1>
  <?php if ($flash !== ''): ?><div class="flash"><?= e($flash) ?></div><?php endif; ?>
  <form method="post" class="bar">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="text" name="text" placeholder="e.g. Dentist 8/3 2pm…" autocomplete="off" autofocus required>
    <div class="btns">
      <button type="submit" name="action" value="add_reminder" class="qb rem" title="Add reminder"><span>&#10003;</span><span>Reminder</span></button>
      <button type="submit" name="action" value="add_event" class="qb evt" title="Add event"><span>&#128197;</span><span>Event</span></button>
      <button type="submit" name="action" value="add_note" class="qb note" title="Add note"><span>&#128221;</span><span>Note</span></button>
    </div>
    <?php // Optional explicit date/time, hidden until asked for; either wins over what the
          // text parses to. An event with no date at all is still filed today. ?>
    <button type="button" class="dtbtn" id="dtToggle">+ Date/Time</button>
    <div class="dtrow" id="dtRow" hidden>
      <label>Date <input type="date" name="date"></label>
      <label>Time <input type="time" name="time"></label>
    </div>
  </form>
  <div class="syntax">
    <p class="shead">You can also type the date and time into the line:</p>
    <ul>
      <li><b>2pm</b> or <b>2:30pm</b> — a time</li>
      <li><b>8/3</b> — a date this year (the next one to come)</li>
      <li><b>8/3/26</b> or <b>8/3/2026</b> — a full date</li>
      <li>e.g. <b>Vet 8/3 2pm</b> → “Vet”, Aug 3, 2:00pm</li>
    </ul>
  </div>
  <script>(function(){
    var b=document.getElementById('dtToggle'), r=document.getElementById('dtRow');
    if(b&&r){ b.addEventListener('click',function(){ r.hidden=!r.hidden; b.hidden=true;
      var d=r.querySelector('input[type=date]'); if(d) d.focus(); }); }
  })();</script>
</div>
<?php render_tabbar('add'); ?>
</body>
</html>
