<?php
/**
 * Sharing between the two people who use this suite.
 *
 * Nothing is copied: each person keeps owning their own encrypted files, and a
 * small shares-<user>.json says which of their calendars and reminder folders
 * the other one is allowed to see. The reader loads the owner's file directly.
 */

/** Who sees whose stuff. Anyone not listed here has no sharing UI at all. */
const SHARE_PAIRS = ['sean' => 'aki', 'aki' => 'sean'];

/** The other person for $user (default: the signed-in user), or null if they have none. */
function share_partner(?string $user = null): ?string
{
    $u = strtolower((string) ($user ?? current_user() ?? ''));
    return SHARE_PAIRS[$u] ?? null;
}

/** What $user has shared out: ['calendars' => [calendar ids], 'folders' => [folder names]]. */
function shares_load(string $dir, string $user): array
{
    $d = store_read(user_data_file($dir, 'shares', $user));
    return [
        'calendars' => array_values(array_filter((array) ($d['calendars'] ?? []), 'is_string')),
        'folders'   => array_values(array_filter((array) ($d['folders'] ?? []), 'is_string')),
    ];
}

function shares_save(string $dir, string $user, array $shares): void
{
    store_write(user_data_file($dir, 'shares', $user), [
        'calendars' => array_values($shares['calendars'] ?? []),
        'folders'   => array_values($shares['folders'] ?? []),
    ]);
}

/** Add or remove one key from a share list, and save. Returns the new list. */
function shares_toggle(string $dir, string $user, string $kind, string $key, bool $on): array
{
    $shares = shares_load($dir, $user);
    $k      = $kind === 'calendar' ? 'calendars' : 'folders';
    $shares[$k] = array_values(array_filter($shares[$k], fn($x) => $x !== $key));
    if ($on) { $shares[$k][] = $key; }
    shares_save($dir, $user, $shares);
    return $shares;
}

/** A display name for a username — just capitalised, these are first names. */
function share_name(string $user): string
{
    return ucfirst($user);
}

/**
 * The share window, shared by the Calendar's manage window and the Reminders "+".
 * Both apps show the same two lists — my calendars and my reminder folders — and
 * both post the same `share_set` action, so sharing looks and behaves identically
 * wherever you happen to open it.
 */

/** My own calendars as id => name (sets skipped), for the calendars half of the list. */
function share_calendars(string $dir, string $user): array
{
    $out = [];
    foreach (store_read(user_data_file($dir, 'calendars', $user)) as $c) {
        if (($c['type'] ?? '') === 'set') { continue; }
        $out[(string) ($c['id'] ?? '')] = (string) ($c['name'] ?? '');
    }
    return $out;
}

/**
 * Handle a posted `share_set` and answer with the new share list. $calIds and
 * $folders are what this user actually owns — a key outside them is ignored, so
 * the window can never share something that isn't theirs.
 */
function share_handle_set(string $dir, string $user, array $calIds, array $folders): void
{
    $kind = (string) ($_POST['kind'] ?? '');
    $key  = (string) ($_POST['key'] ?? '');
    $pool = $kind === 'calendar' ? $calIds : $folders;
    $shares = shares_load($dir, $user);
    if (in_array($kind, ['calendar', 'folder'], true) && in_array($key, $pool, true)) {
        $shares = shares_toggle($dir, $user, $kind, $key, !empty($_POST['on']));
    }
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'shares' => $shares]);
    exit;
}

function share_modal_html(string $partner): string
{
    $who = htmlspecialchars(share_name($partner), ENT_QUOTES);
    return <<<HTML
    <div class="modal-backdrop" id="shareModal">
      <div class="sh-modal">
        <h2>Shared with {$who}</h2>
        <p class="sh-hint">Ticked calendars and reminder folders show up in {$who}&rsquo;s apps. Nothing is copied — {$who} reads yours.</p>
        <h3>Calendars</h3>
        <ul class="sh-list" id="shareCals"></ul>
        <h3>Reminders</h3>
        <ul class="sh-list" id="shareFolders"></ul>
        <div class="sh-actions"><button type="button" class="sh-done" id="shareDone">Done</button></div>
      </div>
    </div>
    HTML;
}

/** The button that opens it — sits beside the Done of whichever window it's in. */
function share_button_html(): string
{
    return '<button type="button" class="sh-open" id="shareBtn">Share</button>';
}

