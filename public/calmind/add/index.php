<?php
/**
 * Quick-add app — the middle "+" tab. Type a line, pick a type (Reminder / Event / Note)
 * with the selector, optionally choose a folder/section and a date/time, then press Done.
 * It reuses the suite's text parser, so "Vet 8/3 2pm" lands on Aug 3 at 2pm. Reminders go
 * to the chosen folder/section (or the fallback folder), events to the events file, notes
 * to the default note folder — the same defaults the widget's quick-add uses.
 */
// A page served under /test/ (the sandbox mirror) loads lib-test/ instead of lib/, and one
// served under /dev/ (a second, fixed sandbox slot) loads lib-dev/ — each mirror
// isolated in code, config and data. Cross-app links carry the same prefix via suite_base();
// _self_path() redirects already stay put. Keep this preamble identical when adding a page.
$__test   = strpos(__DIR__, '/test/') !== false
         || strncmp($_SERVER['REQUEST_URI'] ?? '', '/test/', 6) === 0;
$__dev    = strpos(__DIR__, '/dev/') !== false
         || strncmp($_SERVER['REQUEST_URI'] ?? '', '/dev/', 5) === 0;
$__libDir = null;
$__cands  = $__dev
    ? [__DIR__ . '/../../../../lib-dev', '/home/protected/lib-dev']
    : ($__test
        ? [__DIR__ . '/../../../../lib-test', '/home/protected/lib-test']
        : [__DIR__ . '/../../../lib',         '/home/protected/lib']);
