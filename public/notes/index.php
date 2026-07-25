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
require_login('Notes');

$cfg      = app_config();
$dataFile = user_data_file($cfg['data_dir'], 'notes');

// Ungrouped notes live under a permanent, non-deletable section shown last.
const NOTES_DEFAULT_SECTION = 'Notes';

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$folders = folders_load($cfg['data_dir'])['notes'];

// Which folder is being viewed? ('All' = every note)
$view = (string) ($_REQUEST['view'] ?? $_GET['folder'] ?? 'All');
if ($view !== 'All' && !in_array($view, $folders, true)) {
    $view = 'All';
}
// New notes land in the viewed folder, or the chosen default when viewing All.
$defFolder = folder_default_get($cfg['data_dir'], 'notes');
$addTarget = $view === 'All' ? $defFolder : $view;
$listUrl   = _self_path() . '?folder=' . urlencode($view);

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES);
}

/** A stored row is a "section" divider (bold header) rather than a note. */
function is_section(array $it): bool
{
    return ($it['type'] ?? '') === 'section';
}

/**
 * The "+" on a section header: makes a note straight into that section and opens it.
 * A plain submit, since adding a note already means jumping to the editor.
 */
function render_section_add(string $name, string $csrf, string $view, string $folder): void
{
    $label = e($name === '' ? NOTES_DEFAULT_SECTION : $name);
    ?>
    <form method="post" action="" style="display:inline">
      <input type="hidden" name="csrf" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="view" value="<?= e($view) ?>">
      <input type="hidden" name="folder" value="<?= e($folder) ?>">
      <input type="hidden" name="section" value="<?= e($name) ?>">
      <button type="submit" class="sec-add" title="New note in <?= $label ?>"
              aria-label="New note in <?= $label ?>">+</button>
    </form>
    <?php
}

function load_notes(string $file): array { return store_read($file); }
function save_notes(string $file, array $notes): void { store_write($file, array_values($notes)); }

