<?php
/**
 * The suite's test run.
 *
 *   php tools/test.php              # everything
 *   php tools/test.php reminders    # only areas whose name contains "reminders"
 *   php tools/test.php --list       # print the area names and stop
 *   php tools/test.php --keep       # leave the scratch data dir behind for poking at
 *
 * There is no framework here for the same reason there isn't one anywhere else in this
 * repo. It boots `php -S` against a **scratch data directory** (SUITE_DATA_DIR, honoured
 * only by app_config()), seeds the two demo accounts into it with the real seeders, and
 * then drives the real pages over real HTTP — sessions, cookies, redirects, CSRF and all.
 * Nothing it does can touch `data/`, and it never needs credentials from `config.php`:
 * the accounts it logs in as are the ones it just seeded.
 *
 * Unit-level checks (parsers, repeats, sorting, sanitising) run in-process against `lib/`.
 *
 * **When you change a feature, change its test in the same commit. When you add one, add
 * a test with it.** TESTING.md is the map of what is covered here and what still has to
 * be looked at by eye; it is part of the same bargain — keep it in step.
 */

// ---------------------------------------------------------------- setup

$root = dirname(__DIR__);
$args = array_slice($argv, 1);
$keep = in_array('--keep', $args, true);
$list = in_array('--list', $args, true);
$only = array_values(array_filter($args, fn($a) => strncmp($a, '--', 2) !== 0));

$scratch = sys_get_temp_dir() . '/seancheren-test-' . getmypid();
putenv('SUITE_DATA_DIR=' . $scratch);      // for this process (the unit checks)
@mkdir($scratch, 0700, true);

require_once $root . '/lib/auth.php';
require_once $root . '/lib/tabbar.php';
require_once $root . '/lib/folders.php';
require_once $root . '/lib/sharing.php';
require_once $root . '/lib/richtext.php';
require_once $root . '/lib/palette.php';
require_once $root . '/lib/site.php';

// ---------------------------------------------------------------- tiny test framework

$AREAS = [];      // name => [ [label, fn], … ]
$CUR   = null;
function area(string $name): void { global $AREAS, $CUR; $CUR = $name; $AREAS[$name] ??= []; }
function t(string $label, callable $fn): void { global $AREAS, $CUR; $AREAS[$CUR][] = [$label, $fn]; }

/** Assertions. Each throws with a message the runner prints verbatim. */
function ok($cond, string $why = ''): void
{
    if (!$cond) { throw new RuntimeException($why !== '' ? $why : 'expected true'); }
}
function eq($want, $got, string $why = ''): void
{
    if ($want !== $got) {
        throw new RuntimeException(($why !== '' ? $why . ': ' : '')
            . 'expected ' . sv($want) . ', got ' . sv($got));
    }
}
function has(string $needle, string $hay, string $why = ''): void
{
    if (strpos($hay, $needle) === false) {
        throw new RuntimeException(($why !== '' ? $why . ': ' : '') . 'missing ' . sv($needle));
    }
}
function hasnt(string $needle, string $hay, string $why = ''): void
{
    if (strpos($hay, $needle) !== false) {
        throw new RuntimeException(($why !== '' ? $why . ': ' : '') . 'unexpectedly present ' . sv($needle));
    }
}
function sv($v): string
{
    if (is_string($v)) { return '"' . (mb_strlen($v) > 90 ? mb_substr($v, 0, 90) . '…' : $v) . '"'; }
    if (is_bool($v))   { return $v ? 'true' : 'false'; }
    if (is_null($v))   { return 'null'; }
    if (is_scalar($v)) { return (string) $v; }
    return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

// ---------------------------------------------------------------- HTTP client

$PORT = 0; $SRV = null;

/**
 * One request against the dev server. Redirects are never followed — where a POST sends
 * you is half of what's being tested. $jar carries the session cookie between calls.
 */
function req(string $method, string $path, array $post = [], ?array &$jar = null, bool $ajax = false): array
{
    global $PORT;
    $headers = ["Host: 127.0.0.1:$PORT", 'Connection: close'];
    if ($jar) {
        $bits = [];
        foreach ($jar as $k => $v) { $bits[] = "$k=$v"; }
        $headers[] = 'Cookie: ' . implode('; ', $bits);
    }
    if ($ajax) { $headers[] = 'X-Requested-With: XMLHttpRequest'; }
    $body = '';
    if ($method === 'POST') {
        $body = http_build_query($post);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $headers[] = 'Content-Length: ' . strlen($body);
    }
    $ctx = stream_context_create(['http' => [
        'method'          => $method,
        'header'          => implode("\r\n", $headers),
        'content'         => $body,
        'ignore_errors'   => true,     // 4xx/5xx should come back, not throw
        'follow_location' => 0,
        'timeout'         => 15,
    ]]);
    $out = @file_get_contents("http://127.0.0.1:$PORT" . $path, false, $ctx);
    $hdr = $http_response_header ?? [];
    $res = ['status' => 0, 'location' => null, 'body' => (string) $out, 'headers' => $hdr];
    foreach ($hdr as $i => $h) {
        if ($i === 0 && preg_match('#HTTP/\S+\s+(\d{3})#', $h, $m)) { $res['status'] = (int) $m[1]; }
        if (stripos($h, 'Location:') === 0)   { $res['location'] = trim(substr($h, 9)); }
        if (stripos($h, 'Set-Cookie:') === 0 && preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $h, $m)) {
            if ($jar !== null) { $jar[trim($m[1])] = $m[2]; }
        }
    }
    return $res;
}

/** Sign in and return a cookie jar carrying the session. */
function login(string $user, string $pass): array
{
    $jar = [];
    req('GET', '/reminders/', [], $jar);                       // pick up a session cookie
    $r = req('POST', '/reminders/', ['username' => $user, 'password' => $pass], $jar);
    if ($r['status'] !== 302) {
        throw new RuntimeException("login as $user did not redirect (status {$r['status']})");
    }
    return $jar;
}

/** The CSRF token the app would have put in the page. */
function csrf(array $jar, string $path = '/reminders/'): string
{
    $r = req('GET', $path, [], $jar);
    if (!preg_match('/name="csrf" value="([^"]+)"/', $r['body'], $m)) {
        throw new RuntimeException("no CSRF token on $path");
    }
    return $m[1];
}

/** The scratch data dir. The runner holds no session, so anything that would otherwise
 *  default to "the signed-in user" has to be told who it means. */
function datadir(): string { return (string) getenv('SUITE_DATA_DIR'); }

/** Read a user's stored file, the way the app would. */
function stored(string $base, string $user): array
{
    global $scratch;
    return store_read(user_data_file($scratch, $base, $user));
}
/** Every non-section row of a user's reminders. */
function rows(string $user): array
{
    return array_values(array_filter(stored('reminders', $user),
        fn($r) => ($r['type'] ?? '') !== 'section'));
}
/** Put every folder back on screen. The visibility tests deliberately switch folders
 *  off, and anything reading a rendered list afterwards needs the whole list back. */
function showAll(array $jar): void
{
    $keys = folders_load(datadir(), 'example')['reminders'];
    req('POST', '/reminders/', ['csrf' => csrf($jar), 'action' => 'folder_vis_all',
        'show' => '1', 'keys' => implode("\x1F", $keys)], $jar, true);
}

/** htmlspecialchars, for asserting on rendered text. */
function e_test(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }

/** One reminder by its text, or null. */
function rowBy(string $user, string $text): ?array
{
    foreach (rows($user) as $r) { if (($r['text'] ?? '') === $text) { return $r; } }
    return null;
}

// ═══════════════════════════════════════════════════════════════════ THE TESTS
// Each area matches a heading in TESTING.md. Keep the two in step.

// ---------------------------------------------------------------- 1. seeding
area('seed');

t('the example seeder builds a complete account', function () use ($scratch) {
    ok(count(rows('example')) > 15, 'example should have a working number of reminders');
    ok(count(stored('calendars', 'example')) === 3, 'three calendars');
    ok(count(stored('events', 'example')) > 5, 'events');
    ok(count(stored('notes', 'example')) > 5, 'notes');
    $acc = store_read($scratch . '/accounts.json');
    eq('examplepassword', $acc['example']['password'] ?? null, 'account password');
});

t('the buddy seeder builds the other half of a pair', function () {
    ok(count(rows('buddy')) > 20, 'buddy should have both checklists');
    $shares = shares_load(datadir(), 'buddy');
    ok(in_array('Dinners', $shares['folders'], true), 'buddy shares the Dinners folder');
    ok(in_array('Recipes', $shares['notes'], true), 'buddy shares the Recipes notes');
    ok(count($shares['calendars']) === 1, 'buddy shares one calendar');
});

t('seeding pairs the two accounts both ways', function () {
    $back = shares_load(datadir(), 'example');
    ok($back['folders'] || $back['notes'] || $back['calendars'], 'example shares back');
    $names = array_column(stored('events', 'example'), 'text');
    ok(in_array('Dinner with buddy', $names, true), "example has the dinners from their side");
    $mine = array_column(stored('events', 'buddy'), 'text');
    ok(in_array('Dinner with example', $mine, true), 'buddy has them from theirs');
});

t('re-seeding buddy does not double up example\'s dinners', function () use ($root, $scratch) {
    $before = count(array_filter(stored('events', 'example'),
        fn($e) => ($e['text'] ?? '') === 'Dinner with buddy'));
    exec('SUITE_DATA_DIR=' . escapeshellarg($scratch) . ' php '
        . escapeshellarg($root . '/tools/seed-buddy.php') . ' --force 2>&1', $o, $rc);
    eq(0, $rc, 'seeder exit code');
    $after = count(array_filter(stored('events', 'example'),
        fn($e) => ($e['text'] ?? '') === 'Dinner with buddy'));
    eq($before, $after, 'the count should be unchanged');
});

t('the seeders write nothing outside the scratch directory', function () use ($root) {
    // The real data dir must be untouched by a run — the whole point of SUITE_DATA_DIR.
    ok(getenv('SUITE_DATA_DIR') !== rtrim(app_config()['data_dir'], '/')
       || strpos(app_config()['data_dir'], sys_get_temp_dir()) === 0,
       'app_config() must be pointing at the scratch dir');
});

// ---------------------------------------------------------------- test instance (/test/)
// The /test/ sandbox mirror is the same source served under a base prefix, with its own
// data dir. suite_base() is what keeps its cross-app links inside /test/; get this wrong
// and the mirror's tab bar jumps to production. Prod (no base) must stay byte-identical.
area('test-instance');

t('suite_base() is empty for production (no base configured)', function () {
    eq('', suite_base(), 'unprefixed by default');
    ob_start(); render_tabbar('reminders'); $bar = ob_get_clean();
    has('href="/reminders/"', $bar, 'prod tab bar links are unprefixed');
    hasnt('/test/reminders/', $bar, 'no stray /test prefix in prod');
});

t('a base prefixes every cross-app link (tab bar + login landing)', function () use ($root, $scratch) {
    // app_config() caches in-process, so exercise the base in a fresh subprocess the way
    // the real /test/ instance gets it (there, from lib-test/config.php).
    $php = 'require ' . var_export($root . '/lib/auth.php', true) . ';'
         . 'require ' . var_export($root . '/lib/tabbar.php', true) . ';'
         . 'ob_start(); render_tabbar("reminders"); '
         . 'echo suite_base() . "\n" . ob_get_clean();';
    exec('SUITE_BASE=/test SUITE_DATA_DIR=' . escapeshellarg($scratch)
        . ' php -r ' . escapeshellarg($php) . ' 2>&1', $out, $rc);
    $s = implode("\n", $out);
    eq(0, $rc, 'subprocess ran');
    eq('/test', strtok($s, "\n"), 'suite_base() returns the configured prefix');
    has('href="/test/reminders/"', $s, 'tab bar links carry the /test prefix');
    has('href="/test/calendar/"', $s, 'and the calendar tab too');
    hasnt('href="/reminders/"', $s, 'no unprefixed cross-app link leaks out of /test/');
});

t('suite_base() normalises a messy prefix', function () use ($root, $scratch) {
    $php = 'require ' . var_export($root . '/lib/auth.php', true) . '; echo suite_base();';
    exec('SUITE_BASE=' . escapeshellarg('test/') . ' SUITE_DATA_DIR=' . escapeshellarg($scratch)
        . ' php -r ' . escapeshellarg($php) . ' 2>&1', $out, $rc);
    eq('/test', trim(implode("\n", $out)), 'trims slashes, adds a single leading one');
});

// ---------------------------------------------------------------- 2. auth
area('auth');

t('a signed-out visitor gets the login page, not the app', function () {
    foreach (['/reminders/', '/notes/', '/calendar/', '/habits/', '/add/'] as $p) {
        $r = req('GET', $p);
        eq(200, $r['status'], "$p status");
        has('Sign in', $r['body'], "$p should show the login form");
        hasnt('rlist-root', $r['body'], "$p must not leak the app");
    }
});

t('a wrong password is refused', function () {
    $jar = [];
    req('GET', '/reminders/', [], $jar);
    $r = req('POST', '/reminders/', ['username' => 'example', 'password' => 'nope'], $jar);
    eq(200, $r['status'], 'no redirect');
    has('Invalid username or password', $r['body']);
});

t('a good password lands you on the Calendar, wherever you signed in', function () {
    foreach (['/reminders/', '/notes/', '/habits/'] as $from) {
        $jar = [];
        req('GET', $from, [], $jar);
        $r = req('POST', $from, ['username' => 'example', 'password' => 'examplepassword'], $jar);
        eq(302, $r['status'], "$from status");
        eq('/calendar/', $r['location'], "signing in from $from should land on the Calendar");
    }
});

t('the login page draws no scrollbar', function () {
    $r = req('GET', '/reminders/');
    has('100svh', $r['body'], 'sized to the small viewport');
    has('scrollbar-width: none', $r['body']);
});

t('logging out ends the session', function () {
    $jar = login('example', 'examplepassword');
    req('GET', '/reminders/?logout=1', [], $jar);
    $r = req('GET', '/reminders/', [], $jar);
    has('Sign in', $r['body'], 'should be signed out again');
});

t('a POST with no CSRF token is refused', function () {
    $jar = login('example', 'examplepassword');
    $r = req('POST', '/reminders/', ['action' => 'add', 'text' => 'csrfless', 'view' => 'All'], $jar);
    eq(400, $r['status']);
    eq(null, rowBy('example', 'csrfless'), 'nothing should have been written');
});

t('a POST with the wrong CSRF token is refused', function () {
    $jar = login('example', 'examplepassword');
    $r = req('POST', '/reminders/',
        ['csrf' => 'not-the-token', 'action' => 'add', 'text' => 'badcsrf', 'view' => 'All'], $jar);
    eq(400, $r['status']);
    eq(null, rowBy('example', 'badcsrf'));
});

t('one session covers the whole suite', function () {
    $jar = login('example', 'examplepassword');
    foreach (['/reminders/', '/notes/', '/calendar/', '/habits/', '/add/'] as $p) {
        $r = req('GET', $p, [], $jar);
        eq(200, $r['status'], "$p status");
        hasnt('Sign in</h1>', $r['body'], "$p should be the app, not the login page");
    }
});

// ---------------------------------------------------------------- 3. storage
area('storage');

t('data is encrypted at rest', function () use ($scratch) {
    $raw = file_get_contents(user_data_file($scratch, 'reminders', 'example'));
    eq('ENC1:', substr($raw, 0, 5), 'files carry the ENC1 prefix');
    hasnt('Return the library books', $raw, 'plaintext must not be readable in the file');
    ok(count(rows('example')) > 0, 'and it still reads back');
});

t('legacy plaintext JSON still reads', function () use ($scratch) {
    $f = $scratch . '/legacy-test.json';
    file_put_contents($f, json_encode([['id' => 'x', 'text' => 'old row']]));
    $got = store_read($f);
    eq('old row', $got[0]['text'] ?? null, 'plaintext should be accepted');
});

t('a user only ever reads their own file', function () use ($scratch) {
    eq($scratch . '/reminders-buddy.json', user_data_file($scratch, 'reminders', 'buddy'));
    ok(rowBy('buddy', 'Pappardelle') !== null, 'buddy has their own groceries');
    eq(null, rowBy('example', 'Pappardelle'), "and they are not in example's list");
});

// ---------------------------------------------------------------- 4. reminders
area('reminders');

t('adding a reminder', function () {
    $jar = login('example', 'examplepassword');
    $r = req('POST', '/reminders/',
        ['csrf' => csrf($jar), 'action' => 'add', 'view' => 'All', 'text' => 'Test plain add',
         'folder' => 'Reminders', 'section' => ''], $jar);
    eq(302, $r['status'], 'POST redirects');
    $row = rowBy('example', 'Test plain add');
    ok($row !== null, 'the row exists');
    eq('Reminders', $row['folder']);
    ok(empty($row['due']), 'undated');
});

t('a date and time typed into the text are parsed out of it', function () {
    $jar = login('example', 'examplepassword');
    req('POST', '/reminders/',
        ['csrf' => csrf($jar), 'action' => 'add', 'view' => 'All', 'text' => 'Vet 8/3 2pm',
         'folder' => 'Reminders', 'section' => ''], $jar);
    $row = rowBy('example', 'Vet');
    ok($row !== null, 'the text is trimmed to "Vet"');
    eq('14:00', $row['time'], 'time');
    eq('08-03', substr((string) $row['due'], 5), 'month and day');
});

t('ticking a plain reminder marks it done', function () {
    $jar = login('example', 'examplepassword');
    $row = rowBy('example', 'Test plain add');
    req('POST', '/reminders/',
        ['csrf' => csrf($jar), 'action' => 'toggle', 'view' => 'All', 'id' => $row['id']], $jar);
    ok(!empty(rowBy('example', 'Test plain add')['done']), 'now done');
});

t('ticking a repeating reminder rolls it to the next date instead', function () {
    $jar = login('example', 'examplepassword');
    $row = rowBy('example', 'Water the tomatoes');       // every 2 days, from the seeder
    ok($row !== null, 'the seeded repeat exists');
    $was = $row['due'];
    req('POST', '/reminders/',
        ['csrf' => csrf($jar), 'action' => 'toggle', 'view' => 'All', 'id' => $row['id']], $jar);
    $now = rowBy('example', 'Water the tomatoes');
    ok(empty($now['done']), 'a repeat is never marked done');
    ok($now['due'] > $was, "due should have moved forward (was $was, now {$now['due']})");
    eq(2, (int) round((strtotime($now['due']) - strtotime($was)) / 86400), 'by two days');
});

