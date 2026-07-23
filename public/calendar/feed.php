<?php
/**
 * Calendar feed + iOS widget setup.
 *
 *   GET ?token=XYZ   -> JSON of upcoming items   (used by the Scriptable widget; no login)
 *   GET  (logged in) -> setup page: your token, the feed URL, and a ready-to-paste script
 *
 * The token lives in /home/protected/data/token-<user>.json (not web-served, revocable
 * by deleting that file). Anyone with the token URL can read that user's upcoming items.
 */

$__libDir = null;
foreach ([__DIR__ . '/../../lib', '/home/protected/lib'] as $__c) {
    if (is_file($__c . '/auth.php')) { $__libDir = $__c; break; }
}
require_once $__libDir . '/auth.php';

$cfg     = app_config();
$dataDir = rtrim($cfg['data_dir'], '/');

function safe_user(string $u): string { return preg_replace('/[^A-Za-z0-9_-]/', '_', $u); }
function ufile(string $dir, string $user, string $base): string { return "$dir/{$base}-" . safe_user($user) . ".json"; }
function loadlist(string $f): array { return store_read($f); }

/** Upcoming reminders (open, overdue rolled to today) + events + dated notes, next 21 days. */
function build_feed(string $dir, string $user): array {
    $today = date('Y-m-d');
    $until = date('Y-m-d', strtotime('+21 days'));
    $items = [];

    foreach (loadlist(ufile($dir, $user, 'reminders')) as $r) {
        if (($r['type'] ?? '') === 'section' || empty($r['due']) || !empty($r['done'])) { continue; }
        $eff = ($r['due'] < $today) ? $today : $r['due'];         // overdue rolls onto today
        if ($eff >= $today && $eff <= $until) {
            $items[] = ['date' => $eff, 'kind' => 'reminder', 'text' => (string) ($r['text'] ?? ''), 'overdue' => ($eff !== $r['due'])];
        }
    }
    foreach (loadlist(ufile($dir, $user, 'events')) as $e) {
        $d = (string) ($e['date'] ?? '');
        if ($d >= $today && $d <= $until) { $items[] = ['date' => $d, 'kind' => 'event', 'text' => (string) ($e['text'] ?? ''), 'time' => (string) ($e['time'] ?? ''), 'overdue' => false]; }
    }
    foreach (loadlist(ufile($dir, $user, 'notes')) as $n) {
        if (($n['type'] ?? '') === 'section') { continue; }
        $d = (string) ($n['date'] ?? '');
        if ($d >= $today && $d <= $until) { $items[] = ['date' => $d, 'kind' => 'note', 'text' => (string) ($n['title'] ?? 'Untitled note'), 'overdue' => false]; }
    }
    usort($items, fn($a, $b) => [$a['date'], $a['kind']] <=> [$b['date'], $b['kind']]);
    return ['today' => $today, 'items' => array_slice($items, 0, 12)];
}

