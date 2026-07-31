<?php
/**
 * Create (or reset) the "buddy" demo account, paired with "example".
 *
 *   php tools/seed-buddy.php            # local ./data
 *   php tools/seed-buddy.php --force    # overwrite a buddy account that exists
 *
 * Where seed-example.php builds one person's whole suite, this one builds the *other*
 * half of a pair, so the sharing half of the app has something to demonstrate: buddy and
 * example are cooking dinner together over the next two weekends, and each can see the
 * parts of that the other has shared.
 *
 * Buddy gets a grocery checklist, a dinner-prep checklist (with a couple of subtasks),
 * the recipe as a Notes entry dated to the dinner it belongs to, and the two dinners on
 * the calendar. Example gets the same two dinners from their side ("Dinner with buddy")
 * and the share files that make each of them visible to the other — the only two things
 * this script writes outside buddy's own files, and both are re-written from scratch on
 * every run, so re-seeding never doubles anything up.
 *
 * Everything is dated relative to today, so it never goes stale.
 *
 * Like seed-example.php this must run as the WEB user on the live site, not over SSH:
 * /home/protected/data/ is owned by web (drwx------), so a CLI run as the SSH login user
 * gets Permission denied on every write and says "Seeded…" anyway. Seed over HTTP —
 * scp this file plus a throwaway wrapper
 *   (<?php $argv=['x','--force']; require __DIR__.'/seed-buddy.php';  guarded by a secret)
 * into /home/public/, curl the wrapper once, then delete both.
 */

$libDir = null;
foreach ([__DIR__ . '/../lib', '/home/protected/lib'] as $c) {
    if (is_file($c . '/auth.php')) { $libDir = $c; break; }
}
if ($libDir === null) { fwrite(STDERR, "Can't find lib/.\n"); exit(1); }
require_once $libDir . '/auth.php';
require_once $libDir . '/folders.php';
require_once $libDir . '/sharing.php';

const USER    = 'buddy';
const PASS    = 'buddypassword';
const PARTNER = 'example';

$cfg   = app_config();
$dir   = rtrim($cfg['data_dir'], '/');
$force = in_array('--force', $argv, true);
if (!is_dir($dir)) { mkdir($dir, 0700, true); }

$accounts = accounts_load($cfg);
if (isset($accounts[USER]) && !$force) {
    echo "The \"" . USER . "\" account already exists. Re-run with --force to reset it.\n";
    exit(0);
}

$id    = fn() => bin2hex(random_bytes(6));
$file  = fn(string $base) => user_data_file($dir, $base, USER);
$d     = fn(int $days) => date('Y-m-d', strtotime("$days days"));

// The next two Saturdays. strtotime('next saturday') is the coming one (or the one after,
// if today is a Saturday), which is what "the next couple of weekends" means either way.
$sat1 = date('Y-m-d', strtotime('next saturday'));
$sat2 = date('Y-m-d', strtotime($sat1 . ' +7 days'));
$shopDay = date('Y-m-d', strtotime($sat1 . ' -1 day'));   // the Friday before — never in the past

// ---------------------------------------------------------------- account
$accounts[USER] = ['email' => 'buddy@seancheren.com', 'password' => PASS, 'created' => time()];
accounts_save($cfg, $accounts);
// A password someone set by hand would otherwise win over the one above.
$pw = store_read($dir . '/passwords.json');
unset($pw[USER]);
store_write($dir . '/passwords.json', $pw);

// ---------------------------------------------------------------- folders
$remPal  = app_palette('reminders');
$notePal = app_palette('notes');
store_write($file('folders'), [
    'reminders' => [FOLDER_REMINDERS, FOLDER_CALENDAR, 'Dinners', 'House'],
    'notes'     => [FOLDER_DEFAULT, 'Recipes'],
    'default'   => ['reminders' => FOLDER_REMINDERS, 'notes' => FOLDER_DEFAULT],
    'last'      => ['reminders' => 'All', 'notes' => 'All'],
    'hidden'    => ['reminders' => [], 'notes' => []],
    'colors'    => [
        'reminders' => [FOLDER_REMINDERS => $remPal[0], FOLDER_CALENDAR => $remPal[1],
                        'Dinners' => $remPal[3], 'House' => $remPal[2]],
        'notes'     => [FOLDER_DEFAULT => $notePal[0], 'Recipes' => $notePal[3]],
    ],
]);

