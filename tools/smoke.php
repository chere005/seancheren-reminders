<?php
/**
 * A read-only health check for a *live* site.
 *
 *   php tools/smoke.php https://seancheren.com
 *   php tools/smoke.php https://seancheren.com/test
 *   php tools/smoke.php https://seancheren.com --user example --pass examplepassword
 *   php tools/smoke.php https://a.example --compare https://b.example    # do two match?
 *
 * **This is not tools/test.php and must not be confused with it.** The test run creates,
 * renames and deletes real rows; it is safe only because it boots its own server against
 * a scratch data directory. Pointed at a live site it would chew through real data.
 *
 * This one only ever GETs, plus a login POST and a logout when you hand it credentials —
 * neither of which changes anything. It is safe to run against production, and it is the
 * right thing to run straight after a deploy, because it answers the two questions a
 * deploy raises: is the site actually up and unbroken, and is this really the code I just
 * sent? Exit code is 0 when everything passed, 1 otherwise.
 */

$args = array_slice($argv, 1);
$base = '';
$user = $pass = $compare = '';
for ($i = 0; $i < count($args); $i++) {
    if ($args[$i] === '--user' && isset($args[$i + 1]))    { $user = $args[++$i]; }
    elseif ($args[$i] === '--pass' && isset($args[$i + 1])) { $pass = $args[++$i]; }
    elseif ($args[$i] === '--compare' && isset($args[$i + 1])) { $compare = rtrim($args[++$i], '/'); }
    elseif ($base === '')                                   { $base = rtrim($args[$i], '/'); }
}
if ($base === '') {
    fwrite(STDERR, "usage: php tools/smoke.php <base-url> [--user u --pass p] [--compare <other-url>]\n");
    exit(2);
}

// ---------------------------------------------------------------- http

/** One request. Redirects are not followed — where a page sends you is part of the check. */
function get(string $url, ?array &$jar = null, string $method = 'GET', array $post = []): array
{
    $headers = ['User-Agent: seancheren-smoke/1', 'Connection: close'];
    if ($jar) {
        $bits = [];
        foreach ($jar as $k => $v) { $bits[] = "$k=$v"; }
        $headers[] = 'Cookie: ' . implode('; ', $bits);
    }
    $body = '';
    if ($method === 'POST') {
        $body = http_build_query($post);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $headers[] = 'Content-Length: ' . strlen($body);
    }
    $ctx = stream_context_create([
        'http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body,
                   'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 20],
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $out = @file_get_contents($url, false, $ctx);
    $hdr = $http_response_header ?? [];
    $res = ['status' => 0, 'body' => (string) $out, 'headers' => $hdr, 'location' => null, 'cookie' => ''];
    foreach ($hdr as $i => $h) {
        if ($i === 0 && preg_match('#HTTP/\S+\s+(\d{3})#', $h, $m)) { $res['status'] = (int) $m[1]; }
        if (stripos($h, 'Location:') === 0) { $res['location'] = trim(substr($h, 9)); }
        if (stripos($h, 'Set-Cookie:') === 0) {
            $res['cookie'] .= $h . "\n";
            if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $h, $m) && $jar !== null) {
                $jar[trim($m[1])] = $m[2];
            }
        }
    }
    return $res;
}

// ---------------------------------------------------------------- reporting

$pass_n = 0; $fail_n = 0; $notes = [];
function check(string $label, bool $ok, string $detail = ''): bool
{
    global $pass_n, $fail_n;
    if ($ok) { $pass_n++; echo "  \033[32m✓\033[0m $label\n"; }
    else     { $fail_n++; echo "  \033[31m✗\033[0m $label" . ($detail !== '' ? "\n      \033[31m$detail\033[0m" : '') . "\n"; }
    return $ok;
}
function note(string $s): void { global $notes; $notes[] = $s; }

/** Anything PHP should never have said out loud to a browser. */
const LEAKS = ['Fatal error', 'Parse error', 'Warning:', 'Notice:', 'Deprecated:',
               'Stack trace', '/home/protected', 'PDOException', 'Uncaught'];

/**
 * Markers of recent work. Present on a server that has today's code, absent on one that
 * is behind — which is the whole point of running this after a deploy. Extend it as you
 * ship: anything distinctive and stable in the *rendered* output will do.
 */
function FINGERPRINT(): array
{
    return [
        '/'           => ['seancheren'],
        '/reminders/' => ['100svh', 'scrollbar-width: none'],       // the login page rework
    ];
}