// --- Handle mutations (POST -> redirect -> GET) ---
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action'])) {
    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Bad request (invalid CSRF token).');
    }

    // Folder actions.
    if ($_POST['action'] === 'add_folder') {
        $name = folder_clean((string) ($_POST['name'] ?? ''));
        folders_add($cfg['data_dir'], 'notes', $name);
        header('Location: ' . _self_path() . '?folder=' . urlencode($name !== '' ? $name : 'All') . '&edit=1');
        exit;
    }
    if ($_POST['action'] === 'set_default_folder') {
        folder_default_set($cfg['data_dir'], 'notes', (string) ($_POST['name'] ?? ''));
        header('Location: ' . _self_path() . '?folder=' . urlencode((string) ($_POST['view'] ?? 'All')) . '&edit=1');
        exit;
    }
    if ($_POST['action'] === 'delete_folder') {
        $name  = (string) ($_POST['name'] ?? '');
        folders_delete($cfg['data_dir'], 'notes', $name);
        $notes = load_notes($dataFile);
        foreach ($notes as &$n) {
            if (!is_section($n) && ($n['folder'] ?? FOLDER_DEFAULT) === $name) { $n['folder'] = FOLDER_DEFAULT; }
        }
        unset($n);
        save_notes($dataFile, $notes);
        header('Location: ' . _self_path() . '?folder=All&edit=1');
        exit;
    }

    // Section actions (bold headers grouping notes; stored in the notes file).
    if ($_POST['action'] === 'add_section') {
        $name = folder_clean((string) ($_POST['name'] ?? ''));
        if ($name !== '' && strcasecmp($name, NOTES_DEFAULT_SECTION) !== 0) {   // "Notes" is reserved
            $notes = load_notes($dataFile);
            $dup   = false;
            foreach ($notes as $it) {
                if (is_section($it) && strcasecmp((string) ($it['name'] ?? ''), $name) === 0) { $dup = true; break; }
            }
            if (!$dup) {
                $notes[] = ['id' => bin2hex(random_bytes(6)), 'type' => 'section', 'name' => $name, 'created' => time()];
                save_notes($dataFile, $notes);
            }
        }
        header('Location: ' . $listUrl . '&edit=1');
        exit;
    }
    if ($_POST['action'] === 'delete_section') {
        $name  = (string) ($_POST['name'] ?? '');
        $notes = load_notes($dataFile);
        $notes = array_filter($notes, fn($it) => !(is_section($it) && ($it['name'] ?? '') === $name));
        foreach ($notes as &$n) {
            if (!is_section($n) && ($n['section'] ?? '') === $name) { $n['section'] = ''; }
        }
        unset($n);
        save_notes($dataFile, $notes);
        header('Location: ' . $listUrl . '&edit=1');
        exit;
    }

    $notes       = load_notes($dataFile);
    $sectionSet  = [];
    foreach ($notes as $it) { if (is_section($it)) { $sectionSet[$it['name']] = true; } }
    $vq          = '?folder=' . urlencode((string) ($_POST['view'] ?? 'All'));

    switch ($_POST['action']) {
        case 'add':
            $folder  = (string) ($_POST['folder'] ?? FOLDER_DEFAULT);
            if (!in_array($folder, $folders, true)) { $folder = FOLDER_DEFAULT; }
            $section = (string) ($_POST['section'] ?? '');
            if ($section !== '' && !isset($sectionSet[$section])) { $section = ''; }
            $id      = bin2hex(random_bytes(6));
            $notes[] = [
                'id'      => $id,
                'title'   => date('m/d/Y h:i a') . ' - Note',
                'date'    => null,
                'body'    => '',
                'folder'  => $folder,
                'section' => $section,
                'created' => time(),
                'updated' => time(),
            ];
            save_notes($dataFile, $notes);
            header('Location: ' . _self_path() . $vq . '&id=' . $id);   // open the new note
            exit;

        case 'save':
            $id      = (string) ($_POST['id'] ?? '');
            $title   = trim((string) ($_POST['title'] ?? ''));
            $date    = trim((string) ($_POST['date'] ?? ''));
            $body    = (string) ($_POST['body'] ?? '');
            $folder  = (string) ($_POST['folder'] ?? FOLDER_DEFAULT);
            if (!in_array($folder, $folders, true)) { $folder = FOLDER_DEFAULT; }
            $section = (string) ($_POST['section'] ?? '');
            if ($section !== '' && !isset($sectionSet[$section])) { $section = ''; }
            foreach ($notes as &$n) {
                if (!is_section($n) && $n['id'] === $id) {
                    $n['title']   = $title === '' ? (date('m/d/Y h:i a', (int) ($n['created'] ?? time())) . ' - Note') : mb_substr($title, 0, 200);
                    $n['date']    = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;
                    $n['body']    = mb_substr($body, 0, 20000);
                    $n['folder']  = $folder;
                    $n['section'] = $section;
                    $n['updated'] = time();
                    break;
                }
            }
            unset($n);
            save_notes($dataFile, $notes);
            if (!empty($_POST['ajax'])) {
                $saved = null;
                foreach ($notes as $x) { if (!is_section($x) && ($x['id'] ?? '') === $id) { $saved = $x; break; } }
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'title' => $saved['title'] ?? '',
                                  'updated' => date('M j, g:ia', (int) ($saved['updated'] ?? time()))]);
                exit;
            }
            header('Location: ' . _self_path() . $vq . '&id=' . $id);
            exit;

        case 'delete':
            $id    = (string) ($_POST['id'] ?? '');
            foreach ($notes as $x) { if (!is_section($x) && ($x['id'] ?? '') === $id) { $_SESSION['undo_note'] = $x; break; } }
            $notes = array_values(array_filter($notes, fn($n) => is_section($n) || $n['id'] !== $id));
            save_notes($dataFile, $notes);
            header('Location: ' . _self_path() . $vq . '&undo=1&edit=1');   // back to the list, still editing
            exit;

        case 'undo':
            if (!empty($_SESSION['undo_note'])) {
                $notes[] = $_SESSION['undo_note'];
                unset($_SESSION['undo_note']);
                save_notes($dataFile, $notes);
            }
            header('Location: ' . _self_path() . $vq);
            exit;
    }

    header('Location: ' . $listUrl);
    exit;
}

// --- Load + shape data ---
$all = load_notes($dataFile);

$sections = [];
foreach ($all as $it) {
    if (is_section($it) && !in_array($it['name'], $sections, true)) { $sections[] = $it['name']; }
}
$noteRows = array_values(array_filter($all, fn($it) => !is_section($it)));

// Is a specific note open? (?id=)
$currentId = (string) ($_GET['id'] ?? '');
$current   = null;
foreach ($noteRows as $n) {
    if ($n['id'] === $currentId) { $current = $n; break; }
}
$editing = $current !== null;

$csrf  = htmlspecialchars($_SESSION['csrf'], ENT_QUOTES);
$today = date('Y-m-d');