// ---------------------------------------------------------------- calendars
$calPal = app_palette('calendar');
$cals = [
    ['id' => $id(), 'name' => 'Home',    'color' => $calPal[0], 'created' => time()],
    ['id' => $id(), 'name' => 'Cooking', 'color' => $calPal[3], 'created' => time()],
];
store_write($file('calendars'), $cals);
[$calHome, $calCooking] = array_column($cals, 'id');
store_write($file('calprefs'), [
    'hidden_folders' => [], 'hidden_shared_folders' => [],
    'default_cal' => $calHome, 'last_cal' => 'all', 'hidden_cals' => [],
]);

// ---------------------------------------------------------------- reminders
// Two checklists in the shared "Dinners" folder: what to buy, and what to do on the day.
// The prep list is an outline — the long oven stretch carries its two check-ins as
// subtasks, which is what the subtask "+" on a row is for.
$secShop = $id();
$secPrep = $id();
$rem = [
    ['id' => $secShop, 'type' => 'section', 'name' => 'Groceries',  'folder' => 'Dinners', 'created' => time()],
    ['id' => $secPrep, 'type' => 'section', 'name' => 'Dinner prep', 'folder' => 'Dinners', 'created' => time()],
];
$r = function (string $text, ?string $due, string $folder, string $section = '',
               array $extra = []) use ($id): array {
    return array_merge([
        'id' => $id(), 'text' => $text, 'due' => $due, 'time' => null, 'done' => false,
        'folder' => $folder, 'section' => $section, 'indent' => 0, 'repeat' => null,
        'created' => time(),
    ], $extra);
};

// --- Groceries for the ragù, all due the day before ---
foreach ([
    '1kg beef shin, cut into chunks',
    'Pancetta — a thick piece, not the cubes',
    'Two onions, two carrots, two sticks of celery',
    'Tin of San Marzano tomatoes',
    'Tomato purée',
    'Bottle of Chianti (one for the pan, one for the table)',
    'Pappardelle',
    'Parmesan — a wedge',
    'Flat parsley',
    'Bread and good butter',
    'Lemons for the tart',
] as $item) {
    $rem[] = $r($item, $shopDay, 'Dinners', 'Groceries');
}
// A couple already in the trolley, so the list doesn't read as untouched.
$rem[] = $r('Bay leaves', $shopDay, 'Dinners', 'Groceries', ['done' => true]);
$rem[] = $r('Olive oil',  $shopDay, 'Dinners', 'Groceries', ['done' => true]);

// --- The steps, on the day, in the order they happen ---
$rem[] = $r('Beef out of the fridge, salted',       $sat1, 'Dinners', 'Dinner prep', ['time' => '12:30']);
$rem[] = $r('Brown the beef in batches',            $sat1, 'Dinners', 'Dinner prep', ['time' => '13:00']);
$rem[] = $r('Soffritto in the same pan, 15 minutes', $sat1, 'Dinners', 'Dinner prep', ['time' => '13:30']);
$rem[] = $r('Deglaze with the red, then tomatoes',  $sat1, 'Dinners', 'Dinner prep', ['time' => '14:00']);
$rem[] = $r('Into the oven at 160°C',               $sat1, 'Dinners', 'Dinner prep', ['time' => '14:15']);
$rem[] = $r('Check the liquid at two hours',        $sat1, 'Dinners', 'Dinner prep', ['time' => '16:15', 'indent' => 1]);
$rem[] = $r('Skim the fat, shred the meat',         $sat1, 'Dinners', 'Dinner prep', ['time' => '17:30', 'indent' => 1]);
$rem[] = $r('Lemon tart in while the oven is hot',  $sat1, 'Dinners', 'Dinner prep', ['time' => '17:45']);
$rem[] = $r('Lay the table',                        $sat1, 'Dinners', 'Dinner prep', ['time' => '18:15']);
$rem[] = $r('Pasta water on',                       $sat1, 'Dinners', 'Dinner prep', ['time' => '18:40']);
$rem[] = $r('Dress the salad last',                 $sat1, 'Dinners', 'Dinner prep', ['time' => '18:55']);

// --- The second weekend, still only an idea ---
$rem[] = $r('Ask example what they want next Saturday', null, 'Dinners');
$rem[] = $r('Borrow the big roasting tin back',        null, 'Dinners');

