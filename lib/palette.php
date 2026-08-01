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
        'shared' => ['#bcd5fb', '#f7c6c4', '#c6efd8', '#fbdcb6', '#dcc7f3', '#d2d6dd'],
    ],
    'calendar' => [
        'own'    => ['#2672ed', '#e5342e', '#46ce7e', '#f18322', '#8a3ad9', '#7c8598'],
        'shared' => ['#aecbf9', '#f4bab7', '#b6ebcf', '#fad2a5', '#d0b6ef', '#c5cad3'],
    ],
    'notes' => [
        'own'    => ['#125ed9', '#d1201a', '#31b96a', '#dd6e0e', '#7526c5', '#677183'],
        'shared' => ['#a7c6f7', '#eeb1ad', '#aae4c4', '#f6c896', '#c8abec', '#bcc2cd'],
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
