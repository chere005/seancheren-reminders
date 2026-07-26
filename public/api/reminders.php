<?php
/**
 * Reminders as JSON, for the watch app.
 *
 *   GET ?token=XYZ   ->  the reminder list you'd see opening the app, grouped by section
 *
 * Read-only, and authenticated by the same token as the calendar widget
 * (data/token-<user>.json) rather than a session — a watch has nowhere to keep a
 * login. That token is handed out as a read credential, so nothing here writes;
 * ticking something off still happens on the phone.
 *
 * Like feed.php this reads the reminders file by hand. The grouping and ordering
 * mirror public/reminders/index.php — Calendar group first, then the ungrouped
 * catch-all, then the user's own sections, each undated-first and then by date —
 * so a change to how the list is shown needs making in both places.
 */

$__libDir = null;
foreach ([__DIR__ . '/../../lib', '/home/protected/lib'] as $__c) {
    if (is_file($__c . '/auth.php')) { $__libDir = $__c; break; }
}
require_once $__libDir . '/auth.php';
require_once $__libDir . '/folders.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$cfg     = app_config();
$dataDir = rtrim($cfg['data_dir'], '/');
$user    = token_user($dataDir, (string) ($_GET['token'] ?? ''));
if ($user === null) {
    http_response_code(403);
    echo json_encode(['error' => 'invalid token']);
    exit;
}

/** Display order inside a section: undated first, then by date, stored order breaking ties. */
function api_sort(array $rows): array
{
    $i = 0;
    foreach ($rows as &$r) { $r['_seq'] = $i++; }
    unset($r);
    usort($rows, function ($a, $b) {
        $ad = ($a['due'] ?? '') ?: '';
        $bd = ($b['due'] ?? '') ?: '';
        return $ad !== $bd ? strcmp($ad, $bd) : ($a['_seq'] <=> $b['_seq']);
    });
    return $rows;
}

/** One reminder, flattened to what a watch face needs. */
function api_item(array $r, string $today): array
{
    $due = (string) ($r['due'] ?? '');
    return [
        'id'      => (string) ($r['id'] ?? ''),
        'text'    => (string) ($r['text'] ?? ''),
        'due'     => $due,
        'time'    => (string) ($r['time'] ?? ''),
        'done'    => !empty($r['done']),
        'overdue' => $due !== '' && $due < $today && empty($r['done']),
        'repeats' => repeat_get($r) !== null,
    ];
}

$today = date('Y-m-d');
$all   = store_read(user_data_file($dataDir, 'reminders', $user));

// The folder the app would open on. A shared "@partner:Folder" view is skipped —
// the watch shows your own list — and a deleted folder falls back to everything.
$view    = folder_last_get($dataDir, 'reminders', $user);
$mine    = folders_load($dataDir, $user)['reminders'];
if ($view !== 'All' && !in_array($view, $mine, true)) { $view = 'All'; }

// Section names in creation order; Calendar is permanent and drawn on its own.
$sections = [];
foreach ($all as $it) {
    if (($it['type'] ?? '') === 'section' && !in_array($it['name'], $sections, true)
        && strcasecmp((string) $it['name'], CALENDAR_SECTION) !== 0) {
        $sections[] = (string) $it['name'];
    }
}

$calRows = $ungrouped = $grouped = [];
foreach ($all as $r) {
    if (($r['type'] ?? '') === 'section') { continue; }
    if ($view !== 'All' && ($r['folder'] ?? FOLDER_DEFAULT) !== $view) { continue; }
    $s = (string) ($r['section'] ?? '');
    if (strcasecmp($s, CALENDAR_SECTION) === 0)        { $calRows[] = $r; }
    elseif ($s !== '' && in_array($s, $sections, true)) { $grouped[$s][] = $r; }
    else                                               { $ungrouped[] = $r; }
}

$out = [];
foreach (array_merge([[CALENDAR_SECTION, $calRows], ['Reminders', $ungrouped]],
                     array_map(fn($s) => [$s, $grouped[$s] ?? []], $sections)) as [$name, $rows]) {
    $items = array_map(fn($r) => api_item($r, $today), api_sort($rows));
    if ($items) { $out[] = ['name' => $name, 'items' => array_values($items)]; }
}

echo json_encode(['user'     => $user,
                  'today'    => $today,
                  'folder'   => $view,
                  'sections' => $out],
                 JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