// --- A little life outside the kitchen, so the other folders aren't empty ---
$rem[] = $r('Bins out',              $d(1), 'House', '', ['repeat' => ['n' => 1, 'unit' => 'week']]);
$rem[] = $r('Change the bed',        $d(-2), 'House');
$rem[] = $r('Ring the landlord about the boiler', $d(-5), 'House');
$rem[] = $r('Water the herbs',       null,  FOLDER_CALENDAR);
$rem[] = $r('Book the dentist',      null,  FOLDER_REMINDERS);
store_write($file('reminders'), $rem);

// ---------------------------------------------------------------- events
$ev = function (string $text, string $date, ?string $time, string $cal,
                ?array $repeat = null) use ($id): array {
    return ['id' => $id(), 'text' => $text, 'date' => $date, 'time' => $time,
            'cal' => $cal, 'repeat' => $repeat, 'created' => time()];
};
store_write($file('events'), [
    $ev('Dinner with example', $sat1, '19:00', $calCooking),
    $ev('Dinner with example', $sat2, '19:00', $calCooking),
    $ev('Shopping for Saturday', $shopDay, '17:30', $calCooking),
    $ev('Five-a-side',        $d(2),  '19:30', $calHome, ['n' => 1, 'unit' => 'week']),
    $ev('Boiler service',     $d(6),  '09:00', $calHome),
]);

// ---------------------------------------------------------------- notes
// The recipe itself, dated to the dinner it's for, so it turns up on that day.
$nSecDinners = $id();
$n = function (string $title, string $body, string $folder, string $section = '',
               ?string $date = null) use ($id): array {
    return ['id' => $id(), 'title' => $title, 'body' => $body, 'folder' => $folder,
            'section' => $section, 'date' => $date, 'time' => null,
            'created' => time(), 'updated' => time()];
};
store_write($file('notes'), [
    ['id' => $nSecDinners, 'type' => 'section', 'name' => 'Dinners', 'folder' => 'Recipes', 'created' => time()],
    $n('Beef ragù with pappardelle — Saturday',
       '<p><strong>For six, and better the next day.</strong></p>'
       . '<ul>'
       . '<li>1kg beef shin, in big chunks, salted an hour ahead</li>'
       . '<li>150g pancetta, diced small</li>'
       . '<li>Two onions, two carrots, two sticks of celery, all finely chopped</li>'
       . '<li>A tin of San Marzano tomatoes and a spoon of purée</li>'
       . '<li>A large glass of Chianti</li>'
       . '<li>Two bay leaves, parmesan rind if there is one</li>'
       . '</ul>'
       . '<ol>'
       . '<li>Brown the beef hard, in batches — crowd the pan and it steams instead.</li>'
       . '<li>Pancetta into the same pan, then the soffritto, low and slow for fifteen '
       . 'minutes until it goes sweet.</li>'
       . '<li>Purée in for a minute, then the wine; let it cook off properly.</li>'
       . '<li>Tomatoes, bay, the rind, the beef and its juices back in. Barely cover.</li>'
       . '<li>Lid on, 160°C, three and a half hours. Check the liquid at two.</li>'
       . '<li>Shred the meat into the sauce, skim the fat, season again — it will need it.</li>'
       . '<li>Pappardelle, and finish it in the pan with a ladle of the pasta water.</li>'
       . '</ol>'
       . '<p><em>Salad dressed at the last minute. Lemon tart goes in while the oven is '
       . 'still hot from the ragù.</em></p>',
       'Recipes', 'Dinners', $sat1),
    $n('Lemon tart — the short version',
       '<p>Blind bake the shell properly or it weeps. Six eggs, 250g sugar, the juice of '
       . 'four lemons and their zest, 200ml cream. 140°C until it barely wobbles.</p>',
       'Recipes', 'Dinners', $sat1),
    $n('What example does and doesn’t eat',
       '<ul><li>No blue cheese</li><li>Coriander is fine, in moderation</li>'
       . '<li>Will eat anything with an anchovy in it</li></ul>',
       FOLDER_DEFAULT),
]);

