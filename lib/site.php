<?php
// Shared chrome for the static top-level pages (Home, Projects, About, Contact).
// Dark theme echoing the Reminders app: #111 bg, #eee text, #34d399 accent, pill nav.

function site_nav($active) {
  $links = ['' => 'Home', 'projects' => 'Projects', 'about' => 'About', 'contact' => 'Contact'];
  $out = '<nav class="sitenav">';
  foreach ($links as $slug => $label) {
    $href = $slug === '' ? '/' : '/' . $slug . '/';
    $cls = $slug === $active ? ' class="on"' : '';
    $out .= '<a href="' . $href . '"' . $cls . '>' . $label . '</a>';
  }
  return $out . '</nav>';
}

function site_page($active, $title, $bodyHtml) {
  $nav = site_nav($active);
  echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<title>$title &middot; seancheren.com</title>
<style>
  :root { --accent: #34d399; --accent-ink: #06251b; }
  * { box-sizing: border-box; }
  body {
    margin: 0; background: #111; color: #eee;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    line-height: 1.6; -webkit-font-smoothing: antialiased;
    padding: env(safe-area-inset-top) env(safe-area-inset-right) env(safe-area-inset-bottom) env(safe-area-inset-left);
  }
  .wrap { max-width: 640px; margin: 0 auto; padding: 1.5rem 1.25rem 4rem; }
  .sitenav {
    display: flex; flex-wrap: wrap; gap: 0.5rem;
    padding-bottom: 0.75rem; margin-bottom: 1.5rem; border-bottom: 1px solid #2a2a2a;
  }
  .sitenav a {
    text-decoration: none; color: #ccc; border: 1px solid #333; background: #1a1a1a;
    border-radius: 999px; padding: 0.3rem 0.9rem; font-size: 0.95rem;
  }
  .sitenav a:hover { background: #2a2a2a; color: #fff; }
  .sitenav a.on { background: var(--accent); border-color: var(--accent); color: var(--accent-ink); font-weight: 700; }
  /* One luminance ramp rather than three unrelated colours: the page title is the
     brightest thing on the page, section headings carry the accent, and subheadings are
     a desaturated relative of it — so the hierarchy reads without any of the three
     competing with the green of a link in body text. */
  h1 {
    font-size: 1.7rem; margin: 0.5rem 0 1rem; color: #fff;
    letter-spacing: -0.015em; font-weight: 700;
  }
  h2 {
    font-size: 1.25rem; margin: 2rem 0 0.5rem; color: var(--accent);
    letter-spacing: -0.01em;
  }
  h3 { font-size: 1.05rem; margin: 1.5rem 0 0.4rem; color: #9fb8ae; font-weight: 600; }
  p { margin: 0.75rem 0; color: #d8dcda; }
  /* Underlined, and a touch lighter than the h2 green, so a link inside a section never
     reads as another heading. */
  a { color: #5fe0b0; text-underline-offset: 2px; }
  a:hover { color: #8af0ca; }
  ul { margin: 0.5rem 0; padding-left: 1.25rem; }
  li { margin: 0.2rem 0; }
  .sig { margin-top: 1.5rem; color: #888; }
  .sig .date { font-size: 0.85rem; }
  .lists-col ul { columns: 2; column-gap: 2rem; }
  @media (max-width: 480px) { .lists-col ul { columns: 1; } }
</style>
</head>
<body>
<div class="wrap">
$nav
$bodyHtml
</div>
</body>
</html>
HTML;
}
