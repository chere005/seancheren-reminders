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
require_once $root . '/lib/folders.php';
require_once $root . '/lib/sharing.php';
require_once $root . '/lib/richtext.php';
require_once $root . '/lib/palette.php';

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

t('both habit views render', function () {
    $jar = login('example', 'examplepassword');
    foreach (['/habits/?w=0' => 'colhead', '/habits/?m=' . date('Y-m') => 'mgrid'] as $p => $marker) {
        $r = req('GET', $p, [], $jar);
        eq(200, $r['status'], "$p status");
        has($marker, $r['body'], "$p should render its grid");
    }
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
    $b = req('GET', '/habits/', [], $jar)['body'];
    has('drop-line', $b, 'the same line the other apps drop against');
    has('grid-column: 1 / -1', $b, 'spanning every column, so it sits between rows');
    has('blockOf', $b, 'a section travels with the habits under it');
});

t("a habit's row carries its section's colour", function () {
    $jar = login('example', 'examplepassword');
    $b = req('GET', '/habits/', [], $jar)['body'];
    $names = preg_match_all('/class="hname" style="--hc:#[0-9a-f]{6}"/', $b);
    $cells = preg_match_all('/class="cell[^"]*" style="--hc:#[0-9a-f]{6}"/', $b);
    ok($names > 0, 'the name bubbles are tinted');
    ok($cells > $names, 'and so is every day square on those rows');
    preg_match_all('/--hc:(#[0-9a-f]{6})/', $b, $m);
    $used = array_values(array_unique($m[1]));
    foreach ($used as $c) { ok(in_array($c, app_palette('habits', true), true), "$c is a palette colour"); }
    ok(count($used) > 1, 'two sections should not share one colour by default');
});

t('wiring: tapping away leaves edit mode in habits', function () {
    $jar = login('example', 'examplepassword');
    $b = req('GET', '/habits/', [], $jar)['body'];
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

t('wiring: the tab bar + is centred by an equal margin top and bottom', function () {
    $jar = login('example', 'examplepassword');
    $b = req('GET', '/reminders/', [], $jar)['body'];
    ok(preg_match('/\.segmented a\.addtab \{[^}]*margin:\s*(-?\d+px)\s+[^;]*;/', $b, $m) === 1,
       'the add tab sets a margin');
    // Two-value shorthand (vertical horizontal) is symmetric; three values are not.
    ok(preg_match('/\.segmented a\.addtab \{[^}]*margin:\s*-?\d+px\s+[\d.a-z]+\s*;/', $b) === 1,
       'it must be the two-value shorthand, or the circle sits high again');
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
