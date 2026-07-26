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
require_once __DIR__ . '/mail.php';    // sending the sign-up verification code
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

/**
 * Accounts people made themselves, keyed by username: ['email' => …, 'password' => …].
 * config.php seeds the household accounts and is hand-kept on the server; anything
 * signed up for through the login page lands here instead, in the encrypted data dir.
 */
function accounts_load(array $cfg): array
{
    $a = store_read(rtrim($cfg['data_dir'], '/') . '/accounts.json');
    return is_array($a) ? $a : [];
}

function accounts_save(array $cfg, array $accounts): void
{
    store_write(rtrim($cfg['data_dir'], '/') . '/accounts.json', $accounts);
}

/** The user => password map, with backward-compat for the old single-user config. */
function app_users(array $cfg): array
{
    // Signed-up accounts sit alongside the configured ones; config wins on a clash.
    $signed = [];
    foreach (accounts_load($cfg) as $u => $a) { $signed[$u] = (string) ($a['password'] ?? ''); }
    if (!empty($cfg['users']) && is_array($cfg['users'])) {
        return $cfg['users'] + $signed;
    }
    if ($signed && !isset($cfg['auth_username'])) {
        return $signed;
    }
    if (isset($cfg['auth_username'])) {
        return [(string) $cfg['auth_username'] => (string) ($cfg['auth_password'] ?? '')] + $signed;
    }
    return $signed;
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

/**
 * Colour themes, chosen per user in the settings window and kept in
 * data/prefs-<user>.json. A theme is just the accent and the ink that goes on it:
 * every green in the suite reads --accent / --accent-ink rather than a literal, so
 * one variable repaints all of it. The reminder/event/note palette stays put — it
 * says what kind of thing something is, not which theme you like.
 */
const THEMES = [
    'green'  => ['Green',  '#34d399', '#06251b', '#14251f'],
    'blue'   => ['Blue',   '#60a5fa', '#0b2038', '#10203a'],
    'purple' => ['Purple', '#c084fc', '#25123a', '#221430'],
    'amber'  => ['Amber',  '#fbbf24', '#2a1c00', '#241c05'],
    'rose'   => ['Rose',   '#fb7185', '#3a0f1a', '#2e1218'],
];

function theme_file(): string
{
    return rtrim(app_config()['data_dir'], '/') . '/prefs-'
         . preg_replace('/[^A-Za-z0-9_-]/', '_', current_user() ?? 'default') . '.json';
}

function theme_get(): string
{
    $t = (string) (store_read(theme_file())['theme'] ?? '');
    return isset(THEMES[$t]) ? $t : 'green';
}

function theme_set(string $name): bool
{
    if (!isset(THEMES[$name])) { return false; }
    $p = store_read(theme_file());
    $p['theme'] = $name;
    store_write(theme_file(), $p);
    return true;
}

/** The chosen theme as variables. Emit it before anything that reads them. */
function theme_css(): string
{
    [, $accent, $ink, $soft] = THEMES[theme_get()];
    return "    :root { --accent: $accent; --accent-ink: $ink; --accent-soft: $soft; }\n";
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
        [$stage, $suErr, $suUser] = signup_handle($cfg);
        render_login($area, $suErr !== '' ? $suErr : $error, $stage, $suUser);
        exit;
    }

    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }

    // The settings window's theme pick, answered here for the same reason.
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'set_theme') {
        header('Content-Type: application/json');
        $ok = hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))
              && theme_set((string) ($_POST['theme'] ?? ''));
        echo json_encode(['ok' => $ok]);
        exit;
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


/**
 * Self-serve sign-up. A new account isn't real until the four-digit code emailed to
 * the address has come back, so the half-made account waits in data/signups.json
 * (encrypted like everything else) with its code and a fifteen-minute expiry.
 */
function signups_file(array $cfg): string
{
    return rtrim($cfg['data_dir'], '/') . '/signups.json';
}

/** Tidy a wanted username; '' if it isn't one we'll allow. */
function signup_clean_user(string $u): string
{
    $u = strtolower(trim($u));
    return preg_match('/^[a-z0-9_-]{2,20}$/', $u) ? $u : '';
}

