<?php
/**
 * Quick-add app — the middle "+" tab. Type a line, pick a type (Reminder / Event / Note)
 * with the selector, optionally choose a folder/section and a date/time, then press Done.
 * It reuses the suite's text parser, so "Vet 8/3 2pm" lands on Aug 3 at 2pm. Reminders go
 * to the chosen folder/section (or the fallback folder), events to the events file, notes
 * to the default note folder — the same defaults the widget's quick-add uses.
 */
$__libDir = null;
foreach ([__DIR__ . '/../../lib', '/home/protected/lib'] as $__c) {
    if (is_file($__c . '/auth.php')) { $__libDir = $__c; break; }
}
require_once $__libDir . '/auth.php';
require_once $__libDir . '/folders.php';
require_once $__libDir . '/chrome.php';
require_once $__libDir . '/tabbar.php';
require_login('Add');

$cfg   = app_config();
$today = date('Y-m-d');
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
$csrf  = htmlspecialchars($_SESSION['csrf'], ENT_QUOTES);

function e(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES); }

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
    $text = trim((string) ($_POST['text'] ?? ''));
    $act  = (string) ($_POST['action'] ?? '');
    // "Vet 8/3 2pm" -> text "Vet", date Aug 3, time 14:00. An explicit date/time field
    // from the dropdowns wins over anything parsed from the text.
    [$ptext, $pdate, $ptime] = parse_when_from_text($text);
    $fDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['date'] ?? '')) ? $_POST['date'] : null;
    $fTime = preg_match('/^\d{2}:\d{2}$/', (string) ($_POST['time'] ?? '')) ? $_POST['time'] : null;
    $date  = $fDate ?? $pdate;                       // may be null: reminders/notes need no date
    $time  = $fTime ?? $ptime;
    $flash = '';
    if ($text !== '' && $act === 'add_reminder') {
        // A reminder has no default date — undated is fine (it just isn't on the calendar).
        // Folder and section come from their own dropdowns and are each re-validated.
        $rFolders = folders_load($cfg['data_dir'])['reminders'];
        $rFolder  = (string) ($_POST['folder'] ?? '');
        if (!in_array($rFolder, $rFolders, true)) { $rFolder = folder_fallback('reminders'); }
        $rSection = (string) ($_POST['section'] ?? '');
        $f = user_data_file($cfg['data_dir'], 'reminders');
        $l = reminders_folder_migrate(store_read($f));
        $secOk = false;
        foreach ($l as $it) { if (($it['type'] ?? '') === 'section' && ($it['folder'] ?? '') === $rFolder
            && (string) ($it['name'] ?? '') === $rSection) { $secOk = true; break; } }
        if (!$secOk) { $rSection = ''; }
        $l[] = ['id' => bin2hex(random_bytes(6)), 'text' => mb_substr($ptext, 0, 500),
                'due' => $date ?? '', 'time' => $time, 'done' => false,
                'folder' => $rFolder, 'section' => $rSection, 'created' => time()];
        store_write($f, array_values($l));
        $flash = 'Reminder added' . ($date ? ' · ' . date('D, M j', strtotime($date)) : '');
    } elseif ($text !== '' && $act === 'add_event') {
        // An event lives on the calendar, so it does default to today when no date is given.
        $f = user_data_file($cfg['data_dir'], 'events');
        $l = store_read($f);
        $l[] = ['id' => bin2hex(random_bytes(6)), 'text' => mb_substr($ptext, 0, 500),
                'date' => $date ?? $today, 'time' => $time, 'created' => time()];
        store_write($f, array_values($l));
        $flash = 'Event added' . ($time ? ' · ' . date('g:ia', strtotime($time)) : '');
    } elseif ($text !== '' && $act === 'add_note') {
        // Notes have no default date either.
        $f = user_data_file($cfg['data_dir'], 'notes');
        $l = store_read($f);
        $l[] = ['id' => bin2hex(random_bytes(6)), 'title' => mb_substr($ptext, 0, 200),
                'body' => '', 'folder' => FOLDER_DEFAULT, 'section' => '',
                'date' => $date ?? '', 'created' => time()];
        store_write($f, array_values($l));
        $flash = 'Note added';
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?ok=' . rawurlencode($flash));
    exit;
}
$flash = isset($_GET['ok']) ? (string) $_GET['ok'] : '';

