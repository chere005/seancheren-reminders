<?php
/**
 * Create (or reset) the "example" demo account and fill it with plausible data.
 *
 *   php tools/seed-example.php            # local ./data
 *   php tools/seed-example.php --force    # overwrite an example account that exists
 *
 * The account lands in data/accounts.json, the same place a sign-up goes, so nothing
 * about config.php has to change. Every file it writes belongs to "example" alone —
 * no other user's data is read or touched.
 *
 * deploy.sh doesn't send tools/, and it never sends data/, so the live site needs this
 * run there once. It must run as the WEB user, not over SSH: /home/protected/data/ is
 * owned by web (drwx------), so a CLI run as the SSH login user gets Permission denied on
 * every write. Seed over HTTP instead — scp this file plus a throwaway wrapper
 *   (<?php $argv=['x','--force']; require __DIR__.'/seed-example.php';  guarded by a secret)
 * into /home/public/, curl the wrapper once, then delete both.
 * (the script finds lib/ at /home/protected/lib the same way every page does).
 */

$libDir = null;
foreach ([__DIR__ . '/../lib', '/home/protected/lib'] as $c) {
    if (is_file($c . '/auth.php')) { $libDir = $c; break; }
}
if ($libDir === null) { fwrite(STDERR, "Can't find lib/.\n"); exit(1); }
require_once $libDir . '/auth.php';
require_once $libDir . '/folders.php';

const USER = 'example';
const PASS = 'examplepassword';

$cfg   = app_config();
$dir   = rtrim($cfg['data_dir'], '/');
$force = in_array('--force', $argv, true);
if (!is_dir($dir)) { mkdir($dir, 0700, true); }

$accounts = accounts_load($cfg);
if (isset($accounts[USER]) && !$force) {
    echo "The \"" . USER . "\" account already exists. Re-run with --force to reset it.\n";
    exit(0);
}

/** Dates are written relative to today, so the demo never goes stale. */
$d = fn(int $days) => date('Y-m-d', strtotime("$days days"));
$today = date('Y-m-d');
$id    = fn() => bin2hex(random_bytes(6));
$file  = fn(string $base) => user_data_file($dir, $base, USER);

// ---------------------------------------------------------------- account
$accounts[USER] = ['email' => 'example@seancheren.com', 'password' => PASS, 'created' => time()];
accounts_save($cfg, $accounts);
// A password someone set by hand would otherwise win over the one above.
$pw = store_read($dir . '/passwords.json');
unset($pw[USER]);
store_write($dir . '/passwords.json', $pw);

// ---------------------------------------------------------------- folders
$remPal  = app_palette('reminders');
$notePal = app_palette('notes');
store_write($file('folders'), [
    'reminders' => [FOLDER_REMINDERS, FOLDER_CALENDAR, 'Work', 'Home'],
    'notes'     => [FOLDER_DEFAULT, 'Recipes', 'Travel'],
    'default'   => ['reminders' => FOLDER_REMINDERS, 'notes' => FOLDER_DEFAULT],
    'last'      => ['reminders' => 'All', 'notes' => 'All'],
    'hidden'    => ['reminders' => [], 'notes' => []],
    'colors'    => [
        'reminders' => [FOLDER_REMINDERS => $remPal[0], FOLDER_CALENDAR => $remPal[1],
                        'Work' => $remPal[2], 'Home' => $remPal[3]],
        'notes'     => [FOLDER_DEFAULT => $notePal[0], 'Recipes' => $notePal[2], 'Travel' => $notePal[1]],
    ],
]);

// ---------------------------------------------------------------- calendars
$calPal = app_palette('calendar');
$cals = [
    ['id' => $id(), 'name' => 'Personal', 'color' => $calPal[0], 'created' => time()],
    ['id' => $id(), 'name' => 'Work',     'color' => $calPal[1], 'created' => time()],
    ['id' => $id(), 'name' => 'Family',   'color' => $calPal[2], 'created' => time()],
];
store_write($file('calendars'), $cals);
[$calPersonal, $calWork, $calFamily] = array_column($cals, 'id');
store_write($file('calprefs'), [
    'hidden_folders' => [], 'hidden_shared_folders' => [],
    'default_cal' => $calPersonal, 'last_cal' => 'all',
]);