foreach ($__cands as $__c) {
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

/**
 * My own calendars, as [id, name]. The Calendar app owns the real loader (and the
 * migration that goes with it); this only needs to list them and check an id, so it
 * reads the file directly and drops anything that isn't a calendar row — the leftover
 * "calendar set" rows that load_calendars() also discards on its next write.
 */
function add_calendars(array $cfg): array
{
    $out = [];
    foreach (store_read(user_data_file($cfg['data_dir'], 'calendars')) as $c) {
        if (($c['type'] ?? '') === 'set' || empty($c['id'])) { continue; }
        $out[] = ['id' => (string) $c['id'], 'name' => (string) ($c['name'] ?? 'Calendar')];
    }
    return $out;
}

/** Where an event lands when none is chosen: the Calendar's own default, else the first. */
function add_default_cal(array $cfg, array $ids): string
{
    $d = (string) (store_read(user_data_file($cfg['data_dir'], 'calprefs'))['default_cal'] ?? '');
    return in_array($d, $ids, true) ? $d : (string) ($ids[0] ?? '');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // A bad token used to fall straight through to the render, so a stale page looked
    // like it had added your line when it had done nothing at all. Refuse it out loud,
    // the same 400 every other page in the suite answers with.
    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400); exit('Bad request (invalid CSRF token).');
    }
    $text = trim((string) ($_POST['text'] ?? ''));
    $act  = (string) ($_POST['action'] ?? '');
    // "Vet 8/3 2pm" -> text "Vet", date Aug 3, time 14:00. An explicit date/time field
    // from the dropdowns wins over anything parsed from the text.
    [$ptext, $pdate, $ptime] = parse_when_from_text($text);
    $fDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['date'] ?? '')) ? $_POST['date'] : null;
    $fTime = preg_match('/^\d{2}:\d{2}$/', (string) ($_POST['time'] ?? '')) ? $_POST['time'] : null;
    $date  = $fDate ?? $pdate;                       // may be null: reminders/notes need no date
    $time  = $fTime ?? $ptime;
    // Optional repeat, from the "+ Repeat" panel: a unit picks it up, blank leaves it once.
    $repeat = repeat_clean((string) ($_POST['repeat_unit'] ?? ''), (int) ($_POST['repeat_n'] ?? 1));
    $flash = '';
    if ($text !== '' && $act === 'add_reminder') {
        // A reminder has no default date — undated is fine (it just isn't on the calendar).
        // Folder and section come from their own dropdowns and are each re-validated; a
        // blank or unknown section lands in the folder's real default (there's no catch-all).
        $rFolders = folders_load($cfg['data_dir'])['reminders'];
        $rFolder  = (string) ($_POST['folder'] ?? '');
        if (!in_array($rFolder, $rFolders, true)) { $rFolder = folder_fallback('reminders'); }
        $rSection = (string) ($_POST['section'] ?? '');
        $f = user_data_file($cfg['data_dir'], 'reminders');
        $l = sections_normalize(reminders_folder_migrate(store_read($f)), $rFolders);
        $firstSec = sections_first_by_folder($l);
        $secOk = false;
        foreach ($l as $it) { if (($it['type'] ?? '') === 'section' && ($it['folder'] ?? '') === $rFolder
            && (string) ($it['name'] ?? '') === $rSection) { $secOk = true; break; } }
        if (!$secOk) { $rSection = $firstSec[$rFolder] ?? SECTION_DEFAULT_NAME; }
        $l[] = ['id' => bin2hex(random_bytes(6)), 'text' => mb_substr($ptext, 0, 500),
                'due' => $date ?? '', 'time' => $time, 'done' => false, 'repeat' => $repeat,
                'folder' => $rFolder, 'section' => $rSection, 'created' => time()];
        store_write($f, array_values($l));
        $flash = 'Reminder added' . ($date ? ' · ' . date('D, M j', strtotime($date)) : '');
    } elseif ($text !== '' && $act === 'add_event') {
        // An event lives on the calendar, so it does default to today when no date is
        // given — and it belongs to a calendar, picked here or the default one.
        $eCal = (string) ($_POST['cal'] ?? '');
        $ids  = array_column(add_calendars($cfg), 'id');
        if (!in_array($eCal, $ids, true)) { $eCal = add_default_cal($cfg, $ids); }
        $f = user_data_file($cfg['data_dir'], 'events');
        $l = store_read($f);
        $l[] = ['id' => bin2hex(random_bytes(6)), 'text' => mb_substr($ptext, 0, 500),
                'date' => $date ?? $today, 'time' => $time, 'cal' => $eCal, 'repeat' => $repeat, 'created' => time()];
        store_write($f, array_values($l));
        $flash = 'Event added' . ($time ? ' · ' . date('g:ia', strtotime($time)) : '');
    } elseif ($text !== '' && $act === 'add_note') {
        // Notes have no default date either. Folder and section come from their own
        // dropdowns and are re-validated the same way a reminder's are.
        $nFolders = folders_load($cfg['data_dir'])['notes'];
        $nFolder  = (string) ($_POST['nfolder'] ?? '');
        if (!in_array($nFolder, $nFolders, true)) { $nFolder = FOLDER_DEFAULT; }
        $nSection = (string) ($_POST['nsection'] ?? '');
        $f = user_data_file($cfg['data_dir'], 'notes');
        $l = sections_normalize(store_read($f), $nFolders);
        $firstSec = sections_first_by_folder($l);
        $secOk = false;
        foreach ($l as $it) { if (($it['type'] ?? '') === 'section' && ($it['folder'] ?? FOLDER_DEFAULT) === $nFolder
            && (string) ($it['name'] ?? '') === $nSection) { $secOk = true; break; } }
        if (!$secOk) { $nSection = $firstSec[$nFolder] ?? SECTION_DEFAULT_NAME; }
        $l[] = ['id' => bin2hex(random_bytes(6)), 'title' => mb_substr($ptext, 0, 200),
                'body' => '', 'folder' => $nFolder, 'section' => $nSection,
                'date' => $date ?? '', 'created' => time()];
        store_write($f, array_values($l));
        $flash = 'Note added';
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?ok=' . rawurlencode($flash));
    exit;
}
$flash = isset($_GET['ok']) ? (string) $_GET['ok'] : '';

// Folder/section choices for a reminder: each folder with its real sections. There's no
// unnamed catch-all any more — every folder has at least a default section (normalised in,
// so a folder that has never been opened still lists its "General"). Kept as a map so the
// Section dropdown can follow the chosen folder.
$remFolders = folders_load($cfg['data_dir'])['reminders'];
$remDef     = folder_default_get($cfg['data_dir'], 'reminders');
if (!in_array($remDef, $remFolders, true)) { $remDef = $remFolders[0] ?? folder_fallback('reminders'); }
$myRem      = sections_normalize(reminders_folder_migrate(store_read(user_data_file($cfg['data_dir'], 'reminders'))), $remFolders);
$remSecs    = [];   // folder => [[value, label], …]
foreach ($remFolders as $mf) {
    $remSecs[$mf] = [];
    foreach ($myRem as $it) {
        if (($it['type'] ?? '') === 'section' && ($it['folder'] ?? '') === $mf) {
            $nm = (string) ($it['name'] ?? '');
            if ($nm !== '') { $remSecs[$mf][] = [$nm, $nm]; }
        }
    }
}

