<?php
// Locate the shared lib/ — local dev (../../lib) or NFSN (/home/protected/lib).
$__libDir = null;
foreach ([__DIR__ . '/../../lib', '/home/protected/lib'] as $__c) {
    if (is_file($__c . '/auth.php')) { $__libDir = $__c; break; }
}
require_once $__libDir . '/auth.php';
require_once $__libDir . '/tabbar.php';
require_once $__libDir . '/chrome.php';
require_login('Habits');

$cfg      = app_config();
$dataFile = user_data_file($cfg['data_dir'], 'habits');
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }

function e(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES); }
function load_habits(string $f): array { return store_read($f); }
function save_habits(string $f, array $h): void { store_write($f, array_values($h)); }
function is_section(array $it): bool { return ($it['type'] ?? '') === 'section'; }

// Render one habit's name bubble + 7 day cells into the grid.
function render_habit_row(array $h, array $days, string $today, string $csrf): void { ?>
        <div class="hname">
          <span class="hlabel"><?= e($h['name'] ?? '') ?></span>
          <form method="post" action="" style="display:inline">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="delete_habit">
            <input type="hidden" name="id" value="<?= e($h['id']) ?>">
            <button class="del needs-confirm" data-confirm="Delete?" type="submit" title="Delete habit">&times;</button>
          </form>
        </div>
        <?php foreach ($days as $d): $done = !empty($h['done'][$d]); ?>
          <button class="cell <?= $done ? 'done' : '' ?> <?= $d === $today ? 'today' : ($d > $today ? 'ahead' : '') ?>"
                  data-id="<?= e($h['id']) ?>" data-date="<?= $d ?>" aria-label="<?= e(($h['name'] ?? '') . ' ' . $d) ?>"></button>
        <?php endforeach;
}

// --- Mutations ---
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action'])) {
    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400); exit('Bad request (invalid CSRF token).');
    }
    $habits = load_habits($dataFile);

    if ($_POST['action'] === 'toggle') {                 // AJAX: flip a day cell
        $id = (string) ($_POST['id'] ?? '');
        $d  = (string) ($_POST['date'] ?? '');
        $now = false;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            foreach ($habits as &$h) {
                if (($h['id'] ?? '') === $id) {
                    if (!empty($h['done'][$d])) { unset($h['done'][$d]); $now = false; }
                    else { $h['done'][$d] = true; $now = true; }
                    break;
                }
            }
            unset($h);
            save_habits($dataFile, $habits);
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'done' => $now]);
        exit;
    }

    // Nothing destructive happens without the confirmed second press.
    if (in_array($_POST['action'], ['delete_habit', 'delete_section'], true)
        && empty($_POST['confirm'])) {
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?edit=1');
        exit;
    }

    $stay = '?edit=1';   // these are all edit-mode controls; hand edit mode back
    if ($_POST['action'] === 'add_habit') {
        $name = trim(preg_replace('/\s+/', ' ', (string) ($_POST['name'] ?? '')));
        $section = (string) ($_POST['section'] ?? '');
        // Only keep a section id that actually exists.
        $validSection = '';
        foreach ($habits as $it) { if (is_section($it) && ($it['id'] ?? '') === $section) { $validSection = $section; break; } }
        if ($name !== '') {
            $habits[] = ['id' => bin2hex(random_bytes(6)), 'name' => mb_substr($name, 0, 60), 'done' => new stdClass(), 'section' => $validSection, 'created' => time()];
            save_habits($dataFile, $habits);
        }
    } elseif ($_POST['action'] === 'add_section') {
        $name = mb_substr(trim(preg_replace('/\s+/', ' ', (string) ($_POST['name'] ?? ''))), 0, 40);
        $exists = false;
        foreach ($habits as $it) { if (is_section($it) && mb_strtolower($it['name'] ?? '') === mb_strtolower($name)) { $exists = true; break; } }
        if ($name !== '' && !$exists) {
            $habits[] = ['id' => bin2hex(random_bytes(6)), 'type' => 'section', 'name' => $name, 'created' => time()];
            save_habits($dataFile, $habits);
        }
    } elseif ($_POST['action'] === 'delete_section') {
        $id = (string) ($_POST['id'] ?? '');
        $habits = array_values(array_filter($habits, fn($it) => !(is_section($it) && ($it['id'] ?? '') === $id)));
        foreach ($habits as &$it) { if (($it['section'] ?? '') === $id) { $it['section'] = ''; } }
        unset($it);
        save_habits($dataFile, $habits);
    } elseif ($_POST['action'] === 'delete_habit') {
        $id = (string) ($_POST['id'] ?? '');
        $habits = array_values(array_filter($habits, fn($h) => is_section($h) || ($h['id'] ?? '') !== $id));
        save_habits($dataFile, $habits);
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . $stay);
    exit;
}