// ---------------------------------------------------------------- reminders
// A section header, then rows. Deliberately a mix: dated, undated, repeating, a few
// already ticked, and several overdue and still open — which is what the app is
// actually like to use and what the overdue styling needs to be looked at.
$secErrands = $id();
$secBills   = $id();
$secGarden  = $id();
$rem = [
    ['id' => $secErrands, 'type' => 'section', 'name' => 'Errands', 'folder' => 'Home',  'created' => time()],
    ['id' => $secBills,   'type' => 'section', 'name' => 'Bills',   'folder' => 'Home',  'created' => time()],
    ['id' => $secGarden,  'type' => 'section', 'name' => 'Garden',  'folder' => 'Home',  'created' => time()],
];
$r = function (string $text, ?string $due, string $folder, string $section = '',
               array $extra = []) use ($id): array {
    return array_merge([
        'id' => $id(), 'text' => $text, 'due' => $due, 'time' => null, 'done' => false,
        'folder' => $folder, 'section' => $section, 'repeat' => null, 'created' => time(),
    ], $extra);
};

// --- Overdue and still not ticked off ---
$rem[] = $r('Return the library books', $d(-12), 'Home', 'Errands');
$rem[] = $r('Call the dentist back',    $d(-6),  'Home', 'Errands');
$rem[] = $r('Pay the water bill',       $d(-3),  'Home', 'Bills');
$rem[] = $r('Send Marcus the invoice',  $d(-2),  'Work');
$rem[] = $r('Renew the car registration', $d(-21), 'Home', 'Errands');

// --- Dated, coming up ---
$rem[] = $r('Dentist — 8:40am',   $d(2),  'Home', 'Errands', ['time' => '08:40']);
$rem[] = $r('Q3 numbers to Priya', $d(1), 'Work');
$rem[] = $r('Book the rental car', $d(5), 'Home', 'Errands');
$rem[] = $r('Mum’s birthday card', $d(9), 'Home', 'Errands');
$rem[] = $r('Standup notes',       $today, 'Work', '', ['time' => '09:30']);
$rem[] = $r('Water the tomatoes',  $today, 'Home', 'Garden',
            ['repeat' => ['n' => 2, 'unit' => 'day']]);
$rem[] = $r('Rent',                $d(4),  'Home', 'Bills',
            ['repeat' => ['n' => 1, 'unit' => 'month']]);
$rem[] = $r('Change the furnace filter', $d(17), 'Home', 'Garden',
            ['repeat' => ['n' => 3, 'unit' => 'month']]);

// --- No date at all ---
$rem[] = $r('Find a plumber for the upstairs tap', null, 'Home', 'Errands');
$rem[] = $r('Read the Kubernetes migration doc',   null, 'Work');
$rem[] = $r('Think about a name for the podcast',  null, FOLDER_REMINDERS);
$rem[] = $r('Sort the loft',                       null, 'Home', 'Garden');
$rem[] = $r('Reply to Dana about the cabin',       null, FOLDER_REMINDERS);

// --- The Calendar folder: undated, so these ride along on today until ticked ---
$rem[] = $r('Stretch for ten minutes', null, FOLDER_CALENDAR);
$rem[] = $r('Check the compost',       null, FOLDER_CALENDAR);

// --- Already done ---
$rem[] = $r('Pick up the dry cleaning', $d(-1), 'Home', 'Errands', ['done' => true]);
$rem[] = $r('Submit expenses',          $d(-4), 'Work', '',        ['done' => true]);
store_write($file('reminders'), $rem);

// ---------------------------------------------------------------- events
$ev = function (string $text, string $date, ?string $time, string $cal,
                ?array $repeat = null) use ($id): array {
    return ['id' => $id(), 'text' => $text, 'date' => $date, 'time' => $time,
            'cal' => $cal, 'repeat' => $repeat, 'created' => time()];
};
store_write($file('events'), [
    $ev('Team standup',            $d(-14), '09:30', $calWork,   ['n' => 1, 'unit' => 'day']),
    $ev('1:1 with Priya',          $d(1),   '14:00', $calWork,   ['n' => 1, 'unit' => 'week']),
    $ev('Design review',           $d(2),   '11:00', $calWork),
    $ev('Flight to Chicago',       $d(6),   '06:15', $calPersonal),
    $ev('Hotel checkout',          $d(9),   '11:00', $calPersonal),
    $ev('Dinner with the Kellers', $d(3),   '19:00', $calFamily),
    $ev('Soccer practice',         $d(-7),  '17:30', $calFamily,  ['n' => 1, 'unit' => 'week']),
    $ev('Mum’s birthday',          $d(9),   null,    $calFamily,  ['n' => 1, 'unit' => 'year']),
    $ev('Quarterly planning',      $d(12),  '10:00', $calWork),
    $ev('Car service',             $d(15),  '08:00', $calPersonal),
    $ev('Book club — “Piranesi”',  $d(-2),  '19:30', $calPersonal, ['n' => 1, 'unit' => 'month']),
    $ev('Parents’ evening',        $d(20),  '18:00', $calFamily),
]);

