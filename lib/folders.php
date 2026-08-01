<?php
/**
 * Per-user folders for reminders and notes.
 * Stored in data/folders-<user>.json as { "reminders": [...], "notes": [...] }, beside
 * `default`, `last`, `hidden` and `colors` — all keyed by type.
 * Reminders always has "Reminders" and "Calendar"; Notes always has "General".
 */

require_once __DIR__ . '/palette.php';

const FOLDER_DEFAULT = 'General';

/**
 * Folders that always exist and can't be deleted, always first in the list.
 * Reminders has two — "Reminders" (the catch-all) and "Calendar" (undated items there
 * ride along on today); see lib/util.php. Notes keeps the one "General".
 * "General" is still accepted in a reminders list on the way in and migrated away.
 */
function folders_fixed(string $type): array
{
    return $type === 'reminders' ? [FOLDER_REMINDERS, FOLDER_CALENDAR] : [FOLDER_DEFAULT];
}

/** The folder a type falls back to when nothing else is named. */
function folder_fallback(string $type): string
{
    return folders_fixed($type)[0];
}

function folder_is_fixed(string $type, string $name): bool
{
    return in_array($name, folders_fixed($type), true);
}

// A folder's colours come from the per-app palette (app_palette('reminders'|'notes')),
// so reminder and note folders sit at their own shades. See lib/palette.php.

function folders_load(string $dir, ?string $user = null): array
{
    $file = user_data_file($dir, 'folders', $user);
    $data = store_read($file);
    foreach (['reminders', 'notes'] as $type) {
        $fixed = folders_fixed($type);
        $have  = (is_array($data[$type] ?? null)) ? $data[$type] : [];
        // A reminders list from before the permanent folders existed carries "General";
        // its items are re-pointed at "Reminders" by reminders_folder_migrate(), so the
        // name itself just goes.
        if ($type === 'reminders') {
            $have = array_filter($have, fn($f) => $f !== FOLDER_DEFAULT);
        }
        // Keep the stored order — the permanent folders can be dragged too, so they're
        // no longer forced to the front. Any fixed folder that isn't in the list yet
        // (a fresh account) is added at the front, its default spot.
        $have = array_values(array_unique(array_filter($have, fn($f) => is_string($f) && $f !== '')));
        foreach (array_reverse($fixed) as $ff) {
            if (!in_array($ff, $have, true)) { array_unshift($have, $ff); }
        }
        $data[$type] = $have;
    }
    return $data;
}

/**
 * Which folders are switched off in the picker. Hiding is per type and per user, kept
 * beside the list; "All" then means "every folder I haven't hidden". Re-validated on
 * read, so a hidden folder that's since been deleted quietly stops counting.
 */
function folders_hidden(string $dir, string $type, ?string $user = null): array
{
    $data = folders_load($dir, $user);
    $hid  = is_array($data['hidden'][$type] ?? null) ? $data['hidden'][$type] : [];
    return array_values(array_intersect($hid, $data[$type] ?? []));
}

/**
 * Which of the partner's shared folders I've switched off in my own view. Kept in my
 * file under `hidden_shared`, keyed by the full "@partner:Folder" view key so it never
 * touches the owner's data — it's purely which of theirs I want in my "All".
 */
function folders_shared_hidden(string $dir, string $type, ?string $user = null): array
{
    $data = folders_load($dir, $user);
    $h    = is_array($data['hidden_shared'][$type] ?? null) ? $data['hidden_shared'][$type] : [];
    return array_values(array_filter($h, 'is_string'));
}

/** Show or hide one of the partner's shared folders in my own "All", keyed by its
 *  "@partner:Folder" view key. This is a view preference of mine, not an edit of theirs. */
function folder_shared_hidden_set(string $dir, string $type, string $key, bool $hidden): void
{
    if (!in_array($type, ['reminders', 'notes'], true)) { return; }
    $data = folders_load($dir);
    $cur  = is_array($data['hidden_shared'][$type] ?? null) ? $data['hidden_shared'][$type] : [];
    $cur  = array_values(array_filter($cur, fn($k) => $k !== $key));
    if ($hidden) { $cur[] = $key; }
    $data['hidden_shared'][$type] = $cur;
    folders_save($dir, $data);
}

/** Show or hide one folder in the picker. A fixed folder can be hidden like any other. */
function folder_hidden_set(string $dir, string $type, string $name, bool $hidden): void
{
    if (!in_array($type, ['reminders', 'notes'], true)) { return; }
    $data = folders_load($dir);
    if (!in_array($name, $data[$type], true)) { return; }
    $cur = is_array($data['hidden'][$type] ?? null) ? $data['hidden'][$type] : [];
    $cur = array_values(array_filter($cur, fn($f) => $f !== $name));
    if ($hidden) { $cur[] = $name; }
    $data['hidden'][$type] = $cur;
    folders_save($dir, $data);
}

/** Show every folder (hidden = []) or hide every folder, from the picker's "All" master
 *  checkbox. Only the viewer's own folders can be hidden, which is all this list holds. */
function folders_set_all_hidden(string $dir, string $type, bool $hidden): void
{
    if (!in_array($type, ['reminders', 'notes'], true)) { return; }
    $data = folders_load($dir);
    $data['hidden'][$type] = $hidden ? array_values($data[$type] ?? []) : [];
    folders_save($dir, $data);
}

/**
 * A folder's colour at low opacity, for the rounded highlight behind its heading in the
 * Reminders and Notes lists. Eight-digit hex rather than color-mix(), which is newer
 * than some of the phones these run on. Anything that isn't a plain #rrggbb (a stored
 * value that has drifted, a conic-gradient) gets no tint rather than a broken one.
 */
function folder_tint(string $hex, string $alpha = '33'): string
{
    return preg_match('/^#[0-9a-fA-F]{6}$/', $hex) ? $hex . $alpha : 'transparent';
}

/**
 * Where a folder's catch-all group sits among that folder's sections. The catch-all is
 * not a stored row — it is drawn for every folder — so it cannot carry its place in the
 * reminders list the way a real section does. It is kept here as an index instead, and
 * clamped on read, so deleting a section can never strand it past the end.
 */
function folder_catchall_index(string $dir, string $type, string $folder, int $count, ?string $user = null): int
{
    $v = folders_load($dir, $user)['catchall'][$type][$folder] ?? null;
    if (!is_int($v) && !(is_string($v) && ctype_digit($v))) { return $count; }   // default: last
    return max(0, min($count, (int) $v));
}

