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
 * Persist a new order for a type's folders, from the Manage-folders drag. Every folder
 * can be reordered now, the permanent ones included: names that aren't real folders are
 * dropped, and any folder the request left out keeps its place at the end so nothing can
 * vanish (a fixed folder that's missing is re-added by folders_load regardless).
 */
function folders_reorder(string $dir, string $type, array $order): void
{
    if (!in_array($type, ['reminders', 'notes'], true)) { return; }
    $data = folders_load($dir);
    $all  = array_values($data[$type] ?? []);
    $wanted = [];
    foreach ($order as $f) {
        if (in_array($f, $all, true) && !in_array($f, $wanted, true)) { $wanted[] = $f; }
    }
    foreach ($all as $f) { if (!in_array($f, $wanted, true)) { $wanted[] = $f; } }
    $data[$type] = $wanted;
    folders_save($dir, $data);
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
    $name = trim(preg_replace('/\s+/', ' ', $name));
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
    ?>
    <div class="folderpick">
      <button type="button" class="folderpick-btn" id="folderSelBtn" aria-haspopup="listbox"
              aria-expanded="false" title="<?= $e($activeLabel) ?>" aria-label="<?= $e($activeLabel) ?>">
        <span class="fdot<?= $cur === '' ? ' all' : '' ?>"<?= $cur === '' ? '' : ' style="background:' . $e($cur) . '"' ?>></span>
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
        <?php foreach ($groups as $g): ?>
          <?php if (empty($g['options'])) { continue; } ?>
          <?php if ($g['label'] !== ''): ?><div class="folderpick-group"><?= $e($g['label']) ?></div><?php endif; ?>
          <?php foreach ($g['options'] as [$val, $label, $col]): ?>
            <?php // Only my own folders can be hidden; a shared one is theirs to show. ?>
            <?php $own = $boxes && strncmp($val, '@', 1) !== 0; ?>
            <a class="folderpick-opt" href="?folder=<?= urlencode($val) ?>">
              <?php if ($boxes): ?>
                <?php // A drawn box rather than a real <input>: the row is a link, so the
                      // click has to be cancelled, and cancelling a checkbox's click also
                      // undoes the tick the browser already applied. A span has no such
                      // activation behaviour to fight. ?>
                <?php if ($own): ?>
                  <span class="fvis<?= in_array($val, $hidden, true) ? '' : ' on' ?>" role="checkbox"
                        tabindex="0" aria-checked="<?= in_array($val, $hidden, true) ? 'false' : 'true' ?>"
                        data-folder="<?= $e($val) ?>"
                        title="Show in All" aria-label="Show <?= $e($label) ?> in All"></span>
                <?php else: ?>
                  <span class="fvis-pad" aria-hidden="true"></span>
                <?php endif; ?>
              <?php endif; ?>
              <span class="fdot" style="background:<?= $e($col) ?>"></span><span><?= $e($label) ?></span>
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
      document.addEventListener('click', function (e) {
        if (!m.hidden && !m.contains(e.target)) { m.hidden = true; b.setAttribute('aria-expanded', 'false'); }
      });
      // "Manage folders" is the last row of the menu; it opens the manager (wired up in
      // folder_modal_script via #folderMgr) and closes the menu behind it.
      m.addEventListener('click', function (e) {
        if (e.target.closest('.folderpick-manage')) { m.hidden = true; b.setAttribute('aria-expanded', 'false'); }
      });
      // The show/hide box lives inside the row's link, so it has to swallow the click
      // that would otherwise navigate to that folder. It posts in the background and
      // reloads only when the All view is what's on screen and would actually change.
      var CSRF = <?= json_encode($csrf) ?>;
      m.addEventListener('click', function (e) {
        var cb = e.target.closest('.fvis');
        if (!cb) { return; }
        e.preventDefault(); e.stopPropagation();
        var on = !cb.classList.contains('on');
        cb.classList.toggle('on', on);
        cb.setAttribute('aria-checked', on ? 'true' : 'false');
        // The "All" box is a master switch: it shows or hides every folder at once.
        var isAll = cb.classList.contains('fvis-all');
        // Changing what's in view expands the sections and folders, so whatever you just
        // switched on is open rather than hidden behind a collapse you'd forgotten about.
        try {
          localStorage.removeItem('collapsed:' + location.pathname);
          localStorage.removeItem('foldercollapsed:' + location.pathname);
        } catch (_) {}
        var body = new URLSearchParams(isAll
          ? { csrf: CSRF, action: 'folder_vis_all', show: on ? '1' : '' }
          : { csrf: CSRF, action: 'folder_vis', name: cb.dataset.folder, show: on ? '1' : '' });
        fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: body })
          .then(function () { location.reload(); })
          .catch(function () { location.reload(); });
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
function render_folder_modal(array $folders, string $csrf, string $view = 'All',
                             string $default = FOLDER_DEFAULT, string $defaultLabel = 'New items go to',
                             string $extraButton = '', array $colors = [], array $palette = [],
                             array $sharedRows = [], array $sharedPalette = [],
                             string $type = 'reminders'): void
{
    $csrf  = htmlspecialchars($csrf, ENT_QUOTES);
    $vw    = htmlspecialchars($view, ENT_QUOTES);
    $fixed = folders_fixed($type);   // the permanent ones carry no delete ×
    if (!$palette) { $palette = app_palette('reminders'); }
    if (!$sharedPalette) { $sharedPalette = app_palette('reminders', true); }
    ?>
    <div class="modal-backdrop" id="folderModal">
      <div class="foldermodal">
        <h2>Folders</h2>
        <form class="addrow" method="post" action="" onsubmit="return this.name.value.trim()!==''">
          <input type="hidden" name="csrf" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="add_folder">
          <input type="text" name="name" placeholder="New folder" maxlength="40" autocomplete="off">
          <button type="submit" class="plus" title="Add folder">+</button>
        </form>
        <ul class="flist" id="fReorder">
          <?php foreach ($folders as $f): ?>
            <?php $fFixed = in_array($f, $fixed, true); ?>
            <li data-folder="<?= htmlspecialchars($f, ENT_QUOTES) ?>">
              <?php // Every folder drags to reorder, the permanent ones included. They
                    // just keep their × off, since they can't be deleted. ?>
              <span class="fhandle" title="Drag to reorder" aria-hidden="true">&#9776;</span>
              <?php // The swatch opens a <details> palette: picking a colour is an
                    // ordinary POST, like everything else in this window. ?>
              <details class="fcolor">
                <summary style="background:<?= htmlspecialchars($colors[$f] ?? $palette[0], ENT_QUOTES) ?>"
                         title="Colour"></summary>
                <form class="fswatches" method="post" action="">
                  <input type="hidden" name="csrf" value="<?= $csrf ?>">
                  <input type="hidden" name="action" value="set_folder_color">
                  <input type="hidden" name="view" value="<?= $vw ?>">
                  <input type="hidden" name="name" value="<?= htmlspecialchars($f, ENT_QUOTES) ?>">
                  <?php foreach ($palette as $col): ?>
                    <button type="submit" name="color" value="<?= $col ?>" style="background:<?= $col ?>"
                            title="<?= $col ?>"></button>
                  <?php endforeach; ?>
                </form>
              </details>
              <span class="fname"><?= htmlspecialchars($f, ENT_QUOTES) ?></span>
              <?php if (!in_array($f, $fixed, true)): ?>
                <form method="post" action="" style="display:inline">
                  <input type="hidden" name="csrf" value="<?= $csrf ?>">
                  <input type="hidden" name="action" value="delete_folder">
                  <input type="hidden" name="view" value="<?= $vw ?>">
                  <input type="hidden" name="name" value="<?= htmlspecialchars($f, ENT_QUOTES) ?>">
                  <button type="submit" class="fdel needs-confirm"
                          title="Delete folder">&times;</button>
                </form>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php // Folders the other person shared with me: I can recolour how they show in
              // my picker (stored in my own file), but nothing else about them is mine. ?>
        <?php if ($sharedRows): ?>
          <h3 class="fshared-h">Shared with me</h3>
          <ul class="flist">
            <?php foreach ($sharedRows as $r): ?>
              <li>
                <details class="fcolor">
                  <summary style="background:<?= htmlspecialchars($r['color'], ENT_QUOTES) ?>"
                           title="Colour"></summary>
                  <form class="fswatches" method="post" action="">
                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="set_folder_color">
                    <input type="hidden" name="view" value="<?= $vw ?>">
                    <input type="hidden" name="name" value="<?= htmlspecialchars($r['key'], ENT_QUOTES) ?>">
                    <?php foreach ($sharedPalette as $col): ?>
                      <button type="submit" name="color" value="<?= $col ?>" style="background:<?= $col ?>"
                              title="<?= $col ?>"></button>
                    <?php endforeach; ?>
                  </form>
                </details>
                <span class="fname"><?= htmlspecialchars($r['label'], ENT_QUOTES) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
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
         . "var close=function(){m.classList.remove('open');};"
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
         . "fetch('',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:body}).catch(function(){});};"
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
      max-width: min(320px, 90vw); max-height: 60vh; overflow-y: auto;
      background: #1c1c1c; border: 1px solid #333; border-radius: 10px;
      box-shadow: 0 8px 22px rgba(0,0,0,0.6); padding: 0.3rem;
    }
    .folderpick-menu[hidden] { display: none; }
    .folderpick-group {
      color: #777; font-size: 0.65rem; font-weight: 700; text-transform: uppercase;
      letter-spacing: 0.05em; padding: 0.45rem 0.6rem 0.2rem;
    }
    .folderpick-opt {
      display: flex; align-items: center; gap: 0.5rem; padding: 0.5rem 0.6rem;
      border-radius: 7px; color: #ddd; text-decoration: none; font-size: 0.92rem;
    }
    .folderpick-opt .fdot { width: 9px; height: 9px; }
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
    /* The folder's colour: a swatch that opens the palette under it. */
    .foldermodal .fcolor { flex: 0 0 auto; position: relative; }
    .foldermodal .fcolor summary {
      width: 20px; height: 20px; border-radius: 50%; border: 1px solid #444;
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
      width: 22px; height: 22px; border-radius: 50%; border: 1px solid #444; cursor: pointer; padding: 0;
    }
    /* Which folder new items land in — same shape as the Calendar's default picker. */
    .foldermodal .defrow { display: flex; align-items: center; gap: 0.6rem; margin-top: 0.8rem; }
    .foldermodal .defrow label { font-size: 0.85rem; color: #999; white-space: nowrap; }
    .foldermodal .defrow select {
      flex: 1; min-width: 0; padding: 0.4rem 0.6rem; background: #222; border: 1px solid #3a3a3a;
      border-radius: 6px; color: #eee; font-size: 16px; font-family: inherit; cursor: pointer;
    }
    .foldermodal .defrow select:focus { outline: none; border-color: #888; }
    .foldermodal .fhint { color: #777; font-size: 0.78rem; margin: 0.8rem 0 0; }
    .foldermodal .frow { display: flex; align-items: center; gap: 0.5rem; margin-top: 1.1rem; }
    .foldermodal .fdone {
      display: block; margin: 0 0 0 auto; padding: 0.55rem 1.1rem; border: none;
      border-radius: 6px; background: var(--accent); color: var(--accent-ink); font-size: 0.95rem;
      font-weight: 600; cursor: pointer; font-family: inherit;
    }
    CSS;
}