function share_modal_styles(): string
{
    return <<<'CSS'
    .sh-modal {
      background: #1a1a1a; border: 1px solid #333; border-radius: 12px; padding: 1.25rem;
      width: 100%; max-width: 380px; max-height: 85vh; overflow-y: auto;
    }
    .sh-modal h2 { font-size: 1.05rem; margin: 0 0 0.4rem; }
    .sh-modal h3 { font-size: 0.9rem; margin: 1rem 0 0.5rem; color: #ddd; }
    .sh-hint { font-size: 0.78rem; color: #888; margin: 0 0 0.4rem; line-height: 1.4; }
    .sh-list { list-style: none; display: flex; flex-direction: column; gap: 0.4rem; margin: 0; padding: 0; }
    .sh-list li {
      display: flex; align-items: center; gap: 0.6rem; padding: 0.5rem 0.6rem;
      background: #222; border: 1px solid #333; border-radius: 8px; cursor: pointer;
    }
    .sh-list .sh-name { flex: 1; font-size: 0.95rem; word-break: break-word; }
    .sh-list input[type=checkbox] { width: 18px; height: 18px; accent-color: var(--accent); flex: 0 0 auto; }
    .sh-list .sh-empty { color: #777; font-size: 0.85rem; background: none; border: none; cursor: default; }
    .sh-actions { display: flex; justify-content: flex-end; margin-top: 1.1rem; }
    .sh-done, .sh-open {
      padding: 0.35rem 0.9rem; font-size: 0.9rem; border-radius: 999px; cursor: pointer; font-family: inherit;
    }
    .sh-done { background: var(--accent); border: none; color: var(--accent-ink); font-weight: 700; }
    .sh-open { margin-right: auto; background: #2a2a2a; border: 1px solid #3a3a3a; color: #ccc; }
    .sh-open:hover { background: #333; color: #fff; }
    CSS;
}

/**
 * Wire it up. The page provides `window.shareData()` returning
 * `{cals: [[id, name], …], folders: [name, …], shares: {calendars, folders}}` —
 * a function rather than a snapshot so a page that reloads its calendars (the
 * Calendar's manage window does) always draws the current ones.
 */
function share_modal_script(string $csrf): string
{
    $csrf = htmlspecialchars($csrf, ENT_QUOTES);
    return <<<JS
<script>(function () {
  const modal = document.getElementById('shareModal'), open = document.getElementById('shareBtn');
  if (!modal || !open || typeof window.shareData !== 'function') { return; }
  const calList = document.getElementById('shareCals'), folList = document.getElementById('shareFolders');

  const post = (kind, key, on) =>
    fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: new URLSearchParams({ csrf: '{$csrf}', action: 'share_set', kind: kind, key: key, on: on ? 1 : 0 }) })
      .then(r => r.json())
      .then(j => { if (j && j.shares && window.onSharesChanged) { window.onSharesChanged(j.shares); } })
      .catch(() => location.reload());

  const row = (label, checked, onChange) => {
    const li = document.createElement('li');
    const cb = document.createElement('input');
    cb.type = 'checkbox'; cb.checked = checked;
    cb.addEventListener('change', () => onChange(cb.checked));
    const name = document.createElement('span');
    name.className = 'sh-name'; name.textContent = label;
    li.append(cb, name);
    li.addEventListener('click', e => { if (e.target !== cb) { cb.checked = !cb.checked; cb.dispatchEvent(new Event('change')); } });
    return li;
  };
  const empty = (text) => {
    const li = document.createElement('li');
    li.className = 'sh-empty'; li.textContent = text;
    return li;
  };

  window.shareRender = () => {
    const d = window.shareData() || {};
    const shares = d.shares || {}, cals = d.cals || [], folders = d.folders || [];
    calList.innerHTML = ''; folList.innerHTML = '';
    cals.forEach(([id, name]) => calList.appendChild(
      row(name, (shares.calendars || []).indexOf(id) !== -1, on => post('calendar', id, on))));
    folders.forEach(f => folList.appendChild(
      row(f, (shares.folders || []).indexOf(f) !== -1, on => post('folder', f, on))));
    if (!cals.length)    { calList.appendChild(empty('No calendars yet.')); }
    if (!folders.length) { folList.appendChild(empty('No reminder folders yet.')); }
  };

  open.addEventListener('click', () => { window.shareRender(); modal.classList.add('open'); });
  document.getElementById('shareDone').addEventListener('click', () => modal.classList.remove('open'));
  modal.addEventListener('click', e => { if (e.target === modal) { modal.classList.remove('open'); } });
})();</script>
JS;
}