function folder_catchall_index_set(string $dir, string $type, string $folder, int $i): void
{
    if (!in_array($type, ['reminders', 'notes'], true) || $folder === '') { return; }
    $data = folders_load($dir);
    $data['catchall'][$type][$folder] = max(0, $i);
    folders_save($dir, $data);
}

/** The picker posts the keys it drew as one \x1F-joined field; unpack it. */
function folder_pick_keys(string $raw): array
{
    return array_values(array_filter(explode("\x1F", $raw), fn($s) => $s !== ''));
}

/**
 * Set exactly which of the picker's rows are showing: everything in $keys is hidden
 * except what's in $show. The picker hands over the keys it drew, so my own folders and
 * the partner's "@partner:Folder" ones are written in one go — the "All" master used to
 * touch only my own, which left it reading "off" with nothing left to switch on, and a
 * shared folder switched off by hand could never be brought back by it.
 *
 * My own names are checked against the folders I actually have; a shared key is taken as
 * given (as the per-row box always has), since hiding is only ever a view preference of
 * mine and a stale key hides nothing.
 */
function folders_set_visible(string $dir, string $type, array $keys, array $show): void
{
    if (!in_array($type, ['reminders', 'notes'], true)) { return; }
    $data = folders_load($dir);
    $mine = array_values($data[$type] ?? []);
    $own  = $shared = [];
    foreach ($keys as $k) {
        if (!is_string($k) || $k === '') { continue; }
        if (strncmp($k, '@', 1) === 0)      { $shared[] = $k; }
        elseif (in_array($k, $mine, true))  { $own[] = $k; }
    }
    $data['hidden'][$type]        = array_values(array_diff(array_unique($own), $show));
    $data['hidden_shared'][$type] = array_values(array_diff(array_unique($shared), $show));
    folders_save($dir, $data);
}

/**
 * Persist a new order for a type's folders, from the Manage-folders drag. The order can
 * now interleave my own folders with the partner's shared ones ("@partner:Folder" keys),
 * so it's kept as a single display list under `order[$type]`. My own folders' relative
 * order is mirrored into `$type` too, since some readers (colour-by-position) still want
 * an own-only list. Names that are neither a real folder nor an "@"-key are dropped, and
 * any own folder the request left out keeps its place at the end so nothing can vanish.
 */
function folders_reorder(string $dir, string $type, array $order): void
{
    if (!in_array($type, ['reminders', 'notes'], true)) { return; }
    $data = folders_load($dir);
    $own  = array_values($data[$type] ?? []);
    $keys = [];
    foreach ($order as $k) {
        $ok = in_array($k, $own, true) || strncmp($k, '@', 1) === 0;
        if ($ok && !in_array($k, $keys, true)) { $keys[] = $k; }
    }
    // Own folders left out of the request keep their place at the end.
    foreach ($own as $f) { if (!in_array($f, $keys, true)) { $keys[] = $f; } }
    $data['order'][$type] = $keys;
    // Mirror the own-only order (colours default by position, so it wants to be stable).
    $ownOrder = [];
    foreach ($keys as $k) { if (in_array($k, $own, true)) { $ownOrder[] = $k; } }
    foreach ($own as $f) { if (!in_array($f, $ownOrder, true)) { $ownOrder[] = $f; } }
    $data[$type] = $ownOrder;
    folders_save($dir, $data);
}

/**
 * The order folders show in — my own and the partner's shared ones interleaved, as set by
 * the Manage-folders drag. Stored keys are re-validated on read (an own folder that's gone,
 * or a shared key no longer shared, drops out); anything the stored order doesn't mention
 * is appended — my own folders first, then the shared keys — so a brand-new folder or a
 * freshly shared one still appears. `$sharedKeys` are full "@partner:Folder" strings.
 */
function folders_display_order(string $dir, string $type, array $ownFolders, array $sharedKeys): array
{
    $data   = folders_load($dir);
    $stored = is_array($data['order'][$type] ?? null) ? $data['order'][$type] : [];
    $valid  = array_merge($ownFolders, $sharedKeys);
    $out    = [];
    foreach ($stored as $k) {
        if (in_array($k, $valid, true) && !in_array($k, $out, true)) { $out[] = $k; }
    }
    foreach ($ownFolders as $f) { if (!in_array($f, $out, true)) { $out[] = $f; } }
    foreach ($sharedKeys as $k) { if (!in_array($k, $out, true)) { $out[] = $k; } }
    return $out;
}

/**
 * Every folder's colour, keyed by name. A folder that hasn't been given one takes
 * the next palette colour by position, so a new folder is distinct straight away.
 */
function folder_colors(string $dir, string $type, ?string $user = null): array
{
    $data  = folders_load($dir, $user);
    $set   = is_array($data['colors'][$type] ?? null) ? $data['colors'][$type] : [];
    $pal   = app_palette($type);
    $out   = [];
    foreach (array_values($data[$type] ?? []) as $i => $name) {
        $c = (string) ($set[$name] ?? '');
        // A stored colour from this app's palette (own or shared) is kept; otherwise a
        // default is handed out by position so a new folder is distinct straight away.
        $out[$name] = palette_has($type, $c) ? $c : $pal[$i % count($pal)];
    }
    return $out;
}

function folder_color_set(string $dir, string $type, string $name, string $color): void
{
    if (!in_array($type, ['reminders', 'notes'], true) || !palette_has($type, $color)) {
        return;
    }
    $data = folders_load($dir);
    if (!in_array($name, $data[$type], true)) { return; }
    $data['colors'][$type][$name] = $color;
    folders_save($dir, $data);
}

/**
 * A view key names a partner's shared folder as "@<partner>:<Folder>". These are how
 * shared folders are addressed in the picker, and how the viewer's own colour overrides
 * for them are keyed. Returns [partner, folder] or null if it isn't one.
 */
function folder_shared_key_parse(string $key): ?array
{
    return preg_match('/^@([A-Za-z0-9_-]+):(.*)$/s', $key, $m) ? [$m[1], $m[2]] : null;
}

/**
 * The viewer's own colour overrides for a partner's shared folders, keyed by the full
 * "@<partner>:<Folder>" view key. Stored in the viewer's folders file under
 * colors.shared[$type], so recolouring someone else's folder never touches their data.
 */
function folder_shared_colors(string $dir, string $type, ?string $user = null): array
{
    $data = folders_load($dir, $user);
    $set  = $data['colors']['shared'][$type] ?? null;
    return is_array($set) ? $set : [];
}

