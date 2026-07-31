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

// Every folder ends with an ungrouped catch-all — the rows in that folder that aren't
// in any section. It carries the app's name the way Notes' does, and it can't be
// deleted or created; it's simply "the rest of this folder".
const DEFAULT_SECTION = 'Reminders';

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$me        = current_user() ?? '';
$myFolders = folders_load($cfg['data_dir'])['reminders'];
// Folders switched off in the picker. They still exist and can still be opened by
// picking them; "All" just stops including them.
$hidFolders = folders_hidden($cfg['data_dir'], 'reminders');

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
// No folder in the URL means "carry on where I left off".
$view       = (string) ($_REQUEST['view'] ?? $_GET['folder'] ?? folder_last_get($cfg['data_dir'], 'reminders'));
$owner      = $me;
$viewFolder = $view;
$isShared   = false;
if ($partner && preg_match('/^@([A-Za-z0-9_-]+):(.*)$/s', $view, $m)
    && $m[1] === $partner && in_array($m[2], $sharedFolders, true)) {
    $owner = $partner; $viewFolder = $m[2]; $isShared = true;
} elseif ($view !== 'All' && !in_array($view, $myFolders, true)) {
    $view = $viewFolder = 'All';
}

// Remember it (post-validation, so a stale one can't stick around).
folder_last_set($cfg['data_dir'], 'reminders', $view);

$dataFile = user_data_file($cfg['data_dir'], 'reminders', $isShared ? $owner : null);
$folders  = $isShared ? folders_load($cfg['data_dir'], $owner)['reminders'] : $myFolders;

// New items land in the viewed folder, or the chosen default when viewing All.
$defFolder = folder_default_get($cfg['data_dir'], 'reminders');
$addTarget = $viewFolder === 'All' ? $defFolder : $viewFolder;
$backUrl   = _self_path() . '?folder=' . urlencode($view);
// Structural changes (folders, sections, deletes) are only reachable from edit mode,
// so they hand it back on the way through — everything else lands out of edit mode.
$editBack  = $backUrl . '&edit=1';

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES);
}

/** A stored row is a "section" divider (bold header) rather than a reminder. */
function is_section(array $it): bool
{
    return ($it['type'] ?? '') === 'section';
}

function load_reminders(string $file): array
{
    return reminders_folder_migrate(sections_migrate(store_read($file)));
}
function save_reminders(string $file, array $list): void { store_write($file, array_values($list)); }

/**
 * Display order inside a section: undated first, then by due date, soonest first.
 * Ties keep the stored (drag) order, so dragging still decides who sits where among
 * items that share a date. Completed items sink to the bottom either way (CSS order).
 */
function sort_by_date(array $rows): array
{
    $i = 0;
    foreach ($rows as &$r) { $r['_seq'] = $i++; }   // usort isn't stable enough on its own
    unset($r);
    usort($rows, function ($a, $b) {
        $ad = ($a['due'] ?? '') ?: '';   // '' (undated) sorts before any real date
        $bd = ($b['due'] ?? '') ?: '';
        return $ad !== $bd ? strcmp($ad, $bd) : ($a['_seq'] <=> $b['_seq']);
    });
    foreach ($rows as &$r) { unset($r['_seq']); }
    unset($r);
    return $rows;
}

/** Stable DOM id tying a section's "+" button to the row it opens. Keyed by folder and
 *  name, since two folders may hold same-named sections (both visible in the All view). */
function section_add_id(string $folder, string $name): string
{
    return 'secadd-' . substr(md5($folder . "\x1F" . $name), 0, 8);
}

/** The "+" that sits on a section header. */
function render_section_add_button(string $name, string $folder): void
{
    $label = e($name === '' ? DEFAULT_SECTION : $name);
    ?>
    <button type="button" class="sec-add" data-target="<?= section_add_id($folder, $name) ?>"
            title="Add to <?= $label ?>" aria-label="Add to <?= $label ?>">+</button>
    <?php
}

/**
 * The row that "+" reveals. Typing here adds straight to the end of that section —
 * no window — and the text is scanned for a date and a time ("Vet 8/3 2pm") the same
 * way the Calendar's quick add is. The new reminder lands in the section's own folder.
 */
function render_section_add_row(string $name, string $csrf, string $view, string $folder): void
{
    $label = e($name === '' ? DEFAULT_SECTION : $name);
    ?>
    <form method="post" action="" class="secadd-row" id="<?= section_add_id($folder, $name) ?>" hidden
          onsubmit="return this.text.value.trim()!==''">
      <input type="hidden" name="csrf" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="view" value="<?= e($view) ?>">
      <input type="hidden" name="folder" value="<?= e($folder) ?>">
      <input type="hidden" name="section" value="<?= e($name) ?>">
      <input type="text" name="text" placeholder="Add to <?= $label ?>&hellip;" maxlength="500" autocomplete="off">
      <button type="submit" class="plus" title="Add">+</button>
    </form>
    <?php
}

/**
 * A Markdown checklist of the currently-viewed reminders, grouped by section, open
 * items only. Fed to the "Copy as Markdown" action in the settings window so the whole
 * list can be pasted elsewhere.
 */
function reminders_markdown(array $secRows, array $grouped, array $looseByFolder): string
{
    $line = function (array $r): string {
        $bits = [];
        if (!empty($r['due']))  { $bits[] = $r['due']; }
        if (!empty($r['time'])) { $bits[] = $r['time']; }
        $suffix = $bits ? ' (' . implode(' ', $bits) . ')' : '';
        return '- [] ' . ($r['text'] ?? '') . $suffix;
    };
    $block = function (string $title, array $rows) use ($line): string {
        $rows = array_values(array_filter($rows, fn($r) => empty($r['done'])));
        if (!$rows) { return ''; }
        $out = "## $title\n";
        foreach ($rows as $r) { $out .= $line($r) . "\n"; }
        return $out . "\n";
    };
    $md = '';
    foreach ($secRows as $s) { $md .= $block((string) $s['name'], $grouped[$s['id']] ?? []); }
    foreach ($looseByFolder as $folder => $rows) { $md .= $block((string) $folder, $rows); }
    return trim($md);
}

/** The edit-mode ‹ › pair that outdents / indents an item into a subsection. The row's
 *  id and the CSRF/view come from the DOM, so this is the same markup for rows and heads. */
function indent_controls(): string
{
    return '<span class="indent-ctrls">'
         . '<button type="button" class="indent-btn" data-dir="out" title="Outdent" aria-label="Outdent">&lsaquo;</button>'
         . '<button type="button" class="indent-btn" data-dir="in" title="Indent (subsection)" aria-label="Indent">&rsaquo;</button>'
         . '</span>';
}