// Folder/section choices for a reminder: each folder with its sections (the catch-all shown
// as "Reminders"). Kept as a map so the Section dropdown can be filtered to the chosen folder.
$remFolders = folders_load($cfg['data_dir'])['reminders'];
$remDef     = folder_default_get($cfg['data_dir'], 'reminders');
if (!in_array($remDef, $remFolders, true)) { $remDef = $remFolders[0] ?? folder_fallback('reminders'); }
$myRem      = reminders_folder_migrate(store_read(user_data_file($cfg['data_dir'], 'reminders')));
$remSecs    = [];   // folder => [[value, label], …], value '' = the catch-all
foreach ($remFolders as $mf) {
    $remSecs[$mf] = [['', 'Reminders']];
    foreach ($myRem as $it) {
        if (($it['type'] ?? '') === 'section' && ($it['folder'] ?? '') === $mf) {
            $nm = (string) ($it['name'] ?? '');
            if ($nm !== '') { $remSecs[$mf][] = [$nm, $nm]; }
        }
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>Add</title>
  <meta name="theme-color" content="#111111">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black">
  <meta name="apple-mobile-web-app-title" content="Add">
  <style>
    <?= kind_color_css() ?>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root { --accent: #34d399; --accent-ink: #06251b; }
    body { font-family: system-ui, sans-serif; background: #111; color: #eee; min-height: 100vh; }
    <?= tabbar_styles() ?>
    <?= chrome_styles() ?>
    /* Same top bar as every other app: back + name on the left, username on the right,
       a rule under the lot (the rule comes from chrome_styles' header border). */
    header { display: flex; align-items: center; justify-content: space-between; }
    header h1 { font-size: 1.35rem; }
    header .titlebar { display: flex; align-items: center; gap: 0.85rem; }
    .wrap { width: 100%; max-width: 460px; margin: 0 auto; padding: 1.5rem 1rem 5rem; }
    .addhead { font-size: 0.85rem; color: #888; margin: 0.5rem 0 0; }
    .flash { background: #14251f; border: 1px solid var(--accent); color: var(--accent); border-radius: 8px;
      padding: 0.55rem 0.8rem; font-size: 0.9rem; margin: 0.75rem 0; }
    .bar input[type=text] { width: 100%; padding: 0.85rem 0.9rem; background: #1a1a1a; border: 1px solid #333;
      border-radius: 10px; color: #eee; font-size: 16px; margin: 1rem 0; }
    .bar input[type=text]:focus { outline: none; border-color: #888; }
    /* The type selector: three toggles, one active at a time. Picking one doesn't submit —
       Done does — so it reads as choosing a kind, not firing three separate actions. */
    .btns { display: flex; gap: 0.6rem; }
    .qb { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 0.25rem;
      padding: 0.9rem 0.5rem; border: 1px solid #333; border-radius: 12px; font-size: 0.9rem; font-weight: 600;
      cursor: pointer; background: #1a1a1a; font-family: inherit; color: #888; }
    .qb :first-child { font-size: 1.6rem; line-height: 1; }
    .qb.sel.rem { color: var(--k-reminder); border-color: var(--k-reminder); background: #14231c; }
    .qb.sel.evt { color: var(--k-event); border-color: var(--k-event); background: #142131; }
    .qb.sel.note { color: var(--k-note); border-color: var(--k-note); background: #23200f; }
    /* A pill toggle that reveals a hidden panel, the same shape as "+ Date/Time". */
    .revbtn { margin-top: 0.9rem; padding: 0.5rem 0.9rem; background: none; border: 1px solid #333;
      color: #ccc; border-radius: 999px; font-size: 0.9rem; font-family: inherit; cursor: pointer; }
    .revbtn:hover { border-color: #888; color: #fff; }
    .revrow[hidden], .revbtn[hidden] { display: none; }
    .revrow { display: flex; gap: 0.75rem; margin-top: 0.9rem; }
    .revrow label { flex: 1; display: flex; flex-direction: column; gap: 0.3rem; font-size: 0.78rem; color: #999; }
    .revrow select, .revrow input { padding: 0.6rem 0.7rem; background: #1a1a1a; border: 1px solid #333;
      border-radius: 8px; color: #eee; font-size: 16px; font-family: inherit; }
    .revrow select:focus, .revrow input:focus { outline: none; border-color: #888; }
    /* Done: the primary action, green, sitting under the options and above the syntax notes. */
    .donerow { margin-top: 1.25rem; }
    .donebtn { width: 100%; padding: 0.85rem; background: var(--accent); color: var(--accent-ink);
      border: none; border-radius: 10px; font-size: 1rem; font-weight: 700; font-family: inherit; cursor: pointer; }
    .donebtn:hover { background: #52e0ac; }
    .syntax { margin-top: 1.5rem; color: #999; font-size: 0.82rem; }
    .syntax .shead { color: #888; margin-bottom: 0.4rem; }
    .syntax ul { list-style: none; margin: 0 0 0.9rem; padding: 0; }
    .syntax li { padding: 0.2rem 0; }
    .syntax li::before { content: '·'; color: #666; margin-right: 0.5rem; }
    .syntax b { color: #cfcfcf; font-weight: 600; }
  </style>
</head>
<body>
<div class="wrap">
  <header>
    <div class="hleft">
      <?= back_button() ?>
      <div class="titlebar"><h1>Add</h1></div>
    </div>
    <?= render_user_menu(false, '', '', false, '') ?>
  </header>
  <p class="addhead"><?= e(date('l, M j')) ?></p>
  <?php if ($flash !== ''): ?><div class="flash"><?= e($flash) ?></div><?php endif; ?>
  <form method="post" class="bar" id="addForm">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="action" id="actField" value="add_reminder">
    <input type="text" name="text" placeholder="e.g. Dentist 8/3 2pm…" autocomplete="off" autofocus required>

    <?php // Type selector — one active at a time; the active one sets the hidden action. ?>
    <div class="btns" id="typeSel">
      <button type="button" class="qb rem sel" data-act="add_reminder"><span>&#10003;</span><span>Reminder</span></button>
      <button type="button" class="qb evt" data-act="add_event"><span>&#128197;</span><span>Event</span></button>
      <button type="button" class="qb note" data-act="add_note"><span>&#128221;</span><span>Note</span></button>
    </div>

    <?php // Folder / section — only meaningful for a reminder, so it hides for event/note.
          // Styled and revealed like the + Date/Time panel below it. ?>
    <button type="button" class="revbtn" id="fsToggle">+ Folder/Section</button>
    <div class="revrow" id="fsRow" hidden>
      <label>Folder
        <select name="folder" id="fFolder">
          <?php foreach ($remFolders as $mf): ?>
            <option value="<?= e($mf) ?>"<?= $mf === $remDef ? ' selected' : '' ?>><?= e($mf) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Section
        <select name="section" id="fSection"></select>
      </label>
    </div>

    <?php // Optional explicit date/time, hidden until asked for; either wins over what the
          // text parses to. An event with no date at all is still filed today. ?>
    <button type="button" class="revbtn" id="dtToggle">+ Date/Time</button>
    <div class="revrow" id="dtRow" hidden>
      <label>Date <input type="date" name="date"></label>
      <label>Time <input type="time" name="time"></label>
    </div>

    <div class="donerow"><button type="submit" class="donebtn">Done</button></div>
  </form>

  <div class="syntax">
    <p class="shead">You can also type the date and time into the line:</p>
    <ul>
      <li><b>2pm</b> or <b>2:30pm</b> — a time</li>
      <li><b>8/3</b> — a date this year (the next one to come)</li>
      <li><b>8/3/26</b> or <b>8/3/2026</b> — a full date</li>
      <li>e.g. <b>Vet 8/3 2pm</b> → &ldquo;Vet&rdquo;, Aug 3, 2:00pm</li>
    </ul>
  </div>

  <script>(function(){
    // Reveal panels (folder/section, date/time), each a pill that swaps for its row.
    [['fsToggle','fsRow'],['dtToggle','dtRow']].forEach(function(p){
      var b=document.getElementById(p[0]), r=document.getElementById(p[1]);
      if(b&&r){ b.addEventListener('click',function(){ r.hidden=false; b.hidden=true;
        var el=r.querySelector('select,input'); if(el) el.focus(); }); }
    });

    // Type selector: highlight the picked one and set the hidden action. Folder/section
    // only applies to reminders, so its toggle+row hide for events and notes.
    var SECS = <?= json_encode($remSecs) ?>;
    var act=document.getElementById('actField'), sel=document.getElementById('typeSel');
    var fsToggle=document.getElementById('fsToggle'), fsRow=document.getElementById('fsRow');
    function setType(a){
      act.value=a;
      [].forEach.call(sel.querySelectorAll('.qb'),function(q){ q.classList.toggle('sel',q.dataset.act===a); });
      var isRem=(a==='add_reminder');
      // Show the folder/section control only for reminders; keep whichever state it's in.
      if(!isRem){ fsRow.hidden=true; fsToggle.hidden=true; }
      else if(fsRow.hidden){ fsToggle.hidden=false; }
    }
    sel.addEventListener('click',function(e){ var q=e.target.closest('.qb'); if(q) setType(q.dataset.act); });

    // Section dropdown follows the chosen folder.
    var fFolder=document.getElementById('fFolder'), fSection=document.getElementById('fSection');
    function fillSecs(){
      var opts=SECS[fFolder.value]||[['','Reminders']];
      fSection.innerHTML='';
      opts.forEach(function(o){ var op=document.createElement('option'); op.value=o[0]; op.textContent=o[1]; fSection.appendChild(op); });
    }
    if(fFolder){ fFolder.addEventListener('change',fillSecs); fillSecs(); }
  })();</script>
</div>
<?php render_tabbar('add'); ?>
<?= chrome_script() ?>
</body>
</html>
