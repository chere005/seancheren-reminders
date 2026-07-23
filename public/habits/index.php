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

    $undoFlag = '';   // set after a delete so the page shows the Undo button
    if ($_POST['action'] === 'add_habit') {
        $name = trim(preg_replace('/\s+/', ' ', (string) ($_POST['name'] ?? '')));
        if ($name !== '') {
            $habits[] = ['id' => bin2hex(random_bytes(6)), 'name' => mb_substr($name, 0, 60), 'done' => new stdClass(), 'created' => time()];
            save_habits($dataFile, $habits);
        }
    } elseif ($_POST['action'] === 'delete_habit') {
        $id = (string) ($_POST['id'] ?? '');
        foreach ($habits as $h) { if (($h['id'] ?? '') === $id) { $_SESSION['undo_habit'] = $h; break; } }
        $habits = array_values(array_filter($habits, fn($h) => ($h['id'] ?? '') !== $id));
        save_habits($dataFile, $habits);
        $undoFlag = '?undo=1';
    } elseif ($_POST['action'] === 'undo') {
        if (!empty($_SESSION['undo_habit'])) { $habits[] = $_SESSION['undo_habit']; unset($_SESSION['undo_habit']); save_habits($dataFile, $habits); }
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . $undoFlag);
    exit;
}

