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
require_login('Reminders');

$cfg = app_config();

// Ungrouped reminders live under a permanent, non-deletable group shown last.
const DEFAULT_SECTION = 'Reminders';

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$me        = current_user() ?? '';
$myFolders = folders_load($cfg['data_dir'])['reminders'];

// Folders the other person shared with me, shown in the picker as "@aki:Groceries".
$partner       = share_partner();
$sharedFolders = $partner
    ? array_values(array_intersect(folders_load($cfg['data_dir'], $partner)['reminders'],
                                   shares_load($cfg['data_dir'], $partner)['folders']))
    : [];

/**
 * Which folder is being viewed? 'All', one of mine, or '@<partner>:<folder>' for
 * a shared one — in which case every read and write goes to their file instead.
 */
$view       = (string) ($_REQUEST['view'] ?? $_GET['folder'] ?? 'All');
$owner      = $me;
$viewFolder = $view;
$isShared   = false;
if ($partner && preg_match('/^@([A-Za-z0-9_-]+):(.*)$/s', $view, $m)
    && $m[1] === $partner && in_array($m[2], $sharedFolders, true)) {
    $owner = $partner; $viewFolder = $m[2]; $isShared = true;
} elseif ($view !== 'All' && !in_array($view, $myFolders, true)) {
    $view = $viewFolder = 'All';
}

$dataFile = user_data_file($cfg['data_dir'], 'reminders', $isShared ? $owner : null);
$folders  = $isShared ? folders_load($cfg['data_dir'], $owner)['reminders'] : $myFolders;

// New items land in the viewed folder, or the chosen default when viewing All.
$defFolder = folder_default_get($cfg['data_dir'], 'reminders');
$addTarget = $viewFolder === 'All' ? $defFolder : $viewFolder;
$backUrl   = _self_path() . '?folder=' . urlencode($view);

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES);
}

/** A stored row is a "section" divider (bold header) rather than a reminder. */
function is_section(array $it): bool
{
    return ($it['type'] ?? '') === 'section';
}

function load_reminders(string $file): array { return store_read($file); }
function save_reminders(string $file, array $list): void { store_write($file, array_values($list)); }

/** Default order for a group: open first, then by due date (nulls last), then newest. */
function sort_by_date(array $rows): array
{
    usort($rows, function ($a, $b) {
        if ((!empty($a['done'])) !== (!empty($b['done']))) {
            return !empty($a['done']) ? 1 : -1;
        }
        $ad = $a['due'] ?? null;
        $bd = $b['due'] ?? null;
        if ($ad !== $bd) {
            if ($ad === null) return 1;
            if ($bd === null) return -1;
            return strcmp($ad, $bd);
        }
        return ($b['created'] ?? 0) <=> ($a['created'] ?? 0);
    });
    return $rows;
}

/** Echo a <ul> of reminder rows (nothing if empty). Data attributes drive sort + drag. */
function render_rows(array $rows, string $csrf, string $view, string $today, string $section = ''): void
{
    static $pos = 0;   // running position across all groups = the manual order
    echo '<ul class="rlist" data-section="' . e($section) . '">';   // always emit (empty = drop target)
    foreach ($rows as $r) {
        $done    = !empty($r['done']);
        $overdue = !empty($r['due']) && !$done && $r['due'] < $today;
        ?>
        <li class="<?= $done ? 'done' : '' ?>"
            data-id="<?= e($r['id']) ?>"
            data-pos="<?= $pos++ ?>"
            data-done="<?= $done ? '1' : '0' ?>"
            data-due="<?= e($r['due'] ?? '') ?>"
            data-text="<?= e($r['text'] ?? '') ?>"
            data-created="<?= (int) ($r['created'] ?? 0) ?>">
          <span class="drag-handle" title="Drag to reorder" aria-hidden="true">&#9776;</span>
          <form method="post" action="" style="display:inline">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="view" value="<?= e($view) ?>">
            <input type="hidden" name="id" value="<?= e($r['id']) ?>">
            <button class="check" type="submit" title="Toggle done"><?= $done ? '&#10003;' : '&nbsp;&nbsp;' ?></button>
          </form>
          <span class="text"><?= e($r['text']) ?></span>
          <?php if (!empty($r['due'])): ?>
            <span class="due <?= $overdue ? 'overdue' : '' ?>"><?= e($r['due']) ?></span>
          <?php endif; ?>
          <form method="post" action="" style="display:inline">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="view" value="<?= e($view) ?>">
            <input type="hidden" name="id" value="<?= e($r['id']) ?>">
            <button class="del" type="submit" title="Delete">&times;</button>
          </form>
        </li>
        <?php
    }
    echo '</ul>';
}