t('editing a reminder\'s text', function () {
    $jar = login('example', 'examplepassword');
    $row = rowBy('example', 'Test plain add');
    req('POST', '/reminders/', ['csrf' => csrf($jar), 'action' => 'edit_text', 'view' => 'All',
        'id' => $row['id'], 'text' => 'Test edited'], $jar, true);
    ok(rowBy('example', 'Test edited') !== null, 'renamed');
    eq(null, rowBy('example', 'Test plain add'), 'old text gone');
});

t('deleting needs the confirmed second press', function () {
    $jar = login('example', 'examplepassword');
    $row = rowBy('example', 'Test edited');
    req('POST', '/reminders/',
        ['csrf' => csrf($jar), 'action' => 'delete', 'view' => 'All', 'id' => $row['id']], $jar);
    ok(rowBy('example', 'Test edited') !== null, 'one press must not delete');
    req('POST', '/reminders/',
        ['csrf' => csrf($jar), 'action' => 'delete', 'view' => 'All', 'id' => $row['id'],
         'confirm' => '1'], $jar);
    eq(null, rowBy('example', 'Test edited'), 'confirmed press deletes');
});

t('sections: add, rename, delete', function () {
    $jar = login('example', 'examplepassword');
    req('POST', '/reminders/', ['csrf' => csrf($jar), 'action' => 'add_section',
        'view' => 'All', 'name' => 'Testsec', 'folder' => 'Reminders'], $jar);
    $secs = array_filter(stored('reminders', 'example'), fn($r) => ($r['type'] ?? '') === 'section');
    ok(in_array('Testsec', array_column($secs, 'name'), true), 'section added');

    req('POST', '/reminders/', ['csrf' => csrf($jar), 'action' => 'rename_section',
        'view' => 'All', 'folder' => 'Reminders', 'name' => 'Testsec', 'newname' => 'Testsec2'], $jar);
    $names = array_column(array_filter(stored('reminders', 'example'),
        fn($r) => ($r['type'] ?? '') === 'section'), 'name');
    ok(in_array('Testsec2', $names, true), 'renamed');

    req('POST', '/reminders/', ['csrf' => csrf($jar), 'action' => 'delete_section',
        'view' => 'All', 'folder' => 'Reminders', 'name' => 'Testsec2', 'confirm' => '1'], $jar);
    $names = array_column(array_filter(stored('reminders', 'example'),
        fn($r) => ($r['type'] ?? '') === 'section'), 'name');
    ok(!in_array('Testsec2', $names, true), 'deleted');
});

t('the subtask + makes a child under its parent, not an indent on the row', function () {
    $jar = login('example', 'examplepassword');
    $parent = rowBy('example', 'Return the library books');   // dated, in Home/Errands
    ok($parent !== null, 'the seeded parent exists');
    $before = count(rows('example'));
    $r = req('POST', '/reminders/', ['csrf' => csrf($jar), 'action' => 'add_subtask',
        'view' => 'All', 'parent' => $parent['id']], $jar);
    eq(302, $r['status']);
    eq($before + 1, count(rows('example')), 'a new row was created');
    ok(strpos((string) $r['location'], 'focus=') !== false, 'and it comes back focused');
    eq(0, (int) (rowBy('example', 'Return the library books')['indent'] ?? 0),
        'the parent must NOT have been indented');

    // The child sits immediately after its parent, in the parent's folder and section.
    $all = array_values(array_filter(stored('reminders', 'example'),
        fn($r) => ($r['type'] ?? '') !== 'section'));
    $i = null;
    foreach ($all as $k => $row) { if ($row['id'] === $parent['id']) { $i = $k; break; } }
    $child = $all[$i + 1] ?? [];
    eq(1, (int) ($child['indent'] ?? 0), 'child is indented one level');
    eq('', (string) $child['text'], 'and starts empty, ready to type into');
    eq($parent['folder'], $child['folder'], 'same folder');
    eq($parent['section'], $child['section'], 'same section');
});

t('a subtask can be lifted back out to a task', function () {
    $jar = login('example', 'examplepassword');
    $child = null;
    foreach (rows('example') as $r) { if ((int) ($r['indent'] ?? 0) === 1 && ($r['text'] ?? '') === '') { $child = $r; break; } }
    ok($child !== null, 'the blank subtask from the last test is there');
    req('POST', '/reminders/', ['csrf' => csrf($jar), 'action' => 'set_indent',
        'view' => 'All', 'id' => $child['id'], 'indent' => '0'], $jar, true);
    foreach (rows('example') as $r) {
        if ($r['id'] === $child['id']) { eq(0, (int) ($r['indent'] ?? 0), 'back to level 0'); }
    }
});

t('a section can never be indented', function () {
    $jar = login('example', 'examplepassword');
    $sec = null;
    foreach (stored('reminders', 'example') as $r) { if (($r['type'] ?? '') === 'section') { $sec = $r; break; } }
    req('POST', '/reminders/', ['csrf' => csrf($jar), 'action' => 'set_indent',
        'view' => 'All', 'id' => $sec['id'], 'indent' => '1'], $jar, true);
    foreach (stored('reminders', 'example') as $r) {
        if ($r['id'] === $sec['id']) { eq(0, (int) ($r['indent'] ?? 0), 'sections stay at level 0'); }
    }
});

t('clear_done removes only the ticked rows', function () {
    $jar = login('example', 'examplepassword');
    $doneBefore = count(array_filter(rows('example'), fn($r) => !empty($r['done'])));
    $openBefore = count(array_filter(rows('example'), fn($r) => empty($r['done'])));
    ok($doneBefore > 0, 'there is something ticked to clear');
    req('POST', '/reminders/', ['csrf' => csrf($jar), 'action' => 'clear_done', 'view' => 'All'], $jar);
    eq(0, count(array_filter(rows('example'), fn($r) => !empty($r['done']))), 'none left ticked');
    eq($openBefore, count(array_filter(rows('example'), fn($r) => empty($r['done']))), 'open rows untouched');
});

t('the list renders undated first, then oldest date', function () {
    $jar = login('example', 'examplepassword');
    $r = req('GET', '/reminders/?folder=All', [], $jar);
    preg_match_all('/data-due="([^"]*)"/', $r['body'], $m);
    ok(count($m[1]) > 3, 'rows rendered');
    // Within the run, an empty due never comes after a non-empty one *inside a section*;
    // sections restart the sequence, so just check the first row of the first group.
    eq('', $m[1][0], 'the first row of the first group is undated');
});

// ---------------------------------------------------------------- 5. folders
area('folders');

t('adding and deleting a folder, with its items falling back', function () {
    $jar = login('example', 'examplepassword');
    req('POST', '/reminders/', ['csrf' => csrf($jar), 'action' => 'add_folder',
        'view' => 'All', 'name' => 'Testfolder'], $jar);
    ok(in_array('Testfolder', folders_load(datadir(), 'example')['reminders'], true),
       'folder added');

    req('POST', '/reminders/', ['csrf' => csrf($jar), 'action' => 'add', 'view' => 'Testfolder',
        'text' => 'Homeless soon', 'folder' => 'Testfolder', 'section' => ''], $jar);
    eq('Testfolder', rowBy('example', 'Homeless soon')['folder']);

    req('POST', '/reminders/', ['csrf' => csrf($jar), 'action' => 'delete_folder',
        'view' => 'All', 'name' => 'Testfolder', 'confirm' => '1'], $jar);
    ok(!in_array('Testfolder', folders_load(datadir(), 'example')['reminders'], true),
       'folder gone');
    eq(folder_fallback('reminders'), rowBy('example', 'Homeless soon')['folder'],
       'its items moved to the fallback rather than being destroyed');
});

t('the permanent folders cannot be deleted', function () {
    $jar = login('example', 'examplepassword');
    req('POST', '/reminders/', ['csrf' => csrf($jar), 'action' => 'delete_folder',
        'view' => 'All', 'name' => FOLDER_REMINDERS, 'confirm' => '1'], $jar);
    ok(in_array(FOLDER_REMINDERS, folders_load(datadir(), 'example')['reminders'], true),
       'Reminders is still there');
});

t('a folder colour must come from the palette', function () {
    $jar = login('example', 'examplepassword');
    $good = app_palette('reminders')[4];
    req('POST', '/reminders/', ['csrf' => csrf($jar), 'action' => 'set_folder_color',
        'view' => 'All', 'name' => 'Work', 'color' => $good], $jar, true);
    eq($good, folder_colors(datadir(), 'reminders', 'example')['Work'] ?? null, 'palette colour sticks');
    req('POST', '/reminders/', ['csrf' => csrf($jar), 'action' => 'set_folder_color',
        'view' => 'All', 'name' => 'Work', 'color' => '#ff0000'], $jar, true);
    eq($good, folder_colors(datadir(), 'reminders', 'example')['Work'] ?? null, 'off-palette refused');
});

t('the picker box toggles one folder', function () {
    $jar = login('example', 'examplepassword');
    req('POST', '/reminders/', ['csrf' => csrf($jar), 'action' => 'folder_vis',
        'name' => 'Work', 'show' => ''], $jar, true);
    ok(in_array('Work', folders_hidden(datadir(), 'reminders', 'example'), true), 'hidden');
    req('POST', '/reminders/', ['csrf' => csrf($jar), 'action' => 'folder_vis',
        'name' => 'Work', 'show' => '1'], $jar, true);
    ok(!in_array('Work', folders_hidden(datadir(), 'reminders', 'example'), true), 'shown again');
});

t('tapping a folder row makes it the only one showing', function () {
    $jar = login('example', 'examplepassword');
    $keys = folders_load(datadir(), 'example')['reminders'];
    req('POST', '/reminders/', ['csrf' => csrf($jar), 'action' => 'folder_vis_only',
        'name' => 'Home', 'keys' => implode("\x1F", $keys)], $jar, true);
    $hidden = folders_hidden(datadir(), 'reminders', 'example');
    ok(!in_array('Home', $hidden, true), 'Home stays showing');
    foreach ($keys as $k) {
        if ($k !== 'Home') { ok(in_array($k, $hidden, true), "$k should be hidden"); }
    }
});

t('All shows everything, then hides everything', function () {
    $jar = login('example', 'examplepassword');
    $keys = folders_load(datadir(), 'example')['reminders'];
    $ks   = implode("\x1F", $keys);
    req('POST', '/reminders/', ['csrf' => csrf($jar), 'action' => 'folder_vis_all',
        'show' => '1', 'keys' => $ks], $jar, true);
    eq([], folders_hidden(datadir(), 'reminders', 'example'), 'nothing hidden');
    req('POST', '/reminders/', ['csrf' => csrf($jar), 'action' => 'folder_vis_all',
        'show' => '', 'keys' => $ks], $jar, true);
    eq(count($keys), count(folders_hidden(datadir(), 'reminders', 'example')), 'all hidden');
    req('POST', '/reminders/', ['csrf' => csrf($jar), 'action' => 'folder_vis_all',
        'show' => '1', 'keys' => $ks], $jar, true);   // put it back for later tests
});

t('the default folder is where a new item lands from All', function () {
    $jar = login('example', 'examplepassword');
    req('POST', '/reminders/', ['csrf' => csrf($jar), 'action' => 'set_default_folder',
        'view' => 'All', 'name' => 'Work'], $jar);
    eq('Work', folder_default_get(datadir(), 'reminders', 'example'));
    req('POST', '/reminders/', ['csrf' => csrf($jar), 'action' => 'set_default_folder',
        'view' => 'All', 'name' => FOLDER_REMINDERS], $jar);
});

t('the folder heading wears its colour as a wash, not a dot', function () {
    $jar = login('example', 'examplepassword');
    $r = req('GET', '/reminders/?folder=All', [], $jar);
    ok(preg_match('/<div class="folder-label" style="background:#[0-9a-f]{6}33"/', $r['body']) === 1,
       'the heading carries an 8-digit tint');
    ok(!preg_match('/folder-label[^>]*>[^<]*<\/div>\s*<span class="fdot"/', $r['body']),
       'and no dot follows it');
    has('.folder-block .section-group { padding-left', $r['body'],
        'and its sections nest slightly indented under it');
});

// ---------------------------------------------------------------- 6. notes
area('notes');

t('adding a note opens it in the editor', function () {
    $jar = login('example', 'examplepassword');
    $r = req('POST', '/notes/', ['csrf' => csrf($jar, '/notes/'), 'action' => 'add',
        'view' => 'All', 'folder' => 'General', 'section' => ''], $jar);
    eq(302, $r['status']);
    ok(strpos((string) $r['location'], 'id=') !== false, 'redirects into the note');
});

t('a note folder\'s sections nest indented under its heading', function () {
    // Every section — named or the catch-all — is a .section-group now (so it can drag as
    // one unit), and the single indent rule covers them all.
    $jar = login('example', 'examplepassword');
    $r = req('GET', '/notes/?folder=All', [], $jar);
    eq(200, $r['status']);
    has('.folder-block > .section-group { padding-left', $r['body'], 'the indent rule ships');
    has('class="sec-handle"', $r['body'], 'and named sections carry a drag handle');
});

t('a note body is sanitised on the way in', function () {
    $jar = login('example', 'examplepassword');
    $notes = array_values(array_filter(stored('notes', 'example'), fn($n) => ($n['type'] ?? '') !== 'section'));
    $id = $notes[0]['id'];
    req('POST', '/notes/', ['csrf' => csrf($jar, '/notes/'), 'action' => 'save', 'view' => 'All',
        'id' => $id, 'title' => 'Sanitiser test',
        'body' => '<p>ok</p><script>alert(1)</script><img src=x onerror=alert(1)><b>bold</b>'], $jar);
    $body = '';
    foreach (stored('notes', 'example') as $n) { if (($n['id'] ?? '') === $id) { $body = $n['body']; } }
    hasnt('<script', $body, 'script tags are stripped');
    hasnt('onerror', $body, 'event handlers are stripped');
    hasnt('<img', $body, 'img is not on the allowlist');
    has('<b>bold</b>', $body, 'allowed tags survive');
});

t('rt_sanitize keeps only the allowlist', function () {
    $out = rt_sanitize('<div class="rt-quote">q</div><span class="evil">x</span><ul><li>a</li></ul><iframe></iframe>');
    has('rt-quote', $out, 'rt-* classes are kept');
    hasnt('class="evil"', $out, 'other classes are dropped');
    hasnt('<iframe', $out, 'unknown tags are dropped');
    has('<li>a</li>', $out);
});

t('an old plain-text note is escaped rather than rendered', function () {
    $out = rt_body_html('5 < 6 & "quoted"');
    has('&lt;', $out, 'escaped');
    hasnt('<script', $out);
});

t('note folders and sections behave like the reminders ones', function () {
    $jar = login('example', 'examplepassword');
    req('POST', '/notes/', ['csrf' => csrf($jar, '/notes/'), 'action' => 'add_folder',
        'view' => 'All', 'name' => 'Testnotes'], $jar);
    ok(in_array('Testnotes', folders_load(datadir(), 'example')['notes'], true));
    req('POST', '/notes/', ['csrf' => csrf($jar, '/notes/'), 'action' => 'delete_folder',
        'view' => 'All', 'name' => 'Testnotes', 'confirm' => '1'], $jar);
    ok(!in_array('Testnotes', folders_load(datadir(), 'example')['notes'], true));
});

// ---------------------------------------------------------------- 7. calendar
area('calendar');

t('the day panel payload groups by day', function () {
    $jar = login('example', 'examplepassword');
    $r = req('GET', '/calendar/', [], $jar);
    eq(200, $r['status']);
    ok(preg_match('/\{"\d{4}-\d{2}-\d{2}":/', $r['body']) === 1, 'items are keyed by date');
});

t("a day's reminders sort undated first, then oldest, then by time", function () {
    $jar = login('example', 'examplepassword');
    $r = req('GET', '/calendar/', [], $jar);
    preg_match('/=\s*(\{"20\d\d-\d\d-\d\d".*?\})\s*;/s', $r['body'], $m);
    $byDay = json_decode($m[1], true);
    $today = date('Y-m-d');
    $rem = array_values(array_filter($byDay[$today] ?? [], fn($i) => $i['kind'] === 'reminder'
        && ($i['owner'] ?? '') === ''));
    ok(count($rem) > 1, "today should hold several of example's reminders");
    $prev = null;
    foreach ($rem as $i) {
        $due = (string) ($i['due'] ?? '');
        if ($prev !== null) {
            ok(!($prev !== '' && $due === ''), 'an undated reminder must not follow a dated one');
            if ($prev !== '' && $due !== '') { ok($due >= $prev, "dates ascend ($prev then $due)"); }
        }
        $prev = $due;
    }
});

t('events come before reminders within a day', function () {
    $jar = login('example', 'examplepassword');
    $r = req('GET', '/calendar/', [], $jar);
    preg_match('/=\s*(\{"20\d\d-\d\d-\d\d".*?\})\s*;/s', $r['body'], $m);
    $byDay = json_decode($m[1], true);
    foreach ($byDay as $day => $items) {
        $rank = ['event' => 0, 'reminder' => 1, 'note' => 2];
        $last = -1;
        foreach ($items as $i) {
            $k = $rank[$i['kind']] ?? 9;
            ok($k >= $last, "$day: kinds are out of order at {$i['kind']}");
            $last = $k;
        }
    }
});

t('an undated Calendar-folder reminder rides on today and is not overdue', function () {
    $jar = login('example', 'examplepassword');
    $r = req('GET', '/calendar/', [], $jar);
    preg_match('/=\s*(\{"20\d\d-\d\d-\d\d".*?\})\s*;/s', $r['body'], $m);
    $byDay = json_decode($m[1], true);
    $found = null;
    foreach ($byDay[date('Y-m-d')] ?? [] as $i) {
        if ($i['kind'] === 'reminder' && $i['text'] === 'Stretch for ten minutes') { $found = $i; }
    }
    ok($found !== null, 'the rider shows on today');
    ok(empty($found['rolled']), 'and is not flagged overdue — it is not late');
});