/**
 * Resolve a shared folder's colour for the viewer: their own override first, then the
 * owner's own colour for it, then the lighter shared-palette default by position. The
 * override wins so each person can recolour the other's folders without conflict.
 */
function folder_shared_color(array $overrides, array $ownerColors, string $type,
                             string $key, string $folder, int $pos): string
{
    if (isset($overrides[$key]) && palette_has($type, (string) $overrides[$key])) {
        return (string) $overrides[$key];
    }
    if (isset($ownerColors[$folder])) {
        return (string) $ownerColors[$folder];
    }
    $pal = app_palette($type, true);
    return $pal[$pos % count($pal)];
}

/**
 * Store the viewer's colour override for a partner's shared folder. The colour must be a
 * lighter shared-palette variant, and the key must name a real shared folder — validated
 * by the caller passing the list of shared folders currently visible.
 */
function folder_shared_color_set(string $dir, string $type, string $key, string $color,
                                 array $sharedFolders): void
{
    if (!in_array($type, ['reminders', 'notes'], true)
        || !in_array($color, app_palette($type, true), true)) {
        return;
    }
    $parsed = folder_shared_key_parse($key);
    if (!$parsed || !in_array($parsed[1], $sharedFolders, true)) { return; }
    $data = folders_load($dir);
    $data['colors']['shared'][$type][$key] = $color;
    folders_save($dir, $data);
}

function folders_save(string $dir, array $data): void
{
    $file = user_data_file($dir, 'folders');
    store_write($file, $data);
}

/** Clean a user-supplied folder name; returns '' if invalid. */
function folder_clean(string $name): string
{
    // Control characters go first, \x1F above all: the Calendar's add window packs
    // "folder\x1Fgroup" into one <option> value and splits on it, so a folder whose name
    // contained one would tear that apart and land the item somewhere else. The rest are
    // dropped for the same reason a newline in a folder name helps nobody.
    // Whitespace first, so a tab or a newline collapses to a space rather than closing
    // the gap between two words; then anything else control-ish simply goes.
    $name = preg_replace('/\s+/u', ' ', $name);
    $name = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $name));
    return mb_substr($name, 0, 40);
}

function folders_add(string $dir, string $type, string $name): void
{
    $name = folder_clean($name);
    if ($name === '' || !in_array($type, ['reminders', 'notes'], true)) {
        return;
    }
    $data = folders_load($dir);
    if (!in_array($name, $data[$type], true)) {
        $data[$type][] = $name;
        folders_save($dir, $data);
    }
}

/**
 * Which folder new items land in while you're viewing "All". Chosen in the folder
 * window and kept alongside the folder list. Falls back to General, including when
 * the chosen folder has since been deleted.
 */
function folder_default_get(string $dir, string $type, ?string $user = null): string
{
    $data = folders_load($dir, $user);
    $name = (string) ($data['default'][$type] ?? '');
    return in_array($name, $data[$type] ?? [], true) ? $name : folder_fallback($type);
}

function folder_default_set(string $dir, string $type, string $name): void
{
    if (!in_array($type, ['reminders', 'notes'], true)) {
        return;
    }
    $data = folders_load($dir);
    if (!in_array($name, $data[$type], true)) {
        return;
    }
    $data['default'][$type] = $name;
    folders_save($dir, $data);
}

/**
 * The section within the default folder that new items land in when none is chosen — the
 * other half of "Default Folder/Section". Sections live in the items file, not here, so the
 * name is stored raw and the caller (which has the section list) validates it, falling back
 * to the folder's first section. Empty means "the folder's first section".
 */
function folder_default_section_get(string $dir, string $type, ?string $user = null): string
{
    return (string) (folders_load($dir, $user)['default_section'][$type] ?? '');
}

/** Set the default folder *and* its section together, from the Manage-folders picker. The
 *  folder is validated here; the section is validated by the caller against real sections. */
function folder_default_section_set(string $dir, string $type, string $folder, string $section): void
{
    if (!in_array($type, ['reminders', 'notes'], true)) { return; }
    $data = folders_load($dir);
    if (!in_array($folder, $data[$type] ?? [], true)) { return; }
    $data['default'][$type]         = $folder;
    $data['default_section'][$type] = $section;
    folders_save($dir, $data);
}

/**
 * The folder view you were last on, so the app opens where you left it.
 * Stored raw (it may be a "@partner:Folder" shared view) and re-validated by the
 * caller, which is the only place that knows what's still legal to show.
 */
function folder_last_get(string $dir, string $type, ?string $user = null): string
{
    return (string) (folders_load($dir, $user)['last'][$type] ?? 'All');
}

function folder_last_set(string $dir, string $type, string $view): void
{
    if (!in_array($type, ['reminders', 'notes'], true)) {
        return;
    }
    $data = folders_load($dir);
    if ((string) ($data['last'][$type] ?? '') === $view) {
        return;   // nothing changed, don't rewrite the file on every page view
    }
    $data['last'][$type] = $view;
    folders_save($dir, $data);
}

/**
 * Rename a folder, carrying every reference to it in this file across to the new name:
 * the list itself (keeping its place), its colour, its hidden flag, the drag order, the
 * catch-all slot, and the default/last-view prefs. A permanent folder can't be renamed —
 * its name *is* its identity, since folders_fixed()/folder_fallback() name it literally —
 * and an empty, unchanged or already-taken name is refused. Returns whether the rename
 * happened, so a caller doesn't re-point its own data file (where the items name the
 * folder) on a no-op. The items live in the app's data file, so re-pointing those is the
 * caller's job — this only touches the folders file.
 */