// ---------------------------------------------------------------- habits
// A short, honest few weeks — enough for the week grid and the month pies to have shape.
$hSecKitchen = $id();
$hSecRest    = $id();
$habitPal = app_palette('habits', true);   // habits borrows the lighter reminders tier
$habitSpec = [
    ['Cook something new', $hSecKitchen, 0.35],
    ['Wash up before bed', $hSecKitchen, 0.70],
    ['Walk after dinner',  $hSecRest,    0.60],
    ['Lights out by 11',   $hSecRest,    0.45],
];
$habits = [
    ['id' => $hSecKitchen, 'type' => 'section', 'name' => 'Kitchen', 'color' => $habitPal[3], 'created' => time()],
    ['id' => $hSecRest,    'type' => 'section', 'name' => 'Rest',    'color' => $habitPal[4], 'created' => time()],
];
foreach ($habitSpec as [$name, $section, $rate]) {
    $done = [];
    for ($i = 42; $i >= 0; $i--) {
        $day    = $d(-$i);
        $chance = $rate * (in_array(date('N', strtotime($day)), ['6', '7'], true) ? 0.7 : 1.0);
        if (mt_rand(0, 999) / 1000 < $chance) { $done[$day] = true; }
    }
    $habits[] = ['id' => $id(), 'name' => $name, 'done' => $done ?: new stdClass(),
                 'section' => $section, 'created' => time()];
}
store_write($file('habits'), $habits);

// ---------------------------------------------------------------- prefs
store_write($dir . '/prefs-' . USER . '.json', ['theme' => 'green', 'habits_view' => 'week']);

// ---------------------------------------------------------------- the pairing
// Buddy shares the dinner out: the Dinners reminder folder (both checklists), the Recipes
// note folder (the recipe itself) and the Cooking calendar (the two Saturdays).
shares_save($dir, USER, [
    'calendars' => [$calCooking],
    'folders'   => ['Dinners'],
    'notes'     => ['Recipes'],
]);

// The other side only exists if example has been seeded. Everything below is written from
// scratch each run rather than appended to, so re-seeding buddy can't double anything up.
$partnerCals = store_read(user_data_file($dir, 'calendars', PARTNER));
if (!$partnerCals) {
    echo "Note: no \"" . PARTNER . "\" account found — run tools/seed-example.php first for\n"
       . "      the sharing half of this. Buddy's own data is complete either way.\n";
} else {
    // Put the same two dinners on example's calendar, named from their side. Any earlier
    // copy is dropped first, so this stays idempotent.
    $pFamily = '';
    foreach ($partnerCals as $c) {
        if (($c['type'] ?? '') === 'set') { continue; }
        if ($pFamily === '') { $pFamily = (string) ($c['id'] ?? ''); }
        if (strcasecmp((string) ($c['name'] ?? ''), 'Family') === 0) { $pFamily = (string) $c['id']; break; }
    }
    $pEvFile = user_data_file($dir, 'events', PARTNER);
    $pEvents = array_values(array_filter(store_read($pEvFile),
        fn($e) => strcasecmp((string) ($e['text'] ?? ''), 'Dinner with buddy') !== 0));
    $pEvents[] = $ev('Dinner with buddy', $sat1, '19:00', $pFamily);
    $pEvents[] = $ev('Dinner with buddy', $sat2, '19:00', $pFamily);
    store_write($pEvFile, $pEvents);

    // And example shares back, so the sharing reads both ways rather than one.
    $pFolders = folders_load($dir, PARTNER);
    $backRem  = in_array('Home', $pFolders['reminders'] ?? [], true) ? ['Home'] : [];
    $backNote = in_array('Recipes', $pFolders['notes'] ?? [], true) ? ['Recipes'] : [];
    shares_save($dir, PARTNER, [
        'calendars' => $pFamily !== '' ? [$pFamily] : [],
        'folders'   => $backRem,
        'notes'     => $backNote,
    ]);
}

echo "Seeded the \"" . USER . "\" account (password: " . PASS . ").\n";
echo "  Dinners on " . date('D j M', strtotime($sat1)) . " and " . date('D j M', strtotime($sat2))
   . "; shopping " . date('D j M', strtotime($shopDay)) . ".\n";
echo "  " . count(array_filter($rem, fn($x) => ($x['type'] ?? '') !== 'section')) . " reminders, "
   . count($cals) . " calendars, " . count($habitSpec) . " habits.\n";
echo "  Paired with \"" . PARTNER . "\": each shares a calendar, a reminder folder and a note folder.\n";
echo "  Data written to $dir\n";
