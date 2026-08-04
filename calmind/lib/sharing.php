<?php
/**
 * Sharing between two people.
 *
 * Nothing is copied: each person keeps owning their own encrypted files, and a
 * small shares-<user>.json says which of their calendars and reminder folders
 * the other one is allowed to see. The reader loads the owner's file directly.
 *
 * WHO shares with whom is opt-in and strictly mutual: each person keeps a list of
 * usernames in their own shares file (`partners`), and a partnership exists only
 * while BOTH lists name each other. That is checked fresh on every request in
 * share_partner()/share_partners() — the one gate every shared read goes through —
 * so the moment either person removes the other, all sharing between them stops.
 */

/**
 * The built-in pairs, kept as *seed* entries: a user who has never touched their
 * partner list acts as if it held their pair partner, so sean ⇄ aki and the demo pair
 * buddy ⇄ example (tools/seed-example.php, tools/seed-buddy.php) work out of the box.
 * Once a user saves a list of their own, only that list counts — deleting the seeded
 * name is how they opt out.
 */
const SHARE_PAIRS = ['sean' => 'aki', 'aki' => 'sean', 'buddy' => 'example', 'example' => 'buddy'];

/** A username as the partner list stores it: lowercase [a-z0-9_-], max 32, or ''. */
function share_username_clean(string $name): string
{
    $name = strtolower(trim($name));
    return preg_match('/^[a-z0-9_-]{1,32}$/', $name) ? $name : '';
}

/**
 * The usernames $user *wants* to share with — their own half of the handshake, not
 * yet the mutual check. Stored under `partners` in their shares file; a file that has
 * never carried the key falls back to the built-in pair, so the seed pairs keep
 * working with no migration. Cleaned, deduped, and never naming themselves.
 */
function share_partner_list(string $dir, string $user): array
{
    $user = strtolower($user);
    $raw  = store_read(user_data_file($dir, 'shares', $user));
    $list = array_key_exists('partners', $raw)
        ? (array) $raw['partners']
        : (isset(SHARE_PAIRS[$user]) ? [SHARE_PAIRS[$user]] : []);
    $out = [];
    foreach ($list as $p) {
        $p = share_username_clean(is_string($p) ? $p : '');
        if ($p !== '' && $p !== $user && !in_array($p, $out, true)) { $out[] = $p; }
    }
    return $out;
}

/** Persist $user's partner list, leaving every other bucket in the file alone. */
function share_partner_list_save(string $dir, string $user, array $list): void
{
    $file = user_data_file($dir, 'shares', strtolower($user));
    $raw  = store_read($file);
    $raw['partners'] = array_values($list);
    store_write($file, $raw);
}

/**
 * THE safeguard: a partnership exists only while each list names the other. Checked
 * against both stored files on every call, so it can never linger past a removal.
 */
function share_mutual(string $dir, string $a, string $b): bool
{
    $a = strtolower($a); $b = strtolower($b);
    return $a !== '' && $b !== '' && $a !== $b
        && in_array($b, share_partner_list($dir, $a), true)
        && in_array($a, share_partner_list($dir, $b), true);
}

/** Everyone $user is mutually partnered with, in their own list's order. */
function share_partners(?string $user = null): array
{
    $u = strtolower((string) ($user ?? current_user() ?? ''));
    if ($u === '') { return []; }
    $dir = app_config()['data_dir'];
    return array_values(array_filter(share_partner_list($dir, $u),
                                     fn($p) => share_mutual($dir, $u, $p)));
}

/**
 * The other person for $user (default: the signed-in user), or null if they have none.
 * The apps show one partner at a time, so this is the first mutual name on the list.
 */
function share_partner(?string $user = null): ?string
{
    return share_partners($user)[0] ?? null;
}

/**
 * Answer a posted partner_add / partner_rename / partner_del (AJAX) with the fresh
 * list, each name carrying whether the handshake is complete. Only the caller's own
 * list is ever written — the other side has to add you themselves, that's the point.
 */