// --- Handle mutations (POST -> redirect -> GET) ---
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action'])) {
    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Bad request (invalid CSRF token).');
    }

    // A shared folder is someone else's list: you can work its reminders, but its
    // folders and sections stay theirs to arrange.
    if ($isShared && in_array($_POST['action'], ['add_section', 'delete_section', 'delete_folder', 'reorder'], true)) {
        http_response_code(403);
        exit('That belongs to ' . htmlspecialchars(share_name($owner), ENT_QUOTES) . '.');
    }

    // The New-item window can also make an event or a note; those live in their own files.
    if ($_POST['action'] === 'add_event' || $_POST['action'] === 'add_note') {
        $text = trim((string) ($_POST['text'] ?? ''));
        $due  = trim((string) ($_POST['due'] ?? ''));
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) ? $due : null;
        if ($text === '') { header('Location: ' . $backUrl); exit; }
        if ($_POST['action'] === 'add_event') {
            $file = user_data_file($cfg['data_dir'], 'events');
            $list = store_read($file);
            $list[] = ['id' => bin2hex(random_bytes(6)), 'text' => mb_substr($text, 0, 500),
                       'date' => $date, 'time' => null, 'cal' => '', 'created' => time()];
            store_write($file, array_values($list));
            header('Location: /calendar/' . ($date ? '?day=' . $date . '&ym=' . substr($date, 0, 7) : ''));
            exit;
        }
        $file  = user_data_file($cfg['data_dir'], 'notes');
        $list  = store_read($file);
        $newId = bin2hex(random_bytes(6));
        $list[] = ['id' => $newId, 'title' => mb_substr($text, 0, 200), 'date' => $date,
                   'body' => '', 'created' => time(), 'updated' => time()];
        store_write($file, array_values($list));
        header('Location: /notes/?id=' . $newId);
        exit;
    }

    // Folder actions don't touch the reminders list.
    if ($_POST['action'] === 'add_folder') {
        $name = folder_clean((string) ($_POST['name'] ?? ''));
        folders_add($cfg['data_dir'], 'reminders', $name);
        header('Location: ' . _self_path() . '?folder=' . urlencode($name !== '' ? $name : 'All'));
        exit;
    }
    if ($_POST['action'] === 'set_default_folder') {
        folder_default_set($cfg['data_dir'], 'reminders', (string) ($_POST['name'] ?? ''));
        header('Location: ' . $backUrl);
        exit;
    }
    if ($_POST['action'] === 'delete_folder') {
        $name = (string) ($_POST['name'] ?? '');
        folders_delete($cfg['data_dir'], 'reminders', $name);
        // Move that folder's reminders back to General.
        $list = load_reminders($dataFile);
        foreach ($list as &$r) {
            if (!is_section($r) && ($r['folder'] ?? FOLDER_DEFAULT) === $name) { $r['folder'] = FOLDER_DEFAULT; }
        }
        unset($r);
        save_reminders($dataFile, $list);
        header('Location: ' . _self_path() . '?folder=All');
        exit;
    }

    // Section actions. Sections are bold headers that group reminders (orthogonal to folders).
    if ($_POST['action'] === 'add_section') {
        $name = folder_clean((string) ($_POST['name'] ?? ''));
        if ($name !== '' && strcasecmp($name, DEFAULT_SECTION) !== 0) {   // "Reminders" is reserved for the default group
            $list = load_reminders($dataFile);
            $dup  = false;
            foreach ($list as $it) {
                if (is_section($it) && strcasecmp((string) ($it['name'] ?? ''), $name) === 0) { $dup = true; break; }
            }
            if (!$dup) {
                $list[] = ['id' => bin2hex(random_bytes(6)), 'type' => 'section', 'name' => $name, 'created' => time()];
                save_reminders($dataFile, $list);
            }
        }
        header('Location: ' . $backUrl);
        exit;
    }
    if ($_POST['action'] === 'delete_section') {
        $name = (string) ($_POST['name'] ?? '');
        $list = load_reminders($dataFile);
        $list = array_filter($list, fn($it) => !(is_section($it) && ($it['name'] ?? '') === $name));
        foreach ($list as &$r) {
            if (!is_section($r) && ($r['section'] ?? '') === $name) { $r['section'] = ''; }
        }
        unset($r);
        save_reminders($dataFile, $list);
        header('Location: ' . $backUrl);
        exit;
    }

    // Reorder / re-section reminders after a drag (AJAX). order = [{id, section}, …] top-to-bottom.
    if ($_POST['action'] === 'reorder') {
        $order    = json_decode((string) ($_POST['order'] ?? '[]'), true);
        $secOrder = json_decode((string) ($_POST['sections'] ?? '[]'), true);
        if (!is_array($order))    { $order = []; }
        if (!is_array($secOrder)) { $secOrder = []; }
        $list = load_reminders($dataFile);

        $validSections = [];
        $secByName     = [];
        $byId          = [];
        foreach ($list as $it) {
            if (is_section($it)) { $secByName[$it['name']] = $it; $validSections[$it['name']] = true; }
            else { $byId[$it['id']] = $it; }
        }

        // Reorder section entries by the posted order; keep any not listed (e.g. hidden in a folder view).
        $sectionsList = [];
        foreach ($secOrder as $name) {
            if (isset($secByName[$name])) { $sectionsList[] = $secByName[$name]; unset($secByName[$name]); }
        }
        foreach ($secByName as $e) { $sectionsList[] = $e; }

        $newReminders = [];
        $used = [];
        foreach ($order as $o) {
            $id = (string) ($o['id'] ?? '');
            if ($id === '' || !isset($byId[$id]) || isset($used[$id])) { continue; }
            $sec = (string) ($o['section'] ?? '');
            if ($sec !== '' && !isset($validSections[$sec])) { $sec = ''; }
            $item            = $byId[$id];
            $item['section'] = $sec;
            $newReminders[]  = $item;
            $used[$id]       = true;
        }
        // Preserve reminders not in the posted order (e.g. other folders).
        foreach ($list as $it) {
            if (!is_section($it) && !isset($used[$it['id']])) { $newReminders[] = $it; }
        }

        save_reminders($dataFile, array_merge($sectionsList, $newReminders));
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }

    // Inline edit of a reminder's text (AJAX, from tapping it in edit mode).
    if ($_POST['action'] === 'edit_text') {
        $id   = (string) ($_POST['id'] ?? '');
        $text = trim((string) ($_POST['text'] ?? ''));
        $list = load_reminders($dataFile);
        if ($text !== '') {
            foreach ($list as &$r) {
                if (!is_section($r) && ($r['id'] ?? '') === $id) { $r['text'] = mb_substr($text, 0, 500); break; }
            }
            unset($r);
            save_reminders($dataFile, $list);
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }

    $list        = load_reminders($dataFile);
    $sectionSet  = [];
    foreach ($list as $it) { if (is_section($it)) { $sectionSet[$it['name']] = true; } }
    $undoFlag = '';   // set after a delete so the page shows the Undo button

    switch ($_POST['action']) {
        case 'add':
            $text    = trim((string) ($_POST['text'] ?? ''));
            $due     = trim((string) ($_POST['due'] ?? ''));
            $folder  = (string) ($_POST['folder'] ?? FOLDER_DEFAULT);
            if (!in_array($folder, $folders, true)) { $folder = FOLDER_DEFAULT; }
            $section = (string) ($_POST['section'] ?? '');
            if ($section !== '' && !isset($sectionSet[$section])) { $section = ''; }
            if ($text !== '') {
                $list[] = [
                    'id'      => bin2hex(random_bytes(6)),
                    'text'    => mb_substr($text, 0, 500),
                    'due'     => preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) ? $due : null,
                    'done'    => false,
                    'folder'  => $folder,
                    'section' => $section,
                    'created' => time(),
                ];
            }
            break;

        case 'toggle':
            $id = (string) ($_POST['id'] ?? '');
            foreach ($list as &$r) {
                if (!is_section($r) && $r['id'] === $id) {
                    $r['done'] = !$r['done'];
                    break;
                }
            }
            unset($r);
            break;

        case 'delete':
            $id = (string) ($_POST['id'] ?? '');
            foreach ($list as $r) { if (!is_section($r) && ($r['id'] ?? '') === $id) { $_SESSION['undo_rem'] = $r; break; } }
            $list = array_values(array_filter($list, fn($r) => is_section($r) || ($r['id'] ?? '') !== $id));
            $undoFlag = '&undo=1';
            break;

        case 'undo':
            if (!empty($_SESSION['undo_rem'])) { $list[] = $_SESSION['undo_rem']; unset($_SESSION['undo_rem']); }
            break;

        case 'clear_done':
            // Clear completed within the folder being viewed (or all when viewing All).
            $list = array_filter($list, function ($r) use ($viewFolder) {
                if (is_section($r) || empty($r['done'])) { return true; }
                return $viewFolder !== 'All' && ($r['folder'] ?? FOLDER_DEFAULT) !== $viewFolder;
            });
            break;
    }

    save_reminders($dataFile, $list);
    header('Location: ' . $backUrl . $undoFlag);
    exit;
}

