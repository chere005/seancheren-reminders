<?php
/**
 * Shared bottom tab bar for the app (Reminders / Calendar).
 * Include the CSS once in <head> via tabbar_styles(), and render the bar
 * just before </body> via render_tabbar('reminders'|'calendar').
 */

function tabbar_styles(): string
{
    return <<<CSS
    /* Bottom segmented selector */
    body { padding-bottom: calc(78px + env(safe-area-inset-bottom, 0px)); }
    .tabbar {
      position: fixed; left: 0; right: 0; bottom: 0; z-index: 50;
      display: flex; justify-content: center;
      background: #161616; border-top: 1px solid #2a2a2a;
      padding: 0.6rem 1rem calc(0.6rem + env(safe-area-inset-bottom, 0px));
    }
    .segmented {
      display: flex; width: 100%; max-width: 420px;
      background: #0e0e0e; border: 1px solid #2a2a2a; border-radius: 10px; padding: 3px;
    }
    .segmented a {
      flex: 1; display: flex; align-items: center; justify-content: center; gap: 5px;
      padding: 0.5rem 0; text-decoration: none; color: #888;
      font-size: 0.82rem; font-weight: 600; border-radius: 8px;
      transition: background 0.12s, color 0.12s;
    }
    .segmented a .ico { font-size: 1.35rem; line-height: 1; }
    .segmented a:hover { color: #ccc; }
    .segmented a.active {
      background: #2a2a2a; color: var(--accent);
    }
    .segmented a.active:hover { color: var(--accent); }
    CSS;
}

function render_tabbar(string $active): void
{
    $tabs = [
        'reminders' => ['href' => '/reminders/', 'ico' => '&#9745;', 'label' => 'Reminders'],
        'calendar'  => ['href' => '/calendar/',  'ico' => '&#128197;', 'label' => 'Calendar'],
        'notes'     => ['href' => '/notes/',     'ico' => '&#128221;', 'label' => 'Notes'],
        'habits'    => ['href' => '/habits/',    'ico' => '&#128293;', 'label' => 'Habits'],
    ];
    echo '<nav class="tabbar"><div class="segmented">';
    foreach ($tabs as $key => $t) {
        $cls = $key === $active ? ' class="active"' : '';
        // Icon only; the label stays as the accessible name.
        echo '<a href="' . $t['href'] . '"' . $cls
           . ' title="' . $t['label'] . '" aria-label="' . $t['label'] . '">'
           . '<span class="ico">' . $t['ico'] . '</span></a>';
    }
    echo '</div></nav>';
    // iOS home-screen (standalone) apps otherwise open internal links in Safari
    // with the browser chrome; intercept same-origin links so they stay in the app.
    echo '<script>if(window.navigator.standalone){document.addEventListener("click",function(e){'
       . 'var a=e.target.closest&&e.target.closest("a");'
       . 'if(a&&a.href&&a.host===location.host&&!a.target&&!a.hasAttribute("download")){'
       . 'e.preventDefault();location.href=a.getAttribute("href");}},false);}</script>';
}