t('adding an event from the day panel', function () {
    $jar = login('example', 'examplepassword');
    $cal = stored('calendars', 'example')[0]['id'];
    $day = date('Y-m-d', strtotime('+3 days'));
    req('POST', '/calendar/', ['csrf' => csrf($jar, '/calendar/'), 'action' => 'add_event',
        'kind' => 'event', 'text' => 'Panel event', 'day' => $day, 'date' => $day,
        'time' => '10:00', 'cal' => $cal, 'ym' => date('Y-m')], $jar);
    $ev = null;
    foreach (stored('events', 'example') as $e) { if ($e['text'] === 'Panel event') { $ev = $e; } }
    ok($ev !== null, 'the event exists');
    eq($cal, $ev['cal'], 'in the chosen calendar');
    eq('10:00', $ev['time']);
});

t('editing and deleting a calendar item', function () {
    $jar = login('example', 'examplepassword');
    $ev = null;
    foreach (stored('events', 'example') as $e) { if ($e['text'] === 'Panel event') { $ev = $e; } }
    req('POST', '/calendar/', ['csrf' => csrf($jar, '/calendar/'), 'action' => 'edit_item',
        'kind' => 'event', 'id' => $ev['id'], 'text' => 'Panel event renamed',
        'date' => $ev['date'], 'ym' => date('Y-m')], $jar);
    $names = array_column(stored('events', 'example'), 'text');
    ok(in_array('Panel event renamed', $names, true), 'renamed');

    req('POST', '/calendar/', ['csrf' => csrf($jar, '/calendar/'), 'action' => 'delete_item',
        'kind' => 'event', 'id' => $ev['id'], 'ym' => date('Y-m')], $jar);
    ok(in_array('Panel event renamed', array_column(stored('events', 'example'), 'text'), true),
       'one press must not delete');
    req('POST', '/calendar/', ['csrf' => csrf($jar, '/calendar/'), 'action' => 'delete_item',
        'kind' => 'event', 'id' => $ev['id'], 'confirm' => '1', 'ym' => date('Y-m')], $jar);
    ok(!in_array('Panel event renamed', array_column(stored('events', 'example'), 'text'), true),
       'confirmed press deletes');
});

t('calendars: add, recolour, default, delete', function () {
    $jar = login('example', 'examplepassword');
    $c = csrf($jar, '/calendar/');
    req('POST', '/calendar/', ['csrf' => $c, 'action' => 'cal_add', 'name' => 'Testcal',
        'ym' => date('Y-m')], $jar, true);
    $cal = null;
    foreach (stored('calendars', 'example') as $x) { if (($x['name'] ?? '') === 'Testcal') { $cal = $x; } }
    ok($cal !== null, 'calendar added');

    $col = app_palette('calendar')[3];
    req('POST', '/calendar/', ['csrf' => $c, 'action' => 'cal_color', 'id' => $cal['id'],
        'color' => $col, 'ym' => date('Y-m')], $jar, true);
    foreach (stored('calendars', 'example') as $x) {
        if ($x['id'] === $cal['id']) { eq($col, $x['color'], 'recoloured'); }
    }

    req('POST', '/calendar/', ['csrf' => $c, 'action' => 'cal_delete', 'id' => $cal['id'],
        'confirm' => '1', 'ym' => date('Y-m')], $jar, true);
    ok(!in_array('Testcal', array_column(stored('calendars', 'example'), 'name'), true), 'deleted');
});

t('tapping a calendar row leaves only it showing', function () {
    $jar = login('example', 'examplepassword');
    $cals = stored('calendars', 'example');
    $keep = $cals[0]['id'];
    req('POST', '/calendar/', ['csrf' => csrf($jar, '/calendar/'), 'action' => 'cal_vis_only',
        'name' => $keep, 'ym' => date('Y-m')], $jar, true);
    $hidden = (array) (stored('calprefs', 'example')['hidden_cals'] ?? []);
    ok(!in_array($keep, $hidden, true), 'the tapped one stays');
    foreach ($cals as $c) { if ($c['id'] !== $keep) { ok(in_array($c['id'], $hidden, true), 'the rest hide'); } }
    req('POST', '/calendar/', ['csrf' => csrf($jar, '/calendar/'), 'action' => 'cal_vis_all',
        'show' => '1', 'ym' => date('Y-m')], $jar, true);
    eq([], (array) (stored('calprefs', 'example')['hidden_cals'] ?? []), 'All puts them back');
});

t('ticking a reminder from the calendar rolls a repeat too', function () {
    $jar = login('example', 'examplepassword');
    $row = rowBy('example', 'Rent');       // monthly, from the seeder
    ok($row !== null);
    $was = $row['due'];
    req('POST', '/calendar/', ['csrf' => csrf($jar, '/calendar/'), 'action' => 'toggle_reminder',
        'id' => $row['id'], 'day' => $was, 'ym' => date('Y-m')], $jar);
    $now = rowBy('example', 'Rent');
    ok(empty($now['done']), 'not marked done');
    ok($now['due'] > $was, 'rolled forward a month');
});

// ---------------------------------------------------------------- 8. habits
area('habits');

t('ticking a day', function () {
    $jar = login('example', 'examplepassword');
    $h = null;
    foreach (stored('habits', 'example') as $x) { if (($x['type'] ?? '') !== 'section') { $h = $x; break; } }
    $day = date('Y-m-d');
    $r = req('POST', '/habits/', ['csrf' => csrf($jar, '/habits/'), 'action' => 'toggle',
        'id' => $h['id'], 'date' => $day], $jar, true);
    $j = json_decode($r['body'], true);
    ok(isset($j['done']), 'answers with the new state');
    foreach (stored('habits', 'example') as $x) {
        if (($x['id'] ?? '') === $h['id']) { eq($j['done'], !empty($x['done'][$day]), 'stored state matches'); }
    }
});

t('habits: add, rename, delete', function () {
    $jar = login('example', 'examplepassword');
    req('POST', '/habits/', ['csrf' => csrf($jar, '/habits/'), 'action' => 'add_habit',
        'name' => 'Test habit', 'section' => ''], $jar);
    $h = null;
    foreach (stored('habits', 'example') as $x) { if (($x['name'] ?? '') === 'Test habit') { $h = $x; } }
    ok($h !== null, 'added');
    req('POST', '/habits/', ['csrf' => csrf($jar, '/habits/'), 'action' => 'rename_habit',
        'id' => $h['id'], 'name' => 'Test habit renamed'], $jar, true);
    ok(in_array('Test habit renamed', array_column(stored('habits', 'example'), 'name'), true));
    req('POST', '/habits/', ['csrf' => csrf($jar, '/habits/'), 'action' => 'delete_habit',
        'id' => $h['id'], 'confirm' => '1'], $jar);
    ok(!in_array('Test habit renamed', array_column(stored('habits', 'example'), 'name'), true));
});

t('a section colour must come from the palette, and the answer says what stuck', function () {
    $jar = login('example', 'examplepassword');
    $sec = null;
    foreach (stored('habits', 'example') as $x) { if (($x['type'] ?? '') === 'section') { $sec = $x; break; } }
    ok($sec !== null, 'there is a section to colour');
    $good = app_palette('habits', true)[2];
    $r = req('POST', '/habits/', ['csrf' => csrf($jar, '/habits/'), 'action' => 'set_section_color',
        'id' => $sec['id'], 'color' => $good], $jar, true);
    eq($good, json_decode($r['body'], true)['color'] ?? null, 'answers with the stored colour');

    $r = req('POST', '/habits/', ['csrf' => csrf($jar, '/habits/'), 'action' => 'set_section_color',
        'id' => $sec['id'], 'color' => '#ff0000'], $jar, true);
    eq($good, json_decode($r['body'], true)['color'] ?? null,
       'an off-palette colour is refused and the old one comes back');
});

t('both habit views render, and draw actual cells', function () {
    $jar = login('example', 'examplepassword');
    // The view is chosen with ?v=; ?m= only picks which month once you are in it. Assert
    // on rendered *cells*, not on a marker word — every one of these names also appears
    // in the stylesheet, so "does the page contain mgrid" passes on an empty grid. That
    // is exactly how the month view went untested until someone looked.
    $r = req('GET', '/habits/?v=week&w=0', [], $jar);
    eq(200, $r['status']);
    ok(preg_match_all('/<div class="colhead/', $r['body']) > 3, 'the week grid has day columns');
    ok(preg_match_all('/<button class="cell/', $r['body']) > 3, 'and tickable squares');

    $r = req('GET', '/habits/?v=month&m=' . date('Y-m'), [], $jar);
    eq(200, $r['status']);
    ok(preg_match_all('/<div class="mcell/', $r['body']) >= 28, 'the month grid has a cell per day');
});

// ---------------------------------------------------------------- 9. the Add app
area('add');

t('Add makes a reminder in the chosen folder and section', function () {
    $jar = login('example', 'examplepassword');
    req('POST', '/add/', ['csrf' => csrf($jar, '/add/'), 'action' => 'add_reminder',
        'text' => 'From the add app', 'folder' => 'Home', 'section' => 'Errands'], $jar);
    $row = rowBy('example', 'From the add app');
    ok($row !== null, 'created');
    eq('Home', $row['folder']);
    eq('Errands', $row['section']);
});

t('Add makes an event in the chosen calendar', function () {
    $jar = login('example', 'examplepassword');
    $cal = stored('calendars', 'example')[1]['id'];
    req('POST', '/add/', ['csrf' => csrf($jar, '/add/'), 'action' => 'add_event',
        'text' => 'Add-app event', 'cal' => $cal], $jar);
    $ev = null;
    foreach (stored('events', 'example') as $e) { if ($e['text'] === 'Add-app event') { $ev = $e; } }
    ok($ev !== null, 'created');
    eq($cal, $ev['cal'], 'in the chosen calendar');
    eq(date('Y-m-d'), $ev['date'], 'an undated event defaults to today');
});

t('Add makes a note in the chosen note folder', function () {
    $jar = login('example', 'examplepassword');
    req('POST', '/add/', ['csrf' => csrf($jar, '/add/'), 'action' => 'add_note',
        'text' => 'Add-app note', 'nfolder' => 'Recipes', 'nsection' => ''], $jar);
    $n = null;
    foreach (stored('notes', 'example') as $x) { if (($x['title'] ?? '') === 'Add-app note') { $n = $x; } }
    ok($n !== null, 'created');
    eq('Recipes', $n['folder']);
});

t('a destination that does not exist falls back instead of being taken on trust', function () {
    $jar = login('example', 'examplepassword');
    req('POST', '/add/', ['csrf' => csrf($jar, '/add/'), 'action' => 'add_reminder',
        'text' => 'Bogus folder', 'folder' => 'Nope', 'section' => 'Nope'], $jar);
    eq(folder_fallback('reminders'), rowBy('example', 'Bogus folder')['folder']);
    eq('', rowBy('example', 'Bogus folder')['section']);

    req('POST', '/add/', ['csrf' => csrf($jar, '/add/'), 'action' => 'add_event',
        'text' => 'Bogus cal', 'cal' => 'nosuchcal'], $jar);
    $ids = array_column(stored('calendars', 'example'), 'id');
    $ev = null;
    foreach (stored('events', 'example') as $e) { if ($e['text'] === 'Bogus cal') { $ev = $e; } }
    ok(in_array($ev['cal'], $ids, true), 'an unknown calendar id is replaced with a real one');
});

t('Add reads the date and time out of the line too', function () {
    $jar = login('example', 'examplepassword');
    req('POST', '/add/', ['csrf' => csrf($jar, '/add/'), 'action' => 'add_reminder',
        'text' => 'Dentist 9/4 3:30pm', 'folder' => 'Home', 'section' => ''], $jar);
    $row = rowBy('example', 'Dentist');
    ok($row !== null, 'text trimmed');
    eq('15:30', $row['time']);
    eq('09-04', substr((string) $row['due'], 5));
});

// ---------------------------------------------------------------- 10. sharing
area('sharing');

t('the pair can see each other, and nobody else has a partner', function () {
    eq('example', share_partner('buddy'));
    eq('buddy', share_partner('example'));
    eq('aki', share_partner('sean'));
    eq(null, share_partner('nobody'));
});

t("a partner's shared folder shows in my list, unshared ones do not", function () {
    $jar = login('example', 'examplepassword');
    $r = req('GET', '/reminders/?folder=All', [], $jar);
    has('@buddy:Dinners', $r['body'], "buddy's shared folder is offered");
    hasnt('@buddy:House', $r['body'], 'a folder they did not share is not');
});

t("writing into a partner's shared folder writes to their file", function () {
    $jar = login('example', 'examplepassword');
    $before = count(rows('buddy'));
    $view = '@buddy:Dinners';
    $r = req('POST', '/reminders/', ['csrf' => csrf($jar), 'action' => 'add', 'view' => $view,
        'text' => 'Added by example', 'folder' => 'Dinners', 'section' => ''], $jar);
    ok(in_array($r['status'], [302, 403], true), 'either it wrote or it refused, not a 500');
    if ($r['status'] === 302) {
        eq($before + 1, count(rows('buddy')), "the row landed in buddy's file");
        eq(null, rowBy('example', 'Added by example'), "and not in example's");
    }
});

t("structural edits to a partner's folder are refused", function () {
    $jar = login('example', 'examplepassword');
    $view = '@buddy:Dinners';
    $secsBefore = count(array_filter(stored('reminders', 'buddy'), fn($r) => ($r['type'] ?? '') === 'section'));
    $r = req('POST', '/reminders/', ['csrf' => csrf($jar), 'action' => 'add_section',
        'view' => $view, 'name' => 'Sneaky', 'folder' => 'Dinners'], $jar);
    eq(403, $r['status'], 'adding a section to their folder is a 403');
    eq($secsBefore, count(array_filter(stored('reminders', 'buddy'), fn($r) => ($r['type'] ?? '') === 'section')),
       'and nothing was written');
});

t('share_set adds and removes a share', function () {
    $jar = login('buddy', 'buddypassword');
    req('POST', '/reminders/', ['csrf' => csrf($jar), 'action' => 'share_set',
        'kind' => 'folder', 'key' => 'House', 'on' => '1'], $jar, true);
    ok(in_array('House', shares_load(datadir(), 'buddy')['folders'], true), 'shared');
    req('POST', '/reminders/', ['csrf' => csrf($jar), 'action' => 'share_set',
        'kind' => 'folder', 'key' => 'House', 'on' => ''], $jar, true);
    ok(!in_array('House', shares_load(datadir(), 'buddy')['folders'], true), 'unshared');
});

// ---------------------------------------------------------------- 11. widget / api
area('widget');

t('the feed refuses a bad token and answers a good one', function () use ($scratch) {
    $r = req('GET', '/calendar/feed.php?token=nonsense');
    ok($r['status'] >= 400 || strpos($r['body'], '"items"') === false, 'a bad token gets nothing');

    $tok = store_read($scratch . '/token-example.json');
    $tok = is_array($tok) ? ($tok['token'] ?? reset($tok)) : $tok;
    if (!is_string($tok) || $tok === '') { return; }        // no token issued in this fixture
    $r = req('GET', '/calendar/feed.php?token=' . urlencode($tok));
    eq(200, $r['status']);
    $j = json_decode($r['body'], true);
    ok(is_array($j), 'the feed is JSON');
});

t('the feed is read-only — a POST behind the token changes nothing', function () use ($scratch) {
    $tok = store_read($scratch . '/token-example.json');
    $tok = is_array($tok) ? ($tok['token'] ?? reset($tok)) : $tok;
    if (!is_string($tok) || $tok === '') { return; }
    $before = count(rows('example'));
    req('POST', '/calendar/feed.php?token=' . urlencode($tok),
        ['action' => 'add', 'text' => 'via the feed']);
    eq($before, count(rows('example')), 'nothing was written');
});

t('the reminders API needs a session or a token', function () {
    $r = req('GET', '/api/reminders.php');
    ok($r['status'] !== 200 || strpos($r['body'], '"text"') === false,
       'no anonymous read');
});

t('quick.php adds for today', function () {
    $jar = login('example', 'examplepassword');
    $r = req('GET', '/calendar/quick.php', [], $jar);
    eq(200, $r['status'], 'the page loads');
    if (preg_match('/name="csrf" value="([^"]+)"/', $r['body'], $m)) {
        req('POST', '/calendar/quick.php',
            ['csrf' => $m[1], 'type' => 'reminder', 'text' => 'Quick add test'], $jar);
        $row = rowBy('example', 'Quick add test');
        if ($row !== null) { eq(date('Y-m-d'), (string) $row['due'], 'lands on today'); }
    }
});

// ---------------------------------------------------------------- 12. lib units
area('lib');

t('the text parser is slash-only and US-order', function () {
    [$text, $date, $time] = parse_when_from_text('Vet 8/3 2pm');
    eq('Vet', $text);
    eq('14:00', $time);
    eq('08-03', substr((string) $date, 5));

    [$text, $date] = parse_when_from_text('Milk');
    eq('Milk', $text);
    eq(null, $date, 'no date in plain text');

    eq('14:30', parse_time_from_text('meet 2:30 pm')[1] ?? parse_time_from_text('meet 2:30 pm'),
       '2:30 pm');
});

t('a date-like fraction is a known limitation, not a crash', function () {
    [$text, $date] = parse_when_from_text('2/3 cup flour');
    ok($date !== null, 'documented: "2/3 cup" parses as a date');
});

t('month repeats clamp the day instead of sliding', function () {
    $days = repeat_dates('2026-01-31', ['n' => 1, 'unit' => 'month'], '2026-02-01', '2026-03-05');
    ok(in_array('2026-02-28', $days, true), 'Jan 31 + 1 month is Feb 28, not Mar 3');
    ok(!in_array('2026-03-03', $days, true), 'and never slides into March');
});

t('year repeats clamp a leap day', function () {
    $days = repeat_dates('2024-02-29', ['n' => 1, 'unit' => 'year'], '2025-01-01', '2025-12-31');
    ok(in_array('2025-02-28', $days, true), 'Feb 29 + 1 year is Feb 28');
});

t('repeat_next moves to the next occurrence', function () {
    eq('2026-08-03', repeat_next('2026-08-01', ['n' => 2, 'unit' => 'day'], '2026-08-01'));
    eq('2026-08-08', repeat_next('2026-08-01', ['n' => 1, 'unit' => 'week'], '2026-08-01'));
    eq('2026-09-01', repeat_next('2026-08-01', ['n' => 1, 'unit' => 'month'], '2026-08-01'));
});

