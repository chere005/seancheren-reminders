<?php
/**
 * Per-user folders for reminders and notes.
 * Stored in data/folders-<user>.json as { "reminders": [...], "notes": [...] }.
 * "General" always exists and is the default folder.
 */

require_once __DIR__ . '/palette.php';

const FOLDER_DEFAULT = 'General';

// A folder's colours come from the per-app palette (app_palette('reminders'|'notes')),
// so reminder and note folders sit at their own shades. See lib/palette.php.

function folders_load(string $dir, ?string $user = null): array
{
    $file = user_data_file($dir, 'folders', $user);
    $data = store_read($file);
    foreach (['reminders', 'notes'] as $type) {
        if (empty($data[$type]) || !is_array($data[$type])) {
            $data[$type] = [FOLDER_DEFAULT];
        }
        // Ensure General is present and first.
        $data[$type] = array_values(array_unique(array_merge([FOLDER_DEFAULT],
            array_filter($data[$type], fn($f) => $f !== FOLDER_DEFAULT))));
    }
    return $data;
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
    return in_array($name, $data[$type] ?? [], true) ? $name : FOLDER_DEFAULT;
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
    if ($name === FOLDER_DEFAULT || !in_array($type, ['reminders', 'notes'], true)) {
        return;   // never delete the default
    }
    $data = folders_load($dir);
    $data[$type] = array_values(array_filter($data[$type], fn($f) => $f !== $name));
    folders_save($dir, $data);
}

/**
 * The folder picker: a round button wearing the selected folder's colour, right of
 * the app's "+", opening a menu of every folder. Same shape as the Calendar's, so
 * the two apps pick a view the same way.
 * $groups is [ ['label' => 'Sean', 'options' => [ [value, label, color], … ] ], … ];
 * a group with an empty label lists its options loose at the top.
 */
function render_folder_pick(array $groups, string $active, string $activeLabel = 'All'): void
{
    $e = fn($x) => htmlspecialchars((string) $x, ENT_QUOTES);
    $cur = '';
    foreach ($groups as $g) {
        foreach ($g['options'] as [$val, $label, $col]) {
            if ($active === $val) { $cur = $col; $activeLabel = $label; }
        }
    }
    ?>
    <div class="folderpick">
      <button type="button" class="folderpick-btn" id="folderSelBtn" aria-haspopup="listbox"
              aria-expanded="false" title="<?= $e($activeLabel) ?>" aria-label="<?= $e($activeLabel) ?>">
        <span class="fdot<?= $cur === '' ? ' all' : '' ?>"<?= $cur === '' ? '' : ' style="background:' . $e($cur) . '"' ?>></span>
      </button>
      <div class="folderpick-menu" id="folderSelMenu" role="listbox" hidden>
        <a class="folderpick-opt<?= ($active === 'All' || $active === '') ? ' on' : '' ?>" href="?folder=All">
          <span class="fdot all"></span><span>All</span>
        </a>
        <?php foreach ($groups as $g): ?>
          <?php if (empty($g['options'])) { continue; } ?>
          <?php if ($g['label'] !== ''): ?><div class="folderpick-group"><?= $e($g['label']) ?></div><?php endif; ?>
          <?php foreach ($g['options'] as [$val, $label, $col]): ?>
            <a class="folderpick-opt<?= $active === $val ? ' on' : '' ?>" href="?folder=<?= urlencode($val) ?>">
              <span class="fdot" style="background:<?= $e($col) ?>"></span><span><?= $e($label) ?></span>
            </a>
          <?php endforeach; ?>
        <?php endforeach; ?>
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
                             string $extraButton = '', array $colors = [], array $palette = []): void
{
    $csrf = htmlspecialchars($csrf, ENT_QUOTES);
    $vw   = htmlspecialchars($view, ENT_QUOTES);
    if (!$palette) { $palette = app_palette('reminders'); }
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
        <ul class="flist">
          <?php foreach ($folders as $f): ?>
            <li>
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
              <?php if ($f !== FOLDER_DEFAULT): ?>
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
        <p class="fhint">Deleting a folder keeps its items — they move to <?= FOLDER_DEFAULT ?>.</p>
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
         . "if(d)d.addEventListener('click',close);"
         . "m.addEventListener('click',function(e){if(e.target===m)close();});"
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
      position: absolute; left: 0; top: calc(100% + 5px); z-index: 45; min-width: 200px;
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
    .folderpick-opt.on { background: var(--accent-soft); color: var(--accent); font-weight: 600; }


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
    .foldermodal .flist { list-style: none; display: flex; flex-direction: column; gap: 0.4rem; }
    .foldermodal .flist li {
      display: flex; align-items: center; gap: 0.6rem; padding: 0.5rem 0.6rem;
      background: #222; border: 1px solid #333; border-radius: 8px;
    }
    .foldermodal .flist .fname { flex: 1; font-size: 0.95rem; word-break: break-word; }
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
      display: grid; grid-template-columns: repeat(5, 22px); gap: 0.4rem;
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