/** Echo a <ul> of reminder rows (nothing if empty). Data attributes drive sort + drag. */
function render_rows(array $rows, string $csrf, string $view, string $today, string $section = ''): void
{
    static $pos = 0;   // running position across all groups = the manual order
    echo '<ul class="rlist" data-section="' . e($section) . '">';   // always emit (empty = drop target)
    foreach ($rows as $r) {
        $done    = !empty($r['done']);
        // Past / today / future, so a glance at the chip tells you where it sits.
        $when = '';
        if (!empty($r['due'])) {
            $when = $r['due'] < $today ? 'past' : ($r['due'] === $today ? 'today' : 'future');
        }
        ?>
        <li class="swipe-row <?= $done ? 'done' : '' ?>"
            style="--ind:<?= (int) ($r['indent'] ?? 0) ?>"
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
          <?php if (!empty($r['time'])): ?>
            <span class="attime"><?= e(date('g:ia', strtotime($r['time']))) ?></span>
          <?php endif; ?>
          <?php if (!empty($r['due'])): ?>
            <span class="due <?= $when ?>"><?= e($r['due']) ?></span>
          <?php endif; ?>
          <?= indent_controls() ?>
          <form method="post" action="" style="display:inline">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="view" value="<?= e($view) ?>">
            <input type="hidden" name="id" value="<?= e($r['id']) ?>">
            <button class="del needs-confirm" type="submit" title="Delete">&times;</button>
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
    if ($isShared && in_array($_POST['action'], ['add_section', 'delete_section', 'delete_folder', 'reorder', 'reorder_folders'], true)) {
        http_response_code(403);
        exit('That belongs to ' . htmlspecialchars(share_name($owner), ENT_QUOTES) . '.');
    }


    // Nothing destructive happens without the confirmed second press. The arming
    // script adds this field; if it never ran, we bounce rather than delete.
    if (in_array($_POST['action'], ['delete', 'delete_section', 'delete_folder'], true)
        && empty($_POST['confirm'])) {
        header('Location: ' . $editBack);
        exit;
    }

    // Sharing: the same window the Calendar has, so it can be reached from either app.
    if ($_POST['action'] === 'share_set' && $partner && !$isShared) {
        share_handle_set($cfg['data_dir'], $me, array_keys(share_calendars($cfg['data_dir'], $me)),
                         $myFolders, folders_load($cfg['data_dir'])['notes']);
    }

    // Folder actions don't touch the reminders list.
    if ($_POST['action'] === 'add_folder') {
        $name = folder_clean((string) ($_POST['name'] ?? ''));
        folders_add($cfg['data_dir'], 'reminders', $name);
        // Switch to the new folder, staying in edit mode only if we were already in it.
        // fm=1 reopens the folder manager so adding one doesn't close it.
        $stay = !empty($_POST['edit']) ? '&edit=1' : '';
        header('Location: ' . _self_path() . '?folder=' . urlencode($name !== '' ? $name : 'All') . $stay . '&fm=1');
        exit;
    }
    if ($_POST['action'] === 'set_default_folder') {
        folder_default_set($cfg['data_dir'], 'reminders', (string) ($_POST['name'] ?? ''));
        header('Location: ' . $editBack . '&fm=1');
        exit;
    }
    // The show/hide box on a folder row in the picker (AJAX; the page reloads itself).
    if ($_POST['action'] === 'folder_vis') {
        folder_hidden_set($cfg['data_dir'], 'reminders', (string) ($_POST['name'] ?? ''),
                          empty($_POST['show']));
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'hidden' => folders_hidden($cfg['data_dir'], 'reminders')]);
        exit;
    }
    // Drag-reorder of the custom folders from the Manage-folders window (AJAX).
    if ($_POST['action'] === 'reorder_folders') {
        $order = array_values(array_filter(explode("\x1F", (string) ($_POST['order'] ?? '')),
                                           fn($s) => $s !== ''));
        folders_reorder($cfg['data_dir'], 'reminders', $order);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'folders' => folders_load($cfg['data_dir'])['reminders']]);
        exit;
    }
    if ($_POST['action'] === 'set_folder_color') {
        $cname = (string) ($_POST['name'] ?? '');
        if (folder_shared_key_parse($cname)) {
            // Recolouring a partner's shared folder: stored in my own file, keyed @partner:folder.
            folder_shared_color_set($cfg['data_dir'], 'reminders', $cname,
                                    (string) ($_POST['color'] ?? ''), $sharedFolders);
        } else {
            folder_color_set($cfg['data_dir'], 'reminders', $cname, (string) ($_POST['color'] ?? ''));
        }
        header('Location: ' . $editBack);
        exit;
    }
    if ($_POST['action'] === 'delete_folder') {
        $name = (string) ($_POST['name'] ?? '');
        folders_delete($cfg['data_dir'], 'reminders', $name);
        // Move that folder's reminders back to the permanent catch-all.
        $list = load_reminders($dataFile);
        foreach ($list as &$r) {
            if (!is_section($r) && ($r['folder'] ?? '') === $name) { $r['folder'] = folder_fallback('reminders'); }
        }
        unset($r);
        save_reminders($dataFile, $list);
        header('Location: ' . _self_path() . '?folder=All&edit=1&fm=1');
        exit;
    }

    // Section actions. A section belongs to one folder — folders keep distinct sections,
    // so adding, renaming or deleting one only ever touches the folder it's in.
    if ($_POST['action'] === 'add_section') {
        $name = folder_clean((string) ($_POST['name'] ?? ''));
        // A section lands in the folder whose "+" was used (each folder head carries one),
        // falling back to the folder you're viewing, or the default when you're on All.
        $postFolder = (string) ($_POST['folder'] ?? '');
        $secFolder  = ($postFolder !== '' && in_array($postFolder, $myFolders, true))
            ? $postFolder
            : ($viewFolder === 'All' ? $defFolder : $viewFolder);
        // "Reminders" is the catch-all every folder already ends with, so a section
        // can't be called that — there'd be two of them under one heading.
        if ($name !== '' && strcasecmp($name, DEFAULT_SECTION) !== 0) {
            $list = load_reminders($dataFile);
            $dup  = false;
            foreach ($list as $it) {
                if (is_section($it) && ($it['folder'] ?? '') === $secFolder
                    && strcasecmp((string) ($it['name'] ?? ''), $name) === 0) { $dup = true; break; }
            }
            if (!$dup) {
                // Prepend so a new section lands at the top of the list.
                array_unshift($list, ['id' => bin2hex(random_bytes(6)), 'type' => 'section',
                           'name' => $name, 'folder' => $secFolder, 'created' => time()]);
                save_reminders($dataFile, $list);
            }
        }
        // Stay in edit mode only if we were already in it (the form carries `edit` while
        // editing) — adding a section shouldn't drag you into edit mode on its own.
        header('Location: ' . $backUrl . (!empty($_POST['edit']) ? '&edit=1' : ''));
        exit;
    }
    if ($_POST['action'] === 'rename_section') {
        $secFolder = (string) ($_POST['folder'] ?? $viewFolder);
        $list = load_reminders($dataFile);
        save_reminders($dataFile, section_rename($list, (string) ($_POST['name'] ?? ''),
                                                 (string) ($_POST['newname'] ?? ''), $secFolder));
        header('Location: ' . $editBack);
        exit;
    }
    if ($_POST['action'] === 'delete_section') {
        $name      = (string) ($_POST['name'] ?? '');
        $secFolder = (string) ($_POST['folder'] ?? $viewFolder);
        $list = load_reminders($dataFile);
        // Only this folder's copy of the section goes; other folders keep theirs.
        $list = array_filter($list, fn($it) => !(is_section($it)
            && ($it['name'] ?? '') === $name && ($it['folder'] ?? '') === $secFolder));
        foreach ($list as &$r) {
            if (!is_section($r) && ($r['section'] ?? '') === $name
                && ($r['folder'] ?? folder_fallback('reminders')) === $secFolder) { $r['section'] = ''; }
        }
        unset($r);
        save_reminders($dataFile, $list);
        header('Location: ' . $editBack);
        exit;
    }

    // Reorder / re-section reminders after a drag (AJAX). order = [{id, section}, …] top-to-bottom.
    if ($_POST['action'] === 'reorder') {
        $order    = json_decode((string) ($_POST['order'] ?? '[]'), true);
        $secOrder = json_decode((string) ($_POST['sections'] ?? '[]'), true);
        if (!is_array($order))    { $order = []; }
        if (!is_array($secOrder)) { $secOrder = []; }
        $list = load_reminders($dataFile);

        // Sections are per-folder, so a drag only reorders the viewed folder's sections
        // and only re-sections items against sections that exist in their own folder.
        $secExists      = [];   // "folder\x1Fname" => true, for every section row
        $thisFolderSecs = [];   // name => row, for the folder being viewed
        $otherSecs      = [];   // rows in other folders, left untouched
        $byId           = [];
        foreach ($list as $it) {
            if (is_section($it)) {
                $f = $it['folder'] ?? folder_fallback('reminders');
                $secExists[$f . "\x1F" . $it['name']] = true;
                if ($viewFolder !== 'All' && $f === $viewFolder) { $thisFolderSecs[$it['name']] = $it; }
                else { $otherSecs[] = $it; }
            } else {
                $byId[$it['id']] = $it;
            }
        }

        // Reorder the viewed folder's section rows by the posted order; keep the rest.
        $sectionsList = [];
        foreach ($secOrder as $name) {
            if (isset($thisFolderSecs[$name])) { $sectionsList[] = $thisFolderSecs[$name]; unset($thisFolderSecs[$name]); }
        }
        foreach ($thisFolderSecs as $e) { $sectionsList[] = $e; }
        $sectionsList = array_merge($sectionsList, $otherSecs);

        $newReminders = [];
        $used = [];
        foreach ($order as $o) {
            $id = (string) ($o['id'] ?? '');
            if ($id === '' || !isset($byId[$id]) || isset($used[$id])) { continue; }
            $item = $byId[$id];
            $sec  = (string) ($o['section'] ?? '');
            $f    = $item['folder'] ?? folder_fallback('reminders');
            // A section must exist in this item's own folder, or the row falls to the
            // folder's catch-all.
            if ($sec !== '' && !isset($secExists[$f . "\x1F" . $sec])) { $sec = ''; }
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

    // Indent a reminder or section into a subsection (visual only): store its level 0–4.
    if ($_POST['action'] === 'set_indent') {
        $id   = (string) ($_POST['id'] ?? '');
        $ind  = max(0, min(4, (int) ($_POST['indent'] ?? 0)));
        $list = load_reminders($dataFile);
        foreach ($list as &$it) {
            if (($it['id'] ?? '') === $id) { $it['indent'] = $ind; break; }
        }
        unset($it);
        save_reminders($dataFile, $list);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'indent' => $ind]);
        exit;
    }

    $list        = load_reminders($dataFile);
    $sectionSet  = [];   // "folder\x1Fname" — sections are per-folder
    // Edit mode rides along on the POST, so anything done from within it lands back
    // in it. The forms only carry this field while editing (see the submit hook).
    $stay        = !empty($_POST['edit']) ? '&edit=1' : '';
    foreach ($list as $it) {
        if (is_section($it)) { $sectionSet[($it['folder'] ?? folder_fallback('reminders')) . "\x1F" . $it['name']] = true; }
    }

    switch ($_POST['action']) {
        case 'add':
            $text    = trim((string) ($_POST['text'] ?? ''));
            $due     = trim((string) ($_POST['due'] ?? ''));
            $folder  = (string) ($_POST['folder'] ?? $addTarget);
            if (!in_array($folder, $folders, true)) { $folder = $addTarget; }
            $section = (string) ($_POST['section'] ?? '');
            // '' is the folder's catch-all; anything else has to be a real section there.
            if ($section !== '' && !isset($sectionSet[$folder . "\x1F" . $section])) { $section = ''; }

            // A dated field from the window wins; otherwise read the date and time
            // out of what was typed ("Vet 8/3 2pm").
            $time = null;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
                [$text, $parsedDate, $time] = parse_when_from_text($text);
                $due = $parsedDate ?? '';
            }
            if ($text !== '') {
                // Prepend so a new reminder lands at the top of its group (stored order
                // breaks ties, and earlier in the list sorts higher).
                array_unshift($list, [
                    'id'      => bin2hex(random_bytes(6)),
                    'text'    => mb_substr($text, 0, 500),
                    'due'     => preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) ? $due : null,
                    'time'    => $time,
                    'done'    => false,
                    'folder'  => $folder,
                    'section' => $section,
                    'created' => time(),
                ]);
            }
            break;

        case 'toggle':
            $id = (string) ($_POST['id'] ?? '');
            foreach ($list as &$r) {
                if (!is_section($r) && $r['id'] === $id) {
                    $rep = repeat_get($r);
                    if ($rep !== null && !$r['done'] && !empty($r['due'])) {
                        // A repeat never finishes: ticking it moves it to the next date.
                        $r['due'] = repeat_next($r['due'], $rep, max($r['due'], date('Y-m-d')));
                    } else {
                        $r['done'] = !$r['done'];
                    }
                    break;
                }
            }
            unset($r);
            break;

        case 'delete':
            $id = (string) ($_POST['id'] ?? '');
            $list = array_values(array_filter($list, fn($r) => is_section($r) || ($r['id'] ?? '') !== $id));
            $stay = '&edit=1';   // deleting is an edit-mode action; stay in it
            break;

        case 'clear_done':
            // Clear completed within the folder being viewed (or all when viewing All).
            $list = array_filter($list, function ($r) use ($viewFolder) {
                if (is_section($r) || empty($r['done'])) { return true; }
                return $viewFolder !== 'All' && ($r['folder'] ?? folder_fallback('reminders')) !== $viewFolder;
            });
            break;
    }

    save_reminders($dataFile, $list);
    header('Location: ' . $backUrl . $stay);
    exit;
}