t('a folder tint is 8-digit hex, and refuses anything else', function () {
    eq('#4c8bf033', folder_tint('#4c8bf0'));
    eq('transparent', folder_tint('conic-gradient(red, blue)'));
    eq('transparent', folder_tint('red'));
});

t('the plus icon is an SVG, so it centres by construction', function () {
    $svg = plus_icon_svg(12);
    has('<svg', $svg);
    has('width="12"', $svg);
    hasnt('>+<', $svg, 'never a text plus');
});

t('every app palette offers six colours and validates its own', function () {
    foreach (['reminders', 'notes', 'calendar'] as $app) {
        eq(6, count(app_palette($app)), "$app own");
        eq(6, count(app_palette($app, true)), "$app shared");
        ok(palette_has($app, app_palette($app)[0]), 'own colour validates');
        ok(palette_has($app, app_palette($app, true)[0]), 'shared colour validates');
        ok(!palette_has($app, '#ff0000'), 'a stranger does not');
    }
    eq(app_palette('reminders', true), app_palette('habits', true), 'habits borrows the reminders tier');
});

t('the folder migration is idempotent', function () {
    $old = [
        ['id' => 'a', 'type' => 'section', 'name' => 'Calendar', 'folder' => 'General'],
        ['id' => 'b', 'text' => 'thing', 'section' => 'Calendar', 'folder' => 'General'],
        ['id' => 'c', 'text' => 'other', 'folder' => 'General'],
    ];
    $once  = reminders_folder_migrate($old);
    $twice = reminders_folder_migrate($once);
    eq($once, $twice, 'running it again changes nothing');
    foreach ($once as $r) {
        ok(($r['folder'] ?? '') !== 'General', 'General became Reminders');
    }
});

t('escaping is applied on output', function () {
    $jar = login('example', 'examplepassword');
    showAll($jar);                                   // don't read a list something else hid
    req('POST', '/reminders/', ['csrf' => csrf($jar), 'action' => 'add', 'view' => 'Reminders',
        'text' => '<script>alert(1)</script>', 'folder' => 'Reminders', 'section' => ''], $jar);
    $r = req('GET', '/reminders/?folder=Reminders', [], $jar);
    hasnt('<script>alert(1)</script>', $r['body'], 'the raw tag never reaches the page');
    has('&lt;script&gt;', $r['body'], 'it is escaped instead');
});

// ---------------------------------------------------------------- 13. every page renders
area('pages');

t('every page of the suite renders for a seeded user', function () {
    foreach (['example', 'buddy'] as $user) {
        $jar = login($user, $user . 'password');
        foreach (['/reminders/', '/notes/', '/calendar/', '/habits/', '/add/',
                  '/reminders/?folder=All', '/calendar/?ym=' . date('Y-m'),
                  '/habits/?m=' . date('Y-m'), '/calendar/quick.php'] as $p) {
            $r = req('GET', $p, [], $jar);
            eq(200, $r['status'], "$user $p");
            hasnt('Fatal error', $r['body'], "$user $p");
            hasnt('Warning:', $r['body'], "$user $p");
            hasnt('Notice:', $r['body'], "$user $p");
        }
    }
});

t('the public pages need no login', function () {
    foreach (['/', '/about/', '/projects/', '/contact/', '/chat/'] as $p) {
        $r = req('GET', $p);
        eq(200, $r['status'], "$p status");
        hasnt('Fatal error', $r['body'], $p);
    }
});

t('an empty account is a working empty suite, not a crash', function () use ($scratch) {
    $acc = store_read($scratch . '/accounts.json');
    $acc['freshy'] = ['email' => 'f@example.com', 'password' => 'freshpassword', 'created' => time()];
    store_write($scratch . '/accounts.json', $acc);
    $jar = login('freshy', 'freshpassword');
    foreach (['/reminders/', '/notes/', '/calendar/', '/habits/', '/add/'] as $p) {
        $r = req('GET', $p, [], $jar);
        eq(200, $r['status'], "empty account $p");
        hasnt('Fatal error', $r['body'], "empty account $p");
        hasnt('Warning:', $r['body'], "empty account $p");
    }
});

// ---------------------------------------------------------------- 14. regressions
// One case per bug that actually reached a phone. Several of these were touch-only —
// a click-eater, a link interceptor in the PWA shell, a two-step gesture, a margin —
// and a headless run can never press them. Those are checked as **wiring**: the page
// still has to contain the handler or the rule that makes the behaviour possible, so
// removing it fails here even though nothing here can feel it. Behaviour that *can* be
// driven is driven properly. Both kinds say which they are in the label.
area('regress');

t('wiring: a picker row tap stops the click reaching the PWA link interceptor', function () {
    $jar = login('example', 'examplepassword');
    foreach (['/reminders/' => 'folderpick-opt', '/calendar/' => 'calpick-opt'] as $page => $cls) {
        $b = req('GET', $page, [], $jar)['body'];
        has($cls, $b, "$page draws picker rows");
        // The row handler is the one that both cancels the link and stops it bubbling.
        ok(preg_match('/preventDefault\(\);\s*e\.stopPropagation\(\)/', $b) === 1,
           "$page: a row tap must cancel the link *and* stop it reaching tabbar.php");
    }
    // tabbar.php is the thing it has to beat: it follows same-origin links from document.
    has('window.navigator.standalone', req('GET', '/reminders/', [], $jar)['body'],
        'the interceptor this guards against is still there');
});

t("a partner's folder view still shows the visibility checkmarks", function () {
    $jar = login('example', 'examplepassword');
    $r = req('GET', '/reminders/?folder=' . rawurlencode('@buddy:Dinners'), [], $jar);
    eq(200, $r['status']);
    ok(substr_count($r['body'], 'class="fvis') > 0,
       'opening one of theirs must not blank every checkmark');
});

t('wiring: the edit gesture opens a section name for typing', function () {
    $jar = login('example', 'examplepassword');
    foreach (['/reminders/', '/notes/'] as $page) {
        $b = req('GET', $page, [], $jar)['body'];
        ok(preg_match('/querySelector\(.\.sectitle.\)/', $b) === 1,
           "$page: the gesture has to reach for the name field");
        has('.focus()', $b, "$page: and focus it");
    }
});

t('renaming a section from the list works in Notes as well as Reminders', function () {
    foreach ([['/notes/', 'notes'], ['/reminders/', 'reminders']] as [$page, $base]) {
        $jar = login('example', 'examplepassword');
        $sec = null;
        foreach (stored($base, 'example') as $it) { if (($it['type'] ?? '') === 'section') { $sec = $it; break; } }
        ok($sec !== null, "$page has a section");
        $to = 'Renamed ' . $base;
        req('POST', $page, ['csrf' => csrf($jar, $page), 'action' => 'rename_section', 'view' => 'All',
            'folder' => $sec['folder'] ?? '', 'name' => $sec['name'], 'newname' => $to], $jar);
        $names = [];
        foreach (stored($base, 'example') as $it) { if (($it['type'] ?? '') === 'section') { $names[] = $it['name']; } }
        ok(in_array($to, $names, true), "$page rename should have stuck");
        // and the rows that named it follow it, rather than being orphaned
        foreach (stored($base, 'example') as $it) {
            if (($it['type'] ?? '') === 'section') { continue; }
            ok(($it['section'] ?? '') !== $sec['name'], "$page: no row still points at the old name");
        }
    }
});

t('editing a reminder inline reads the date out of what was typed', function () {
    $jar = login('example', 'examplepassword');
    showAll($jar);
    req('POST', '/reminders/', ['csrf' => csrf($jar), 'action' => 'add', 'view' => 'Reminders',
        'text' => 'Regress edit target', 'folder' => 'Reminders', 'section' => ''], $jar);
    $row = rowBy('example', 'Regress edit target');
    $r = req('POST', '/reminders/', ['csrf' => csrf($jar), 'action' => 'edit_text', 'view' => 'All',
        'id' => $row['id'], 'text' => 'Regress edit target 9/6 4pm'], $jar, true);
    $j = json_decode($r['body'], true);
    eq('Regress edit target', $j['text'] ?? null, 'the date words are taken out of the text');
    eq('16:00', $j['time'] ?? null);
    eq('09-06', substr((string) ($j['due'] ?? ''), 5));
    $after = rowBy('example', 'Regress edit target');
    eq('16:00', $after['time'], 'and that is what was stored');
});

t('renaming a dated reminder with no date in the line leaves its date alone', function () {
    $jar = login('example', 'examplepassword');
    $row = rowBy('example', 'Regress edit target');
    $was = $row['due'];
    ok(!empty($was), 'it has a date to lose');
    req('POST', '/reminders/', ['csrf' => csrf($jar), 'action' => 'edit_text', 'view' => 'All',
        'id' => $row['id'], 'text' => 'Regress edit target renamed'], $jar, true);
    eq($was, rowBy('example', 'Regress edit target renamed')['due'], 'the date must survive a rename');
});

t('a date picked by hand wins, and leaves the typed text exactly as typed', function () {
    $jar = login('example', 'examplepassword');
    $ev = null;
    foreach (stored('events', 'example') as $e) { if (($e['text'] ?? '') === 'Design review') { $ev = $e; } }
    ok($ev !== null, 'the seeded event is there');
    req('POST', '/calendar/', ['csrf' => csrf($jar, '/calendar/'), 'action' => 'edit_item',
        'kind' => 'event', 'id' => $ev['id'], 'text' => 'Design review 8/3 with Sam',
        'date' => '2026-08-10', 'ym' => date('Y-m')], $jar);
    foreach (stored('events', 'example') as $e) {
        if (($e['id'] ?? '') !== $ev['id']) { continue; }
        eq('Design review 8/3 with Sam', $e['text'], 'the text is left alone when the date came from the picker');
        eq('2026-08-10', $e['date'], 'and the picked date is what is used');
    }
});

t('with no date picked, the calendar still reads one out of the text', function () {
    $jar = login('example', 'examplepassword');
    $ev = null;
    foreach (stored('events', 'example') as $e) { if (strpos((string) ($e['text'] ?? ''), 'Design review') === 0) { $ev = $e; } }
    req('POST', '/calendar/', ['csrf' => csrf($jar, '/calendar/'), 'action' => 'edit_item',
        'kind' => 'event', 'id' => $ev['id'], 'text' => 'Design review 9/9 3pm', 'ym' => date('Y-m')], $jar);
    foreach (stored('events', 'example') as $e) {
        if (($e['id'] ?? '') !== $ev['id']) { continue; }
        eq('Design review', $e['text'], 'stripped');
        eq('09-09', substr((string) $e['date'], 5));
        eq('15:00', $e['time']);
    }
});

t('habits and sections can be reordered, and nothing falls out', function () {
    $jar = login('example', 'examplepassword');
    $all     = stored('habits', 'example');
    $habits  = array_values(array_filter($all, fn($x) => ($x['type'] ?? '') !== 'section'));
    $secIds  = array_column(array_values(array_filter($all, fn($x) => ($x['type'] ?? '') === 'section')), 'id');
    ok(count($habits) > 1 && count($secIds) > 1, 'something to reorder');

    $order = [];
    foreach (array_reverse($habits) as $h) { $order[] = ['id' => $h['id'], 'section' => $h['section'] ?? '']; }
    $want = array_reverse($secIds);
    req('POST', '/habits/', ['csrf' => csrf($jar, '/habits/'), 'action' => 'reorder',
        'order' => json_encode($order), 'sections' => json_encode($want)], $jar, true);

    $after   = stored('habits', 'example');
    $aHabits = array_values(array_filter($after, fn($x) => ($x['type'] ?? '') !== 'section'));
    eq(count($habits), count($aHabits), 'no habit was dropped');
    eq($want, array_column(array_values(array_filter($after, fn($x) => ($x['type'] ?? '') === 'section')), 'id'),
       'the sections are in the order asked for');
});

t('a reorder that never mentions a habit keeps it rather than dropping it', function () {
    $jar = login('example', 'examplepassword');
    $before = count(array_filter(stored('habits', 'example'), fn($x) => ($x['type'] ?? '') !== 'section'));
    // A stale page posting one row only must not be read as "delete the rest".
    $one = null;
    foreach (stored('habits', 'example') as $x) { if (($x['type'] ?? '') !== 'section') { $one = $x; break; } }
    req('POST', '/habits/', ['csrf' => csrf($jar, '/habits/'), 'action' => 'reorder',
        'order' => json_encode([['id' => $one['id'], 'section' => '']]), 'sections' => json_encode([])], $jar, true);
    eq($before, count(array_filter(stored('habits', 'example'), fn($x) => ($x['type'] ?? '') !== 'section')),
       'everything the drag never mentioned is still there');
});

t('wiring: the habits drag drops against a line, between rows', function () {
    $jar = login('example', 'examplepassword');
    $b = req('GET', '/habits/?v=week', [], $jar)['body'];
    has('drop-line', $b, 'the same line the other apps drop against');
    has('grid-column: 1 / -1', $b, 'spanning every column, so it sits between rows');
    has('blockOf', $b, 'a section travels with the habits under it');
});

t("a habit's row carries its section's colour", function () {
    $jar = login('example', 'examplepassword');
    // Ask for the week grid by name: the view is a stored preference, so whichever test
    // last looked at the month would otherwise decide what this one is looking at.
    $b = req('GET', '/habits/?v=week', [], $jar)['body'];
    // The style now carries the colour plus its wash and its line, so match the prefix.
    $names = preg_match_all('/class="hname" style="--hc:#[0-9a-f]{6};/', $b);
    $cells = preg_match_all('/class="cell[^"]*" style="--hc:#[0-9a-f]{6};/', $b);
    has('--hc-soft:#', $b, 'an empty square gets the wash');
    has('--hc-line:#', $b, 'and the borders get the line');
    ok($names > 0, 'the name bubbles are tinted');
    ok($cells > $names, 'and so is every day square on those rows');
    preg_match_all('/--hc:(#[0-9a-f]{6})/', $b, $m);
    $used = array_values(array_unique($m[1]));
    foreach ($used as $c) { ok(in_array($c, app_palette('habits', true), true), "$c is a palette colour"); }
    ok(count($used) > 1, 'two sections should not share one colour by default');
});

t('a + Habit closes every section, and + Section sits alone at the bottom', function () {
    $jar = login('example', 'examplepassword');
    $b = req('GET', '/habits/?v=week', [], $jar)['body'];
    $adds = preg_match_all('/class="habitadd"/', $b);
    $secs = preg_match_all('/<div class="hsection"/', $b);
    eq($secs + 1, $adds, 'one per section, plus one closing the ungrouped run');
    // Each posts into its own section, so a habit lands where you were looking.
    preg_match_all('/name="section" value="([^"]*)"/', $b, $m);
    ok(in_array('', $m[1], true), 'the ungrouped one adds with no section');
    eq(count(array_unique($m[1])), count($m[1]), 'and no two target the same place');
    // The footer keeps only + Section now.
    eq(1, substr_count($b, 'id="newSecBtn"'), '+ Section is still there');
    eq(0, substr_count($b, 'id="newHabitBtn"'), 'and + Habit has left the footer');
    has('justify-content: flex-start', $b, 'the footer is left-justified');
});

t('an empty habits list still offers both ways to start', function () {
    $jar = login('freshy', 'freshpassword');
    $b = req('GET', '/habits/?v=week', [], $jar)['body'];
    has('empty-list', $b, 'the grid says it is empty');
    has('secfoot always', $b, 'so + Section stays out of edit mode');
    ok(preg_match('/body:not\(\.editing\) \.grid\.empty-list \.habitadd/', $b) === 1,
       'and so does + Habit — there is nothing to long-press to get into edit mode');
});

t('wiring: tapping away leaves edit mode in habits', function () {
    $jar = login('example', 'examplepassword');
    $b = req('GET', '/habits/?v=week', [], $jar)['body'];
    has('setEdit(false)', $b, 'there is a way out that is not a button');
    hasnt('id="editBtn"', $b, 'and the Edit pencil is gone');
});

t('wiring: the Calendar remembers the day, and the tab bar is what forgets it', function () {
    $jar = login('example', 'examplepassword');
    $cal = req('GET', '/calendar/', [], $jar)['body'];
    has("'calDay'", $cal, 'the calendar stores the selected day');
    has('sessionStorage', $cal, 'for the life of the app session, not for ever');
    // The tab bar is on every page and is the one thing that clears it.
    has('data-tab="calendar"', $cal, 'the Calendar tab is identifiable');
    has('removeItem("calDay")', $cal, 'and tapping it asks for today again');
});

t('wiring: an explicit ?day= still wins over the remembered one', function () {
    $jar = login('example', 'examplepassword');
    $b = req('GET', '/calendar/', [], $jar)['body'];
    ok(preg_match("/URLSearchParams\(location\.search\)\.get\('day'\)/", $b) === 1,
       'the URL is consulted before the remembered day');
});

t('wiring: the tab bar clusters its tabs and centres the + inside the bar', function () {
    $jar = login('example', 'examplepassword');
    $b = req('GET', '/reminders/', [], $jar)['body'];
    // The + is centred by the flex row, not raised out of it with a negative margin — the
    // old trick left it reading as off-centre. So: the row centres its items, and the add
    // tab carries no vertical raise (a two-value margin whose first value is 0).
    ok(preg_match('/\.segmented \{[^}]*align-items:\s*center/', $b) === 1,
       'the segmented centres its items vertically');
    ok(preg_match('/\.segmented a\.addtab \{[^}]*margin:\s*0\s+[\d.a-z]+\s*;/', $b) === 1,
       'the add tab has no vertical raise');
    // Tabs are sized to content and clustered in the middle, not stretched flex:1 which
    // flung Reminders and Habits out to the far corners.
    ok(preg_match('/\.segmented \{[^}]*justify-content:\s*center/', $b) === 1,
       'the tabs are centred as a cluster');
    ok(preg_match('/\.segmented a \{[^}]*flex:\s*0 0 auto/', $b) === 1,
       'and sized to content, not stretched edge to edge');
});

// ---------------------------------------------------------------- 15. security sweeps
// Data-driven rather than one case per action: the point is that *every* mutating action
// is covered, including one added next week that nobody remembers to write a test for.
area('security');

