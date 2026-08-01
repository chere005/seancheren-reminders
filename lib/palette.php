<?php
/**
 * The suite's colour palettes.
 *
 * Every app — reminders, calendar, notes — offers the same six hues for its folders or
 * calendars: blue, red, green, orange, purple, grey. What differs is the *shade*: each app
 * sits at its own lightness tier (reminders brightest, calendar mid, notes deepest). A
 * partner's shared items use the **pastel** variant — clearly lighter *and* desaturated, a
 * soft wash rather than a slightly-lighter version of the same saturated hue — so "theirs"
 * reads at a glance as distinct from "mine" rather than as a near-twin. Generated once from
 * HSL; kept here as the single source of truth.
 */

const PALETTES = [
    'reminders' => [
        'own'    => ['#4c8bf0', '#ea5853', '#66d695', '#f39849', '#9e5ce0', '#929aaa'],
        'shared' => ['#dce9fd', '#fce0de', '#dcf6e8', '#feefd6', '#efe4fa', '#e6e9ee'],
    ],
    'calendar' => [
        'own'    => ['#2672ed', '#e5342e', '#46ce7e', '#f18322', '#8a3ad9', '#7c8598'],
        'shared' => ['#d2e2fc', '#fbd7d4', '#d3f2e2', '#fde7c6', '#e7d8f8', '#dee2e8'],
    ],
    'notes' => [
        'own'    => ['#125ed9', '#d1201a', '#31b96a', '#dd6e0e', '#7526c5', '#677183'],
        'shared' => ['#c9ddfb', '#f8ccc8', '#caefdb', '#fbdebb', '#ddc8f4', '#d6dbe2'],
    ],
];

/**
 * The six colours an app offers — the partner ("shared") variant when $shared is true.
 * An app with no tier of its own falls back to the reminders one: Habits asks for
 * app_palette('habits', true) and gets the lighter reminders set, which sits closer to
 * that app's soft violet than the saturated folder colours do.
 */
function app_palette(string $app, bool $shared = false): array
{
    $set = PALETTES[$app] ?? PALETTES['reminders'];
    return $shared ? $set['shared'] : $set['own'];
}

/** True if $hex is one of an app's colours (own or shared) — used to validate a choice. */
function palette_has(string $app, string $hex): bool
{
    return in_array($hex, app_palette($app), true) || in_array($hex, app_palette($app, true), true);
}