function folders_rename(string $dir, string $type, string $old, string $new): bool
{
    $new = folder_clean($new);
    if (!in_array($type, ['reminders', 'notes'], true) || $new === '' || $old === $new
        || folder_is_fixed($type, $old) || folder_is_fixed($type, $new)) {
        return false;
    }
    $data = folders_load($dir);
    $list = $data[$type] ?? [];
    if (!in_array($old, $list, true) || in_array($new, $list, true)) {
        return false;   // old must exist, new must not (matching how they're stored)
    }
    $swap = fn($f) => $f === $old ? $new : $f;
    $data[$type] = array_map($swap, $list);
    // Colour, hidden flag, order and catch-all are all keyed by name — move each across.
    if (isset($data['colors'][$type][$old])) {
        $data['colors'][$type][$new] = $data['colors'][$type][$old];
        unset($data['colors'][$type][$old]);
    }
    if (is_array($data['hidden'][$type] ?? null)) {
        $data['hidden'][$type] = array_map($swap, $data['hidden'][$type]);
    }
    if (is_array($data['order'][$type] ?? null)) {
        $data['order'][$type] = array_map($swap, $data['order'][$type]);
    }
    if (isset($data['catchall'][$type][$old])) {
        $data['catchall'][$type][$new] = $data['catchall'][$type][$old];
        unset($data['catchall'][$type][$old]);
    }
    if ((string) ($data['default'][$type] ?? '') === $old) { $data['default'][$type] = $new; }
    if ((string) ($data['last'][$type] ?? '') === $old)    { $data['last'][$type] = $new; }
    folders_save($dir, $data);
    return true;
}

function folders_delete(string $dir, string $type, string $name): void
{
    if (!in_array($type, ['reminders', 'notes'], true) || folder_is_fixed($type, $name)) {
        return;   // the permanent folders are never deleted
    }
    $data = folders_load($dir);
    $data[$type] = array_values(array_filter($data[$type], fn($f) => $f !== $name));
    if (is_array($data['hidden'][$type] ?? null)) {
        $data['hidden'][$type] = array_values(array_filter($data['hidden'][$type], fn($f) => $f !== $name));
    }
    folders_save($dir, $data);
}

/**
 * The folder picker: a round button wearing the selected folder's colour, right of
 * the app's "+", opening a menu of every folder. Same shape as the Calendar's, so
 * the two apps pick a view the same way.
 * $groups is [ ['label' => 'Sean', 'options' => [ [value, label, color], … ] ], … ];
 * a group with an empty label lists its options loose at the top.
 *
 * Each of my own folders also carries a checkbox saying whether it shows in the "All"
 * view: tapping the row still switches to that folder, tapping the box only switches it
 * on or off. $hidden lists the folders currently switched off; $csrf being non-empty is
 * what turns the boxes on at all (a page that doesn't handle `folder_vis` passes '').
 */