function share_partner_post(string $dir, string $user, string $action): void
{
    $list = share_partner_list($dir, $user);
    $name = share_username_clean((string) ($_POST['name'] ?? ''));
    if ($action === 'partner_add' && $name !== '' && $name !== strtolower($user)) {
        if (!in_array($name, $list, true)) { $list[] = $name; }
        share_partner_list_save($dir, $user, $list);
    } elseif ($action === 'partner_rename' && $name !== '') {
        // Renaming an entry is retyping who you meant — the old name simply goes.
        $new = share_username_clean((string) ($_POST['newname'] ?? ''));
        if ($new !== '' && $new !== strtolower($user) && in_array($name, $list, true)) {
            $list = array_values(array_filter($list, fn($p) => $p !== $name && $p !== $new));
            $list[] = $new;
            share_partner_list_save($dir, $user, $list);
        }
    } elseif ($action === 'partner_del' && $name !== '' && !empty($_POST['confirm'])) {
        $list = array_values(array_filter($list, fn($p) => $p !== $name));
        share_partner_list_save($dir, $user, $list);
    }
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'partners' => share_partner_rows($dir, $user)]);
    exit;
}

/** The partner list as the window shows it: each name with its handshake state. */
function share_partner_rows(string $dir, string $user): array
{
    return array_map(fn($p) => ['name' => $p, 'mutual' => share_mutual($dir, $user, $p)],
                     share_partner_list($dir, $user));
}

/**
 * The three shareable kinds and the shares-file key each lives under. Calendars are ids,
 * reminder and note folders are names — kept in separate buckets so a reminder folder and
 * a note folder of the same name don't collide.
 */
const SHARE_KINDS = ['calendar' => 'calendars', 'folder' => 'folders', 'notefolder' => 'notes'];

/**
 * What $user has shared out, keyed by bucket:
 * ['calendars' => [calendar ids], 'folders' => [reminder folders], 'notes' => [note folders]].
 */
function shares_load(string $dir, string $user): array
{
    $d = store_read(user_data_file($dir, 'shares', $user));
    return [
        'calendars' => array_values(array_filter((array) ($d['calendars'] ?? []), 'is_string')),
        'folders'   => array_values(array_filter((array) ($d['folders'] ?? []), 'is_string')),
        'notes'     => array_values(array_filter((array) ($d['notes'] ?? []), 'is_string')),
    ];
}

function shares_save(string $dir, string $user, array $shares): void
{
    // The partner list lives in the same file but belongs to share_partner_list_save();
    // carry the stored one through so toggling a share can never drop a friendship —
    // and a file that never had the key keeps not having it (the seed-pair fallback).
    $file = user_data_file($dir, 'shares', $user);
    $keep = store_read($file);
    $out  = [
        'calendars' => array_values($shares['calendars'] ?? []),
        'folders'   => array_values($shares['folders'] ?? []),
        'notes'     => array_values($shares['notes'] ?? []),
    ];
    if (array_key_exists('partners', $keep)) { $out['partners'] = $keep['partners']; }
    store_write($file, $out);
}

/** Add or remove one key from a share list, and save. Returns the new list. */
function shares_toggle(string $dir, string $user, string $kind, string $key, bool $on): array
{
    $shares = shares_load($dir, $user);
    $k      = SHARE_KINDS[$kind] ?? 'folders';
    $shares[$k] = array_values(array_filter($shares[$k], fn($x) => $x !== $key));
    if ($on) { $shares[$k][] = $key; }
    shares_save($dir, $user, $shares);
    return $shares;
}

/** The note folders $partner has shared with the viewer, validated against what they own. */
function shared_note_folders(string $dir, ?string $partner): array
{
    if (!$partner) { return []; }
    return array_values(array_intersect(folders_load($dir, $partner)['notes'],
                                        shares_load($dir, $partner)['notes']));
}

