<?php
/**
 * The suite's colour palettes.
 *
 * Every app — reminders, calendar, notes — offers the same six hues for its folders or
 * calendars: blue, red, green, orange, purple, grey. What differs is the *shade*: each
 * app sits at its own lightness tier (reminders brightest, calendar mid, notes deepest),
 * and a partner's shared items are one step lighter again. So a blue reminder folder, a
 * blue calendar and a blue note folder are each recognisable, and "mine" reads a touch
 * deeper than "shared". Generated once from HSL; kept here as the single source of truth.
 */

const PALETTES = [
    'reminders' => [
        'own'    => ['#4c8bf0', '#ea5853', '#66d695', '#f39849', '#9e5ce0', '#929aaa'],
        'shared' => ['#8eb6f6', '#f29592', '#9ee5bc', '#f8be8c', '#c298eb', '#babfc9'],
    ],
    'calendar' => [
        'own'    => ['#2672ed', '#e5342e', '#46ce7e', '#f18322', '#8a3ad9', '#7c8598'],
        'shared' => ['#689df3', '#ed726e', '#7edda6', '#f5a966', '#ad76e5', '#a4aab7'],
    ],
    'notes' => [
        'own'    => ['#125ed9', '#d1201a', '#31b96a', '#dd6e0e', '#7526c5', '#677183'],
        'shared' => ['#4285f0', '#e94f49', '#5ed48f', '#f3933f', '#9954de', '#8d95a5'],
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