// The "+ New section" control for the list view.
$sectionInput =
    '<form method="post" action="" class="newsection" onsubmit="return this.name.value.trim()!==\'\'">'
  . '<input type="hidden" name="csrf" value="' . $csrf . '">'
  . '<input type="hidden" name="action" value="add_section">'
  . '<input type="hidden" name="view" value="' . e($view) . '">'
  . '<input type="text" name="name" placeholder="+ New section" maxlength="40" autocomplete="off">'
  . '</form>';

if (!$editing) {
    // Build the grouped list for the current folder.
    $listNotes = $noteRows;
    if ($view !== 'All') {
        $listNotes = array_values(array_filter($listNotes, fn($n) => ($n['folder'] ?? FOLDER_DEFAULT) === $view));
    }
    // Newest-updated first by default (JS can re-sort).
    usort($listNotes, fn($a, $b) => ($b['updated'] ?? 0) <=> ($a['updated'] ?? 0));

    $ungrouped = [];
    $grouped   = [];
    foreach ($listNotes as $n) {
        $s = (string) ($n['section'] ?? '');
        if ($s !== '' && in_array($s, $sections, true)) { $grouped[$s][] = $n; }
        else { $ungrouped[] = $n; }
    }
}

/** Echo a list of note rows (nothing if empty). */
function render_note_rows(array $rows, string $view, string $csrf): void
{
    if (!$rows) { return; }
    echo '<ul class="nlist">';
    foreach ($rows as $n) {
        $date = $n['date'] ?? '';
        ?>
        <li data-title="<?= e($n['title'] ?? '') ?>" data-date="<?= e($date) ?>" data-updated="<?= (int) ($n['updated'] ?? 0) ?>">
          <a class="noteitem" href="?folder=<?= urlencode($view) ?>&amp;id=<?= e($n['id']) ?>">
            <span class="ntitle"><?= e($n['title'] ?? 'Untitled note') ?></span>
            <?php if ($date !== ''): ?><span class="ndate"><?= e($date) ?></span><?php endif; ?>
            <span class="nchev">&rsaquo;</span>
          </a>
          <form method="post" action="" class="ndel">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="view" value="<?= e($view) ?>">
            <input type="hidden" name="id" value="<?= e($n['id']) ?>">
            <button class="del" type="submit" title="Delete note">&times;</button>
          </form>
        </li>
        <?php
    }
    echo '</ul>';
}
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>Notes</title>
  <meta name="theme-color" content="#111111">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black">
  <meta name="apple-mobile-web-app-title" content="Notes">
  <link rel="apple-touch-icon" href="/reminders/icon-180.png">
  <link rel="icon" href="/reminders/icon-192.png">
  <link rel="manifest" href="/reminders/manifest.webmanifest?v=2">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: system-ui, sans-serif; background: #111; color: #eee;
      min-height: 100vh; padding: 1.5rem 1rem;
    }
    .wrap { max-width: 720px; margin: 0 auto; }
    header {
      display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.25rem;
    }
    header h1 { font-size: 1.5rem; }
    header .titlebar { display: flex; align-items: center; gap: 0.6rem; }
    header nav { display: flex; align-items: center; gap: 0.5rem; }
    header nav a { color: #888; text-decoration: none; font-size: 0.85rem; }
    header nav a:hover { color: #fff; }
    header nav .who {
      color: #34d399; font-size: 0.8rem; border: 1px solid #2a4a3d;
      border-radius: 999px; padding: 0.15rem 0.6rem;
    }

    /* List view — pills sized to match the Calendar's day-panel buttons. */
    .listbar { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1rem; }
    .listbar .newnote {
      padding: 0.35rem 0.9rem; background: #34d399; color: #06251b; border: none;
      border-radius: 999px; font-size: 0.9rem; font-weight: 700; cursor: pointer; white-space: nowrap;
      font-family: inherit;
    }
    .listbar .newnote:hover { background: #52e0ac; }
    .listbar select {
      padding: 0.5rem 0.5rem; background: #1a1a1a; border: 1px solid #333;
      border-radius: 6px; color: #eee; font-size: 0.9rem; color-scheme: dark; cursor: pointer;
    }
    .listbar select:focus { outline: none; border-color: #888; }

    .section-head { display: flex; align-items: center; gap: 0.5rem; margin: 1.5rem 0 0.4rem; }
    .section-title { font-weight: 700; font-size: 1.05rem; color: #f0b429; }
    .sec-add {
      flex: 0 0 auto; background: none; border: 1px solid #2a4a3d; color: #34d399;
      border-radius: 999px; width: 24px; height: 24px; font-size: 1rem; line-height: 1;
      cursor: pointer; font-family: inherit; display: inline-flex;
      align-items: center; justify-content: center;
    }
    .sec-add:hover { border-color: #34d399; background: #14251f; }
    .section-del {
      background: none; border: 1px solid #2a2a2a; color: #666; border-radius: 6px;
      padding: 0.1rem 0.45rem; font-size: 0.85rem; line-height: 1; cursor: pointer;
    }
    .section-del:hover { border-color: #f66; color: #f66; }

    ul.nlist { list-style: none; margin-bottom: 0.5rem; }
    ul.nlist li { border-bottom: 1px solid #222; display: flex; align-items: center; }
    .noteitem {
      flex: 1; display: flex; align-items: center; gap: 0.6rem; padding: 0.85rem 0.25rem;
      text-decoration: none; color: #eee;
    }
    .noteitem:hover { background: #171717; }
    /* Edit mode: delete buttons hidden until "Edit" */
    .ndel .del, .section-del { display: none; }
    body.editing .ndel .del, body.editing .section-del { display: inline-block; }
    .ndel .del {
      background: none; border: 1px solid #444; color: #ccc; cursor: pointer; margin-left: 0.5rem;
      border-radius: 6px; padding: 0.3rem 0.55rem; font-size: 0.95rem; line-height: 1;
    }
    .ndel .del:hover { border-color: #f66; color: #f66; }
    .listbar .undo {
      padding: 0.35rem 0.9rem; background: none; border: 1px solid #444; color: #ccc;
      border-radius: 999px; font-size: 0.9rem; cursor: pointer; display: none; font-family: inherit;
      margin-left: auto;   /* sits opposite + New note */
    }
    .listbar .undo:hover { border-color: #888; color: #fff; }
    body.can-undo .listbar .undo { display: inline-block; }   /* only right after a delete */
    .noteitem .ntitle { flex: 1; font-size: 1.02rem; word-break: break-word; }
    .noteitem .ndate {
      font-size: 0.72rem; color: #b9a7f5; background: #241a3a; padding: 0.15rem 0.5rem;
      border-radius: 999px; white-space: nowrap;
    }
    .noteitem .nchev { color: #555; font-size: 1.1rem; }
    .empty { color: #666; text-align: center; padding: 2rem 0; }

    /* Editor view */
    .backbar { margin-bottom: 1rem; }
    .backbar a { color: #34d399; text-decoration: none; font-size: 0.9rem; }
    .backbar a:hover { text-decoration: underline; }
    .editor { display: flex; flex-direction: column; gap: 0.6rem; }
    .editor .row { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center; }
    .editor input[type=text] {
      flex: 1 1 300px; padding: 0.6rem 0.75rem; background: #1a1a1a; border: 1px solid #333;
      border-radius: 6px; color: #eee; font-size: 1.05rem; font-weight: 600;
    }
    .editor input[type=date] {
      padding: 0.6rem 0.5rem; background: #1a1a1a; border: 1px solid #333;
      border-radius: 6px; color: #eee; font-size: 0.95rem; color-scheme: dark;
    }
    .editor select {
      padding: 0.55rem 0.5rem; background: #1a1a1a; border: 1px solid #333;
      border-radius: 6px; color: #eee; font-size: 0.9rem; color-scheme: dark; cursor: pointer;
    }
    .editor select.secsel { border-color: #4a3f2a; color: #f0b429; }
    .editor .adddate {
      background: none; border: 1px dashed #3a5a4d; color: #34d399; border-radius: 6px;
      padding: 0 0.9rem; min-height: 2.4rem; font-size: 0.9rem; cursor: pointer;
    }
    .editor .adddate:hover { background: #14251f; }
    .editor .datewrap { display: inline-flex; align-items: center; gap: 0.35rem; }
    .editor .datewrap[hidden] { display: none; }   /* make [hidden] win over inline-flex */
    .editor .cleardate {
      background: none; border: 1px solid #333; color: #999; border-radius: 6px;
      padding: 0.55rem 0.6rem; font-size: 0.9rem; cursor: pointer; line-height: 1;
    }
    .editor .cleardate:hover { border-color: #f66; color: #f66; }
    .editor input:focus, .editor textarea:focus, .editor select:focus { outline: none; border-color: #888; }
    .editor textarea {
      width: 100%; min-height: 320px; resize: vertical; padding: 0.8rem;
      background: #1a1a1a; border: 1px solid #333; border-radius: 6px; color: #eee;
      font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 0.95rem; line-height: 1.5;
    }
    .editor .actions { display: flex; align-items: center; gap: 0.75rem; }
    .editor button.save {
      padding: 0.6rem 1.2rem; background: #eee; color: #111; border: none;
      border-radius: 6px; font-size: 1rem; font-weight: 700; cursor: pointer;
    }
    .editor button.save:hover { background: #fff; }
    .editor button.del {
      margin-left: auto; background: none; border: none; color: #666;
      font-size: 0.8rem; cursor: pointer;
    }
    .editor button.del:hover { color: #f66; }
    .editor .meta { font-size: 0.72rem; color: #666; }
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
      <div class="titlebar">
        <h1>Notes</h1>
        <?php if (!$editing): ?>
          <button type="button" id="folderMgr" class="folderplus"
                  title="Manage folders" aria-label="Manage folders">+</button>
        <?php endif; ?>
      </div>
    </div>
    <?= render_user_menu(!$editing) ?>
  </header>

<?php if (!$editing): ?>
  <!-- ===== LIST VIEW ===== -->
  <?php render_folder_nav($folders, $view); ?>

  <div class="listbar">
    <form method="post" action="">
      <input type="hidden" name="csrf" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="view" value="<?= e($view) ?>">
      <input type="hidden" name="folder" value="<?= e($addTarget) ?>">
      <button class="newnote" type="submit">+ New note</button>
    </form>
    <button type="button" id="undoBtn" class="undo">Undo</button>
  </div>

  <?php render_folder_modal($folders, $csrf, $view, $defFolder, 'New notes go to'); ?>
  <form id="undoForm" method="post" action="" style="display:none">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="undo">
    <input type="hidden" name="view" value="<?= e($view) ?>">
  </form>

  <?= $sectionInput ?>

  <?php if (!$noteRows && !$sections): ?>
    <p class="empty">No notes yet. Tap <strong>+ New note</strong> to start.</p>
  <?php else: ?>
    <?php foreach ($sections as $sname): ?>
      <?php $rows = $grouped[$sname] ?? []; ?>
      <?php if (!$rows && $view !== 'All') continue; ?>
      <div class="section-head">
        <span class="section-title"><?= e($sname) ?></span>
        <?php render_section_add($sname, $csrf, $view, $addTarget); ?>
        <?= section_edit_button() ?>
        <form method="post" action="" style="display:inline"
              onsubmit="return confirm('Delete this section? Its notes stay, just ungrouped.')">
          <input type="hidden" name="csrf" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="delete_section">
          <input type="hidden" name="view" value="<?= e($view) ?>">
          <input type="hidden" name="name" value="<?= e($sname) ?>">
          <button class="section-del" type="submit" title="Delete section">&times;</button>
        </form>
      </div>
      <?php render_note_rows($rows, $view, $csrf); ?>
    <?php endforeach; ?>

    <!-- Permanent "Notes" group: always last, not deletable. -->
    <div class="section-head">
      <span class="section-title"><?= NOTES_DEFAULT_SECTION ?></span>
      <?php render_section_add('', $csrf, $view, $addTarget); ?>
      <?= section_edit_button() ?>
    </div>
    <?php render_note_rows($ungrouped, $view, $csrf); ?>
  <?php endif; ?>

<?php else: ?>
  <!-- ===== EDITOR VIEW ===== -->
  <div class="backbar"><a href="<?= e($listUrl) ?>">&larr; All notes</a></div>
  <?php
    $hasDate     = !empty($current['date']);
    $noteDefault = date('m/d/Y h:i a', (int) ($current['created'] ?? time())) . ' - Note';
  ?>
  <form class="editor" method="post" action="">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="view" value="<?= e($view) ?>">
    <input type="hidden" name="id" value="<?= e($current['id']) ?>">
    <div class="row">
      <input type="text" name="title" placeholder="Title" maxlength="200"
             value="<?= e($current['title'] ?? '') ?>" data-default="<?= e($noteDefault) ?>" required>
      <button type="button" class="adddate" id="addDateBtn" <?= $hasDate ? 'hidden' : '' ?>>+ Add date</button>
      <span class="datewrap" id="dateWrap" <?= $hasDate ? '' : 'hidden' ?>>
        <input type="date" name="date" id="dateInput" title="Optional date"
               value="<?= e($current['date'] ?? '') ?>">
        <button type="button" class="cleardate" id="clearDateBtn" title="Remove date">&times;</button>
      </span>
    </div>
    <div class="row">
      <select name="folder" title="Folder">
        <?php foreach ($folders as $f): ?>
          <option value="<?= e($f) ?>" <?= ($current['folder'] ?? FOLDER_DEFAULT) === $f ? 'selected' : '' ?>><?= e($f) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="section" class="secsel" title="Section">
        <option value="">Notes</option>
        <?php foreach ($sections as $sname): ?>
          <option value="<?= e($sname) ?>" <?= ($current['section'] ?? '') === $sname ? 'selected' : '' ?>><?= e($sname) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <textarea name="body" placeholder="Write your note…"><?= e($current['body'] ?? '') ?></textarea>
    <div class="actions">
      <span class="meta" id="saveStatus">Saved</span>
      <button class="del" type="submit" name="action" value="delete">Delete</button>
    </div>
  </form>
<?php endif; ?>
</div>
<?php render_tabbar('notes'); ?>
<script>
  const TODAY = '<?= date('Y-m-d') ?>';

  // Editor: optional date toggle.
  const addBtn = document.getElementById('addDateBtn');
  if (addBtn) {
    const wrap  = document.getElementById('dateWrap');
    const input = document.getElementById('dateInput');
    addBtn.addEventListener('click', () => {
      wrap.hidden = false; addBtn.hidden = true;
      if (!input.value) input.value = TODAY;
      input.focus();
      if (input.showPicker) { try { input.showPicker(); } catch (_) {} }
    });
    document.getElementById('clearDateBtn').addEventListener('click', () => {
      input.value = ''; wrap.hidden = true; addBtn.hidden = false;
    });
  }

  // Editor: the default title acts like a placeholder — clears when you type, returns if left blank.
  const titleInput = document.querySelector('.editor input[name=title]');
  if (titleInput) {
    const DEF = titleInput.dataset.default || '';
    titleInput.addEventListener('focus', () => { if (titleInput.value === DEF) titleInput.select(); });
    titleInput.addEventListener('blur', () => { if (titleInput.value.trim() === '') titleInput.value = DEF; });
  }

  // Notes autosave — debounced, no Save button.
  const noteForm = document.querySelector('form.editor');
  if (noteForm) {
    const status = document.getElementById('saveStatus');
    let timer = null;
    const doSave = () => {
      if (status) status.textContent = 'Saving…';
      const fd = new FormData(noteForm);
      fd.set('action', 'save'); fd.set('ajax', '1');
      fetch('', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
          if (status) status.textContent = 'Saved';
          if (d && d.title && titleInput && document.activeElement !== titleInput) titleInput.value = d.title;
        })
        .catch(() => { if (status) status.textContent = 'Save failed'; });
    };
    const schedule = () => { if (status) status.textContent = 'Editing…'; clearTimeout(timer); timer = setTimeout(doSave, 800); };
    noteForm.querySelectorAll('input, textarea, select').forEach(el => {
      el.addEventListener('input', schedule);
      el.addEventListener('change', schedule);
    });
    if (titleInput) titleInput.addEventListener('keydown', e => { if (e.key === 'Enter') e.preventDefault(); });
    document.addEventListener('visibilitychange', () => { if (document.hidden) { clearTimeout(timer); doSave(); } });
  }

  // List: edit mode reveals delete buttons (persisted across adds, like reminders).
  const editBtn = document.getElementById('editBtn');
  if (editBtn) {
    const setEdit = (on) => {
      document.body.classList.toggle('editing', on);
      if (!on) document.body.classList.remove('can-undo');   // tapping Done clears the Undo button
      editBtn.textContent = on ? 'Done' : 'Edit';
    };
    // Always starts off; a structural change redirects back with ?edit=1 to keep it on.
    setEdit(new URLSearchParams(location.search).get('edit') === '1');
    editBtn.addEventListener('click', () => setEdit(!document.body.classList.contains('editing')));
  }

  // Undo appears only immediately after a delete (server redirects back with ?undo=1).
  const undoBtn = document.getElementById('undoBtn');
  if (undoBtn) undoBtn.addEventListener('click', () => document.getElementById('undoForm').submit());
  if (new URLSearchParams(location.search).get('undo') === '1') {
    document.body.classList.add('can-undo');
    const u = new URL(location.href); u.searchParams.delete('undo');
    history.replaceState(null, '', u);
  }

</script>
<?= folder_modal_script() ?>
<?= chrome_script() ?>
</body>
</html>