// The same thing for notes, which have their own folders.
$noteFolders = folders_load($cfg['data_dir'])['notes'];
$noteDef     = folder_default_get($cfg['data_dir'], 'notes');
if (!in_array($noteDef, $noteFolders, true)) { $noteDef = $noteFolders[0] ?? FOLDER_DEFAULT; }
$myNotes     = sections_normalize(store_read(user_data_file($cfg['data_dir'], 'notes')), $noteFolders);
$noteSecs    = [];
foreach ($noteFolders as $nf) {
    $noteSecs[$nf] = [];
    foreach ($myNotes as $it) {
        if (($it['type'] ?? '') === 'section' && ($it['folder'] ?? FOLDER_DEFAULT) === $nf) {
            $nm = (string) ($it['name'] ?? '');
            if ($nm !== '') { $noteSecs[$nf][] = [$nm, $nm]; }
        }
    }
}

// The default section within each app's default folder, so the Section dropdown opens on
// the same spot the Manage-folders "Default for new items" picker names. Validated to a
// real section, else the folder's first.
$remDefSecRaw  = folder_default_section_get($cfg['data_dir'], 'reminders');
$remDefSec     = in_array($remDefSecRaw, array_column($remSecs[$remDef] ?? [], 0), true)
    ? $remDefSecRaw : (string) ($remSecs[$remDef][0][0] ?? '');
$noteDefSecRaw = folder_default_section_get($cfg['data_dir'], 'notes');
$noteDefSec    = in_array($noteDefSecRaw, array_column($noteSecs[$noteDef] ?? [], 0), true)
    ? $noteDefSecRaw : (string) ($noteSecs[$noteDef][0][0] ?? '');