// --- Render ---
$all = load_reminders($dataFile);

// Section names, in creation order (deduped).
$sections = [];
foreach ($all as $it) {
    if (is_section($it) && !in_array($it['name'], $sections, true)) { $sections[] = $it['name']; }
}

// Reminder rows, filtered to the viewed folder.
$items = array_values(array_filter($all, fn($it) => !is_section($it)));
if ($viewFolder !== 'All') {
    $items = array_values(array_filter($items, fn($r) => ($r['folder'] ?? FOLDER_DEFAULT) === $viewFolder));
}

// Split into ungrouped + per-section. Stored array order = the manual (drag) order;
// the JS sort menu can re-sort by date/name on top of it.
$ungrouped = [];
$grouped   = [];
foreach ($items as $r) {
    $s = (string) ($r['section'] ?? '');
    if ($s !== '' && in_array($s, $sections, true)) { $grouped[$s][] = $r; }
    else { $ungrouped[] = $r; }
}

$openCount = count(array_filter($items, fn($r) => empty($r['done'])));
$doneCount = count($items) - $openCount;
$csrf      = htmlspecialchars($_SESSION['csrf'], ENT_QUOTES);
$today     = date('Y-m-d');

// Folder picker contents: mine under my name, then whatever the other person shared.
$folderGroups = [['label' => share_name($me),
                  'options' => array_map(fn($f) => [$f, $f], $myFolders)]];