// --- Render ---
$habits = load_habits($dataFile);
$today  = date('Y-m-d');
$days   = [];
// Six days back through tomorrow, so today sits second from the right and you can
// tick something off a day early.
for ($i = 5; $i >= -1; $i--) { $days[] = date('Y-m-d', strtotime("-$i days")); }
$csrf   = htmlspecialchars($_SESSION['csrf'], ENT_QUOTES);

// Split sections from habits; group habits under their section (ungrouped first).
$sections   = array_values(array_filter($habits, 'is_section'));
$habitItems = array_values(array_filter($habits, fn($h) => !is_section($h)));
$sectionIds = array_map(fn($s) => $s['id'], $sections);
$ungrouped  = array_values(array_filter($habitItems, fn($h) => !in_array($h['section'] ?? '', $sectionIds, true)));
$bySection  = fn(string $sid) => array_values(array_filter($habitItems, fn($h) => ($h['section'] ?? '') === $sid));
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>Habits</title>
  <meta name="theme-color" content="#111111">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black">
  <meta name="apple-mobile-web-app-title" content="Habits">
  <link rel="apple-touch-icon" href="/reminders/icon-180.png">
  <link rel="manifest" href="/reminders/manifest.webmanifest?v=2">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: system-ui, sans-serif; background: #111; color: #eee; min-height: 100vh; padding: 1.5rem 1rem; }
    .wrap { max-width: 640px; margin: 0 auto; }   /* same column as Reminders + Calendar */
    header { display: flex; align-items: center; justify-content: space-between; }
    header h1 { font-size: 1.35rem; }   /* same as the Calendar's */
    header .titlebar { display: flex; align-items: center; gap: 0.85rem; }
    header nav { display: flex; align-items: center; gap: 0.5rem; }
    header nav a { color: #888; text-decoration: none; font-size: 0.85rem; }
    header nav a:hover { color: #fff; }
    header nav .who { color: #34d399; font-size: 0.8rem; border: 1px solid #2a4a3d; border-radius: 999px; padding: 0.15rem 0.6rem; }

    .bar { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1.25rem; flex-wrap: wrap; padding-left: 2rem; }
    body:not(.editing) .bar { justify-content: flex-end; }   /* Edit keeps the right edge */
    .bar form.addh { flex: 1 1 220px; }
    body:not(.editing) .bar form.addh { display: none; }   /* edit mode only */
    .bar input[type=text] {
      width: 100%; padding: 0.6rem 0.75rem; background: #1a1a1a; border: 1px dashed #4a3f6a;
      border-radius: 8px; color: #b9a7f5; font-size: 1rem;
    }
    .bar input::placeholder { color: #b9a7f5; opacity: 0.75; }
    .bar input:focus { outline: none; border-style: solid; border-color: #8b6ef0; }
    .bar .hsel { padding: 0.55rem 0.6rem; background: #1a1a1a; border: 1px solid #333; color: #ccc; border-radius: 999px; font-size: 16px; }

    /* + Section — left-aligned amber pill above the day grid. */
    .newsection { margin: 0 0 1.1rem; }
    body:not(.editing) .newsection { display: none; }   /* edit mode only */
    .newsection input {
      width: 220px; max-width: 100%; padding: 0.45rem 0.85rem; background: #1a1a1a;
      border: 1px dashed #4a3f6a; border-radius: 999px; color: #b9a7f5; font-size: 16px;
    }
    .newsection input::placeholder { color: #b9a7f5; opacity: 0.8; }
    .newsection input:focus { outline: none; border-style: solid; border-color: #8b6ef0; }

    /* Grid: name column + 7 flexible day columns that shrink to fit narrow phones. */
    /* Fixed day columns rather than 1fr, so the gap between squares is the 6px it
       says it is in both directions instead of whatever's left over. */
    .grid { display: grid; grid-template-columns: minmax(52px, 84px) repeat(7, 28px); gap: 6px; align-items: center; max-width: 520px; }
    .colhead {
      text-align: center; font-family: ui-monospace, Menlo, monospace; font-size: 0.8rem;
      color: #888; padding-bottom: 0.4rem; border-radius: 8px 8px 0 0;
    }
    /* Today's whole column is tinted so it's obvious at a glance. */
    .colhead.today { color: #34d399; font-weight: 700; background: #14251f; }
    .colhead.ahead { color: #666; }        /* tomorrow, ticked off early */
    .colhead .num { display: block; font-size: 0.95rem; margin-top: 0.1rem; }
    .corner { }

    /* Section header row spans the full grid width. */
    .hsection {
      grid-column: 1 / -1; display: flex; align-items: center; gap: 0.5rem;
      margin: 0.9rem 0 0.1rem; padding: 0 0.1rem 0.35rem 2rem;   /* the Reminders offset */
      color: #b9a7f5; font-weight: 700; font-size: 0.95rem; border-bottom: 1px solid #2c2540;
    }
    .hsection .hslabel { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .hsection .del { display: none; margin-left: auto; background: none; border: 1px solid #444; color: #ccc; border-radius: 6px; padding: 0.1rem 0.45rem; font-size: 0.9rem; line-height: 1; cursor: pointer; }
    body.editing .hsection .del { display: inline-block; }
    .hsection .del:hover { border-color: #f66; color: #f66; }

    .hname {
      position: relative; background: #1b1726; border: 1px solid #2c2540; border-radius: 8px;
      padding: 0.35rem 0.5rem; min-height: 28px; display: flex; align-items: center; overflow: hidden;
    }
    .hname .hlabel { color: #d9d2f0; font-size: 0.92rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .hname .del { display: none; margin-left: auto; flex: 0 0 auto; background: none; border: 1px solid #444; color: #ccc; border-radius: 6px; padding: 0.15rem 0.45rem; font-size: 0.9rem; line-height: 1; cursor: pointer; }
    body.editing .hname .del { display: inline-block; }
    .hname .del:hover { border-color: #f66; color: #f66; }

    .cell {
      aspect-ratio: 1 / 1; min-height: 0; background: #1b1726; border: 1px solid #2c2540;
      border-radius: 8px; cursor: pointer; padding: 0; transition: background 0.1s;
    }
    .cell.today { border-color: #34d399; background: #14251f; }
    .cell.ahead { opacity: 0.55; }         /* tomorrow reads as not-yet */
    .cell.done { background: #34d399; border-color: #34d399; }
    .cell.done.today { border-color: #eee; }
    .cell:active { transform: scale(0.94); }

    .empty { color: #666; text-align: center; padding: 2rem 0; }
<?= tabbar_styles() ?>
<?= chrome_styles() ?>
  </style>
</head>
<body>
<div class="wrap">
  <header>
    <div class="hleft">
      <?= back_button() ?>
      <div class="titlebar">
        <h1>Habits</h1>
        <button type="button" id="editBtn" class="titlebtn edit-toggle" title="Edit" aria-label="Edit">&#9998;&#65038;</button>
      </div>
    </div>
    <?= render_user_menu() ?>
  </header>

  <div class="bar">
    <form method="post" action="" class="addh" onsubmit="return this.name.value.trim()!==''">
      <input type="hidden" name="csrf" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="add_habit">
      <input type="text" name="name" placeholder="+ New habit…" maxlength="60" autocomplete="off">
      <?php if ($sections): ?>
        <select name="section" class="hsel" aria-label="Section for new habit">
          <option value="">No section</option>
          <?php foreach ($sections as $s): ?>
            <option value="<?= e($s['id']) ?>"><?= e($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>
    </form>
  </div>

  <form method="post" action="" class="newsection" onsubmit="return this.name.value.trim()!==''">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="add_section">
    <input type="text" name="name" placeholder="+ Section" maxlength="40" autocomplete="off">
  </form>

  <?php if (!$habitItems && !$sections): ?>
    <p class="empty">No habits yet. Tap Edit to add one, then tap a day to mark it done.</p>
  <?php else: ?>
    <div class="grid">
      <div class="corner"></div>
      <?php foreach ($days as $d): $ts = strtotime($d); ?>
        <div class="colhead <?= $d === $today ? 'today' : ($d > $today ? 'ahead' : '') ?>">
          <?= substr(date('D', $ts), 0, 2) ?><span class="num"><?= (int) date('j', $ts) ?></span>
        </div>
      <?php endforeach; ?>

      <?php foreach ($ungrouped as $h) render_habit_row($h, $days, $today, $csrf); ?>

      <?php foreach ($sections as $s): ?>
        <div class="hsection">
          <span class="hslabel"><?= e($s['name']) ?></span>
          <form method="post" action="" style="display:inline">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="delete_section">
            <input type="hidden" name="id" value="<?= e($s['id']) ?>">
            <button class="del needs-confirm" data-confirm="Delete?" type="submit" title="Delete section">&times;</button>
          </form>
        </div>
        <?php foreach ($bySection($s['id']) as $h) render_habit_row($h, $days, $today, $csrf); ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>


<?php render_tabbar('habits'); ?>
<script>
  const CSRF = '<?= $csrf ?>';

  // Edit mode (persisted, like the other tabs).
  const editBtn = document.getElementById('editBtn');
  const setEdit = (on) => document.body.classList.toggle('editing', on);
  // Always starts off; a structural change redirects back with ?edit=1 to keep it on.
  setEdit(new URLSearchParams(location.search).get('edit') === '1');
  editBtn.addEventListener('click', () => setEdit(!document.body.classList.contains('editing')));

  // Tap a cell -> toggle that day for the habit (no reload).
  document.querySelectorAll('.cell').forEach(cell => {
    cell.addEventListener('click', () => {
      if (document.body.classList.contains('editing')) return;   // don't toggle while editing
      const body = new URLSearchParams({ csrf: CSRF, action: 'toggle', id: cell.dataset.id, date: cell.dataset.date });
      fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body })
        .then(r => r.json())
        .then(d => { if (d && d.ok) cell.classList.toggle('done', d.done); })
        .catch(() => {});
    });
  });
</script>
<?= chrome_script() ?>
</body>
</html>