// ---------------------------------------------------------------- notes
$nSecIdeas  = $id();
$nSecRecipe = $id();
$n = function (string $title, string $body, string $folder, string $section = '',
               ?string $date = null) use ($id): array {
    return ['id' => $id(), 'title' => $title, 'body' => $body, 'folder' => $folder,
            'section' => $section, 'date' => $date, 'time' => null,
            'created' => time(), 'updated' => time()];
};
store_write($file('notes'), [
    ['id' => $nSecIdeas,  'type' => 'section', 'name' => 'Ideas',   'folder' => FOLDER_DEFAULT, 'created' => time()],
    ['id' => $nSecRecipe, 'type' => 'section', 'name' => 'Dinners', 'folder' => 'Recipes',      'created' => time()],
    $n('Podcast name shortlist',
       '<ul><li>The Long Way Round</li><li>Second Draft</li><li>Nothing Finished</li></ul>'
       . '<p>Check each one on the podcast directories before getting attached.</p>',
       FOLDER_DEFAULT, 'Ideas'),
    $n('Things to fix in the flat',
       '<ul><li>Upstairs tap drips</li><li>Hall light switch is loose</li>'
       . '<li>Back door sticks in the damp</li></ul>',
       FOLDER_DEFAULT, 'Ideas'),
    $n('Weeknight pasta',
       '<p>Garlic in cold oil, low heat, ten minutes. Anchovy, chilli, then the tomatoes. '
       . 'Salt the water properly. Finish in the pan with a splash of the pasta water.</p>',
       'Recipes', 'Dinners'),
    $n('Sunday roast timings',
       '<p>Chicken 20 min at 220°C, then 45 at 180. Potatoes in at the same time as the '
       . 'temperature drops. Rest the bird for 15 while the gravy comes together.</p>',
       'Recipes', 'Dinners'),
    $n('Chicago — what to do',
       '<p>Art Institute (free Thursday evenings), the Green Mill if there is a late set, '
       . 'walk the lakefront if it is not blowing a gale.</p>',
       'Travel', '', $d(6)),
    $n('Packing list',
       '<ul><li>Charger + adapter</li><li>Rain shell</li><li>Book for the flight</li></ul>',
       'Travel', '', $d(5)),
    $n('Standup — running notes',
       '<p>Blocked on the migration script. Ask about the staging database refresh.</p>',
       FOLDER_DEFAULT),
]);

// ---------------------------------------------------------------- habits
// Six habits under two sections, with roughly eight weeks of history behind them, each
// at its own rate — so the month view's pies come out uneven the way real ones do.
$hSecMorning = $id();
$hSecEvening = $id();
$habitSpec = [
    ['Morning walk',   $hSecMorning, 0.80],
    ['Stretch',        $hSecMorning, 0.55],
    ['Vitamins',       $hSecMorning, 0.92],
    ['Read 20 pages',  $hSecEvening, 0.65],
    ['No phone in bed', $hSecEvening, 0.40],
    ['Tidy the kitchen', $hSecEvening, 0.75],
];
$habits = [
    ['id' => $hSecMorning, 'type' => 'section', 'name' => 'Morning', 'created' => time()],
    ['id' => $hSecEvening, 'type' => 'section', 'name' => 'Evening', 'created' => time()],
];
foreach ($habitSpec as [$name, $section, $rate]) {
    $done = [];
    for ($i = 56; $i >= 0; $i--) {
        $day = $d(-$i);
        // Weekends slip a bit, the way they do.
        $chance = $rate * (in_array(date('N', strtotime($day)), ['6', '7'], true) ? 0.7 : 1.0);
        if (mt_rand(0, 999) / 1000 < $chance) { $done[$day] = true; }
    }
    $habits[] = ['id' => $id(), 'name' => $name, 'done' => $done ?: new stdClass(),
                 'section' => $section, 'created' => time()];
}
store_write($file('habits'), $habits);

// ---------------------------------------------------------------- prefs
store_write($dir . '/prefs-' . USER . '.json', ['theme' => 'green', 'habits_view' => 'week']);

echo "Seeded the \"" . USER . "\" account (password: " . PASS . ").\n";
echo "  " . count(array_filter($rem, fn($x) => ($x['type'] ?? '') !== 'section')) . " reminders, "
   . count($cals) . " calendars, " . count($habitSpec) . " habits.\n";
echo "  Data written to $dir\n";
