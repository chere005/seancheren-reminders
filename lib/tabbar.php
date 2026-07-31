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
    .segmented a { position: relative; }
    .segmented a .ico { font-size: 1.35rem; line-height: 1; position: relative; z-index: 1; }
    .segmented a:hover { color: #ccc; }
    /* The selected tab's highlight is inset a little from the tab's edges, so it reads as
       a pill sitting inside the tab rather than filling it corner to corner. */
    .segmented a.active { color: var(--accent); }
    .segmented a.active::before {
      content: ''; position: absolute; inset: 3px 12px; background: #2a2a2a; border-radius: 8px; z-index: 0;
    }
    .segmented a.active:hover { color: var(--accent); }
    /* The middle "+" tab: a round green add button that opens the quick-add app. When it's
       the current tab it just deepens in colour — no pill highlight behind it. */
    .segmented a.addtab {
      flex: 0 0 auto; width: 44px; height: 44px; align-self: center; margin: -6px 0.4rem 0; padding: 0;
      border-radius: 50%; background: var(--accent, #34d399); color: var(--accent-ink, #06251b);
    }
    .segmented a.addtab .ico { display: inline-flex; align-items: center; justify-content: center; }
    .segmented a.addtab .ico svg { display: block; }
    .segmented a.addtab:hover { background: #52e0ac; color: var(--accent-ink, #06251b); }
    .segmented a.addtab.active::before { display: none; }   /* no pill behind the + */
    .segmented a.addtab.active { background: #0f9b73; color: #eafff6; }
    CSS;
}

function render_tabbar(string $active): void
{
    // A green "+" sits in the middle, between Calendar and Notes, opening the quick-add app.
    $tabs = [
        'reminders' => ['href' => '/reminders/', 'ico' => '&#9745;',   'label' => 'Reminders'],
        'calendar'  => ['href' => '/calendar/',  'ico' => '&#128197;', 'label' => 'Calendar'],
        'add'       => ['href' => '/add/',        'ico' => '+',          'label' => 'Add', 'add' => true],
        'notes'     => ['href' => '/notes/',     'ico' => '&#128221;', 'label' => 'Notes'],
        'habits'    => ['href' => '/habits/',    'ico' => '&#128293;', 'label' => 'Habits'],
    ];
    echo '<nav class="tabbar"><div class="segmented">';
    foreach ($tabs as $key => $t) {
        $cls = ($key === $active ? ' active' : '') . (!empty($t['add']) ? ' addtab' : '');
        // The "+" tab draws its plus as an SVG so it's dead-centre in the round button
        // regardless of font metrics; the other tabs keep their emoji glyph.
        $ico = !empty($t['add'])
            ? '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" '
              . 'stroke-width="3" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>'
            : $t['ico'];
        // Icon only; the label stays as the accessible name.
        echo '<a href="' . $t['href'] . '" data-tab="' . $key . '"'
           . ($cls !== '' ? ' class="' . trim($cls) . '"' : '')
           . ' title="' . $t['label'] . '" aria-label="' . $t['label'] . '">'
           . '<span class="ico">' . $ico . '</span></a>';
    }
    echo '</div></nav>';
    // The Calendar tab means "today". The Calendar remembers the day you were looking at
    // so that coming back from a note or a reminder lands you where you left it, and this
    // button is the way to ask for today again — along with closing the app, since the
    // memory lives in sessionStorage and goes with the session.
    echo '<script>document.addEventListener("click",function(e){'
       . 'var a=e.target.closest&&e.target.closest(\'a[data-tab="calendar"]\');'
       . 'if(a){try{sessionStorage.removeItem("calDay");}catch(_){}}},true);</script>';
    // iOS home-screen (standalone) apps otherwise open internal links in Safari
    // with the browser chrome; intercept same-origin links so they stay in the app.
    echo '<script>if(window.navigator.standalone){document.addEventListener("click",function(e){'
       . 'var a=e.target.closest&&e.target.closest("a");'
       . 'if(a&&a.href&&a.host===location.host&&!a.target&&!a.hasAttribute("download")){'
       . 'e.preventDefault();location.href=a.getAttribute("href");}},false);}</script>';
}
