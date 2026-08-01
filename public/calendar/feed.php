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

$cfg     = app_config();
$dataDir = rtrim($cfg['data_dir'], '/');

function safe_user(string $u): string { return preg_replace('/[^A-Za-z0-9_-]/', '_', $u); }
function ufile(string $dir, string $user, string $base): string { return "$dir/{$base}-" . safe_user($user) . ".json"; }
function loadlist(string $f): array { return store_read($f); }

/** This user's own calendar ids. (A stray legacy "set" row is skipped — see index.php.) */
function feed_cal_ids(string $dir, string $user): array {
    $ids = [];
    foreach (loadlist(ufile($dir, $user, 'calendars')) as $c) {
        if (($c['type'] ?? '') !== 'set') { $ids[] = (string) ($c['id'] ?? ''); }
    }
    return $ids;
}

/**
 * Which calendars and reminder folders the widget should show.
 *
 * $pin is the `cals` parameter baked into the feed URL when the script was made:
 * `all`, or a comma-separated list of calendar ids. It fixes the widget to the
 * calendars that were selected at that moment, so later browsing on the Calendar
 * page doesn't quietly change what the home screen shows. Without it (an older
 * script, or an id that no longer exists) the scope falls back to whatever the
 * page is on now. Reminder folders always follow the page — they aren't pinned.
 *
 * Returns [visible calendar ids or null for all, hidden folder names].
 */
function feed_scope(string $dir, string $user, ?string $pin = null): array {
    $prefs  = loadlist(ufile($dir, $user, 'calprefs'));
    $hidden = array_values(array_filter((array) ($prefs['hidden_folders'] ?? []), 'is_string'));
    $calIds = feed_cal_ids($dir, $user);

    if ($pin !== null) {
        if ($pin === 'all') { return [null, $hidden]; }
        // Only ever narrows, and only to calendars this user still owns.
        $picked = array_values(array_intersect($calIds, explode(',', $pin)));
        if ($picked) { return [$picked, $hidden]; }
    }

    $view = (string) ($prefs['last_cal'] ?? 'all');
    if ($view === 'all' || strncmp($view, 'f:', 2) === 0) { return [null, $hidden]; }
    if (in_array($view, $calIds, true))  { return [[$view], $hidden]; }
    return [null, $hidden];   // stale id — fall back to everything
}

/** How much of a reminder folder the widget shows, matching the calendar page:
 *  'all' (dated + undated riding under today), 'dated' (only dated) or 'none'. Calendar
 *  folder defaults to 'all', others to 'dated'; an old hidden folder reads as 'none'. */
function feed_rf_mode(array $prefs, string $folder): string {
    $m = $prefs['rf_mode'][$folder] ?? null;
    if (in_array($m, ['all', 'dated', 'none'], true)) { return $m; }
    $hidden = (array) ($prefs['hidden_folders'] ?? []);
    if (in_array($folder, $hidden, true)) { return 'none'; }
    return $folder === FOLDER_CALENDAR ? 'all' : 'dated';
}

/** Upcoming reminders (open, overdue rolled to today) + events, next 21 days.
 *  Notes are deliberately left out — the widget is for what's *due*, and a dated note
 *  isn't. They're dropped here rather than only in the script, so a widget still running
 *  an older copy of the script stops showing them too. */
function build_feed(string $dir, string $user, ?string $pin = null): array {
    $today = date('Y-m-d');
    $until = date('Y-m-d', strtotime('+21 days'));
    $items = [];
    [$visibleCals, $hidFolders] = feed_scope($dir, $user, $pin);
    $prefs  = loadlist(ufile($dir, $user, 'calprefs'));
    $defCal = null;

    foreach (reminders_folder_migrate(loadlist(ufile($dir, $user, 'reminders'))) as $r) {
        if (($r['type'] ?? '') === 'section' || !empty($r['done'])) { continue; }
        $mode = feed_rf_mode($prefs, (string) ($r['folder'] ?? ''));
        if ($mode === 'none') { continue; }                            // folder switched off
        // Undated reminders ride along under today only when the folder is set to "All".
        $rides = empty($r['due']) && $mode === 'all';
        if (empty($r['due']) && !$rides) { continue; }
        $eff = $rides ? $today : (($r['due'] < $today) ? $today : $r['due']);
        // Its own (possibly rolled) date, then any repeats inside the window.
        $days = ($eff >= $today && $eff <= $until) ? [[$eff, (!$rides && $eff !== $r['due'])]] : [];
        if (!$rides) {
            foreach (repeat_dates((string) $r['due'], repeat_get($r), $today, $until) as $d) {
                if ($d > $eff) { $days[] = [$d, false]; }
            }
        }
        foreach ($days as [$d, $wasRolled]) {
            // The id rides along so the widget's checkbox can link back to this one row.
            $items[] = ['date' => $d, 'kind' => 'reminder', 'id' => (string) ($r['id'] ?? ''),
                        'text' => (string) ($r['text'] ?? ''),
                        'time' => (string) ($r['time'] ?? ''), 'overdue' => $wasRolled];
        }
    }
    foreach (loadlist(ufile($dir, $user, 'events')) as $e) {
        if ($visibleCals !== null) {
            if ($defCal === null) {                       // an event with no calendar belongs to the first one
                $cals   = array_values(array_filter(loadlist(ufile($dir, $user, 'calendars')),
                                                    fn($c) => ($c['type'] ?? '') !== 'set'));
                $defCal = (string) ($cals[0]['id'] ?? '');
            }
            $ec = (string) ($e['cal'] ?? '');
            if ($ec === '') { $ec = $defCal; }
            if (!in_array($ec, $visibleCals, true)) { continue; }
        }
        foreach (repeat_dates((string) ($e['date'] ?? ''), repeat_get($e), $today, $until) as $d) {
            $items[] = ['date' => $d, 'kind' => 'event', 'text' => (string) ($e['text'] ?? ''),
                        'time' => (string) ($e['time'] ?? ''), 'overdue' => false];
        }
    }
    usort($items, fn($a, $b) => [$a['date'], $a['kind']] <=> [$b['date'], $b['kind']]);
    return ['today' => $today, 'items' => array_slice($items, 0, 12)];
}