// And the calendars an event can land in.
$calList = add_calendars($cfg);
$calDef  = add_default_cal($cfg, array_column($calList, 'id'));
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>Add</title>
  <meta name="theme-color" content="<?= e(theme_bg()) ?>">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black">
  <meta name="apple-mobile-web-app-title" content="Add">
  <style>
    <?= kind_color_css() ?>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: system-ui, sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; }
    <?= tabbar_styles() ?>
    <?= chrome_styles() ?>
    /* Same top bar as every other app: back + name on the left, username on the right,
       a rule under the lot (the rule comes from chrome_styles' header border). */
    header { display: flex; align-items: center; justify-content: space-between; }
    header h1 { font-size: 1.35rem; }
    header .titlebar { display: flex; align-items: center; gap: 0.85rem; }
    .wrap { width: 100%; max-width: 460px; margin: 0 auto; padding: 1.5rem 1rem 5rem; }
    .addhead { font-size: 0.85rem; color: var(--muted); margin: 0.5rem 0 0; }
    .flash { background: #14251f; border: 1px solid var(--accent); color: var(--accent); border-radius: 8px;
      padding: 0.55rem 0.8rem; font-size: 0.9rem; margin: 0.75rem 0; }
    .bar input[type=text] { width: 100%; padding: 0.85rem 0.9rem; background: var(--surface); border: 1px solid var(--line);
      border-radius: 10px; color: var(--text); font-size: 16px; margin: 1rem 0; }
    .bar input[type=text]:focus { outline: none; border-color: var(--muted); }
    /* The type selector: three toggles, one active at a time. Picking one doesn't submit —
       Done does — so it reads as choosing a kind, not firing three separate actions. */
    .btns { display: flex; gap: 0.6rem; }
    .qb { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 0.25rem;
      padding: 0.9rem 0.5rem; border: 1px solid var(--line); border-radius: 12px; font-size: 0.9rem; font-weight: 600;
      cursor: pointer; background: var(--surface); font-family: inherit; color: var(--muted); }
    .qb :first-child { font-size: 1.6rem; line-height: 1; }
    .qb.sel.rem { color: var(--k-reminder); border-color: var(--k-reminder); background: var(--k-reminder-bg); }
    .qb.sel.evt { color: var(--k-event); border-color: var(--k-event); background: var(--k-event-bg); }
    .qb.sel.note { color: var(--k-note); border-color: var(--k-note); background: var(--k-note-bg); }
    /* A pill toggle that reveals a hidden panel, the same shape as "+ Date/Time". */
    .revbtn { margin-top: 0.9rem; padding: 0.5rem 0.9rem; background: none; border: 1px solid var(--line);
      color: var(--text-dim); border-radius: 999px; font-size: 0.9rem; font-family: inherit; cursor: pointer; }
    .revbtn:hover { border-color: var(--muted); color: #fff; }
    .revrow[hidden], .revbtn[hidden] { display: none; }
    .revrow { display: flex; gap: 0.75rem; margin-top: 0.9rem; }
    .revrow label { flex: 1; display: flex; flex-direction: column; gap: 0.3rem; font-size: 0.78rem; color: var(--muted); }
    .revrow select, .revrow input { padding: 0.6rem 0.7rem; background: var(--surface); border: 1px solid var(--line);
      border-radius: 8px; color: var(--text); font-size: 16px; font-family: inherit; }
    .revrow select:focus, .revrow input:focus { outline: none; border-color: var(--muted); }
    /* Repeat row: the "every N" count sits narrow on the left, the unit fills the rest, both
       on one line so the two read as a single control (the count aligned to the selector). */
    .rprow .rpnum { flex: 0 0 auto; }
    .rprow .rpnum input { width: 4.5rem; }
    .rprow .rpunit { flex: 1; }
    /* Done: the primary action, green, sitting under the options and above the syntax notes. */
    .donerow { margin-top: 1.25rem; }
    .donebtn { width: 100%; padding: 0.85rem; background: var(--accent); color: var(--accent-ink);
      border: none; border-radius: 10px; font-size: 1rem; font-weight: 700; font-family: inherit; cursor: pointer; }
    .donebtn:hover { filter: brightness(1.1); }
    .syntax { margin-top: 1.5rem; color: var(--muted); font-size: 0.82rem; }
    .syntax .shead { color: var(--muted); margin-bottom: 0.4rem; }
    .syntax ul { list-style: none; margin: 0 0 0.9rem; padding: 0; }
    .syntax li { padding: 0.2rem 0; }
    .syntax li::before { content: '·'; color: var(--muted); margin-right: 0.5rem; }
    .syntax b { color: var(--text-dim); font-weight: 600; }
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

    <?php // Where it lands. Each type has its own answer — a reminder has a folder and a
          // section, an event has a calendar, a note has its own folders and sections —
          // so there are three panels behind one pill, and the type picks which. Styled
          // and revealed like the + Date/Time panel below. ?>
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
    <div class="revrow" id="calRow" hidden>
      <label>Calendar
        <select name="cal" id="fCal">
          <?php foreach ($calList as $c): ?>
            <option value="<?= e($c['id']) ?>"<?= $c['id'] === $calDef ? ' selected' : '' ?>><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <div class="revrow" id="nfsRow" hidden>
      <label>Folder
        <select name="nfolder" id="nFolder">
          <?php foreach ($noteFolders as $nf): ?>
            <option value="<?= e($nf) ?>"<?= $nf === $noteDef ? ' selected' : '' ?>><?= e($nf) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Section
        <select name="nsection" id="nSection"></select>
      </label>
    </div>

    <?php // Optional explicit date/time, hidden until asked for; either wins over what the
          // text parses to. An event with no date at all is still filed today. ?>
    <button type="button" class="revbtn" id="dtToggle">+ Date/Time</button>
    <div class="revrow" id="dtRow" hidden>
      <label>Date <input type="date" name="date"></label>
      <label>Time <input type="time" name="time"></label>
    </div>

    <?php // Optional repeat (reminders and events only — notes don't repeat): "every N unit".
          // The count sits left of the unit, both on one line so they read as one control. ?>
    <button type="button" class="revbtn" id="rpToggle">+ Repeat</button>
    <div class="revrow rprow" id="rpRow" hidden>
      <label class="rpnum">Every <input type="number" name="repeat_n" min="1" max="99" value="1" inputmode="numeric"></label>
      <label class="rpunit">Unit
        <select name="repeat_unit">
          <option value="">— (once)</option>
          <option value="day">days</option>
          <option value="week">weeks</option>
          <option value="month">months</option>
          <option value="year">years</option>
        </select>
      </label>
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
    // + Date/Time is its own pill and row; the destination pill below is shared by three.
    (function(){
      var b=document.getElementById('dtToggle'), r=document.getElementById('dtRow');
      if(b&&r){ b.addEventListener('click',function(){ r.hidden=false; b.hidden=true;
        var el=r.querySelector('select,input'); if(el) el.focus(); }); }
    })();
    // + Repeat: its own pill and row, opened the same way. It stays open across type
    // changes, but the whole control hides for notes (a note never repeats).
    var rpOpen=false;
    (function(){
      var b=document.getElementById('rpToggle'), r=document.getElementById('rpRow');
      if(b&&r){ b.addEventListener('click',function(){ rpOpen=true; r.hidden=false; b.hidden=true;
        var el=r.querySelector('input'); if(el) el.focus(); }); }
    })();
    function paintRepeat(){
      var isNote=act.value==='add_note';
      var b=document.getElementById('rpToggle'), r=document.getElementById('rpRow'), u=document.querySelector('select[name=repeat_unit]');
      if(isNote){ if(b) b.hidden=true; if(r) r.hidden=true; if(u) u.value=''; }   // notes never repeat
      else { if(r) r.hidden=!rpOpen; if(b) b.hidden=rpOpen; }
    }

    var REM_SECS  = <?= json_encode($remSecs) ?>;
    var NOTE_SECS = <?= json_encode($noteSecs) ?>;
    // Per type: the row it reveals, and what the pill that reveals it says.
    var DEST = {
      add_reminder: { row: 'fsRow',  label: '+ Folder/Section' },
      add_event:    { row: 'calRow', label: '+ Calendar' },
      add_note:     { row: 'nfsRow', label: '+ Folder/Section' }
    };
    var act=document.getElementById('actField'), sel=document.getElementById('typeSel');
    var fsToggle=document.getElementById('fsToggle');
    var open=false;   // whether the destination panel is showing, kept across type changes

    function paint(){
      var d=DEST[act.value]||DEST.add_reminder;
      ['fsRow','calRow','nfsRow'].forEach(function(id){
        var r=document.getElementById(id); if(r) r.hidden=(id!==d.row)||!open;
      });
      fsToggle.textContent=d.label;
      fsToggle.hidden=open;
    }
    fsToggle.addEventListener('click',function(){
      open=true; paint();
      var d=DEST[act.value]||DEST.add_reminder, r=document.getElementById(d.row);
      var el=r&&r.querySelector('select'); if(el) el.focus();
    });

    // Type selector: highlight the picked one, set the hidden action, and swap which
    // destination panel the pill controls. A calendar has no sections, so the shape of
    // the panel changes with the type rather than three pills stacking up.
    function setType(a){
      act.value=a;
      [].forEach.call(sel.querySelectorAll('.qb'),function(q){ q.classList.toggle('sel',q.dataset.act===a); });
      paint();
      paintRepeat();
    }
    sel.addEventListener('click',function(e){ var q=e.target.closest('.qb'); if(q) setType(q.dataset.act); });

    // Each Section dropdown follows its own Folder dropdown. On the default folder it opens
    // on the stored default section (matching the Manage-folders picker); on any other
    // folder it just shows that folder's first section.
    function wireSecs(folderId, sectionId, map, fallback, defFolder, defSec){
      var f=document.getElementById(folderId), s=document.getElementById(sectionId);
      if(!f||!s) return;
      var fill=function(){
        var opts=map[f.value]||[['',fallback]];
        s.innerHTML='';
        opts.forEach(function(o){ var op=document.createElement('option'); op.value=o[0]; op.textContent=o[1]; s.appendChild(op); });
        if(f.value===defFolder && defSec){ s.value=defSec; }
      };
      f.addEventListener('change',fill); fill();
    }
    wireSecs('fFolder','fSection',REM_SECS,'General',<?= json_encode($remDef) ?>,<?= json_encode($remDefSec) ?>);
    wireSecs('nFolder','nSection',NOTE_SECS,'General',<?= json_encode($noteDef) ?>,<?= json_encode($noteDefSec) ?>);
    paint();
    paintRepeat();
  })();</script>
</div>
<?php render_tabbar('add'); ?>
<?= chrome_script() ?>
</body>
</html>
