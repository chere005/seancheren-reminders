<?php
// Locate the shared lib/ — local dev (../../lib) or NFSN (/home/protected/lib).
$__libDir = null;
foreach ([__DIR__ . '/../../lib', '/home/protected/lib'] as $__c) {
    if (is_file($__c . '/auth.php')) { $__libDir = $__c; break; }
}
require_once $__libDir . '/auth.php';
require_once $__libDir . '/tabbar.php';
require_login('Books');

$cfg       = app_config();
$booksFile = user_data_file($cfg['data_dir'], 'books');       // array of book cards
$notesFile = user_data_file($cfg['data_dir'], 'booknotes');   // map: bookId => [notes]
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }

function e(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES); }

function books_load(string $f): array { return store_read($f); }
function books_save(string $f, array $b): void { store_write($f, array_values($b)); }
function bnotes_load(string $f): array { return store_read($f); }        // map keyed by bookId
function bnotes_save(string $f, array $m): void { store_write($f, $m); }

/** Open Library cover URL for a numeric cover id ('S' | 'M' | 'L'). */
function cover_url(?int $id, string $size = 'M'): string
{
    return $id ? "https://covers.openlibrary.org/b/id/{$id}-{$size}.jpg" : '';
}

/** GET a URL with a short timeout (curl, then a file_get_contents fallback). */
function http_get(string $url): string
{
    $ua = 'seancheren-books/1.0 (personal reading list)';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => $ua,
        ]);
        $r = curl_exec($ch);
        curl_close($ch);
        if ($r !== false) { return (string) $r; }
    }
    $ctx = stream_context_create(['http' => ['timeout' => 8, 'header' => "User-Agent: {$ua}\r\n"]]);
    return (string) @file_get_contents($url, false, $ctx);
}

/**
 * Search Open Library — an accepted Goodreads source for covers + book data
 * (help.goodreads.com Librarian Manual). Returns compact matches that HAVE a
 * cover, so the user picks from a list of cover thumbnails.
 */
function ol_search(string $q): array
{
    $q = trim($q);
    if ($q === '') { return []; }
    $url = 'https://openlibrary.org/search.json?q=' . rawurlencode($q)
         . '&fields=key,title,author_name,cover_i,first_publish_year&limit=25';
    $data = json_decode(http_get($url), true);
    $out  = [];
    foreach (($data['docs'] ?? []) as $d) {
        if (empty($d['cover_i'])) { continue; }              // this feature is about covers
        $out[] = [
            'key'    => (string) ($d['key'] ?? ''),
            'title'  => (string) ($d['title'] ?? 'Untitled'),
            'author' => implode(', ', array_slice($d['author_name'] ?? [], 0, 2)),
            'cover'  => (int) $d['cover_i'],
            'year'   => isset($d['first_publish_year']) ? (int) $d['first_publish_year'] : null,
        ];
        if (count($out) >= 15) { break; }
    }
    return $out;
}

// --- AJAX: cover/book search (GET) ---
if (($_GET['action'] ?? '') === 'search') {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'results' => ol_search((string) ($_GET['q'] ?? ''))]);
    exit;
}