function render_folder_pick(array $groups, string $active, string $activeLabel = 'All',
                            string $manageLabel = '', array $hidden = [], string $csrf = ''): void
{
    $e = fn($x) => htmlspecialchars((string) $x, ENT_QUOTES);
    $cur = '';
    foreach ($groups as $g) {
        foreach ($g['options'] as [$val, $label, $col]) {
            if ($active === $val) { $cur = $col; $activeLabel = $label; }
        }
    }
    $boxes = $csrf !== '';
    // In the "All" view the button's dot becomes a little pie of the visible folders'
    // colours (own folders not switched off), so it reflects what's actually on screen.
    $pieColors = [];
    if ($cur === '') {
        foreach ($groups as $g) {
            foreach ($g['options'] as [$val, $label, $col]) {
                if (strncmp($val, '@', 1) !== 0 && !in_array($val, $hidden, true)) { $pieColors[] = $col; }
            }
        }
    }
    $pieCss = '';
    if (count($pieColors) > 1) {
        $n = count($pieColors);
        $stops = [];
        foreach ($pieColors as $i => $c) {
            $stops[] = $e($c) . ' ' . round($i * 100 / $n, 2) . '% ' . round(($i + 1) * 100 / $n, 2) . '%';
        }
        $pieCss = 'conic-gradient(' . implode(',', $stops) . ')';
    }
    ?>
    <div class="folderpick">
      <button type="button" class="folderpick-btn" id="folderSelBtn" aria-haspopup="listbox"
              aria-expanded="false" title="<?= $e($activeLabel) ?>" aria-label="<?= $e($activeLabel) ?>">
        <?php if ($pieCss !== ''): ?>
          <span class="fdot" style="background:<?= $pieCss ?>"></span>
        <?php else: ?>
          <span class="fdot<?= $cur === '' ? ' all' : '' ?>"<?= $cur === '' ? '' : ' style="background:' . $e($cur) . '"' ?>></span>
        <?php endif; ?>
      </button>
      <div class="folderpick-menu" id="folderSelMenu" role="listbox" hidden>
        <a class="folderpick-opt" href="?folder=All">
          <?php if ($boxes): ?>
            <?php // The "All" box is a master switch: ticked when every folder is showing,
                  // and tapping it shows them all or hides them all at once. ?>
            <span class="fvis fvis-all<?= empty($hidden) ? ' on' : '' ?>" role="checkbox"
                  tabindex="0" aria-checked="<?= empty($hidden) ? 'true' : 'false' ?>"
                  title="Show every folder" aria-label="Show every folder in All"></span>
          <?php endif; ?>
          <span class="fdot all"></span><span>All</span>
        </a>
        <?php // One flat list, mine and the partner's together — no owner headings. A
              // shared folder wears a small badge instead, since there's no heading to say
              // whose it is. Every folder (mine or theirs) can be shown or hidden in my
              // "All"; a shared toggle is my view preference, never an edit of their data. ?>
        <?php foreach ($groups as $g): ?>
          <?php foreach ($g['options'] as [$val, $label, $col]): ?>
            <?php $shared = strncmp($val, '@', 1) === 0;
                  $pName  = $shared ? explode(':', substr($val, 1), 2)[0] : ''; ?>
            <a class="folderpick-opt" href="?folder=<?= urlencode($val) ?>">
              <?php if ($boxes): ?>
                <?php // A drawn box rather than a real <input>: the row is a link, so the
                      // click has to be cancelled, and cancelling a checkbox's click also
                      // undoes the tick the browser already applied. ?>
                <span class="fvis<?= in_array($val, $hidden, true) ? '' : ' on' ?>" role="checkbox"
                      tabindex="0" aria-checked="<?= in_array($val, $hidden, true) ? 'false' : 'true' ?>"
                      data-folder="<?= $e($val) ?>"
                      title="Show in All" aria-label="Show <?= $e($label) ?> in All"></span>
              <?php endif; ?>
              <span class="fdot" style="background:<?= $e($col) ?>"></span><span class="fpick-name"><?= $e($label) ?><?php if ($shared): ?><span class="fshared-badge" title="Shared by <?= $e($pName) ?>"><?= $e($pName) ?></span><?php endif; ?></span>
            </a>
          <?php endforeach; ?>
        <?php endforeach; ?>
        <?php if ($manageLabel !== ''): ?>
          <button type="button" class="folderpick-opt folderpick-manage" id="folderMgr">
            <span class="fpick-gear" aria-hidden="true"><?= folder_icon_svg() ?></span><span><?= $e($manageLabel) ?></span>
          </button>
        <?php endif; ?>
      </div>
    </div>
    <script>(function(){
      var b = document.getElementById('folderSelBtn'), m = document.getElementById('folderSelMenu');
      if (!b || !m) { return; }
      b.addEventListener('click', function (e) {
        e.stopPropagation();
        m.hidden = !m.hidden;
        b.setAttribute('aria-expanded', m.hidden ? 'false' : 'true');
      });
      // Toggling a checkbox reloads (to re-filter), so reopen the menu afterwards — ticking
      // folders shouldn't fold the dropdown away between each one.
      try { if (sessionStorage.getItem('folderPickOpen') === '1') { sessionStorage.removeItem('folderPickOpen');
        m.hidden = false; b.setAttribute('aria-expanded', 'true'); } } catch (_) {}
      document.addEventListener('click', function (e) {
        if (!m.hidden && !m.contains(e.target)) { m.hidden = true; b.setAttribute('aria-expanded', 'false'); }
      });
      // "Manage folders" is the last row of the menu; it opens the manager (wired up in
      // folder_modal_script via #folderMgr) and closes the menu behind it.
      m.addEventListener('click', function (e) {
        if (e.target.closest('.folderpick-manage')) { m.hidden = true; b.setAttribute('aria-expanded', 'false'); }
      });
      var CSRF = <?= json_encode($csrf) ?>;
      // Every folder key the menu drew, so a change can be written as "these are the ones
      // showing" rather than one flag at a time — mine and the partner's in one go.
      var allKeys = function () {
        return [].map.call(m.querySelectorAll('.fvis[data-folder]'), function (c) { return c.dataset.folder; });
      };
      // Changing what's in view expands the sections and folders, so whatever you just
      // switched on is open rather than hidden behind a collapse you'd forgotten about,
      // and the menu reopens after the reload instead of folding away between taps.
      var before = function () {
        try {
          localStorage.removeItem('collapsed:' + location.pathname);
          localStorage.removeItem('foldercollapsed:' + location.pathname);
          sessionStorage.setItem('folderPickOpen', '1');
        } catch (_) {}
      };
      var post = function (params, then) {
        fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: new URLSearchParams(params) }).then(then).catch(then);
      };

      // The show/hide box lives inside the row's link, so it has to swallow the click
      // that would otherwise navigate to that folder. It posts in the background and
      // reloads to re-filter what's on screen.
      m.addEventListener('click', function (e) {
        var cb = e.target.closest('.fvis');
        if (!cb) { return; }
        e.preventDefault(); e.stopPropagation();
        var on = !cb.classList.contains('on');
        cb.classList.toggle('on', on);
        cb.setAttribute('aria-checked', on ? 'true' : 'false');
        before();
        var reload = function () { location.reload(); };
        // "All" is a master switch: ticked only when every folder is showing, so one tap
        // shows them all, and a second — with them all already on — hides the lot.
        if (cb.classList.contains('fvis-all')) {
          var keys = allKeys();
          post({ csrf: CSRF, action: 'folder_vis_all', show: on ? '1' : '',
                 keys: keys.join('\x1F') }, reload);
        } else {
          post({ csrf: CSRF, action: 'folder_vis', name: cb.dataset.folder, show: on ? '1' : '' }, reload);
        }
      });

      // Tapping the *row* rather than its box makes that folder the only one ticked — the
      // quick way to look at one thing without unticking the rest by hand. The row still
      // navigates to its own view afterwards, so the ticks always say what's on screen.
      m.addEventListener('click', function (e) {
        if (e.target.closest('.fvis')) { return; }          // the box has its own handler
        var row = e.target.closest('a.folderpick-opt');
        if (!row) { return; }
        var box = row.querySelector('.fvis');
        if (!box || !CSRF) { return; }                      // no boxes here: plain navigation
        // stopPropagation as well as preventDefault: in a home-screen PWA tabbar.php has a
        // click listener on document that navigates same-origin links itself, and it would
        // otherwise leave the page before this POST had gone anywhere.
        e.preventDefault(); e.stopPropagation();
        var href = row.getAttribute('href'), keys = allKeys();
        var go = function () { location.href = href; };
        before();
        if (box.classList.contains('fvis-all')) {
          // The All row does what its box does: everything on, or — if it already was —
          // everything off.
          post({ csrf: CSRF, action: 'folder_vis_all', show: box.classList.contains('on') ? '' : '1',
                 keys: keys.join('\x1F') }, go);
        } else {
          post({ csrf: CSRF, action: 'folder_vis_only', name: box.dataset.folder,
                 keys: keys.join('\x1F') }, go);
        }
      });
    })();</script>
    <?php
}

/**
 * The folder manager the "+" beside the app title opens — the same shape as the
 * Calendar's manage-calendars window: an add row, then every folder with an X.
 * It posts the add_folder / delete_folder actions the pages already handle, so
 * each change is an ordinary POST -> redirect, not AJAX.
 */
