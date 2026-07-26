<?php
/**
 * Shared session auth for gated areas.
 *
 * Usage at the top of a protected page:
 *
 *   require_once __DIR__ . '/../../lib/auth.php';
 *   require_login('Reminders');   // renders login + exits if not signed in
 *
 * After this returns, the visitor is authenticated and app_config() is available.
 */

require_once __DIR__ . '/store.php';   // encrypted-at-rest storage helpers
require_once __DIR__ . '/util.php';    // small shared helpers (time parsing, …)

function app_config(): array
{
    static $config = null;
    if ($config === null) {
        $local = __DIR__ . '/config.php';
        $config = require (is_file($local) ? $local : __DIR__ . '/config.sample.php');
    }
    return $config;
}

// Everything in the suite runs on one clock. The server keeps UTC, so without this
// "today" rolls over in the evening and the calendar advances a day early.
date_default_timezone_set(app_config()['timezone'] ?? 'America/Chicago');

/** Current path without query string, for safe self-redirects. */
function _self_path(): string
{
    return strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
}

/** The user => password map, with backward-compat for the old single-user config. */
function app_users(array $cfg): array
{
    if (!empty($cfg['users']) && is_array($cfg['users'])) {
        return $cfg['users'];
    }
    if (isset($cfg['auth_username'])) {
        return [(string) $cfg['auth_username'] => (string) ($cfg['auth_password'] ?? '')];
    }
    return [];
}

/**
 * Passwords people have changed themselves, keyed by username.
 *
 * config.php seeds the accounts, but it is hand-kept on the server and deliberately
 * never deployed — so a change lands in the encrypted data directory instead, and
 * wins over the config entry. Deleting passwords.json falls back to config.
 */
function auth_passwords_file(array $cfg): string
{
    return rtrim($cfg['data_dir'], '/') . '/passwords.json';
}

/** The password to check $user against: their own if they've set one, else config's. */
function auth_password_for(array $cfg, string $user): ?string
{
    $users = app_users($cfg);
    if (!isset($users[$user])) {
        return null;                       // not an account at all
    }
    $own = store_read(auth_passwords_file($cfg));
    return isset($own[$user]) ? (string) $own[$user] : (string) $users[$user];
}

/** Store a new password for $user. */
function auth_password_set(array $cfg, string $user, string $password): void
{
    $file = auth_passwords_file($cfg);
    $own  = store_read($file);
    $own[$user] = $password;
    store_write($file, $own);
}

/** Username of the signed-in user (null if not logged in). */
function current_user(): ?string
{
    return $_SESSION['user'] ?? null;
}

/**
 * Per-user data file path, e.g. reminders -> data/reminders-jacob.json.
 * The username is sanitized so it is always a safe filename.
 * $user names someone other than the signed-in user (sharing reads their files).
 */
function user_data_file(string $dir, string $base, ?string $user = null): string
{
    $u    = $user ?? (current_user() ?? 'default');
    $safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $u);
    return rtrim($dir, '/') . "/{$base}-{$safe}.json";
}

function require_login(string $area = 'App'): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    // Logout
    if (isset($_GET['logout'])) {
        $_SESSION = [];
        session_destroy();
        header('Location: ' . _self_path());
        exit;
    }

    $cfg = app_config();

    // Login submission
    $error = '';
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
        && isset($_POST['username'], $_POST['password'])) {
        $u = (string) $_POST['username'];
        $p = (string) $_POST['password'];
        $want = auth_password_for($cfg, $u);
        if ($want !== null && hash_equals($want, $p)) {
            session_regenerate_id(true);
            $_SESSION['auth'] = true;
            $_SESSION['user'] = $u;
            header('Location: ' . _self_path());
            exit;
        }
        $error = 'Invalid username or password.';
    }

    if (empty($_SESSION['auth'])) {
        render_login($area, $error);
        exit;
    }

    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }

    // The settings window's password change. It's handled here rather than in each
    // app because the window rides in the top bar of every one of them.
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
        && ($_POST['action'] ?? '') === 'change_password') {
        header('Content-Type: application/json');
        if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Bad request.']);
            exit;
        }
        $me  = (string) current_user();
        $cur = (string) ($_POST['current'] ?? '');
        $new = (string) ($_POST['new'] ?? '');
        $want = auth_password_for($cfg, $me);
        if ($want === null || !hash_equals($want, $cur)) {
            echo json_encode(['ok' => false, 'error' => 'That is not your current password.']);
        } elseif (strlen($new) < 6) {
            echo json_encode(['ok' => false, 'error' => 'Use at least 6 characters.']);
        } else {
            auth_password_set($cfg, $me, $new);
            echo json_encode(['ok' => true]);
        }
        exit;
    }
}

function render_login(string $area, string $error = ''): void
{
    $action = htmlspecialchars(_self_path(), ENT_QUOTES);
    $area   = htmlspecialchars($area, ENT_QUOTES);
    header('Content-Type: text/html; charset=utf-8');
    ?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>Sign in</title>
  <meta name="theme-color" content="#111111">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black">
  <meta name="apple-mobile-web-app-title" content="Reminders">
  <link rel="apple-touch-icon" href="/reminders/icon-180.png">
  <link rel="icon" href="/reminders/icon-192.png">
  <link rel="manifest" href="/reminders/manifest.webmanifest?v=2">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: system-ui, sans-serif; background: #111; color: #eee;
      min-height: 100vh; display: flex; align-items: center; justify-content: center;
    }
    .login-box {
      background: #1a1a1a; border: 1px solid #333; border-radius: 8px;
      padding: 2rem; width: 100%; max-width: 320px;
    }
    .login-box h1 { font-size: 1.25rem; margin-bottom: 0.25rem; text-align: center; }
    .login-box .area { font-size: 0.8rem; color: #888; margin-bottom: 1.5rem; text-align: center; }
    .login-box label { display: block; font-size: 0.8rem; color: #aaa; margin-bottom: 0.25rem; }
    .login-box input {
      width: 100%; padding: 0.5rem 0.75rem; background: #222; border: 1px solid #444;
      border-radius: 4px; color: #eee; font-size: 1rem; margin-bottom: 1rem;
    }
    .login-box input:focus { outline: none; border-color: #888; }
    .login-box button {
      width: 100%; padding: 0.6rem; background: #eee; color: #111; border: none;
      border-radius: 4px; font-size: 1rem; cursor: pointer;
    }
    .login-box button:hover { background: #fff; }
    .error { color: #f66; font-size: 0.85rem; margin-top: 0.75rem; text-align: center; }
  </style>
</head>
<body>
  <div class="login-box">
    <h1>Sign in</h1>
    <div class="area"><?= $area ?></div>
    <form method="post" action="<?= $action ?>">
      <label for="username">Username</label>
      <input id="username" type="text" name="username" autocomplete="username" required autofocus>
      <label for="password">Password</label>
      <input id="password" type="password" name="password" autocomplete="current-password" required>
      <button type="submit">Log in</button>
    </form>
    <?php if ($error !== ''): ?>
      <p class="error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
    <?php endif; ?>
  </div>
</body>
</html>
    <?php
}