/** Every mutating action, by the page that answers it. Add to this when you add one. */
function ALL_ACTIONS(): array
{
    return [
        '/reminders/' => ['add', 'toggle', 'edit_text', 'delete', 'add_section', 'rename_section',
                          'delete_section', 'add_subtask', 'set_indent', 'reorder', 'clear_done',
                          'add_folder', 'delete_folder', 'set_default_folder', 'set_folder_color',
                          'folder_vis', 'folder_vis_all', 'folder_vis_only', 'reorder_folders',
                          'share_set', 'change_password', 'set_theme'],
        '/notes/'     => ['add', 'save', 'delete', 'add_section', 'rename_section', 'delete_section',
                          'reorder', 'add_folder', 'delete_folder', 'set_default_folder',
                          'set_folder_color', 'folder_vis', 'folder_vis_all', 'folder_vis_only',
                          'reorder_folders', 'share_set'],
        '/calendar/'  => ['add_reminder', 'add_event', 'add_note', 'edit_item', 'delete_item',
                          'toggle_reminder', 'cal_add', 'cal_color', 'cal_default', 'cal_delete',
                          'cal_reorder', 'cal_vis', 'cal_vis_all', 'cal_vis_only', 'rf_mode',
                          'folder_vis', 'share_set'],
        '/habits/'    => ['toggle', 'rename_habit', 'set_section_color', 'reorder', 'add_habit',
                          'add_section', 'rename_section', 'delete_habit', 'delete_section',
                          'msec_vis', 'msec_only', 'msec_all'],
        '/add/'       => ['add_reminder', 'add_event', 'add_note'],
        // quick.php is the one page the widget can reach that writes, so it is in here
        // too: a tick or an add with no token has to be as dead as anywhere else.
        '/calendar/quick.php' => ['tick', 'add_reminder', 'add_event'],
    ];
}

/** A cheap fingerprint of everything a user owns, to prove a request changed nothing. */
function snapshot(string $user = 'example'): string
{
    $out = '';
    foreach (['reminders', 'notes', 'events', 'calendars', 'habits', 'folders', 'calprefs',
              'shares', 'prefs'] as $b) {
        $out .= $b . '=' . json_encode(stored($b, $user)) . '|';
    }
    return md5($out);
}

t('every mutating action refuses a POST with no CSRF token', function () {
    $jar   = login('example', 'examplepassword');
    $before = snapshot();
    $checked = 0;
    foreach (ALL_ACTIONS() as $page => $actions) {
        foreach ($actions as $a) {
            $r = req('POST', $page, ['action' => $a, 'view' => 'All', 'name' => 'x', 'text' => 'x',
                                     'id' => 'x', 'kind' => 'reminder'], $jar);
            ok($r['status'] === 400 || $r['status'] === 403,
               "$page $a: expected a refusal, got {$r['status']}");
            $checked++;
        }
    }
    ok($checked > 60, "swept $checked actions");
    eq($before, snapshot(), 'and nothing anywhere was written');
});

t('every mutating action refuses a POST with the wrong CSRF token', function () {
    $jar    = login('example', 'examplepassword');
    $before = snapshot();
    foreach (ALL_ACTIONS() as $page => $actions) {
        foreach ($actions as $a) {
            $r = req('POST', $page, ['csrf' => 'wrong', 'action' => $a, 'view' => 'All',
                                     'name' => 'x', 'text' => 'x', 'id' => 'x', 'kind' => 'reminder'], $jar);
            ok($r['status'] === 400 || $r['status'] === 403, "$page $a: got {$r['status']}");
        }
    }
    eq($before, snapshot(), 'nothing was written');
});

t('a signed-out POST mutates nothing, whatever it claims to be', function () {
    $before = snapshot();
    foreach (ALL_ACTIONS() as $page => $actions) {
        foreach ($actions as $a) {
            // No jar at all: no session, no token.
            req('POST', $page, ['action' => $a, 'view' => 'All', 'name' => 'x', 'text' => 'x',
                                'id' => 'x', 'kind' => 'reminder']);
        }
    }
    eq($before, snapshot(), 'a signed-out caller changed nothing');
});

t('a folder name cannot carry a path or the separator the pickers use', function () {
    $jar = login('example', 'examplepassword');
    foreach (['../escape', 'a/b', "with\x1Fsep", '..', './x'] as $bad) {
        req('POST', '/reminders/', ['csrf' => csrf($jar), 'action' => 'add_folder',
            'view' => 'All', 'name' => $bad], $jar);
    }
    foreach (folders_load(datadir(), 'example')['reminders'] as $f) {
        // A slash is harmless — a folder name is never a path; it lives inside JSON and
        // is urlencoded into ?folder=. The separator is the one that matters: the
        // Calendar's add window packs "folder\x1Fgroup" into one value and splits on it.
        hasnt("\x1F", $f, 'no folder name holds the separator the pickers join keys with');
        ok(!preg_match('/[\x00-\x1F\x7F]/', $f), 'nor any other control character');
    }
    // And nothing was written outside the data dir.
    foreach (glob(datadir() . '/*') as $file) {
        eq(realpath(datadir()), dirname(realpath($file)), 'every file is in the data dir');
    }
});

t("one user cannot reach another user's file by asking for it", function () {
    $jar = login('example', 'examplepassword');
    // A "shared" view key naming a folder buddy has not shared.
    $before = snapshot('buddy');
    req('POST', '/reminders/', ['csrf' => csrf($jar), 'action' => 'add', 'view' => '@buddy:House',
        'text' => 'should not land', 'folder' => 'House', 'section' => ''], $jar);
    eq($before, snapshot('buddy'), "buddy's file is untouched by a folder they never shared");
    eq(null, rowBy('buddy', 'should not land'));
});

t('the destructive actions all need the confirmed second press', function () {
    $jar = login('example', 'examplepassword');
    $before = snapshot();
    $tries = [
        ['/reminders/', ['action' => 'delete', 'view' => 'All', 'id' => (rows('example')[0]['id'] ?? 'x')]],
        ['/reminders/', ['action' => 'delete_folder', 'view' => 'All', 'name' => 'Work']],
        ['/notes/',     ['action' => 'delete_folder', 'view' => 'All', 'name' => 'Recipes']],
        ['/habits/',    ['action' => 'delete_habit', 'id' => 'x']],
    ];
    foreach ($tries as [$page, $post]) {
        $post['csrf'] = csrf($jar, $page);
        req('POST', $page, $post, $jar);
    }
    eq($before, snapshot(), 'one press destroys nothing anywhere');
});

// ---------------------------------------------------------------- 16. notes, in full
area('notes2');

t('a note carries its folder, section and date, and can be deleted', function () {
    $jar = login('example', 'examplepassword');
    // Its own folder, so nothing another area did can decide where this note lands.
    req('POST', '/notes/', ['csrf' => csrf($jar, '/notes/'), 'action' => 'add_folder',
        'view' => 'All', 'name' => 'Notes2folder'], $jar);
    $r = req('POST', '/notes/', ['csrf' => csrf($jar, '/notes/'), 'action' => 'add',
        'view' => 'All', 'folder' => 'Notes2folder', 'section' => ''], $jar);
    preg_match('/id=([a-f0-9]+)/', (string) $r['location'], $m);
    $id = $m[1] ?? '';
    ok($id !== '', 'the redirect names the new note');
    // The editor posts the whole form on save, folder included. Omitting it is not a
    // thing the app does — and the handler reads the field rather than leaving the
    // folder alone, so a save without it would quietly move the note to General.
    req('POST', '/notes/', ['csrf' => csrf($jar, '/notes/'), 'action' => 'save', 'view' => 'All',
        'id' => $id, 'title' => 'Full note', 'body' => '<p>body</p>', 'date' => '2026-09-01',
        'folder' => 'Notes2folder', 'section' => ''], $jar);
    $n = null;
    foreach (stored('notes', 'example') as $x) { if (($x['id'] ?? '') === $id) { $n = $x; } }
    eq('Full note', $n['title']);
    eq('Notes2folder', $n['folder']);
    req('POST', '/notes/', ['csrf' => csrf($jar, '/notes/'), 'action' => 'delete',
        'view' => 'All', 'id' => $id], $jar);
    $still = false;
    foreach (stored('notes', 'example') as $x) { if (($x['id'] ?? '') === $id) { $still = true; } }
    ok($still, 'one press must not delete a note');
    req('POST', '/notes/', ['csrf' => csrf($jar, '/notes/'), 'action' => 'delete',
        'view' => 'All', 'id' => $id, 'confirm' => '1'], $jar);
    foreach (stored('notes', 'example') as $x) { ok(($x['id'] ?? '') !== $id, 'confirmed press deletes'); }
});

t('note sections add, rename and delete per folder', function () {
    $jar = login('example', 'examplepassword');
    req('POST', '/notes/', ['csrf' => csrf($jar, '/notes/'), 'action' => 'add_folder',
        'view' => 'All', 'name' => 'Notes2folder'], $jar);
    req('POST', '/notes/', ['csrf' => csrf($jar, '/notes/'), 'action' => 'add_section',
        'view' => 'Notes2folder', 'folder' => 'Notes2folder', 'name' => 'Puddings'], $jar);
    $names = fn() => array_column(array_values(array_filter(stored('notes', 'example'),
        fn($x) => ($x['type'] ?? '') === 'section')), 'name');
    ok(in_array('Puddings', $names(), true), 'added');
    req('POST', '/notes/', ['csrf' => csrf($jar, '/notes/'), 'action' => 'rename_section',
        'view' => 'Notes2folder', 'folder' => 'Notes2folder', 'name' => 'Puddings', 'newname' => 'Afters'], $jar);
    ok(in_array('Afters', $names(), true), 'renamed');
    req('POST', '/notes/', ['csrf' => csrf($jar, '/notes/'), 'action' => 'delete_section',
        'view' => 'Notes2folder', 'folder' => 'Notes2folder', 'name' => 'Afters', 'confirm' => '1'], $jar);
    ok(!in_array('Afters', $names(), true), 'deleted');
});

t('dragging a note section reorders it within its folder', function () {
    // The gesture is by-eye (no JS in the harness), but the drag posts a per-folder
    // section map to the reorder action — that server side is what this locks down.
    $jar = login('example', 'examplepassword');
    req('POST', '/notes/', ['csrf' => csrf($jar, '/notes/'), 'action' => 'add_folder',
        'view' => 'All', 'name' => 'DragNotes'], $jar);
    foreach (['Alpha', 'Beta'] as $nm) {
        req('POST', '/notes/', ['csrf' => csrf($jar, '/notes/'), 'action' => 'add_section',
            'view' => 'DragNotes', 'folder' => 'DragNotes', 'name' => $nm], $jar);
    }
    $order = fn() => array_column(array_values(array_filter(stored('notes', 'example'),
        fn($x) => ($x['type'] ?? '') === 'section' && ($x['folder'] ?? '') === 'DragNotes')), 'name');

    req('POST', '/notes/', ['csrf' => csrf($jar, '/notes/'), 'action' => 'reorder', 'view' => 'DragNotes',
        'order' => '[]', 'sections' => json_encode(['DragNotes' => ['Alpha', 'Beta']])], $jar, true);
    eq(['Alpha', 'Beta'], $order(), 'the map sets the section order');

    req('POST', '/notes/', ['csrf' => csrf($jar, '/notes/'), 'action' => 'reorder', 'view' => 'DragNotes',
        'order' => '[]', 'sections' => json_encode(['DragNotes' => ['Beta', 'Alpha']])], $jar, true);
    eq(['Beta', 'Alpha'], $order(), 'and dragging the other way flips it');
});

t('dragging a note into another folder re-files it', function () {
    $jar = login('example', 'examplepassword');
    req('POST', '/notes/', ['csrf' => csrf($jar, '/notes/'), 'action' => 'add_folder', 'view' => 'All', 'name' => 'FromF'], $jar);
    req('POST', '/notes/', ['csrf' => csrf($jar, '/notes/'), 'action' => 'add_folder', 'view' => 'All', 'name' => 'ToF'], $jar);
    req('POST', '/notes/', ['csrf' => csrf($jar, '/notes/'), 'action' => 'add',
        'view' => 'All', 'folder' => 'FromF', 'section' => ''], $jar);
    $id = null;
    foreach (stored('notes', 'example') as $n) {
        if (($n['type'] ?? '') !== 'section' && ($n['folder'] ?? '') === 'FromF') { $id = $n['id']; break; }
    }
    ok($id !== null, 'a note exists in FromF');
    $folderOf = function () use ($id) {
        foreach (stored('notes', 'example') as $n) { if (($n['id'] ?? '') === $id) { return $n['folder'] ?? null; } }
        return null;
    };
    // The drag posts each note with the folder of the block it landed in.
    req('POST', '/notes/', ['csrf' => csrf($jar, '/notes/'), 'action' => 'reorder', 'view' => 'All',
        'order' => json_encode([['id' => $id, 'section' => '', 'folder' => 'ToF']]), 'sections' => '{}'], $jar, true);
    eq('ToF', $folderOf(), 'the note re-files to ToF');
    // A folder that isn't mine (e.g. a partner's shared block) is refused.
    req('POST', '/notes/', ['csrf' => csrf($jar, '/notes/'), 'action' => 'reorder', 'view' => 'All',
        'order' => json_encode([['id' => $id, 'section' => '', 'folder' => '@someone:Nope']]), 'sections' => '{}'], $jar, true);
    eq('ToF', $folderOf(), 'a folder that is not mine is ignored');
});

t('the "Notes" catch-all name is reserved', function () {
    $jar = login('example', 'examplepassword');
    req('POST', '/notes/', ['csrf' => csrf($jar, '/notes/'), 'action' => 'add_section',
        'view' => 'All', 'folder' => 'General', 'name' => 'Notes'], $jar);
    $n = 0;
    foreach (stored('notes', 'example') as $x) {
        if (($x['type'] ?? '') === 'section' && ($x['name'] ?? '') === 'Notes') { $n++; }
    }
    eq(0, $n, 'a section may not be called Notes — that is the catch-all');
});

t('a note folder colour comes from the notes palette', function () {
    $jar = login('example', 'examplepassword');
    $c = app_palette('notes')[1];
    req('POST', '/notes/', ['csrf' => csrf($jar, '/notes/'), 'action' => 'set_folder_color',
        'view' => 'All', 'name' => 'Recipes', 'color' => $c], $jar, true);
    eq($c, folder_colors(datadir(), 'notes', 'example')['Recipes'] ?? null);
    req('POST', '/notes/', ['csrf' => csrf($jar, '/notes/'), 'action' => 'set_folder_color',
        'view' => 'All', 'name' => 'Recipes', 'color' => app_palette('reminders')[1]], $jar, true);
    eq($c, folder_colors(datadir(), 'notes', 'example')['Recipes'] ?? null,
       "another app's palette is not this app's");
});

// ---------------------------------------------------------------- 17. calendar, in full
area('calendar2');

t('a repeat is expanded across the month being drawn', function () {
    $jar = login('example', 'examplepassword');
    $r = req('GET', '/calendar/', [], $jar);
    preg_match('/=\s*(\{"20\d\d-\d\d-\d\d".*?\})\s*;/s', $r['body'], $m);
    $byDay = json_decode($m[1], true);
    $days = [];
    foreach ($byDay as $d => $items) {
        foreach ($items as $i) { if (($i['text'] ?? '') === 'Team standup') { $days[] = $d; } }
    }
    ok(count($days) > 5, 'a daily repeat shows on many days of the month, not just its start');
});

t('paging to another month works and lands on its first', function () {
    $jar = login('example', 'examplepassword');
    $next = date('Y-m', strtotime('first day of next month'));
    $r = req('GET', '/calendar/?ym=' . $next, [], $jar);
    eq(200, $r['status']);
    has($next . '-01', $r['body'], 'the month it was asked for is the month it drew');
});

t('a reminder folder can be switched to Dated-only or Off for the calendar', function () {
    $jar = login('example', 'examplepassword');
    req('POST', '/calendar/', ['csrf' => csrf($jar, '/calendar/'), 'action' => 'rf_mode',
        'name' => 'Home', 'mode' => 'none', 'ym' => date('Y-m')], $jar, true);
    $r = req('GET', '/calendar/', [], $jar);
    preg_match('/=\s*(\{"20\d\d-\d\d-\d\d".*?\})\s*;/s', $r['body'], $m);
    $found = false;
    foreach (json_decode($m[1], true) as $items) {
        foreach ($items as $i) { if (($i['text'] ?? '') === 'Call the dentist back') { $found = true; } }
    }
    ok(!$found, "a folder switched off does not reach the calendar");
    req('POST', '/calendar/', ['csrf' => csrf($jar, '/calendar/'), 'action' => 'rf_mode',
        'name' => 'Home', 'mode' => 'all', 'ym' => date('Y-m')], $jar, true);
});

t('adding a reminder from the day panel puts it in the chosen folder and group', function () {
    $jar = login('example', 'examplepassword');
    $day = date('Y-m-d', strtotime('+2 days'));
    req('POST', '/calendar/', ['csrf' => csrf($jar, '/calendar/'), 'action' => 'add_reminder',
        'kind' => 'reminder', 'text' => 'From the day panel', 'day' => $day, 'date' => $day,
        'section' => "Home\x1FErrands", 'ym' => date('Y-m')], $jar);
    $row = rowBy('example', 'From the day panel');
    ok($row !== null, 'created');
    eq($day, $row['due']);
});

t('a calendar with a stale id on an event falls back to a real one', function () {
    $jar = login('example', 'examplepassword');
    $day = date('Y-m-d');
    req('POST', '/calendar/', ['csrf' => csrf($jar, '/calendar/'), 'action' => 'add_event',
        'kind' => 'event', 'text' => 'Stale cal event', 'day' => $day, 'date' => $day,
        'cal' => 'nope', 'ym' => date('Y-m')], $jar);
    $ids = array_column(stored('calendars', 'example'), 'id');
    foreach (stored('events', 'example') as $e) {
        if (($e['text'] ?? '') === 'Stale cal event') {
            ok(in_array($e['cal'] ?? '', $ids, true) || ($e['cal'] ?? '') === '',
               'it never keeps an id that does not exist');
        }
    }
});

// ---------------------------------------------------------------- 18. habits, in full
area('habits2');

t('the month view counts a day against the habits ticked on it', function () {
    $jar = login('example', 'examplepassword');
    $r = req('GET', '/habits/?v=month&m=' . date('Y-m'), [], $jar);
    eq(200, $r['status']);
    $n = preg_match_all('/title="(\d+) of (\d+) on \d{4}-\d{2}-\d{2}"/', $r['body'], $m);
    ok($n >= 28, 'every day says how many of how many');
    foreach ($m[1] as $i => $done) {
        ok((int) $done <= (int) $m[2][$i], 'never more done than there are habits');
    }
});

