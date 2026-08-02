<?php
// A page served under /test/ (the sandbox mirror) loads lib-test/ instead of lib/, and one
// served under /dev/ (a second, fixed sandbox slot) loads lib-dev/ — each mirror
// isolated in code, config and data. Cross-app links carry the same prefix via suite_base();
// _self_path() redirects already stay put. Keep this preamble identical when adding a page.
$__test   = strpos(__DIR__, '/test/') !== false
         || strncmp($_SERVER['REQUEST_URI'] ?? '', '/test/', 6) === 0;
$__dev    = strpos(__DIR__, '/dev/') !== false
         || strncmp($_SERVER['REQUEST_URI'] ?? '', '/dev/', 5) === 0;
$__libDir = null;
$__cands  = $__dev
    ? [__DIR__ . '/../../../lib-dev', '/home/protected/lib-dev']
    : ($__test
        ? [__DIR__ . '/../../../lib-test', '/home/protected/lib-test']
        : [__DIR__ . '/../../lib',         '/home/protected/lib']);
foreach ($__cands as $__c) {
    if (is_file($__c . '/auth.php')) { $__libDir = $__c; break; }
}
require_once $__libDir . '/auth.php';
require_once $__libDir . '/folders.php';   // folder_tint(), and palette.php with it
require_login('Palettes');

/**
 * The colours the suite actually uses, shown under every theme.
 *
 * This is the companion to /akisthemes/: that page *builds* page palettes (a background,
 * greys, text, an accent), this one shows the *item* colours those pages are dressed
 * with — the six hues a reminder folder, a calendar, a notes folder or a habits section
 * is coloured from, at both tiers (mine, and a partner's shared pastel), plus the fixed
 * reminder/event/note kind colours.
 *
 * It is **read-only on purpose**: it writes nothing, changes nothing and has no editor.
 * The palettes come from app_palette() (lib/palette.php) and the kind colours are read
 * back out of kind_color_css(), so the page cannot drift from what the apps draw — if a
 * swatch here is wrong, the palette is wrong, not the copy of it.
 *
 * Every palette is redrawn once per theme, because that is the only question worth
 * asking of them: a pastel that reads clearly on #111 can vanish on a cream page. Each
 * board is painted in its theme's own variables and each swatch carries its contrast
 * against that theme's page and surface, with anything under 3:1 — the floor for a dot,
 * a chip or any other non-text mark — flagged where it happens.
 */
$cfg  = app_config();
$me   = current_user() ?? '';

function e(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES); }

/** The six hues in palette order, named so a swatch can say which one it is. */
const HUES = ['Blue', 'Red', 'Green', 'Orange', 'Purple', 'Grey'];

/** The apps that colour something from the suite palette, and what they colour with it. */
const PAL_APPS = [
    'reminders' => ['Reminders', 'folder washes and section dots', 'folder'],
    'calendar'  => ['Calendar',  'a calendar\'s colour, and its dots on a day',  'cal'],
    'notes'     => ['Notes',     'folder washes and section dots', 'folder'],
    'habits'    => ['Habits',    'section dots and month-pie slices', 'habits'],
];

/**
 * A theme value is normally a #rrggbb, but a role is allowed to point at another
 * variable (`var(--gold)`), and a workbench palette is hand-editable. Anything the
 * arithmetic below can't read comes back null and the contrast is simply not claimed —
 * a made-up ratio would flag colours that are fine, or clear ones that aren't.
 */
function up_hex(?string $v): ?string
{
    $v = strtolower(trim((string) $v));
    return preg_match('/^#[0-9a-f]{6}$/', $v) ? $v : null;
}

/** Relative luminance of a #rrggbb, for the WCAG ratio below. */
function up_lum(string $hex): float
{
    $p = [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    $c = array_map(function ($v) {
        $v /= 255;
        return $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4);
    }, $p);
    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
}

/** Contrast between two #rrggbb, 1–21. */
function up_ratio(string $a, string $b): float
{
    $x = up_lum($a);
    $y = up_lum($b);
    return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
}

/**
 * The kind colours, read straight out of kind_color_css() rather than copied, so this
 * page shows what the apps emit even after someone retunes the event blue.
 */
function up_kind_colors(): array
{
    preg_match_all('/(--k-[a-z-]+):\s*(#[0-9a-fA-F]{6})/', kind_color_css(), $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $x) { $out[$x[1]] = strtolower($x[2]); }
    return $out;
}