function render_folder_modal(array $rows, string $csrf, string $view = 'All',
                             string $extraButton = '', array $palette = [],
                             array $sharedPalette = [], string $type = 'reminders',
                             bool $allowRename = false, array $sectionsByFolder = [],
                             string $defaultFolder = '', string $defaultSection = ''): void
{
    $csrf  = htmlspecialchars($csrf, ENT_QUOTES);
    $vw    = htmlspecialchars($view, ENT_QUOTES);
    $fixed = folders_fixed($type);   // the permanent ones carry no delete × and no rename
    if (!$palette) { $palette = app_palette($type); }
    if (!$sharedPalette) { $sharedPalette = app_palette($type, true); }
    ?>
    <div class="modal-backdrop" id="folderModal">
      <div class="foldermodal">
        <h2>Folders</h2>
        <form class="addrow" method="post" action="" onsubmit="return this.name.value.trim()!==''">
          <input type="hidden" name="csrf" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="add_folder">
          <input type="text" name="name" placeholder="New folder" maxlength="40" autocomplete="off">
          <button type="submit" class="plus" title="Add folder" aria-label="Add folder"><?= plus_icon_svg(18, 3) ?></button>
        </form>
        <?php // One interleaved list: my own folders and the partner's shared ones together,
              // in the order I've dragged them into. A shared row wears a badge (its data is
              // never mine to edit — only recolour how it shows in my picker, and reorder it
              // in my list), so it carries no delete ×; my own folders keep theirs unless
              // permanent. Every row drags to reorder by its handle. ?>
        <ul class="flist" id="fReorder">
          <?php foreach ($rows as $r): ?>
            <?php $shared = !empty($r['shared']);
                  $key    = (string) $r['key'];
                  $pal    = $shared ? $sharedPalette : $palette; ?>
            <li data-folder="<?= htmlspecialchars($key, ENT_QUOTES) ?>"<?= $shared ? ' class="fshared"' : '' ?>>
              <span class="fhandle" title="Drag to reorder" aria-hidden="true">&#9776;</span>
              <?php // The swatch opens a <details> palette: picking a colour is an
                    // ordinary POST, like everything else in this window. A shared row's
                    // colour is stored as my own override, keyed by its "@partner:Folder". ?>
              <details class="fcolor">
                <summary style="background:<?= htmlspecialchars($r['color'], ENT_QUOTES) ?>"
                         title="Colour"></summary>
                <form class="fswatches" method="post" action="">
                  <input type="hidden" name="csrf" value="<?= $csrf ?>">
                  <input type="hidden" name="action" value="set_folder_color">
                  <input type="hidden" name="view" value="<?= $vw ?>">
                  <input type="hidden" name="name" value="<?= htmlspecialchars($key, ENT_QUOTES) ?>">
                  <?php foreach ($pal as $col): ?>
                    <button type="submit" name="color" value="<?= $col ?>" style="background:<?= $col ?>"
                            title="<?= $col ?>"></button>
                  <?php endforeach; ?>
                </form>
              </details>
              <?php // The name is a plain span, or — when rename is on and this is my own
                    // non-permanent folder — an editable field that posts rename_folder on
                    // Enter or blur and reopens the manager (fm=1). Shared and fixed rows
                    // stay plain, since neither is mine to rename. ?>
              <?php if ($allowRename && !$shared && !in_array($key, $fixed, true)): ?>
                <form method="post" action="" class="frename-form">
                  <input type="hidden" name="csrf" value="<?= $csrf ?>">
                  <input type="hidden" name="action" value="rename_folder">
                  <input type="hidden" name="view" value="<?= $vw ?>">
                  <input type="hidden" name="fm" value="1">
                  <input type="hidden" name="name" value="<?= htmlspecialchars($key, ENT_QUOTES) ?>">
                  <input class="fname frename" name="newname" value="<?= htmlspecialchars($r['label'], ENT_QUOTES) ?>"
                         maxlength="40" autocomplete="off" aria-label="Folder name">
                </form>
              <?php else: ?>
                <span class="fname"><?= htmlspecialchars($r['label'], ENT_QUOTES) ?></span>
              <?php endif; ?>
              <?php if ($shared): ?>
                <span class="fshared-badge" title="Shared by <?= htmlspecialchars($r['partner'], ENT_QUOTES) ?>"><?= htmlspecialchars($r['partner'], ENT_QUOTES) ?></span>
              <?php elseif (!in_array($key, $fixed, true)): ?>
                <form method="post" action="" style="display:inline">
                  <input type="hidden" name="csrf" value="<?= $csrf ?>">
                  <input type="hidden" name="action" value="delete_folder">
                  <input type="hidden" name="view" value="<?= $vw ?>">
                  <input type="hidden" name="name" value="<?= htmlspecialchars($key, ENT_QUOTES) ?>">
                  <button type="submit" class="fdel needs-confirm"
                          title="Delete folder">&times;</button>
                </form>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php // Default Folder/Section: where a new item lands when you're viewing "All" and
              // don't pick a spot. The same folder→section list the Add app offers, real
              // sections only. Changing it posts set_default_section and reopens the manager. ?>
        <?php if ($sectionsByFolder): ?>
          <form class="fdefrow" method="post" action="">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="set_default_section">
            <input type="hidden" name="view" value="<?= $vw ?>">
            <label for="fDefSel">Default for new items</label>
            <select name="fs" id="fDefSel" onchange="this.form.submit()">
              <?php foreach ($sectionsByFolder as $fname => $secs): ?>
                <optgroup label="<?= htmlspecialchars((string) $fname, ENT_QUOTES) ?>">
                  <?php foreach ($secs as $sname): ?>
                    <?php $val = $fname . "\x1F" . $sname;
                          $sel = ($fname === $defaultFolder && $sname === $defaultSection); ?>
                    <option value="<?= htmlspecialchars($val, ENT_QUOTES) ?>"<?= $sel ? ' selected' : '' ?>><?= htmlspecialchars((string) $sname, ENT_QUOTES) ?></option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endforeach; ?>
            </select>
          </form>
        <?php endif; ?>
        <p class="fhint">Deleting a folder keeps its items — they move to <?= htmlspecialchars($fixed[0], ENT_QUOTES) ?>.</p>
        <div class="frow"><?= $extraButton ?><button type="button" class="fdone" id="folderDone">Done</button></div>
      </div>
    </div>
    <?php
}