// ---------- Mode 1: token feed (no login) ----------
if (isset($_GET['token'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $token = (string) $_GET['token'];
    $owner = null;
    foreach (glob("$dataDir/token-*.json") as $f) {
        $t = store_read($f);
        if (!empty($t['token']) && hash_equals((string) $t['token'], $token)) {
            $owner = preg_replace('/^token-(.*)\.json$/', '$1', basename($f));
            break;
        }
    }
    if ($owner === null) { http_response_code(403); echo json_encode(['error' => 'invalid token']); exit; }
    echo json_encode(build_feed($dataDir, $owner), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- Mode 2: setup page (login required) ----------
require_login('Calendar widget');
$user      = current_user() ?? 'default';
$tokenFile = "$dataDir/token-" . safe_user($user) . ".json";
$t         = loadlist($tokenFile);
if (empty($t['token'])) {
    if (!is_dir($dataDir)) { mkdir($dataDir, 0700, true); }
    $t = ['token' => bin2hex(random_bytes(16))];
    store_write($tokenFile, $t);
}
$token   = $t['token'];
$feedUrl = 'https://seancheren.com/calendar/feed.php?token=' . $token;

$script = str_replace('__FEED_URL__', $feedUrl, <<<'JS'
// seancheren calendar — Scriptable widget
const FEED = "__FEED_URL__";
const OPEN = "https://seancheren.com/calendar/quick.php";
const COLORS = { reminder: "#34d399", event: "#38bdf8", note: "#8b6ef0" };

let data;
try { data = await new Request(FEED).loadJSON(); }
catch (e) { data = { items: [], error: true }; }

const w = new ListWidget();
w.backgroundColor = new Color("#111111");
w.url = OPEN;                     // tap the widget -> open the calendar
w.setPadding(12, 14, 12, 14);

const head = w.addStack();
const title = head.addText("Calendar");
title.font = Font.boldSystemFont(15);
title.textColor = Color.white();
head.addSpacer();
const dl = head.addText(new Date().toLocaleDateString([], { month: "short", day: "numeric" }));
dl.font = Font.mediumSystemFont(13);
dl.textColor = new Color("#8a8a8a");
w.addSpacer(8);

const items = data.items || [];
if (data.error) {
  const t = w.addText("Couldn't load."); t.textColor = new Color("#ff6666"); t.font = Font.systemFont(12);
} else if (!items.length) {
  const t = w.addText("Nothing coming up."); t.textColor = new Color("#777777"); t.font = Font.systemFont(12);
} else {
  const max = config.widgetFamily === "large" ? 8 : (config.widgetFamily === "small" ? 3 : 5);
  for (const it of items.slice(0, max)) {
    const row = w.addStack();
    row.centerAlignContent();
    const dot = row.addText("●");
    dot.textColor = new Color(COLORS[it.kind] || "#888888");
    dot.font = Font.systemFont(9);
    row.addSpacer(6);
    const prefix = (it.kind === "event" && it.time) ? fmtTime(it.time) + "  " : "";
    const label = row.addText(prefix + (it.text || ""));
    label.font = Font.systemFont(12);
    label.textColor = it.overdue ? new Color("#ff7755") : new Color("#eeeeee");
    label.lineLimit = 1;
    row.addSpacer();
    const d = row.addText(shortDate(it.date, data.today));
    d.font = Font.systemFont(11);
    d.textColor = new Color("#777777");
    w.addSpacer(5);
  }
}

if (config.runsInWidget) { Script.setWidget(w); } else { await w.presentMedium(); }
Script.complete();

function shortDate(ymd, today) {
  if (ymd === today) return "today";
  const [y, m, d] = ymd.split("-").map(Number);
  return new Date(y, m - 1, d).toLocaleDateString([], { month: "short", day: "numeric" });
}
JS);
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>Calendar widget setup</title>
  <meta name="theme-color" content="#111111">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: system-ui, sans-serif; background: #111; color: #eee; padding: 1.5rem 1rem; line-height: 1.5; }
    .wrap { max-width: 640px; margin: 0 auto; }
    h1 { font-size: 1.4rem; margin-bottom: 0.25rem; }
    .sub { color: #888; font-size: 0.85rem; margin-bottom: 1.5rem; }
    a { color: #34d399; }
    h2 { font-size: 0.95rem; margin: 1.5rem 0 0.5rem; color: #ddd; }
    ol { margin: 0 0 0 1.1rem; font-size: 0.92rem; color: #ccc; }
    ol li { margin-bottom: 0.35rem; }
    code, .url { font-family: ui-monospace, Menlo, monospace; font-size: 0.82rem; color: #7dd3fc; word-break: break-all; }
    .box { background: #1a1a1a; border: 1px solid #333; border-radius: 8px; padding: 0.75rem; margin-top: 0.5rem; }
    textarea { width: 100%; height: 260px; background: #0e0e0e; color: #cfe; border: 1px solid #333;
      border-radius: 8px; padding: 0.75rem; font-family: ui-monospace, Menlo, monospace; font-size: 0.72rem; line-height: 1.4; }
    button { margin-top: 0.5rem; padding: 0.55rem 1rem; background: #34d399; color: #06251b; border: none;
      border-radius: 6px; font-size: 0.95rem; font-weight: 700; cursor: pointer; }
    .warn { color: #f0b429; font-size: 0.8rem; margin-top: 0.75rem; }
    nav a { color: #888; text-decoration: none; }
  </style>
</head>
<body>
<div class="wrap">
  <nav><a href="/calendar/">&larr; Calendar</a></nav>
  <h1>Calendar widget</h1>
  <div class="sub">A home-screen widget for <strong><?= htmlspecialchars($user, ENT_QUOTES) ?></strong> that shows your agenda and opens the calendar when tapped.</div>

  <h2>1. Install Scriptable</h2>
  <ol><li>Get the free <strong>Scriptable</strong> app from the App Store.</li></ol>

  <h2>2. Add the script</h2>
  <ol>
    <li>Open Scriptable → tap <strong>+</strong> (new script).</li>
    <li>Delete the sample, then <strong>paste everything below</strong>:</li>
  </ol>
  <div class="box">
    <textarea id="script" readonly><?= htmlspecialchars($script, ENT_QUOTES) ?></textarea>
    <button onclick="navigator.clipboard.writeText(document.getElementById('script').value).then(()=>this.textContent='Copied!')">Copy script</button>
  </div>
  <ol start="3">
    <li>Tap the script's title, name it <em>Calendar</em>, and tap <strong>Done</strong>.</li>
  </ol>

  <h2>3. Put it on your home screen</h2>
  <ol>
    <li>Long-press the home screen → <strong>+</strong> → search <strong>Scriptable</strong> → pick a size → <strong>Add Widget</strong>.</li>
    <li>Long-press the new widget → <strong>Edit Widget</strong> → set <em>Script</em> to <strong>Calendar</strong> and <em>When Interacting</em> to <strong>Run Script</strong> (or Open URL).</li>
  </ol>

  <p class="warn">Your feed URL contains a secret token — anyone with it can read your agenda. To revoke it, tell me and I'll reset it (or delete <code>token-<?= htmlspecialchars(safe_user($user), ENT_QUOTES) ?>.json</code> on the server).</p>
  <details style="margin-top:1rem">
    <summary style="color:#888;font-size:0.85rem;cursor:pointer">Show raw feed URL</summary>
    <div class="box"><span class="url"><?= htmlspecialchars($feedUrl, ENT_QUOTES) ?></span></div>
  </details>
</div>
</body>
</html>