/**
 * Four proposed retunes — Midnight, Sage & Cream, Forest, Olive & Slate, each drawn as
 * one extra board where EVERY swatch clears 3:1 on its own page and card. Searched, not
 * hand-picked: each colour was walked the minimum lightness from the live palette that
 * passes (offline, then frozen here), so the dark three are near-identical to today —
 * only the deep purple lifts, Olive's card darkens a step, and the Done grey brightens —
 * while cream needs its own world: the tiers become inks, banded by depth (calendar
 * deepest, then habits, reminders, notes), shared a visibly lighter ink, and the kind
 * colours deepen to match. Wired to nothing; the apps still read app_palette(). Each
 * row is [name, base theme, var overrides, app => [own six, shared six], kind overrides].
 */
const UP_PROPOSALS = [
    ['Midnight II', 'midnight', [], [
        'reminders' => [['#4c8bf0', '#ea5853', '#66d695', '#f39849', '#9e5ce0', '#929aaa'],
                        ['#aecbf8', '#f6b4b2', '#baedcf', '#fad1ad', '#d3b6f1', '#ced2d9']],
        'calendar'  => [['#0361f6', '#ee140d', '#2ad070', '#fa7400', '#8c32e6', '#6b778f'],
                        ['#8eb8fb', '#f79592', '#9feabf', '#fdc08c', '#cba3f4', '#bcc2cd']],
        'notes'     => [['#84aceb', '#e78c89', '#94dbb2', '#edb482', '#b88ee1', '#b0b5bf'],
                        ['#c2d6f5', '#f3c6c4', '#caedd9', '#f6dac1', '#dcc7f0', '#d8dadf']],
        'habits'    => [['#3a77d9', '#d34641', '#53c081', '#dc8437', '#8a4aca', '#7e8695'],
                        ['#a6c2ee', '#ebacaa', '#b2e3c6', '#efc8a5', '#caaee7', '#c5c9cf']],
    ], ['--k-done' => '#676767']],
    ['Forest II', 'forest', [], [
        'reminders' => [['#4c8bf0', '#ea5853', '#66d695', '#f39849', '#9e5ce0', '#929aaa'],
                        ['#aecbf8', '#f6b4b2', '#baedcf', '#fad1ad', '#d3b6f1', '#ced2d9']],
        'calendar'  => [['#0361f6', '#ee140d', '#2ad070', '#fa7400', '#8f37e7', '#6b778f'],
                        ['#8eb8fb', '#f79592', '#9feabf', '#fdc08c', '#cda5f4', '#bcc2cd']],
        'notes'     => [['#84aceb', '#e78c89', '#94dbb2', '#edb482', '#b88ee1', '#b0b5bf'],
                        ['#c2d6f5', '#f3c6c4', '#caedd9', '#f6dac1', '#dcc7f0', '#d8dadf']],
        'habits'    => [['#3a77d9', '#d34641', '#53c081', '#dc8437', '#8a4aca', '#7e8695'],
                        ['#a6c2ee', '#ebacaa', '#b2e3c6', '#efc8a5', '#caaee7', '#c5c9cf']],
    ], ['--k-done' => '#6c6c6c']],
    ['Olive & Slate II', 'olive', ['--surface' => '#2b2334'], [
        'reminders' => [['#4c8bf0', '#ea5853', '#66d695', '#f39849', '#9e5ce0', '#929aaa'],
                        ['#aecbf8', '#f6b4b2', '#baedcf', '#fad1ad', '#d3b6f1', '#ced2d9']],
        'calendar'  => [['#0766fc', '#ee140d', '#2ad070', '#fa7400', '#9745e8', '#6b778f'],
                        ['#8fbafe', '#f79592', '#9feabf', '#fdc08c', '#d0abf5', '#bcc2cd']],
        'notes'     => [['#84aceb', '#e78c89', '#94dbb2', '#edb482', '#b88ee1', '#b0b5bf'],
                        ['#c2d6f5', '#f3c6c4', '#caedd9', '#f6dac1', '#dcc7f0', '#d8dadf']],
        'habits'    => [['#3a77d9', '#d34641', '#53c081', '#dc8437', '#9256cd', '#7e8695'],
                        ['#a6c2ee', '#ebacaa', '#b2e3c6', '#efc8a5', '#ceb3e9', '#c5c9cf']],
    ], ['--k-done' => '#717171']],
    ['Sage & Cream II', 'sage', [], [
        'reminders' => [['#1364e6', '#d2201a', '#207945', '#a7540b', '#8f42db', '#626b7e'],
                        ['#3079ee', '#e73e38', '#268d51', '#c4620d', '#9e5ce0', '#727d91']],
        'calendar'  => [['#0343ab', '#970c08', '#11542e', '#753600', '#6616b6', '#444b5a'],
                        ['#0353d3', '#b90f0a', '#166a39', '#944500', '#7f1ce3', '#535c6f']],
        'notes'     => [['#3576de', '#d8423c', '#2e8954', '#b6631b', '#965ad3', '#70798a'],
                        ['#4b85e2', '#de5f5a', '#33985d', '#cc6f1e', '#9e66d6', '#7d8696']],
        'habits'    => [['#2158b0', '#a72a26', '#256540', '#864b18', '#7a37bd', '#555b68'],
                        ['#286ad2', '#cc342f', '#2d7b4e', '#a0591c', '#8c4dcb', '#656d7c']],
    ], ['--k-reminder' => '#1a7856', '--k-event' => '#0767de',
        '--k-note' => '#6d49ec', '--k-overdue' => '#a3590f']],
];