/** The same, but only visible once you're signed in. */
function FINGERPRINT_IN(): array
{
    return [
        '/reminders/' => ['folder-label', 'plus_icon', 'subtask-btn'],
        '/habits/'    => ['--hc-soft', 'drop-line', 'habitadd'],
        '/calendar/'  => ['calDay', 'data-tab="calendar"'],
        '/notes/'     => ['sec-add'],
    ];
}

// ---------------------------------------------------------------- the checks

/** Everything that doesn't need a login. Returns a fingerprint map for --compare. */
function run_public(string $base): array
{
    $seen = [];
    echo "\n\033[1m$base — reachable and quiet\033[0m\n";

    foreach (['/', '/about/', '/projects/', '/contact/', '/chat/'] as $p) {
        $r = get($base . $p);
        check("GET $p is 200", $r['status'] === 200, "got {$r['status']}");
        foreach (LEAKS as $l) {
            if (strpos($r['body'], $l) !== false) { check("$p leaks \"$l\"", false); }
        }
    }

    echo "\n\033[1m$base — the app pages gate on a login\033[0m\n";
    foreach (['/reminders/', '/notes/', '/calendar/', '/habits/', '/add/'] as $p) {
        $r = get($base . $p);
        $ok = $r['status'] === 200 && strpos($r['body'], 'Sign in') !== false;
        check("GET $p shows the login form", $ok, "status {$r['status']}");
        // The app itself must not be in the page for someone who isn't signed in.
        foreach (['rlist-root', 'notes-root', 'wGrid', 'dpList'] as $marker) {
            if (strpos($r['body'], $marker) !== false) {
                check("$p leaks the app to a signed-out visitor ($marker)", false);
            }
        }
        foreach (LEAKS as $l) {
            if (strpos($r['body'], $l) !== false) { check("$p leaks \"$l\"", false); }
        }
    }

    echo "\n\033[1m$base — the web-root boundary holds\033[0m\n";
    // lib/ and data/ live outside /home/public. Nothing under them may be fetchable, and
    // a 200 here is the most serious thing this script can find: it would mean the
    // encryption key, the config or someone's data is downloadable.
    foreach (['/lib/config.php', '/lib/auth.php', '/lib/store.php', '/data/accounts.json',
              '/data/.datakey', '/../lib/config.php', '/tools/seed-example.php'] as $p) {
        $r = get($base . $p);
        // A 404, an empty body, or the site's own HTML (a catch-all rewrite) are all fine.
        // What matters is whether *source or data* came back — PHP source, the encrypted
        // store's prefix, a JSON blob, or something that looks like the key.
        $b    = $r['body'];
        $leak = strpos($b, '<?php') !== false
             || strpos($b, 'ENC1:') !== false
             || strpos($b, 'data_key') !== false
             || (strncmp(ltrim($b), '{', 1) === 0 && strpos($b, '<html') === false)
             || (trim($b) !== '' && strpos($b, '<') === false && strlen(trim($b)) > 20);
        $ok = $r['status'] >= 400 || !$leak;
        check("$p is not downloadable", $ok, "status {$r['status']}, " . strlen($b) . ' bytes');
        if (!$ok) { note("SERIOUS: $base$p served " . strlen($b) . ' bytes of real content'); }
    }

    echo "\n\033[1m$base — the login page\033[0m\n";
    $r = get($base . '/reminders/');
    check('has a username and password field',
          strpos($r['body'], 'name="username"') !== false && strpos($r['body'], 'name="password"') !== false);
    check('sets a session cookie', $r['cookie'] !== '', 'no Set-Cookie at all');
    if ($r['cookie'] !== '') {
        check('the session cookie is HttpOnly', stripos($r['cookie'], 'httponly') !== false);
        check('the session cookie is SameSite', stripos($r['cookie'], 'samesite') !== false);
        if (strncmp($base, 'https://', 8) === 0) {
            check('the session cookie is Secure over https', stripos($r['cookie'], 'secure') !== false);
        }
    }

    // The /test/ mirror is the same source under a prefix. Its links have to stay inside
    // it: an unprefixed cross-app link there is a door into production that looks like it
    // worked. Production has to be the mirror image — no /test anywhere.
    echo "\n\033[1m$base — the instance keeps to itself\033[0m\n";
    // Signed out there is no tab bar, but the login page's own asset links are built
    // through suite_base() too, so they say which instance answered.
    $pfx  = rtrim((string) parse_url($base, PHP_URL_PATH), '/');
    $body = get($base . '/reminders/')['body'];
    if ($pfx !== '') {
        check("the login page's assets are under $pfx",
              strpos($body, 'href="' . $pfx . '/reminders/icon-180.png"') !== false,
              'this looks like production answering under a /test URL');
        check('and none of them point at the site root',
              !preg_match('#href="/reminders/(icon|manifest)#', $body));
    } else {
        check('production assets are unprefixed',
              strpos($body, 'href="/reminders/icon-180.png"') !== false);
        check('and production carries no /test link', strpos($body, '/test/') === false);
    }

    echo "\n\033[1m$base — is this today's code?\033[0m\n";
    foreach (FINGERPRINT() as $p => $markers) {
        $b = get($base . $p)['body'];
        foreach ($markers as $mk) {
            $hit = strpos($b, $mk) !== false;
            $seen["$p:$mk"] = $hit;
            check("$p carries \"$mk\"", $hit, 'this server looks behind');
        }
    }
    return $seen;
}

