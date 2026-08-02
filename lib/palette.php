<?php
/**
 * The suite's colour palettes, generated from one base set of six hues.
 *
 * Every app offers the same six hues (blue, red, green, orange, purple, grey), and each
 * wears them as its own unmistakable shade — not a lightness ladder but a lean within
 * the family: reminders is the vivid anchor (the base itself); calendar is electric
 * deep with a touch of violet lean; notes leans the other way and brightens (sky blue,
 * rose red); habits leans clockwise at full strength (indigo, crimson, amber). Every
 * own colour additionally clears 3:1 against the dark themes' card (#1a1a1a), so a dot
 * is never a thing you squint for. Each app's *shared* (a partner's) set is its own six
 * mixed toward white — a matching lighter version of the same shade, so "mine vs
 * theirs" reads the same way in every app. Kept generated (not hand-typed) so the
 * tiers stay consistent and easy to tune.
 */

/** The six base hues (blue, red, green, orange, purple, grey) — reminders' own set as-is. */
const PAL_BASE = ['#4c8bf0', '#ea5853', '#66d695', '#f39849', '#9e5ce0', '#929aaa'];

/**
 * How much of an app's hue lean each hue takes: red and orange start only 26° apart, so
 * they lean at half strength or they walk into each other, and grey has no hue to lean.
 */
const PAL_LEAN = [1.0, 0.5, 1.0, 0.5, 1.0, 0.0];

/**
 * Per app: [hue lean °, saturation multiplier, HSL lightness (null = the base as-is),
 * grey lightness, shared lighten fraction]. Grey can only separate by lightness, so it
 * gets its own rung rather than riding the app's coloured one.
 */
const PAL_TONES = [
    'reminders' => [  0, 1.00, null, null,  0.55],   // the vivid anchor
    'calendar'  => [ -6, 1.15, 0.49, 0.47,  0.55],   // electric deep, violet-leaned
    'notes'     => [-14, 0.90, 0.71, 0.71,  0.47],   // leaned back and brightened: sky, rose
    'habits'    => [ 16, 1.00, 0.52, 0.545, 0.50],   // leaned on at full strength: indigo, crimson, amber
];

/** #rrggbb → [hue 0–360, saturation 0–1, lightness 0–1]. */
function pal_hsl(string $hex): array
{
    $r = hexdec(substr($hex, 1, 2)) / 255;
    $g = hexdec(substr($hex, 3, 2)) / 255;
    $b = hexdec(substr($hex, 5, 2)) / 255;
    $max = max($r, $g, $b);
    $min = min($r, $g, $b);
    $l = ($max + $min) / 2;
    $d = $max - $min;
    if ($d == 0) { return [0.0, 0.0, $l]; }
    $s = $d / (1 - abs(2 * $l - 1));
    $h = match ($max) {
        $r => fmod(($g - $b) / $d, 6),
        $g => ($b - $r) / $d + 2,
        default => ($r - $g) / $d + 4,
    };
    return [fmod($h * 60 + 360, 360), $s, $l];
}

/** [hue 0–360, saturation 0–1, lightness 0–1] → #rrggbb. */
function pal_hex(float $h, float $s, float $l): string
{
    $c = (1 - abs(2 * $l - 1)) * $s;
    $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
    $m = $l - $c / 2;
    [$r, $g, $b] = match (true) {
        $h < 60  => [$c, $x, 0],
        $h < 120 => [$x, $c, 0],
        $h < 180 => [0, $c, $x],
        $h < 240 => [0, $x, $c],
        $h < 300 => [$x, 0, $c],
        default  => [$c, 0, $x],
    };
    $ch = fn($v) => (int) round(($v + $m) * 255);
    return sprintf('#%02x%02x%02x', $ch($r), $ch($g), $ch($b));
}

/** Mix a #rrggbb colour toward white by fraction $f (0 = unchanged, 1 = white). */
function pal_lighten(string $hex, float $f): string
{
    $r = hexdec(substr($hex, 1, 2));
    $g = hexdec(substr($hex, 3, 2));
    $b = hexdec(substr($hex, 5, 2));
    $mix = fn($c) => (int) round($c + (255 - $c) * $f);
    return sprintf('#%02x%02x%02x', $mix($r), $mix($g), $mix($b));
}

/** Relative luminance of a #rrggbb (the WCAG one). */
function pal_lum(string $hex): float
{
    $lin = function (int $p) use ($hex): float {
        $c = hexdec(substr($hex, $p, 2)) / 255;
        return $c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
    };
    return 0.2126 * $lin(1) + 0.7152 * $lin(3) + 0.0722 * $lin(5);
}

/** Lift a colour until it clears 3:1 (plus a whisker) on the dark themes' #1a1a1a card. */
function pal_floor(string $hex): string
{
    [$h, $s, $l] = pal_hsl($hex);
    $card = pal_lum('#1a1a1a');
    while ((pal_lum($hex) + 0.05) / ($card + 0.05) < 3.05 && $l < 0.9) {
        $l += 0.01;
        $hex = pal_hex($h, $s, $l);
    }
    return $hex;
}

/** An app's shade of base hue $i: leaned, saturated, re-lit, then floored. */
function pal_shade(int $i, string $hex, float $lean, float $sMul, ?float $l, ?float $greyL): string
{
    if ($l === null) { return $hex; }   // the anchor keeps the base byte-identical
    [$h, $s] = pal_hsl($hex);
    $h = fmod($h + $lean * PAL_LEAN[$i] + 360, 360);
    $s = min(1.0, $s * $sMul);
    if ($i === 5 && $greyL !== null) { $l = $greyL; }
    return pal_floor(pal_hex($h, $s, $l));
}

/**
 * The six colours an app offers — the partner ("shared") variant when $shared is true.
 * An app with no tone row of its own falls back to reminders'.
 */
function app_palette(string $app, bool $shared = false): array
{
    [$lean, $sMul, $l, $greyL, $f] = PAL_TONES[$app] ?? PAL_TONES['reminders'];
    $own = [];
    foreach (PAL_BASE as $i => $hex) { $own[] = pal_shade($i, $hex, $lean, $sMul, $l, $greyL); }
    return $shared ? array_map(fn($h) => pal_lighten($h, $f), $own) : $own;
}

/** True if $hex is one of an app's colours (own or shared) — used to validate a choice. */
function palette_has(string $app, string $hex): bool
{
    return in_array($hex, app_palette($app), true) || in_array($hex, app_palette($app, true), true);
}