/**
 * Draft 1, kept for the record — the earlier lightness tiers (vivid / deep / light /
 * dark, same hue throughout), frozen at the values the apps used before the leaned
 * palette went live. Drawn so the iterations stay comparable side by side.
 */
const UP_DRAFT1 = [
    'reminders' => [['#4c8bf0', '#ea5853', '#66d695', '#f39849', '#9e5ce0', '#929aaa'],
                    ['#aecbf8', '#f6b4b2', '#baedcf', '#fad1ad', '#d3b6f1', '#ced2d9']],
    'calendar'  => [['#0361f6', '#ee140d', '#2ad070', '#fa7400', '#7d1bdf', '#6b778f'],
                    ['#8eb8fb', '#f79592', '#9feabf', '#fdc08c', '#c598f1', '#bcc2cd']],
    'notes'     => [['#84aceb', '#e78c89', '#94dbb2', '#edb482', '#b88ee1', '#b0b5bf'],
                    ['#c2d6f5', '#f3c6c4', '#caedd9', '#f6dac1', '#dcc7f0', '#d8dadf']],
    'habits'    => [['#3a77d9', '#d34641', '#53c081', '#dc8437', '#8a4aca', '#7e8695'],
                    ['#a6c2ee', '#ebacaa', '#b2e3c6', '#efc8a5', '#caaee7', '#c5c9cf']],
];

/**
 * Every theme this page draws a board for, grouped into drafts, newest first: Draft 3
 * is the suite's own eight themes (THEMES, lib/auth.php) plus anything built in the
 * Themes workbench, all wearing the leaned palette the apps really use now; Draft 2 is
 * the four II proposals above; Draft 1 is the frozen earlier tiers, on the same four
 * themes. A workbench palette is the same twelve roles under the same names, so
 * everything lands in one shape and renders through one path; a non-live board
 * additionally carries its own item palette and kind overrides.
 */
function up_themes(string $file): array
{
    $out = [];
    foreach (THEMES as $key => $row) {
        $out[] = ['key' => 'suite-' . $key, 'name' => $row[0], 'src' => 'suite theme',
                  'vars' => theme_vars($key)['vars'], 'draft' => 3];
    }
    foreach (store_read($file) as $p) {
        $vars = [];
        foreach ((array) ($p['colors'] ?? []) as $role => $hex) {
            if (preg_match('/^#[0-9a-f]{6}$/i', (string) $hex)) { $vars[$role] = strtolower($hex); }
        }
        // A hand-edited or half-filled palette is skipped rather than rendered with holes:
        // a board missing its page colour has nothing to judge a swatch against.
        if (!isset($vars['--bg'], $vars['--surface'], $vars['--text'], $vars['--line'])) { continue; }
        $out[] = ['key'  => 'user-' . (string) ($p['id'] ?? count($out)),
                  'name' => (string) ($p['name'] ?? 'Untitled'), 'src' => 'from Themes',
                  'vars' => $vars, 'draft' => 3];
    }
    foreach (UP_PROPOSALS as $i => [$name, $base, $over, $pal, $kinds]) {
        $out[] = ['key' => 'prop-' . $i, 'name' => $name, 'src' => 'proposal',
                  'vars' => array_merge(theme_vars($base)['vars'], $over),
                  'pal' => $pal, 'kinds' => $kinds, 'draft' => 2];
    }
    foreach (['midnight', 'forest', 'olive', 'sage'] as $i => $base) {
        $out[] = ['key' => 'd1-' . $i, 'name' => THEMES[$base][0], 'src' => 'draft 1',
                  'vars' => theme_vars($base)['vars'], 'pal' => UP_DRAFT1, 'draft' => 1];
    }
    return $out;
}

/** A theme's variables as one inline style, so a board paints its own subtree. */
function up_vars_style(array $vars): string
{
    $out = '';
    foreach ($vars as $k => $v) { $out .= $k . ':' . $v . ';'; }
    return $out;
}

/**
 * The worse of a colour's two contrasts — against the page and against a card — since a
 * folder wash sits on one and a section dot on the other, and a palette has to survive
 * both. Null when a value isn't readable as a colour (see up_hex).
 */
