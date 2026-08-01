<?php
// Quick-add bar — opened by the iOS widget. Add a reminder or event for today, fast.
// A page served under /test/ (the sandbox mirror) loads lib-test/ instead of lib/, so
// the test instance stays isolated from production's code, config and data. Cross-app
// links carry the same /test prefix via suite_base(); _self_path() redirects already
// stay put. Keep this preamble identical when adding a page.
$__test = strpos(__DIR__, '/test/') !== false
       || strncmp($_SERVER['REQUEST_URI'] ?? '', '/test/', 6) === 0;
$__libDir = null;
$__cands  = $__test
    ? [__DIR__ . '/../../../lib-test', '/home/protected/lib-test']
    : [__DIR__ . '/../../lib',         '/home/protected/lib'];
foreach ($__cands as $__c) {
    if (is_file($__c . '/auth.php')) { $__libDir = $__c; break; }
}
require_once $__libDir . '/auth.php';
require_once $__libDir . '/folders.php';   // folder_fallback() for where a quick add lands
require_login('Quick add');

$cfg   = app_config();
$today = date('Y-m-d');
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
$csrf  = htmlspecialchars($_SESSION['csrf'], ENT_QUOTES);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
    $text = trim((string) ($_POST['text'] ?? ''));
    $act  = (string) ($_POST['action'] ?? '');
    $flash = '';
    // Ticking one reminder off from the widget's checkbox. The write happens here, in
    // the signed-in session with a CSRF token — the widget's token stays read-only.
    if ($act === 'tick') {
        $id = (string) ($_POST['id'] ?? '');
        $f  = user_data_file($cfg['data_dir'], 'reminders');
        $l  = store_read($f);
        foreach ($l as &$r) {
            if (($r['type'] ?? '') === 'section' || ($r['id'] ?? '') !== $id) { continue; }
            $rep = repeat_get($r);
            if ($rep !== null && empty($r['done']) && !empty($r['due'])) {
                // A repeat never finishes: ticking rolls it to the next date instead.
                $r['due'] = repeat_next($r['due'], $rep, max($r['due'], $today));
                $flash = 'Moved to ' . date('D, M j', strtotime($r['due']));
            } else {
                $r['done'] = true;
                $flash = 'Done · ' . mb_substr((string) ($r['text'] ?? ''), 0, 60);
            }
            break;
        }
        unset($r);
        store_write($f, array_values($l));
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?ok=' . rawurlencode($flash));
        exit;
    }
    // "Vet 8/3 2pm" -> text "Vet", date 2026-08-03, time 14:00; no date means today.
    [$ptext, $pdate, $ptime] = parse_when_from_text($text);
    $when = $pdate ?? $today;
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
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?ok=' . rawurlencode($flash));
    exit;
}
$flash = isset($_GET['ok']) ? (string) $_GET['ok'] : '';
function e(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES); }

// ?tick=<id> — the widget's checkbox landed here. Show that one reminder with a single
// button, so the actual change is still a POST with a token rather than a bare GET.
$tickId = isset($_GET['tick']) ? (string) $_GET['tick'] : '';
$tick   = null;
if ($tickId !== '') {
    foreach (store_read(user_data_file($cfg['data_dir'], 'reminders')) as $r) {
        if (($r['type'] ?? '') !== 'section' && ($r['id'] ?? '') === $tickId) { $tick = $r; break; }
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>Quick add</title>
  <meta name="theme-color" content="#111111">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: system-ui, sans-serif; background: #111; color: #eee; min-height: 100vh;
      display: flex; align-items: center; justify-content: center; padding: 1.5rem 1rem; }
    .wrap { width: 100%; max-width: 460px; }
    h1 { font-size: 1.5rem; margin-bottom: 0.25rem; }
    h1 .day { font-size: 0.85rem; color: #888; font-weight: 400; margin-left: 0.4rem; }
    .flash { background: #14251f; border: 1px solid var(--accent); color: var(--accent); border-radius: 8px;
      padding: 0.55rem 0.8rem; font-size: 0.9rem; margin: 0.75rem 0; }
    .bar input[type=text] { width: 100%; padding: 0.85rem 0.9rem; background: #1a1a1a; border: 1px solid #333;
      border-radius: 10px; color: #eee; font-size: 1.1rem; margin: 1rem 0; }
    .bar input:focus { outline: none; border-color: #888; }
    .btns { display: flex; gap: 0.75rem; }
    .qb { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 0.25rem;
      padding: 1rem; border: 1px solid #333; border-radius: 12px; font-size: 0.95rem; font-weight: 600;
      cursor: pointer; background: #1a1a1a; }
    .qb :first-child { font-size: 1.7rem; line-height: 1; }
    .qb.rem { color: var(--accent); border-color: #2a4a3d; }
    .qb.rem:hover { background: #14251f; }
    .qb.evt { color: #7dd3fc; border-color: #24506a; }
    .qb.evt:hover { background: #0f2734; }
    .open { display: inline-block; margin-top: 1.25rem; color: #888; text-decoration: none; font-size: 0.9rem; }
    .open:hover { color: #fff; }
    .hint { color: #666; font-size: 0.78rem; margin-top: 0.6rem; }
    /* The one reminder the widget's checkbox was pointing at. */
    .tickwhat { font-size: 1.15rem; color: #ddd; margin: 0.9rem 0 1.25rem; word-break: break-word; }
    a.qb { text-decoration: none; }
  </style>
</head>
<body>
<div class="wrap">
  <?php if ($tick !== null && empty($tick['done'])): ?>
    <h1>Mark done?</h1>
    <p class="tickwhat"><?= e((string) ($tick['text'] ?? '')) ?></p>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="tick">
      <input type="hidden" name="id" value="<?= e($tickId) ?>">
      <div class="btns">
        <button type="submit" class="qb rem" title="Mark done"><span>&#10003;</span><span>Done</span></button>
        <a class="qb evt" href="<?= e(strtok($_SERVER['REQUEST_URI'], '?')) ?>"><span>&#8592;</span><span>Not yet</span></a>
      </div>
    </form>
    <a class="open" href="<?= suite_base() ?>/calendar/">Open calendar &rsaquo;</a>
  </div>
  </body></html>
  <?php exit; endif; ?>
  <h1>Quick add <span class="day"><?= e(date('D, M j')) ?></span></h1>
  <?php if ($flash !== ''): ?><div class="flash"><?= e($flash) ?></div><?php endif; ?>
  <form method="post" class="bar">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="text" name="text" placeholder="e.g. Dentist 2pm…" autocomplete="off" autofocus required>
    <div class="btns">
      <button type="submit" name="action" value="add_reminder" class="qb rem" title="Add reminder"><span>&#10003;</span><span>Reminder</span></button>
      <button type="submit" name="action" value="add_event" class="qb evt" title="Add event"><span>&#128197;</span><span>Event</span></button>
    </div>
  </form>
  <p class="hint">Times like “2pm” become the event’s time. Added for today.</p>
  <a class="open" href="<?= suite_base() ?>/calendar/">Open calendar &rsaquo;</a>
</div>
</body>
</html>
