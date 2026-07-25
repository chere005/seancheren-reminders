<?php
/**
 * Sharing between the two people who use this suite.
 *
 * Nothing is copied: each person keeps owning their own encrypted files, and a
 * small shares-<user>.json says which of their calendars and reminder folders
 * the other one is allowed to see. The reader loads the owner's file directly.
 */

/** Who sees whose stuff. Anyone not listed here has no sharing UI at all. */
const SHARE_PAIRS = ['sean' => 'aki', 'aki' => 'sean'];

/** The other person for $user (default: the signed-in user), or null if they have none. */
function share_partner(?string $user = null): ?string
{
    $u = strtolower((string) ($user ?? current_user() ?? ''));
    return SHARE_PAIRS[$u] ?? null;
}

/** What $user has shared out: ['calendars' => [calendar ids], 'folders' => [folder names]]. */
function shares_load(string $dir, string $user): array
{
    $d = store_read(user_data_file($dir, 'shares', $user));
    return [
        'calendars' => array_values(array_filter((array) ($d['calendars'] ?? []), 'is_string')),
        'folders'   => array_values(array_filter((array) ($d['folders'] ?? []), 'is_string')),
    ];
}

function shares_save(string $dir, string $user, array $shares): void
{
    store_write(user_data_file($dir, 'shares', $user), [
        'calendars' => array_values($shares['calendars'] ?? []),
        'folders'   => array_values($shares['folders'] ?? []),
    ]);
}

/** Add or remove one key from a share list, and save. Returns the new list. */
function shares_toggle(string $dir, string $user, string $kind, string $key, bool $on): array
{
    $shares = shares_load($dir, $user);
    $k      = $kind === 'calendar' ? 'calendars' : 'folders';
    $shares[$k] = array_values(array_filter($shares[$k], fn($x) => $x !== $key));
    if ($on) { $shares[$k][] = $key; }
    shares_save($dir, $user, $shares);
    return $shares;
}

/** A display name for a username — just capitalised, these are first names. */
function share_name(string $user): string
{
    return ucfirst($user);
}
