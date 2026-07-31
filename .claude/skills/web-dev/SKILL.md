---
name: web-dev
description: >-
  Craft and review server-rendered web apps built in plain PHP with vanilla
  JavaScript and SQL (SQLite / PostgreSQL / MySQL) — no framework, no build step.
  Use when writing or reviewing a PHP page, browser JS, an HTML form, or database
  code: covers output escaping, CSRF, sessions, PDO prepared statements,
  POST→redirect→GET, progressive-enhancement AJAX, and the procedural, one-file-
  per-page conventions this codebase follows. Complements CLAUDE.md; defer to it
  on anything project-specific.
---

# Plain-PHP web development

This repo is framework-free procedural PHP: each page is a self-contained
`index.php` that renders its own HTML/CSS/JS inline and posts back to itself.
That is a deliberate style, not a limitation to route around. Write code that a
future reader can follow top-to-bottom without chasing abstractions.

**Read CLAUDE.md first for anything project-specific** (storage encryption,
auth, sharing, the `lib/` preamble, UI conventions). This skill is the general
craft that sits under those rules.

## The rules that prevent bugs and holes

1. **Escape on output, always.** Never echo a variable into HTML raw. Use the
   repo's `e()` (htmlspecialchars). Into a `<script>` use `json_encode` (it
   escapes `</`), into a URL `rawurlencode`, into an attribute still `e()` with
   quotes. Escaping at output — not at input — is the only reliable place.
2. **Parameterize every query.** String-concatenating user input into SQL is the
   #1 way apps get owned. Use PDO prepared statements with bound parameters —
   *always*, even for an integer you "know" is safe. See `references/databases.md`.
3. **Every state change is a POST with a CSRF token**, checked with
   `hash_equals`, then a redirect (POST→redirect→GET) so reload doesn't
   re-submit. GET must be side-effect-free. This repo keeps the token in
   `$_SESSION['csrf']`; mirror that.
4. **Validate and normalize input** before it touches storage: whitelist the
   allowed shape (an id must be in the known set, an enum must be one of N
   values), don't blacklist. Re-check authorization on every request — never
   trust that a hidden field or a prior check still holds.
5. **Fail closed.** On a missing token, unknown id, or unauthorized user, stop
   (403 / redirect), don't guess. Destructive actions require an explicit
   confirm flag server-side (this repo uses `!empty($_POST['confirm'])`).
6. **Never render a secret or a raw error to the client.** `display_errors` off
   in production; log instead. Don't leak stack traces, file paths, or SQL.

## House style to match

- Procedural PHP, short helper functions each with a one-line docblock.
- One page = one file that handles its own GET render *and* POST mutations,
  branching on `$_SERVER['REQUEST_METHOD']` / `$_POST['action']`.
- Inline `<style>`/`<script>` in the page; shared chrome via `lib/*.php`.
- AJAX callers send `X-Requested-With: XMLHttpRequest`; the handler echoes back
  the *fresh authoritative state* as JSON (e.g. the whole list), not a bare `ok`,
  so the client re-renders from truth instead of guessing the new state.
- Progressive enhancement: the form works as a plain POST; JS intercepts to make
  it AJAX. Don't build a flow that only works with JS unless there's no fallback.
- `font-size: 16px` on inputs so iOS doesn't zoom on focus (a repo convention).

## Reference files (read on demand)

- **`references/php.md`** — request lifecycle, sessions/cookies, superglobals,
  headers & redirects, file handling, common PHP footguns, error handling.
- **`references/javascript.md`** — vanilla DOM patterns, event delegation,
  `fetch`, forms/`FormData`, `localStorage`, and the traps (XSS via innerHTML,
  timing, event leaks) that bite frameworkless code.
- **`references/databases.md`** — PDO across SQLite / PostgreSQL / MySQL, choosing
  between them, prepared statements, transactions, migrations, indexing, and
  N+1. (This repo currently uses JSON files, not a DB — see CLAUDE.md storage —
  so reach here only when a DB is actually in play.)

## Before you finish

- Run the tests: `php tools/test.php`. Anything you changed the behaviour of needs its
  case changed in the same commit; anything you added needs one adding. If you fixed a
  bug, write the case that would have caught it *before* you fix it, and watch it fail.
  `TESTING.md` is the map of what the run covers and what only eyes can check — keep it
  in step, since a feature in neither list has nobody looking at it.
- Remember what a green run does **not** cover here: the harness drives real HTTP but
  runs no JavaScript, so gestures, drags, long-presses and anything that only misbehaves
  inside an installed PWA are checked by hand. That is where most real bugs in this repo
  have come from — a click that bubbled somewhere it shouldn't, a handler on `document`
  that fired first, a margin that was symmetric on one axis only.
- Lint: `find public lib -name '*.php' -exec php -l {} \;` — and add `tools` to that
  list when you've touched anything in it. `deploy.sh` only lints what it deploys
  (`public` and `lib`), so a broken script in `tools/` sails past the deploy and only
  shows up when someone runs it, which for the seeders is on the live server.
- Re-read the diff for the six rules above — especially any new `echo` of a
  variable and any new query.
- Drive the page to confirm it works (see CLAUDE.md "Working here": start a
  session in the CLI, set `$_SESSION`, `require` the page).
