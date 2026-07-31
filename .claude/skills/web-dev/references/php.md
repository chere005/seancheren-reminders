# PHP reference

Practical notes for framework-free procedural PHP (PHP 8.1+). Assumes the house
style in `SKILL.md`.

## Request lifecycle in a self-posting page

A single file typically does, in order:

1. Locate `lib/` and require the preamble (auth, store, util) — see CLAUDE.md.
2. `require_login('Area')` — starts the session, seeds CSRF, gates access.
3. If `REQUEST_METHOD === 'POST'`: verify CSRF, dispatch on `$_POST['action']`,
   mutate storage, then **either** `header('Location: …'); exit;` (normal) **or**
   `header('Content-Type: application/json'); echo json_encode($state); exit;`
   (when `X-Requested-With` is `XMLHttpRequest`).
4. Otherwise render the page (HTML + inline CSS/JS).

Keeping mutation above render means the page never renders a stale view after a
write it just performed.

## Superglobals & input

- `$_GET`, `$_POST`, `$_SERVER`, `$_SESSION`, `$_COOKIE`, `$_FILES`. Treat all of
  them as attacker-controlled except `$_SESSION` (server-side).
- `$_SERVER['REQUEST_METHOD']`, `['REQUEST_URI']`, `['HTTP_X_REQUESTED_WITH']`,
  `['DOCUMENT_ROOT']`. On NFS.NET also `$_SERVER['NFSN_SITE_ROOT']`.
- Read with a default and coerce type: `$id = (string)($_POST['id'] ?? '');`
  then validate against the known set. Never `extract()` request data.
- `filter_var($email, FILTER_VALIDATE_EMAIL)` for emails; for everything else,
  whitelist explicitly.

## Sessions & cookies

- `session_start()` before any output. This repo centralizes it in
  `session_boot()` — go through that, don't call `session_start()` ad hoc.
- Regenerate the id on privilege change: `session_regenerate_id(true)` after
  login, to prevent fixation.
- Long-lived login needs **both** a long cookie lifetime **and** a long
  `session.gc_maxlifetime` — a valid cookie pointing at a garbage-collected
  session file is still a logout. (CLAUDE.md's auth notes hinge on this.)
- Set cookies `HttpOnly`, `Secure`, and `SameSite=Lax` (or `Strict`) unless a
  flow needs otherwise.

## Headers & redirects

- Any `header()` / `setcookie()` must precede all output — even one stray space
  before `<?php` sends headers. Prefer no closing `?>` at end of PHP-only files.
- POST→redirect→GET: `header('Location: '.$url, true, 303); exit;`. Always
  `exit` after a redirect so the rest of the script doesn't run.
- JSON: `header('Content-Type: application/json'); echo json_encode($x); exit;`.

## Strings, escaping, encoding

- HTML: `htmlspecialchars($s, ENT_QUOTES, 'UTF-8')` — the repo wraps this as
  `e()`. Use `ENT_QUOTES` so single quotes in attributes are covered.
- Into JS: `json_encode($s)` (also escapes `</script>` as `<\/script>`).
- Into a URL query: `rawurlencode()`. Into a shell arg (rare here):
  `escapeshellarg()` — but avoid shelling out on user input entirely.
- Compare secrets with `hash_equals($known, $given)`, never `==` (timing +
  PHP's loose `==` type-juggling, e.g. `"0e123" == "0e456"`). Use `===` for
  ordinary comparisons; loose `==` bites on `0 == "abc"` quirks across versions.

## Files & storage

- Never `file_get_contents()` a data file directly in this repo — go through
  `store_read()` / `store_write()` (they encrypt). See CLAUDE.md.
- For raw file work elsewhere: write to a temp file then `rename()` (atomic on
  the same filesystem) instead of truncating in place, so a crash mid-write
  can't leave a half file. Use `flock()` if two requests may write at once —
  JSON-file storage has no transaction; concurrent writers can clobber.
- Building paths from input is a path-traversal risk: reject anything with `/`,
  `\`, or `..`; prefer looking an id up in a known map over interpolating it.
  This repo's `user_data_file()` derives the path — reuse it.

## Errors

- Production: `ini_set('display_errors','0')`, `error_reporting(E_ALL)`, log to
  file. Never echo the message to the browser.
- Wrap risky calls (JSON decode, DB) and handle failure explicitly:
  `json_decode($s, true)` returns `null` on bad input — check it. PDO in
  `ERRMODE_EXCEPTION` throws — catch where you can recover, let it 500 otherwise.
- `??` for missing keys, `?->` for maybe-null objects, `match(true)` for clean
  dispatch. These are PHP 8 idioms worth using.

## Common footguns

- `array_merge` renumbers integer keys; use `+` to preserve them.
- `empty('0')` is `true` and `isset($a['k'])` is `false` when the value is
  literally `null` — pick the test that matches your intent.
- `foreach ($a as &$v)` leaves `$v` dangling as a reference — `unset($v)` after.
- Floats for money are wrong; use integer cents.
- `strtotime('+1 month')` slides Jan 31 → Mar 3; clamp the day yourself (this
  repo's repeat logic does exactly this).
