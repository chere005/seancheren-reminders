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
    /* Edit sits under the username, wearing the same pill. */
    .usercol { display: flex; flex-direction: column; align-items: flex-end; gap: 0.4rem; flex: 0 0 auto; }
    .hedit {
      margin: 0; color: #ccc; font-size: 0.8rem; background: none; border: 1px solid #333;
      border-radius: 999px; padding: 0.25rem 0.7rem; cursor: pointer; font-family: inherit;
    }
    .hedit:hover { border-color: #888; color: #fff; }
    body.editing .hedit { background: #34d399; border-color: #34d399; color: #06251b; font-weight: 700; }
    CSS;
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
    return '<div class="usercol">' . $menu
         . '<button type="button" class="hedit" id="' . htmlspecialchars($editId, ENT_QUOTES) . '">Edit</button></div>';
}

function chrome_script(): string
{
    return "<script>(function(){var b=document.getElementById('userBtn'),m=document.getElementById('userMenu');"
         . "if(b&&m){b.addEventListener('click',function(e){e.stopPropagation();m.hidden=!m.hidden;});"
         . "document.addEventListener('click',function(e){if(!m.hidden&&!m.contains(e.target)){m.hidden=true;}});}})();</script>";
}
