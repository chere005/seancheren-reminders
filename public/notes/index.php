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
require_once $__libDir . '/richtext.php';   // note-body toolbar + sanitiser
require_login('Notes');

$cfg = app_config();

// Ungrouped notes live under a permanent, non-deletable section shown last.
const NOTES_DEFAULT_SECTION = 'Notes';

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$me           = current_user() ?? '';
$myFolders    = folders_load($cfg['data_dir'])['notes'];
$folderColors = folder_colors($cfg['data_dir'], 'notes');

// Folders the other person shared with me, shown in the picker as "@aki:Recipes".
$partner       = share_partner();
$sharedFolders = shared_note_folders($cfg['data_dir'], $partner);

/**
 * Which folder is being viewed? 'All', one of mine, or '@<partner>:<folder>' for a
 * shared one — in which case every read and write goes to their notes file instead.
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

$dataFile = user_data_file($cfg['data_dir'], 'notes', $isShared ? $owner : null);
$folders  = $isShared ? folders_load($cfg['data_dir'], $owner)['notes'] : $myFolders;

// New notes land in the viewed folder, or the chosen default when viewing All.
$defFolder = folder_default_get($cfg['data_dir'], 'notes');
$addTarget = $viewFolder === 'All' ? $defFolder : $viewFolder;
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
    <form method="post" action="" class="sec-add-form" style="display:inline">
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

function load_notes(string $file): array { return sections_migrate(store_read($file)); }
function save_notes(string $file, array $notes): void { store_write($file, array_values($notes)); }

// --- Handle mutations (POST -> redirect -> GET) ---
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action'])) {
    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Bad request (invalid CSRF token).');
    }

    // A shared folder is someone else's list: you can work its notes, but its folders
    // and sections stay theirs to arrange.
    if ($isShared && in_array($_POST['action'], ['add_section', 'delete_section', 'delete_folder', 'reorder'], true)) {
        http_response_code(403);
        exit('That belongs to ' . htmlspecialchars(share_name($owner), ENT_QUOTES) . '.');
    }

    // Sharing: the same window the other apps have, now reached from the Settings ⋮.
    if ($_POST['action'] === 'share_set' && $partner && !$isShared) {
        share_handle_set($cfg['data_dir'], $me, array_keys(share_calendars($cfg['data_dir'], $me)),
                         folders_load($cfg['data_dir'])['reminders'], $myFolders);
    }

    // Nothing destructive happens without the confirmed second press.
    if (in_array($_POST['action'], ['delete', 'delete_section', 'delete_folder'], true)
        && empty($_POST['confirm'])) {
        header('Location: ' . $listUrl . '&edit=1');
        exit;
    }

    // Folder actions.
    if ($_POST['action'] === 'add_folder') {
        $name = folder_clean((string) ($_POST['name'] ?? ''));
        folders_add($cfg['data_dir'], 'notes', $name);
        // fm=1 reopens the folder manager so adding one doesn't close it.
        $stay = !empty($_POST['edit']) ? '&edit=1' : '';
        header('Location: ' . _self_path() . '?folder=' . urlencode($name !== '' ? $name : 'All') . $stay . '&fm=1');
        exit;
    }
    if ($_POST['action'] === 'set_default_folder') {
        folder_default_set($cfg['data_dir'], 'notes', (string) ($_POST['name'] ?? ''));
        header('Location: ' . _self_path() . '?folder=' . urlencode((string) ($_POST['view'] ?? 'All')) . '&edit=1&fm=1');
        exit;
    }
    if ($_POST['action'] === 'set_folder_color') {
        $cname = (string) ($_POST['name'] ?? '');
        if (folder_shared_key_parse($cname)) {
            // Recolouring a partner's shared folder: stored in my own file, keyed @partner:folder.
            folder_shared_color_set($cfg['data_dir'], 'notes', $cname,
                                    (string) ($_POST['color'] ?? ''), $sharedFolders);
        } else {
            folder_color_set($cfg['data_dir'], 'notes', $cname, (string) ($_POST['color'] ?? ''));
        }
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
        header('Location: ' . _self_path() . '?folder=All&edit=1&fm=1');
        exit;
    }

    // Section actions (bold headers grouping notes; stored in the notes file).
    if ($_POST['action'] === 'add_section') {
        $name = folder_clean((string) ($_POST['name'] ?? ''));
        $secFolder = $viewFolder === 'All' ? $defFolder : $viewFolder;   // sections belong to a folder
        if ($name !== '' && strcasecmp($name, NOTES_DEFAULT_SECTION) !== 0) {   // "Notes" is reserved
            $notes = load_notes($dataFile);
            $dup   = false;
            foreach ($notes as $it) {
                if (is_section($it) && ($it['folder'] ?? '') === $secFolder
                    && strcasecmp((string) ($it['name'] ?? ''), $name) === 0) { $dup = true; break; }
            }
            if (!$dup) {
                // Prepend so a new section lands at the top of the list.
                array_unshift($notes, ['id' => bin2hex(random_bytes(6)), 'type' => 'section',
                            'name' => $name, 'folder' => $secFolder, 'created' => time()]);
                save_notes($dataFile, $notes);
            }
        }
        // Stay in edit mode only if we were already in it (the form carries `edit`).
        header('Location: ' . $listUrl . (!empty($_POST['edit']) ? '&edit=1' : ''));
        exit;
    }
    if ($_POST['action'] === 'rename_section') {
        $secFolder = (string) ($_POST['folder'] ?? $viewFolder);
        save_notes($dataFile, section_rename(load_notes($dataFile), (string) ($_POST['name'] ?? ''),
                                             (string) ($_POST['newname'] ?? ''), $secFolder));
        header('Location: ' . $listUrl . '&edit=1');
        exit;
    }
    if ($_POST['action'] === 'delete_section') {
        $name      = (string) ($_POST['name'] ?? '');
        $secFolder = (string) ($_POST['folder'] ?? $viewFolder);
        $notes = load_notes($dataFile);
        // Only this folder's copy of the section goes; other folders keep theirs.
        $notes = array_filter($notes, fn($it) => !(is_section($it)
            && ($it['name'] ?? '') === $name && ($it['folder'] ?? '') === $secFolder));
        foreach ($notes as &$n) {
            if (!is_section($n) && ($n['section'] ?? '') === $name
                && ($n['folder'] ?? FOLDER_DEFAULT) === $secFolder) { $n['section'] = ''; }
        }
        unset($n);
        save_notes($dataFile, $notes);
        header('Location: ' . $listUrl . '&edit=1');
        exit;
    }

    // Reorder / re-section notes after a drag (AJAX). order = [{id, section}, …] top-to-bottom.
    if ($_POST['action'] === 'reorder') {
        $order = json_decode((string) ($_POST['order'] ?? '[]'), true);
        if (!is_array($order)) { $order = []; }
        $notes = load_notes($dataFile);

        // Sections are per-folder: reorder only the viewed folder's, and re-section notes
        // against sections that exist in their own folder.
        $secExists      = [];   // "folder\x1Fname" => true
        $thisFolderSecs = [];   // name => row (viewed folder)
        $otherSecs      = [];
        $byId           = [];
        foreach ($notes as $it) {
            if (is_section($it)) {
                $f = $it['folder'] ?? FOLDER_DEFAULT;
                $secExists[$f . "\x1F" . $it['name']] = true;
                if ($viewFolder !== 'All' && $f === $viewFolder) { $thisFolderSecs[$it['name']] = $it; }
                else { $otherSecs[] = $it; }
            } else {
                $byId[$it['id']] = $it;
            }
        }
        $secOrder = json_decode((string) ($_POST['sections'] ?? '[]'), true);
        if (!is_array($secOrder)) { $secOrder = []; }
        $sectionRows = [];
        foreach ($secOrder as $nm) {
            if (isset($thisFolderSecs[$nm])) { $sectionRows[] = $thisFolderSecs[$nm]; unset($thisFolderSecs[$nm]); }
        }
        foreach ($thisFolderSecs as $e) { $sectionRows[] = $e; }
        $sectionRows = array_merge($sectionRows, $otherSecs);

        $newRows = [];
        $used    = [];
        foreach ($order as $o) {
            $id = (string) ($o['id'] ?? '');
            if ($id === '' || !isset($byId[$id]) || isset($used[$id])) { continue; }
            $row = $byId[$id];
            $sec = (string) ($o['section'] ?? '');
            $f   = $row['folder'] ?? FOLDER_DEFAULT;
            if ($sec !== '' && !isset($secExists[$f . "\x1F" . $sec])) { $sec = ''; }
            $row['section'] = $sec;
            $newRows[]      = $row;
            $used[$id]      = true;
        }
        // Notes the drag never saw (they're in another folder) keep their place at the end.
        foreach ($notes as $it) {
            if (!is_section($it) && !isset($used[$it['id']])) { $newRows[] = $it; }
        }

        save_notes($dataFile, array_merge($sectionRows, $newRows));
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
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
            // Prepend so a new note lands at the top of its section.
            array_unshift($notes, [
                'id'      => $id,
                'title'   => date('m/d/Y h:i a') . ' - Note',
                'date'    => null,
                'body'    => '',
                'folder'  => $folder,
                'section' => $section,
                'created' => time(),
                'updated' => time(),
            ]);
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
                    // The body is HTML now, so it only ever gets stored sanitised.
                    $n['body']    = mb_substr(rt_sanitize($body), 0, 20000);
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
            $notes = array_values(array_filter($notes, fn($n) => is_section($n) || $n['id'] !== $id));
            save_notes($dataFile, $notes);
            header('Location: ' . _self_path() . $vq . '&edit=1');   // back to the list, still editing
            exit;
    }

    header('Location: ' . $listUrl);
    exit;
}

// --- Load + shape data ---
$all = load_notes($dataFile);

// Section rows tagged with their folder. A folder view shows only its own; All shows every folder's.
$secRows = [];
foreach ($all as $it) {
    if (is_section($it) && ($viewFolder === 'All' || ($it['folder'] ?? FOLDER_DEFAULT) === $viewFolder)) { $secRows[] = $it; }
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

// Folder picker contents: mine under my name, then whatever the other person shared.
// Shared folders take my own colour override first (kept in my file), then the owner's
// colour, then a shared-palette default — the same rule Reminders uses.
$theirColors     = $partner ? folder_colors($cfg['data_dir'], 'notes', $partner) : [];
$sharedOverrides = folder_shared_colors($cfg['data_dir'], 'notes');
$sharedRows      = [];
$folderGroups = [['label' => $sharedFolders ? share_name($me) : '',
                  'options' => array_map(fn($f) => [$f, $f, $folderColors[$f] ?? app_palette('notes')[0]], $myFolders)]];
if ($sharedFolders) {
    $sharedOpts = [];
    foreach ($sharedFolders as $i => $f) {
        $key = '@' . $partner . ':' . $f;
        $col = folder_shared_color($sharedOverrides, $theirColors, 'notes', $key, $f, $i);
        $sharedOpts[] = [$key, $f, $col];
        $sharedRows[] = ['key' => $key, 'label' => $f, 'color' => $col];
    }
    $folderGroups[] = ['label' => share_name($partner), 'options' => $sharedOpts];
}

/** The colour of a folder as this viewer sees it, for the dot on a section header —
 *  the same resolution the picker uses. (Reminders carries the identical helper.) */