// ---------- Mode 1: token feed (no login) ----------
if (isset($_GET['token'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $owner = token_user($dataDir, (string) $_GET['token']);
    if ($owner === null) { http_response_code(403); echo json_encode(['error' => 'invalid token']); exit; }
    $pin = isset($_GET['cals']) ? (string) $_GET['cals'] : null;
    echo json_encode(build_feed($dataDir, $owner, $pin), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
$token = $t['token'];

// Freeze the calendars the page is on right now into the URL, so the script you
// copy keeps showing these however you browse afterwards. Come back and copy it
// again to re-point the widget.
[$scopeCals] = feed_scope($dataDir, $user);
$pin      = $scopeCals === null ? 'all' : implode(',', $scopeCals);
$feedUrl  = 'https://seancheren.com/calendar/feed.php?token=' . $token . '&cals=' . rawurlencode($pin);

$calNames = [];
foreach (loadlist(ufile($dataDir, $user, 'calendars')) as $c) {
    if (($c['type'] ?? '') !== 'set') { $calNames[(string) ($c['id'] ?? '')] = (string) ($c['name'] ?? ''); }
}
$scopeLabel = $scopeCals === null
    ? 'every calendar'
    : implode(', ', array_map(fn($id) => $calNames[$id] ?? $id, $scopeCals));

$script = str_replace(['__FEED_URL__', '__BLUE__'], [$feedUrl, KIND_BLUE], <<<'JS'
// seancheren calendar — Scriptable widget
const FEED = "__FEED_URL__";
const OPEN = "https://seancheren.com/calendar/quick.php";
const COLORS = { reminder: "#34d399", event: "__BLUE__", note: "#8b6ef0" };

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

// Reminders and events only — the feed no longer sends notes at all.
const META = new Color("#777777");                 // the muted time/date colour
const items = (data.items || []).filter(it => it.kind !== "note");
if (data.error) {
  const t = w.addText("Couldn't load."); t.textColor = new Color("#ff6666"); t.font = Font.systemFont(12);
} else {
  // The day is the section, not the kind: one heading per date, reminders before events
  // under it. The heading gets a light rule directly beneath it (so the date reads as a
  // heading rather than as another row) and a heavier one between days, which is the
  // only thing separating one day's list from the next.
  const max = config.widgetFamily === "large" ? 8 : (config.widgetFamily === "small" ? 3 : 5);
  const RANK = { reminder: 0, event: 1, note: 2 };
  const byDay = [];
  for (const it of items) {
    const last = byDay[byDay.length - 1];
    if (last && last.date === it.date) { last.list.push(it); }
    else { byDay.push({ date: it.date, list: [it] }); }
  }
  for (const d of byDay) { d.list.sort((a, b) => (RANK[a.kind] ?? 9) - (RANK[b.kind] ?? 9)); }
  // Today is always its own section, even when it's empty — an overdue reminder rolls onto
  // today, so an empty today genuinely means "nothing left", shown as a row under the date.
  if (!byDay.length || byDay[0].date !== data.today) { byDay.unshift({ date: data.today, list: [] }); }
  let budget = max;

  // A full-width rule. `weight` 1 is the hairline under a date, 2 the divider between days.
  const rule = (weight, color) => {
    const div = w.addStack();
    div.size = new Size(0, weight);
    div.backgroundColor = new Color(color);
    div.addSpacer();
  };

  const drawRow = (it) => {
    const row = w.addStack();
    row.centerAlignContent();
    if (it.kind === "reminder") {
      // A reminder wears an empty tick box rather than a dot — it's a thing to *do*.
      // Tapping the row opens the tick page for that one reminder, which does the write
      // in Safari under your own session (the widget's token is read-only by design).
      if (it.id) { row.url = OPEN + "?tick=" + encodeURIComponent(it.id); }
      const box = row.addImage(SFSymbol.named("square").image);
      box.imageSize = new Size(11, 11);
      box.tintColor = new Color(it.overdue ? "#ff7755" : COLORS.reminder);
      box.resizable = true;
    } else {
      const dot = row.addText("●");
      dot.textColor = new Color(COLORS[it.kind] || "#888888");
      dot.font = Font.systemFont(9);
    }
    row.addSpacer(6);
    const label = row.addText(it.text || "");
    label.font = Font.systemFont(12);
    label.textColor = it.overdue ? new Color("#ff7755") : new Color("#eeeeee");
    label.lineLimit = 1;
    row.addSpacer();
    // The date has moved up to its day heading, so only the time sits on the right.
    if (it.time) {
      const t = row.addText(fmtTime(it.time));
      t.font = Font.systemFont(11); t.textColor = META;
    }
    w.addSpacer(5);
  };

  let first = true;
  for (const day of byDay) {
    if (budget <= 0) { break; }
    if (!first) {                       // heavier rule marks the change of day
      w.addSpacer(7);
      rule(2, "#3a3a3a");
      w.addSpacer(8);
    }
    first = false;
    const isToday = day.date === data.today;
    const h = w.addText(longDate(day.date, data.today).toUpperCase());
    h.font = Font.boldSystemFont(10);
    h.textColor = isToday ? new Color(COLORS.reminder) : new Color("#9a9a9a");
    w.addSpacer(3);
    rule(1, isToday ? "#2f5f4d" : "#242424");   // the date's own light underline
    w.addSpacer(6);
    if (!day.list.length) {
      // Only an empty *today* is unshifted in, so this reads as "nothing left today".
      const t = w.addText("No more items today."); t.textColor = META; t.font = Font.systemFont(12);
      w.addSpacer(5);
      continue;
    }
    for (const it of day.list) {
      if (budget <= 0) { break; }
      drawRow(it);
      budget--;
    }
  }
}

if (config.runsInWidget) { Script.setWidget(w); } else { await w.presentMedium(); }
Script.complete();

// The day heading: "Today", otherwise "Sat · Aug 1" — the weekday earns its place once
// it's a heading rather than a note at the end of a row.
function longDate(ymd, today) {
  const [y, m, d] = ymd.split("-").map(Number);
  const date = new Date(y, m - 1, d);
  const md = date.toLocaleDateString([], { month: "short", day: "numeric" });
  if (ymd === today) return "Today · " + md;
  return date.toLocaleDateString([], { weekday: "short" }) + " · " + md;
}

// Stored as 24-hour "HH:MM"; shown as "2pm" / "2:30pm".
function fmtTime(hm) {
  const [h, m] = String(hm).split(":").map(Number);
  if (isNaN(h)) return "";
  const ap = h < 12 ? "am" : "pm";
  const h12 = h % 12 === 0 ? 12 : h % 12;
  return m ? `${h12}:${String(m).padStart(2, "0")}${ap}` : `${h12}${ap}`;
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
    button { margin-top: 0.5rem; padding: 0.55rem 1rem; background: #34d399; color: var(--accent-ink); border: none;
      border-radius: 6px; font-size: 0.95rem; font-weight: 700; cursor: pointer; }
    .warn { color: #f0b429; font-size: 0.8rem; margin-top: 0.75rem; }
    .scope { font-size: 0.85rem; color: #aaa; margin-bottom: 0.5rem; }
    .scope strong { color: #7dd3fc; }
    nav a { color: #888; text-decoration: none; }
  </style>
</head>
<body>
<div class="wrap">
  <nav><a href="<?= suite_base() ?>/calendar/">&larr; Calendar</a></nav>
  <h1>Calendar widget</h1>
  <div class="sub">A home-screen widget for <strong><?= htmlspecialchars($user, ENT_QUOTES) ?></strong> that shows your agenda and opens the calendar when tapped.</div>

  <h2>1. Install Scriptable</h2>
  <ol><li>Get the free <strong>Scriptable</strong> app from the App Store.</li></ol>

  <h2>2. Add the script</h2>
  <p class="scope">This script is set to <strong><?= htmlspecialchars($scopeLabel, ENT_QUOTES) ?></strong> — whatever the calendar was showing when you opened this page. To point the widget somewhere else, pick it on the calendar, come back here, and copy the script again.</p>
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