function up_worst(string $hex, array $vars): ?float
{
    $c = up_hex($hex);
    if ($c === null) { return null; }
    $out = null;
    foreach (['--bg', '--surface'] as $role) {
        $bg = up_hex($vars[$role] ?? null);
        if ($bg === null) { continue; }
        $r   = up_ratio($c, $bg);
        $out = $out === null ? $r : min($out, $r);
    }
    return $out;
}

/**
 * The hex label's colour, grading the ratio from red (poor) through orange to green
 * (good): red at 1.5:1 or worse, green from 5.5:1 up, hue steps between. Kept legible
 * on the board itself — deep tones on the cream themes, light ones on the dark.
 */
function up_grade(float $r, array $vars): string
{
    $t    = min(1.0, max(0.0, ($r - 1.5) / 4.0));
    $bg   = up_hex($vars['--bg'] ?? null);
    $lite = $bg !== null && up_lum($bg) > 0.5;
    return pal_hex(120 * $t, $lite ? 0.70 : 0.60, $lite ? 0.34 : 0.62);
}

/**
 * One swatch: the colour as a dot, its hex under it, and its worst contrast on this
 * theme. Under 3:1 is flagged in place, which is the whole point of the board; the
 * label wears up_grade()'s verdict, so the number reads at a glance.
 */
function up_swatch(string $hex, string $label, array $vars): string
{
    $r   = up_worst($hex, $vars);
    $low = $r !== null && $r < 3.0;
    $t   = $label . ' ' . $hex . ' — '
         . ($r === null ? 'contrast unknown on this theme'
                        : number_format($r, 2) . ':1 against this theme'
                          . ($low ? ' (under 3:1, hard to see)' : ''));
    return '<span class="sw' . ($low ? ' low' : '') . '" title="' . e($t) . '">'
         . '<i style="background:' . e($hex) . '"></i>'
         . '<b' . ($r === null ? '' : ' style="color:' . up_grade($r, $vars) . '"') . '>'
         . e(substr($hex, 1)) . '</b></span>';
}

/** A row of six swatches under a tier label ("Mine" / "Shared"). */
function up_tier(string $label, array $colors, array $vars): string
{
    $out = '<div class="tier"><span class="tl">' . e($label) . '</span><span class="sws">';
    foreach ($colors as $i => $hex) { $out .= up_swatch($hex, HUES[$i] ?? '', $vars); }
    return $out . '</span></div>';
}

/**
 * The little mock beside each app's swatches — the colours doing their actual job, which
 * is the only way to tell that a wash has gone invisible or a dot has gone muddy. One
 * shape per kind of use: a folder heading and its section dots, a calendar day's dots, a
 * habits month pie.
 */
function up_demo(string $shape, array $mine, array $shared, array $vars, array $kinds = []): string
{
    if ($shape === 'cal') {
        // A month cell as the calendar now draws it: the legend's kind icons, at most
        // one of each, each in its item's colour. A proposal board's kind overrides win.
        $k   = $kinds + up_kind_colors();
        $svg = fn($p) => '<svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor"'
             . ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
        $ic  = fn(string $c, string $p) => '<i class="cico" style="color:' . e($c) . '">' . $svg($p) . '</i>';
        $ev  = '<rect x="3" y="4.5" width="18" height="16" rx="2"/><path d="M3 9h18M8 3v3M16 3v3"/>';
        $rem = '<rect x="3" y="3" width="18" height="18" rx="4"/><path d="M7.5 12.5l3 3 6-7"/>';
        $nt  = '<path d="M6 3h8l5 5v13H6z"/><path d="M14 3v5h5M9.5 13h6M9.5 17h4"/>';
        return '<div class="demo"><div class="cell"><span class="cnum">1</span><span class="cdots">'
             . $ic($mine[0], $ev) . $ic($mine[3], $rem) . $ic($shared[4], $nt)
             . '</span></div><div class="cell today"><span class="cnum">2</span><span class="cdots">'
             . $ic($mine[2], $ev) . $ic($k['--k-overdue'] ?? '#f0a860', $rem) . '</span></div></div>';
    }
    if ($shape === 'habits') {
        // A month pie is a conic-gradient of the section colours, as the habits grid draws it.
        $pie = 'conic-gradient(' . e($mine[2]) . ' 0 45%,' . e($mine[0]) . ' 45% 70%,'
             . e($mine[4]) . ' 70% 85%, var(--surface-2) 85% 100%)';
        return '<div class="demo"><div class="pies"><i class="pie" style="background:' . $pie . '"></i>'
             . '<i class="pie" style="background:' . e($mine[2]) . '"></i></div>'
             . '<div class="drow"><i class="ddot" style="background:' . e($mine[0]) . '"></i>'
             . '<span class="dsec">Mornings</span></div>'
             . '<div class="drow"><i class="ddot" style="background:' . e($mine[4]) . '"></i>'
             . '<span class="dsec">Evenings</span></div></div>';
    }
    // A folder heading wears its colour as a 20%-alpha wash, not a dot; its sections take
    // dots. Both tiers, because a partner's pastel is the one that gets lost on a light page.
    return '<div class="demo">'
         . '<div class="dhead"><span class="chip" style="background:' . e(folder_tint($mine[1])) . '">Groceries</span><i class="drule"></i></div>'
         . '<div class="drow"><i class="ddot" style="background:' . e($mine[2]) . '"></i><span class="dsec">Weeknights</span></div>'
         . '<div class="dhead"><span class="chip" style="background:' . e(folder_tint($shared[4])) . '">@aki: Recipes</span><i class="drule"></i></div>'
         . '<div class="drow"><i class="ddot" style="background:' . e($shared[0]) . '"></i><span class="dsec">Dinner</span></div>'
         . '</div>';
}