// --- Mutations (POST -> redirect -> GET), CSRF protected ---
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action'])) {
    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Bad request (invalid CSRF token).');
    }
    $action  = (string) $_POST['action'];
    $bookId  = (string) ($_POST['book'] ?? '');
    $listUrl = _self_path();
    $bookUrl = $listUrl . '?book=' . urlencode($bookId);

    // ----- Book cards -----
    if ($action === 'add_book') {
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title !== '') {
            $books   = books_load($booksFile);
            $cover   = (int) ($_POST['cover'] ?? 0);
            $books[] = [
                'id'      => bin2hex(random_bytes(6)),
                'title'   => mb_substr($title, 0, 300),
                'author'  => mb_substr(trim((string) ($_POST['author'] ?? '')), 0, 200),
                'cover'   => $cover > 0 ? $cover : null,
                'key'     => mb_substr(trim((string) ($_POST['key'] ?? '')), 0, 60),
                'created' => time(),
            ];
            books_save($booksFile, $books);
        }
        header('Location: ' . $listUrl);
        exit;
    }
    if ($action === 'delete_book') {
        $books = books_load($booksFile);
        foreach ($books as $b) { if (($b['id'] ?? '') === $bookId) { $_SESSION['undo_book'] = $b; break; } }
        $books = array_values(array_filter($books, fn($b) => ($b['id'] ?? '') !== $bookId));
        books_save($booksFile, $books);
        header('Location: ' . $listUrl . '?undo=1');
        exit;
    }
    if ($action === 'undo_book') {
        if (!empty($_SESSION['undo_book'])) {
            $books   = books_load($booksFile);
            $books[] = $_SESSION['undo_book'];
            unset($_SESSION['undo_book']);
            books_save($booksFile, $books);
        }
        header('Location: ' . $listUrl);
        exit;
    }

    // ----- Per-book notes (completely separate from the Notes tab) -----
    $map   = bnotes_load($notesFile);
    $notes = $map[$bookId] ?? [];

    if ($action === 'add_note') {
        $nid     = bin2hex(random_bytes(6));
        $notes[] = ['id' => $nid, 'title' => date('m/d/Y h:i a') . ' - Note', 'body' => '',
                    'created' => time(), 'updated' => time()];
        $map[$bookId] = $notes;
        bnotes_save($notesFile, $map);
        header('Location: ' . $bookUrl . '&note=' . $nid);
        exit;
    }
    if ($action === 'save_note') {
        $nid   = (string) ($_POST['id'] ?? '');
        $title = trim((string) ($_POST['title'] ?? ''));
        $body  = (string) ($_POST['body'] ?? '');
        foreach ($notes as &$n) {
            if (($n['id'] ?? '') === $nid) {
                $n['title']   = $title === '' ? (date('m/d/Y h:i a', (int) ($n['created'] ?? time())) . ' - Note') : mb_substr($title, 0, 200);
                $n['body']    = mb_substr($body, 0, 20000);
                $n['updated'] = time();
                break;
            }
        }
        unset($n);
        $map[$bookId] = $notes;
        bnotes_save($notesFile, $map);
        if (!empty($_POST['ajax'])) {
            $saved = null;
            foreach ($notes as $x) { if (($x['id'] ?? '') === $nid) { $saved = $x; break; } }
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'title' => $saved['title'] ?? '']);
            exit;
        }
        header('Location: ' . $bookUrl . '&note=' . urlencode($nid));
        exit;
    }
    if ($action === 'delete_note') {
        $nid = (string) ($_POST['id'] ?? '');
        foreach ($notes as $x) { if (($x['id'] ?? '') === $nid) { $_SESSION['undo_bnote'] = ['book' => $bookId, 'note' => $x]; break; } }
        $notes = array_values(array_filter($notes, fn($n) => ($n['id'] ?? '') !== $nid));
        $map[$bookId] = $notes;
        bnotes_save($notesFile, $map);
        header('Location: ' . $bookUrl . '&undo=1');
        exit;
    }
    if ($action === 'undo_note') {
        if (!empty($_SESSION['undo_bnote']) && ($_SESSION['undo_bnote']['book'] ?? '') === $bookId) {
            $notes[]      = $_SESSION['undo_bnote']['note'];
            $map[$bookId] = $notes;
            unset($_SESSION['undo_bnote']);
            bnotes_save($notesFile, $map);
        }
        header('Location: ' . $bookUrl);
        exit;
    }

    header('Location: ' . $listUrl);
    exit;
}

// --- Which view? ---
$books  = books_load($booksFile);
$csrf   = htmlspecialchars($_SESSION['csrf'], ENT_QUOTES);

$bookId = (string) ($_GET['book'] ?? '');
$book   = null;
foreach ($books as $b) { if (($b['id'] ?? '') === $bookId) { $book = $b; break; } }

