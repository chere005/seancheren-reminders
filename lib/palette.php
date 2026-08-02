<?php
/**
 * The suite's colour palettes, generated from one base set of six hues.
 *
 * Every app offers the same six hues (blue, red, green, orange, purple, grey), but each
 * app wears them at its own distinct shade: reminders vivid (the base itself), calendar
 * deep and rich, notes soft and dusty, habits muted slate. So a blue folder, a blue
 * calendar and a blue habit section are all recognisably blue — and recognisably not
 * each other's blue. Each app's *shared* (a partner's) set is that app's own six pushed
 * clearly toward white, a matching lighter version of the same shade, so "mine vs
 * theirs" reads the same way in every app. Kept generated (not hand-typed) so the
 * tiers stay consistent and easy to tune.
 */

/** The six base hues (blue, red, green, orange, purple, grey) — reminders' own set as-is. */
const PAL_BASE = ['#4c8bf0', '#ea5853', '#66d695', '#f39849', '#9e5ce0', '#929aaa'];

/**
 * Per app: [saturation multiplier, lightness shift, shared lighten fraction]. The first
 * two shape the app's own shade from the base (which sits at HSL lightness 0.62
 * throughout); the third mixes that own shade toward white for the partner's tier.
 */
const PAL_TONES = [
    'reminders' => [1.00,  0.00, 0.55],   // vivid — the base itself
    'calendar'  => [1.15, -0.13, 0.55],   // deep and rich
    'notes'     => [0.70,  0.09, 0.50],   // soft — lighter but still clearly coloured
    'habits'    => [0.58, -0.06, 0.55],   // slate — darker but still clearly coloured
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

/** An app's shade of one base hue: saturation scaled, lightness shifted, both clamped. */
function pal_tone(string $hex, float $sMul, float $lShift): string
{
    if ($sMul == 1.0 && $lShift == 0.0) { return $hex; }   // the base, byte-identical
    [$h, $s, $l] = pal_hsl($hex);
    return pal_hex($h, min(1.0, $s * $sMul), min(1.0, max(0.0, $l + $lShift)));
}

/**
 * The six colours an app offers — the partner ("shared") variant when $shared is true.
 * An app with no tone row of its own falls back to reminders'.
 */
function app_palette(string $app, bool $shared = false): array
{
    [$sMul, $lShift, $sharedF] = PAL_TONES[$app] ?? PAL_TONES['reminders'];
    $own = array_map(fn($h) => pal_tone($h, $sMul, $lShift), PAL_BASE);
    return $shared ? array_map(fn($h) => pal_lighten($h, $sharedF), $own) : $own;
}

/** True if $hex is one of an app's colours (own or shared) — used to validate a choice. */
function palette_has(string $app, string $hex): bool
{
    return in_array($hex, app_palette($app), true) || in_array($hex, app_palette($app, true), true);
}