$folderDotColor = function (string $f) use ($isShared, $partner, $folderColors, $theirColors,
                                            $sharedOverrides, $sharedFolders): string {
    if (!$isShared) { return $folderColors[$f] ?? app_palette('notes')[0]; }
    $i = array_search($f, $sharedFolders, true);
    return folder_shared_color($sharedOverrides, $theirColors, 'notes',
                               '@' . $partner . ':' . $f, $f, $i === false ? 0 : (int) $i);
};

// Calendars as [id, name] pairs, for the Settings share window.
$shareCals = [];
if ($partner && !$isShared) {
    foreach (share_calendars($cfg['data_dir'], $me) as $cid => $cname) { $shareCals[] = [$cid, $cname]; }
}

// The "+ Section" control for the list view.
$sectionInput =
    '<form method="post" action="" class="newsection" id="newSecForm" hidden'
  . ' onsubmit="return this.name.value.trim()!==\'\'">'
  . '<input type="hidden" name="csrf" value="' . $csrf . '">'
  . '<input type="hidden" name="action" value="add_section">'
  . '<input type="hidden" name="view" value="' . e($view) . '">'
  . '<input type="text" name="name" placeholder="+ Section" maxlength="40" autocomplete="off">'
  . '<button type="submit" class="plus" title="Add section">+</button>'
  . '</form>';