/** Wire the "+" beside the title to the manager window. */
function folder_modal_script(): string
{
    return "<script>(function(){var b=document.getElementById('folderMgr'),m=document.getElementById('folderModal'),"
         . "d=document.getElementById('folderDone');if(!b||!m)return;"
         // A reorder only changes the DOM in the modal; reload on close so the picker
         // dropdown (rendered with the page) shows the new folder order too.
         . "var fDirty=false;"
         . "var close=function(){if(fDirty){location.reload();return;}m.classList.remove('open');};"
         . "b.addEventListener('click',function(){m.classList.add('open');"
         . "var i=m.querySelector('.addrow input[type=text]');if(i)i.focus();});"
         // Add/delete/default reload the page with ?fm=1 so the manager reopens rather
         // than closing out from under you; strip the param once it's back open.
         . "var q=new URLSearchParams(location.search);if(q.get('fm')==='1'){m.classList.add('open');"
         . "q.delete('fm');var u=new URL(location.href);u.search=q.toString();history.replaceState(null,'',u);}"
         . "if(d)d.addEventListener('click',close);"
         . "m.addEventListener('click',function(e){if(e.target===m)close();});"
         // Picking a colour posts in the background and recolours the swatch in place, so
         // the manager window stays open instead of reloading out from under you.
         . "m.addEventListener('click',function(e){var sw=e.target.closest('.fswatches button[name=color]');"
         . "if(!sw)return;e.preventDefault();var col=sw.value,det=sw.closest('details'),f=sw.form;"
         . "var body=new URLSearchParams(new FormData(f));body.set('color',col);"
         . "fetch('',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:body}).catch(function(){});"
         . "var sum=det&&det.querySelector('summary');if(sum)sum.style.background=col;"
         . "if(det)det.open=false;});"
         // Drag a custom folder by its handle to reorder it; the permanent folders stay
         // pinned first. On drop, POST the new order (custom folders only) in the background.
         . "var list=document.getElementById('fReorder');if(list){var drag=null,"
         . "ce=m.querySelector('input[name=csrf]'),CSRF=ce?ce.value:'';"
         . "var clr=function(){list.querySelectorAll('.fdrop-before,.fdrop-after').forEach(function(li){"
         . "li.classList.remove('fdrop-before','fdrop-after');});};"
         . "var rows=function(){return [].slice.call(list.querySelectorAll('li:not(.fpinned)'));};"
         . "list.addEventListener('pointerdown',function(e){var h=e.target.closest('.fhandle');"
         . "if(!h||h.classList.contains('blank'))return;drag=h.closest('li');if(!drag)return;"
         . "e.preventDefault();drag.classList.add('dragging');h.setPointerCapture(e.pointerId);});"
         . "list.addEventListener('pointermove',function(e){if(!drag)return;e.preventDefault();clr();"
         . "var y=e.clientY,tgt=null,after=false;rows().forEach(function(li){if(li===drag)return;"
         . "var r=li.getBoundingClientRect();if(y>=r.top){tgt=li;after=y>(r.top+r.height/2);}});"
         . "if(tgt)tgt.classList.add(after?'fdrop-after':'fdrop-before');});"
         . "var drop=function(){if(!drag)return;var mk=list.querySelector('.fdrop-before,.fdrop-after');"
         . "var moved=false;if(mk){if(mk.classList.contains('fdrop-after'))mk.after(drag);else mk.before(drag);moved=true;}"
         . "clr();drag.classList.remove('dragging');drag=null;if(!moved)return;"
         . "var order=rows().map(function(li){return li.dataset.folder;});"
         . "var body=new URLSearchParams();body.set('csrf',CSRF);body.set('action','reorder_folders');"
         . "body.set('order',order.join('\\u001f'));"
         . "fetch('',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:body}).catch(function(){});"
         . "fDirty=true;};"
         . "list.addEventListener('pointerup',drop);"
         . "list.addEventListener('pointercancel',function(){clr();if(drag){drag.classList.remove('dragging');drag=null;}});}"
         . "document.addEventListener('keydown',function(e){if(e.key==='Escape')close();});})();</script>";
}