// --- Render ---
$all = load_reminders($dataFile);

// Which folders are on screen. A folder view is just that one; "All" is every folder
// that isn't switched off in the picker (a partner's list is theirs, so nothing of mine
// is hidden there). The order is the folder list's, so the permanent two lead.
$fbase       = $isShared ? $folders : $myFolders;
$viewFolders = $viewFolder === 'All'
    ? array_values(array_filter($fbase, fn($f) => $isShared || !in_array($f, $hidFolders, true)))
    : [$viewFolder];

$folderOf = fn(array $it): string => (string) ($it['folder'] ?? folder_fallback('reminders'));

// Section rows, in stored order, tagged with the folder they belong to.
$secRows = [];
foreach ($all as $it) {
    if (is_section($it) && in_array($folderOf($it), $viewFolders, true)) { $secRows[] = $it; }
}

// Reminder rows, filtered to the folders on screen.
$items = array_values(array_filter($all,
    fn($it) => !is_section($it) && in_array($folderOf($it), $viewFolders, true)));

// A section is matched by folder *and* name, so same-named sections in two folders stay
// distinct. Grouped rows are keyed by the section row's id; everything else falls to its
// own folder's catch-all.
$secByKey = [];   // "folder\x1Fname" => row id
foreach ($secRows as $s) { $secByKey[$folderOf($s) . "\x1F" . $s['name']] = $s['id']; }

$looseByFolder = [];
$grouped       = [];
foreach ($items as $r) {
    $sec = (string) ($r['section'] ?? '');
    $key = $folderOf($r) . "\x1F" . $sec;
    if ($sec !== '' && isset($secByKey[$key])) { $grouped[$secByKey[$key]][] = $r; }
    else { $looseByFolder[$folderOf($r)][] = $r; }
}
foreach ($viewFolders as $f) { if (!isset($looseByFolder[$f])) { $looseByFolder[$f] = []; } }
$looseByFolder = array_replace(array_flip($viewFolders), $looseByFolder);   // keep folder order
foreach ($looseByFolder as $f => $rows) { $looseByFolder[$f] = sort_by_date(is_array($rows) ? $rows : []); }
foreach ($grouped as $id => $rows) { $grouped[$id] = sort_by_date($rows); }