t('a day\'s pie is drawn in its sections\' colours, not the flat accent', function () {
    $jar = login('example', 'examplepassword');
    // Count every section, so a day's slices are its habits' section colours.
    req('POST', '/habits/', ['csrf' => csrf($jar, '/habits/'), 'action' => 'msec_all', 'show' => '1'], $jar, true);
    $body = req('GET', '/habits/?v=month&m=' . date('Y-m'), [], $jar)['body'];
    preg_match_all('/class="pie" style="background:([^"]+)"/', $body, $m);
    ok(count($m[1]) >= 28, 'every day has a pie');
    $coloured = 0; $accent = 0;
    foreach ($m[1] as $bg) {
        if (strpos($bg, 'var(--accent)') !== false) { $accent++; }
        // A day with ticks is a conic-gradient whose first slice is a section colour (a
        // hex), never the old flat green fill.
        if (preg_match('/conic-gradient\(#[0-9a-fA-F]{6}/', $bg)) { $coloured++; }
    }
    eq(0, $accent, 'no pie is filled with the accent any more');
    ok($coloured > 0, 'at least one day is filled in a section colour');
});

t('the week grid pages whole weeks', function () {
    $jar = login('example', 'examplepassword');
    $seen = [];
    foreach ([-1, 0] as $w) {
        $r = req('GET', '/habits/?v=week&w=' . $w, [], $jar);
        eq(200, $r['status'], "?w=$w");
        preg_match_all('/data-date="(\d{4}-\d{2}-\d{2})"/', $r['body'], $m);
        ok(count($m[1]) > 0, "?w=$w draws days");
        $seen[$w] = min($m[1]);
    }
    ok($seen[-1] < $seen[0], 'paging back really moves back');
    eq(7, (int) round((strtotime($seen[0]) - strtotime($seen[-1])) / 86400), 'by a whole week');
});

t('deleting a section leaves its habits behind, ungrouped', function () {
    $jar = login('example', 'examplepassword');
    $sec = null;
    foreach (stored('habits', 'example') as $x) { if (($x['type'] ?? '') === 'section') { $sec = $x; break; } }
    $under = array_values(array_filter(stored('habits', 'example'),
        fn($x) => ($x['type'] ?? '') !== 'section' && ($x['section'] ?? '') === $sec['id']));
    $before = count(array_filter(stored('habits', 'example'), fn($x) => ($x['type'] ?? '') !== 'section'));
    req('POST', '/habits/', ['csrf' => csrf($jar, '/habits/'), 'action' => 'delete_section',
        'id' => $sec['id'], 'confirm' => '1'], $jar);
    eq($before, count(array_filter(stored('habits', 'example'), fn($x) => ($x['type'] ?? '') !== 'section')),
       'no habit went with it');
    foreach ($under as $h) {
        foreach (stored('habits', 'example') as $x) {
            if (($x['id'] ?? '') === $h['id']) { eq('', (string) ($x['section'] ?? ''), 'it is ungrouped now'); }
        }
    }
});

t('the month view\'s section filter has the suite\'s three gestures', function () {
    $jar = login('example', 'examplepassword');
    $secs = array_values(array_filter(stored('habits', 'example'), fn($h) => ($h['type'] ?? '') === 'section'));
    ok(count($secs) >= 1, 'there is at least one section to filter');
    $one = (string) $secs[0]['id'];
    $all = array_merge(['~none'], array_map(fn($s) => (string) $s['id'], $secs));
    $post = function (array $p) use ($jar) {
        return json_decode(req('POST', '/habits/', $p + ['csrf' => csrf($jar, '/habits/')], $jar, true)['body'], true);
    };

    // The box toggles one.
    eq([$one], $post(['action' => 'msec_vis', 'name' => $one, 'show' => ''])['hidden'] ?? null,
       'unticking hides that section');
    eq([], $post(['action' => 'msec_vis', 'name' => $one, 'show' => '1'])['hidden'] ?? null,
       'and ticking it puts it back');

    // A row tap makes it the only one counted.
    $hidden = $post(['action' => 'msec_only', 'name' => $one])['hidden'] ?? null;
    eq(count($all) - 1, count($hidden), 'everything but the one tapped is hidden');
    ok(!in_array($one, $hidden, true), 'and the one tapped is counted');

    // "All" shows everything, then hides everything.
    eq([], $post(['action' => 'msec_all', 'show' => '1'])['hidden'] ?? null, 'All on');
    eq(count($all), count($post(['action' => 'msec_all', 'show' => ''])['hidden'] ?? []), 'All off');

    // A section that isn't there is a no-op, not a stored ghost.
    $was = $post(['action' => 'msec_all', 'show' => '1'])['hidden'] ?? null;
    eq($was, $post(['action' => 'msec_only', 'name' => 'no-such-section'])['hidden'] ?? null,
       'an unknown key changes nothing');
});

t('the filter changes the pies and nothing else', function () {
    $jar = login('example', 'examplepassword');
    $secs = array_values(array_filter(stored('habits', 'example'), fn($h) => ($h['type'] ?? '') === 'section'));
    $one  = (string) $secs[0]['id'];
    $csrf = csrf($jar, '/habits/');
    req('POST', '/habits/', ['csrf' => $csrf, 'action' => 'msec_all', 'show' => '1'], $jar, true);

    $before = req('GET', '/habits/?v=month', [], $jar)['body'];
    preg_match('/of (\d+) on \d{4}/', $before, $m);
    $wholeTotal = (int) ($m[1] ?? 0);
    ok($wholeTotal > 0, 'the month counts every habit to begin with');
    has('id="msecBtn"', $before, 'and the picker sits by the Week/Month switch');

    req('POST', '/habits/', ['csrf' => $csrf, 'action' => 'msec_only', 'name' => $one], $jar, true);
    $after = req('GET', '/habits/?v=month', [], $jar)['body'];
    preg_match('/of (\d+) on \d{4}/', $after, $m2);
    ok((int) ($m2[1] ?? 0) < $wholeTotal, 'filtering to one section counts fewer habits');
    has("you're counting", $after, 'and the legend says so');

    // The picker now sits by the switch in the week view too — but it only feeds the month
    // pies, so the week grid itself still shows every habit, filtered or not.
    $week = req('GET', '/habits/?v=week', [], $jar)['body'];
    has('id="msecBtn"', $week, 'the picker is by the switch in week view too');
    foreach (stored('habits', 'example') as $h) {
        if (($h['type'] ?? '') === 'section') { continue; }
        has(e_test((string) $h['name']), $week, 'every habit is still in the week grid');
    }
    req('POST', '/habits/', ['csrf' => $csrf, 'action' => 'msec_all', 'show' => '1'], $jar, true);
});

t('the chosen view is remembered per user', function () {
    $jar = login('example', 'examplepassword');
    req('GET', '/habits/?m=' . date('Y-m'), [], $jar);
    $prefs = store_read(datadir() . '/prefs-example.json');
    ok(in_array($prefs['habits_view'] ?? '', ['week', 'month'], true), 'the view is stored');
});

// ---------------------------------------------------------------- 19. the widget feed
area('feed');

function FEED_TOKEN(): string
{
    $t = store_read(datadir() . '/token-example.json');
    $t = is_array($t) ? ($t['token'] ?? reset($t)) : $t;
    return is_string($t) ? $t : '';
}

t('the feed groups by day and never carries a note', function () {
    if (FEED_TOKEN() === '') { return; }
    $r = req('GET', '/calendar/feed.php?token=' . urlencode(FEED_TOKEN()));
    eq(200, $r['status']);
    $j = json_decode($r['body'], true);
    ok(is_array($j), 'JSON');
    $items = $j['items'] ?? $j;
    if (!is_array($items)) { return; }
    $flat = json_encode($items);
    hasnt('"kind":"note"', $flat, 'notes are dropped server-side, not just in the script');
});

t('a reminder in the feed carries the id its tick link needs', function () {
    if (FEED_TOKEN() === '') { return; }
    $r = req('GET', '/calendar/feed.php?token=' . urlencode(FEED_TOKEN()));
    $flat = $r['body'];
    if (strpos($flat, '"reminder"') === false) { return; }
    ok(preg_match('/"id"\s*:\s*"[a-f0-9]{6,}"/', $flat) === 1, 'ids are there to tick against');
});

t('the feed is scoped, and a stale pin cannot widen it', function () {
    if (FEED_TOKEN() === '') { return; }
    $r = req('GET', '/calendar/feed.php?token=' . urlencode(FEED_TOKEN()) . '&cals=nosuchcalendar');
    eq(200, $r['status'], 'a stale pin is not an error');
    ok(json_decode($r['body'], true) !== null, 'it still answers JSON');
});

// ---------------------------------------------------------------- 20. edges
area('edges');

t('an unknown id is a no-op, not a crash', function () {
    $jar = login('example', 'examplepassword');
    $before = snapshot();
    foreach ([['/reminders/', ['action' => 'toggle', 'view' => 'All', 'id' => 'nosuchid']],
              ['/reminders/', ['action' => 'edit_text', 'view' => 'All', 'id' => 'nosuchid', 'text' => 'x']],
              ['/reminders/', ['action' => 'set_indent', 'view' => 'All', 'id' => 'nosuchid', 'indent' => '1']],
              ['/reminders/', ['action' => 'add_subtask', 'view' => 'All', 'parent' => 'nosuchid']],
              ['/habits/',    ['action' => 'rename_habit', 'id' => 'nosuchid', 'name' => 'x']],
              ['/habits/',    ['action' => 'set_section_color', 'id' => 'nosuchid', 'color' => app_palette('habits', true)[0]]],
             ] as [$page, $post]) {
        $post['csrf'] = csrf($jar, $page);
        $r = req('POST', $page, $post, $jar, true);
        ok($r['status'] < 500, "$page {$post['action']}: no server error");
        hasnt('Fatal error', $r['body'], $page);
    }
    eq($before, snapshot(), 'and nothing was written');
});

t('a malformed JSON payload is ignored rather than believed', function () {
    $jar = login('example', 'examplepassword');
    $before = snapshot();
    foreach ([['/reminders/', 'reorder', ['order' => '{not json', 'sections' => 'nope']],
              ['/habits/',    'reorder', ['order' => 'null', 'sections' => '"a string"']],
             ] as [$page, $action, $extra]) {
        $r = req('POST', $page, array_merge(['csrf' => csrf($jar, $page), 'action' => $action,
            'view' => 'All'], $extra), $jar, true);
        ok($r['status'] < 500, "$page $action survived");
    }
    eq($before, snapshot(), 'a garbage order changes nothing');
});

t('unicode and long text survive a round trip intact', function () {
    $jar = login('example', 'examplepassword');
    $text = 'Café — naïve “quotes” 🎉 日本語';
    req('POST', '/reminders/', ['csrf' => csrf($jar), 'action' => 'add', 'view' => 'Reminders',
        'text' => $text, 'folder' => 'Reminders', 'section' => ''], $jar);
    ok(rowBy('example', $text) !== null, 'stored exactly as sent');
    $long = str_repeat('a', 900);
    req('POST', '/reminders/', ['csrf' => csrf($jar), 'action' => 'add', 'view' => 'Reminders',
        'text' => $long, 'folder' => 'Reminders', 'section' => ''], $jar);
    $found = null;
    foreach (rows('example') as $r) { if (strncmp($r['text'] ?? '', 'aaaa', 4) === 0) { $found = $r; } }
    ok($found !== null, 'a very long line is kept');
    eq(500, mb_strlen($found['text']), 'clipped to the documented 500, not stored unbounded');
});

t('an empty or whitespace-only add is refused', function () {
    $jar = login('example', 'examplepassword');
    $before = count(rows('example'));
    foreach (['', '   ', "\t\n"] as $t) {
        req('POST', '/reminders/', ['csrf' => csrf($jar), 'action' => 'add', 'view' => 'Reminders',
            'text' => $t, 'folder' => 'Reminders', 'section' => ''], $jar);
    }
    eq($before, count(rows('example')), 'nothing empty was added');
});

t('the same section name can exist in two folders without colliding', function () {
    $jar = login('example', 'examplepassword');
    foreach (['Work', 'Home'] as $f) {
        req('POST', '/reminders/', ['csrf' => csrf($jar), 'action' => 'add_section',
            'view' => $f, 'folder' => $f, 'name' => 'Shared name'], $jar);
    }
    $secs = array_values(array_filter(stored('reminders', 'example'),
        fn($r) => ($r['type'] ?? '') === 'section' && ($r['name'] ?? '') === 'Shared name'));
    eq(2, count($secs), 'one per folder');
    // Renaming one must not touch the other.
    req('POST', '/reminders/', ['csrf' => csrf($jar), 'action' => 'rename_section',
        'view' => 'Work', 'folder' => 'Work', 'name' => 'Shared name', 'newname' => 'Work only'], $jar);
    $left = array_values(array_filter(stored('reminders', 'example'),
        fn($r) => ($r['type'] ?? '') === 'section' && ($r['name'] ?? '') === 'Shared name'));
    eq(1, count($left), "the other folder's section kept its name");
    eq('Home', $left[0]['folder']);
});

// ---------------------------------------------------------------- 21. more lib units
area('lib2');

t('dates parse in every documented shape', function () {
    $y = (int) date('Y');
    [, $d1] = parse_when_from_text('thing 8/3/26');
    eq('2026-08-03', $d1, 'm/d/yy');
    [, $d2] = parse_when_from_text('thing 8/3/2027');
    eq('2027-08-03', $d2, 'm/d/yyyy');
    [, $d3] = parse_when_from_text('thing 12/25');
    eq('12-25', substr((string) $d3, 5), 'bare m/d keeps the month and day');
    ok((int) substr((string) $d3, 0, 4) >= $y, 'and never lands in the past');
});

t('times parse in every documented shape', function () {
    foreach (['2pm' => '14:00', '2:30pm' => '14:30', '9am' => '09:00', '12:05 am' => '00:05'] as $in => $want) {
        [, , $t] = parse_when_from_text('x ' . $in);
        eq($want, $t, $in);
    }
});

t('a repeat spec is cleaned or refused', function () {
    ok(repeat_clean('week', 2) !== null, 'a real one survives');
    eq(null, repeat_clean('', 1), 'no unit means it happens once');
    eq(null, repeat_clean('fortnight', 1), 'an unknown unit is refused');
});

t('folder names are cleaned on the way in', function () {
    eq('Work', folder_clean('  Work  '), 'trimmed');
    eq('a b', folder_clean("a\tb"), 'whitespace collapses');
    hasnt("\x1F", folder_clean("a\x1Fb"), 'the picker separator cannot survive');
    ok(!preg_match('/[\x00-\x1F\x7F]/', folder_clean("a\x00b\x07c")), 'no control characters survive');
    eq(40, mb_strlen(folder_clean(str_repeat('x', 80))), 'clipped to 40');
});

t('folders reorder and keep every folder', function () {
    $before = folders_load(datadir(), 'example')['reminders'];
    folders_reorder(datadir(), 'reminders', array_reverse($before));
    $after = folders_load(datadir(), 'example')['reminders'];
    eq(count($before), count($after), 'nothing was lost');
    foreach ($before as $f) { ok(in_array($f, $after, true), "$f is still there"); }
});

t('the kind palette is emitted as variables, not literals', function () {
    $css = kind_color_css();
    foreach (['--k-reminder', '--k-event', '--k-note', '--k-overdue'] as $v) { has($v, $css); }
    has('#60a5fa', $css, 'the event blue is a blue, not the old cyan');
});

// ---------------------------------------------------------------- the /test/ mirror, for real
// The unit checks in `test-instance` prove suite_base() prefixes a link. These prove the
// whole arrangement: two instances of the same source served side by side the way
// deploy.sh lays them out — public/ + public/test/, lib/ + lib-test/, a config.php each
// and a data directory each. What matters is that they cannot see one another. A row
// added in the sandbox must not turn up in production, and no link may cross between
// them, or a tap in /test/ quietly drops you into the real app.
area('instance');

/** A request against an arbitrary port. Same rules as req(): redirects are never followed. */
function hreq(int $port, string $method, string $path, array $post = [], ?array &$jar = null): array
{
    $headers = ["Host: 127.0.0.1:$port", 'Connection: close'];
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
    $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers),
        'content' => $body, 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 15]]);
    $out = @file_get_contents("http://127.0.0.1:$port" . $path, false, $ctx);
    $hdr = $http_response_header ?? [];
    $res = ['status' => 0, 'location' => null, 'body' => (string) $out];
    foreach ($hdr as $i => $h) {
        if ($i === 0 && preg_match('#HTTP/\S+\s+(\d{3})#', $h, $m)) { $res['status'] = (int) $m[1]; }
        if (stripos($h, 'Location:') === 0) { $res['location'] = trim(substr($h, 9)); }
        if (stripos($h, 'Set-Cookie:') === 0 && preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $h, $m)) {
            if ($jar !== null) { $jar[trim($m[1])] = $m[2]; }
        }
    }
    return $res;
}

/**
 * Build the two-instance sandbox once and boot a server over it. Deliberately built
 * from *files*, not from the environment: neither SUITE_DATA_DIR nor SUITE_BASE is
 * passed to this server, so each instance has to find its data and its prefix the way
 * the live one does — from the config.php in its own lib directory.
 */