// --- Render ---
$habits = load_habits($dataFile);
$today  = date('Y-m-d');
$days   = [];
for ($i = 6; $i >= 0; $i--) { $days[] = date('Y-m-d', strtotime("-$i days")); }
$csrf   = htmlspecialchars($_SESSION['csrf'], ENT_QUOTES);
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
  <link rel="manifest" href="/reminders/manifest.webmanifest">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: system-ui, sans-serif; background: #111; color: #eee; min-height: 100vh; padding: 1.5rem 1rem; }
    .wrap { max-width: 720px; margin: 0 auto; }
    header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.25rem; }
    header h1 { font-size: 1.5rem; }
    header nav { display: flex; align-items: center; gap: 0.5rem; }
    header nav a { color: #888; text-decoration: none; font-size: 0.85rem; }
    header nav a:hover { color: #fff; }
    header nav .who { color: #34d399; font-size: 0.8rem; border: 1px solid #2a4a3d; border-radius: 999px; padding: 0.15rem 0.6rem; }

    .bar { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1.25rem; flex-wrap: wrap; }
    .bar form.addh { flex: 1 1 220px; }
    .bar input[type=text] {
      width: 100%; padding: 0.6rem 0.75rem; background: #1a1a1a; border: 1px dashed #4a3f6a;
      border-radius: 8px; color: #b9a7f5; font-size: 1rem;
    }
    .bar input::placeholder { color: #b9a7f5; opacity: 0.75; }
    .bar input:focus { outline: none; border-style: solid; border-color: #8b6ef0; }
    .bar .editbtn { padding: 0.6rem 1rem; background: #1a1a1a; border: 1px solid #333; color: #ccc; border-radius: 8px; font-size: 1rem; cursor: pointer; }
    .bar .editbtn:hover { border-color: #888; color: #fff; }
    body.editing .bar #editBtn { background: #34d399; border-color: #34d399; color: #06251b; font-weight: 700; }
    .bar #undoBtn { display: none; }
    body.can-undo .bar #undoBtn { display: inline-block; }   /* only right after a delete */

    /* Grid: name column + 7 day columns (fixed small squares, left-aligned) */
    .grid { display: grid; grid-template-columns: 92px repeat(7, 34px); gap: 8px; align-items: center; justify-content: start; }
    .colhead { text-align: center; font-family: ui-monospace, Menlo, monospace; font-size: 0.8rem; color: #888; padding-bottom: 0.4rem; }
    .colhead.today { color: #fff; font-weight: 700; }
    .colhead .num { display: block; font-size: 0.95rem; margin-top: 0.1rem; }
    .corner { }

    .hname {
      position: relative; background: #1b1726; border: 1px solid #2c2540; border-radius: 8px;
      padding: 0.5rem 0.55rem; min-height: 34px; display: flex; align-items: center; overflow: hidden;
    }
    .hname .hlabel { color: #d9d2f0; font-size: 0.92rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .hname .del { display: none; margin-left: auto; flex: 0 0 auto; background: none; border: 1px solid #444; color: #ccc; border-radius: 6px; padding: 0.15rem 0.45rem; font-size: 0.9rem; line-height: 1; cursor: pointer; }
    body.editing .hname .del { display: inline-block; }
    .hname .del:hover { border-color: #f66; color: #f66; }

    .cell {
      aspect-ratio: 1 / 1; min-height: 0; background: #1b1726; border: 1px solid #2c2540;
      border-radius: 8px; cursor: pointer; padding: 0; transition: background 0.1s;
    }
    .cell.today { border-color: #eee; }
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
      <h1>Habits</h1>
    </div>
    <?= render_user_menu() ?>
  </header>

  <div class="bar">
    <form method="post" action="" class="addh" onsubmit="return this.name.value.trim()!==''">
      <input type="hidden" name="csrf" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="add_habit">
      <input type="text" name="name" placeholder="+ New habit…" maxlength="60" autocomplete="off">
    </form>
    <button type="button" id="undoBtn" class="editbtn">Undo</button>
    <button type="button" id="editBtn" class="editbtn">Edit</button>
  </div>

  <?php if (!$habits): ?>
    <p class="empty">No habits yet. Add one above, then tap a day to mark it done.</p>
  <?php else: ?>
    <div class="grid">
      <div class="corner"></div>
      <?php foreach ($days as $d): $ts = strtotime($d); ?>
        <div class="colhead <?= $d === $today ? 'today' : '' ?>">
          <?= substr(date('D', $ts), 0, 2) ?><span class="num"><?= (int) date('j', $ts) ?></span>
        </div>
      <?php endforeach; ?>

      <?php foreach ($habits as $h): ?>
        <div class="hname">
          <span class="hlabel"><?= e($h['name'] ?? '') ?></span>
          <form method="post" action="" style="display:inline">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="delete_habit">
            <input type="hidden" name="id" value="<?= e($h['id']) ?>">
            <button class="del" type="submit" title="Delete habit">&times;</button>
          </form>
        </div>
        <?php foreach ($days as $d): $done = !empty($h['done'][$d]); ?>
          <button class="cell <?= $done ? 'done' : '' ?> <?= $d === $today ? 'today' : '' ?>"
                  data-id="<?= e($h['id']) ?>" data-date="<?= $d ?>" aria-label="<?= e(($h['name'] ?? '') . ' ' . $d) ?>"></button>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<form id="undoForm" method="post" action="" style="display:none">
  <input type="hidden" name="csrf" value="<?= $csrf ?>">
  <input type="hidden" name="action" value="undo">
</form>

<?php render_tabbar('habits'); ?>
<script>
  const CSRF = '<?= $csrf ?>';

  // Edit mode (persisted, like the other tabs).
  const editBtn = document.getElementById('editBtn');
  const setEdit = (on) => {
    document.body.classList.toggle('editing', on);
    if (!on) document.body.classList.remove('can-undo');   // tapping Done clears the Undo button
    editBtn.textContent = on ? 'Done' : 'Edit';
    localStorage.setItem('habitsEditing', on ? '1' : '0');
  };
  setEdit(localStorage.getItem('habitsEditing') === '1');
  // Undo shows only immediately after a delete (server redirects back with ?undo=1).
  if (new URLSearchParams(location.search).get('undo') === '1') {
    document.body.classList.add('can-undo');
    history.replaceState(null, '', location.pathname);
  }
  editBtn.addEventListener('click', () => setEdit(!document.body.classList.contains('editing')));
  document.getElementById('undoBtn').addEventListener('click', () => document.getElementById('undoForm').submit());

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
