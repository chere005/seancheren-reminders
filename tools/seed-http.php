<?php
/**
 * The throwaway wrapper that seeds a live instance **as the web user**.
 *
 * /home/protected/data/ (and data-test/) are owned by `web` with mode drwx------, so a
 * seeder run over SSH as the login user hits Permission denied on every write — and the
 * seeders print "Seeded…" anyway, so the failure is silent. The only way to seed on the
 * live host is to run as `web`, which means over HTTP.
 *
 * It is not deployed (deploy.sh sends only public/ and lib/) and it is **not meant to
 * live on the server**. The procedure is: scp this plus the two seeders into
 * /home/public/, curl it once per account, then delete all three. See the block comment
 * in CLAUDE.md, and tools/seed-example.php's header.
 *
 * Guarded three ways, because for the minute it exists it is a URL that can overwrite an
 * account: a secret that has to be substituted in below before it goes anywhere near the
 * server, a compare that is constant-time, and a data dir that has to be named
 * explicitly — there is no default, so a bare hit can never touch production by accident.
 *
 *   ?k=<secret>&dir=/home/protected/data-test&s=example
 *   ?k=<secret>&dir=/home/protected/data-test&s=buddy
 *
 * One account per request on purpose: the two seeders both define USER and PASS, so
 * requiring them into one process is a constant-redefinition fatal.
 */

// Replace this before the file leaves your machine. The deploy note generates one.
const SEED_KEY = 'CHANGE-ME';

if (SEED_KEY === 'CHANGE-ME' || !hash_equals(SEED_KEY, (string) ($_GET['k'] ?? ''))) {
    http_response_code(404);
    exit("Not found.\n");
}

// No default: naming the dir is how you say which instance you meant, and getting it
// wrong has to be a typo you can see rather than a silent fall-through to production.
$dir = (string) ($_GET['dir'] ?? '');
if (!preg_match('#^/home/protected/data(-[a-z0-9]+)?$#', $dir)) {
    http_response_code(400);
    exit("Name the data dir explicitly, e.g. &dir=/home/protected/data-test\n");
}

$which = ($_GET['s'] ?? '') === 'buddy' ? 'seed-buddy.php' : 'seed-example.php';
if (!is_file(__DIR__ . '/' . $which)) {
    http_response_code(500);
    exit("$which isn't beside me — scp the seeders across too.\n");
}

// app_config() reads this, and nothing else in the suite sets it, so it is the one lever
// that decides which instance gets seeded.
putenv('SUITE_DATA_DIR=' . $dir);
$argv = ['seed', '--force'];
$argc = 2;

header('Content-Type: text/plain; charset=utf-8');
echo "seeding $which into $dir …\n";
// Success shows as the *absence* of Permission-denied warnings — the seeders report
// "Seeded…" whether or not the writes landed.
require __DIR__ . '/' . $which;
