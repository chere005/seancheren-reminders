<?php
/**
 * Shared top-of-page chrome, so every app header looks the same:
 *   - a back (<) button in the top-left that returns to the previous screen
 *   - the username as a dropdown that holds "Log out"
 *
 * Usage in an app page:
 *   <head>:            <?= chrome_styles() ?>   (inside the <style> block)
 *   header left side:  <div class="hleft"><?= back_button() ?> …title… </div>
 *   header right side: <?= render_user_menu() ?>
 *   before </body>:    <?= chrome_script() ?>
 */

function chrome_styles(): string
{
    return <<<CSS
    /* The top bar: back, the app's name, its one round button, then the username on
       the right. Everything on it is 32px tall and sits on the same line, with a
       rule under the lot, and the same small gap under that rule in every app.
       Apps only supply what goes inside. */
    header { border-bottom: 1px solid #262626; padding-bottom: 0.7rem; margin-bottom: 0.5rem; }
    .hleft { display: flex; align-items: center; gap: 0.5rem; min-width: 0; }
    .hright { display: flex; align-items: center; gap: 0.8rem; flex: 0 0 auto; }
    .backbtn, .titlebtn, .usermenu .who {
      height: 32px; display: inline-flex; align-items: center; justify-content: center;
      border: 1px solid #333; border-radius: 999px; background: none; color: #ccc;
      font-family: inherit; line-height: 1; cursor: pointer; flex: 0 0 auto;
    }
    .backbtn { width: 32px; background: #1a1a1a; font-size: 1.35rem; padding: 0; }
    .backbtn:hover { border-color: #888; color: #fff; }
    /* The "+" (or pencil) beside the app's name. */
    .titlebtn { width: 32px; font-size: 1.05rem; }
    .titlebtn:hover { border-color: #34d399; color: #34d399; }
    body.editing .titlebtn.edit-toggle { background: #34d399; border-color: #34d399; color: #06251b; }
    .usermenu { position: relative; flex: 0 0 auto; }
    .usermenu .who { margin: 0; padding: 0 0.8rem; color: #34d399; font-size: 0.85rem; border-color: #2a4a3d; }
    .usermenu .who:hover { border-color: #34d399; color: #34d399; }
    .usermenu .menu {
      position: absolute; right: 0; top: calc(100% + 6px); z-index: 40; background: #1c1c1c;
      border: 1px solid #333; border-radius: 8px; min-width: 120px; box-shadow: 0 8px 20px rgba(0,0,0,0.5); overflow: hidden;
    }
    .usermenu .menu a { display: block; margin: 0; padding: 0.6rem 0.9rem; color: #eee; text-decoration: none; font-size: 0.9rem; }
    .usermenu .menu a:hover { background: #2a2a2a; color: #fff; }
    /* Edit — a pencil, as on every other Edit in the suite — sits to the left of
       the username, wearing the same pill. */
    .usercol { display: flex; align-items: center; gap: 0.35rem; flex: 0 0 auto; }
    .hedit {
      margin: 0; color: #ccc; font-size: 0.95rem; background: none; border: 1px solid #333;
      border-radius: 999px; padding: 0.2rem 0.6rem; cursor: pointer; font-family: inherit;
      line-height: 1.2;
    }
    .hedit:hover { border-color: #888; color: #fff; }
    body.editing .hedit { background: #34d399; border-color: #34d399; color: #06251b; font-weight: 700; }
    /* The same toggle again, small enough to sit beside a section's "+". */
    .sec-edit {
      flex: 0 0 auto; background: none; border: 1px solid #333; color: #888; border-radius: 999px;
      width: 24px; height: 24px; font-size: 0.8rem; line-height: 1; cursor: pointer;
      font-family: inherit; display: inline-flex; align-items: center; justify-content: center;
    }
    .sec-edit:hover { border-color: #888; color: #ccc; }
    body.editing .sec-edit { background: #34d399; border-color: #34d399; color: #06251b; }
    CSS
    . settings_modal_styles()
    . confirm_delete_styles();
}

function back_button(): string
{
    return '<button type="button" class="backbtn" onclick="history.back()" aria-label="Back">&lsaquo;</button>';
}

/**
 * The username chip, the settings "⋮" to its left, and optionally the app's Edit
 * toggle (a pencil) beside them. $editId is the button id the page's own script
 * already listens on.
 */
function render_user_menu(bool $withEdit = false, string $editId = 'editBtn'): string
{
    $u    = htmlspecialchars(current_user() ?? '', ENT_QUOTES);
    // One flex child, because the header is space-between: loose buttons would be
    // spread across the row rather than gathered on the right.
    $menu = '<div class="usercol">' . settings_button()
          . '<div class="usermenu"><button type="button" class="who" id="userBtn">' . $u . ' &#9662;</button>'
          . '<div class="menu" id="userMenu" hidden><a href="?logout">Log out</a></div></div></div>';
    $edit = $withEdit
        ? '<button type="button" class="hedit" title="Edit" aria-label="Edit" id="'
          . htmlspecialchars($editId, ENT_QUOTES) . '">&#9998;&#65038;</button>'
        : '';
    return '<div class="hright">' . $edit . $menu . '</div>' . settings_modal_html();
}

/**
 * User settings: the "⋮" that sits left of the username, and the window behind it.
 * The only thing in there so far is changing your own password, which posts
 * `change_password` to whatever page you're on — require_login() answers it, so no
 * app has to wire anything up. Pages that don't use chrome_styles()/chrome_script()
 * (Aki's Bookshelf) emit the three helpers themselves.
 */
function settings_button(): string
{
    return '<button type="button" class="setbtn" id="setBtn" title="Settings" aria-label="Settings">&#8942;</button>';
}

function settings_modal_html(): string
{
    $csrf = htmlspecialchars($_SESSION['csrf'] ?? '', ENT_QUOTES);
    $u    = htmlspecialchars(current_user() ?? '', ENT_QUOTES);
    return <<<HTML
<div class="setmodal-backdrop" id="setBackdrop">
  <div class="setmodal">
    <h2>Settings</h2>
    <p class="setwho">Signed in as <strong>{$u}</strong></p>
    <form id="pwForm" autocomplete="off">
      <input type="hidden" name="csrf" value="{$csrf}">
      <input type="hidden" name="action" value="change_password">
      <label>Current password<input type="password" name="current" autocomplete="current-password"></label>
      <label>New password<input type="password" name="new" autocomplete="new-password"></label>
      <label>Repeat new password<input type="password" name="again" autocomplete="new-password"></label>
      <p class="setmsg" id="setMsg" hidden></p>
      <button type="submit" class="setsave">Change password</button>
    </form>
    <button type="button" class="setdone" id="setDone">Done</button>
  </div>
</div>
HTML;
}

function settings_modal_styles(): string
{
    return <<<CSS
    /* The "⋮" wears the username's pill, one notch narrower. Its own class, not
       ".dots" — the Calendar's day cells already use that for their dot row. */
    .setbtn {
      height: 32px; width: 26px; display: inline-flex; align-items: center; justify-content: center;
      background: none; border: 1px solid #333; border-radius: 999px; color: #ccc;
      font-family: inherit; font-size: 1.1rem; line-height: 1; cursor: pointer; flex: 0 0 auto;
    }
    .setbtn:hover { border-color: #888; color: #fff; }
    /* Same shape as the calendar and folder managers. */
    .setmodal-backdrop {
      position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 70;
      display: none; align-items: center; justify-content: center; padding: 1rem;
    }
    .setmodal-backdrop.open { display: flex; }
    .setmodal {
      background: #1a1a1a; border: 1px solid #333; border-radius: 12px; text-align: left;
      width: 100%; max-width: 340px; padding: 1.25rem; max-height: 85vh; overflow-y: auto;
    }
    .setmodal h2 { font-size: 1.05rem; margin-bottom: 0.4rem; }
    .setmodal .setwho { font-size: 0.8rem; color: #888; margin-bottom: 1rem; }
    .setmodal label { display: block; font-size: 0.78rem; color: #aaa; margin-bottom: 0.7rem; }
    .setmodal input[type=password] {
      display: block; width: 100%; margin-top: 0.25rem; padding: 0.45rem 0.75rem;
      background: #222; border: 1px solid #444; border-radius: 6px; color: #eee;
      font-size: 16px; font-family: inherit;
    }
    .setmodal input[type=password]:focus { outline: none; border-color: #888; }
    .setmodal .setmsg { font-size: 0.8rem; margin-bottom: 0.7rem; color: #f66; }
    .setmodal .setmsg.ok { color: #34d399; }
    .setmodal .setsave {
      background: #34d399; color: #06251b; border: none; border-radius: 999px; font-weight: 700;
      padding: 0.35rem 0.9rem; font-size: 0.9rem; cursor: pointer; font-family: inherit;
    }
    .setmodal .setsave:hover { background: #52e0ac; }
    .setmodal .setdone {
      display: block; margin: 1.1rem 0 0 auto; padding: 0.35rem 0.9rem; border: 1px solid #444;
      background: none; color: #ccc; border-radius: 999px; font-size: 0.9rem; cursor: pointer;
      font-family: inherit;
    }
    .setmodal .setdone:hover { border-color: #888; color: #fff; }
    CSS;
}

function settings_modal_script(): string
{
    return <<<'JS'
<script>(function () {
  var btn = document.getElementById('setBtn'), back = document.getElementById('setBackdrop');
  if (!btn || !back) { return; }
  var form = document.getElementById('pwForm'), msg = document.getElementById('setMsg');
  var show = function (text, ok) { msg.textContent = text; msg.classList.toggle('ok', !!ok); msg.hidden = !text; };
  var close = function () { back.classList.remove('open'); form.reset(); show(''); };
  btn.addEventListener('click', function () { back.classList.add('open'); });
  document.getElementById('setDone').addEventListener('click', close);
  back.addEventListener('click', function (e) { if (e.target === back) { close(); } });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { close(); } });
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var d = new FormData(form);
    if (String(d.get('new')) !== String(d.get('again'))) { show('The new passwords do not match.'); return; }
    d.delete('again');
    fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: new URLSearchParams(d) })
      .then(function (r) { return r.json(); })
      .then(function (r) {
        if (r && r.ok) { form.reset(); show('Password changed.', true); }
        else { show((r && r.error) || 'Could not change it.'); }
      })
      .catch(function () { show('Could not change it.'); });
  });
})();</script>
JS;
}

/**
 * A second Edit toggle, sized to sit beside a section's "+". It clicks the header's
 * Edit button rather than toggling anything itself, so whatever Edit means on a
 * given page (each app layers its own behaviour on) stays in one place.
 */
function section_edit_button(): string
{
    // U+FE0E forces the text pencil rather than the colour emoji one.
    return '<button type="button" class="sec-edit" title="Edit" aria-label="Edit">&#9998;&#65038;</button>';
}

/**
 * Two-press delete, used everywhere instead of a confirm() box or an Undo button.
 * Mark any delete control `needs-confirm`: the first press arms it (fills red), the
 * second goes through. Arming disarms itself after a few seconds, and only one
 * control is ever armed, so a stray tap can't leave a landmine somewhere off screen.
 *
 * Emit these in pages that don't already use chrome_styles()/chrome_script().
 */
function confirm_delete_styles(): string
{
    return <<<CSS
    .needs-confirm.armed {
      background: #b3261e; border-color: #f66; color: #fff; font-weight: 700;
    }
    .needs-confirm.armed:hover { background: #d0342c; border-color: #f88; color: #fff; }
    CSS;
}

function confirm_delete_script(): string
{
    // Capture phase, so this runs before the page's own submit/click handlers.
    return <<<'JS'
<script>(function () {
  var armed = null, timer = null;
  function disarm() {
    if (timer) { clearTimeout(timer); timer = null; }
    if (armed) { armed.classList.remove('armed'); armed.textContent = armed.dataset.label; armed = null; }
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('.needs-confirm');
    if (!b) { disarm(); return; }          // tapping anything else calls it off
    if (b === armed) {                     // second press: let the click through
      // Tell the server this was confirmed. Destructive handlers refuse without it,
      // so a stale or broken page can't delete anything on a single tap.
      if (b.form && !b.form.querySelector('input[name="confirm"]')) {
        var c = document.createElement('input');
        c.type = 'hidden'; c.name = 'confirm'; c.value = '1';
        b.form.appendChild(c);
      }
      disarm();
      return;
    }
    e.preventDefault();
    e.stopPropagation();
    disarm();
    armed = b;
    b.dataset.label = b.textContent;
    if (b.dataset.confirm) { b.textContent = b.dataset.confirm; }
    b.classList.add('armed');
    timer = setTimeout(disarm, 4000);
  }, true);
})();</script>
JS;
}

/**
 * Carry edit mode through a POST. Any form submitted while editing picks up an
 * `edit` field, so the handler's redirect can hand edit mode back and you aren't
 * dropped out of it just for adding something.
 */
function keep_edit_script(): string
{
    return <<<'JS'
<script>document.addEventListener('submit', function (e) {
  var f = e.target;
  if (!f || f.tagName !== 'FORM') { return; }
  if (!document.body.classList.contains('editing')) { return; }
  if (f.querySelector('input[name="edit"]')) { return; }
  var i = document.createElement('input');
  i.type = 'hidden'; i.name = 'edit'; i.value = '1';
  f.appendChild(i);
}, true);</script>
JS;
}

function chrome_script(): string
{
    return "<script>(function(){var b=document.getElementById('userBtn'),m=document.getElementById('userMenu');"
         . "if(b&&m){b.addEventListener('click',function(e){e.stopPropagation();m.hidden=!m.hidden;});"
         . "document.addEventListener('click',function(e){if(!m.hidden&&!m.contains(e.target)){m.hidden=true;}});}"
         // A page can define window.sectionEditToggle to treat the section pencils
         // differently from the header button; otherwise they just click it.
         . "var eb=document.getElementById('editBtn');if(eb){"
         . "document.querySelectorAll('.sec-edit').forEach(function(s){"
         . "s.addEventListener('click',function(){"
         . "if(window.sectionEditToggle){window.sectionEditToggle();}else{eb.click();}});});}})();</script>"
         . settings_modal_script()
         . confirm_delete_script()
         . keep_edit_script();
}
