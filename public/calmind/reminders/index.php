<?php
// A page served under /test/ (the sandbox mirror) loads lib-test/ instead of lib/, and one
// served under /dev/ (a second, fixed sandbox slot) loads lib-dev/ — each mirror
// isolated in code, config and data. Cross-app links carry the same prefix via suite_path();
// _self_path() redirects already stay put. Keep this preamble identical when adding a page.
$__test   = strpos(__DIR__, '/test/') !== false
         || strncmp($_SERVER['REQUEST_URI'] ?? '', '/test/', 6) === 0;
$__dev    = strpos(__DIR__, '/dev/') !== false
         || strncmp($_SERVER['REQUEST_URI'] ?? '', '/dev/', 5) === 0;
$__libDir = null;
$__cands  = $__dev
    ? [__DIR__ . '/../../../../lib-dev', '/home/protected/lib-dev']
    : ($__test
        ? [__DIR__ . '/../../../../lib-test', '/home/protected/lib-test']
        : [__DIR__ . '/../../../lib',         '/home/protected/lib']);
foreach ($__cands as $__c) {
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
// Which of the partner's shared folders I've switched off in my own "All" (keyed by the
// full "@partner:Folder" key). Their data is read-only to me — this is just my view.
$sharedHidden = $partner ? folders_shared_hidden($cfg['data_dir'], 'reminders') : [];

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
    // Sort in outline blocks, not row by row: a top-level reminder carries the subtasks
    // that follow it in stored order. Sorting the flat list instead tore a family apart
    // the moment the two disagreed — an undated subtask under a dated parent sorted to
    // the head of the section, where it read as a subtask of whatever now sat above it.
    $blocks = [];
    foreach ($rows as $r) {
        if ($blocks && (int) ($r['indent'] ?? 0) > 0) { $blocks[count($blocks) - 1]['rows'][] = $r; }
        else { $blocks[] = ['due' => ($r['due'] ?? '') ?: '', 'seq' => count($blocks), 'rows' => [$r]]; }
    }
    // '' (undated) sorts before any real date; stored order breaks a tie.
    usort($blocks, fn($a, $b) => $a['due'] !== $b['due']
        ? strcmp($a['due'], $b['due'])
        : ($a['seq'] <=> $b['seq']));
    $out = [];
    foreach ($blocks as $b) { foreach ($b['rows'] as $r) { $out[] = $r; } }
    return $out;
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
            title="Add to <?= $label ?>" aria-label="Add to <?= $label ?>"><?= plus_icon_svg(12) ?></button>
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
      <button type="submit" class="plus" title="Add" aria-label="Add"><?= plus_icon_svg(16, 3) ?></button>
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

/**
 * The edit-mode control just left of a row's delete ×. One slot, two jobs, decided by
 * where the row already sits — there is one level of subtask and no more:
 *
 *   a task    → "+", which adds a *new* subtask under this one and opens it to type in
 *   a subtask → "‹", which lifts it back out to being a task of its own
 *
 * The + used to indent the row you pressed it on, which is a different act entirely:
 * it demoted an existing reminder rather than making a child of it, and there was no
 * way to add a subtask without first adding a reminder somewhere else and dragging it.
 */
function subtask_button(int $ind, string $csrf, string $view, string $id): string
{
    if ($ind > 0) {
        return '<button type="button" class="subtask-btn out" data-id="' . e($id) . '"'
             . ' title="Make it a task again" aria-label="Make it a task again">&lsaquo;</button>';
    }
    return '<form method="post" action="" class="subtask-btn-form">'
         . '<input type="hidden" name="csrf" value="' . $csrf . '">'
         . '<input type="hidden" name="action" value="add_subtask">'
         . '<input type="hidden" name="view" value="' . e($view) . '">'
         . '<input type="hidden" name="parent" value="' . e($id) . '">'
         . '<button type="submit" class="subtask-btn" title="Add subtask"'
         . ' aria-label="Add subtask">' . plus_icon_svg(11) . '</button></form>';
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
            style="--ind:<?= min(1, (int) ($r['indent'] ?? 0)) ?>"
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
          <?= subtask_button(min(1, (int) ($r['indent'] ?? 0)), $csrf, $view, (string) $r['id']) ?>
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

/** A read-only list of the partner's reminders (in "All"): text, time and due only —
 *  no check, drag or delete, since their data is never mine to edit. */
function render_ro_rows(array $rows, string $today): void
{
    echo '<ul class="rlist ro">';
    foreach (sort_by_date($rows) as $r) {
        $done = !empty($r['done']);
        $when = !empty($r['due']) ? ($r['due'] < $today ? 'past' : ($r['due'] === $today ? 'today' : 'future')) : '';
        echo '<li class="ro-row' . ($done ? ' done' : '') . '">';
        echo '<span class="ro-mark' . ($done ? ' on' : '') . '" aria-hidden="true">' . ($done ? '&#10003;' : '') . '</span>';
        echo '<span class="text">' . e($r['text'] ?? '') . '</span>';
        if (!empty($r['time'])) { echo '<span class="attime">' . e(date('g:ia', strtotime($r['time']))) . '</span>'; }
        if (!empty($r['due']))  { echo '<span class="due ' . $when . '">' . e($r['due']) . '</span>'; }
        echo '</li>';
    }
    echo '</ul>';
}

/** One of the partner's shared folders, rendered read-only in my "All": a badged head,
 *  then their sections and the loose catch-all, all non-interactive. */
function render_shared_folder_ro(string $dir, string $partner, string $folder, string $key,
                                 string $color, string $today): void
{
    // Normalise their list in memory (never saved — their data is theirs) so their loose
    // reminders show under a real section rather than a nameless catch-all.
    $all   = sections_normalize(load_reminders(user_data_file($dir, 'reminders', $partner)), [$folder]);
    $secs  = array_values(array_filter($all, fn($it) => is_section($it) && ($it['folder'] ?? '') === $folder));
    $items = array_values(array_filter($all, fn($it) => !is_section($it)
        && ($it['folder'] ?? folder_fallback('reminders')) === $folder));
    $names = array_map(fn($s) => (string) $s['name'], $secs);
    $bySec = [];
    $loose = [];
    foreach ($items as $r) {
        $s = (string) ($r['section'] ?? '');
        if ($s !== '' && in_array($s, $names, true)) { $bySec[$s][] = $r; } else { $loose[] = $r; }
    }
    ?>
    <div class="folder-block shared-block" data-folder="<?= e($key) ?>">
      <div class="folder-head">
        <?= folder_collapse_button() ?>
        <div class="folder-label" style="background:<?= e(folder_tint($color)) ?>"><?= e($folder) ?></div>
        <span class="fshared-badge" title="Shared by <?= e($partner) ?>"><?= e($partner) ?></span>
        <span class="folder-rule" aria-hidden="true"></span>
      </div>
      <?php // Their sections fold like mine do. The collapse key is folder + section, and
            // the folder here is the "@partner:Folder" view key, so their "Errands" and my
            // "Errands" can never collapse each other. Read-only is about their *data* —
            // whether I want to look at it right now is mine to decide. ?>
      <?php foreach ($secs as $s): $sn = (string) $s['name']; if (empty($bySec[$sn])) { continue; } ?>
        <div class="section-group" data-section="<?= e($sn) ?>" data-folder="<?= e($key) ?>">
          <div class="section-head">
            <?= section_collapse_button() ?>
            <span class="section-title"><?= e($sn) ?></span>
          </div>
          <?php render_ro_rows($bySec[$sn], $today); ?>
        </div>
      <?php endforeach; ?>
      <?php if ($loose): ?>
        <div class="section-group" data-section="" data-folder="<?= e($key) ?>">
          <div class="section-head">
            <?= section_collapse_button() ?>
            <span class="section-title"><?= DEFAULT_SECTION ?></span>
          </div>
          <?php render_ro_rows($loose, $today); ?>
        </div>
      <?php endif; ?>
    </div>
    <?php
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
        // Reopen the manager, but don't force edit mode — a manage action only stays in edit
        // if you were already in it (the form carries `edit` then).
        header('Location: ' . $backUrl . (!empty($_POST['edit']) ? '&edit=1' : '') . '&fm=1');
        exit;
    }
    // The Manage-folders "Default for new items" picker sets the default folder and its
    // section together, from a "folder\x1Fsection" value. The section is validated against
    // the folder's real sections, falling back to the first.
    if ($_POST['action'] === 'set_default_section') {
        $v = (string) ($_POST['fs'] ?? '');
        [$dFolder, $dSection] = strpos($v, "\x1F") !== false ? explode("\x1F", $v, 2) : [$v, ''];
        if (in_array($dFolder, $myFolders, true)) {
            $secs = [];
            foreach (sections_normalize(load_reminders($dataFile), $myFolders) as $it) {
                if (is_section($it) && ($it['folder'] ?? '') === $dFolder) { $secs[] = (string) $it['name']; }
            }
            if (!in_array($dSection, $secs, true)) { $dSection = $secs[0] ?? SECTION_DEFAULT_NAME; }
            folder_default_section_set($cfg['data_dir'], 'reminders', $dFolder, $dSection);
        }
        header('Location: ' . $backUrl . (!empty($_POST['edit']) ? '&edit=1' : '') . '&fm=1');
        exit;
    }
    // The show/hide box on a folder row in the picker (AJAX; the page reloads itself).
    if ($_POST['action'] === 'folder_vis') {
        $vname = (string) ($_POST['name'] ?? '');
        // A "@partner:Folder" key is one of theirs I'm showing/hiding in my own view.
        if (strncmp($vname, '@', 1) === 0) {
            folder_shared_hidden_set($cfg['data_dir'], 'reminders', $vname, empty($_POST['show']));
        } else {
            folder_hidden_set($cfg['data_dir'], 'reminders', $vname, empty($_POST['show']));
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'hidden' => folders_hidden($cfg['data_dir'], 'reminders')]);
        exit;
    }
    // The "All" master in the picker — its box or its row: show or hide every folder at
    // once, the partner's shared ones included (the picker sends the keys it drew).
    if ($_POST['action'] === 'folder_vis_all') {
        $keys = folder_pick_keys((string) ($_POST['keys'] ?? ''));
        if ($keys) { folders_set_visible($cfg['data_dir'], 'reminders', $keys, empty($_POST['show']) ? [] : $keys); }
        else       { folders_set_all_hidden($cfg['data_dir'], 'reminders', empty($_POST['show'])); }
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'hidden' => folders_hidden($cfg['data_dir'], 'reminders')]);
        exit;
    }
    // Tapping a folder's row: that one becomes the only one ticked (AJAX).
    if ($_POST['action'] === 'folder_vis_only') {
        $keys = folder_pick_keys((string) ($_POST['keys'] ?? ''));
        folders_set_visible($cfg['data_dir'], 'reminders', $keys, [(string) ($_POST['name'] ?? '')]);
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
    if ($_POST['action'] === 'rename_folder') {
        // Rename one of my own (non-permanent) folders and carry every reminder and section
        // that named it across. folders_rename() refuses a fixed/duplicate/empty name and
        // reports whether it ran, so a no-op doesn't rewrite the reminders file.
        $old  = (string) ($_POST['name'] ?? '');
        $new  = folder_clean((string) ($_POST['newname'] ?? ''));
        $done = folders_rename($cfg['data_dir'], 'reminders', $old, $new);
        if ($done) {
            $list = load_reminders($dataFile);
            foreach ($list as &$r) {
                if (($r['folder'] ?? folder_fallback('reminders')) === $old) { $r['folder'] = $new; }
            }
            unset($r);
            save_reminders($dataFile, $list);
        }
        $vw = (string) ($_POST['view'] ?? 'All');
        if ($done && $vw === $old) { $vw = $new; }
        // From the manager (fm): reopen it without forcing edit mode. From the list heading
        // (in edit mode): stay in edit, since that's where the field lives.
        $extra = !empty($_POST['fm']) ? '&fm=1' : '&edit=1';
        header('Location: ' . _self_path() . '?folder=' . urlencode($vw) . $extra);
        exit;
    }
    if ($_POST['action'] === 'delete_folder') {
        $name = (string) ($_POST['name'] ?? '');
        folders_delete($cfg['data_dir'], 'reminders', $name);   // "Calendar" is refused; "Reminders" isn't
        $myFoldersNow = folders_load($cfg['data_dir'])['reminders'];
        // If the folder is still there, the delete was refused (a permanent folder) — do NOT
        // touch its items. (Moving them regardless would strip a permanent folder like
        // Calendar of its reminders while the folder stayed put.)
        if (in_array($name, $myFoldersNow, true)) {
            header('Location: ' . _self_path() . '?folder=All' . (!empty($_POST['edit']) ? '&edit=1' : '') . '&fm=1');
            exit;
        }
        // Move that folder's reminders to the default folder and its default section (chosen
        // in Manage folders). folder_default_get() already skips the deleted folder, so this
        // resolves to a real destination; the section is validated against it.
        $destFolder = folder_default_get($cfg['data_dir'], 'reminders');
        $list = sections_normalize(load_reminders($dataFile), $myFoldersNow);
        $destSecs = [];
        foreach ($list as $it) {
            if (is_section($it) && ($it['folder'] ?? '') === $destFolder) { $destSecs[] = (string) $it['name']; }
        }
        $defSecRaw = folder_default_section_get($cfg['data_dir'], 'reminders');
        $destSec   = in_array($defSecRaw, $destSecs, true) ? $defSecRaw : ($destSecs[0] ?? SECTION_DEFAULT_NAME);
        foreach ($list as &$r) {
            if (!is_section($r) && ($r['folder'] ?? '') === $name) { $r['folder'] = $destFolder; $r['section'] = $destSec; }
        }
        unset($r);
        save_reminders($dataFile, $list);
        header('Location: ' . _self_path() . '?folder=All' . (!empty($_POST['edit']) ? '&edit=1' : '') . '&fm=1');
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
        // Any non-empty name is fine now (the duplicate check below keeps a folder from
        // holding two same-named sections); there's no reserved catch-all name any more.
        if ($name !== '') {
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
        if (!$isShared) { $list = sections_normalize($list, $folders); }
        // A folder always keeps at least one section: the last one can't be deleted. When
        // it isn't the last, its reminders move into the folder's first remaining section
        // rather than being orphaned — there's no nameless catch-all to fall to any more.
        $folderSecNames = [];
        foreach ($list as $it) {
            if (is_section($it) && ($it['folder'] ?? '') === $secFolder) { $folderSecNames[] = (string) $it['name']; }
        }
        if (count($folderSecNames) <= 1) {          // it's the folder's only section
            header('Location: ' . $editBack);
            exit;
        }
        $rest  = array_values(array_filter($folderSecNames, fn($n) => $n !== $name));
        $moveTo = $rest[0] ?? SECTION_DEFAULT_NAME;
        // Only this folder's copy of the section goes; other folders keep theirs.
        $list = array_filter($list, fn($it) => !(is_section($it)
            && ($it['name'] ?? '') === $name && ($it['folder'] ?? '') === $secFolder));
        foreach ($list as &$r) {
            if (!is_section($r) && ($r['section'] ?? '') === $name
                && ($r['folder'] ?? folder_fallback('reminders')) === $secFolder) { $r['section'] = $moveTo; }
        }
        unset($r);
        save_reminders($dataFile, $list);
        header('Location: ' . $editBack);
        exit;
    }

    // Reorder / re-section / re-folder reminders after a drag (AJAX). order = [{id,
    // section, folder}, …] top-to-bottom; sections = a map folder => [section id, …]
    // (the catch-all as ''), the same shape Notes posts. The old flat list of bare
    // names only worked inside a single named folder, so a section drag on "All" — the
    // view most people live in — was silently thrown away on reload.
    if ($_POST['action'] === 'reorder') {
        $order = json_decode((string) ($_POST['order'] ?? '[]'), true);
        if (!is_array($order)) { $order = []; }
        $secMap = json_decode((string) ($_POST['sections'] ?? '[]'), true);
        if (!is_array($secMap)) { $secMap = []; }
        // A payload that decoded to nothing carries no instruction. Rebuilding from it
        // still preserved every row, but it reassembled the file as "sections, then
        // everything else" — and stored order is what breaks ties between same-dated
        // reminders, so a garbage post quietly reshuffled the list.
        if (!$order && !$secMap) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'skipped' => 'empty order']);
            exit;
        }
        $list = load_reminders($dataFile);

        // A section listed under a folder is re-filed to it — by id, since names collide
        // across folders — and its rows follow via the order below. A stale page from
        // before this shape posted a flat list of names: its entries fail both the
        // is_array and the id lookup, so it reorders nothing rather than guessing.
        $secById = [];   // id => section row
        $byId    = [];   // reminder id => row
        foreach ($list as $it) {
            if (is_section($it)) { $secById[$it['id']] = $it; }
            else { $byId[$it['id']] = $it; }
        }

        $sectionRows = [];
        $placed      = [];   // section id => true
        $namesIn     = [];   // folder => [name => true], so a move can't create a duplicate
        // A move into a folder that already holds that name must be refused — items
        // reference sections by name, and a duplicate loses items when one is deleted.
        // Names are claimed by every section that is *staying put*: the ones the drag
        // never listed, and the ones listed under their own stored folder (a whole-screen
        // payload lists every folder's run, so the destination's own same-named section
        // is in the map too — it mustn't free its name just by being mentioned).
        $inMap = [];   // section id => the folder the map lists it under
        foreach ($secMap as $f => $ids) {
            if (!is_array($ids)) { continue; }
            foreach ($ids as $sid) { $inMap[(string) $sid] ??= (string) $f; }
        }
        foreach ($list as $it) {
            if (!is_section($it)) { continue; }
            $home = (string) ($it['folder'] ?? folder_fallback('reminders'));
            $to   = $inMap[(string) $it['id']] ?? null;
            if ($to === null || $to === $home) { $namesIn[$home][(string) $it['name']] = true; }
        }
        foreach ($secMap as $f => $ids) {
            if (!is_array($ids)) { continue; }
            $folderOk = in_array($f, $myFolders, true);
            foreach ($ids as $sid) {
                $sid = (string) $sid;
                if ($sid === '' || !isset($secById[$sid]) || isset($placed[$sid])) { continue; }
                $srow = $secById[$sid];
                $name = (string) $srow['name'];
                $home = (string) ($srow['folder'] ?? folder_fallback('reminders'));
                // Re-file to $f if it's one of mine and no *staying* section there holds
                // the name; otherwise the section keeps its folder (but takes its slot).
                $dest = ($folderOk && ($f === $home || !isset($namesIn[$f][$name]))) ? $f : $home;
                $srow['folder'] = $dest;
                $namesIn[$dest][$name] = true;
                $sectionRows[] = $srow;
                $placed[$sid]  = true;
            }
            // The catch-all rides in the same drag but has no row to carry its place, so
            // its slot in $f — how many real sections precede its '' marker — is stored
            // beside the folder list.
            if ($folderOk && in_array('', $ids, true)) {
                $idx = 0;
                foreach ($ids as $sid) { if ((string) $sid === '') { break; } if (isset($secById[(string) $sid])) { $idx++; } }
                folder_catchall_index_set($cfg['data_dir'], 'reminders', $f, $idx);
            }
        }
        // Sections the drag never touched keep their place, after the reordered ones.
        foreach ($list as $it) {
            if (is_section($it) && !isset($placed[$it['id']])) { $sectionRows[] = $it; }
        }

        // secExists reflects the re-filed sections — each row below is validated against
        // where its section actually ended up.
        $secExists = [];
        foreach ($sectionRows as $s) {
            $secExists[($s['folder'] ?? folder_fallback('reminders')) . "\x1F" . $s['name']] = true;
        }
        $newReminders = [];
        $used = [];
        foreach ($order as $o) {
            $id = (string) ($o['id'] ?? '');
            if ($id === '' || !isset($byId[$id]) || isset($used[$id])) { continue; }
            $item = $byId[$id];
            // The drag posts the folder of the block each row landed in; only my own
            // folders count (a partner's shared block is skipped client-side too).
            $folder = (string) ($o['folder'] ?? '');
            if ($folder !== '' && in_array($folder, $myFolders, true)) { $item['folder'] = $folder; }
            $sec = (string) ($o['section'] ?? '');
            $f   = $item['folder'] ?? folder_fallback('reminders');
            // A section must exist in this item's own folder, or the row falls to the
            // folder's catch-all.
            if ($sec !== '' && !isset($secExists[$f . "\x1F" . $sec])) { $sec = ''; }
            $item['section'] = $sec;
            $newReminders[]  = $item;
            $used[$id]       = true;
        }
        // Preserve reminders not in the posted order (e.g. hidden folders).
        foreach ($list as $it) {
            if (!is_section($it) && !isset($used[$it['id']])) { $newReminders[] = $it; }
        }

        save_reminders($dataFile, array_merge($sectionRows, $newReminders));
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }

    // Inline edit of a reminder's text (AJAX, from tapping it in edit mode).
    if ($_POST['action'] === 'edit_text') {
        $id   = (string) ($_POST['id'] ?? '');
        $text = trim((string) ($_POST['text'] ?? ''));
        $list = load_reminders($dataFile);
        // Retyping a row reads the same way as typing a new one: "Vet 8/3 2pm" moves it
        // to Aug 3 at 2pm and leaves "Vet" behind. There is no date field on an inline
        // edit, so there is nothing to override it — but a line with no date in it must
        // leave the date alone rather than clear it, or renaming a dated reminder would
        // quietly undate it.
        [$ptext, $pdate, $ptime] = parse_when_from_text($text);
        $due = null; $time = null;
        if ($text !== '') {
            foreach ($list as &$r) {
                if (is_section($r) || ($r['id'] ?? '') !== $id) { continue; }
                $r['text'] = mb_substr($ptext !== '' ? $ptext : $text, 0, 500);
                if ($pdate !== null) { $r['due']  = $pdate; }
                if ($ptime !== null) { $r['time'] = $ptime; }
                $due = $r['due'] ?? null; $time = $r['time'] ?? null;
                break;
            }
            unset($r);
            save_reminders($dataFile, $list);
        }
        // Answer with what was actually stored, so the row can redraw its date chip
        // instead of the client guessing that nothing moved.
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'text' => $ptext !== '' ? $ptext : $text,
                          'due' => $due, 'time' => $time]);
        exit;
    }

    // Make a reminder a subtask of the one above it (visual only): 0 = task, 1 = subtask.
    // One level only, reminders only — a section is always level 0.
    if ($_POST['action'] === 'set_indent') {
        $id   = (string) ($_POST['id'] ?? '');
        $ind  = max(0, min(1, (int) ($_POST['indent'] ?? 0)));
        $list = load_reminders($dataFile);
        foreach ($list as &$it) {
            if (($it['id'] ?? '') === $id) { $it['indent'] = is_section($it) ? 0 : $ind; break; }
        }
        unset($it);
        save_reminders($dataFile, $list);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'indent' => $ind]);
        exit;
    }

    $list        = load_reminders($dataFile);
    // Every folder keeps at least one real section and every reminder sits in one — the
    // model that replaced the unnamed catch-all. My own file is repaired in place; a
    // partner's structure is theirs, so it's left as-is (they normalise their own).
    if (!$isShared) { $list = sections_normalize($list, $folders); }
    $sectionSet  = [];   // "folder\x1Fname" — sections are per-folder
    $firstSec    = sections_first_by_folder($list);   // folder => its default (first) section
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
            // Every reminder sits in a real section now — a blank or unknown one lands in
            // the folder's first (default) section rather than a nameless catch-all.
            if ($section === '' || !isset($sectionSet[$folder . "\x1F" . $section])) {
                $section = $firstSec[$folder] ?? SECTION_DEFAULT_NAME;
            }

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
                // Reopen this section's add row after the redirect, so you can keep adding.
                $stay = '&addto=' . section_add_id($folder, $section);
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

        case 'add_subtask':
            // A blank subtask directly under its parent, in the parent's folder and
            // section, opened for typing on the way back (?focus=). It's inserted into
            // the *stored* order rather than prepended like an ordinary add, because
            // sort_by_date() carries a block's subtasks along with it — being the row
            // after its parent is what makes it that parent's child.
            $pid = (string) ($_POST['parent'] ?? '');
            foreach ($list as $i => $it) {
                if (is_section($it) || ($it['id'] ?? '') !== $pid) { continue; }
                $new = [
                    'id'      => bin2hex(random_bytes(6)),
                    'text'    => '',
                    'due'     => null,
                    'time'    => null,
                    'done'    => false,
                    'folder'  => $it['folder'] ?? folder_fallback('reminders'),
                    'section' => $it['section'] ?? '',
                    'indent'  => 1,
                    'created' => time(),
                ];
                // Past the parent and past any subtasks it already has, so a second + on
                // the same row adds a sibling at the end rather than jumping the queue.
                $at = $i + 1;
                while ($at < count($list) && !is_section($list[$at])
                       && (int) ($list[$at]['indent'] ?? 0) > 0) { $at++; }
                array_splice($list, $at, 0, [$new]);
                $stay = '&edit=1&focus=' . $new['id'];
                break;
            }
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
// Guarantee the "every folder has a real section, every reminder sits in one" model, then
// write the repair back — but only for my own file. A partner's shared folders are theirs
// to structure, so their list is normalised in memory for the read-only view, never saved.
$all = sections_normalize($all, $folders, $secChanged);
if ($secChanged && !$isShared) { save_reminders($dataFile, $all); }

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
// How many sections each folder has, so a folder's *only* section shows no delete × (its
// last section is undeletable — the guard is enforced server-side, this just hides the ×).
$folderSecCount = [];
foreach ($secRows as $s) { $folderSecCount[$folderOf($s)] = ($folderSecCount[$folderOf($s)] ?? 0) + 1; }

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
// Mine and the partner's shared folders are one interleaved list now, ordered by the
// Manage-folders drag (folders_display_order). The same order drives the picker options,
// the manager rows and the "All" listing below, so all three agree.
$optByKey   = [];   // display key => [val, label, colour, shared?, partner-or-'']
foreach ($myFolders as $f) {
    $optByKey[$f] = [$f, $f, $myColors[$f] ?? app_palette('reminders')[0], false, ''];
}
$sharedKeys = [];
foreach ($sharedFolders as $i => $f) {
    $key = '@' . $partner . ':' . $f;
    $col = folder_shared_color($sharedOverrides, $theirColors, 'reminders', $key, $f, $i);
    $optByKey[$key] = [$key, $f, $col, true, $partner];
    $sharedKeys[]   = $key;
}
$dispOrder  = folders_display_order($cfg['data_dir'], 'reminders', $myFolders, $sharedKeys);
$pickOpts   = [];
$modalRows  = [];
foreach ($dispOrder as $k) {
    if (!isset($optByKey[$k])) { continue; }
    [$val, $label, $col, $sh, $who] = $optByKey[$k];
    $pickOpts[]  = [$val, $label, $col];
    $modalRows[] = ['key' => $val, 'label' => $label, 'color' => $col, 'shared' => $sh, 'partner' => $who];
}
$folderGroups = [['label' => '', 'options' => $pickOpts]];