/** The reminder folders $partner has shared with the viewer, validated against what they own. */
function shared_reminder_folders(string $dir, ?string $partner): array
{
    if (!$partner) { return []; }
    return array_values(array_intersect(folders_load($dir, $partner)['reminders'],
                                        shares_load($dir, $partner)['folders']));
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
 * Handle a posted `share_set` and answer with the new share list. $calIds, $folders and
 * $noteFolders are what this user actually owns — a key outside its kind's pool is
 * ignored, so the window can never share something that isn't theirs.
 */
function share_handle_set(string $dir, string $user, array $calIds, array $folders,
                          array $noteFolders = []): void
{
    $kind = (string) ($_POST['kind'] ?? '');
    $key  = (string) ($_POST['key'] ?? '');
    $pool = ['calendar' => $calIds, 'folder' => $folders, 'notefolder' => $noteFolders][$kind] ?? null;
    $shares = shares_load($dir, $user);
    if ($pool !== null && in_array($key, $pool, true)) {
        $shares = shares_toggle($dir, $user, $kind, $key, !empty($_POST['on']));
    }
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'shares' => $shares]);
    exit;
}

function share_modal_html(?string $partner): string
{
    // No partner yet: the window is just the doorway to the partner list, since there
    // is nobody to tick anything for. With one, the three share lists as ever.
    $body = $partner === null
        ? '<h2>Sharing</h2>'
        . '<p class="sh-hint">No sharing partner yet. Add someone&rsquo;s username with the pencil below — '
        . 'sharing switches on once they add yours back.</p>'
        : (function () use ($partner) {
            $who = htmlspecialchars(share_name($partner), ENT_QUOTES);
            return <<<HTML
        <h2>Shared with {$who}</h2>
        <p class="sh-hint">Ticked calendars, reminder folders and note folders show up in {$who}&rsquo;s apps. Nothing is copied — {$who} reads yours.</p>
        <h3>Calendars</h3>
        <ul class="sh-list" id="shareCals"></ul>
        <h3>Reminders</h3>
        <ul class="sh-list" id="shareFolders"></ul>
        <h3>Notes</h3>
        <ul class="sh-list" id="shareNotes"></ul>
    HTML;
        })();
    $pencil = pencil_icon_svg();
    return <<<HTML
    <div class="modal-backdrop" id="shareModal">
      <div class="sh-modal">
        {$body}
        <div class="sh-actions">
          <button type="button" class="sh-editp" id="shareEditBtn" title="Sharing partners" aria-label="Sharing partners">{$pencil}</button>
          <button type="button" class="sh-done" id="shareDone">Done</button>
        </div>
      </div>
    </div>
    <div class="modal-backdrop" id="partnerModal">
      <div class="sh-modal">
        <h2>Sharing partners</h2>
        <p class="sh-hint">Sharing is strictly two-way: it only switches on between you and a person when <strong>both</strong> of you have added the other&rsquo;s username here, and it switches off the moment either of you removes it.</p>
        <ul class="sh-list sh-plist" id="partnerRows"></ul>
        <form class="sh-addrow" id="partnerAddForm">
          <input type="text" id="partnerAddName" placeholder="Their username" maxlength="32"
                 autocomplete="off" autocapitalize="none" spellcheck="false" aria-label="Username to share with">
          <button type="submit" class="sh-plus" title="Add" aria-label="Add">+</button>
        </form>
        <div class="sh-actions"><button type="button" class="sh-done" id="partnerDone">Done</button></div>
      </div>
    </div>
    HTML;
}

/** The button that opens it — sits beside the Done of whichever window it's in. */
function share_button_html(): string
{
    // An icon, sized and shaped like the other two actions in the settings footer.
    return '<button type="button" class="sh-open setact" id="shareBtn" title="Share" aria-label="Share">'
         . '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"'
         . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
         . '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/>'
         . '<path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/></svg></button>';
}