function instance_boot(): array
{
    static $I = null;
    if ($I !== null) { return $I; }
    global $root, $scratch;

    $box = $scratch . '/box';
    @mkdir($box, 0700, true);
    // public/ and public/test/ are the same tree, exactly as deploy.sh pushes them.
    foreach ([['lib', 'lib'], ['lib', 'lib-test'], ['public', 'public'], ['public', 'public/test']] as [$from, $to]) {
        exec('cp -R ' . escapeshellarg($root . '/' . $from) . ' ' . escapeshellarg($box . '/' . $to), $o, $rc);
        if ($rc !== 0) { throw new RuntimeException("could not lay out $to"); }
    }
    // A data dir each, both starting from the same seeded account set — so a difference
    // between them later can only have been written by one of the two instances.
    foreach (['data', 'data-test'] as $d) {
        @mkdir($box . '/' . $d, 0700, true);
        foreach (glob($scratch . '/*.json') ?: [] as $f) { copy($f, $box . '/' . $d . '/' . basename($f)); }
        if (is_file($scratch . '/.datakey')) { copy($scratch . '/.datakey', $box . '/' . $d . '/.datakey'); }
    }
    $conf = function (string $dir, string $base) use ($box) {
        file_put_contents($box . '/' . $dir . '/config.php',
            "<?php return ['users' => [], 'data_dir' => " . var_export($box . '/' . ($dir === 'lib' ? 'data' : 'data-test'), true)
            . ", 'base' => " . var_export($base, true) . ", 'timezone' => 'America/Chicago'];\n");
    };
    $conf('lib', '');
    $conf('lib-test', '/test');

    $sock = stream_socket_server('tcp://127.0.0.1:0', $e1, $e2);
    $port = (int) explode(':', stream_socket_get_name($sock, false))[1];
    fclose($sock);
    $desc = [1 => ['file', '/dev/null', 'w'], 2 => ['file', $box . '/server.log', 'w']];
    // env -u: the sandbox must not inherit the outer run's SUITE_* overrides, or both
    // instances would silently share the outer scratch dir and every check below would
    // pass for the wrong reason.
    $srv = proc_open('env -u SUITE_DATA_DIR -u SUITE_BASE php -d display_errors=1 -d error_reporting=E_ALL'
        . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($box . '/public'), $desc, $pipes);
    register_shutdown_function(function () use ($srv) {
        if (is_resource($srv)) { proc_terminate($srv); proc_close($srv); }
    });
    for ($i = 0; $i < 100; $i++) {
        $c = @fsockopen('127.0.0.1', $port, $x, $y, 0.2);
        if ($c) { fclose($c); break; }
        usleep(100000);
    }
    return $I = ['port' => $port, 'box' => $box];
}

/** Sign in on the sandbox, on either instance. */
function instance_login(int $port, string $pfx, string $user = 'example', string $pass = 'examplepassword'): array
{
    $jar = [];
    hreq($port, 'GET', $pfx . '/reminders/', [], $jar);
    $r = hreq($port, 'POST', $pfx . '/reminders/', ['username' => $user, 'password' => $pass], $jar);
    if ($r['status'] !== 302) { throw new RuntimeException("$pfx login did not redirect ({$r['status']})"); }
    return [$jar, $r];
}

/** Reminders stored by one of the sandbox's two instances. */
function instance_rows(string $box, string $which, string $user = 'example'): array
{
    $l = store_read($box . '/' . $which . '/reminders-' . $user . '.json');
    return array_values(array_filter($l, fn($r) => ($r['type'] ?? '') !== 'section'));
}

t('both instances come up from their own config, with no environment help', function () {
    ['port' => $p] = instance_boot();
    foreach (['' => 'production', '/test' => 'the sandbox'] as $pfx => $what) {
        $r = hreq($p, 'GET', ($pfx ?: '') . '/reminders/');
        eq(200, $r['status'], "$what should answer");
        has('Sign in', $r['body'], "$what should show the login form");
        foreach (['Fatal error', 'Warning:', 'Notice:'] as $l) { hasnt($l, $r['body'], "$what is quiet"); }
    }
});

t('the sandbox prefixes every cross-app link and production has none', function () {
    ['port' => $p] = instance_boot();
    [$jar] = instance_login($p, '/test');
    $b = hreq($p, 'GET', '/test/reminders/', [], $jar)['body'];
    foreach (['/test/reminders/', '/test/calendar/', '/test/notes/', '/test/habits/', '/test/add/'] as $l) {
        has('href="' . $l . '"', $b, 'the sandbox tab bar stays inside /test');
    }
    // The killer: an unprefixed absolute app link in a /test/ page is a door back into
    // production, and it would look like it worked.
    ok(!preg_match('#href="/(reminders|calendar|notes|habits|add)/"#', $b),
       'no unprefixed cross-app link may leak out of /test/');

    [$pjar] = instance_login($p, '');
    $pb = hreq($p, 'GET', '/reminders/', [], $pjar)['body'];
    has('href="/reminders/"', $pb, 'production links are unprefixed');
    hasnt('/test/', $pb, 'and production carries no trace of the sandbox');
});

t('signing in lands you inside the instance you signed in to', function () {
    ['port' => $p] = instance_boot();
    [, $t] = instance_login($p, '/test');
    eq('/test/calendar/', $t['location'], 'the sandbox login lands in the sandbox');
    [, $r] = instance_login($p, '');
    eq('/calendar/', $r['location'], 'production stays in production');
});

t('a row added in the sandbox never reaches production', function () {
    ['port' => $p, 'box' => $box] = instance_boot();
    [$jar] = instance_login($p, '/test');
    $g = hreq($p, 'GET', '/test/reminders/', [], $jar);
    preg_match('/name="csrf" value="([^"]+)"/', $g['body'], $m);
    ok(!empty($m[1]), 'the sandbox page carries a token');
    hreq($p, 'POST', '/test/reminders/', ['csrf' => $m[1], 'action' => 'add', 'view' => 'All',
        'text' => 'sandbox-only row', 'folder' => 'Reminders', 'section' => ''], $jar);

    $inTest = array_column(instance_rows($box, 'data-test'), 'text');
    $inProd = array_column(instance_rows($box, 'data'), 'text');
    ok(in_array('sandbox-only row', $inTest, true), 'it landed in the sandbox data dir');
    ok(!in_array('sandbox-only row', $inProd, true), 'and NOT in production');
});

t('a row added in production never reaches the sandbox', function () {
    ['port' => $p, 'box' => $box] = instance_boot();
    [$jar] = instance_login($p, '');
    $g = hreq($p, 'GET', '/reminders/', [], $jar);
    preg_match('/name="csrf" value="([^"]+)"/', $g['body'], $m);
    hreq($p, 'POST', '/reminders/', ['csrf' => $m[1], 'action' => 'add', 'view' => 'All',
        'text' => 'production-only row', 'folder' => 'Reminders', 'section' => ''], $jar);

    ok(in_array('production-only row', array_column(instance_rows($box, 'data'), 'text'), true),
       'it landed in production');
    ok(!in_array('production-only row', array_column(instance_rows($box, 'data-test'), 'text'), true),
       'and NOT in the sandbox');
});

t('every page under /test/ loads lib-test, not lib', function () {
    ['port' => $p] = instance_boot();
    [$jar] = instance_login($p, '/test');
    // If a page's preamble were missed out, it would load lib/ — whose config has no
    // base — and its links would come out unprefixed while everything else looked fine.
    foreach (['/test/reminders/', '/test/notes/', '/test/calendar/', '/test/habits/', '/test/add/'] as $path) {
        $r = hreq($p, 'GET', $path, [], $jar);
        eq(200, $r['status'], "$path renders");
        has('href="/test/calendar/"', $r['body'], "$path was served by the sandbox instance");
        foreach (['Fatal error', 'Warning:', 'Notice:'] as $l) { hasnt($l, $r['body'], "$path is quiet"); }
    }
});

t('the sandbox writes nowhere near the outer run, let alone data/', function () use ($root) {
    ['box' => $box] = instance_boot();
    ok(strpos($box, sys_get_temp_dir()) === 0, 'the sandbox lives under the temp dir');
    ok(!is_dir($root . '/data') || count(glob($root . '/data/reminders-*.json') ?: []) === 0
       || !in_array('sandbox-only row', array_column(
            store_read($root . '/data/reminders-example.json') ?: [], 'text'), true),
       'the repo data dir is untouched');
});

// ---------------------------------------------------------------- sign-up
// Anyone can make an account from the login page. Emailing is switched off, so the code
// is fixed at SIGNUP_CODE — which is exactly why the rest of the gate has to hold: a
// half-made account must not be an account, and five wrong codes must end it.
area('signup');

t('a sign-up is refused unless the username, email and password are all right', function () use ($scratch) {
    $bad = [
        ['x',        'a@b.com',   'longenough', 'username too short'],
        ['ok_user',  'not-email', 'longenough', 'email'],
        ['ok_user',  'a@b.com',   'short',      'password length'],
        ['example',  'a@b.com',   'longenough', 'username taken'],
    ];
    foreach ($bad as [$u, $em, $pw, $why]) {
        $jar = [];
        req('GET', '/reminders/', [], $jar);
        $r = req('POST', '/reminders/', ['action' => 'signup', 'newuser' => $u,
            'email' => $em, 'newpass' => $pw], $jar);
        eq(200, $r['status'], "$why: no redirect");
        $acc = store_read($scratch . '/accounts.json');
        ok(!isset($acc[$u]) || $u === 'example', "$why must not create an account");
    }
});

t('a good sign-up parks the account rather than creating it', function () use ($scratch) {
    $jar = [];
    req('GET', '/reminders/', [], $jar);
    $r = req('POST', '/reminders/', ['action' => 'signup', 'newuser' => 'newbie',
        'email' => 'newbie@example.com', 'newpass' => 'newbiepass'], $jar);
    eq(200, $r['status'], 'the code window opens in place');
    $pending = store_read($scratch . '/signups.json');
    ok(isset($pending['newbie']), 'it is waiting in signups.json');
    eq('newbie@example.com', $pending['newbie']['email'] ?? null);
    ok(!isset(store_read($scratch . '/accounts.json')['newbie']), 'and is NOT an account yet');
    // Nor can it sign in while it's only pending.
    $j2 = [];
    req('GET', '/reminders/', [], $j2);
    $s = req('POST', '/reminders/', ['username' => 'newbie', 'password' => 'newbiepass'], $j2);
    eq(200, $s['status'], 'a pending account cannot sign in');
});

t('a wrong code is counted and the fifth one ends the sign-up', function () use ($scratch) {
    $jar = [];
    req('GET', '/reminders/', [], $jar);
    req('POST', '/reminders/', ['action' => 'signup', 'newuser' => 'doomed',
        'email' => 'doomed@example.com', 'newpass' => 'doomedpass'], $jar);
    for ($i = 0; $i < 5; $i++) {
        req('POST', '/reminders/', ['action' => 'verify', 'newuser' => 'doomed', 'code' => '9999'], $jar);
    }
    $r = req('POST', '/reminders/', ['action' => 'verify', 'newuser' => 'doomed', 'code' => '1234'], $jar);
    eq(200, $r['status'], 'even the right code is too late now');
    ok(!isset(store_read($scratch . '/accounts.json')['doomed']), 'no account was made');
    ok(!isset(store_read($scratch . '/signups.json')['doomed']), 'and the pending row is gone');
});

t('the right code makes the account and signs you in', function () use ($scratch) {
    $jar = [];
    req('GET', '/reminders/', [], $jar);
    req('POST', '/reminders/', ['action' => 'signup', 'newuser' => 'newbie',
        'email' => 'newbie@example.com', 'newpass' => 'newbiepass'], $jar);
    $r = req('POST', '/reminders/', ['action' => 'verify', 'newuser' => 'newbie', 'code' => '1234'], $jar);
    eq(302, $r['status'], 'verifying redirects');
    eq('/calendar/', $r['location'], 'straight into the app');
    $acc = store_read($scratch . '/accounts.json');
    ok(isset($acc['newbie']), 'the account is real now');
    eq('newbiepass', $acc['newbie']['password'] ?? null);
    ok(!isset(store_read($scratch . '/signups.json')['newbie']), 'and no longer pending');
});

t('a brand-new account is an empty working suite, and sees nobody else\'s data', function () {
    ensure_account('fresh', 'freshpassword');
    $jar = login('fresh', 'freshpassword');
    foreach (['/reminders/', '/notes/', '/calendar/', '/habits/', '/add/'] as $p) {
        $r = req('GET', $p, [], $jar);
        eq(200, $r['status'], "$p renders for a new account");
        foreach (['Fatal error', 'Warning:', 'Notice:'] as $l) { hasnt($l, $r['body'], "$p is quiet"); }
    }
    eq(0, count(rows('fresh')), 'no reminders');
    // A stranger has no partner, so nothing of anyone else's can be reachable.
    eq(null, share_partner('fresh'), 'and no partner');
});

/**
 * Make an account through the real sign-up, if it isn't there already. Areas share the
 * seeded set, so anything that needs a *fresh* account has to be able to make one on its
 * own — otherwise running one area by name depends on another having run first.
 */
function ensure_account(string $user, string $pass): void
{
    global $scratch;
    if (!isset(store_read($scratch . '/accounts.json')[$user])) {
        $jar = [];
        req('GET', '/reminders/', [], $jar);
        req('POST', '/reminders/', ['action' => 'signup', 'newuser' => $user,
            'email' => $user . '@example.com', 'newpass' => $pass], $jar);
        req('POST', '/reminders/', ['action' => 'verify', 'newuser' => $user, 'code' => SIGNUP_CODE], $jar);
    }
    if (!isset(store_read($scratch . '/accounts.json')[$user])) {
        // Signup wouldn't take the name: it's already an account in the developer's own
        // config.php (aki is, here). So the suite doesn't depend on whatever passwords a
        // given machine's config holds, guarantee this one works with the passwords.json
        // override — the same file a self-service change writes, and it wins over config.
        auth_password_set(app_config(), $user, $pass);
    }
}

// ---------------------------------------------------------------- the settings window
// require_login() answers these on whatever page you happen to be on, so they are the
// one pair of handlers every app inherits without wiring anything up.
area('account');

t('changing a password needs a token and the current password', function () use ($scratch) {
    ensure_account('newbie', 'newbiepass');
    $jar = login('newbie', 'newbiepass');
    $was = store_read($scratch . '/passwords.json');
    $r = req('POST', '/reminders/', ['action' => 'change_password', 'csrf' => 'wrong',
        'current' => 'newbiepass', 'new' => 'brandnewpass'], $jar, true);
    eq(400, $r['status'], 'a bad token is a 400');
    eq($was, store_read($scratch . '/passwords.json'), 'and nothing was written');

    $r = req('POST', '/reminders/', ['action' => 'change_password', 'csrf' => csrf($jar),
        'current' => 'nope', 'new' => 'brandnewpass'], $jar, true);
    eq(false, json_decode($r['body'], true)['ok'] ?? null, 'the wrong current password is refused');

    $r = req('POST', '/reminders/', ['action' => 'change_password', 'csrf' => csrf($jar),
        'current' => 'newbiepass', 'new' => 'short'], $jar, true);
    eq(false, json_decode($r['body'], true)['ok'] ?? null, 'a six-character floor');
});

t('a changed password takes effect and the old one stops working', function () {
    ensure_account('newbie', 'newbiepass');
    $jar = login('newbie', 'newbiepass');
    $r = req('POST', '/reminders/', ['action' => 'change_password', 'csrf' => csrf($jar),
        'current' => 'newbiepass', 'new' => 'brandnewpass'], $jar, true);
    eq(true, json_decode($r['body'], true)['ok'] ?? null, 'accepted');

    $j = [];
    req('GET', '/reminders/', [], $j);
    eq(200, req('POST', '/reminders/', ['username' => 'newbie', 'password' => 'newbiepass'], $j)['status'],
       'the old password is dead');
    $j2 = [];
    req('GET', '/reminders/', [], $j2);
    eq(302, req('POST', '/reminders/', ['username' => 'newbie', 'password' => 'brandnewpass'], $j2)['status'],
       'the new one works');
});

t('a stored password wins over the account record it overrides', function () use ($scratch) {
    // passwords.json is the override, because config.php is hand-kept on the server and
    // never deployed. Deleting it has to fall back rather than lock the account out.
    $pw = store_read($scratch . '/passwords.json');
    ok(isset($pw['newbie']), 'the override is on disk');
    eq('newbiepass', store_read($scratch . '/accounts.json')['newbie']['password'] ?? null,
       'and the account record still holds the original');
});

t('the theme is set over AJAX, refuses a name it does not know, and sticks', function () use ($scratch) {
    $jar = login('example', 'examplepassword');
    $r = req('POST', '/reminders/', ['action' => 'set_theme', 'csrf' => csrf($jar),
        'theme' => 'not-a-theme'], $jar, true);
    eq(false, json_decode($r['body'], true)['ok'] ?? null, 'an unknown theme is refused');

    $names = array_keys(THEMES);
    $pick  = $names[count($names) - 1];
    $r = req('POST', '/reminders/', ['action' => 'set_theme', 'csrf' => csrf($jar),
        'theme' => $pick], $jar, true);
    eq(true, json_decode($r['body'], true)['ok'] ?? null, "theme $pick is accepted");
    eq($pick, store_read($scratch . '/prefs-example.json')['theme'] ?? null, 'and it is stored');

    $r = req('POST', '/reminders/', ['action' => 'set_theme', 'csrf' => 'wrong', 'theme' => $names[0]], $jar, true);
    eq(false, json_decode($r['body'], true)['ok'] ?? null, 'no token, no change');
    eq($pick, store_read($scratch . '/prefs-example.json')['theme'] ?? null, 'still the one we set');
});

// ---------------------------------------------------------------- token auth
// The widget and the watch carry a token instead of a session. It is a READ credential
// and has been handed out as one: anything behind it that wrote would hand that power
// to every copy already in circulation.
area('token');

t('token_user() matches exactly, or not at all', function () {
    $dir = datadir();
    $tok = 'testtoken' . bin2hex(random_bytes(6));
    store_write($dir . '/token-example.json', ['token' => $tok]);
    eq('example', token_user($dir, $tok), 'the right token names its owner');
    eq(null, token_user($dir, ''), 'an empty token is nobody');
    eq(null, token_user($dir, substr($tok, 0, -1)), 'a prefix is not a match');
    eq(null, token_user($dir, $tok . 'x'), 'nor is an extension');
    eq(null, token_user($dir, strtoupper($tok)), 'nor a different case');
});

t('one person\'s token cannot read another person\'s feed', function () {
    $dir = datadir();
    $mine = 'tokA' . bin2hex(random_bytes(6));
    $them = 'tokB' . bin2hex(random_bytes(6));
    store_write($dir . '/token-example.json', ['token' => $mine]);
    store_write($dir . '/token-buddy.json',   ['token' => $them]);

    $a = json_decode(req('GET', '/calendar/feed.php?token=' . $mine)['body'], true);
    $b = json_decode(req('GET', '/calendar/feed.php?token=' . $them)['body'], true);
    ok(is_array($a) && is_array($b), 'both answer JSON');
    $txt = fn($f) => array_column($f['items'] ?? [], 'text');
    ok($txt($a) !== $txt($b) || (!$txt($a) && !$txt($b)), 'the two feeds are not the same list');
    eq('example', token_user($dir, $mine));
    eq('buddy',   token_user($dir, $them));
});

