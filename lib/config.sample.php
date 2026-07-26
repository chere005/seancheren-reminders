<?php
/**
 * Sample config. Copy to lib/config.php (which is gitignored) and fill in.
 *
 *   cp lib/config.sample.php lib/config.php
 *
 * This file lives OUTSIDE the web root (public/), so it is never served.
 */

return [
    // --- Logins for the gated areas. Each user gets separate reminder data. ---
    // Plain-text on purpose: easy to update later. Format: 'username' => 'password'.
    'users' => [
        'admin' => 'changeme',
    ],

    // --- Secrets: keep these private, never commit lib/config.php ---
    'nfsn_member_password' => '',   // your NearlyFreeSpeech account password
    'nfsn_api_key'         => '',   // NFSN control panel -> Profile -> API key

    // Where reminder data is stored (outside the web root).
    'data_dir' => __DIR__ . '/../data',

    // --- Outgoing mail (the sign-up verification code) ---
    // Without smtp_host the code goes out through PHP's mail(), which a shared host
    // sends unauthenticated and spam filters usually discard. Name a mailbox here and
    // it's sent over an authenticated TLS session instead.
    'mail_from'      => 'no-reply@seancheren.com',
    'mail_from_name' => 'seancheren.com',
    'smtp_host'      => '',        // e.g. smtp.nyc1.nearlyfreespeech.net
    'smtp_port'      => 587,       // 587 STARTTLS, or 465 for implicit TLS
    'smtp_user'      => '',
    'smtp_pass'      => '',

    // The clock every page runs on. The server keeps UTC; leaving this unset means
    // America/Chicago, so "today" turns over at local midnight.
    'timezone' => 'America/Chicago',
];