$themes  = up_themes(user_data_file($cfg['data_dir'], 'palettes'));
$kinds   = up_kind_colors();
$current = theme_get();

/** The five kind colours, in the order the legend uses them. */
$kindRows = [
    ['Reminder', $kinds['--k-reminder'] ?? '#34d399', $kinds['--k-reminder-bg'] ?? '#06251b'],
    ['Event',    $kinds['--k-event']    ?? '#60a5fa', $kinds['--k-event-bg']    ?? '#10233f'],
    ['Note',     $kinds['--k-note']     ?? '#8b6ef0', $kinds['--k-note-bg']     ?? '#241a3a'],
    ['Overdue',  $kinds['--k-overdue']  ?? '#f0a860', $kinds['--k-overdue-bg']  ?? '#3a2410'],
    ['Done',     $kinds['--k-done']     ?? '#555555', $kinds['--k-done']        ?? '#555555'],
];
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>Palettes</title>
  <meta name="theme-color" content="#111111">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black">
  <meta name="apple-mobile-web-app-title" content="Palettes">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: system-ui, sans-serif; background: #111; color: #eee;
           min-height: 100vh; padding: 1.5rem 1rem calc(2rem + env(safe-area-inset-bottom, 0px)); }
    .wrap { max-width: 900px; margin: 0 auto; }
    /* Same top bar as the rest of the suite: one 32px row, rule under it. */
    header { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem;
             margin-bottom: 0.5rem; padding-bottom: 0.7rem; border-bottom: 1px solid #262626; }
    .hleft { display: flex; align-items: center; gap: 0.75rem; min-width: 0; }
    .hleft h1 { font-size: 1.35rem; }
    .backbtn {
      width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center;
      border: 1px solid #333; border-radius: 999px; background: #1a1a1a; color: #ccc;
      font-size: 1.35rem; line-height: 1; text-decoration: none; flex: 0 0 auto;
      font-family: inherit; cursor: pointer; padding: 0;
    }
    .backbtn:hover { border-color: #888; color: #fff; }
    .hright { display: flex; align-items: center; gap: 0.75rem; flex: 0 0 auto; }
    .who { height: 32px; display: inline-flex; align-items: center; justify-content: center;
           padding: 0 0.8rem; border: 1px solid #2a4a3d; border-radius: 999px;
           background: none; color: var(--accent); font-size: 0.85rem; }
    .lede { color: #888; font-size: 0.85rem; margin: 0.6rem 0 1.1rem; line-height: 1.5; }
    .lede b { color: #bbb; font-weight: 600; }

    .toolbar { display: flex; align-items: center; gap: 0.6rem; height: 32px; margin-bottom: 1.2rem; }
    .btn { height: 32px; display: inline-flex; align-items: center; justify-content: center;
           padding: 0 0.9rem; border-radius: 999px; font-size: 0.9rem; font-family: inherit;
           border: 1px solid #333; background: none; color: #ccc; cursor: pointer; }
    .btn:hover { border-color: #888; color: #fff; }
    .count { color: #666; font-size: 0.8rem; }

    /* A draft heading groups the boards into iterations of the palette itself, and
       tapping it folds the whole group, the way every list heading in the suite folds. */
    .draft { font-size: 0.95rem; font-weight: 600; color: #ccc; cursor: pointer;
             user-select: none; display: flex; align-items: center; gap: 0.55rem;
             margin: 1.7rem 0 0.8rem; padding-top: 1rem; border-top: 1px solid #262626; }
    .toolbar ~ .draft:first-of-type { margin-top: 0; padding-top: 0; border-top: none; }
    .dcol { width: 22px; height: 22px; display: inline-flex; align-items: center;
            justify-content: center; border: 1px solid #333; border-radius: 999px;
            color: #ccc; font-size: 0.95rem; line-height: 1; flex: 0 0 auto;
            transform: rotate(90deg); }
    .draft.folded .dcol { transform: rotate(0deg); }
    .dgroup.folded { display: none; }

    /* --- One board per theme, painted in that theme's own variables --------------- */
    .theme { border: 1px solid #262626; border-radius: 12px; margin-bottom: 1.1rem;
             overflow: hidden; background: var(--bg); }
    .thead { display: flex; align-items: center; gap: 0.5rem; padding: 0.55rem 0.7rem;
             background: var(--surface); border-bottom: 1px solid var(--line); }
    .thead h2 { font-size: 0.98rem; font-weight: 600; color: var(--text); min-width: 0;
                overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .tsrc { font-size: 0.7rem; color: var(--muted); flex: 0 0 auto; }
    .tnow { font-size: 0.68rem; color: var(--accent); border: 1px solid var(--accent);
            border-radius: 999px; padding: 0.05rem 0.45rem; flex: 0 0 auto; }
    .twarn { margin-left: auto; font-size: 0.68rem; color: var(--muted); flex: 0 0 auto; }
    .twarn.bad { color: #f5a3ad; }
    .tcol { width: 26px; height: 26px; display: inline-flex; align-items: center;
            justify-content: center; border: 1px solid var(--line); border-radius: 999px;
            background: none; color: var(--text-dim, var(--text)); font-size: 0.9rem;
            line-height: 1; cursor: pointer; flex: 0 0 auto; padding: 0;
            transform: rotate(90deg); }
    .theme.folded .tcol { transform: rotate(0deg); }
    .theme.folded .tbody { display: none; }
    .tbody { padding: 0.75rem; display: grid; gap: 0.75rem; }

    .app { display: grid; grid-template-columns: minmax(0, 1fr) 180px; gap: 0.6rem 0.9rem;
           align-items: start; padding-bottom: 0.7rem; border-bottom: 1px solid var(--line-soft, var(--line)); }
    .app:last-child { border-bottom: none; padding-bottom: 0; }
    .an { grid-column: 1 / -1; display: flex; align-items: baseline; gap: 0.45rem; flex-wrap: wrap; }
    .an b { font-size: 0.85rem; color: var(--gold); font-weight: 600; }
    .an span { font-size: 0.7rem; color: var(--muted); }
    .tiers { display: grid; gap: 0.4rem; min-width: 0; }
    .tier { display: flex; align-items: flex-start; gap: 0.5rem; min-width: 0; }
    .tl { width: 46px; flex: 0 0 auto; font-size: 0.7rem; color: var(--muted); padding-top: 0.35rem; }
    .sws { display: flex; flex-wrap: wrap; gap: 0.35rem; min-width: 0; }
    .sw { display: inline-flex; flex-direction: column; align-items: center; gap: 0.15rem; width: 46px; }
    .sw i { width: 22px; height: 22px; border-radius: 999px; display: block;
            border: 1px solid var(--line); }
    .sw b { font: 0.55rem ui-monospace, monospace; color: var(--muted); font-weight: 400;
            background: var(--surface-2); padding: 0.1rem 0.3rem; border-radius: 5px; }
    /* A colour that can't be seen on this theme is marked where it fails, not in a list:
       its label's chip blushes — a red wash over the same surface grey, layered so it
       tints rather than replaces it (no color-mix; some of the phones predate it). */
    .sw.low b { background: linear-gradient(#e0525229, #e0525229), var(--surface-2); }

    /* --- The mock beside each app: the colours doing their real job ---------------- */
    .demo { background: var(--surface); border: 1px solid var(--line); border-radius: 8px;
            padding: 0.45rem 0.5rem; display: grid; gap: 0.3rem; }
    .dhead { display: flex; align-items: center; gap: 0.4rem; }
    .chip { font-size: 0.68rem; color: var(--text); padding: 0.1rem 0.45rem; border-radius: 999px;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .drule { flex: 1 1 auto; height: 1px; background: var(--line); }
    .drow { display: flex; align-items: center; gap: 0.35rem; }
    .ddot { width: 9px; height: 9px; border-radius: 999px; flex: 0 0 auto; }
    .dsec { font-size: 0.68rem; color: var(--gold); }
    .cell { border: 1px solid var(--line); border-radius: 6px; padding: 0.25rem 0.3rem;
            display: grid; gap: 0.2rem; }
    .cell.today { border: 2px solid var(--accent); }
    .cnum { font-size: 0.65rem; color: var(--text); }
    .cdots { display: flex; gap: 4px; }
    .cico { display: inline-flex; align-items: center; justify-content: center;
            width: 11px; height: 11px; }
    .cico svg { display: block; }
    .pies { display: flex; gap: 0.35rem; }
    .pie { width: 26px; height: 26px; border-radius: 999px; display: block;
           border: 1px solid var(--line); }

    /* --- The kind colours: one palette suite-wide, and not themed ------------------ */
    .kinds { display: flex; flex-wrap: wrap; gap: 0.35rem; align-items: center; }
    .kind { display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.7rem;
            padding: 0.2rem 0.55rem; border-radius: 999px; border: 1px solid var(--line); }
    .kind i { width: 9px; height: 9px; border-radius: 999px; flex: 0 0 auto; }
    .kind.low { border-color: #f5a3ad; }
    .kind.low span { color: #f5a3ad; }
    .empty { color: #666; font-size: 0.9rem; padding: 2rem 0; text-align: center; }
<?php // theme_css() returns bare CSS, so it belongs INSIDE the block — after </style> it
      // renders as text at the top of the page. It only dresses this page's own chrome
      // (the accent); every board below overrides all twelve variables locally. ?>
<?= theme_css() ?>
  </style>
</head>
<body>
<div class="wrap">
  <header>
    <div class="hleft">
      <button type="button" class="backbtn goback" onclick="history.back()" title="Back" aria-label="Back">&lsaquo;</button>
      <h1>Palettes</h1>
    </div>
    <div class="hright">
      <span class="who"><?= e($me) ?></span>
    </div>
  </header>

  <p class="lede">
    The colours the suite dresses <b>items</b> in — the six hues a reminder folder, a calendar,
    a notes folder or a habits section takes, at both tiers (<b>mine</b>, and a partner's lighter
    <b>shared</b> pastel), plus the fixed reminder/event/note kind colours. Each set is drawn once
    per theme, on that theme's own page and card colours, because that is the question worth
    asking of a palette: a swatch under <b>3:1</b> against its own background is flagged, since a
    dot or a wash that faint is one you have to go looking for. Nothing here is editable —
    page palettes are built in <b>Themes</b>. The boards are grouped into <b>drafts</b>:
    Draft 3 — the leaned palette — is what the apps read today, on every theme; Draft 2
    is the four II retunes, on which every swatch clears 3:1; Draft 1 is the earlier
    lightness tiers, kept for the record. Only Draft 3 is real — the older drafts are
    history, read by nothing.
  </p>

  <div class="toolbar">
    <button type="button" class="btn" id="foldAll">Collapse all</button>
    <span class="count"><?= count($themes) ?> theme<?= count($themes) === 1 ? '' : 's' ?></span>
  </div>

  <?php if (!$themes): ?>
    <p class="empty">No themes to draw.</p>
  <?php endif; ?>

  <?php $draftNames = [3 => 'Draft 3 — live: what the apps use today',
                       2 => 'Draft 2 — the II retunes: everything clears 3:1',
                       1 => 'Draft 1 — the earlier lightness tiers'];
        $lastDraft = 0;
        foreach ($themes as $t): $v = $t['vars'];
        // A later-draft board carries its own item palette and kind overrides; every
        // Draft 1 board draws the one set the apps really use.
        $bp = fn(string $app, bool $sh) => $t['pal'][$app][$sh ? 1 : 0] ?? app_palette($app, $sh);
        $bk = $t['kinds'] ?? [];
        $rowsK = [];
        foreach ($kindRows as $k) {
            $key = '--k-' . strtolower($k[0]);
            $rowsK[] = isset($bk[$key]) ? [$k[0], $bk[$key], folder_tint($bk[$key])] : $k;
        }
        // Everything the board draws, counted once, so the head can say up front whether
        // this theme is one the palettes survive.
        $low = 0; $tot = 0;
        foreach (PAL_APPS as $app => $_m) {
            foreach ([false, true] as $sh) {
                foreach ($bp($app, $sh) as $hex) {
                    $tot++;
                    $r = up_worst($hex, $v);
                    if ($r !== null && $r < 3.0) { $low++; }
                }
            }
        } ?>
    <?php if (($t['draft'] ?? 1) !== $lastDraft): ?>
      <?php if ($lastDraft): ?></div><?php endif; $lastDraft = $t['draft'] ?? 1; ?>
      <h2 class="draft" data-draft="<?= (int) $lastDraft ?>"><span class="dcol">&rsaquo;</span><?= e($draftNames[$lastDraft] ?? '') ?></h2>
      <div class="dgroup" data-draft="<?= (int) $lastDraft ?>">
    <?php endif; ?>
    <section class="theme" data-key="<?= e($t['key']) ?>" style="<?= e(up_vars_style($v)) ?>">
      <div class="thead">
        <button type="button" class="tcol" title="Collapse" aria-label="Collapse">&rsaquo;</button>
        <h2><?= e($t['name']) ?></h2>
        <span class="tsrc"><?= e($t['src']) ?></span>
        <?php if ($t['key'] === 'suite-' . $current): ?><span class="tnow">yours</span><?php endif; ?>
        <span class="twarn<?= $low ? ' bad' : '' ?>">
          <?= $low ? e($low . ' of ' . $tot . ' under 3:1') : 'all clear 3:1' ?>
        </span>
      </div>
      <div class="tbody">
        <?php foreach (PAL_APPS as $app => $meta):
              $mine = $bp($app, false);
              $shrd = $bp($app, true); ?>
          <div class="app">
            <div class="an"><b><?= e($meta[0]) ?></b><span><?= e($meta[1]) ?></span></div>
            <div class="tiers">
              <?= up_tier('Mine', $mine, $v) ?>
              <?= up_tier('Shared', $shrd, $v) ?>
            </div>
            <?= up_demo($meta[2], $mine, $shrd, $v, $bk) ?>
          </div>
        <?php endforeach; ?>

        <div class="app">
          <div class="an"><b>Kinds</b><span><?= $bk
              ? 'retuned with this proposal &mdash; the live apps keep one set suite-wide'
              : 'one palette suite-wide, and deliberately not themed &mdash; these say what a thing <i>is</i>' ?></span></div>
          <div class="tiers">
            <div class="kinds">
              <?php foreach ($rowsK as $k):
                    $kr  = up_worst($k[1], $v);
                    $bad = $kr !== null && $kr < 3.0; ?>
                <span class="kind<?= $bad ? ' low' : '' ?>" style="background:<?= e($k[2]) ?>"
                      title="<?= e($k[0] . ' ' . $k[1]) ?>">
                  <i style="background:<?= e($k[1]) ?>"></i><span style="color:<?= e($k[1]) ?>"><?= e($k[0]) ?></span>
                </span>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="demo">
            <div class="drow"><i class="ddot" style="background:<?= e($rowsK[0][1]) ?>"></i><span class="dsec" style="color:var(--text)">Pick up the milk</span></div>
            <div class="drow"><i class="ddot" style="background:<?= e($rowsK[1][1]) ?>"></i><span class="dsec" style="color:var(--text)">Dinner, 7pm</span></div>
            <div class="drow"><i class="ddot" style="background:<?= e($rowsK[3][1]) ?>"></i><span class="dsec" style="color:var(--muted)">Vet &mdash; overdue</span></div>
          </div>
        </div>
      </div>
    </section>
  <?php endforeach; ?>
  <?php if ($lastDraft): ?></div><?php endif; ?>
</div>

<script>
  // A draft heading folds its whole group, remembered per draft — the same gesture as
  // folding one board, one level up.
  document.querySelectorAll('h2.draft').forEach(function (h) {
    var key = 'upDraftFold_' + h.dataset.draft;
    var grp = h.nextElementSibling;
    function set(f) {
      h.classList.toggle('folded', f);
      grp.classList.toggle('folded', f);
      localStorage.setItem(key, f ? '1' : '0');
    }
    if (localStorage.getItem(key) === '1') { set(true); }
    h.addEventListener('click', function () { set(!h.classList.contains('folded')); });
  });
  // Folding is remembered per theme, keyed by this page alone so it can't collide with
  // the section/folder keys the apps use. The page opens with everything shown — the
  // boards are the whole content, so starting folded would open on an empty page.
  document.querySelectorAll('.theme').forEach(function (sec) {
    var key = 'upFold_' + sec.dataset.key;
    if (localStorage.getItem(key) === '1') { sec.classList.add('folded'); }
    sec.querySelector('.tcol').addEventListener('click', function () {
      sec.classList.toggle('folded');
      localStorage.setItem(key, sec.classList.contains('folded') ? '1' : '0');
    });
  });
  // One button for the lot, and it toggles: if anything is open it folds everything,
  // otherwise it opens everything again.
  document.getElementById('foldAll').addEventListener('click', function () {
    var all  = Array.prototype.slice.call(document.querySelectorAll('.theme'));
    var fold = all.some(function (s) { return !s.classList.contains('folded'); });
    all.forEach(function (s) {
      s.classList.toggle('folded', fold);
      localStorage.setItem('upFold_' + s.dataset.key, fold ? '1' : '0');
    });
    this.textContent = fold ? 'Expand all' : 'Collapse all';
  });
</script>
</body>
</html>