if (!$editing) {
    // Build the grouped list for the current folder.
    $listNotes = $noteRows;
    if ($viewFolder !== 'All') {
        $listNotes = array_values(array_filter($listNotes, fn($n) => ($n['folder'] ?? FOLDER_DEFAULT) === $viewFolder));
    }
    // Stored order is drag order, as in Reminders and the bookshelf's notes.

    // Group by folder+name so same-named sections in two folders stay distinct; keyed by row id.
    $secByKey = [];
    foreach ($secRows as $s) { $secByKey[($s['folder'] ?? FOLDER_DEFAULT) . "\x1F" . $s['name']] = $s['id']; }
    $ungrouped = [];
    $grouped   = [];
    foreach ($listNotes as $n) {
        $s   = (string) ($n['section'] ?? '');
        $key = ($n['folder'] ?? FOLDER_DEFAULT) . "\x1F" . $s;
        if ($s !== '' && isset($secByKey[$key])) { $grouped[$secByKey[$key]][] = $n; }
        else { $ungrouped[] = $n; }
    }
}

/** Echo a list of note rows. Always emitted, since an empty one is a drag target. */
function render_note_rows(array $rows, string $view, string $csrf, string $section = '', string $cls = ''): void
{
    echo '<ul class="nlist' . ($cls !== '' ? ' ' . $cls : '') . '" data-section="' . e($section) . '">';
    foreach ($rows as $n) {
        $date = $n['date'] ?? '';
        ?>
        <li data-id="<?= e($n['id']) ?>">
          <span class="drag-handle" title="Drag to reorder" aria-hidden="true">&#9776;</span>
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
            <button class="del needs-confirm" type="submit" title="Delete note">&times;</button>
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
      overscroll-behavior-y: none;               /* no rubber-band scroll when it all fits */
    }
    .wrap { max-width: 640px; margin: 0 auto; }   /* same column as Reminders + Calendar */
    header {
      display: flex; align-items: center; justify-content: space-between;
    }
    header h1 { font-size: 1.35rem; }   /* same as the Calendar's */
    header .titlebar { display: flex; align-items: center; gap: 0.85rem; }
    header nav { display: flex; align-items: center; gap: 0.5rem; }
    header nav a { color: #888; text-decoration: none; font-size: 0.85rem; }
    header nav a:hover { color: #fff; }
    header nav .who {
      color: var(--accent); font-size: 0.8rem; border: 1px solid #2a4a3d;
      border-radius: 999px; padding: 0.15rem 0.6rem;
    }

    /* List view — pills sized to match the Calendar's day-panel buttons. */
    /* The row under the rule starts where Reminders' folder row does. */
    /* The buttons line up with the + on the section headers under them. */
    /* The row lines up with the + on the section headers under it. */
    .listbar { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1rem; padding-left: 0; }
    /* One height for everything on this row, whichever of them is showing. */
    .listbar .listedit, .listbar .newsection input, .listbar .newsection .plus { height: 32px; }
    .listbar .listedit {
      background: none; border: 1px solid #333; color: #ccc; border-radius: 999px;
      padding: 0.35rem 0.9rem; font-size: 0.9rem; cursor: pointer; white-space: nowrap;
      font-family: inherit;
    }
    .listbar .listedit:hover { border-color: #888; color: #fff; }
    .listbar select {
      padding: 0.5rem 0.5rem; background: #1a1a1a; border: 1px solid #333;
      border-radius: 6px; color: #eee; font-size: 0.9rem; color-scheme: dark; cursor: pointer;
    }
    .listbar select:focus { outline: none; border-color: #888; }

    /* Same side padding as a row, so the section's X lands under the rows' Xs. */
    /* Folder labels, shown above a run of that folder's sections when "All" mixes more
       than one folder together — bigger and a shade darker than a section header. */
    .folder-head { display: flex; align-items: center; gap: 0.35rem; margin: 1.75rem 0 0 0.25rem; }
    .folder-label { font-weight: 700; font-size: 1.3rem; color: #b8860b; }
    /* The dot sits a little further off the name than the chevron does. */
    .folder-head .fdot { margin-left: 0.4rem; }
    .folder-divider { border-top: 1px solid #2a2a2a; margin: 1.5rem 0 0; }
    .section-head { display: flex; align-items: center; gap: 0.75rem; margin: 1.5rem 0 0.4rem; padding: 0 0.25rem; }
    .section-head form { margin-left: auto; }
    /* The + sits in the left slot, ahead of the name — not with the delete X. The
       header's gap is the rows' gap, so the name sits the same distance from the +
       as from the pencil on its other side. */
    /* The + rides in a form here, so the pull-in goes on the form, not the button. */
    .section-head form.sec-add-form { margin-left: -0.45rem; }
    /* The permanent group's plain-span title, matching the field version's metrics so
       both sit on the same centre line as the chevron and the "+". */
    .section-title { font-weight: 700; font-size: 1.15rem; color: #f0b429; line-height: 1.2; align-self: center; }
    /* The folder's colour, right of the folder's name — the same dot the picker wears. */
    .fdot { flex: 0 0 auto; width: 11px; height: 11px; border-radius: 50%; }
    .sec-add {
      flex: 0 0 auto; background: none; border: 1px solid #2a4a3d; color: var(--accent);
      border-radius: 999px; width: 20px; height: 20px; font-size: 0.85rem; line-height: 1;
      cursor: pointer; font-family: inherit; display: inline-flex; align-self: center;
      align-items: center; justify-content: center; padding: 0;
    }
    .sec-add:hover { border-color: var(--accent); background: var(--accent-soft); }
    .section-del {
      background: none; border: 1px solid #444; color: #ccc; border-radius: 6px;
      padding: 0.3rem 0.55rem; font-size: 0.95rem; line-height: 1; cursor: pointer;
      font-family: inherit;
    }
    .section-del:hover { border-color: #f66; color: #f66; }

    ul.nlist { list-style: none; margin-bottom: 0.5rem; }
    ul.nlist li { border-bottom: 1px solid #222; display: flex; align-items: center; padding-right: 0.25rem; }
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

    /* Drag-to-reorder notes (edit mode). Hidden, not gone: taking the handle out of
       the flow would shift every title sideways the moment you started editing.
       The section headers carry a blank one so their names start at the same x. */
    .drag-handle {
      visibility: hidden; flex: 0 0 auto; width: 1rem; display: inline-flex;
      align-items: center; justify-content: center; color: #666; font-size: 0.9rem;
      cursor: grab; touch-action: none; user-select: none;
    }
    body.editing .drag-handle { visibility: visible; }
    /* The blank slot swallows the header's gap, so a section's name starts exactly
       where the note titles under it do. */
    .drag-handle.blank { visibility: hidden; cursor: default; margin-right: 0.25rem; }
    .drag-handle:active { cursor: grabbing; color: var(--accent); }
    ul.nlist li.dragging { background: #1b1f1d; border-radius: 6px; box-shadow: 0 4px 14px rgba(0,0,0,0.45); }
    body.editing #notes-root ul.nlist:empty { min-height: 1.5rem; border: 1px dashed #333; border-radius: 6px; margin: 0.3rem 0; }
    /* Hold-to-drag: stop iOS text selection / callout on the rows while editing. */
    body.editing #notes-root li { -webkit-touch-callout: none; -webkit-user-select: none; user-select: none; }
    .noteitem .ntitle { flex: 1; font-size: 1.02rem; word-break: break-word; }
    .noteitem .ndate {
      font-size: 0.72rem; color: #b9a7f5; background: #241a3a; padding: 0.15rem 0.5rem;
      border-radius: 999px; white-space: nowrap;
    }
    .noteitem .nchev { color: #555; font-size: 1.1rem; }
    .empty { color: #666; text-align: center; padding: 2rem 0; }

    /* Editor view */
    .backbar { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 1rem; }
    .backbar a { color: var(--accent); text-decoration: none; font-size: 0.9rem; margin-right: 0.25rem; }
    .backbar a:hover { text-decoration: underline; }
    /* The folder/section pickers wear the Edit button's pill (.hedit in chrome.php). */
    .backbar select {
      margin: 0; color: #ccc; font-size: 0.8rem; background: none; border: 1px solid #333;
      border-radius: 999px; padding: 0.25rem 0.7rem; cursor: pointer; font-family: inherit;
      color-scheme: dark;
    }
    .backbar select:hover { border-color: #888; color: #fff; }
    .backbar select.secsel { border-color: #4a3f2a; color: #f0b429; }
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
      background: none; border: 1px dashed #3a5a4d; color: var(--accent); border-radius: 6px;
      padding: 0 0.9rem; min-height: 2.4rem; font-size: 0.9rem; cursor: pointer;
    }
    .editor .adddate:hover { background: var(--accent-soft); }
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
    .newsection { margin: 0; display: flex; gap: 0.4rem; align-items: center; }
    .newsection[hidden] { display: none; }   /* [hidden] has to win over the flex above */
    /* Both wear the height of the button they appear in place of. */
    .newsection .plus {
      flex: 0 0 auto; width: 34px; display: inline-flex; align-items: center; justify-content: center; padding: 0 0 2px; background: #f0b429; color: #241a00;
      border: none; border-radius: 999px; font-size: 1.05rem; line-height: 1; font-weight: 700;
      cursor: pointer; font-family: inherit;
    }
    .newsection .plus:hover { background: #f7c95a; }
    .newsection input {
      width: 190px; max-width: 100%; padding: 0.35rem 0.9rem; background: #1a1a1a; border: 1px dashed #5a4a2a;
      border-radius: 999px; color: #f0b429; font-size: 16px; line-height: 1.2;   /* 16px stops iOS zoom on focus */
    }
    .newsection input::placeholder { color: #f0b429; opacity: 0.85; }
    .newsection input:focus { outline: none; border-style: solid; border-color: #f0b429; }
<?= tabbar_styles() ?>
<?= chrome_styles() ?>
<?= rt_styles() ?>
<?= share_modal_styles() ?>
  </style>
</head>
<body>
<div class="wrap">
  <header>
    <div class="hleft">
      <?= back_button() ?>
      <div class="titlebar">
        <h1>Notes</h1>
      </div>
    </div>
    <?php
      // The folder picker rides on the right by the ⋮ (list view only); "Manage folders"
      // is the last row of its dropdown rather than a button of its own.
      $titleControls = '';
      if (!$editing) {
          ob_start();
          render_folder_pick($folderGroups, $view, 'All', $isShared ? '' : 'Manage folders');
          $titleControls = ob_get_clean();
      }
    ?>
    <?= render_user_menu(false, 'editBtn', '', $partner && !$isShared, $titleControls) ?>
  </header>

<?php if (!$editing): ?>
  <!-- ===== LIST VIEW ===== -->

  <div class="listbar">
    <?php // No + Note here any more: a note is made from the + on the section it goes in. ?>
    <button type="button" id="newSecBtn" class="listedit">+ Section</button>
    <?= $sectionInput ?>
  </div>

  <?php if (!$isShared) {
        render_folder_modal($myFolders, $csrf, $view, $defFolder, 'New notes go to', '',
                            $folderColors, app_palette('notes'), $sharedRows, app_palette('notes', true));
      } ?>
  <?php if ($partner && !$isShared) { echo share_modal_html($partner); } ?>

  <?php // The permanent group always renders, so there's always a + to add against. ?>
   <div id="notes-root">
    <?php
      // Folder labels + dividers: only in "All", and only once more than one folder is
      // actually represented among the visible sections.
      $secFolders  = array_unique(array_map(fn($s) => (string) ($s['folder'] ?? FOLDER_DEFAULT), $secRows));
      $showFolders = $viewFolder === 'All' && count($secFolders) > 1;
      $prevFolder  = null;
    ?>
    <?php foreach ($secRows as $s): ?>
      <?php $sname = (string) $s['name']; $sfolder = (string) ($s['folder'] ?? FOLDER_DEFAULT); ?>
      <?php $rows = $grouped[$s['id']] ?? []; ?>
      <?php if ($showFolders && $sfolder !== $prevFolder): ?>
        <?php if ($prevFolder !== null): ?></div><div class="folder-divider"></div><?php endif; ?>
        <div class="folder-block" data-folder="<?= e($sfolder) ?>">
          <div class="folder-head">
            <?= folder_collapse_button() ?>
            <div class="folder-label"><?= e($sfolder) ?></div>
            <?php // The folder's own colour, the same dot its entry wears in the picker. ?>
            <span class="fdot" style="background:<?= e($folderDotColor($sfolder)) ?>" aria-hidden="true"></span>
          </div>
      <?php endif; ?>
      <?php $prevFolder = $sfolder; ?>
      <?php // Sections always render, empty or not — like the permanent Notes group below.
            // Each belongs to one folder; its rename and delete carry that folder. ?>
      <div class="section-head" data-folder="<?= e($sfolder) ?>">
        <?= section_collapse_button() ?>
        <?= section_title_html($sname, $csrf, $view, false, 'rename_section',
              '<input type="hidden" name="folder" value="' . e($sfolder) . '">') ?>
        <?php render_section_add($sname, $csrf, $view, $sfolder); ?>
        <form method="post" action="" style="display:inline">
          <input type="hidden" name="csrf" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="delete_section">
          <input type="hidden" name="view" value="<?= e($view) ?>">
          <input type="hidden" name="folder" value="<?= e($sfolder) ?>">
          <input type="hidden" name="name" value="<?= e($sname) ?>">
          <button class="section-del needs-confirm" type="submit" title="Delete section">&times;</button>
        </form>
      </div>
      <?php render_note_rows($rows, $view, $csrf, $sname, ''); ?>
    <?php endforeach; ?>
    <?php if ($showFolders && $prevFolder !== null): ?></div><?php endif; ?>

    <!-- Permanent "Notes" group: always last, not deletable. -->
    <div class="section-head">
      <?= section_collapse_button() ?>
      <span class="section-title"><?= NOTES_DEFAULT_SECTION ?></span>
      <?php render_section_add('', $csrf, $view, $addTarget); ?>
    </div>
    <?php render_note_rows($ungrouped, $view, $csrf); ?>
   </div>

<?php else: ?>
  <!-- ===== EDITOR VIEW ===== -->
  <?php
    $hasDate     = !empty($current['date']);
    $noteDefault = date('m/d/Y h:i a', (int) ($current['created'] ?? time())) . ' - Note';
  ?>
  <form class="editor" method="post" action="">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="view" value="<?= e($view) ?>">
    <input type="hidden" name="id" value="<?= e($current['id']) ?>">
    <?php // The pickers ride in the back bar, wearing the Edit button's pill. They have
          // to stay inside the form to post, so the bar lives here rather than above it. ?>
    <div class="backbar">
      <a href="<?= e($listUrl) ?>">&larr; All notes</a>
      <select name="folder" title="Folder">
        <?php foreach ($folders as $f): ?>
          <option value="<?= e($f) ?>" <?= ($current['folder'] ?? FOLDER_DEFAULT) === $f ? 'selected' : '' ?>><?= e($f) ?></option>
        <?php endforeach; ?>
      </select>
      <?php // Only this note's folder's sections — sections are per-folder now.
            $noteFolder = $current['folder'] ?? $defFolder;
            $editorSecs = [];
            foreach ($all as $it) {
                if (is_section($it) && ($it['folder'] ?? FOLDER_DEFAULT) === $noteFolder) { $editorSecs[] = (string) $it['name']; }
            } ?>
      <select name="section" class="secsel" title="Section">
        <option value="">Notes</option>
        <?php foreach ($editorSecs as $sname): ?>
          <option value="<?= e($sname) ?>" <?= ($current['section'] ?? '') === $sname ? 'selected' : '' ?>><?= e($sname) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
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
    <?= rt_toolbar_html() ?>
    <div class="rt-body" contenteditable="true" data-placeholder="Write your note&hellip;"><?= rt_body_html($current['body'] ?? '') ?></div>
    <input type="hidden" class="rt-value" name="body" value="<?= e(rt_body_html($current['body'] ?? '')) ?>">
    <div class="actions">
      <span class="meta" id="saveStatus">Saved</span>
      <button class="del needs-confirm" type="submit" name="action" value="delete">Delete</button>
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

  // List: edit mode reveals delete buttons. The bar's Edit button is gone — the pencil
  // on each section header is the way in, so it toggles the mode itself here.
  const setEdit = (on) => document.body.classList.toggle('editing', on);
  // Always starts off; a structural change redirects back with ?edit=1 to keep it on.
  setEdit(new URLSearchParams(location.search).get('edit') === '1');
  window.sectionEditToggle = () => setEdit(!document.body.classList.contains('editing'));

  // ----- Enter/leave edit mode by gesture (no Edit button any more) -----
  // Long-press a note or section on touch, or double-click on the desktop.
  const editingNow = () => document.body.classList.contains('editing');
  const gBlocked = (t) => t.closest('.ndel, .sec-add, .section-del, .sec-collapse, button, input, textarea, select');
  const gestureEdit = (target) => {
    if (!target.closest('li[data-id], .section-head')) return;
    setEdit(true);
  };
  let gSuppress = false;
  document.addEventListener('click', (e) => { if (gSuppress) { e.preventDefault(); e.stopPropagation(); gSuppress = false; } }, true);
  // Touch/pen: long-press. Suppress the click that follows so a note link doesn't open.
  let lpT = null, lpX = 0, lpY = 0, lpEl = null;
  const clearLp = () => { if (lpT) { clearTimeout(lpT); lpT = null; } };
  document.addEventListener('pointerdown', (e) => {
    if (e.pointerType === 'mouse' || editingNow()) return;
    if (gBlocked(e.target) || !e.target.closest('li[data-id], .section-head')) return;
    lpEl = e.target; lpX = e.clientX; lpY = e.clientY;
    lpT = setTimeout(() => {
      lpT = null; if (navigator.vibrate) navigator.vibrate(12);
      gestureEdit(lpEl); gSuppress = true; setTimeout(() => { gSuppress = false; }, 600);
    }, 500);
  });
  document.addEventListener('pointermove', (e) => {
    if (lpT && (Math.abs(e.clientX - lpX) > 10 || Math.abs(e.clientY - lpY) > 10)) clearLp();
  });
  document.addEventListener('pointerup', clearLp);
  document.addEventListener('pointercancel', clearLp);
  // Desktop: a note is a link, so distinguish a single click (open) from a double
  // click (edit) with a short delay; only on a fine pointer, so touch taps stay instant.
  if (window.matchMedia && window.matchMedia('(pointer: fine)').matches) {
    let clickT = null;
    document.addEventListener('click', (e) => {
      const a = e.target.closest('.noteitem'); if (!a) return;
      if (editingNow()) { e.preventDefault(); return; }   // in edit mode a click doesn't navigate
      e.preventDefault();
      if (clickT) { clearTimeout(clickT); clickT = null; setEdit(true); return; }
      const href = a.href;
      clickT = setTimeout(() => { clickT = null; location.href = href; }, 220);
    });
  }
  // Leave edit mode by tapping away from what you're editing. A tap stays in edit only
  // on a note row, a section name field, an edit control, the toolbar, or a modal.
  document.addEventListener('click', (e) => {
    if (!editingNow() || gSuppress) return;
    if (e.target.closest('.noteitem, .sectitle, .sec-handle, .listbar, button, a, input, textarea, select,'
        + ' .modal-backdrop, .setmodal-backdrop, .sh-modal, .tabbar')) return;
    setEdit(false);
  });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && editingNow()) setEdit(false); });

  // "+ Section" turns into the field it's asking for, and turns back if you leave it
  // empty — the same gesture as the "+" on a section header, and the same size as the
  // button it replaces so the row doesn't jump.
  const newSecBtn = document.getElementById('newSecBtn'), newSecForm = document.getElementById('newSecForm');
  if (newSecBtn && newSecForm) {
    const input = newSecForm.querySelector('input[type=text]');
    const close = () => { newSecForm.hidden = true; newSecBtn.hidden = false; input.value = ''; };
    newSecBtn.addEventListener('click', () => {
      newSecBtn.hidden = true; newSecForm.hidden = false; input.focus();
    });
    input.addEventListener('keydown', e => { if (e.key === 'Escape') { e.preventDefault(); close(); } });
    input.addEventListener('blur', () => { if (input.value.trim() === '') { close(); } });
  }

  // ---- Drag to reorder notes (edit mode). Hold anywhere on a row to pick it up,
  //      or use the ☰ handle for an immediate grab. Same gesture as the bookshelf's. ----
  (function () {
    const root = document.getElementById('notes-root');
    if (!root) return;
    const CSRF = '<?= $csrf ?>', VIEW = '<?= e($view) ?>';
    let dragLi = null, pressTimer = null, pid = null, sx = 0, sy = 0, suppressClick = false;

    const persist = () => {
      const order = [];
      root.querySelectorAll('ul.nlist').forEach(ul => {
        const section = ul.dataset.section || '';
        ul.querySelectorAll(':scope > li[data-id]').forEach(li => order.push({ id: li.dataset.id, section }));
      });
      const body = new URLSearchParams({ csrf: CSRF, action: 'reorder', view: VIEW, order: JSON.stringify(order) });
      fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body }).catch(() => location.reload());
    };
    const begin = (li) => {
      dragLi = li; li.classList.add('dragging');
      try { li.setPointerCapture(pid); } catch (_) {}
      if (navigator.vibrate) navigator.vibrate(12);
    };
    const cancelPress = () => { if (pressTimer) { clearTimeout(pressTimer); pressTimer = null; } };

    root.addEventListener('pointerdown', (e) => {
      if (!document.body.classList.contains('editing')) return;
      const li = e.target.closest('li[data-id]'); if (!li || !root.contains(li)) return;
      if (e.target.closest('.ndel')) return;            // let the delete button work
      pid = e.pointerId; sx = e.clientX; sy = e.clientY;
      if (e.target.closest('.drag-handle')) { e.preventDefault(); begin(li); }
      else { pressTimer = setTimeout(() => { pressTimer = null; begin(li); }, 280); }
    });
    // iOS sits on a touch deciding whether it's a scroll; claiming the handle makes
    // the grab feel immediate.
    root.addEventListener('touchstart', (e) => {
      if (!document.body.classList.contains('editing')) return;
      if (e.target.closest('.drag-handle:not(.blank)')) e.preventDefault();
    }, { passive: false });
    document.addEventListener('pointermove', (e) => {
      if (pressTimer) {                                 // still waiting: a real move = scroll/tap
        if (Math.abs(e.clientX - sx) > 10 || Math.abs(e.clientY - sy) > 10) cancelPress();
        return;
      }
      if (!dragLi) return;
      e.preventDefault();
      const under = document.elementFromPoint(e.clientX, e.clientY); if (!under) return;
      const overLi = under.closest('li[data-id]');
      if (overLi && overLi !== dragLi && root.contains(overLi)) {
        const r = overLi.getBoundingClientRect();
        overLi.parentNode.insertBefore(dragLi, (e.clientY > r.top + r.height / 2) ? overLi.nextSibling : overLi);
      } else {
        const ul = under.closest('ul.nlist');
        if (ul && root.contains(ul) && ul !== dragLi.parentNode) ul.appendChild(dragLi);
      }
    }, { passive: false });
    const end = () => {
      cancelPress();
      if (!dragLi) return;
      dragLi.classList.remove('dragging'); dragLi = null;
      suppressClick = true;                             // swallow the click that follows a drag
      setTimeout(() => { suppressClick = false; }, 350);
      persist();
    };
    document.addEventListener('pointerup', end);
    document.addEventListener('pointercancel', end);
    root.addEventListener('click', (e) => { if (suppressClick) { e.preventDefault(); e.stopPropagation(); } }, true);
  })();
</script>
<script>
  // What the share window (in Settings) draws: my calendars, my reminder + note folders,
  // and what's currently ticked. A function so it always reflects the latest state.
  window.SHARES = <?= json_encode(($partner && !$isShared) ? shares_load($cfg['data_dir'], $me) : ['calendars' => [], 'folders' => [], 'notes' => []]) ?>;
  window.shareData = () => ({
    cals: <?= json_encode($shareCals) ?>,
    folders: <?= json_encode(($partner && !$isShared) ? folders_load($cfg['data_dir'])['reminders'] : []) ?>,
    notefolders: <?= json_encode(($partner && !$isShared) ? $myFolders : []) ?>,
    shares: window.SHARES
  });
  window.onSharesChanged = (s) => { window.SHARES = s; window.shareRender(); };
</script>
<?php if ($partner && !$isShared) { echo share_modal_script($csrf); } ?>
<?= folder_modal_script() ?>
<?= chrome_script() ?>
<?= rt_script() ?>
</body>
</html>