// The order the "All" listing renders in — mine and the partner's read-only shared folders
// interleaved, following the same display order as the picker. A single-folder view is just
// that one folder. Each unit is ['own', name] or ['shared', folder, index, key].
$renderUnits = [];
if ($viewFolder === 'All' && !$isShared) {
    foreach ($dispOrder as $k) {
        if (strncmp($k, '@', 1) === 0) {
            if (in_array($k, $sharedHidden, true)) { continue; }
            $sf = $optByKey[$k][1];
            $i  = array_search($sf, $sharedFolders, true);
            if ($i !== false) { $renderUnits[] = ['shared', $sf, (int) $i, $k]; }
        } elseif (in_array($k, $viewFolders, true)) {
            $renderUnits[] = ['own', $k];
        }
    }
} else {
    foreach ($viewFolders as $f) { $renderUnits[] = ['own', $f]; }
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

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>Reminders</title>
  <meta name="theme-color" content="<?= e(theme_bg()) ?>">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black">
  <meta name="apple-mobile-web-app-title" content="Reminders">
  <link rel="apple-touch-icon" href="<?= suite_path() ?>/reminders/icon-180.png">
  <link rel="icon" href="<?= suite_path() ?>/reminders/icon-192.png">
  <link rel="manifest" href="<?= suite_path() ?>/reminders/manifest.webmanifest?v=2">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: system-ui, sans-serif; background: var(--bg); color: var(--text);
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
    header .meta { font-size: 0.8rem; color: var(--muted); }
    header .htitle { min-width: 0; }
    header a { color: var(--muted); text-decoration: none; margin-left: 1rem; }
    header a:hover { color: var(--text); }
    header .who {
      color: var(--accent); font-size: 0.8rem; border: 1px solid var(--accent); opacity: 0.75;
      border-radius: 999px; padding: 0.15rem 0.6rem;
    }

    /* Completed sits on the folder-dropdown row, sized to match it. */
    /* One height for everything on this row, whichever of them is showing. */
    .foldernav { padding-left: 0; }   /* line Completed up with the sections' + */
    .foldernav .showall, .foldernav .newsection input, .foldernav .newsection .plus { height: 32px; }
    .foldernav .showall {
      background: none; color: var(--text-dim); border: 1px solid var(--line); border-radius: 999px;
      padding: 0.3rem 0.75rem; font-size: 16px; cursor: pointer; font-family: inherit;
      white-space: nowrap;
    }
    /* Completed is icon-only (a ☑), so it's a 32px circle like the back button —
       unlike "+ Section", which keeps its label and stays a pill. */
    .foldernav #doneBtn, .foldernav #mdShareBtn {
      width: 32px; padding: 0; flex: 0 0 auto;
      display: inline-flex; align-items: center; justify-content: center;
    }
    .foldernav .showall:hover { border-color: var(--muted); color: var(--text-dim); }
    .foldernav #mdShareBtn.copied { border-color: var(--accent); color: var(--accent); }
    body.show-done .foldernav #doneBtn { background: var(--accent); border-color: var(--accent); color: var(--accent-ink); font-weight: 700; }

    /* The + on each section header, and the row it opens. Both draw their plus with
       plus_icon_svg(), so they centre by construction and need no padding nudge. */
    /* Same grey outlined pill as the folder head's "+" (.fsec-add) — it's the same act,
       just adding a row to one section rather than a section to a folder. */
    /* The + that adds a reminder wears the theme (accent) colour. */
    .sec-add {
      flex: 0 0 auto; align-self: center; background: none; border: 1px solid var(--accent); opacity: 0.75;
      color: var(--accent); border-radius: 999px; width: 20px; height: 20px;
      font-size: 0.85rem; line-height: 1; cursor: pointer; font-family: inherit;
      display: inline-flex; align-items: center; justify-content: center; padding: 0;
    }
    .sec-add:hover { opacity: 1; }
    .secadd-row { display: flex; gap: 0.5rem; margin: 0.5rem 0 0.25rem; }
    .secadd-row[hidden] { display: none; }   /* make [hidden] win over flex */
    .secadd-row input[type=text] {
      flex: 1; min-width: 0; padding: 0.45rem 0.75rem; background: var(--surface);
      border: 1px solid var(--line); border-radius: 999px; color: var(--text);
      font-size: 16px;   /* 16px stops iOS from zooming on focus */
    }
    .secadd-row input:focus { outline: none; border-color: var(--accent); }
    .secadd-row .plus {
      flex: 0 0 auto; width: 38px; background: var(--accent); color: var(--accent-ink); border: none;
      border-radius: 999px; font-size: 1.1rem; font-weight: 700; cursor: pointer; font-family: inherit;
      display: inline-flex; align-items: center; justify-content: center; line-height: 1; padding: 0;
    }
    .secadd-row .plus:hover { filter: brightness(1.1); }
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
      background: var(--surface); border: 1px solid var(--line); border-radius: 12px;
      width: 100%; max-width: 380px; padding: 1.25rem;
    }
    .modal h2 { font-size: 1.05rem; margin-bottom: 1rem; }
    .modal input[type=text] {
      width: 100%; padding: 0.6rem 0.75rem; background: var(--surface-2); border: 1px solid var(--line);
      border-radius: 6px; color: var(--text); font-size: 16px; margin-bottom: 0.85rem;
    }
    .modal input:focus, .modal select:focus { outline: none; border-color: var(--muted); }
    .modal .kind { display: flex; gap: 0.5rem; margin-bottom: 1rem; }
    .modal .kind label {
      flex: 1; text-align: center; padding: 0.5rem; border: 1px solid var(--line);
      border-radius: 6px; font-size: 0.9rem; color: var(--text-dim); cursor: pointer; user-select: none;
    }
    .modal .kind input { display: none; }
    .modal .kind input:checked + span { color: var(--accent); font-weight: 700; }
    .modal .kind label:has(input:checked) { border-color: var(--accent); background: var(--accent-soft); }
    .modal .daterow, .modal .secrow { margin-bottom: 1rem; }
    .modal .adddate {
      background: none; border: 1px dashed var(--line); color: var(--accent); border-radius: 6px;
      padding: 0.45rem 0.8rem; font-size: 0.9rem; cursor: pointer; font-family: inherit;
    }
    .modal .adddate:hover { background: var(--accent-soft); }
    .modal .datewrap { display: flex; align-items: center; gap: 0.5rem; }
    .modal .datewrap[hidden] { display: none; }   /* make [hidden] win over flex */
    .modal .datewrap input[type=date] {
      flex: 1; padding: 0.5rem 0.6rem; background: var(--surface-2); border: 1px solid var(--line);
      border-radius: 6px; color: var(--text); font-size: 16px; color-scheme: dark;
    }
    .modal .datewrap .cleardate {
      background: none; border: 1px solid var(--line); color: var(--muted); border-radius: 6px;
      padding: 0.45rem 0.6rem; font-size: 0.9rem; cursor: pointer; line-height: 1; font-family: inherit;
    }
    .modal .datewrap .cleardate:hover { border-color: #f66; color: #f66; }
    .modal .secsel {
      width: 100%; padding: 0.5rem 0.6rem; background: var(--surface-2); border: 1px solid var(--gold); opacity: 0.8;
      border-radius: 6px; color: var(--gold); font-size: 16px; color-scheme: dark; cursor: pointer;
      font-family: inherit;
    }
    .modal .buttons { display: flex; gap: 0.5rem; justify-content: flex-end; }
    .modal .buttons button {
      padding: 0.55rem 1.1rem; border: none; border-radius: 6px; font-size: 0.95rem;
      font-weight: 600; cursor: pointer; font-family: inherit;
    }
    .modal .buttons .cancel { background: var(--surface-2); color: var(--text-dim); }
    .modal .buttons .ok { background: var(--accent); color: var(--accent-ink); }

    /* Folder labels, shown above a run of that folder's sections when "All" mixes more
       than one folder together — bigger and a shade darker than a section header, so the
       two read as different levels of the same hierarchy rather than competing. */
    /* A slightly thicker rule straight across the page above each folder, capping the run
       before it. Full-width because the block is; the folder-head keeps its own short
       trailing rule as the heading's flourish. The block's top margin+padding sit the
       heading just under the divider, so the heading's own top margin comes off. */
    .folder-block { border-top: 2px solid var(--line); margin-top: 1.5rem; padding-top: 0.55rem; }
    .folder-head { display: flex; align-items: center; gap: 0.35rem; margin: 0 0 0 0.25rem; }
    /* The folder name is the top heading, sitting on a rounded, fairly transparent wash
       of the folder's own colour — 8-digit hex, inline, from folder_tint(). That wash is
       what makes it read as the level above the gold section titles under it, and what
       says "this whole run belongs to that folder"; the 11px dot it replaced was a full
       stop you had to go looking for. The text itself is back to near-white *because* of
       the wash: the colour identity is the chip, so the name only has to be legible, and
       near-white is the one value that reads on all six folder colours at once. */
    .folder-label {
      font-weight: 700; font-size: 1.35rem; line-height: 1.2; color: var(--text);
      border-radius: 999px; padding: 0.1rem 0.65rem;
    }
    /* A short rule on the heading's own line, trailing off to the right edge. It rides
       in the header rather than sitting between folders, so the first folder gets one
       too — the gap above each heading is what separates one folder from the next. */
    .folder-rule { display: none; }   /* the full-width rule above each folder replaces this short one */
    /* The "+" that adds a section to this folder — right of its name, always shown so a
       section can be added without first entering edit mode. */
    /* The + that adds a section wears the section-title colour (gold). */
    .fsec-add {
      flex: 0 0 auto; align-self: center; background: none; border: 1px solid var(--gold); opacity: 0.8;
      color: var(--gold); border-radius: 999px; width: 22px; height: 22px; margin-left: 0.15rem;
      font-size: 0.95rem; line-height: 1; cursor: pointer; font-family: inherit;
      display: inline-flex; align-items: center; justify-content: center; padding: 0;
    }
    .fsec-add:hover { opacity: 1; }
    .fsec-form.newsection { margin: 0; }
    /* Inside a folder block, a folder's sections nest slightly to the right of its heading,
       so the wash-backed folder name reads as the level above them. Reminders' two permanent
       groups (Calendar, Reminders) are global, sit outside any block, and so don't move. */
    .folder-block .section-group { padding-left: 0.85rem; }

    /* Section headers (bold), grouping reminders */
    /* Same side padding as a row, so the handle and the X line up with the rows'. */
    .section-head { display: flex; align-items: center; gap: 0.75rem; margin: 1.5rem 0 0.25rem; padding: 0 0.25rem; }
    /* The permanent groups' plain-span title, matching the field version's metrics so
       both sit on the same centre line as the chevron and the "+". */
    .section-title { font-weight: 600; font-size: 1.15rem; color: var(--gold); line-height: 1.2; align-self: center; }
    /* The folder's colour, right of the folder's name — the same dot the picker wears. */
    .fdot { flex: 0 0 auto; width: 11px; height: 11px; border-radius: 50%; }
    /* The section's X lines up with the rows' — pushed to the right edge, same shape. */
    .section-head form { margin-left: auto; }
    .section-del {
      flex: 0 0 auto; align-items: center; justify-content: center; width: 30px; height: 30px; padding: 0;
      background: none; border: 1px solid var(--line); color: var(--text-dim); border-radius: 6px;
      font-size: 0.95rem; line-height: 1; cursor: pointer; font-family: inherit;
    }
    .section-del:hover { border-color: #f66; color: #f66; }
    /* A subtask (indent 1) is padded in one level, so the delete × stays pinned to the
       right edge and every × lines up down the app. Sections never indent. */
    ul.rlist > li { padding-left: calc(0.25rem + var(--ind, 0) * 1.5rem); }
    .section-head { padding-left: 0.25rem; }
    /* The right-hand tail of a section header (just the delete now), pushed to the edge. */
    .section-head .sec-tail { margin-left: auto; display: inline-flex; align-items: center; gap: 0.75rem; }
    .section-head .sec-tail form { margin-left: 0; }
    /* The subtask control: edit mode only, just left of a row's delete ×. A "+" on a task
       (adds a child), a "‹" on a subtask (lifts it back out) — one slot, same box either
       way, so a row never changes width when it gains or loses its indent. */
    .subtask-btn {
      display: none; flex: 0 0 auto; align-items: center; justify-content: center;
      width: 30px; height: 30px; padding: 0; background: none; border: 1px solid var(--line);
      color: var(--muted); border-radius: 6px; font-size: 1.05rem; line-height: 1; cursor: pointer; font-family: inherit;
    }
    .subtask-btn svg { display: block; }
    /* The form is only a wrapper for the POST — it must not take a slot of its own. */
    .subtask-btn-form { display: none; flex: 0 0 auto; }
    body.editing .subtask-btn-form { display: inline-flex; }
    body.editing .subtask-btn { display: inline-flex; }
    .subtask-btn:hover { border-color: var(--muted); color: var(--text-dim); }
    /* The lift-out "‹" reads as the mirror of the section indent controls, so it stays
       the quieter of the two — adding is the common act, undoing is not. */
    .subtask-btn.out { font-size: 1.15rem; }

    /* A partner's shared folder shown read-only in my "All": their rows carry no controls,
       just a static tick where the check would be. The badge marks whose it is. */
    .folder-head .fshared-badge {
      flex: 0 0 auto; font-size: 0.68rem; color: #cbb8ff; background: #2a2440;
      border: 1px solid #3d3559; border-radius: 999px; padding: 0.05rem 0.45rem; margin-left: 0.15rem;
    }
    ul.rlist.ro > li { padding-left: 0.25rem; }
    .ro-row { display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem 0.25rem; border-bottom: 1px solid var(--line-soft); }
    .ro-row.done .text { color: var(--muted); text-decoration: line-through; }
    .ro-mark {
      flex: 0 0 auto; display: inline-flex; align-items: center; justify-content: center;
      width: 30px; height: 30px; border: 1px solid var(--line); border-radius: 6px;
      color: var(--accent); font-size: 0.95rem; line-height: 1;
    }

    ul { list-style: none; }
    li {
      display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem 0.25rem;
      border-bottom: 1px solid var(--line-soft);
    }
    /* No divider under the last row of a section. When completed rows are hidden, the last
       *open* row is the real last one, so drop its divider too — an open row with no open
       row after it. (:has() degrades to just the last-child rule on older browsers.) */
    ul.rlist > li:last-child { border-bottom: none; }
    body:not(.show-done) ul.rlist > li:not(.done):not(:has(~ li:not(.done))) { border-bottom: none; }
    li.done .text { color: var(--muted); text-decoration: line-through; }
    ul.rlist { display: flex; flex-direction: column; }
    li.done { order: 1; }   /* when shown, completed items sink below the open ones */
    .text { flex: 1; font-size: 1rem; word-break: break-word; }
    /* Holding a row or heading to enter edit mode must not paint the text as a selection —
       and the highlight starts during the hold, before body.editing is set, so this stays
       ungated. The edit field (.textedit) opts back in, so you can still select while typing. */
    li, .section-head, .folder-head { -webkit-touch-callout: none; -webkit-user-select: none; user-select: none; }
    .section-head input, .folder-head input { -webkit-user-select: text; user-select: text; }
    body.editing .text { cursor: text; }
    .textedit {
      flex: 1; font-size: 1rem; padding: 0.25rem 0.5rem; background: var(--surface-2); border: 1px solid var(--line);
      border-radius: 4px; color: var(--text); -webkit-user-select: text; user-select: text;
    }
    .textedit:focus { outline: none; border-color: var(--muted); }
    .due {
      font-size: 0.75rem; padding: 0.15rem 0.5rem; border-radius: 999px; white-space: nowrap;
      color: var(--muted); background: var(--surface-2);
    }
    .due.past   { color: var(--k-overdue); background: var(--k-overdue-bg); }   /* gone by */
    .due.today  { color: var(--k-reminder); background: var(--k-reminder-bg); }   /* due today */
    .due.future { color: var(--k-event-soft); background: var(--k-event-bg); }   /* still ahead */
    /* Fixed size + flex-centring, so the glyph (or the empty check) sits dead centre
       rather than being positioned by padding and a couple of &nbsp;s. */
    .check, .del {
      flex: 0 0 auto; display: inline-flex; align-items: center; justify-content: center;
      width: 30px; height: 30px; padding: 0;
      background: none; border: 1px solid var(--line); color: var(--text-dim); cursor: pointer;
      border-radius: 6px; font-size: 0.95rem; line-height: 1;
    }
    .check:hover { border-color: var(--accent); color: var(--accent); }
    .del:hover { border-color: #f66; color: #f66; }

    /* Edit mode: the X buttons + drag handles stay hidden until the pencil is tapped.
       The handles keep their column either way — hiding them with display:none nudged
       every line of text sideways the moment you started editing. */
    .del, .section-del { display: none; }
    body.editing .del, body.editing .section-del { display: inline-flex; }
    .drag-handle, .sec-handle { visibility: hidden; }
    body.editing .drag-handle, body.editing .sec-handle { visibility: visible; }
    .drag-handle, .sec-handle {
      flex: 0 0 auto; display: inline-flex; align-items: center; justify-content: center; width: 1rem;
      color: var(--muted); font-size: 0.9rem; cursor: grab; touch-action: none; user-select: none;
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
    li.dragging { background: var(--surface-2); border-radius: 6px; box-shadow: 0 4px 14px rgba(0,0,0,0.45); opacity: 0.45; }
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
      background: var(--surface-2); border-radius: 6px; box-shadow: 0 4px 14px rgba(0,0,0,0.45);
    }
    .section-group.dragging .section-title { color: var(--accent); }
    body.editing ul.rlist { min-height: 1.4rem; }
    body.editing ul.rlist:empty { border: 1px dashed var(--line); border-radius: 6px; margin: 0.25rem 0; }

    .empty { color: var(--muted); text-align: center; padding: 2rem 0; }
    footer { margin-top: 1.5rem; display: flex; justify-content: flex-end; }
    footer button {
      background: none; border: none; color: var(--muted); font-size: 0.8rem; cursor: pointer;
    }
    footer button:hover { color: #f66; }
<?= folder_nav_styles() ?>
    .newsection { margin: 0; display: flex; gap: 0.4rem; align-items: center; }
    .newsection[hidden] { display: none; }   /* [hidden] has to win over the flex above */
    /* The + matches the input's height: align-self: stretch fills the flex row, whose
       height the (taller, padding-sized) input sets, so the two line up whatever the input. */
    .newsection .plus {
      flex: 0 0 auto; width: 34px; align-self: stretch; display: inline-flex; align-items: center; justify-content: center; padding: 0; background: var(--gold); color: #241a00;
      border: none; border-radius: 999px; font-size: 1.05rem; line-height: 1; font-weight: 700;
      cursor: pointer; font-family: inherit;
    }
    .newsection .plus:hover { filter: brightness(1.1); }
    .newsection input {
      width: 190px; max-width: 100%; padding: 0.3rem 0.75rem; background: var(--surface); border: 1px dashed var(--line);
      border-radius: 999px; color: var(--gold); font-size: 16px; line-height: 1.2;   /* 16px stops iOS zoom on focus */
    }
    .newsection input::placeholder { color: var(--gold); opacity: 0.85; }
    .newsection input:focus { outline: none; border-style: solid; border-color: var(--gold); }
<?= kind_color_css() ?>
<?= share_modal_styles() ?>
<?= tabbar_styles() ?>
<?= chrome_styles() ?>
    /* The Completed/Share toolbar sits the same small gap below its row as the header sits
       above it (header's 0.5rem), so the first folder divider isn't a wide gulf under the
       buttons. Overrides folder_nav_styles' 1.25rem, which is emitted earlier. */
    .foldernav { margin-bottom: 0.5rem; }
    #rlist-root > .folder-block:first-child { margin-top: 0; }
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
                         array_merge($hidFolders, $sharedHidden), $csrf);
      $titleControls = ob_get_clean();
    ?>
    <?= render_user_menu(false, 'editBtn', '', $partner && !$isShared, $titleControls) ?>
  </header>

  <?php // Completed and Section keep the row under the header; the folder picker
        // itself has moved up beside the +. ?>
  <div class="foldernav">
    <?= collapse_all_button() ?>
    <button type="button" id="doneBtn" class="showall" title="Completed" aria-label="Completed">&#9745;&#65038;</button>
    <?php // Copy-as-Markdown is a personal tool — only Sean's account shows it. ?>
    <?php if ($me === 'sean'): ?>
    <button type="button" id="mdShareBtn" class="showall" title="Copy as Markdown" aria-label="Copy as Markdown">
      <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M4 12v7a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-7"/><path d="M12 3v13"/><path d="M8 7l4-4 4 4"/>
      </svg>
    </button>
    <textarea id="shareMdText" hidden><?= e($shareMd) ?></textarea>
    <?php endif; ?>
    <?php // No toolbar "+ Section": a section is added from the "+" beside each folder's
          // name (edit mode), so it's always clear which folder it lands in. ?>
  </div>

  <?php if (!$isShared) {
        // The Default Folder/Section picker's list: each of my folders with its real
        // sections (from the already-normalised $all), plus which one is the current default.
        $modalSecs = [];
        foreach ($myFolders as $mf) {
            $modalSecs[$mf] = [];
            foreach ($all as $it) {
                if (is_section($it) && ($it['folder'] ?? '') === $mf) { $modalSecs[$mf][] = (string) $it['name']; }
            }
        }
        $defSecRaw = folder_default_section_get($cfg['data_dir'], 'reminders');
        $defSec    = in_array($defSecRaw, $modalSecs[$defFolder] ?? [], true)
            ? $defSecRaw : ($modalSecs[$defFolder][0] ?? SECTION_DEFAULT_NAME);
        render_folder_modal($modalRows, $csrf, $view, '', app_palette('reminders'),
                            app_palette('reminders', true), 'reminders', true, $modalSecs, $defFolder, $defSec);
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
    <?php foreach ($renderUnits as $u): ?>
      <?php if ($u[0] === 'shared'):
              // The partner's shared folder, read-only and badged, in its interleaved spot.
              render_shared_folder_ro($cfg['data_dir'], $partner, $u[1], $u[3],
                  folder_shared_color($sharedOverrides, $theirColors, 'reminders', $u[3], $u[1], $u[2]), $today);
              continue;
            endif; ?>
      <?php $sfolder = $u[1]; ?>
      <?php if ($showFolders): ?>
        <div class="folder-block" data-folder="<?= e($sfolder) ?>">
          <div class="folder-head">
            <?= folder_collapse_button() ?>
            <?php // The folder's own colour is the wash behind its name, where it used to
                  // be a dot beside it — the same colour its entry wears in the picker. The
                  // name is renameable in place in edit mode; the permanent Reminders/Calendar
                  // (named by identity) and a partner's shared folder are not. ?>
            <?= folder_title_html($sfolder, $csrf, $view, folder_tint($folderDotColor($sfolder)),
                                  $isShared || folder_is_fixed('reminders', $sfolder)) ?>
            <?php // Edit-mode only: a "+" just right of the folder's name that reveals an
                  // inline section-name field (below), so a folder with no sections of its
                  // own — like the permanent Reminders/Calendar — can get its first. ?>
            <?php if (!$isShared): ?>
              <button type="button" class="fsec-add" data-folder="<?= e($sfolder) ?>" title="Add section"
                      aria-label="Add section"><?= plus_icon_svg(13) ?></button>
              <form method="post" action="" class="fsec-form newsection" hidden
                    onsubmit="return this.name.value.trim()!==''">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="add_section">
                <input type="hidden" name="view" value="<?= e($view) ?>">
                <input type="hidden" name="folder" value="<?= e($sfolder) ?>">
                <input type="text" name="name" placeholder="+ Section" maxlength="40" autocomplete="off">
                <button type="submit" class="plus" title="Add section" aria-label="Add section"><?= plus_icon_svg(16, 3) ?></button>
              </form>
            <?php endif; ?>
            <?php // A short rule trailing off to the right edge on the same line. ?>
            <span class="folder-rule" aria-hidden="true"></span>
          </div>
      <?php endif; ?>
      <?php // Sections always render, empty or not. Each belongs to one folder; its
            // rename and delete forms carry that folder, so acting on it never touches
            // another folder's same-named section. ?>
      <?php // Each block is buffered rather than echoed, so the catch-all can be put back
            // in at whatever position it was last dragged to instead of always trailing. ?>
      <?php $blocks = []; ?>
      <?php foreach ($secRows as $s): ?>
        <?php if ($folderOf($s) !== $sfolder) { continue; } ?>
        <?php $sname = (string) $s['name']; ob_start(); ?>
        <div class="section-group" data-section="<?= e($sname) ?>" data-folder="<?= e($sfolder) ?>"
             data-id="<?= e($s['id']) ?>" style="--ind:0">
          <div class="section-head">
            <?= section_collapse_button() ?>
            <span class="sec-handle" title="Drag section" aria-hidden="true">&#9776;</span>
            <?= section_title_html($sname, $csrf, $view, false, 'rename_section',
                  '<input type="hidden" name="folder" value="' . e($sfolder) . '">') ?>
            <?php render_section_add_button($sname, $sfolder); ?>
            <span class="sec-tail">
              <?php // No × on a folder's only section — its last section can't be deleted. ?>
              <?php if (($folderSecCount[$sfolder] ?? 0) > 1): ?>
              <form method="post" action="" style="display:inline">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="delete_section">
                <input type="hidden" name="view" value="<?= e($view) ?>">
                <input type="hidden" name="folder" value="<?= e($sfolder) ?>">
                <input type="hidden" name="name" value="<?= e($sname) ?>">
                <button class="section-del needs-confirm" type="submit" title="Delete section">&times;</button>
              </form>
              <?php endif; ?>
            </span>
          </div>
          <?php render_section_add_row($sname, $csrf, $view, $sfolder); ?>
          <?php render_rows($grouped[$s['id']] ?? [], $csrf, $view, $today, $sname); ?>
        </div>
        <?php $blocks[] = ob_get_clean(); ?>
      <?php endforeach; ?>
      <?php
        // The folder's catch-all. It drags like any other section — it just can't store
        // its place in the reminders list, not being a row there, so its index lives in
        // folders-<user>.json. It's deletable only when the
        // folder has another section (its loose items move there), and once it has no
        // loose items *and* there are other sections it stops rendering — so a fully
        // sectioned folder isn't headed by an empty "Reminders". A folder with no
        // sections always keeps it, so there's always a "+" to add against.
        $folderSecs = count($blocks);
        $hasCatch   = !empty($looseByFolder[$sfolder]) || $folderSecs === 0;
        if ($hasCatch): ob_start();
      ?>
      <div class="section-group default-group" data-section="" data-folder="<?= e($sfolder) ?>">
        <div class="section-head">
          <?= section_collapse_button() ?>
          <span class="sec-handle" title="Drag section" aria-hidden="true">&#9776;</span>
          <span class="section-title"><?= DEFAULT_SECTION ?></span>
          <?php render_section_add_button('', $sfolder); ?>
          <?php if ($folderSecs > 0 && !$isShared): ?>
            <span class="sec-tail">
              <form method="post" action="" style="display:inline">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="delete_section">
                <input type="hidden" name="view" value="<?= e($view) ?>">
                <input type="hidden" name="folder" value="<?= e($sfolder) ?>">
                <input type="hidden" name="name" value="">
                <button class="section-del needs-confirm" type="submit" title="Delete section">&times;</button>
              </form>
            </span>
          <?php endif; ?>
        </div>
        <?php render_section_add_row('', $csrf, $view, $sfolder); ?>
        <?php render_rows($looseByFolder[$sfolder] ?? [], $csrf, $view, $today, ''); ?>
      </div>
      <?php $blocks[] = ''; $catch = ob_get_clean(); endif; ?>
      <?php
        // Where the catch-all goes. A partner's folder is theirs, so it just trails there.
        $at = ($hasCatch && !$isShared)
            ? folder_catchall_index($cfg['data_dir'], 'reminders', $sfolder, $folderSecs)
            : $folderSecs;
        if ($hasCatch) { array_pop($blocks); }    // drop the placeholder pushed above (only then)
        foreach ($blocks as $bi => $bhtml) {
            if ($hasCatch && $bi === $at) { echo $catch; }
            echo $bhtml;
        }
        if ($hasCatch && $at >= $folderSecs) { echo $catch; }
      ?>
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
  // Tapping or clicking away from an open add field closes it — the same as Escape — so you
  // exit "adding a reminder" without having to finish or reach for the +. A click inside the
  // field itself, or on a + that opens one, is left alone.
  document.addEventListener('click', e => {
    if (e.target.closest('.secadd-row') || e.target.closest('.sec-add')) return;
    document.querySelectorAll('.secadd-row:not([hidden])').forEach(r => { r.hidden = true; });
  });
  // After adding a reminder we land back with ?addto=<row id>: reopen that section's field
  // and focus it, so you can add another without reaching for the "+" again.
  (function () {
    const q = new URLSearchParams(location.search), id = q.get('addto');
    if (!id) return;
    const row = document.getElementById(id);
    if (row) { row.hidden = false; const i = row.querySelector('input[type=text]'); if (i) { i.focus(); } }
    const u = new URL(location.href); u.searchParams.delete('addto'); history.replaceState(null, '', u);
  })();

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
    // Row order: id + section + folder — a drag can re-file a row into another of my
    // folders, so each posts the folder of the block it now sits in. A partner's shared
    // block is read-only and never posted.
    const order = [];
    document.querySelectorAll('ul.rlist').forEach(ul => {
      const block = ul.closest('.folder-block');
      if (block && block.classList.contains('shared-block')) return;
      const section = ul.dataset.section || '';
      const folder = block ? (block.dataset.folder || '') : '';
      ul.querySelectorAll(':scope > li[data-id]').forEach(li => order.push({ id: li.dataset.id, section, folder }));
    });
    // Section order per folder, by section id — a section can move to another folder, so
    // its id is what re-files it (names collide across folders). The catch-all has no id;
    // it rides as '' so its slot moves too. Same shape as Notes.
    const sections = {};
    document.querySelectorAll('.folder-block:not(.shared-block)').forEach(blk => {
      const ids = [];
      blk.querySelectorAll(':scope > .section-group').forEach(g => ids.push(g.dataset.id || ''));
      sections[blk.dataset.folder || ''] = ids;
    });
    order.forEach((o, i) => {                          // keep the drag order stable
      const li = document.querySelector('li[data-id="' + o.id + '"]');
      if (li) li.dataset.pos = i;
    });
    const body = new URLSearchParams({ csrf: CSRF, action: 'reorder', view: VIEW,
      order: JSON.stringify(order), sections: JSON.stringify(sections) });
    return fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body }).catch(() => location.reload());
  };

  // Folders that can't be deleted (the permanent Calendar) — moving their last section
  // out never offers to delete them; the folder just regrows a default section.
  const FIXED_FOLDERS = <?= json_encode(folders_fixed('reminders')) ?>;
  // Deleting the folder a drag just emptied, after the reorder has landed — chained so
  // the two writes can't race each other over the same file.
  const deleteEmptiedFolder = (name) => {
    const body = new URLSearchParams({ csrf: CSRF, action: 'delete_folder', view: 'All', name, confirm: '1' });
    return fetch('', { method: 'POST', body });
  };

  let pressTimer = null, pid = null, sx = 0, sy = 0, tapTextLi = null, suppressClick = false;
  const beginItem = (li) => {
    dragLi = li; li.classList.add('dragging');
    try { li.setPointerCapture(pid); } catch (_) {}
    if (navigator.vibrate) navigator.vibrate(12);
  };
  const cancelPress = () => { if (pressTimer) { clearTimeout(pressTimer); pressTimer = null; } tapTextLi = null; };

  // isNew marks a row that was created empty (the subtask "+"): leaving it empty means
  // you changed your mind, so it deletes itself rather than leaving a blank reminder.
  function startInlineEdit(li, isNew) {
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
      if (isNew && !val) {
        li.remove();
        const gone = new URLSearchParams({ csrf: CSRF, action: 'delete', view: VIEW, id, confirm: '1' });
        fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: gone })
          .catch(() => location.reload());
        return;
      }
      const ns = document.createElement('span'); ns.className = 'text';
      ns.textContent = (save && val) ? val : cur;
      inp.replaceWith(ns);
      if (save && val && val !== cur) {
        const body = new URLSearchParams({ csrf: CSRF, action: 'edit_text', view: VIEW, id, text: val });
        fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body })
          .then(r => r.json())
          .then(s => {
            if (!s || !s.ok) { return; }
            // The server reads a date out of what was typed, so the text it stored may be
            // shorter than what was sent — show that, not the raw line.
            if (s.text) { ns.textContent = s.text; }
            // And if the row has moved to a different date it belongs somewhere else in
            // the list, with a different chip; let the page redraw rather than lie.
            if ((s.due || '') !== (li.dataset.due || '')) { location.reload(); }
          })
          .catch(() => location.reload());
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
      if (e.target.closest('.check, .del, .subtask-btn, form, input')) return;
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
    if (under.closest('.shared-block')) { clearLine(); return; }   // never drop into a partner's folder
    const y = e.clientY;

    if (dragSection && isTop(dragSection)) {
      // ----- Level 0 -----
      // A whole section, everything under it travelling with it. It can only land
      // between other level-0 blocks, never inside one, so its subsections and rows
      // always stay grouped together underneath it. Another folder's blocks count too:
      // dropping there re-files the section (and its rows) into that folder.
      const g = under.closest('.section-group');
      if (!g) {
        // The gap around a folder's own heading: land at the top of that folder's run,
        // so an empty(ish) folder is still a target.
        const blk = under.closest('.folder-block');
        const head = blk && blk.querySelector(':scope > .folder-head');
        if (head) lineBefore(head, true, 'div');
        return;
      }
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
    // Dragging a folder's last section into another folder leaves it empty. Ask: OK
    // moves the section and deletes the emptied folder with it; Cancel reverts the
    // whole drop. The permanent Calendar is never offered — it just regrows a default.
    let emptied = null, cancelled = false;
    if (dragSection && isTop(dragSection) && dropLine) {
      const src = dragSection.closest('.folder-block');
      const dst = dropLine.parentNode && dropLine.parentNode.closest('.folder-block');
      if (src && dst && src !== dst) {
        const moving = dragBlock || [dragSection];
        const left = [...src.querySelectorAll(':scope > .section-group')].filter(g => !moving.includes(g));
        if (left.length === 0) {
          const name = src.dataset.folder || '';
          if (!FIXED_FOLDERS.includes(name)) {
            if (confirm('Move the last section out of “' + name + '” and delete the empty folder?')) emptied = name;
            else cancelled = true;
          }
        }
      }
    }
    if (cancelled) { clearLine(); } else { applyDrop(); }
    if (dragLi) dragLi.classList.remove('dragging');
    if (dragSection) { dragSection.classList.remove('dragging'); (dragBlock || []).forEach(m => m.classList.remove('dragging')); }
    dragBlock = null;
    dragLi = null; dragSection = null;
    if (wasDrag) {
      suppressClick = true; setTimeout(() => { suppressClick = false; }, 350);
      if (cancelled) return;
      if (emptied) { persistOrder().then(() => deleteEmptiedFolder(emptied)).then(() => location.reload()).catch(() => location.reload()); }
      else { persistOrder(); }
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
    const li = target.closest('li[data-id]'), head = target.closest('.section-head, .folder-head');
    if (!li && !head) return;
    if (!editing()) setEdit(true);
    if (li) startInlineEdit(li);
    // A section head's gesture opens its name, the way a row's opens its text. Without
    // this you had to tap the name again afterwards, and that second tap falls inside the
    // window where the long-press's own click is still being swallowed.
    const f = head && head.querySelector('.sectitle');
    if (f) { setTimeout(() => { f.focus(); try { f.select(); } catch (_) {} }, 0); }
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
    if (!e.target.closest('li[data-id], .section-head, .folder-head')) return;
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
        + ' .folder-head, .foldernav, button, a, input, textarea, select,'
        + ' .modal-backdrop, .setmodal-backdrop, .sh-modal, .tabbar')) return;
    setEdit(false);
  });
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape' || !editing()) return;
    if (document.querySelector('.textedit') || document.querySelector('.secadd-row:not([hidden])')) return;
    setEdit(false);
  });

  // ----- Lift a subtask back out to being a task of its own (edit mode) -----
  // Adding one goes the other way, through an ordinary POST on the row's "+" form, since
  // the new row has to come back from the server before there's anything to type into.
  document.addEventListener('click', (e) => {
    const b = e.target.closest('.subtask-btn.out'); if (!b) return;
    e.preventDefault(); e.stopPropagation();
    const host = b.closest('li[data-id]'); if (!host) return;
    host.style.setProperty('--ind', 0);
    const body = new URLSearchParams({ csrf: CSRF, action: 'set_indent', view: VIEW, id: host.dataset.id, indent: 0 });
    fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body })
      .then(() => location.reload()).catch(() => location.reload());
  });

  // A new subtask comes back as ?focus=<id>: find that row and open it to type in, so
  // pressing + reads as "a new line appeared here" rather than "the page reloaded".
  (function () {
    const q = new URLSearchParams(location.search), fid = q.get('focus');
    if (!fid) { return; }
    const u = new URL(location.href); u.searchParams.delete('focus'); history.replaceState(null, '', u);
    const li = document.querySelector('li[data-id="' + fid + '"]');
    if (!li) { return; }
    li.scrollIntoView({ block: 'center', behavior: 'auto' });
    startInlineEdit(li, true);
  })();
</script>
<?= folder_modal_script() ?>
<?= chrome_script() ?>
<?php if ($partner && !$isShared) { echo share_modal_script($csrf); } ?>
</body>
</html>