function share_modal_styles(): string
{
    return <<<'CSS'
    .sh-modal {
      background: var(--surface); border: 1px solid var(--line); border-radius: 12px; padding: 1.25rem;
      width: 100%; max-width: 380px; max-height: 85vh; overflow-y: auto;
    }
    .sh-modal h2 { font-size: 1.05rem; margin: 0 0 0.4rem; }
    .sh-modal h3 { font-size: 0.9rem; margin: 1rem 0 0.5rem; color: var(--text-dim); }
    .sh-hint { font-size: 0.78rem; color: var(--muted); margin: 0 0 0.4rem; line-height: 1.4; }
    .sh-list { list-style: none; display: flex; flex-direction: column; gap: 0.4rem; margin: 0; padding: 0; }
    .sh-list li {
      display: flex; align-items: center; gap: 0.6rem; padding: 0.5rem 0.6rem;
      background: var(--surface-2); border: 1px solid var(--line); border-radius: 8px; cursor: pointer;
    }
    .sh-list .sh-name { flex: 1; font-size: 0.95rem; word-break: break-word; }
    .sh-list input[type=checkbox] { width: 18px; height: 18px; accent-color: var(--accent); flex: 0 0 auto; }
    .sh-list .sh-empty { color: var(--muted); font-size: 0.85rem; background: none; border: none; cursor: default; }
    .sh-actions { display: flex; align-items: center; justify-content: flex-end; margin-top: 1.1rem; }
    .sh-done, .sh-open {
      padding: 0.35rem 0.9rem; font-size: 0.9rem; border-radius: 999px; cursor: pointer; font-family: inherit;
    }
    .sh-done { background: var(--accent); border: none; color: var(--accent-ink); font-weight: 700; }
    .sh-open { margin-right: auto; background: var(--surface-2); border: 1px solid #3a3a3a; color: var(--text-dim); }
    .sh-open:hover { background: var(--surface-2); color: var(--text); }
    /* The pencil to the left of Done: opens the partner list. */
    .sh-editp {
      margin-right: auto; display: inline-flex; align-items: center; justify-content: center;
      width: 32px; height: 32px; padding: 0; background: var(--surface-2);
      border: 1px solid var(--line); color: var(--text-dim); border-radius: 999px;
      cursor: pointer; font-family: inherit;
    }
    .sh-editp:hover { border-color: var(--muted); color: var(--text); }
    /* Partner rows: name, handshake badge, then rename pencil and delete ×. */
    .sh-plist li { cursor: default; }
    .sh-plist .sh-pname { flex: 1; font-size: 0.95rem; word-break: break-word; }
    .sh-plist input.sh-pname {
      background: var(--surface); border: 1px solid #3a3a3a; border-radius: 6px;
      color: var(--text); padding: 0.2rem 0.4rem; min-width: 0;
      font-size: 16px; font-family: inherit;   /* 16px stops iOS from zooming on focus */
    }
    .sh-plist input.sh-pname:focus { outline: none; border-color: var(--accent); }
    .sh-badge {
      flex: 0 0 auto; font-size: 0.68rem; border-radius: 999px; padding: 0.08rem 0.45rem;
      border: 1px solid transparent; white-space: nowrap;
    }
    .sh-badge.linked { color: var(--accent); background: var(--accent-soft); border-color: var(--accent); }
    .sh-badge.waiting { color: var(--muted); border-color: var(--line); }
    .sh-prename, .sh-pdel {
      flex: 0 0 auto; display: inline-flex; align-items: center; justify-content: center;
      width: 26px; height: 26px; padding: 0;
      background: none; border: 1px solid var(--line); color: var(--muted); border-radius: 50%;
      font-size: 0.9rem; line-height: 1; cursor: pointer; font-family: inherit;
    }
    .sh-prename:hover { border-color: var(--muted); color: var(--text-dim); }
    .sh-pdel:hover, .sh-pdel.armed { border-color: #f66; color: #f66; }
    .sh-pdel.armed { background: rgba(255, 102, 102, 0.15); }
    .sh-addrow { display: flex; gap: 0.5rem; margin-top: 0.6rem; }
    .sh-addrow input {
      flex: 1; min-width: 0; padding: 0.5rem 0.7rem; background: var(--surface-2);
      border: 1px solid #3a3a3a; border-radius: 6px; color: var(--text);
      font-size: 16px; font-family: inherit;   /* 16px stops iOS from zooming on focus */
    }
    .sh-addrow input:focus { outline: none; border-color: var(--muted); }
    .sh-plus {
      flex: 0 0 auto; width: 38px; height: 38px; align-self: center;
      display: inline-flex; align-items: center; justify-content: center;
      background: var(--accent); color: var(--accent-ink); border: none; border-radius: 50%;
      font-size: 1.2rem; font-weight: 700; cursor: pointer; font-family: inherit; padding: 0;
    }
    .sh-plus:hover { filter: brightness(1.1); }
    CSS;
}

/**
 * Wire it up. The page provides `window.shareData()` returning
 * `{cals: [[id, name], …], folders: [name, …], shares: {calendars, folders}}` —
 * a function rather than a snapshot so a page that reloads its calendars (the
 * Calendar's manage window does) always draws the current ones.
 */
function share_modal_script(string $csrf, array $partnerRows = []): string
{
    $csrf   = htmlspecialchars($csrf, ENT_QUOTES);
    $prows  = json_encode($partnerRows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $pencil = json_encode(pencil_icon_svg());
    return <<<JS
<script>(function () {
  const modal = document.getElementById('shareModal'), open = document.getElementById('shareBtn');
  if (!modal || !open) { return; }

  // ---- The partner list behind the pencil: who I'd share with, strictly mutual. ----
  const pModal = document.getElementById('partnerModal');
  let PARTNERS = {$prows};
  const pPost = (params) =>
    fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: new URLSearchParams(Object.assign({ csrf: '{$csrf}' }, params)) })
      .then(r => r.json())
      .then(j => {
        if (j && j.partners) { PARTNERS = j.partners; renderPartners(); }
      })
      .catch(() => location.reload());
  const pRows = document.getElementById('partnerRows');
  function renderPartners() {
    if (!pRows) { return; }
    pRows.innerHTML = '';
    if (!PARTNERS.length) {
      const li = document.createElement('li');
      li.className = 'sh-empty'; li.textContent = 'Nobody yet — add a username below.';
      pRows.appendChild(li);
      return;
    }
    PARTNERS.forEach(p => {
      const li = document.createElement('li');
      const name = document.createElement('span');
      name.className = 'sh-pname'; name.textContent = p.name;
      const badge = document.createElement('span');
      badge.className = 'sh-badge ' + (p.mutual ? 'linked' : 'waiting');
      badge.textContent = p.mutual ? 'sharing' : 'waiting for them';
      badge.title = p.mutual ? 'You have both added each other'
                             : 'Sharing starts when they add your username too';
      // Rename: the pencil swaps the name for a field; Enter/blur commits, Escape reverts.
      const pen = document.createElement('button');
      pen.type = 'button'; pen.className = 'sh-prename'; pen.title = 'Edit name'; pen.setAttribute('aria-label', 'Edit name');
      pen.innerHTML = {$pencil};
      pen.addEventListener('click', () => {
        const inp = document.createElement('input');
        inp.className = 'sh-pname'; inp.maxLength = 32; inp.value = p.name;
        inp.autocapitalize = 'none'; inp.spellcheck = false; inp.setAttribute('aria-label', 'Username');
        name.replaceWith(inp); pen.disabled = true;
        let done = false;
        const commit = () => {
          if (done) { return; } done = true;
          const v = inp.value.trim().toLowerCase();
          if (v === '' || v === p.name) { renderPartners(); return; }
          pPost({ action: 'partner_rename', name: p.name, newname: v });
        };
        inp.addEventListener('keydown', ev => {
          if (ev.key === 'Enter') { ev.preventDefault(); commit(); }
          if (ev.key === 'Escape') { done = true; renderPartners(); }
        });
        inp.addEventListener('blur', commit);
        inp.focus(); inp.select();
      });
      // Delete is the suite's two-press gesture: first press arms it red, second removes.
      const del = document.createElement('button');
      del.type = 'button'; del.className = 'sh-pdel'; del.textContent = '×';
      del.title = 'Remove'; del.setAttribute('aria-label', 'Remove ' + p.name);
      del.addEventListener('click', () => {
        if (!del.classList.contains('armed')) {
          del.classList.add('armed');
          setTimeout(() => del.classList.remove('armed'), 3000);
          return;
        }
        pPost({ action: 'partner_del', name: p.name, confirm: 1 });
      });
      li.append(name, badge, pen, del);
      pRows.appendChild(li);
    });
  }
  const pEdit = document.getElementById('shareEditBtn');
  if (pEdit && pModal) {
    pEdit.addEventListener('click', () => {
      renderPartners();
      modal.classList.remove('open');       // the partner window replaces the share one
      pModal.classList.add('open');
    });
    const addForm = document.getElementById('partnerAddForm'), addName = document.getElementById('partnerAddName');
    addForm.addEventListener('submit', e => {
      e.preventDefault();
      const v = addName.value.trim().toLowerCase();
      if (v === '') { return; }
      addName.value = '';
      pPost({ action: 'partner_add', name: v });
    });
    // Done reloads: the page's partner (and everything drawn from it) may just have changed.
    document.getElementById('partnerDone').addEventListener('click', () => location.reload());
    pModal.addEventListener('click', e => { if (e.target === pModal) { location.reload(); } });
  }

  // ---- The share lists themselves (only on a page with a partner to share with:
  // without one the window has no lists to fill, however the page defines shareData). ----
  if (typeof window.shareData !== 'function' || !document.getElementById('shareCals')) {
    open.addEventListener('click', () => modal.classList.add('open'));
    const doneBtn = document.getElementById('shareDone');
    if (doneBtn) { doneBtn.addEventListener('click', () => modal.classList.remove('open')); }
    modal.addEventListener('click', e => { if (e.target === modal) { modal.classList.remove('open'); } });
    return;
  }
  const calList = document.getElementById('shareCals'), folList = document.getElementById('shareFolders'),
        noteList = document.getElementById('shareNotes');

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
    const shares = d.shares || {}, cals = d.cals || [], folders = d.folders || [], notes = d.notefolders || [];
    calList.innerHTML = ''; folList.innerHTML = '';
    if (noteList) { noteList.innerHTML = ''; }
    cals.forEach(([id, name]) => calList.appendChild(
      row(name, (shares.calendars || []).indexOf(id) !== -1, on => post('calendar', id, on))));
    folders.forEach(f => folList.appendChild(
      row(f, (shares.folders || []).indexOf(f) !== -1, on => post('folder', f, on))));
    if (noteList) {
      notes.forEach(f => noteList.appendChild(
        row(f, (shares.notes || []).indexOf(f) !== -1, on => post('notefolder', f, on))));
      if (!notes.length) { noteList.appendChild(empty('No note folders yet.')); }
    }
    if (!cals.length)    { calList.appendChild(empty('No calendars yet.')); }
    if (!folders.length) { folList.appendChild(empty('No reminder folders yet.')); }
  };

  open.addEventListener('click', () => { window.shareRender(); modal.classList.add('open'); });
  document.getElementById('shareDone').addEventListener('click', () => modal.classList.remove('open'));
  modal.addEventListener('click', e => { if (e.target === modal) { modal.classList.remove('open'); } });
})();</script>
JS;
}
