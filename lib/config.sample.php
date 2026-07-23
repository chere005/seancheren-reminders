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
];
