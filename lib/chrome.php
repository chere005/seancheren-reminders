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
    .hleft { display: flex; align-items: center; gap: 0.5rem; min-width: 0; }
    .hright { display: flex; align-items: center; gap: 0.8rem; flex: 0 0 auto; }
    .backbtn {
      flex: 0 0 auto; align-self: center; background: #1a1a1a; border: 1px solid #333; color: #ccc;
      cursor: pointer; width: 34px; height: 34px; border-radius: 8px; font-size: 1.5rem; line-height: 1;
      padding: 0 0 0.15rem; font-family: inherit;
    }
    .backbtn:hover { border-color: #888; color: #fff; }
    .usermenu { position: relative; flex: 0 0 auto; }
    .usermenu .who {
      margin: 0; color: #34d399; font-size: 0.8rem; background: none; border: 1px solid #2a4a3d;
      border-radius: 999px; padding: 0.25rem 0.7rem; cursor: pointer; font-family: inherit;
    }
    .usermenu .who:hover { border-color: #34d399; color: #34d399; }
    .usermenu .menu {
      position: absolute; right: 0; top: calc(100% + 6px); z-index: 40; background: #1c1c1c;
      border: 1px solid #333; border-radius: 8px; min-width: 120px; box-shadow: 0 8px 20px rgba(0,0,0,0.5); overflow: hidden;
    }
    .usermenu .menu a { display: block; margin: 0; padding: 0.6rem 0.9rem; color: #eee; text-decoration: none; font-size: 0.9rem; }
    .usermenu .menu a:hover { background: #2a2a2a; color: #fff; }
    /* Edit sits to the left of the username, wearing the same pill. */
    .usercol { display: flex; align-items: center; gap: 0.5rem; flex: 0 0 auto; }
    .hedit {
      margin: 0; color: #ccc; font-size: 0.8rem; background: none; border: 1px solid #333;
      border-radius: 999px; padding: 0.25rem 0.7rem; cursor: pointer; font-family: inherit;
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
    . confirm_delete_styles();
}

function back_button(): string
{
    return '<button type="button" class="backbtn" onclick="history.back()" aria-label="Back">&lsaquo;</button>';
}

/**
 * The username chip, optionally with the app's Edit toggle stacked underneath it.
 * $editId is the button id the page's own script already listens on.
 */
function render_user_menu(bool $withEdit = false, string $editId = 'editBtn'): string
{
    $u    = htmlspecialchars(current_user() ?? '', ENT_QUOTES);
    $menu = '<div class="usermenu"><button type="button" class="who" id="userBtn">' . $u . ' &#9662;</button>'
          . '<div class="menu" id="userMenu" hidden><a href="?logout">Log out</a></div></div>';
    if (!$withEdit) {
        return $menu;
    }
    return '<div class="usercol">'
         . '<button type="button" class="hedit" id="' . htmlspecialchars($editId, ENT_QUOTES) . '">Edit</button>'
         . $menu . '</div>';
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
         . confirm_delete_script()
         . keep_edit_script();
}