/** Post the code out through lib/mail.php — SMTP if config names a server. */
function signup_send_code(array $cfg, string $email, string $code): bool
{
    $body = "Your verification code is $code\n\n"
          . "It's good for fifteen minutes. If you didn't ask for an account, ignore this.\n";
    return mail_send($cfg, $email, 'Your verification code', $body);
}

/**
 * Handle the login page's sign-up and verify posts. Returns [$stage, $error, $user]:
 * $stage is 'verify' once a code is out, so the page can open the code window.
 */
function signup_handle(array $cfg): array
{
    $action = (string) ($_POST['action'] ?? '');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !in_array($action, ['signup', 'verify'], true)) {
        return ['login', '', ''];
    }
    $pending = store_read(signups_file($cfg));
    if (!is_array($pending)) { $pending = []; }
    // Anything past its fifteen minutes is gone, whichever way we came in.
    $pending = array_filter($pending, fn($p) => (int) ($p['expires'] ?? 0) > time());

    if ($action === 'signup') {
        $user  = signup_clean_user((string) ($_POST['newuser'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $pass  = (string) ($_POST['newpass'] ?? '');
        if ($user === '') {
            return ['signup', 'Pick a username: 2-20 letters, numbers, - or _.', ''];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['signup', 'That email address doesn\'t look right.', ''];
        }
        if (strlen($pass) < 6) {
            return ['signup', 'Use a password of at least 6 characters.', ''];
        }
        if (isset(app_users($cfg)[$user])) {
            return ['signup', 'That username is taken.', ''];
        }
        $code = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $pending[$user] = ['email' => $email, 'password' => $pass, 'code' => $code,
                           'expires' => time() + 900, 'tries' => 0];
        store_write(signups_file($cfg), $pending);
        if (!signup_send_code($cfg, $email, $code)) {
            return ['signup', 'Couldn\'t send the email. Try again in a moment.', ''];
        }
        return ['verify', '', $user];
    }

    // action === 'verify'
    $user = signup_clean_user((string) ($_POST['newuser'] ?? ''));
    $p    = $pending[$user] ?? null;
    if (!$p) {
        return ['login', 'That code expired. Start again.', ''];
    }
    if ((int) $p['tries'] >= 5) {
        unset($pending[$user]);
        store_write(signups_file($cfg), $pending);
        return ['login', 'Too many wrong codes. Start again.', ''];
    }
    if (!hash_equals((string) $p['code'], trim((string) ($_POST['code'] ?? '')))) {
        $pending[$user]['tries'] = (int) $p['tries'] + 1;
        store_write(signups_file($cfg), $pending);
        return ['verify', 'That code doesn\'t match.', $user];
    }
    $accounts = accounts_load($cfg);
    $accounts[$user] = ['email' => $p['email'], 'password' => $p['password'], 'created' => time()];
    accounts_save($cfg, $accounts);
    unset($pending[$user]);
    store_write(signups_file($cfg), $pending);

    session_regenerate_id(true);
    $_SESSION['auth'] = true;
    $_SESSION['user'] = $user;
    header('Location: ' . _self_path());
    exit;
}

function render_login(string $area, string $error = '', string $stage = 'login', string $pendingUser = ''): void
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
    /* Create account: a quieter button under Log in, and the form it reveals. */
    .login-box .makebtn { background: none; border: 1px solid #444; color: #aaa; margin-top: 0.6rem; }
    .login-box .makebtn:hover { background: #222; color: #eee; }
    /* Creating an account is a window over the page, the same shape as the one
       that then waits for the code — the login box itself never changes size. */
    .modalback {
      position: fixed; inset: 0; background: rgba(0,0,0,0.7); display: flex;
      align-items: center; justify-content: center; padding: 1rem; z-index: 20;
    }
    .modalback[hidden] { display: none; }
    .modalbox {
      background: #1a1a1a; border: 1px solid #333; border-radius: 8px; padding: 1.5rem;
      width: 100%; max-width: 320px;
    }
    .modalbox h2 { font-size: 1.05rem; margin-bottom: 0.4rem; text-align: center; }
    .modalbox p { font-size: 0.82rem; color: #888; margin-bottom: 1rem; text-align: center; }
    .modalbox label { display: block; font-size: 0.8rem; color: #aaa; margin-bottom: 0.25rem; }
    .modalbox input {
      width: 100%; padding: 0.5rem 0.75rem; background: #222; border: 1px solid #444;
      border-radius: 4px; color: #eee; font-size: 1rem; margin-bottom: 1rem;
    }
    .modalbox input:focus { outline: none; border-color: #888; }
    .modalbox button {
      width: 100%; padding: 0.6rem; background: #eee; color: #111; border: none;
      border-radius: 4px; font-size: 1rem; cursor: pointer;
    }
    .modalbox .cancel { background: none; border: 1px solid #444; color: #aaa; margin-top: 0.6rem; }
    /* Four characters wide and no wider — a full-width box with half an em of
       letter-spacing pushed the last digit past the edge on a phone. */
    /* Full width like the button under it; the text-indent balances the trailing
       letter-space so the four digits sit centred rather than a nudge to the left. */
    .codebox input {
      width: 100%; text-align: center; font-size: 1.3rem;
      letter-spacing: 0.4em; text-indent: 0.4em;
    }
  </style>
</head>
<body>
  <div class="login-box">
    <h1>Sign in</h1>
    <div class="area"><?= $area ?></div>
    <form method="post" action="<?= $action ?>">
      <label for="username">Username</label>
      <?php // No shift key on the way in — usernames here are all lower case. ?>
      <input id="username" type="text" name="username" autocomplete="username"
             autocapitalize="none" autocorrect="off" spellcheck="false" required autofocus>
      <label for="password">Password</label>
      <input id="password" type="password" name="password" autocomplete="current-password" required>
      <button type="submit">Log in</button>
    </form>
    <button type="button" class="makebtn" id="makeBtn">Create account</button>
    <?php if ($error !== '' && $stage !== 'signup' && $stage !== 'verify'): ?>
      <p class="error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
    <?php endif; ?>
  </div>
  <div class="modalback" id="signBack"<?= $stage === 'signup' ? '' : ' hidden' ?>>
    <div class="modalbox">
    <form method="post" action="<?= $action ?>">
      <h2>Create an account</h2>
      <p>Pick a name and we'll email you a code.</p>
      <?php if ($stage === 'signup' && $error !== ''): ?>
        <p class="error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
      <?php endif; ?>
      <input type="hidden" name="action" value="signup">
      <label for="newuser">Username</label>
      <input id="newuser" type="text" name="newuser" autocapitalize="none" autocorrect="off"
             spellcheck="false" maxlength="20" required>
      <label for="email">Email</label>
      <input id="email" type="email" name="email" autocomplete="email" required>
      <label for="newpass">Password</label>
      <input id="newpass" type="password" name="newpass" autocomplete="new-password" minlength="6" required>
      <button type="submit">Send verification code</button>
      <button type="button" class="cancel" data-close>Cancel</button>
    </form>
    </div>
  </div>
  <?php // The account isn't made until this comes back matching what we emailed. ?>
  <div class="modalback" id="codeBack"<?= $stage === 'verify' ? '' : ' hidden' ?>>
    <div class="modalbox codebox">
      <h2>Check your email</h2>
      <p>Enter the four-digit code we sent you.</p>
      <?php if ($stage === 'verify' && $error !== ''): ?>
        <p class="error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
      <?php endif; ?>
      <form method="post" action="<?= $action ?>">
        <input type="hidden" name="action" value="verify">
        <input type="hidden" name="newuser" value="<?= htmlspecialchars($pendingUser, ENT_QUOTES) ?>">
        <input type="text" name="code" inputmode="numeric" maxlength="4" autocomplete="one-time-code"
               required autofocus>
        <button type="submit">Verify</button>
        <button type="button" class="cancel" data-close>Cancel</button>
      </form>
    </div>
  </div>
  <script>(function () {
    var b = document.getElementById('makeBtn'), f = document.getElementById('signBack');
    b.addEventListener('click', function () { f.hidden = false; f.querySelector('#newuser').focus(); });
    // Either window closes on its backdrop or its Cancel — nothing here is a
    // dead end, since a code that never arrives has to be escapable.
    document.querySelectorAll('.modalback').forEach(function (m) {
      m.addEventListener('click', function (e) {
        if (e.target === m || e.target.hasAttribute('data-close')) { m.hidden = true; }
      });
    });
  })();</script>
</body>
</html>
    <?php
}