$openCount = count(array_filter($items, fn($r) => empty($r['done'])));
$doneCount = count($items) - $openCount;
$csrf      = htmlspecialchars($_SESSION['csrf'], ENT_QUOTES);
$today     = date('Y-m-d');

// "Copy as Markdown": the whole visible list, copied from the share icon in the toolbar.
$shareMd    = reminders_markdown($secRows, $grouped, $looseByFolder);

// Calendars, as [id, name] pairs, for the share window.
$shareCals = [];
if ($partner && !$isShared) {
    foreach (share_calendars($cfg['data_dir'], $me) as $cid => $cname) { $shareCals[] = [$cid, $cname]; }
}

// Folder picker contents: mine under my name, then whatever the other person shared.
// Shared folders take my own colour override first (kept in my file), then the owner's
// colour, then a shared-palette default — so I can recolour theirs without touching it.
$myColors        = folder_colors($cfg['data_dir'], 'reminders');
$theirColors     = $partner ? folder_colors($cfg['data_dir'], 'reminders', $partner) : [];
$sharedOverrides = folder_shared_colors($cfg['data_dir'], 'reminders');
$sharedRows      = [];
$folderGroups = [['label' => share_name($me),
                  'options' => array_map(fn($f) => [$f, $f, $myColors[$f] ?? app_palette('reminders')[0]], $myFolders)]];
if ($sharedFolders) {
    $sharedOpts = [];
    foreach ($sharedFolders as $i => $f) {
        $key = '@' . $partner . ':' . $f;
        $col = folder_shared_color($sharedOverrides, $theirColors, 'reminders', $key, $f, $i);
        $sharedOpts[]  = [$key, $f, $col];
        $sharedRows[]  = ['key' => $key, 'label' => $f, 'color' => $col];
    }
    $folderGroups[] = ['label' => share_name($partner), 'options' => $sharedOpts];
}

/**
 * The colour of a folder as this viewer sees it, for the dot on a section header.
 * Viewing my own list it's my colour; viewing a shared one it's the same resolution the
 * picker uses (my override, then the owner's, then a shared-palette default by position).
 */
$folderDotColor = function (string $f) use ($isShared, $partner, $myColors, $theirColors,
                                             $sharedOverrides, $sharedFolders): string {
    if (!$isShared) { return $myColors[$f] ?? app_palette('reminders')[0]; }
    $i = array_search($f, $sharedFolders, true);
    return folder_shared_color($sharedOverrides, $theirColors, 'reminders',
                               '@' . $partner . ':' . $f, $f, $i === false ? 0 : (int) $i);
};

