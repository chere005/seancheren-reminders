<?php
/**
 * Per-user folders for reminders and notes.
 * Stored in data/folders-<user>.json as { "reminders": [...], "notes": [...] }.
 * "General" always exists and is the default folder.
 */

const FOLDER_DEFAULT = 'General';

function folders_load(string $dir): array
{
    $file = user_data_file($dir, 'folders');
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
 * $extra is optional trailing HTML (e.g. a "+ New section" control) rendered inside the bar.
 */
function render_folder_nav(array $folders, string $active, string $csrf, string $extra = ''): void
{
    ?>
    <div class="foldernav">
      <a href="?folder=All" class="chip <?= ($active === 'All' || $active === '') ? 'active' : '' ?>">All</a>
      <?php foreach ($folders as $f): ?>
        <a href="?folder=<?= urlencode($f) ?>" class="chip <?= $active === $f ? 'active' : '' ?>">
          <?= htmlspecialchars($f, ENT_QUOTES) ?>
        </a>
      <?php endforeach; ?>
      <form method="post" action="" class="newfolder" onsubmit="return this.name.value.trim()!==''">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
        <input type="hidden" name="action" value="add_folder">
        <input type="text" name="name" placeholder="+ New folder" maxlength="40" autocomplete="off">
      </form>
      <?= $extra ?>
    </div>
    <?php
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
    .foldernav .newfolder { margin-left: auto; }
    body:not(.editing) .foldernav .newfolder { display: none; }   /* edit mode only */
    .foldernav .newfolder input {
      width: 150px; padding: 0.3rem 0.7rem; background: #1a1a1a; border: 1px dashed #3a5a4d;
      border-radius: 999px; color: #34d399; font-size: 16px;   /* 16px stops iOS from zooming on focus */
    }
    .foldernav .newfolder input::placeholder { color: #34d399; opacity: 0.8; }
    .foldernav .newfolder input:focus { outline: none; border-style: solid; border-color: #34d399; }
    CSS;
}