/** The signed-in half. Only GETs, plus the login and the logout. */
function run_private(string $base, string $user, string $pass): array
{
    $seen = [];
    echo "\n\033[1m$base — signed in as $user (read-only)\033[0m\n";
    $jar = [];
    get($base . '/reminders/', $jar);
    $r = get($base . '/reminders/', $jar, 'POST', ['username' => $user, 'password' => $pass]);
    if (!check('the password is accepted', $r['status'] === 302,
               "status {$r['status']} — if this instance has its own data dir, it may simply "
               . 'never have been seeded (see the seeding note in CLAUDE.md); an unseeded '
               . 'instance has no accounts and every signed-in check below is skipped')) {
        note("$base could not sign in as $user, so nothing behind the login was checked"
             . ' — and a --compare against it will read as a code difference when it isn\'t one.');
        return $seen;
    }
    check('and lands on the Calendar', $r['location'] === '/calendar/'
          || substr((string) $r['location'], -10) === '/calendar/', "went to {$r['location']}");

    foreach (['/reminders/', '/notes/', '/calendar/', '/habits/', '/add/'] as $p) {
        $g = get($base . $p, $jar);
        $ok = $g['status'] === 200 && strpos($g['body'], 'name="password"') === false;
        check("GET $p renders the app", $ok, "status {$g['status']}");
        foreach (LEAKS as $l) {
            if (strpos($g['body'], $l) !== false) { check("$p leaks \"$l\"", false); }
        }
    }

    foreach (FINGERPRINT_IN() as $p => $markers) {
        $b = get($base . $p, $jar)['body'];
        foreach ($markers as $mk) {
            $hit = strpos($b, $mk) !== false;
            $seen["$p:$mk"] = $hit;
            check("$p carries \"$mk\"", $hit, 'this server looks behind');
        }
    }

    // Signed in the tab bar is there, and it is the link set that actually moves someone
    // between apps — so this is where a prefix leak would really bite.
    $pfx = rtrim((string) parse_url($base, PHP_URL_PATH), '/');
    $b   = get($base . '/reminders/', $jar)['body'];
    foreach (['reminders', 'calendar', 'notes', 'habits', 'add'] as $app) {
        check("the tab bar links to $pfx/$app/",
              strpos($b, 'href="' . $pfx . '/' . $app . '/"') !== false);
    }
    if ($pfx !== '') {
        check('no unprefixed cross-app link leaks out of the mirror',
              !preg_match('#href="/(reminders|calendar|notes|habits|add)/"#', $b),
              'a tap here would drop the user into production');
    }

    get($base . '/reminders/?logout=1', $jar);
    $out = get($base . '/reminders/', $jar);
    check('logging out ends the session', strpos($out['body'], 'Sign in') !== false);
    return $seen;
}

// ---------------------------------------------------------------- run

$a = run_public($base);
if ($user !== '' && $pass !== '') { $a += run_private($base, $user, $pass); }

if ($compare !== '') {
    $b = run_public($compare);
    if ($user !== '' && $pass !== '') { $b += run_private($compare, $user, $pass); }
    echo "\n\033[1mdo the two servers match?\033[0m\n";
    $diff = [];
    foreach ($a as $k => $v) { if (($b[$k] ?? null) !== $v) { $diff[] = $k; } }
    check('both servers carry the same code', !$diff,
          $diff ? 'they differ on: ' . implode(', ', $diff) : '');
}

echo "\n" . str_repeat('─', 60) . "\n";
printf("%d passed, %d failed\n", $pass_n, $fail_n);
foreach ($notes as $n) { echo "  \033[31m$n\033[0m\n"; }
if ($fail_n === 0) { echo "Nothing here writes, so a clean run says the site is up and current — not that\n"
                        . "it behaves. tools/test.php is what proves behaviour, on a scratch dir.\n"; }
exit($fail_n ? 1 : 0);
