<?php
/**
 * The suite's colour palettes, generated from one base set of six hues.
 *
 * Every app draws from the same six hues (blue, red, green, orange, purple, grey). The
 * *own* (mine) set is the base nudged only slightly lighter per app, so reminders, calendar,
 * notes and habits read as one family in a similar shade — a gentle step between them, not
 * the old bright/mid/deep tiers. The *shared* (a partner's) set is the base pushed
 * *significantly* lighter — a clear pastel — so someone else's items are obviously theirs.
 * Kept generated (not hand-typed) so the two tiers stay consistent and easy to tune.
 */

/** The six base hues, at their vivid "own" shade (this is reminders' own set unchanged). */
const PAL_BASE = ['#4c8bf0', '#ea5853', '#66d695', '#f39849', '#9e5ce0', '#929aaa'];

/**
 * [own lighten fraction, shared lighten fraction], 0 = base, 1 = white. The colours are
 * **fixed across every app** now: own is the vivid base everywhere, and a partner's shared
 * items are the same clear pastel everywhere — so a blue folder, a blue calendar and a blue
 * habit section are the identical blue, and "mine vs theirs" reads the same way in each app.
 */
const PAL_TIERS = [
    'reminders' => [0.00, 0.60],
    'calendar'  => [0.00, 0.60],
    'notes'     => [0.00, 0.60],
    'habits'    => [0.00, 0.60],
];

/** Mix a #rrggbb colour toward white by fraction $f (0 = unchanged, 1 = white). */
function pal_lighten(string $hex, float $f): string
{
    $r = hexdec(substr($hex, 1, 2));
    $g = hexdec(substr($hex, 3, 2));
    $b = hexdec(substr($hex, 5, 2));
    $mix = fn($c) => (int) round($c + (255 - $c) * $f);
    return sprintf('#%02x%02x%02x', $mix($r), $mix($g), $mix($b));
}

/**
 * The six colours an app offers — the partner ("shared") variant when $shared is true.
 * An app with no tier of its own falls back to reminders. Habits asks for its own set now
 * (a similar shade to the others), not the borrowed lighter one it used before.
 */
function app_palette(string $app, bool $shared = false): array
{
    [$ownF, $sharedF] = PAL_TIERS[$app] ?? PAL_TIERS['reminders'];
    $f = $shared ? $sharedF : $ownF;
    return array_map(fn($h) => pal_lighten($h, $f), PAL_BASE);
}

/** True if $hex is one of an app's colours (own or shared) — used to validate a choice. */
function palette_has(string $app, string $hex): bool
{
    return in_array($hex, app_palette($app), true) || in_array($hex, app_palette($app, true), true);
}