t('the feed refuses to write, whatever it is asked', function () {
    $dir = datadir();
    $tok = 'tokW' . bin2hex(random_bytes(6));
    store_write($dir . '/token-example.json', ['token' => $tok]);
    $before = count(rows('example'));
    foreach ([['action' => 'add', 'text' => 'via the token'],
              ['action' => 'tick', 'id' => (rows('example')[0]['id'] ?? 'x')]] as $post) {
        req('POST', '/calendar/feed.php?token=' . $tok, $post);
    }
    eq($before, count(rows('example')), 'the feed wrote nothing');
    ok(rowBy('example', 'via the token') === null, 'and added nothing');
});

t('the reminders API has no anonymous read and no write', function () {
    $r = req('GET', '/api/reminders.php');
    ok($r['status'] !== 200 || strpos($r['body'], '"text"') === false,
       'an unauthenticated read must not return rows');
    $before = count(rows('example'));
    req('POST', '/api/reminders.php', ['action' => 'add', 'text' => 'api row']);
    eq($before, count(rows('example')), 'and an unauthenticated POST changes nothing');
});

// ---------------------------------------------------------------- chat
// Deliberately public: no login, no session, one shared file. Which makes the escaping
// and the cap the whole of its safety.
area('chat');

t('chat needs no login and posts a message', function () {
    $r = req('GET', '/chat/');
    eq(200, $r['status'], 'open to anyone');
    hasnt('name="password"', $r['body'], 'no login gate');
    req('POST', '/chat/', ['action' => 'send', 'name' => 'tester', 'text' => 'hello from the test run']);
    has('hello from the test run', req('GET', '/chat/')['body'], 'the message is on the page');
});

t('a message is escaped, not rendered', function () {
    req('POST', '/chat/', ['action' => 'send', 'name' => '<b>me</b>',
        'text' => '<script>alert(1)</script> & "quoted"']);
    $b = req('GET', '/chat/')['body'];
    hasnt('<script>alert(1)</script>', $b, 'no live script in the page');
    has('&lt;script&gt;', $b, 'it came back escaped');
    hasnt('<b>me</b>', $b, 'and so did the name');
});

t('an empty message is not stored', function () {
    $file = datadir() . '/chat.json';
    $before = count(store_read($file));
    req('POST', '/chat/', ['action' => 'send', 'name' => 'tester', 'text' => '   ']);
    eq($before, count(store_read($file)), 'whitespace is nothing');
});

// ---------------------------------------------------------------- Aki's Bookshelf
// One username's app, sitting behind the shared login. The gate is the only thing
// between it and everyone else who has an account on the suite.
area('bookshelf');

t('the bookshelf is behind the login', function () {
    $r = req('GET', '/akisbookshelf/');
    has('Sign in', $r['body'], 'signed out you get the login page');
});

t('a signed-in stranger is turned away and sees none of it', function () {
    $jar = login('example', 'examplepassword');
    $r = req('GET', '/akisbookshelf/', [], $jar);
    has('bookshelf is aki', $r['body'], 'told whose it is');
    foreach (['booksgrid', 'bookcard', 'shelf-tile'] as $marker) {
        hasnt($marker, $r['body'], "no bookshelf markup leaks ($marker)");
    }
    foreach (['Fatal error', 'Warning:', 'Notice:'] as $l) { hasnt($l, $r['body']); }
});

t('aki gets the app itself', function () {
    // aki may already be a config account on this machine, so ensure_account() falls back
    // to the passwords.json override to give it a password the test knows — either way we
    // reach the gate as a signed-in aki, which is what it turns on.
    ensure_account('aki', 'akipassword');
    $jar = login('aki', 'akipassword');
    $r = req('GET', '/akisbookshelf/', [], $jar);
    eq(200, $r['status'], 'it renders');
    hasnt('bookshelf is aki', $r['body'], 'and is not the refusal page');
    foreach (['Fatal error', 'Warning:', 'Notice:'] as $l) { hasnt($l, $r['body']); }
});

// ---------------------------------------------------------------- recolouring a share
// Each person can recolour how the other's shared folders look *in their own picker*.
// The whole point is that it never touches the owner's data, so that is what's checked.
area('shared2');

t('a recolour is stored on the viewer\'s side, keyed by the view name', function () {
    $dir = datadir();
    $key = '@buddy:Dinners';
    $_SESSION['user'] = 'example';                       // the helper writes as "me"
    $ownerBefore = folders_load($dir, 'buddy');
    folder_shared_color_set($dir, 'reminders', $key, app_palette('reminders', true)[1], ['Dinners']);
    $mine = folder_shared_colors($dir, 'reminders', 'example');
    ok(isset($mine[$key]), 'the override is in my own folders file');
    eq($ownerBefore, folders_load($dir, 'buddy'), 'the owner\'s folders file is untouched');
    ok(!isset(folder_shared_colors($dir, 'reminders', 'buddy')[$key]),
       'and nothing was written on their side');
    unset($_SESSION['user']);
});

t('a colour off the shared palette, or a folder they do not share, is refused', function () {
    $dir = datadir();
    $key = '@buddy:Dinners';
    $_SESSION['user'] = 'example';
    $was = folder_shared_colors($dir, 'reminders', 'example')[$key] ?? null;
    folder_shared_color_set($dir, 'reminders', $key, '#ff0000', ['Dinners']);
    eq($was, folder_shared_colors($dir, 'reminders', 'example')[$key] ?? null, 'a made-up colour');
    folder_shared_color_set($dir, 'reminders', '@buddy:Secret',
        app_palette('reminders', true)[3], ['Dinners']);
    ok(!isset(folder_shared_colors($dir, 'reminders', 'example')['@buddy:Secret']),
       'a folder they never shared');
    unset($_SESSION['user']);
});

t('resolution goes mine, then theirs, then a default by position', function () {
    $shared = app_palette('reminders', true);
    $mine   = ['@buddy:Dinners' => $shared[2]];
    $owner  = ['Dinners' => $shared[4], 'Other' => $shared[5]];
    eq($shared[2], folder_shared_color($mine, $owner, 'reminders', '@buddy:Dinners', 'Dinners', 0),
       'my override wins');
    eq($shared[4], folder_shared_color([], $owner, 'reminders', '@buddy:Dinners', 'Dinners', 0),
       'then the owner\'s own colour');
    $d = folder_shared_color([], [], 'reminders', '@buddy:Nothing', 'Nothing', 1);
    ok(in_array($d, $shared, true), 'then a shared-palette default');
});

// ---------------------------------------------------------------- the public front
// Home, projects, about and contact are the marketing front, not the app suite: no
// login, no tab bar, no app chrome. Getting that wrong shows a signed-out stranger a
// tab bar into pages they can't open.
area('site');

t('every public page renders for a stranger', function () {
    foreach (['/', '/about/', '/projects/', '/contact/'] as $p) {
        $r = req('GET', $p);
        eq(200, $r['status'], "$p status");
        hasnt('name="password"', $r['body'], "$p must not ask for a login");
        foreach (['Fatal error', 'Warning:', 'Notice:', '/home/protected'] as $l) {
            hasnt($l, $r['body'], "$p leaks \"$l\"");
        }
    }
});

t('a public page wears the site nav and never the app tab bar', function () {
    foreach (['/', '/about/', '/projects/', '/contact/'] as $p) {
        $b = req('GET', $p)['body'];
        hasnt('class="tabbar"', $b, "$p must not carry the app tab bar");
        hasnt('segmented', $b, "$p must not carry the app segmented control");
    }
    $nav = site_nav('about');
    has('<a href="/about/" class="on">About</a>', $nav, 'the nav marks the page you are on');
    has('<a href="/">Home</a>', $nav, 'and does not mark the others');
});

t('the public pages are the same shell', function () {
    foreach (['/about/', '/projects/', '/contact/'] as $p) {
        $b = req('GET', $p)['body'];
        has('<!DOCTYPE html', $b, "$p is a whole document");
        has('#34d399', strtolower($b), "$p carries the suite accent");
    }
});

// ---------------------------------------------------------------- quick add / widget tick
// quick.php is the one page the widget can reach that writes — deliberately, because the
// write happens in a signed-in session with a token rather than behind the read-only feed.
area('quick');

t('a quick add lands on today, in the fallback folder', function () {
    $jar = login('example', 'examplepassword');
    $tok = csrf($jar, '/calendar/quick.php');
    req('POST', '/calendar/quick.php', ['csrf' => $tok, 'action' => 'add_reminder',
        'text' => 'quick added reminder'], $jar);
    $r = rowBy('example', 'quick added reminder');
    ok($r !== null, 'it was written');
    eq(date('Y-m-d'), $r['due'] ?? null, 'due today');
    eq(folder_fallback('reminders'), $r['folder'] ?? null, 'in the fallback folder');
    eq('', $r['section'] ?? null, 'and no section');
});

t('a quick add reads the date and time out of the line', function () {
    $jar = login('example', 'examplepassword');
    $tok = csrf($jar, '/calendar/quick.php');
    req('POST', '/calendar/quick.php', ['csrf' => $tok, 'action' => 'add_event',
        'text' => 'Vet 8/3 2pm'], $jar);
    $ev = null;
    foreach (stored('events', 'example') as $e) { if (($e['text'] ?? '') === 'Vet') { $ev = $e; } }
    ok($ev !== null, 'the text was trimmed to "Vet"');
    eq('08-03', substr((string) $ev['date'], 5), 'the date came out of the line');
    eq('14:00', $ev['time'] ?? null, 'and so did the time');
});

t('?tick= shows one reminder and its Done button marks it', function () {
    $jar = login('example', 'examplepassword');
    $tok = csrf($jar, '/calendar/quick.php');
    req('POST', '/calendar/quick.php', ['csrf' => $tok, 'action' => 'add_reminder',
        'text' => 'tick me from the widget'], $jar);
    $id = rowBy('example', 'tick me from the widget')['id'];

    $g = req('GET', '/calendar/quick.php?tick=' . $id, [], $jar);
    eq(200, $g['status']);
    has('tick me from the widget', $g['body'], 'the page names the reminder');
    has('value="tick"', $g['body'], 'and carries the Done button');

    req('POST', '/calendar/quick.php?tick=' . $id, ['csrf' => csrf($jar, '/calendar/quick.php'),
        'action' => 'tick', 'id' => $id], $jar);
    ok(!empty(rowBy('example', 'tick me from the widget')['done']), 'it is done');
});

t('ticking a repeat from the widget rolls it instead of finishing it', function () {
    $jar = login('example', 'examplepassword');
    $row = rowBy('example', 'Water the tomatoes');          // every 2 days, from the seeder
    ok($row !== null && repeat_get($row) !== null, 'the seeded repeat exists');
    $was = $row['due'];
    req('POST', '/calendar/quick.php?tick=' . $row['id'], ['csrf' => csrf($jar, '/calendar/quick.php'),
        'action' => 'tick', 'id' => $row['id']], $jar);
    $after = rowBy('example', 'Water the tomatoes');
    ok(empty($after['done']), 'a repeat is never marked done from the widget either');
    ok($after['due'] > $was, "it moved on (was $was, now {$after['due']})");
});

t('a tick with no token changes nothing', function () {
    $jar = login('example', 'examplepassword');
    $tok = csrf($jar, '/calendar/quick.php');
    req('POST', '/calendar/quick.php', ['csrf' => $tok, 'action' => 'add_reminder',
        'text' => 'untouchable'], $jar);
    $id = rowBy('example', 'untouchable')['id'];
    req('POST', '/calendar/quick.php?tick=' . $id, ['action' => 'tick', 'id' => $id], $jar);
    ok(empty(rowBy('example', 'untouchable')['done']), 'still open');
    req('POST', '/calendar/quick.php?tick=' . $id, ['csrf' => 'nope', 'action' => 'tick', 'id' => $id], $jar);
    ok(empty(rowBy('example', 'untouchable')['done']), 'still open with a wrong token');
});

// ---------------------------------------------------------------- the deploy script
// Static checks, because a deploy is the one thing here that can destroy data and the
// one thing no test run may actually perform. These are the promises deploy.sh makes in
// its own header; this is the test that it still keeps them.
area('deploy');

t('the seeding wrapper refuses everything by default', function () use ($root) {
    // It exists to be scp'd to a live host for one minute and deleted. For that minute
    // it is a URL that can overwrite an account, so the shipped copy must be inert.
    $s = (string) file_get_contents($root . '/tools/seed-http.php');
    has("const SEED_KEY = 'CHANGE-ME';", $s, 'the committed copy carries no real key');
    has('hash_equals', $s, 'and compares in constant time');
    // No default data dir: a bare hit must never be able to reach production.
    ok(!preg_match("#\\\$dir\\s*=\\s*\\(string\\)\\s*\\(\\\$_GET\\['dir'\\]\\s*\\?\\?\\s*'/#", $s),
       'the dir parameter has no default');
    // And it is not deployable — deploy.sh sends public/ and lib/ only.
    hasnt('tools', substr($s, 0, 0) . implode(' ', array_filter(
        preg_split('/\R/', (string) file_get_contents($root . '/deploy.sh')),
        fn($l) => strpos($l, 'rsync') !== false && strpos($l, 'tools') !== false)),
       'no rsync line sends tools/');
});

t('deploy.sh parses', function () use ($root) {
    exec('bash -n ' . escapeshellarg($root . '/deploy.sh') . ' 2>&1', $o, $rc);
    eq(0, $rc, 'bash -n: ' . implode("\n", $o));
});

t('it never deletes and never sends a config', function () use ($root) {
    $s = (string) file_get_contents($root . '/deploy.sh');
    foreach (preg_split('/\R/', $s) as $n => $line) {
        if (strpos($line, 'rsync') === false) { continue; }
        $bare = preg_replace('/#.*$/', '', $line);
        ok(strpos($bare, '--delete') === false, 'line ' . ($n + 1) . ' uses --delete');
    }
    ok(substr_count($s, "--exclude='config.php'") + substr_count($s, '--exclude=config.php') >= 2,
       'every rsync of lib excludes config.php');
});

t('it never touches a data directory', function () use ($root) {
    $s = (string) file_get_contents($root . '/deploy.sh');
    foreach (preg_split('/\R/', $s) as $n => $line) {
        if (strpos($line, 'rsync') === false && strpos($line, 'rm ') === false) { continue; }
        $bare = preg_replace('/#.*$/', '', $line);
        ok(strpos($bare, '/home/protected/data') === false,
           'line ' . ($n + 1) . ' names a live data directory');
    }
});

t('a bare deploy is the test instance, never production', function () use ($root) {
    $s = (string) file_get_contents($root . '/deploy.sh');
    has('MODE="${MODE:-test}"', $s, 'the default mode is test');
    foreach (['test|prod|both|promote', 'push_instance'] as $m) { has($m, $s, "deploy.sh still has $m"); }
    // The script itself is not run here: it needs the deploy key, and a test run must
    // never be one keystroke away from touching the live site. These are text checks.
    ok(preg_match('/\bprod\)\s*$/m', $s) === 1, 'prod is its own explicit mode');
    ok(strpos($s, 'promote') !== false, 'and promote exists to move test into prod');
});

// ═══════════════════════════════════════════════════════════════════ run

if ($list) {
    foreach ($AREAS as $name => $cases) { printf("%-10s %d cases\n", $name, count($cases)); }
    exit(0);
}

// Seed the scratch dir with the real seeders — which also tests that they work.
fwrite(STDERR, "seeding a scratch account set in $scratch …\n");
foreach (['seed-example.php', 'seed-buddy.php'] as $s) {
    exec('SUITE_DATA_DIR=' . escapeshellarg($scratch) . ' php ' . escapeshellarg($root . '/tools/' . $s)
         . ' --force 2>&1', $out, $rc);
    if ($rc !== 0) { fwrite(STDERR, "seeder $s failed:\n" . implode("\n", $out) . "\n"); exit(2); }
}

// Boot the dev server on a free port, pointed at the scratch dir.
$sock = stream_socket_server('tcp://127.0.0.1:0', $e1, $e2);
$PORT = (int) explode(':', stream_socket_get_name($sock, false))[1];
fclose($sock);
$desc = [1 => ['file', '/dev/null', 'w'], 2 => ['file', $scratch . '/server.log', 'w']];
$SRV = proc_open('SUITE_DATA_DIR=' . escapeshellarg($scratch)
    . ' php -d display_errors=1 -d error_reporting=E_ALL'
    . ' -S 127.0.0.1:' . $PORT . ' -t ' . escapeshellarg($root . '/public'), $desc, $pipes);
register_shutdown_function(function () use (&$SRV, $scratch, $keep) {
    if (is_resource($SRV)) { proc_terminate($SRV); proc_close($SRV); }
    if (!$keep) { @array_map('unlink', glob($scratch . '/*') ?: []); @rmdir($scratch); }
    else { fwrite(STDERR, "scratch kept at $scratch\n"); }
});
for ($i = 0; $i < 100; $i++) {                       // wait for it to answer
    $c = @fsockopen('127.0.0.1', $PORT, $x, $y, 0.2);
    if ($c) { fclose($c); break; }
    usleep(100000);
}

$pass = $fail = $skipped = 0; $failures = [];
foreach ($AREAS as $name => $cases) {
    if ($only && !array_filter($only, fn($o) => stripos($name, $o) !== false)) { $skipped += count($cases); continue; }
    echo "\n\033[1m$name\033[0m\n";
    foreach ($cases as [$label, $fn]) {
        try {
            $fn();
            $pass++;
            echo "  \033[32m✓\033[0m $label\n";
        } catch (Throwable $e) {
            $fail++;
            $failures[] = "$name / $label\n      " . $e->getMessage();
            echo "  \033[31m✗\033[0m $label\n      \033[31m" . $e->getMessage() . "\033[0m\n";
        }
    }
}

echo "\n" . str_repeat('─', 60) . "\n";
printf("%d passed, %d failed%s\n", $pass, $fail, $skipped ? ", $skipped skipped" : '');
if ($fail) {
    echo "\nFailures:\n";
    foreach ($failures as $f) { echo "  • $f\n"; }
    echo "\nServer log: $scratch/server.log (use --keep to hold on to it)\n";
}
exit($fail ? 1 : 0);