function folder_nav_styles(): string
{
    return <<<CSS
    .foldernav { display: flex; flex-wrap: wrap; gap: 0.4rem; margin-bottom: 1.25rem; align-items: center; }

    /* Folder picker: a round button in the title bar wearing the folder's colour,
       and the menu it drops. The Calendar's calpick is the same thing for calendars. */
    .folderpick { position: relative; display: inline-flex; }
    .folderpick-btn {
      display: inline-flex; align-items: center; justify-content: center;
      width: 32px; height: 32px; padding: 0;
      background: #1a1a1a; border: 1px solid #333; border-radius: 50%; cursor: pointer;
    }
    .folderpick-btn:hover { border-color: #888; }
    .fdot { flex: 0 0 auto; width: 16px; height: 16px; border-radius: 50%; background: #555; }
    .fdot.all {
      background: conic-gradient(#60a5fa, var(--accent), #facc15, #f472b6, #60a5fa);
    }
    .folderpick-menu {
      position: absolute; right: 0; top: calc(100% + 5px); z-index: 45; min-width: 200px;
      max-width: min(320px, 90vw); max-height: 60vh; overflow-y: auto; overflow-x: hidden;
      background: #1c1c1c; border: 1px solid #333; border-radius: 10px;
      box-shadow: 0 8px 22px rgba(0,0,0,0.6); padding: 0.3rem;
    }
    .folderpick-menu[hidden] { display: none; }
    .folderpick-opt {
      display: flex; align-items: center; gap: 0.5rem; padding: 0.5rem 0.6rem;
      border-radius: 7px; color: #ddd; text-decoration: none; font-size: 0.92rem;
      /* Reset the native <button> look (some rows are buttons — the Habits filter — and
         showed a white background with unreadable text) so every row matches the <a> ones. */
      background: none; border: 0; width: 100%; text-align: left; cursor: pointer; font-family: inherit;
    }
    .folderpick-opt .fdot { width: 9px; height: 9px; }
    .folderpick-opt .fpick-name { flex: 1; min-width: 0; overflow-wrap: anywhere; }
    /* Badge marking a folder as the partner's, in place of an owner heading. It rides
       inline at the end of the name so it flows and wraps with the text rather than
       floating at the row's mid-height when a long name wraps to a second line. */
    .folderpick-opt .fshared-badge {
      display: inline-block; vertical-align: middle; margin-left: 0.35rem; white-space: nowrap;
      font-size: 0.68rem; color: #cbb8ff; background: #2a2440;
      border: 1px solid #3d3559; border-radius: 999px; padding: 0.05rem 0.4rem;
    }
    .folderpick-opt:hover { background: #262626; color: #fff; }
    /* The show-in-All box, drawn rather than a form control so it doesn't look like a
       stray input in a menu — and a blank of the same width keeps the rows without one
       (All, the partner's folders) lined up with the rows that have one. */
    .folderpick-opt .fvis {
      flex: 0 0 auto; width: 14px; height: 14px; border: 1px solid #555; border-radius: 3px;
      cursor: pointer; position: relative; box-sizing: border-box;
    }
    .folderpick-opt .fvis.on { background: var(--accent); border-color: var(--accent); }
    .folderpick-opt .fvis.on::after {
      content: ''; position: absolute; left: 4px; top: 1px; width: 4px; height: 8px;
      border: solid var(--accent-ink); border-width: 0 2px 2px 0; transform: rotate(45deg);
    }
    .folderpick-opt .fvis-pad { flex: 0 0 auto; width: 14px; }
    /* The active folder is shown by the coloured dot on the picker button, so the row in
       the menu isn't highlighted — the checkboxes are what the menu is about now. */
    /* "Manage folders", the last row of the picker menu. */
    .folderpick-manage {
      width: 100%; background: none; border: none; border-top: 1px solid #333;
      margin-top: 0.25rem; padding-top: 0.55rem; cursor: pointer; font-family: inherit;
      text-align: left; color: #bbb;
    }
    .folderpick-manage .fpick-gear { display: inline-flex; width: 9px; justify-content: center; color: #888; }
    .folderpick-manage:hover { color: #fff; }


    /* Folder manager window */
    .modal-backdrop {
      position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 60;
      display: none; align-items: center; justify-content: center; padding: 1rem;
    }
    .modal-backdrop.open { display: flex; }
    .foldermodal {
      background: #1a1a1a; border: 1px solid #333; border-radius: 12px;
      width: 100%; max-width: 380px; padding: 1.25rem; max-height: 85vh; overflow-y: auto;
    }
    .foldermodal h2 { font-size: 1.05rem; margin-bottom: 0.8rem; }
    .foldermodal .addrow { display: flex; gap: 0.5rem; margin-bottom: 0.8rem; }
    .foldermodal .addrow input[type=text] {
      flex: 1; padding: 0.6rem 0.75rem; background: #222; border: 1px solid #3a3a3a;
      border-radius: 6px; color: #eee; font-size: 16px;   /* 16px stops iOS from zooming on focus */
    }
    .foldermodal .addrow input:focus { outline: none; border-color: #888; }
    .foldermodal .addrow .plus {
      flex: 0 0 auto; width: 40px; background: var(--accent); color: var(--accent-ink); border: none;
      border-radius: 6px; font-size: 1.2rem; font-weight: 700; cursor: pointer; font-family: inherit;
      display: inline-flex; align-items: center; justify-content: center; padding: 0;
    }
    .foldermodal .addrow .plus:hover { background: #52e0ac; }
    .foldermodal .fshared-h {
      font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;
      color: #777; margin: 1rem 0 0.5rem;
    }
    .foldermodal .flist { list-style: none; display: flex; flex-direction: column; gap: 0.4rem; }
    .foldermodal .flist li {
      display: flex; align-items: center; gap: 0.6rem; padding: 0.5rem 0.6rem;
      background: #222; border: 1px solid #333; border-radius: 8px;
    }
    .foldermodal .flist .fname { flex: 1; font-size: 0.95rem; word-break: break-word; }
    /* Drag handle for reordering a custom folder; the pinned permanent ones show a
       blank slot of the same width so every name still lines up. */
    .foldermodal .fhandle {
      flex: 0 0 auto; width: 18px; text-align: center; color: #666; cursor: grab;
      font-size: 0.95rem; line-height: 1; touch-action: none; user-select: none;
    }
    .foldermodal .fhandle.blank { cursor: default; }
    .foldermodal .flist li.dragging { opacity: 0.5; }
    .foldermodal .flist li.fdrop-before { box-shadow: 0 -2px 0 0 var(--accent, #34d399); }
    .foldermodal .flist li.fdrop-after { box-shadow: 0 2px 0 0 var(--accent, #34d399); }
    .foldermodal .flist .fdel {
      background: none; border: 1px solid #444; color: #999; border-radius: 6px;
      padding: 0.15rem 0.45rem; font-size: 0.9rem; line-height: 1; cursor: pointer; font-family: inherit;
    }
    .foldermodal .flist .fdel:hover { border-color: #f66; color: #f66; }
    /* Badge marking a shared row as the partner's, in place of an owner heading — the same
       chip the picker dropdown wears, since that's where the name comes from. */
    .foldermodal .fshared-badge {
      flex: 0 0 auto; font-size: 0.68rem; color: #cbb8ff; background: #2a2440;
      border: 1px solid #3d3559; border-radius: 999px; padding: 0.05rem 0.4rem;
    }
    /* The folder's colour: a swatch that opens the palette under it. */
    .foldermodal .fcolor { flex: 0 0 auto; position: relative; }
    .foldermodal .fcolor summary {
      width: 22px; height: 22px; border-radius: 6px; border: 1px solid #444;   /* square, like the calendar's */
      cursor: pointer; list-style: none;
    }
    .foldermodal .fcolor summary::-webkit-details-marker { display: none; }
    .foldermodal .fswatches {
      position: absolute; z-index: 5; top: calc(100% + 6px); left: 0;
      background: #1c1c1c; border: 1px solid #444; border-radius: 10px; padding: 0.5rem;
      display: grid; grid-template-columns: repeat(6, 22px); gap: 0.4rem;   /* all six on one row */
      box-shadow: 0 8px 20px rgba(0,0,0,0.6);
    }
    .foldermodal .fswatches button {
      width: 22px; height: 22px; border-radius: 6px; border: 1px solid #444; cursor: pointer; padding: 0;   /* square */
    }
    /* Which folder new items land in — same shape as the Calendar's default picker. */
    .foldermodal .defrow { display: flex; align-items: center; gap: 0.6rem; margin-top: 0.8rem; }
    .foldermodal .defrow label { font-size: 0.85rem; color: #999; white-space: nowrap; }
    .foldermodal .defrow select {
      flex: 1; min-width: 0; padding: 0.4rem 0.6rem; background: #222; border: 1px solid #3a3a3a;
      border-radius: 6px; color: #eee; font-size: 16px; font-family: inherit; cursor: pointer;
    }
    .foldermodal .defrow select:focus { outline: none; border-color: #888; }
    /* Default Folder/Section picker: a full-width labelled select under the folder list. */
    .foldermodal .fdefrow { display: flex; flex-direction: column; gap: 0.35rem; margin-top: 1rem; }
    .foldermodal .fdefrow label { font-size: 0.8rem; color: #999; }
    .foldermodal .fdefrow select {
      width: 100%; padding: 0.55rem 0.7rem; background: #222; border: 1px solid #3a3a3a;
      border-radius: 6px; color: #eee; font-size: 16px; font-family: inherit; cursor: pointer;
    }
    .foldermodal .fdefrow select:focus { outline: none; border-color: #888; }
    .foldermodal .fhint { color: #777; font-size: 0.78rem; margin: 0.8rem 0 0; }
    .foldermodal .frow { display: flex; align-items: center; gap: 0.5rem; margin-top: 1.1rem; }
    .foldermodal .fdone {
      display: block; margin: 0 0 0 auto; padding: 0.55rem 1.1rem; border: none;
      border-radius: 6px; background: var(--accent); color: var(--accent-ink); font-size: 0.95rem;
      font-weight: 600; cursor: pointer; font-family: inherit;
    }
    CSS;
}
