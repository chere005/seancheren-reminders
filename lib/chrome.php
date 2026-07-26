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
    return theme_css() . <<<CSS
    /* The top bar: back, the app's name, its one round button, then the username on
       the right. Everything on it is 32px tall and sits on the same line, with a
       rule under the lot, and the same small gap under that rule in every app.
       Apps only supply what goes inside. */
    header { border-bottom: 1px solid #262626; padding-bottom: 0.7rem; margin-bottom: 0.5rem; }
    .hleft { display: flex; align-items: center; gap: 0.75rem; min-width: 0; }
    .hright { display: flex; align-items: center; gap: 0.75rem; flex: 0 0 auto; }
    .backbtn, .titlebtn, .usermenu .who {
      height: 32px; display: inline-flex; align-items: center; justify-content: center;
      border: 1px solid #333; border-radius: 999px; background: none; color: #ccc;
      font-family: inherit; line-height: 1; cursor: pointer; flex: 0 0 auto;
    }
    .backbtn { width: 32px; background: #1a1a1a; font-size: 1.35rem; padding: 0; }
    .backbtn:hover { border-color: #888; color: #fff; }
    /* The "+" (or pencil) beside the app's name. */
    .titlebtn { width: 32px; font-size: 1.05rem; }
    .titlebtn:hover { border-color: var(--accent); color: var(--accent); }
    body.editing .titlebtn.edit-toggle { background: var(--accent); border-color: var(--accent); color: var(--accent-ink); }
    .usermenu { position: relative; flex: 0 0 auto; }
    .usermenu .who { margin: 0; padding: 0 0.8rem; color: var(--accent); font-size: 0.85rem; border-color: #2a4a3d; }
    .usermenu .who:hover { border-color: var(--accent); color: var(--accent); }
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
    body.editing .hedit { background: var(--accent); border-color: var(--accent); color: var(--accent-ink); font-weight: 700; }
    /* The same toggle again, small enough to sit beside a section's "+". */
    .sec-edit {
      flex: 0 0 auto; background: none; border: 1px solid #333; color: #888; border-radius: 999px;
      width: 24px; height: 24px; font-size: 0.8rem; line-height: 1; cursor: pointer;
      font-family: inherit; display: inline-flex; align-items: center; justify-content: center;
    }
    .sec-edit:hover { border-color: #888; color: #ccc; }
    body.editing .sec-edit { background: var(--accent); border-color: var(--accent); color: var(--accent-ink); }
    CSS
    . settings_modal_styles()
    . confirm_delete_styles()
    . swipe_delete_styles()
    . section_rename_styles();
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
    $now  = theme_get();
    $themes = '';
    foreach (THEMES as $key => [$label, $accent, $ink, $soft]) {
        $on = $key === $now ? ' on' : '';
        $themes .= '<button type="button" class="themebtn' . $on . '" data-theme="' . $key . '"'
                 . ' style="background:' . $accent . '" title="' . $label . '" aria-label="' . $label . '"></button>';
    }
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
    <div class="setthemes">
      <p class="setlabel">Theme</p>
      <div class="themerow">{$themes}</div>
    </div>
    <button type="button" class="setdone" id="setDone">Done</button>
  </div>
</div>
HTML;
}

function settings_modal_styles(): string
{
    return <<<CSS
    .setmodal .setthemes { margin-top: 1.1rem; }
    .setmodal .setlabel { font-size: 0.8rem; color: #aaa; margin-bottom: 0.5rem; }
    .setmodal .themerow { display: flex; gap: 0.5rem; }
    .setmodal .themebtn {
      width: 28px; height: 28px; border-radius: 50%; border: 2px solid transparent;
      cursor: pointer; padding: 0;
    }
    .setmodal .themebtn.on { border-color: #eee; }
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
    .setmodal .setmsg.ok { color: var(--accent); }
    .setmodal .setsave {
      background: var(--accent); color: var(--accent-ink); border: none; border-radius: 999px; font-weight: 700;
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
  // Themes: post the pick, then reload so every rule picks the new accent up.
  back.querySelectorAll('.themebtn').forEach(function (t) {
    t.addEventListener('click', function () {
      var body = new URLSearchParams({ csrf: form.csrf.value, action: 'set_theme', theme: t.dataset.theme });
      fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body })
        .then(function () { location.reload(); })
        .catch(function () { location.reload(); });
    });
  });
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
 * A section's name, editable in place. Out of edit mode it reads as plain text; in
 * edit mode it's a field that posts `rename_section` on Enter or on leaving it. The
 * permanent groups pass $fixed, since they can't be renamed.
 */
function section_title_html(string $name, string $csrf, string $view = '', bool $fixed = false,
                            string $action = 'rename_section', string $extra = ''): string
{
    $e = fn($x) => htmlspecialchars((string) $x, ENT_QUOTES);
    if ($fixed) {
        return '<span class="section-title">' . $e($name) . '</span>';
    }
    return '<form method="post" action="" class="secrename">'
         . '<input type="hidden" name="csrf" value="' . $e($csrf) . '">'
         . '<input type="hidden" name="action" value="' . $e($action) . '">'
         . '<input type="hidden" name="view" value="' . $e($view) . '">'
         . '<input type="hidden" name="name" value="' . $e($name) . '">' . $extra
         . '<input class="section-title sectitle" name="newname" value="' . $e($name) . '"'
         . ' size="' . max(4, min(30, mb_strlen($name))) . '" maxlength="40" autocomplete="off"'
         . ' aria-label="Section name"></form>';
}

function section_rename_styles(): string
{
    return <<<CSS
    /* It is a form, but it is the section's *name* — it must not pick up the
       `margin-left: auto` the apps give the other forms in a section head. */
    .section-head form.secrename { margin-left: 0; }
    .secrename { display: inline-flex; min-width: 0; }
    .sectitle {
      background: none; border: none; border-bottom: 1px solid transparent; padding: 0;
      color: #f0b429; font-family: inherit; font-weight: 700; font-size: 1.15rem;
      min-width: 0; max-width: 100%;
    }
    /* Only a field once you're editing — otherwise it's just the section's name. */
    body:not(.editing) .sectitle { pointer-events: none; }
    body.editing .sectitle { border-bottom-color: #3a3a3a; }
    .sectitle:focus { outline: none; border-bottom-color: #f0b429; }
    CSS;
}

function section_rename_script(): string
{
    return <<<'JS'
<script>
// A `size` in characters is only ever approximately the width of the name, so a
// renameable section sat further from its buttons than a fixed one. Measure the
// text and give the field exactly that width, on load and as it's typed.
(function () {
  var ruler = null;
  function fit(i) {
    if (!ruler) {
      ruler = document.createElement('span');
      ruler.style.cssText = 'position:absolute;visibility:hidden;white-space:pre;left:-9999px;top:0';
      document.body.appendChild(ruler);
    }
    var cs = getComputedStyle(i);
    ruler.style.font = cs.font;
    ruler.style.letterSpacing = cs.letterSpacing;
    ruler.textContent = i.value || ' ';
    i.style.width = (ruler.offsetWidth + 2) + 'px';
  }
  function fitAll() { document.querySelectorAll('.sectitle').forEach(fit); }
  document.addEventListener('input', function (e) {
    if (e.target.classList && e.target.classList.contains('sectitle')) { fit(e.target); }
  });
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', fitAll); }
  else { fitAll(); }
  if (document.fonts && document.fonts.ready) { document.fonts.ready.then(fitAll); }
})();
document.addEventListener('focusout', function (e) {
  var i = e.target;
  if (!i.classList || !i.classList.contains('sectitle')) { return; }
  var was = i.form.querySelector('input[name="name"]').value;
  if (i.value.trim() !== '' && i.value !== was) { i.form.submit(); }
  else { i.value = was; }
});
document.addEventListener('keydown', function (e) {
  var i = e.target;
  if (!i.classList || !i.classList.contains('sectitle')) { return; }
  if (e.key === 'Enter') { e.preventDefault(); i.blur(); }
  if (e.key === 'Escape') { i.value = i.form.querySelector('input[name="name"]').value; i.blur(); }
});</script>
JS;
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

/**
 * Swipe a row left to reveal its delete button — the way to delete one thing without
 * turning edit mode on for the whole list. Mark the row `swipe-row`; its delete
 * control (a `needs-confirm` one) is revealed by the `swiped` class the gesture adds.
 * The swipe itself counts as the first press, so the revealed button deletes on a tap.
 * Edit mode owns the row instead (that's where dragging lives), so it stands down there.
 */
function swipe_delete_styles(): string
{
    return <<<CSS
    .swipe-row { touch-action: pan-y; transition: transform 0.16s ease; }
    .swipe-row.swiped .needs-confirm {
      display: inline-flex; align-items: center; justify-content: center;
      background: #b3261e; border-color: #f66; color: #fff; font-weight: 700;
    }
    CSS;
}

function swipe_delete_script(): string
{
    return <<<'JS'
<script>(function () {
  var row = null, sx = 0, sy = 0, dx = 0, dir = 0, open = null;
  var LIMIT = 84, TRIGGER = 56;
  // Touch only: on a desktop a swipe is a text selection or a stray mouse drag, and
  // there's an Edit mode right there for deleting things.
  if (!window.matchMedia || !window.matchMedia('(pointer: coarse)').matches) { return; }
  function close(r) {
    if (!r) { return; }
    r.classList.remove('swiped');
    r.style.transition = ''; r.style.transform = '';
    if (open === r) { open = null; }
  }
  document.addEventListener('pointerdown', function (e) {
    if (document.body.classList.contains('editing')) { return; }   // dragging owns the row there
    var r = e.target.closest && e.target.closest('.swipe-row');
    if (open && open !== r) { close(open); }
    if (!r || e.target.closest('button, input, a, select, textarea')) { return; }
    row = r; sx = e.clientX; sy = e.clientY; dx = 0; dir = 0;
  });
  document.addEventListener('pointermove', function (e) {
    if (!row) { return; }
    var mx = e.clientX - sx, my = e.clientY - sy;
    if (!dir) {                                   // first real movement decides: swipe or scroll
      if (Math.abs(mx) < 8 && Math.abs(my) < 8) { return; }
      if (Math.abs(mx) <= Math.abs(my) + 4) { row = null; return; }
      dir = 1; row.style.transition = 'none';
    }
    dx = Math.max(-LIMIT, Math.min(0, mx));
    row.style.transform = 'translateX(' + dx + 'px)';
    e.preventDefault();
  }, { passive: false });
  function end() {
    if (!row) { return; }
    var r = row; row = null;
    r.style.transition = '';
    if (dx <= -TRIGGER) { r.classList.add('swiped'); r.style.transform = 'translateX(-' + LIMIT + 'px)'; open = r; }
    else { close(r); }
  }
  document.addEventListener('pointerup', end);
  document.addEventListener('pointercancel', end);
})();</script>
JS;
}

function confirm_delete_script(): string
{
    // Capture phase, so this runs before the page's own submit/click handlers.
    return <<<'JS'
<script>(function () {
  var armed = null, timer = null;
  // Tell the server this was confirmed. Destructive handlers refuse without it, so a
  // stale or broken page can't delete anything on a single tap.
  function confirmed(b) {
    if (b.form && !b.form.querySelector('input[name="confirm"]')) {
      var c = document.createElement('input');
      c.type = 'hidden'; c.name = 'confirm'; c.value = '1';
      b.form.appendChild(c);
    }
    disarm();
  }
  function disarm() {
    if (timer) { clearTimeout(timer); timer = null; }
    if (armed) { armed.classList.remove('armed'); armed = null; }
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('.needs-confirm');
    if (!b) { disarm(); return; }          // tapping anything else calls it off
    if (b === armed) { confirmed(b); return; }   // second press: let the click through
    if (b.closest('.swiped')) { confirmed(b); return; }   // the swipe was the first press
    e.preventDefault();
    e.stopPropagation();
    disarm();
    armed = b;
    // The button keeps its own label — arming just fills it red. A × that turned into
    // the word "Delete?" resized the row it sat in and read as a different control.
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
         . swipe_delete_script()
         . section_rename_script()
         . keep_edit_script();
}