if ($sharedFolders) {
    $folderGroups[] = ['label' => share_name($partner),
                       'options' => array_map(fn($f) => ['@' . $partner . ':' . $f, $f], $sharedFolders)];
}

// The "+ New section" control that sits next to "+ New folder".
$sectionInput =
    '<form method="post" action="" class="newsection" onsubmit="return this.name.value.trim()!==\'\'">'
  . '<input type="hidden" name="csrf" value="' . $csrf . '">'
  . '<input type="hidden" name="action" value="add_section">'
  . '<input type="hidden" name="view" value="' . e($view) . '">'
  . '<input type="text" name="name" placeholder="+ New section" maxlength="40" autocomplete="off">'
  . '</form>';
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>Reminders</title>
  <meta name="theme-color" content="#111111">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black">
  <meta name="apple-mobile-web-app-title" content="Reminders">
  <link rel="apple-touch-icon" href="/reminders/icon-180.png">
  <link rel="icon" href="/reminders/icon-192.png">
  <link rel="manifest" href="/reminders/manifest.webmanifest?v=2">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: system-ui, sans-serif; background: #111; color: #eee;
      min-height: 100vh; padding: 2rem 1rem;
    }
    .wrap { max-width: 640px; margin: 0 auto; }
    /* Tight bottom margin: the folder dropdown sits directly under this. */
    header {
      display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 0.75rem;
    }
    header h1 { font-size: 1.5rem; }
    header .titlebar { display: flex; align-items: baseline; gap: 0.7rem; }
    header .meta { font-size: 0.8rem; color: #888; }
    header .htitle { min-width: 0; }
    header a { color: #888; text-decoration: none; margin-left: 1rem; }
    header a:hover { color: #fff; }
    header .who {
      color: #34d399; font-size: 0.8rem; border: 1px solid #2a4a3d;
      border-radius: 999px; padding: 0.15rem 0.6rem;
    }

    /* Add (+ Undo after a delete), then Show Completed on its own row beneath.
       Pills sized to match the Calendar's day-panel buttons. */
    .addbar, .donebar { display: flex; gap: 0.5rem; align-items: center; }
    .addbar { margin-bottom: 0.5rem; }
    .donebar { margin-bottom: 1.5rem; }
    .addbar button, .donebar button {
      padding: 0.35rem 0.9rem; border-radius: 999px; font-size: 0.9rem;
      cursor: pointer; font-family: inherit;
    }
    .addbar .addopen { background: #34d399; color: #06251b; border: none; font-weight: 700; }
    .addbar .addopen:hover { background: #52e0ac; }
    .donebar .showall { background: none; color: #888; border: 1px solid #333; }
    .donebar .showall:hover { border-color: #888; color: #ccc; }
    body.show-done .donebar #doneBtn { background: #34d399; border-color: #34d399; color: #06251b; font-weight: 700; }
    .addbar .editbtn { background: none; border: 1px solid #444; color: #ccc; }
    .addbar .editbtn:hover { border-color: #888; color: #fff; }
    .addbar #undoBtn { display: none; margin-left: auto; }   /* only right after a delete */
    body.can-undo .addbar #undoBtn { display: inline-block; }
    /* Completed reminders + the clear button stay hidden until "Show Completed" is on */
    body:not(.show-done) li.done { display: none; }
    body:not(.show-done) footer { display: none; }

    /* New-item window, the same one the Calendar uses. */
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
    .modal input[type=text] {
      width: 100%; padding: 0.6rem 0.75rem; background: #222; border: 1px solid #3a3a3a;
      border-radius: 6px; color: #eee; font-size: 16px; margin-bottom: 0.85rem;
    }
    .modal input:focus, .modal select:focus { outline: none; border-color: #888; }
    .modal .kind { display: flex; gap: 0.5rem; margin-bottom: 1rem; }
    .modal .kind label {
      flex: 1; text-align: center; padding: 0.5rem; border: 1px solid #3a3a3a;
      border-radius: 6px; font-size: 0.9rem; color: #aaa; cursor: pointer; user-select: none;
    }
    .modal .kind input { display: none; }
    .modal .kind input:checked + span { color: #34d399; font-weight: 700; }
    .modal .kind label:has(input:checked) { border-color: #34d399; background: #14251f; }
    .modal .daterow, .modal .secrow { margin-bottom: 1rem; }
    .modal .adddate {
      background: none; border: 1px dashed #3a5a4d; color: #34d399; border-radius: 6px;
      padding: 0.45rem 0.8rem; font-size: 0.9rem; cursor: pointer; font-family: inherit;
    }
    .modal .adddate:hover { background: #14251f; }
    .modal .datewrap { display: flex; align-items: center; gap: 0.5rem; }
    .modal .datewrap[hidden] { display: none; }   /* make [hidden] win over flex */
    .modal .datewrap input[type=date] {
      flex: 1; padding: 0.5rem 0.6rem; background: #222; border: 1px solid #3a3a3a;
      border-radius: 6px; color: #eee; font-size: 16px; color-scheme: dark;
    }
    .modal .datewrap .cleardate {
      background: none; border: 1px solid #3a3a3a; color: #999; border-radius: 6px;
      padding: 0.45rem 0.6rem; font-size: 0.9rem; cursor: pointer; line-height: 1; font-family: inherit;
    }
    .modal .datewrap .cleardate:hover { border-color: #f66; color: #f66; }
    .modal .secsel {
      width: 100%; padding: 0.5rem 0.6rem; background: #222; border: 1px solid #4a3f2a;
      border-radius: 6px; color: #f0b429; font-size: 16px; color-scheme: dark; cursor: pointer;
      font-family: inherit;
    }
    .modal .buttons { display: flex; gap: 0.5rem; justify-content: flex-end; }
    .modal .buttons button {
      padding: 0.55rem 1.1rem; border: none; border-radius: 6px; font-size: 0.95rem;
      font-weight: 600; cursor: pointer; font-family: inherit;
    }
    .modal .buttons .cancel { background: #2a2a2a; color: #ccc; }
    .modal .buttons .ok { background: #34d399; color: #06251b; }

    /* Section headers (bold), grouping reminders */
    .section-head { display: flex; align-items: center; gap: 0.5rem; margin: 1.5rem 0 0.25rem; }
    .section-title { font-weight: 700; font-size: 1.05rem; color: #f0b429; }
    .section-del {
      background: none; border: 1px solid #2a2a2a; color: #666; border-radius: 6px;
      padding: 0.1rem 0.45rem; font-size: 0.85rem; line-height: 1; cursor: pointer;
    }
    .section-del:hover { border-color: #f66; color: #f66; }

    ul { list-style: none; }
    li {
      display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem 0.25rem;
      border-bottom: 1px solid #222;
    }
    li.done .text { color: #666; text-decoration: line-through; }
    ul.rlist { display: flex; flex-direction: column; }
    li.done { order: 1; }   /* when shown, completed items sink below the open ones */
    .text { flex: 1; font-size: 1rem; word-break: break-word; }
    /* Edit mode: no accidental text selection while holding to drag. */
    body.editing li, body.editing .section-head { -webkit-touch-callout: none; -webkit-user-select: none; user-select: none; }
    body.editing .text { cursor: text; }
    .textedit {
      flex: 1; font-size: 1rem; padding: 0.25rem 0.5rem; background: #222; border: 1px solid #4a4a4a;
      border-radius: 4px; color: #eee; -webkit-user-select: text; user-select: text;
    }
    .textedit:focus { outline: none; border-color: #888; }
    .due {
      font-size: 0.75rem; color: #7a7; background: #142; padding: 0.15rem 0.5rem;
      border-radius: 999px; white-space: nowrap;
    }
    .due.overdue { color: #f99; background: #411; }
    .check, .del {
      background: none; border: 1px solid #444; color: #ccc; cursor: pointer;
      border-radius: 6px; padding: 0.3rem 0.55rem; font-size: 0.95rem; line-height: 1;
    }
    .check:hover { border-color: #7a7; color: #7a7; }
    .del:hover { border-color: #f66; color: #f66; }

    /* Edit mode: the X buttons + drag handles stay hidden until "Edit" is tapped. */
    .drag-handle, .sec-handle, .del, .section-del { display: none; }
    body.editing .del, body.editing .section-del { display: inline-block; }
    body.editing .drag-handle, body.editing .sec-handle { display: inline-flex; }
    .drag-handle, .sec-handle {
      flex: 0 0 auto; align-items: center; justify-content: center; width: 1.4rem;
      color: #666; font-size: 1.05rem; cursor: grab; touch-action: none; user-select: none;
    }
    .drag-handle:active, .sec-handle:active { cursor: grabbing; color: #34d399; }
    li.dragging { background: #1b1f1d; border-radius: 6px; box-shadow: 0 4px 14px rgba(0,0,0,0.45); }
    .section-group.dragging { opacity: 0.9; }
    .section-group.dragging .section-head {
      background: #1b1f1d; border-radius: 6px; box-shadow: 0 4px 14px rgba(0,0,0,0.45);
    }
    .section-group.dragging .section-title { color: #34d399; }
    body.editing ul.rlist { min-height: 1.4rem; }
    body.editing ul.rlist:empty { border: 1px dashed #333; border-radius: 6px; margin: 0.25rem 0; }

    .empty { color: #666; text-align: center; padding: 2rem 0; }
    footer { margin-top: 1.5rem; display: flex; justify-content: flex-end; }
    footer button {
      background: none; border: none; color: #666; font-size: 0.8rem; cursor: pointer;
    }
    footer button:hover { color: #f66; }
<?= folder_nav_styles() ?>
    .newsection { margin: 0 0 0.6rem; }
    body:not(.editing) .newsection { display: none; }   /* edit mode only */
    .newsection input {
      width: 190px; max-width: 100%; padding: 0.35rem 0.8rem; background: #1a1a1a; border: 1px dashed #5a4a2a;
      border-radius: 999px; color: #f0b429; font-size: 16px;   /* 16px stops iOS zoom on focus */
    }
    .newsection input::placeholder { color: #f0b429; opacity: 0.85; }
    .newsection input:focus { outline: none; border-style: solid; border-color: #f0b429; }
<?= tabbar_styles() ?>
<?= chrome_styles() ?>
  </style>
</head>
<body>
<div class="wrap">
  <header>
    <div class="hleft">
      <?= back_button() ?>
      <div class="htitle">
        <div class="titlebar">
          <h1>Reminders</h1>
          <?php if (!$isShared): ?>
            <button type="button" id="folderMgr" class="folderplus"
                    title="Manage folders" aria-label="Manage folders">+</button>
          <?php endif; ?>
        </div>
        <div class="meta"><?= $isShared ? e(share_name($owner)) . ' &middot; ' : '' ?><?= e($viewFolder) ?>
          &middot; <?= $openCount ?> open<?= $doneCount ? " &middot; {$doneCount} done" : '' ?></div>
      </div>
    </div>
    <?= render_user_menu(true) ?>
  </header>

  <?php render_folder_select($folderGroups, $view); ?>

  <div class="addbar">
    <button type="button" id="addBtn" class="addopen">+ Add</button>
    <button type="button" id="undoBtn" class="editbtn">Undo</button>
  </div>
  <div class="donebar">
    <button type="button" id="doneBtn" class="showall">Show Completed</button>
  </div>

  <!-- New item, the same window the Calendar uses — but starting on Reminder. -->
  <div class="modal-backdrop" id="addModal">
    <form class="modal" method="post" action="" id="addForm">
      <h2>New item</h2>
      <input type="hidden" name="csrf" value="<?= $csrf ?>">
      <input type="hidden" name="action" id="aAction" value="add">
      <input type="hidden" name="view" value="<?= e($view) ?>">
      <input type="hidden" name="folder" value="<?= e($addTarget) ?>">
      <input type="text" name="text" id="aText" placeholder="What is it?" maxlength="500" required>
      <div class="kind">
        <label><input type="radio" name="kindchoice" value="reminder" checked><span>&#9745; Reminder</span></label>
        <label><input type="radio" name="kindchoice" value="event"><span>&#128197; Event</span></label>
        <label><input type="radio" name="kindchoice" value="note"><span>&#128221; Note</span></label>
      </div>
      <div class="daterow">
        <button type="button" class="adddate" id="aDateBtn">+ Date</button>
        <span class="datewrap" id="aDateWrap" hidden>
          <input type="date" name="due" id="aDate">
          <button type="button" class="cleardate" id="aDateClear" title="Remove date">&times;</button>
        </span>
      </div>
      <?php if ($sections): ?>
        <div class="secrow" id="aSecRow">
          <select name="section" class="secsel" title="Add to section">
            <option value="">Reminders</option>
            <?php foreach ($sections as $sname): ?>
              <option value="<?= e($sname) ?>"><?= e($sname) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>
      <div class="buttons">
        <button type="button" class="cancel" id="aCancel">Cancel</button>
        <button type="submit" class="ok">Add</button>
      </div>
    </form>
  </div>
  <?php if (!$isShared) { render_folder_modal($myFolders, $csrf, $view, $defFolder, 'New reminders go to'); } ?>

  <form id="undoForm" method="post" action="" style="display:none">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="undo">
    <input type="hidden" name="view" value="<?= e($view) ?>">
  </form>

  <?= $sectionInput ?>

  <?php if (!$items && !$sections): ?>
    <p class="empty">Nothing yet. Add your first reminder above.</p>
  <?php else: ?>
   <div id="rlist-root">
    <?php foreach ($sections as $sname): ?>
      <?php $rows = $grouped[$sname] ?? []; ?>
      <?php if (!$rows && $view !== 'All') continue; // hide empty sections inside a folder view ?>
      <div class="section-group" data-section="<?= e($sname) ?>">
        <div class="section-head">
          <span class="sec-handle" title="Drag section" aria-hidden="true">&#9776;</span>
          <span class="section-title"><?= e($sname) ?></span>
          <form method="post" action="" style="display:inline">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="delete_section">
            <input type="hidden" name="view" value="<?= e($view) ?>">
            <input type="hidden" name="name" value="<?= e($sname) ?>">
            <button class="section-del" type="submit" title="Delete section">&times;</button>
          </form>
        </div>
        <?php render_rows($rows, $csrf, $view, $today, $sname); ?>
      </div>
    <?php endforeach; ?>

    <!-- Permanent "Reminders" group: always last, not deletable, no drag handle. -->
    <div class="section-group default-group" data-section="">
      <div class="section-head">
        <span class="section-title"><?= DEFAULT_SECTION ?></span>
      </div>
      <?php render_rows($ungrouped, $csrf, $view, $today, ''); ?>
    </div>
   </div>

    <?php if ($doneCount): ?>
      <footer>
        <form method="post" action="" onsubmit="return confirm('Clear completed reminders?')">
          <input type="hidden" name="csrf" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="clear_done">
          <input type="hidden" name="view" value="<?= e($view) ?>">
          <button type="submit">Clear <?= $doneCount ?> completed</button>
        </form>
      </footer>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php render_tabbar('reminders'); ?>
<script>
  const TODAY = '<?= date('Y-m-d') ?>';

  // ----- New-item window -----
  const addModal = document.getElementById('addModal');
  const aText    = document.getElementById('aText');
  const aDateBtn = document.getElementById('aDateBtn');
  const aWrap    = document.getElementById('aDateWrap');
  const aDate    = document.getElementById('aDate');
  const aSecRow  = document.getElementById('aSecRow');

  // Sections only mean anything for reminders; events and notes go to their own apps.
  const syncKind = () => {
    const kind = document.querySelector('input[name=kindchoice]:checked').value;
    if (aSecRow) aSecRow.hidden = kind !== 'reminder';
  };
  document.querySelectorAll('input[name=kindchoice]').forEach(r => r.addEventListener('change', syncKind));

  const closeAdd = () => addModal.classList.remove('open');
  document.getElementById('addBtn').addEventListener('click', () => {
    aText.value = ''; aDate.value = ''; aWrap.hidden = true; aDateBtn.hidden = false;
    document.querySelector('input[name=kindchoice][value=reminder]').checked = true;
    syncKind();
    addModal.classList.add('open');
    setTimeout(() => aText.focus(), 30);
  });
  document.getElementById('aCancel').addEventListener('click', closeAdd);
  addModal.addEventListener('click', e => { if (e.target === addModal) closeAdd(); });
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && addModal.classList.contains('open')) closeAdd();
  });

  // Optional date: reveal the date picker only when "+ Date" is tapped (defaults to today).
  aDateBtn.addEventListener('click', () => {
    aWrap.hidden = false; aDateBtn.hidden = true;
    if (!aDate.value) aDate.value = TODAY;
    aDate.focus();
    if (aDate.showPicker) { try { aDate.showPicker(); } catch (_) {} }
  });
  document.getElementById('aDateClear').addEventListener('click', () => {
    aDate.value = ''; aWrap.hidden = true; aDateBtn.hidden = false;
  });

  // Pick the right action right before submitting: add | add_event | add_note.
  document.getElementById('addForm').addEventListener('submit', () => {
    const kind = document.querySelector('input[name=kindchoice]:checked').value;
    document.getElementById('aAction').value = kind === 'reminder' ? 'add' : 'add_' + kind;
  });

  // ----- Edit mode: reveal the X delete buttons + drag handles -----
  const editBtn = document.getElementById('editBtn');
  const doneBtn = document.getElementById('doneBtn');
  // Editing temporarily forces "show all" on, but the saved preference (remShowDone)
  // is untouched — so tapping Done restores whatever Show Completed state you had before Edit.
  let editShowDone = true;   // transient view state while editing
  const applyShowDone = () => {
    const on = document.body.classList.contains('editing')
      ? editShowDone
      : (localStorage.getItem('remShowDone') === '1');
    document.body.classList.toggle('show-done', on);
  };
  const setEdit = (on) => {
    document.body.classList.toggle('editing', on);
    if (!on) document.body.classList.remove('can-undo');   // tapping Done clears the Undo button
    editBtn.textContent = on ? 'Done' : 'Edit';
    localStorage.setItem('remEditing', on ? '1' : '0');
    if (on) { editShowDone = true; }                       // entering edit auto-shows completed (view only)
    applyShowDone();
  };
  setEdit(localStorage.getItem('remEditing') === '1');   // stay in edit mode across folder/section adds
  // Undo shows only immediately after a delete (server redirects back with ?undo=1).
  if (new URLSearchParams(location.search).get('undo') === '1') {
    document.body.classList.add('can-undo');
    const u = new URL(location.href); u.searchParams.delete('undo');
    history.replaceState(null, '', u);
  }
  editBtn.addEventListener('click', () => setEdit(!document.body.classList.contains('editing')));
  document.getElementById('undoBtn').addEventListener('click', () => document.getElementById('undoForm').submit());
  doneBtn.addEventListener('click', () => {
    if (document.body.classList.contains('editing')) {
      editShowDone = !editShowDone;                        // transient toggle while editing
    } else {
      localStorage.setItem('remShowDone', localStorage.getItem('remShowDone') === '1' ? '0' : '1');
    }
    applyShowDone();
  });

  // ----- Drag to reorder (pointer events => works with touch; edit mode only) -----
  const CSRF = '<?= $csrf ?>', VIEW = '<?= e($view) ?>';
  let dragLi = null, dragSection = null;

  const persistOrder = () => {
    const order = [];
    document.querySelectorAll('ul.rlist').forEach(ul => {
      const section = ul.dataset.section || '';
      ul.querySelectorAll(':scope > li[data-id]').forEach(li => order.push({ id: li.dataset.id, section }));
    });
    const sections = [...document.querySelectorAll('.section-group')].map(g => g.dataset.section);
    order.forEach((o, i) => {                          // keep the drag order stable
      const li = document.querySelector('li[data-id="' + o.id + '"]');
      if (li) li.dataset.pos = i;
    });
    const body = new URLSearchParams({ csrf: CSRF, action: 'reorder', view: VIEW,
      order: JSON.stringify(order), sections: JSON.stringify(sections) });
    fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body }).catch(() => location.reload());
  };

  let pressTimer = null, pid = null, sx = 0, sy = 0, tapTextLi = null, suppressClick = false;
  const beginItem = (li) => {
    dragLi = li; li.classList.add('dragging');
    try { li.setPointerCapture(pid); } catch (_) {}
    if (navigator.vibrate) navigator.vibrate(12);
  };
  const cancelPress = () => { if (pressTimer) { clearTimeout(pressTimer); pressTimer = null; } tapTextLi = null; };

  function startInlineEdit(li) {
    const span = li.querySelector(':scope > .text'); if (!span || li.querySelector('input.textedit')) return;
    const id = li.dataset.id, cur = span.textContent;
    const inp = document.createElement('input');
    inp.type = 'text'; inp.className = 'textedit'; inp.value = cur; inp.maxLength = 500;
    span.replaceWith(inp); inp.focus();
    try { inp.setSelectionRange(cur.length, cur.length); } catch (_) {}
    let done = false;
    const commit = (save) => {
      if (done) return; done = true;
      const val = inp.value.trim();
      const ns = document.createElement('span'); ns.className = 'text';
      ns.textContent = (save && val) ? val : cur;
      inp.replaceWith(ns);
      if (save && val && val !== cur) {
        const body = new URLSearchParams({ csrf: CSRF, action: 'edit_text', view: VIEW, id, text: val });
        fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body }).catch(() => location.reload());
      }
    };
    inp.addEventListener('keydown', e => {
      if (e.key === 'Enter') { e.preventDefault(); commit(true); }
      else if (e.key === 'Escape') { e.preventDefault(); commit(false); }
    });
    inp.addEventListener('blur', () => commit(true));
  }

  document.addEventListener('pointerdown', (e) => {
    if (!document.body.classList.contains('editing')) return;
    const secHandle = e.target.closest('.sec-handle');
    const remHandle = e.target.closest('.drag-handle');
    pid = e.pointerId; sx = e.clientX; sy = e.clientY;
    if (secHandle) {
      dragSection = secHandle.closest('.section-group');
      if (!dragSection) return;
      e.preventDefault();
      dragSection.classList.add('dragging');
      secHandle.setPointerCapture(e.pointerId);
    } else if (remHandle) {
      dragLi = remHandle.closest('li[data-id]');
      if (!dragLi) return;
      e.preventDefault();
      dragLi.classList.add('dragging');
      remHandle.setPointerCapture(e.pointerId);
    } else {
      // Hold anywhere on a reminder to drag it; a short tap on its text edits it.
      if (e.target.closest('.check, .del, form, input')) return;
      const li = e.target.closest('li[data-id]'); if (!li) return;
      tapTextLi = e.target.closest('.text') ? li : null;
      pressTimer = setTimeout(() => { pressTimer = null; beginItem(li); }, 280);
    }
  });

  document.addEventListener('pointermove', (e) => {
    if (pressTimer) {                                  // waiting on a hold: movement = scroll/tap -> cancel
      if (Math.abs(e.clientX - sx) > 10 || Math.abs(e.clientY - sy) > 10) cancelPress();
      return;
    }
    if (!dragLi && !dragSection) return;
    e.preventDefault();
    const under = document.elementFromPoint(e.clientX, e.clientY);
    if (!under) return;
    if (dragLi) {
      const overLi = under.closest('li[data-id]');
      if (overLi && overLi !== dragLi) {
        const rect  = overLi.getBoundingClientRect();
        const after = e.clientY > rect.top + rect.height / 2;
        overLi.parentNode.insertBefore(dragLi, after ? overLi.nextSibling : overLi);
      } else {
        const ul = under.closest('ul.rlist');
        if (ul && ul !== dragLi.parentNode) ul.appendChild(dragLi);
      }
    } else {
      const overGroup = under.closest('.section-group');
      if (overGroup && overGroup !== dragSection) {
        const rect  = overGroup.getBoundingClientRect();
        const after = e.clientY > rect.top + rect.height / 2;
        overGroup.parentNode.insertBefore(dragSection, after ? overGroup.nextSibling : overGroup);
      }
    }
  }, { passive: false });

  const endDrag = () => {
    const wasPress = !!pressTimer;      // timer still pending => it was a short tap, not a drag
    const tapLi = tapTextLi;
    cancelPress();
    if (dragLi) dragLi.classList.remove('dragging');
    if (dragSection) dragSection.classList.remove('dragging');
    const wasDrag = !!(dragLi || dragSection);
    dragLi = null; dragSection = null;
    if (wasDrag) {
      suppressClick = true; setTimeout(() => { suppressClick = false; }, 350);
      persistOrder();
      return;
    }
    if (wasPress && tapLi) { startInlineEdit(tapLi); }   // tapped a reminder's text -> edit it
  };
  document.addEventListener('pointerup', endDrag);
  document.addEventListener('pointercancel', endDrag);
  document.addEventListener('click', (e) => { if (suppressClick) { e.preventDefault(); e.stopPropagation(); } }, true);
</script>
<?= folder_modal_script() ?>
<?= chrome_script() ?>
</body>
</html>