// The "+ Section" control that sits on the folder row.
$sectionInput =
    '<form method="post" action="" class="newsection" id="newSecForm" hidden'
  . ' onsubmit="return this.name.value.trim()!==\'\'">'
  . '<input type="hidden" name="csrf" value="' . $csrf . '">'
  . '<input type="hidden" name="action" value="add_section">'
  . '<input type="hidden" name="view" value="' . e($view) . '">'
  . '<input type="text" name="name" placeholder="+ Section" maxlength="40" autocomplete="off">'
  . '<button type="submit" class="plus" title="Add section">+</button>'
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
      min-height: 100vh; padding: 1.5rem 1rem;   /* same top offset as the other apps */
      overscroll-behavior-y: none;               /* no rubber-band scroll when it all fits */
    }
    .wrap { max-width: 640px; margin: 0 auto; }
    /* Tight bottom margin: the folder dropdown sits directly under this. */
    header {
      display: flex; align-items: center; justify-content: space-between;
    }
    header h1 { font-size: 1.35rem; }   /* same as the Calendar's */
    header .titlebar { display: flex; align-items: center; gap: 0.85rem; }
    header .meta { font-size: 0.8rem; color: #888; }
    header .htitle { min-width: 0; }
    header a { color: #888; text-decoration: none; margin-left: 1rem; }
    header a:hover { color: #fff; }
    header .who {
      color: var(--accent); font-size: 0.8rem; border: 1px solid #2a4a3d;
      border-radius: 999px; padding: 0.15rem 0.6rem;
    }

    /* Completed sits on the folder-dropdown row, sized to match it. */
    /* One height for everything on this row, whichever of them is showing. */
    .foldernav { padding-left: 0; }   /* line Completed up with the sections' + */
    .foldernav .showall, .foldernav .newsection input, .foldernav .newsection .plus { height: 32px; }
    .foldernav .showall {
      background: none; color: #888; border: 1px solid #333; border-radius: 999px;
      padding: 0.3rem 0.75rem; font-size: 16px; cursor: pointer; font-family: inherit;
      white-space: nowrap;
    }
    /* Completed is icon-only (a ☑), so it's a 32px circle like the back button —
       unlike "+ Section", which keeps its label and stays a pill. */
    .foldernav #doneBtn, .foldernav #mdShareBtn {
      width: 32px; padding: 0; flex: 0 0 auto;
      display: inline-flex; align-items: center; justify-content: center;
    }
    .foldernav .showall:hover { border-color: #888; color: #ccc; }
    .foldernav #mdShareBtn.copied { border-color: var(--accent); color: var(--accent); }
    body.show-done .foldernav #doneBtn { background: var(--accent); border-color: var(--accent); color: var(--accent-ink); font-weight: 700; }

    /* The + on each section header, and the row it opens. The "+" sits slightly low in
       the flex box, so nudge it up (padding-bottom, with border-box) to centre it. */
    .sec-add {
      flex: 0 0 auto; align-self: center; background: none; border: 1px solid #2a4a3d;
      color: var(--accent); border-radius: 999px; width: 20px; height: 20px;
      font-size: 0.85rem; line-height: 1; cursor: pointer; font-family: inherit;
      display: inline-flex; align-items: center; justify-content: center; padding: 0;
    }
    .sec-add:hover { border-color: var(--accent); background: var(--accent-soft); }
    .secadd-row { display: flex; gap: 0.5rem; margin: 0.5rem 0 0.25rem; }
    .secadd-row[hidden] { display: none; }   /* make [hidden] win over flex */
    .secadd-row input[type=text] {
      flex: 1; min-width: 0; padding: 0.45rem 0.75rem; background: #1a1a1a;
      border: 1px solid #3a5a4d; border-radius: 999px; color: #eee;
      font-size: 16px;   /* 16px stops iOS from zooming on focus */
    }
    .secadd-row input:focus { outline: none; border-color: var(--accent); }
    .secadd-row .plus {
      flex: 0 0 auto; width: 38px; background: var(--accent); color: var(--accent-ink); border: none;
      border-radius: 999px; font-size: 1.1rem; font-weight: 700; cursor: pointer; font-family: inherit;
      display: inline-flex; align-items: center; justify-content: center; line-height: 1; padding: 0 0 2px;
    }
    .secadd-row .plus:hover { background: #52e0ac; }
    /* A time picked out of the typed text, e.g. "2pm". */
    .attime {
      font-size: 0.75rem; color: #7dd3fc; background: #0c2a3a; padding: 0.15rem 0.5rem;
      border-radius: 999px; white-space: nowrap;
    }
    /* Completed reminders + the clear button stay hidden until "Completed" is on */
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
    .modal .kind input:checked + span { color: var(--accent); font-weight: 700; }
    .modal .kind label:has(input:checked) { border-color: var(--accent); background: var(--accent-soft); }
    .modal .daterow, .modal .secrow { margin-bottom: 1rem; }
    .modal .adddate {
      background: none; border: 1px dashed #3a5a4d; color: var(--accent); border-radius: 6px;
      padding: 0.45rem 0.8rem; font-size: 0.9rem; cursor: pointer; font-family: inherit;
    }
    .modal .adddate:hover { background: var(--accent-soft); }
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
    .modal .buttons .ok { background: var(--accent); color: var(--accent-ink); }

    /* Folder labels, shown above a run of that folder's sections when "All" mixes more
       than one folder together — bigger and a shade darker than a section header, so the
       two read as different levels of the same hierarchy rather than competing. */
    .folder-head { display: flex; align-items: center; gap: 0.35rem; margin: 1.75rem 0 0 0.25rem; }
    .folder-label { font-weight: 700; font-size: 1.3rem; color: #b8860b; line-height: 1.2; }
    /* Half a rem off the name — between the section +'s spacing and the old wider gap —
       and centred on the heading's own centre line rather than its text baseline. */
    .folder-head .fdot { margin-left: 0.15rem; align-self: center; }
    /* A short rule on the heading's own line, trailing off to the right edge. It rides
       in the header rather than sitting between folders, so the first folder gets one
       too — the gap above each heading is what separates one folder from the next. */
    .folder-rule { margin-left: auto; width: 20%; border-top: 1px solid #2a2a2a; align-self: center; }
    /* The "+" that adds a section to this folder — edit mode only, right of its name. */
    .fsec-add {
      flex: 0 0 auto; align-self: center; background: none; border: 1px solid #2a4a3d;
      color: var(--accent); border-radius: 999px; width: 22px; height: 22px; margin-left: 0.15rem;
      font-size: 0.95rem; line-height: 1; cursor: pointer; font-family: inherit; display: none;
      align-items: center; justify-content: center; padding: 0;
    }
    .fsec-add:hover { border-color: var(--accent); background: var(--accent-soft); }
    body.editing .fsec-add { display: inline-flex; }
    .fsec-form.newsection { margin: 0; }

    /* Section headers (bold), grouping reminders */
    /* Same side padding as a row, so the handle and the X line up with the rows'. */
    .section-head { display: flex; align-items: center; gap: 0.75rem; margin: 1.5rem 0 0.25rem; padding: 0 0.25rem; }
    /* The permanent groups' plain-span title, matching the field version's metrics so
       both sit on the same centre line as the chevron and the "+". */
    .section-title { font-weight: 700; font-size: 1.15rem; color: #f0b429; line-height: 1.2; align-self: center; }
    /* The folder's colour, right of the folder's name — the same dot the picker wears. */
    .fdot { flex: 0 0 auto; width: 11px; height: 11px; border-radius: 50%; }
    /* The section's X lines up with the rows' — pushed to the right edge, same shape. */
    .section-head form { margin-left: auto; }
    .section-del {
      background: none; border: 1px solid #444; color: #ccc; border-radius: 6px;
      padding: 0.3rem 0.55rem; font-size: 0.95rem; line-height: 1; cursor: pointer;
      font-family: inherit;
    }
    .section-del:hover { border-color: #f66; color: #f66; }
    /* Subsections: padding-left indents the content, so the delete × stays pinned to the
       right edge and every × lines up down the app. The indent level is a CSS var. */
    ul.rlist > li { padding-left: calc(0.25rem + var(--ind, 0) * 1.5rem); }
    .section-head { padding-left: calc(0.25rem + var(--ind, 0) * 1.5rem); }
    /* The right-hand tail of a section header (indent arrows + delete), pushed to the edge.
       Same gap as a row's own trailing gap, so the ‹ › cluster sits the same distance from
       the × on a section header as it does on a reminder row. */
    .section-head .sec-tail { margin-left: auto; display: inline-flex; align-items: center; gap: 0.75rem; }
    .section-head .sec-tail form { margin-left: 0; }
    /* Indent controls: edit mode only, to the right of a row or section. */
    .indent-ctrls { display: none; flex: 0 0 auto; align-items: center; gap: 0.15rem; }
    body.editing .indent-ctrls { display: inline-flex; }
    .indent-btn {
      background: none; border: 1px solid #444; color: #999; border-radius: 6px;
      padding: 0.3rem 0.4rem; font-size: 0.95rem; line-height: 1; cursor: pointer; font-family: inherit;
    }
    .indent-btn:hover { border-color: #888; color: #ccc; }

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
      font-size: 0.75rem; padding: 0.15rem 0.5rem; border-radius: 999px; white-space: nowrap;
      color: #888; background: #222;
    }
    .due.past   { color: var(--k-overdue); background: var(--k-overdue-bg); }   /* gone by */
    .due.today  { color: var(--k-reminder); background: var(--k-reminder-bg); }   /* due today */
    .due.future { color: var(--k-event-soft); background: var(--k-event-bg); }   /* still ahead */
    .check, .del {
      background: none; border: 1px solid #444; color: #ccc; cursor: pointer;
      border-radius: 6px; padding: 0.3rem 0.55rem; font-size: 0.95rem; line-height: 1;
    }
    .check { padding: 0.25rem 0.4rem; }
    .check:hover { border-color: #7a7; color: #7a7; }
    .del:hover { border-color: #f66; color: #f66; }

    /* Edit mode: the X buttons + drag handles stay hidden until the pencil is tapped.
       The handles keep their column either way — hiding them with display:none nudged
       every line of text sideways the moment you started editing. */
    .del, .section-del { display: none; }
    body.editing .del, body.editing .section-del { display: inline-block; }
    .drag-handle, .sec-handle { visibility: hidden; }
    body.editing .drag-handle, body.editing .sec-handle { visibility: visible; }
    .drag-handle, .sec-handle {
      flex: 0 0 auto; display: inline-flex; align-items: center; justify-content: center; width: 1rem;
      color: #666; font-size: 0.9rem; cursor: grab; touch-action: none; user-select: none;
    }
    /* The permanent groups can't be dragged, but they keep the slot so their titles
       line up with every other section's. */
    .sec-handle.blank { cursor: default; }
    /* On a section header the + and the drag handle share one slot on the left: the +
       out of edit mode, the handle in it. Same width either way, so the name never
       shifts — and the two permanent groups keep the empty slot for the same reason. */
    .section-head .sec-handle { width: 20px; visibility: visible; display: none; }
    /* The header's gap is the rows' gap, so the name sits the same distance from the
       + as it does from the pencil on its other side. */
    body.editing .section-head .sec-handle { display: inline-flex; }
    body.editing .sec-add { display: none; }
    .drag-handle:active, .sec-handle:active { cursor: grabbing; color: var(--accent); }
    li.dragging { background: #1b1f1d; border-radius: 6px; box-shadow: 0 4px 14px rgba(0,0,0,0.45); opacity: 0.45; }
    /* The single line that says where the drop will land. Nothing else moves until the
       drop, so this is the only feedback — it has to be unmissable but not shove the
       list around, hence zero height and a border rather than a block. */
    .drop-line {
      list-style: none; height: 0; margin: 0; padding: 0; border: none;
      border-top: 2px solid var(--accent); box-shadow: 0 0 6px var(--accent-soft);
      pointer-events: none;
    }
    ul.rlist > li.drop-line { display: block; }
    .section-group.dragging { opacity: 0.45; }
    .section-group.dragging .section-head {
      background: #1b1f1d; border-radius: 6px; box-shadow: 0 4px 14px rgba(0,0,0,0.45);
    }
    .section-group.dragging .section-title { color: var(--accent); }
    body.editing ul.rlist { min-height: 1.4rem; }
    body.editing ul.rlist:empty { border: 1px dashed #333; border-radius: 6px; margin: 0.25rem 0; }

    .empty { color: #666; text-align: center; padding: 2rem 0; }
    footer { margin-top: 1.5rem; display: flex; justify-content: flex-end; }
    footer button {
      background: none; border: none; color: #666; font-size: 0.8rem; cursor: pointer;
    }
    footer button:hover { color: #f66; }
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
      width: 190px; max-width: 100%; padding: 0.3rem 0.75rem; background: #1a1a1a; border: 1px dashed #5a4a2a;
      border-radius: 999px; color: #f0b429; font-size: 16px; line-height: 1.2;   /* 16px stops iOS zoom on focus */
    }
    .newsection input::placeholder { color: #f0b429; opacity: 0.85; }
    .newsection input:focus { outline: none; border-style: solid; border-color: #f0b429; }
<?= kind_color_css() ?>
<?= share_modal_styles() ?>
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
        </div>
      </div>
    </div>
    <?php
      // The folder picker rides on the right, by the ⋮; "Manage folders" is the last
      // row of its dropdown rather than a button of its own.
      ob_start();
      render_folder_pick($folderGroups, $view, 'All', $isShared ? '' : 'Manage folders',
                         $hidFolders, $isShared ? '' : $csrf);
      $titleControls = ob_get_clean();
    ?>
    <?= render_user_menu(false, 'editBtn', '', $partner && !$isShared, $titleControls) ?>
  </header>

  <?php // Completed and Section keep the row under the header; the folder picker
        // itself has moved up beside the +. ?>
  <div class="foldernav">
    <button type="button" id="doneBtn" class="showall" title="Completed" aria-label="Completed">&#9745;&#65038;</button>
    <button type="button" id="mdShareBtn" class="showall" title="Copy as Markdown" aria-label="Copy as Markdown">
      <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M4 12v7a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-7"/><path d="M12 3v13"/><path d="M8 7l4-4 4 4"/>
      </svg>
    </button>
    <textarea id="shareMdText" hidden><?= e($shareMd) ?></textarea>
    <button type="button" id="newSecBtn" class="showall">+ Section</button>
    <?= $sectionInput ?>
  </div>

  <?php if (!$isShared) {
        render_folder_modal($myFolders, $csrf, $view, $defFolder, 'New reminders go to',
                            '', $myColors, app_palette('reminders'),
                            $sharedRows, app_palette('reminders', true), 'reminders');
      } ?>
  <?php if ($partner && !$isShared) { echo share_modal_html($partner); } ?>

  <?php // Every folder on screen renders as a block: its sections, then the catch-all
        // that holds whatever isn't in one. The catch-all always renders, so there is
        // always a "+" to add against even in an empty folder. ?>
   <div id="rlist-root">
    <?php
      // Show the folder name as a heading over its sections, even when a single folder
      // is selected — the picker names the current folder, but the heading anchors the
      // list to it too, and keeps the layout identical whether you're on one or "All".
      $showFolders = true;
    ?>
    <?php foreach ($viewFolders as $sfolder): ?>
      <?php if ($showFolders): ?>
        <div class="folder-block" data-folder="<?= e($sfolder) ?>">
          <div class="folder-head">
            <?= folder_collapse_button() ?>
            <div class="folder-label"><?= e($sfolder) ?></div>
            <?php // Edit-mode only: a "+" to the right of the folder name that reveals an
                  // inline section-name field (below), so a folder with no sections of its
                  // own — like the permanent Reminders/Calendar — can get its first. ?>
            <?php if (!$isShared): ?>
              <button type="button" class="fsec-add" data-folder="<?= e($sfolder) ?>" title="Add section">+</button>
              <form method="post" action="" class="fsec-form newsection" hidden
                    onsubmit="return this.name.value.trim()!==''">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="add_section">
                <input type="hidden" name="view" value="<?= e($view) ?>">
                <input type="hidden" name="folder" value="<?= e($sfolder) ?>">
                <input type="text" name="name" placeholder="+ Section" maxlength="40" autocomplete="off">
                <button type="submit" class="plus" title="Add section">+</button>
              </form>
            <?php endif; ?>
            <?php // The folder's own colour, the same dot its entry wears in the picker,
                  // then a short rule trailing off to the right edge on the same line. ?>
            <span class="fdot" style="background:<?= e($folderDotColor($sfolder)) ?>" aria-hidden="true"></span>
            <span class="folder-rule" aria-hidden="true"></span>
          </div>
      <?php endif; ?>
      <?php // Sections always render, empty or not. Each belongs to one folder; its
            // rename and delete forms carry that folder, so acting on it never touches
            // another folder's same-named section. ?>
      <?php foreach ($secRows as $s): ?>
        <?php if ($folderOf($s) !== $sfolder) { continue; } ?>
        <?php $sname = (string) $s['name']; ?>
        <div class="section-group" data-section="<?= e($sname) ?>" data-folder="<?= e($sfolder) ?>"
             data-id="<?= e($s['id']) ?>" style="--ind:<?= (int) ($s['indent'] ?? 0) ?>">
          <div class="section-head">
            <?= section_collapse_button() ?>
            <span class="sec-handle" title="Drag section" aria-hidden="true">&#9776;</span>
            <?= section_title_html($sname, $csrf, $view, false, 'rename_section',
                  '<input type="hidden" name="folder" value="' . e($sfolder) . '">') ?>
            <?php render_section_add_button($sname, $sfolder); ?>
            <span class="sec-tail">
              <?= indent_controls() ?>
              <form method="post" action="" style="display:inline">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="delete_section">
                <input type="hidden" name="view" value="<?= e($view) ?>">
                <input type="hidden" name="folder" value="<?= e($sfolder) ?>">
                <input type="hidden" name="name" value="<?= e($sname) ?>">
                <button class="section-del needs-confirm" type="submit" title="Delete section">&times;</button>
              </form>
            </span>
          </div>
          <?php render_section_add_row($sname, $csrf, $view, $sfolder); ?>
          <?php render_rows($grouped[$s['id']] ?? [], $csrf, $view, $today, $sname); ?>
        </div>
      <?php endforeach; ?>
      <?php // The folder's catch-all: last, undeletable, no drag handle. ?>
      <div class="section-group default-group" data-section="" data-folder="<?= e($sfolder) ?>">
        <div class="section-head">
          <?= section_collapse_button() ?>
          <span class="sec-handle blank" aria-hidden="true"></span>
          <span class="section-title"><?= DEFAULT_SECTION ?></span>
          <?php render_section_add_button('', $sfolder); ?>
        </div>
        <?php render_section_add_row('', $csrf, $view, $sfolder); ?>
        <?php render_rows($looseByFolder[$sfolder] ?? [], $csrf, $view, $today, ''); ?>
      </div>
      <?php if ($showFolders): ?></div><?php endif; ?>
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
</div>
<?php render_tabbar('reminders'); ?>
<script>
  const TODAY = '<?= date('Y-m-d') ?>';

  // ----- Per-section add: the + on a header opens that section's row -----
  document.querySelectorAll('.sec-add').forEach(btn => {
    btn.addEventListener('click', () => {
      const row = document.getElementById(btn.dataset.target);
      if (!row) return;
      const opening = row.hidden;
      // Only one row open at a time, so the page doesn't fill up with inputs.
      document.querySelectorAll('.secadd-row').forEach(r => { r.hidden = true; });
      row.hidden = !opening;
      if (opening) { const i = row.querySelector('input[type=text]'); i.value = ''; i.focus(); }
    });
  });
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.querySelectorAll('.secadd-row').forEach(r => { r.hidden = true; });
  });

  // ----- Edit mode: reveal the X delete buttons + drag handles -----
  // There's no Edit button any more; the pencil on each section header is the way in.
  const doneBtn = document.getElementById('doneBtn');
  // While editing, "show all" is a transient view state; the saved preference
  // (remShowDone) is untouched, so leaving edit restores whatever you had before.
  const savedShowDone = () => localStorage.getItem('remShowDone') === '1';
  let editShowDone = savedShowDone();
  const applyShowDone = () => {
    const on = document.body.classList.contains('editing') ? editShowDone : savedShowDone();
    document.body.classList.toggle('show-done', on);
  };
  // The pencils turn the controls on and leave the view alone; "Completed" is its
  // own button, so edit mode never quietly changes what you're looking at.
  const setEdit = (on) => {
    document.body.classList.toggle('editing', on);
    editShowDone = on ? editShowDone : savedShowDone();
    applyShowDone();
  };
  const editing = () => document.body.classList.contains('editing');
  // Always start out of edit mode. The only exception is a structural change that
  // redirects back here (?edit=1), so adding a folder or section doesn't kick you out.
  setEdit(new URLSearchParams(location.search).get('edit') === '1');
  if (new URLSearchParams(location.search).get('edit') === '1') {
    const u = new URL(location.href); u.searchParams.delete('edit');
    history.replaceState(null, '', u);
  }
  window.sectionEditToggle = () => setEdit(!editing());
  document.querySelectorAll('.sec-edit').forEach(p => p.addEventListener('click', window.sectionEditToggle));

  // "+ Section" becomes the field it's asking for, and goes back if left empty — the
  // button and the field are the same size, so the row doesn't jump when they swap.
  const newSecBtn = document.getElementById('newSecBtn'), newSecForm = document.getElementById('newSecForm');
  if (newSecBtn && newSecForm) {
    const secInput = newSecForm.querySelector('input[type=text]');
    const closeSec = () => { newSecForm.hidden = true; newSecBtn.hidden = false; secInput.value = ''; };
    newSecBtn.addEventListener('click', () => { newSecBtn.hidden = true; newSecForm.hidden = false; secInput.focus(); });
    secInput.addEventListener('keydown', e => { if (e.key === 'Escape') { e.preventDefault(); closeSec(); } });
    secInput.addEventListener('blur', () => { if (secInput.value.trim() === '') { closeSec(); } });
  }

  // Each folder head carries its own "+ Section" (edit mode): it reveals an inline name
  // field for that folder; typing and Enter/blur adds the section there, an empty field
  // just closes again — same swap as the toolbar's + Section, scoped to one folder.
  const fsecClose = (form) => {
    if (!form) { return; }
    const btn = form.closest('.folder-head')?.querySelector('.fsec-add');
    form.hidden = true; form.querySelector('input[type=text]').value = ''; if (btn) { btn.hidden = false; }
  };
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('.fsec-add'); if (!btn) { return; }
    e.preventDefault(); e.stopPropagation();
    const form = btn.closest('.folder-head')?.querySelector('.fsec-form'); if (!form) { return; }
    btn.hidden = true; form.hidden = false; form.querySelector('input[type=text]').focus();
  });
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') { return; }
    const inp = e.target.closest && e.target.closest('.fsec-form input[type=text]');
    if (inp) { e.preventDefault(); fsecClose(inp.closest('.fsec-form')); }
  });
  document.addEventListener('blur', (e) => {
    const inp = e.target.closest && e.target.closest('.fsec-form input[type=text]');
    if (inp && inp.value.trim() === '') { fsecClose(inp.closest('.fsec-form')); }
  }, true);
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
  // What the share window draws: my calendars, my reminder folders, what's ticked now.
  window.SHARES = <?= json_encode(($partner && !$isShared) ? shares_load($cfg['data_dir'], $me) : ['calendars' => [], 'folders' => []]) ?>;
  window.shareData = () => ({
    cals: <?= json_encode($shareCals) ?>,
    folders: <?= json_encode($myFolders) ?>,
    notefolders: <?= json_encode(($partner && !$isShared) ? folders_load($cfg['data_dir'])['notes'] : []) ?>,
    shares: window.SHARES
  });
  window.onSharesChanged = (s) => { window.SHARES = s; window.shareRender(); };
  let dragLi = null, dragSection = null;

  // ----- The outline the drag model works in -----
  // Level 0 is a section. Level 1 is a subsection or a reminder sitting under a section.
  // One more indent again is a sub-subsection or a sub-reminder. A section's own indent
  // is its level; a reminder's level is the level of the section it's sitting in, plus
  // one. Nothing here is a real tree — it's flat rows read as an outline — so "the
  // block belonging to a section" is worked out by walking forward, not by nesting.
  const lvlOf   = g => parseInt(g.style.getPropertyValue('--ind'), 10) || 0;
  const isTop   = g => lvlOf(g) === 0;
  // A level-0 section carries everything indented under it: its own rows come along
  // inside it, and the subsections that follow it come along as whole groups.
  const blockOf = g => {
    const out = [g];
    let n = g.nextElementSibling;
    while (n && n.classList.contains('section-group') && !isTop(n)) { out.push(n); n = n.nextElementSibling; }
    return out;
  };
  // The level-0 section a group belongs to (itself, if it is one).
  const topOf = g => { while (g && !isTop(g)) { g = g.previousElementSibling; } return g; };

  // A single line showing where the thing being dragged will land. Nothing moves until
  // the drop — the old behaviour shuffled the real rows around mid-drag, which made it
  // impossible to tell what you were about to get.
  let dropLine = null, dragBlock = null;
  const clearLine = () => { if (dropLine) { dropLine.remove(); dropLine = null; } };
  // <li> inside a row list, <div> between section blocks — so the marker is always legal
  // where it's put and inherits that context's width.
  const makeLine = tag => { const el = document.createElement(tag); el.className = 'drop-line'; return el; };
  const lineBefore = (ref, after, tag) => {
    clearLine();
    dropLine = makeLine(tag);
    ref.parentNode.insertBefore(dropLine, after ? ref.nextSibling : ref);
  };
  const lineInto = (ul) => { clearLine(); dropLine = makeLine('li'); ul.appendChild(dropLine); };

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
    const secHandle = e.target.closest('.sec-handle:not(.blank)');
    const remHandle = e.target.closest('.drag-handle');
    pid = e.pointerId; sx = e.clientX; sy = e.clientY;
    if (secHandle) {
      dragSection = secHandle.closest('.section-group');
      if (!dragSection) return;
      e.preventDefault();
      // A level-0 section travels with everything indented under it, so the whole block
      // dims together and the whole block is what lands at the line.
      dragBlock = isTop(dragSection) ? blockOf(dragSection) : [dragSection];
      dragBlock.forEach(m => m.classList.add('dragging'));
      clearLine();
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
      pressTimer = setTimeout(() => { pressTimer = null; beginItem(li); }, 250);
    }
  });

  // iOS likes to sit on a touch deciding whether it's a scroll before it emits
  // pointerdown. Claiming the touch on the handles makes the grab feel immediate.
  document.addEventListener('touchstart', (e) => {
    if (!document.body.classList.contains('editing')) return;
    if (e.target.closest('.drag-handle, .sec-handle:not(.blank)')) e.preventDefault();
  }, { passive: false });

  document.addEventListener('pointermove', (e) => {
    if (pressTimer) {                                  // waiting on a hold: movement = scroll/tap -> cancel
      if (Math.abs(e.clientX - sx) > 14 || Math.abs(e.clientY - sy) > 14) cancelPress();
      return;
    }
    if (!dragLi && !dragSection) return;
    e.preventDefault();
    const under = document.elementFromPoint(e.clientX, e.clientY);
    if (!under) return;
    const y = e.clientY;

    if (dragSection && isTop(dragSection)) {
      // ----- Level 0 -----
      // A whole section, everything under it travelling with it. It can only land
      // between other level-0 blocks, never inside one, so its subsections and rows
      // always stay grouped together underneath it.
      const g = under.closest('.section-group');
      if (!g) return;
      const top = topOf(g);
      if (!top || dragBlock.includes(top)) { return; }   // over itself: leave the line be
      const blk   = blockOf(top);
      const first = blk[0], last = blk[blk.length - 1];
      const mid   = (first.getBoundingClientRect().top + last.getBoundingClientRect().bottom) / 2;
      const after = y > mid;
      lineBefore(after ? last : first, after, 'div');
      return;
    }

    // ----- Level 1 -----
    // A reminder, or a subsection. Either can go between any two level-1 things, which
    // means anywhere in any row list. A hovered row has to be tested before the group
    // that contains it, or every row would resolve to "its section" and the line could
    // never land between two rows.
    const overLi = under.closest('li[data-id]');
    if (overLi === dragLi) { return; }        // over itself: leave the line where it is
    if (overLi) {
      const r = overLi.getBoundingClientRect();
      lineBefore(overLi, y > r.top + r.height / 2, 'li');
      return;
    }
    const ul = under.closest('ul.rlist');
    if (ul) { lineInto(ul); return; }         // past the last row, or an empty list
    // Not over a row list: the gap around a section header. A reminder drops into that
    // section's list; a subsection lands beside the header as a sibling group.
    const g = under.closest('.section-group');
    if (!g) return;
    if (dragLi) {
      const gul = g.querySelector(':scope > ul.rlist');
      if (gul) lineInto(gul);
      return;
    }
    if (g !== dragSection) {
      const r = g.getBoundingClientRect();
      lineBefore(g, y > r.top + r.height / 2, 'div');
    }
  }, { passive: false });

  // Land whatever's being dragged where the line is showing.
  const applyDrop = () => {
    if (!dropLine) { clearLine(); return; }
    const parent = dropLine.parentNode;
    if (dragLi) {
      parent.insertBefore(dragLi, dropLine);
    } else if (dragSection) {
      if (!isTop(dragSection) && parent.classList.contains('rlist')) {
        // A subsection dropped in among rows takes the rows below it: everything from
        // the line down to the end of that list becomes its. The list only ever holds
        // one section's rows, so "to the end of the list" *is* "up to the next
        // subsection or section".
        const secUl = dragSection.querySelector(':scope > ul.rlist');
        const trailing = [];
        for (let n = dropLine.nextElementSibling; n; n = n.nextElementSibling) {
          if (n.matches('li[data-id]')) trailing.push(n);
        }
        const targetGroup = parent.closest('.section-group');
        if (targetGroup) targetGroup.parentNode.insertBefore(dragSection, targetGroup.nextSibling);
        if (secUl) trailing.forEach(t => secUl.appendChild(t));
      } else {
        // Level 0 moves as a block; a subsection dropped between groups moves alone.
        const moving = isTop(dragSection) ? (dragBlock || [dragSection]) : [dragSection];
        moving.forEach(m => parent.insertBefore(m, dropLine));
      }
    }
    clearLine();
  };

  const endDrag = () => {
    const wasPress = !!pressTimer;      // timer still pending => it was a short tap, not a drag
    const tapLi = tapTextLi;
    cancelPress();
    const wasDrag = !!(dragLi || dragSection);
    applyDrop();
    if (dragLi) dragLi.classList.remove('dragging');
    if (dragSection) { dragSection.classList.remove('dragging'); (dragBlock || []).forEach(m => m.classList.remove('dragging')); }
    dragBlock = null;
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

  // ----- Copy the visible list as Markdown (the share icon in the toolbar) -----
  const mdShareBtn = document.getElementById('mdShareBtn');
  if (mdShareBtn) {
    mdShareBtn.addEventListener('click', () => {
      navigator.clipboard.writeText(document.getElementById('shareMdText').value).then(() => {
        mdShareBtn.classList.add('copied');
        setTimeout(() => mdShareBtn.classList.remove('copied'), 1200);
      }).catch(() => {});
    });
  }

  // ----- Enter edit mode by gesture (there's no Edit button) -----
  // Long-press a reminder or section on touch, or double-click on the desktop. Landing
  // on a reminder also opens its text for editing straight away.
  const gestureBlocked = (t) => t.closest('.check, .del, .sec-add, .section-del, .sec-collapse, button, a, input, textarea, select');
  const gestureEdit = (target) => {
    const li = target.closest('li[data-id]'), head = target.closest('.section-head');
    if (!li && !head) return;
    if (!editing()) setEdit(true);
    if (li) startInlineEdit(li);
  };
  document.addEventListener('dblclick', (e) => {
    if (editing()) return;
    if (gestureBlocked(e.target) && !e.target.closest('.text')) return;
    gestureEdit(e.target);
  });
  let lpT = null, lpX = 0, lpY = 0, lpEl = null;
  const clearLp = () => { if (lpT) { clearTimeout(lpT); lpT = null; } };
  document.addEventListener('pointerdown', (e) => {
    if (e.pointerType === 'mouse' || editing()) return;      // touch/pen only; desktop uses dblclick
    if (gestureBlocked(e.target) && !e.target.closest('.text')) return;
    if (!e.target.closest('li[data-id], .section-head')) return;
    lpEl = e.target; lpX = e.clientX; lpY = e.clientY;
    lpT = setTimeout(() => { lpT = null; if (navigator.vibrate) navigator.vibrate(12); gestureEdit(lpEl); }, 500);
  });
  document.addEventListener('pointermove', (e) => {
    if (lpT && (Math.abs(e.clientX - lpX) > 10 || Math.abs(e.clientY - lpY) > 10)) clearLp();
  });
  document.addEventListener('pointerup', clearLp);
  document.addEventListener('pointercancel', clearLp);

  // Leave edit mode by tapping away from what you're editing (no Edit button to press).
  // A tap stays in edit only if it lands on the thing you're editing or an edit control —
  // a reminder's text (which starts editing it), a section name field, a handle, a
  // delete/add/collapse/check button, the toolbar, or a modal.
  document.addEventListener('click', (e) => {
    if (!editing() || suppressClick) return;
    if (e.target.closest('.textedit, .text, .sectitle, .sec-handle, .secadd-row, .newsection,'
        + ' .foldernav, button, a, input, textarea, select,'
        + ' .modal-backdrop, .setmodal-backdrop, .sh-modal, .tabbar')) return;
    setEdit(false);
  });
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape' || !editing()) return;
    if (document.querySelector('.textedit') || document.querySelector('.secadd-row:not([hidden])')) return;
    setEdit(false);
  });

  // ----- Indent / outdent a reminder or section into a subsection (edit mode) -----
  document.addEventListener('click', (e) => {
    const b = e.target.closest('.indent-btn'); if (!b) return;
    e.preventDefault(); e.stopPropagation();
    const host = b.closest('[data-id]'); if (!host) return;
    let ind = parseInt(host.style.getPropertyValue('--ind'), 10); if (isNaN(ind)) ind = 0;
    ind = Math.max(0, Math.min(4, ind + (b.dataset.dir === 'in' ? 1 : -1)));
    host.style.setProperty('--ind', ind);
    const body = new URLSearchParams({ csrf: CSRF, action: 'set_indent', view: VIEW, id: host.dataset.id, indent: ind });
    fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body }).catch(() => location.reload());
  });
</script>
<?= folder_modal_script() ?>
<?= chrome_script() ?>
<?php if ($partner && !$isShared) { echo share_modal_script($csrf); } ?>
</body>
</html>
