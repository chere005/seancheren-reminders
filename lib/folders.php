<?php
/**
 * Per-user folders for reminders and notes.
 * Stored in data/folders-<user>.json as { "reminders": [...], "notes": [...] }.
 * "General" always exists and is the default folder.
 */

const FOLDER_DEFAULT = 'General';

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
 * Render a folder navigator bar. $active is the selected folder ('' or 'All' = show all).
 * Folders are added and removed in the manager window — see render_folder_modal().
 */
function render_folder_nav(array $folders, string $active): void
{
    ?>
    <div class="foldernav">
      <a href="?folder=All" class="chip <?= ($active === 'All' || $active === '') ? 'active' : '' ?>">All</a>
      <?php foreach ($folders as $f): ?>
        <a href="?folder=<?= urlencode($f) ?>" class="chip <?= $active === $f ? 'active' : '' ?>">
          <?= htmlspecialchars($f, ENT_QUOTES) ?>
        </a>
      <?php endforeach; ?>
    </div>
    <?php
}

/**
 * Folder navigator as a dropdown, grouped by owner.
 * $groups is [ ['label' => 'Sean', 'options' => [ [value, label], … ] ], … ]; a group
 * with an empty label has its options listed loose at the top.
 */
function render_folder_select(array $groups, string $active): void
{
    ?>
    <div class="foldernav">
      <select id="folderSel" class="foldersel" aria-label="Folder">
        <option value="All"<?= ($active === 'All' || $active === '') ? ' selected' : '' ?>>All</option>
        <?php foreach ($groups as $g): ?>
          <?php if (empty($g['options'])) { continue; } ?>
          <?php if ($g['label'] !== ''): ?><optgroup label="<?= htmlspecialchars($g['label'], ENT_QUOTES) ?>"><?php endif; ?>
          <?php foreach ($g['options'] as [$val, $label]): ?>
            <option value="<?= htmlspecialchars($val, ENT_QUOTES) ?>"<?= $active === $val ? ' selected' : '' ?>>
              <?= htmlspecialchars($label, ENT_QUOTES) ?>
            </option>
          <?php endforeach; ?>
          <?php if ($g['label'] !== ''): ?></optgroup><?php endif; ?>
        <?php endforeach; ?>
      </select>
    </div>
    <script>
      document.getElementById('folderSel').addEventListener('change', function () {
        location.href = '?folder=' + encodeURIComponent(this.value);
      });
    </script>
    <?php
}

/**
 * The folder manager the "+" beside the app title opens — the same shape as the
 * Calendar's manage-calendars window: an add row, then every folder with an X.
 * It posts the add_folder / delete_folder actions the pages already handle, so
 * each change is an ordinary POST -> redirect, not AJAX.
 */
function render_folder_modal(array $folders, string $csrf, string $view = 'All',
                             string $default = FOLDER_DEFAULT, string $defaultLabel = 'New items go to'): void
{
    $csrf = htmlspecialchars($csrf, ENT_QUOTES);
    $vw   = htmlspecialchars($view, ENT_QUOTES);
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
              <span class="fname"><?= htmlspecialchars($f, ENT_QUOTES) ?></span>
              <?php if ($f !== FOLDER_DEFAULT): ?>
                <form method="post" action="" style="display:inline"
                      onsubmit="return confirm('Delete the folder &quot;<?= htmlspecialchars($f, ENT_QUOTES) ?>&quot;? Its items move to <?= FOLDER_DEFAULT ?>.')">
                  <input type="hidden" name="csrf" value="<?= $csrf ?>">
                  <input type="hidden" name="action" value="delete_folder">
                  <input type="hidden" name="view" value="<?= $vw ?>">
                  <input type="hidden" name="name" value="<?= htmlspecialchars($f, ENT_QUOTES) ?>">
                  <button type="submit" class="fdel" title="Delete folder">&times;</button>
                </form>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
        <form class="defrow" method="post" action="">
          <input type="hidden" name="csrf" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="set_default_folder">
          <input type="hidden" name="view" value="<?= $vw ?>">
          <label for="folderDefault"><?= htmlspecialchars($defaultLabel, ENT_QUOTES) ?></label>
          <select id="folderDefault" name="name" onchange="this.form.submit()">
            <?php foreach ($folders as $f): ?>
              <option value="<?= htmlspecialchars($f, ENT_QUOTES) ?>"<?= $f === $default ? ' selected' : '' ?>>
                <?= htmlspecialchars($f, ENT_QUOTES) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </form>
        <p class="fhint">Deleting a folder keeps its items — they move to <?= FOLDER_DEFAULT ?>.</p>
        <button type="button" class="fdone" id="folderDone">Done</button>
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
    .foldernav .chip {
      padding: 0.3rem 0.75rem; border: 1px solid #333; border-radius: 999px;
      color: #aaa; text-decoration: none; font-size: 0.82rem; white-space: nowrap;
    }
    .foldernav .chip:hover { border-color: #666; color: #ddd; }
    .foldernav .chip.active { background: #14251f; border-color: #34d399; color: #34d399; }
    .foldersel {
      background: #1a1a1a; border: 1px solid #333; border-radius: 999px; color: #34d399;
      padding: 0.3rem 0.75rem; font-size: 16px;   /* 16px stops iOS from zooming on focus */
      font-family: inherit; max-width: 100%;
    }
    .foldersel:focus { outline: none; border-color: #34d399; }

    /* The "+" beside the app title — edit mode only, like the Calendar's. */
    .folderplus {
      display: none; background: none; border: 1px solid #333; color: #ccc; border-radius: 999px;
      width: 26px; height: 26px; font-size: 1.05rem; line-height: 1; cursor: pointer; font-family: inherit;
    }
    body.editing .folderplus { display: inline-flex; align-items: center; justify-content: center; }
    .folderplus:hover { border-color: #34d399; color: #34d399; }

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
      flex: 0 0 auto; width: 40px; background: #34d399; color: #06251b; border: none;
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
    /* Which folder new items land in — same shape as the Calendar's default picker. */
    .foldermodal .defrow { display: flex; align-items: center; gap: 0.6rem; margin-top: 0.8rem; }
    .foldermodal .defrow label { font-size: 0.85rem; color: #999; white-space: nowrap; }
    .foldermodal .defrow select {
      flex: 1; min-width: 0; padding: 0.4rem 0.6rem; background: #222; border: 1px solid #3a3a3a;
      border-radius: 6px; color: #eee; font-size: 16px; font-family: inherit; cursor: pointer;
    }
    .foldermodal .defrow select:focus { outline: none; border-color: #888; }
    .foldermodal .fhint { color: #777; font-size: 0.78rem; margin: 0.8rem 0 0; }
    .foldermodal .fdone {
      display: block; margin: 1.1rem 0 0 auto; padding: 0.55rem 1.1rem; border: none;
      border-radius: 6px; background: #34d399; color: #06251b; font-size: 0.95rem;
      font-weight: 600; cursor: pointer; font-family: inherit;
    }
    CSS;
}
