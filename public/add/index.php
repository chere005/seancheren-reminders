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
    // "Vet 8/3 2pm" -> text "Vet", date Aug 3, time 14:00; no date means today.
    [$ptext, $pdate, $ptime] = parse_when_from_text($text);
    $when  = $pdate ?? $today;
    $flash = '';
    if ($text !== '' && $act === 'add_reminder') {
        $f = user_data_file($cfg['data_dir'], 'reminders');
        $l = store_read($f);
        $l[] = ['id' => bin2hex(random_bytes(6)), 'text' => mb_substr($ptext, 0, 500),
                'due' => $when, 'time' => $ptime, 'done' => false,
                'folder' => folder_fallback('reminders'), 'section' => '', 'created' => time()];
        store_write($f, array_values($l));
        $flash = 'Reminder added';
    } elseif ($text !== '' && $act === 'add_event') {
        $f = user_data_file($cfg['data_dir'], 'events');
        $l = store_read($f);
        $l[] = ['id' => bin2hex(random_bytes(6)), 'text' => mb_substr($ptext, 0, 500),
                'date' => $when, 'time' => $ptime, 'created' => time()];
        store_write($f, array_values($l));
        $flash = 'Event added' . ($ptime ? ' · ' . date('g:ia', strtotime($ptime)) : '');
    } elseif ($text !== '' && $act === 'add_note') {
        $f = user_data_file($cfg['data_dir'], 'notes');
        $l = store_read($f);
        $l[] = ['id' => bin2hex(random_bytes(6)), 'title' => mb_substr($ptext, 0, 200),
                'body' => '', 'folder' => FOLDER_DEFAULT, 'section' => '',
                'date' => $pdate ?? '', 'created' => time()];
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
    .hint { color: #666; font-size: 0.78rem; margin-top: 0.7rem; }
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
  </form>
  <p class="hint">A date like “8/3” and a time like “2pm” are read from the text. No date means today.</p>
</div>
<?php render_tabbar('add'); ?>
</body>
</html>
