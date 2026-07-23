<?php
// Locate the shared lib/ — local dev (../../lib) or NFSN (/home/protected/lib).
$__libDir = null;
foreach ([__DIR__ . '/../../lib', '/home/protected/lib'] as $__c) {
    if (is_file($__c . '/auth.php')) { $__libDir = $__c; break; }
}
require_once $__libDir . '/auth.php';
require_once $__libDir . '/tabbar.php';
require_once $__libDir . '/folders.php';
require_login('Reminders');

$cfg      = app_config();
$dataFile = user_data_file($cfg['data_dir'], 'reminders');

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$folders = folders_load($cfg['data_dir'])['reminders'];

// Which folder is being viewed? ('All' = show everything)
$view = (string) ($_REQUEST['view'] ?? $_GET['folder'] ?? 'All');
if ($view !== 'All' && !in_array($view, $folders, true)) {
    $view = 'All';
}
// New items land in the viewed folder, or General when viewing All.
$addTarget = $view === 'All' ? FOLDER_DEFAULT : $view;
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
          <form method="post" action="" style="display:inline" onsubmit="return confirm('Delete this reminder?')">
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

    // Folder actions don't touch the reminders list.
    if ($_POST['action'] === 'add_folder') {
        $name = folder_clean((string) ($_POST['name'] ?? ''));
        folders_add($cfg['data_dir'], 'reminders', $name);
        header('Location: ' . _self_path() . '?folder=' . urlencode($name !== '' ? $name : 'All'));
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
        if ($name !== '') {
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

    $list        = load_reminders($dataFile);
    $sectionSet  = [];
    foreach ($list as $it) { if (is_section($it)) { $sectionSet[$it['name']] = true; } }

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
            $id   = (string) ($_POST['id'] ?? '');
            $list = array_filter($list, fn($r) => is_section($r) || $r['id'] !== $id);
            break;

        case 'clear_done':
            // Clear completed within the folder being viewed (or all when viewing All).
            $list = array_filter($list, function ($r) use ($view) {
                if (is_section($r) || empty($r['done'])) { return true; }
                return $view !== 'All' && ($r['folder'] ?? FOLDER_DEFAULT) !== $view;
            });
            break;
    }

    save_reminders($dataFile, $list);
    header('Location: ' . $backUrl);
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
if ($view !== 'All') {
    $items = array_values(array_filter($items, fn($r) => ($r['folder'] ?? FOLDER_DEFAULT) === $view));
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
  <link rel="manifest" href="/reminders/manifest.webmanifest">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: system-ui, sans-serif; background: #111; color: #eee;
      min-height: 100vh; padding: 2rem 1rem;
    }
    .wrap { max-width: 640px; margin: 0 auto; }
    header {
      display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 1.5rem;
    }
    header h1 { font-size: 1.5rem; }
    header .meta { font-size: 0.8rem; color: #888; }
    header a { color: #888; text-decoration: none; margin-left: 1rem; }
    header a:hover { color: #fff; }
    header .who {
      color: #34d399; font-size: 0.8rem; border: 1px solid #2a4a3d;
      border-radius: 999px; padding: 0.15rem 0.6rem;
    }

    form.add {
      display: flex; gap: 0.5rem; margin-bottom: 1.5rem; flex-wrap: wrap; align-items: center;
    }
    form.add input[type=text] {
      flex: 1 1 100%; padding: 0.6rem 0.75rem; background: #1a1a1a; border: 1px solid #333;
      border-radius: 6px; color: #eee; font-size: 1rem;
    }
    form.add input[type=date] {
      padding: 0.6rem 0.5rem; background: #1a1a1a; border: 1px solid #333;
      border-radius: 6px; color: #eee; font-size: 0.95rem; color-scheme: dark;
    }
    form.add input:focus, form.add select:focus { outline: none; border-color: #888; }
    form.add button[type=submit] {
      padding: 0.6rem 1.1rem; background: #eee; color: #111; border: none;
      border-radius: 6px; font-size: 1rem; cursor: pointer; font-weight: 600;
    }
    form.add button[type=submit]:hover { background: #fff; }
    form.add .editbtn {
      padding: 0.6rem 1rem; background: #1a1a1a; border: 1px solid #333; color: #ccc;
      border-radius: 6px; font-size: 1rem; cursor: pointer;
    }
    form.add .editbtn:hover { border-color: #888; color: #fff; }
    form.add #editBtn { margin-left: auto; }   /* Edit + Add hug the right edge */
    body.editing form.add .editbtn { background: #34d399; border-color: #34d399; color: #06251b; font-weight: 700; }
    body.show-done form.add #doneBtn { background: #34d399; border-color: #34d399; color: #06251b; font-weight: 700; }
    /* Completed reminders + the clear button stay hidden until "DONE?" is toggled on */
    body:not(.show-done) li.done { display: none; }
    body:not(.show-done) footer { display: none; }
    form.add .adddate {
      background: none; border: 1px dashed #3a5a4d; color: #34d399; border-radius: 6px;
      padding: 0 0.8rem; height: 100%; min-height: 2.4rem; font-size: 0.9rem; cursor: pointer;
    }
    form.add .adddate:hover { background: #14251f; }
    form.add .datewrap { display: inline-flex; align-items: center; gap: 0.35rem; }
    form.add .datewrap[hidden] { display: none; }   /* make [hidden] win over inline-flex */
    form.add .cleardate {
      background: none; border: 1px solid #3a3a3a; color: #999; border-radius: 6px;
      padding: 0.5rem 0.55rem; font-size: 0.9rem; cursor: pointer; line-height: 1;
    }
    form.add .cleardate:hover { border-color: #f66; color: #f66; }
    form.add select {
      padding: 0.6rem 0.5rem; background: #1a1a1a; border: 1px solid #333;
      border-radius: 6px; color: #eee; font-size: 0.9rem; color-scheme: dark; cursor: pointer;
    }
    form.add select.secsel { border-color: #4a3f2a; color: #f0b429; }

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
    .text { flex: 1; font-size: 1rem; word-break: break-word; }
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
    .section-group.dragging { opacity: 0.85; }
    body.editing ul.rlist { min-height: 1.4rem; }
    body.editing ul.rlist:empty { border: 1px dashed #333; border-radius: 6px; margin: 0.25rem 0; }

    .empty { color: #666; text-align: center; padding: 2rem 0; }
    footer { margin-top: 1.5rem; display: flex; justify-content: flex-end; }
    footer button {
      background: none; border: none; color: #666; font-size: 0.8rem; cursor: pointer;
    }
    footer button:hover { color: #f66; }
<?= folder_nav_styles() ?>
    .foldernav .newsection input {
      width: 130px; padding: 0.3rem 0.7rem; background: #1a1a1a; border: 1px dashed #5a4a2a;
      border-radius: 999px; color: #f0b429; font-size: 0.82rem;
    }
    .foldernav .newsection input::placeholder { color: #f0b429; opacity: 0.85; }
    .foldernav .newsection input:focus { outline: none; border-style: solid; border-color: #f0b429; }
<?= tabbar_styles() ?>
  </style>
</head>
<body>
<div class="wrap">
  <header>
    <div>
      <h1>Reminders</h1>
      <div class="meta"><?= e($view) ?> &middot; <?= $openCount ?> open<?= $doneCount ? " &middot; {$doneCount} done" : '' ?></div>
    </div>
    <nav>
      <span class="who"><?= e(current_user() ?? '') ?></span>
      <a href="?logout">Log out</a>
    </nav>
  </header>

  <?php render_folder_nav($folders, $view, $csrf, $sectionInput); ?>

  <form class="add" method="post" action="">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="add">
    <input type="hidden" name="view" value="<?= e($view) ?>">
    <input type="hidden" name="folder" value="<?= e($addTarget) ?>">
    <input type="text" name="text" placeholder="Add a reminder…" maxlength="500" required autofocus>
    <button type="button" class="adddate" id="addDateBtn">+ Date</button>
    <span class="datewrap" id="dateWrap" hidden>
      <input type="date" name="due" id="dueInput" title="Optional due date">
      <button type="button" class="cleardate" id="clearDateBtn" title="Remove date">&times;</button>
    </span>
    <?php if ($sections): ?>
      <select name="section" class="secsel" title="Add to section">
        <option value="">No section</option>
        <?php foreach ($sections as $sname): ?>
          <option value="<?= e($sname) ?>"><?= e($sname) ?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
    <select id="sortSel" title="Sort by" aria-label="Sort by">
      <option value="manual">Sort: Manual</option>
      <option value="date">Sort: Date</option>
      <option value="name">Sort: Name</option>
    </select>
    <button type="button" id="doneBtn" class="editbtn">Show All</button>
    <button type="button" id="editBtn" class="editbtn">Edit</button>
    <button type="submit">Add</button>
  </form>

  <?php if (!$items && !$sections): ?>
    <p class="empty">Nothing yet. Add your first reminder above.</p>
  <?php else: ?>
   <div id="rlist-root">
    <?php render_rows($ungrouped, $csrf, $view, $today, ''); ?>

    <?php foreach ($sections as $sname): ?>
      <?php $rows = $grouped[$sname] ?? []; ?>
      <?php if (!$rows && $view !== 'All') continue; // hide empty sections inside a folder view ?>
      <div class="section-group" data-section="<?= e($sname) ?>">
        <div class="section-head">
          <span class="sec-handle" title="Drag section" aria-hidden="true">&#9776;</span>
          <span class="section-title"><?= e($sname) ?></span>
          <form method="post" action="" style="display:inline"
                onsubmit="return confirm('Delete this section? Its reminders stay, just ungrouped.')">
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

  // Optional date: reveal the date picker only when "+ Date" is tapped (defaults to today).
  const addBtn   = document.getElementById('addDateBtn');
  const wrap     = document.getElementById('dateWrap');
  const dueInput = document.getElementById('dueInput');
  addBtn.addEventListener('click', () => {
    wrap.hidden = false; addBtn.hidden = true;
    if (!dueInput.value) dueInput.value = TODAY;
    dueInput.focus();
    if (dueInput.showPicker) { try { dueInput.showPicker(); } catch (_) {} }
  });
  document.getElementById('clearDateBtn').addEventListener('click', () => {
    dueInput.value = ''; wrap.hidden = true; addBtn.hidden = false;
  });

  // ----- Edit mode: reveal the X delete buttons + drag handles -----
  const editBtn = document.getElementById('editBtn');
  editBtn.addEventListener('click', () => {
    const on = document.body.classList.toggle('editing');
    editBtn.textContent = on ? 'Done' : 'Edit';
  });

  // ----- "DONE?" toggle: reveal completed reminders (and the clear button) -----
  const doneBtn = document.getElementById('doneBtn');
  if (localStorage.getItem('remShowDone') === '1') document.body.classList.add('show-done');
  doneBtn.addEventListener('click', () => {
    const on = document.body.classList.toggle('show-done');
    localStorage.setItem('remShowDone', on ? '1' : '0');
  });

  // ----- Sort menu (Manual = the saved drag order, via data-pos) -----
  const sortSel = document.getElementById('sortSel');
  const applySort = (mode) => {
    document.querySelectorAll('ul.rlist').forEach(ul => {
      Array.from(ul.children).sort((a, b) => {
        if (mode === 'manual') return (Number(a.dataset.pos) || 0) - (Number(b.dataset.pos) || 0);
        const ad = a.dataset.done === '1', bd = b.dataset.done === '1';
        if (ad !== bd) return ad ? 1 : -1;
        if (mode === 'name') {
          const c = (a.dataset.text || '').localeCompare(b.dataset.text || '', undefined, { sensitivity: 'base' });
          if (c) return c;
        } else {
          const x = a.dataset.due || '', y = b.dataset.due || '';
          if (x !== y) return !x ? 1 : (!y ? -1 : (x < y ? -1 : 1));
        }
        return (Number(b.dataset.created) || 0) - (Number(a.dataset.created) || 0);
      }).forEach(li => ul.appendChild(li));
    });
  };
  const savedSort = localStorage.getItem('remSort') || 'manual';
  sortSel.value = savedSort;
  applySort(savedSort);
  sortSel.addEventListener('change', () => {
    localStorage.setItem('remSort', sortSel.value);
    applySort(sortSel.value);
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
    order.forEach((o, i) => {                          // keep Manual order consistent after a drag
      const li = document.querySelector('li[data-id="' + o.id + '"]');
      if (li) li.dataset.pos = i;
    });
    localStorage.setItem('remSort', 'manual');
    sortSel.value = 'manual';
    const body = new URLSearchParams({ csrf: CSRF, action: 'reorder', view: VIEW,
      order: JSON.stringify(order), sections: JSON.stringify(sections) });
    fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body }).catch(() => location.reload());
  };

  document.addEventListener('pointerdown', (e) => {
    if (!document.body.classList.contains('editing')) return;
    const secHandle = e.target.closest('.sec-handle');
    const remHandle = e.target.closest('.drag-handle');
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
    }
  });

  document.addEventListener('pointermove', (e) => {
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
    if (dragLi) dragLi.classList.remove('dragging');
    if (dragSection) dragSection.classList.remove('dragging');
    if (!dragLi && !dragSection) return;
    dragLi = null; dragSection = null;
    persistOrder();
  };
  document.addEventListener('pointerup', endDrag);
  document.addEventListener('pointercancel', endDrag);
</script>
</body>
</html>