$noteId    = (string) ($_GET['note'] ?? '');
$bookNotes = $book ? (bnotes_load($notesFile)[$bookId] ?? []) : [];
$curNote   = null;
if ($book && $noteId !== '') {
    foreach ($bookNotes as $n) { if (($n['id'] ?? '') === $noteId) { $curNote = $n; break; } }
}
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>Books</title>
  <meta name="theme-color" content="#111111">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black">
  <meta name="apple-mobile-web-app-title" content="Books">
  <link rel="apple-touch-icon" href="/reminders/icon-180.png">
  <link rel="icon" href="/reminders/icon-192.png">
  <link rel="manifest" href="/reminders/manifest.webmanifest">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: system-ui, sans-serif; background: #111; color: #eee; min-height: 100vh; padding: 1.5rem 1rem; }
    .wrap { max-width: 760px; margin: 0 auto; }
    header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.25rem; }
    header h1 { font-size: 1.5rem; }
    header nav { display: flex; align-items: center; gap: 0.5rem; }
    header nav a { color: #888; text-decoration: none; font-size: 0.85rem; }
    header nav a:hover { color: #fff; }
    header nav .who { color: #34d399; font-size: 0.8rem; border: 1px solid #2a4a3d; border-radius: 999px; padding: 0.15rem 0.6rem; }

    /* Top bar */
    .bar { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1.25rem; }
    .bar .addbook {
      margin-left: auto; padding: 0.55rem 1rem; background: #34d399; color: #06251b; border: none;
      border-radius: 8px; font-size: 0.95rem; font-weight: 700; cursor: pointer; white-space: nowrap;
    }
    .bar .addbook:hover { background: #52e0ac; }
    .bar .editbtn {
      padding: 0.55rem 0.95rem; background: #1a1a1a; border: 1px solid #333; color: #ccc;
      border-radius: 8px; font-size: 0.95rem; cursor: pointer;
    }
    .bar .editbtn:hover { border-color: #888; color: #fff; }
    body.editing .bar #editBtn { background: #34d399; border-color: #34d399; color: #06251b; font-weight: 700; }
    .bar #undoBtn { display: none; }
    body.can-undo .bar #undoBtn { display: inline-block; }

    /* Book cards grid */
    .shelf { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 1rem; }
    .bookcard { position: relative; }
    .booklink { display: flex; flex-direction: column; text-decoration: none; color: #eee; }
    .coverbox {
      position: relative; width: 100%; aspect-ratio: 2 / 3; border-radius: 8px; overflow: hidden;
      background: #1b1b1b; border: 1px solid #2a2a2a; display: flex; align-items: center; justify-content: center;
      box-shadow: 0 4px 12px rgba(0,0,0,0.4);
    }
    .coverbox .ph {
      position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
      padding: 0.6rem; text-align: center; font-size: 0.8rem; color: #888; font-weight: 600;
    }
    .coverbox img { position: relative; width: 100%; height: 100%; object-fit: cover; }
    .booklink .btitle { margin-top: 0.5rem; font-size: 0.92rem; font-weight: 600; line-height: 1.25; }
    .booklink .bauthor { margin-top: 0.15rem; font-size: 0.78rem; color: #999; }
    .bookcard .bdel {
      position: absolute; top: 6px; right: 6px; display: none; z-index: 2;
      background: rgba(0,0,0,0.7); border: 1px solid #666; color: #fff; border-radius: 6px;
      width: 26px; height: 26px; font-size: 1rem; line-height: 1; cursor: pointer;
    }
    .bookcard .bdel:hover { border-color: #f66; color: #f66; }
    body.editing .bookcard .bdel { display: block; }

    .empty { color: #666; text-align: center; padding: 2.5rem 0; }
    .empty strong { color: #34d399; }

    /* Search modal */
    .modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.65); z-index: 60; display: none; align-items: flex-start; justify-content: center; padding: 1.2rem 1rem; }
    .modal-backdrop.open { display: flex; }
    .modal { background: #1a1a1a; border: 1px solid #333; border-radius: 12px; width: 100%; max-width: 520px; margin-top: 4vh; max-height: 88vh; display: flex; flex-direction: column; overflow: hidden; }
    .modal .mhead { display: flex; align-items: center; gap: 0.5rem; padding: 1rem 1rem 0.75rem; }
    .modal .mhead h2 { font-size: 1.05rem; flex: 1; }
    .modal .mhead .mclose { background: none; border: none; color: #999; font-size: 1.4rem; line-height: 1; cursor: pointer; }
    .modal .mhead .mclose:hover { color: #fff; }
    .modal .msearch { padding: 0 1rem 0.75rem; }
    .modal .msearch input { width: 100%; padding: 0.6rem 0.75rem; background: #222; border: 1px solid #3a3a3a; border-radius: 8px; color: #eee; font-size: 1rem; }
    .modal .msearch input:focus { outline: none; border-color: #34d399; }
    .results { overflow-y: auto; padding: 0 0.5rem 0.5rem; }
    .results .hint, .results .loading { color: #777; font-size: 0.9rem; text-align: center; padding: 1.5rem 0; }
    .rrow { display: flex; gap: 0.75rem; align-items: center; padding: 0.5rem; border-radius: 8px; cursor: pointer; }
    .rrow:hover { background: #232323; }
    .rrow .rcover { width: 44px; height: 66px; flex: 0 0 auto; border-radius: 4px; object-fit: cover; background: #262626; border: 1px solid #333; }
    .rrow .rmeta { flex: 1; min-width: 0; }
    .rrow .rtitle { font-size: 0.95rem; font-weight: 600; }
    .rrow .rauthor { font-size: 0.8rem; color: #999; margin-top: 0.1rem; }
    .rrow .radd { flex: 0 0 auto; background: #14332a; color: #34d399; border: 1px solid #2a4a3d; border-radius: 999px; padding: 0.3rem 0.7rem; font-size: 0.8rem; font-weight: 700; }

    /* ---- Book notes list ---- */
    .backbar { margin-bottom: 1rem; }
    .backbar a { color: #34d399; text-decoration: none; font-size: 0.9rem; }
    .backbar a:hover { text-decoration: underline; }
    .bookhead { display: flex; gap: 0.9rem; align-items: center; margin-bottom: 1.25rem; }
    .bookhead .coverbox { width: 60px; flex: 0 0 auto; }
    .bookhead .bh-title { font-size: 1.2rem; font-weight: 700; line-height: 1.2; }
    .bookhead .bh-author { font-size: 0.85rem; color: #999; margin-top: 0.2rem; }

    ul.nlist { list-style: none; margin-bottom: 0.5rem; }
    ul.nlist li { border-bottom: 1px solid #222; display: flex; align-items: center; }
    .noteitem { flex: 1; display: flex; align-items: center; gap: 0.6rem; padding: 0.85rem 0.25rem; text-decoration: none; color: #eee; }
    .noteitem:hover { background: #171717; }
    .noteitem .ntitle { flex: 1; font-size: 1.02rem; word-break: break-word; }
    .noteitem .nchev { color: #555; font-size: 1.1rem; }
    .ndel .del { display: none; background: none; border: 1px solid #444; color: #ccc; cursor: pointer; margin-left: 0.5rem; border-radius: 6px; padding: 0.3rem 0.55rem; font-size: 0.95rem; line-height: 1; }
    body.editing .ndel .del { display: inline-block; }
    .ndel .del:hover { border-color: #f66; color: #f66; }

    /* ---- Note editor ---- */
    .editor { display: flex; flex-direction: column; gap: 0.6rem; }
    .editor input[type=text] { padding: 0.6rem 0.75rem; background: #1a1a1a; border: 1px solid #333; border-radius: 6px; color: #eee; font-size: 1.05rem; font-weight: 600; }
    .editor input:focus, .editor textarea:focus { outline: none; border-color: #888; }
    .editor textarea { width: 100%; min-height: 320px; resize: vertical; padding: 0.8rem; background: #1a1a1a; border: 1px solid #333; border-radius: 6px; color: #eee; font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 0.95rem; line-height: 1.5; }
    .editor .actions { display: flex; align-items: center; gap: 0.75rem; }
    .editor .meta { font-size: 0.72rem; color: #666; }
    .editor button.del { margin-left: auto; background: none; border: none; color: #666; font-size: 0.8rem; cursor: pointer; }
    .editor button.del:hover { color: #f66; }
<?= tabbar_styles() ?>
  </style>
</head>
<body>
<div class="wrap">
<?php if (!$book): ?>
  <!-- ===================== BOOKS LIST ===================== -->
  <header>
    <h1>Books</h1>
    <nav>
      <span class="who"><?= e(current_user() ?? '') ?></span>
      <a href="/reminders/?logout">Log out</a>
    </nav>
  </header>

  <div class="bar">
    <button type="button" id="editBtn" class="editbtn">Edit</button>
    <button type="button" id="undoBtn" class="editbtn">Undo</button>
    <button type="button" id="addBookBtn" class="addbook">+ Add book</button>
  </div>

  <?php if (!$books): ?>
    <p class="empty">No books yet. Tap <strong>+ Add book</strong> to search and pick a cover.</p>
  <?php else: ?>
    <div class="shelf">
      <?php foreach ($books as $b): ?>
        <div class="bookcard" data-id="<?= e($b['id']) ?>">
          <a class="booklink" href="?book=<?= urlencode($b['id']) ?>">
            <span class="coverbox">
              <span class="ph"><?= e($b['title'] ?? '') ?></span>
              <?php if (!empty($b['cover'])): ?>
                <img src="<?= e(cover_url((int) $b['cover'], 'M')) ?>" alt="" loading="lazy" onerror="this.remove()">
              <?php endif; ?>
            </span>
            <span class="btitle"><?= e($b['title'] ?? 'Untitled') ?></span>
            <?php if (!empty($b['author'])): ?><span class="bauthor"><?= e($b['author']) ?></span><?php endif; ?>
          </a>
          <form method="post" action="">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="delete_book">
            <input type="hidden" name="book" value="<?= e($b['id']) ?>">
            <button class="bdel" type="submit" title="Remove book">&times;</button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form id="undoForm" method="post" action="" style="display:none">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="undo_book">
  </form>

  <!-- Hidden form that actually adds the chosen search result -->
  <form id="addForm" method="post" action="" style="display:none">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="add_book">
    <input type="hidden" name="title"  id="afTitle">
    <input type="hidden" name="author" id="afAuthor">
    <input type="hidden" name="cover"  id="afCover">
    <input type="hidden" name="key"    id="afKey">
  </form>

  <!-- Search modal -->
  <div class="modal-backdrop" id="searchModal">
    <div class="modal">
      <div class="mhead">
        <h2>Add a book</h2>
        <button type="button" class="mclose" id="mClose">&times;</button>
      </div>
      <div class="msearch">
        <input type="text" id="q" placeholder="Search title or author…" autocomplete="off">
      </div>
      <div class="results" id="results">
        <p class="hint">Type a title or author to find cover matches.</p>
      </div>
    </div>
  </div>

<?php elseif ($book && $curNote === null): ?>
  <!-- ===================== BOOK NOTES LIST ===================== -->
  <div class="backbar"><a href="<?= e(_self_path()) ?>">&larr; All books</a></div>
  <div class="bookhead">
    <span class="coverbox">
      <span class="ph"><?= e($book['title'] ?? '') ?></span>
      <?php if (!empty($book['cover'])): ?>
        <img src="<?= e(cover_url((int) $book['cover'], 'M')) ?>" alt="" onerror="this.remove()">
      <?php endif; ?>
    </span>
    <div>
      <div class="bh-title"><?= e($book['title'] ?? 'Untitled') ?></div>
      <?php if (!empty($book['author'])): ?><div class="bh-author"><?= e($book['author']) ?></div><?php endif; ?>
    </div>
  </div>

  <div class="bar">
    <button type="button" id="editBtn" class="editbtn">Edit</button>
    <button type="button" id="undoBtn" class="editbtn">Undo</button>
    <form method="post" action="" style="margin-left:auto">
      <input type="hidden" name="csrf" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="add_note">
      <input type="hidden" name="book" value="<?= e($book['id']) ?>">
      <button class="addbook" type="submit">+ New note</button>
    </form>
  </div>

  <?php if (!$bookNotes): ?>
    <p class="empty">No notes for this book yet. Tap <strong>+ New note</strong> to start.</p>
  <?php else: ?>
    <?php usort($bookNotes, fn($a, $b) => ($b['updated'] ?? 0) <=> ($a['updated'] ?? 0)); ?>
    <ul class="nlist">
      <?php foreach ($bookNotes as $n): ?>
        <li>
          <a class="noteitem" href="?book=<?= urlencode($book['id']) ?>&amp;note=<?= e($n['id']) ?>">
            <span class="ntitle"><?= e($n['title'] ?? 'Untitled note') ?></span>
            <span class="nchev">&rsaquo;</span>
          </a>
          <form method="post" action="" class="ndel">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="delete_note">
            <input type="hidden" name="book" value="<?= e($book['id']) ?>">
            <input type="hidden" name="id" value="<?= e($n['id']) ?>">
            <button class="del" type="submit" title="Delete note">&times;</button>
          </form>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <form id="undoForm" method="post" action="" style="display:none">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="undo_note">
    <input type="hidden" name="book" value="<?= e($book['id']) ?>">
  </form>

<?php else: ?>
  <!-- ===================== BOOK NOTE EDITOR ===================== -->
  <?php $noteDefault = date('m/d/Y h:i a', (int) ($curNote['created'] ?? time())) . ' - Note'; ?>
  <div class="backbar"><a href="?book=<?= urlencode($book['id']) ?>">&larr; <?= e($book['title'] ?? 'Book') ?></a></div>
  <form class="editor" method="post" action="">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="save_note">
    <input type="hidden" name="book" value="<?= e($book['id']) ?>">
    <input type="hidden" name="id" value="<?= e($curNote['id']) ?>">
    <input type="text" name="title" placeholder="Title" maxlength="200"
           value="<?= e($curNote['title'] ?? '') ?>" data-default="<?= e($noteDefault) ?>">
    <textarea name="body" placeholder="Notes on this book…"><?= e($curNote['body'] ?? '') ?></textarea>
    <div class="actions">
      <span class="meta" id="saveStatus">Saved</span>
      <button class="del" type="submit" name="action" value="delete_note">Delete</button>
    </div>
  </form>
<?php endif; ?>
</div>
<?php render_tabbar('books'); ?>
<script>
  // ---- Undo appears only right after a delete (server redirects with ?undo=1) ----
  const undoBtn = document.getElementById('undoBtn');
  if (undoBtn) undoBtn.addEventListener('click', () => document.getElementById('undoForm').submit());
  if (new URLSearchParams(location.search).get('undo') === '1') {
    document.body.classList.add('can-undo');
    const u = new URL(location.href); u.searchParams.delete('undo');
    history.replaceState(null, '', u);
  }

  // ---- Edit mode (reveals delete controls) ----
  const editBtn = document.getElementById('editBtn');
  if (editBtn) {
    const setEdit = (on) => {
      document.body.classList.toggle('editing', on);
      if (!on) document.body.classList.remove('can-undo');   // tapping Done clears Undo
      editBtn.textContent = on ? 'Done' : 'Edit';
      localStorage.setItem('booksEditing', on ? '1' : '0');
    };
    setEdit(localStorage.getItem('booksEditing') === '1');
    editBtn.addEventListener('click', () => setEdit(!document.body.classList.contains('editing')));
  }
  // Don't navigate into a book while editing (so the × can be tapped).
  document.querySelectorAll('.booklink').forEach(a => {
    a.addEventListener('click', e => { if (document.body.classList.contains('editing')) e.preventDefault(); });
  });

  // ---- Search modal (books list only) ----
  const modal = document.getElementById('searchModal');
  if (modal) {
    const openBtn = document.getElementById('addBookBtn');
    const closeBtn = document.getElementById('mClose');
    const q = document.getElementById('q');
    const results = document.getElementById('results');
    const addForm = document.getElementById('addForm');

    const open = () => { modal.classList.add('open'); setTimeout(() => q.focus(), 30); };
    const close = () => { modal.classList.remove('open'); };
    openBtn.addEventListener('click', open);
    closeBtn.addEventListener('click', close);
    modal.addEventListener('click', e => { if (e.target === modal) close(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });

    const esc = s => (s || '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
    const pick = (r) => {
      document.getElementById('afTitle').value  = r.title || '';
      document.getElementById('afAuthor').value = r.author || '';
      document.getElementById('afCover').value  = r.cover || '';
      document.getElementById('afKey').value    = r.key || '';
      addForm.submit();
    };

    let timer = null, seq = 0;
    const run = () => {
      const term = q.value.trim();
      if (term.length < 2) { results.innerHTML = '<p class="hint">Type a title or author to find cover matches.</p>'; return; }
      results.innerHTML = '<p class="loading">Searching…</p>';
      const mine = ++seq;
      fetch('?action=search&q=' + encodeURIComponent(term))
        .then(r => r.json())
        .then(d => {
          if (mine !== seq) return;   // ignore stale responses
          const list = (d && d.results) || [];
          if (!list.length) { results.innerHTML = '<p class="hint">No covers found. Try a different search.</p>'; return; }
          results.innerHTML = '';
          list.forEach(r => {
            const row = document.createElement('div');
            row.className = 'rrow';
            const cov = r.cover ? 'https://covers.openlibrary.org/b/id/' + r.cover + '-M.jpg' : '';
            row.innerHTML =
              '<img class="rcover" loading="lazy" src="' + cov + '" alt="" onerror="this.style.visibility=\'hidden\'">' +
              '<div class="rmeta"><div class="rtitle">' + esc(r.title) + '</div>' +
              '<div class="rauthor">' + esc(r.author) + (r.year ? ' &middot; ' + r.year : '') + '</div></div>' +
              '<span class="radd">Add</span>';
            row.addEventListener('click', () => pick(r));
            results.appendChild(row);
          });
        })
        .catch(() => { if (mine === seq) results.innerHTML = '<p class="hint">Search failed. Check your connection and try again.</p>'; });
    };
    q.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(run, 350); });
  }

  // ---- Note editor autosave ----
  const noteForm = document.querySelector('form.editor');
  if (noteForm) {
    const titleInput = noteForm.querySelector('input[name=title]');
    const status = document.getElementById('saveStatus');
    const DEF = titleInput ? (titleInput.dataset.default || '') : '';
    if (titleInput) {
      titleInput.addEventListener('focus', () => { if (titleInput.value === DEF) titleInput.select(); });
      titleInput.addEventListener('blur', () => { if (titleInput.value.trim() === '') titleInput.value = DEF; });
      titleInput.addEventListener('keydown', e => { if (e.key === 'Enter') e.preventDefault(); });
    }
    let timer = null;
    const doSave = () => {
      if (status) status.textContent = 'Saving…';
      const fd = new FormData(noteForm);
      fd.set('action', 'save_note'); fd.set('ajax', '1');
      fetch('', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
          if (status) status.textContent = 'Saved';
          if (d && d.title && titleInput && document.activeElement !== titleInput) titleInput.value = d.title;
        })
        .catch(() => { if (status) status.textContent = 'Save failed'; });
    };
    const schedule = () => { if (status) status.textContent = 'Editing…'; clearTimeout(timer); timer = setTimeout(doSave, 800); };
    noteForm.querySelectorAll('input, textarea').forEach(el => el.addEventListener('input', schedule));
    document.addEventListener('visibilitychange', () => { if (document.hidden) { clearTimeout(timer); doSave(); } });
  }
</script>
</body>
</html>
