# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`seancheren.com` — a small multi-user app suite (Reminders · Calendar · Notes · Habits · Chat · Aki's Bookshelf) in plain PHP on NearlyFreeSpeech.NET. No framework, no build step, no database, no dependencies. Each app is essentially one self-contained `index.php` that renders its own HTML/CSS/JS inline and posts back to itself.

## Commands

```sh
php -S 127.0.0.1:8787 -t public          # local server; apps at /reminders/, /calendar/, …
find public lib -name '*.php' -exec php -l {} \;   # lint everything (no test suite exists)
./deploy.sh --dry-run                    # preview the deploy
./deploy.sh                              # lint, then rsync to the live site
```

`deploy.sh` is one-way (Mac → server) and deliberately never sends `lib/config.php`, never touches `/home/protected/data/`, and never uses `--delete`. The Mac is the source of truth; if anything was hand-edited on the server, `rsync` it back down before deploying (see README).

Local login: users come from `lib/config.php` (gitignored; copy `lib/config.sample.php`). Local data lands in `./data/` and is unrelated to live data.

## Layout and the web-root boundary

- `public/` → server `/home/public/`. Anything here is URL-reachable.
- `lib/` → server `/home/protected/lib/`. Shared code, never served.
- `data/` → server `/home/protected/data/`. JSON storage, never served, gitignored.

Every app page starts by locating `lib/` at either `__DIR__ . '/../../lib'` (local) or `/home/protected/lib` (server) — copy that preamble verbatim when adding a page.

## Core mechanics

**Storage.** All data goes through `store_read()` / `store_write()` (`lib/store.php`), which encrypt with AES-256-CBC under an `ENC1:` prefix. Reads transparently accept legacy plaintext JSON and re-encrypt on next write. Never `file_get_contents` a data file directly. Key comes from config `data_key` or an auto-generated `data/.datakey`.

**Per-user files.** `user_data_file($dir, $base, $user = null)` → `data/<base>-<user>.json`, defaulting to the signed-in user. Bases in use: `reminders`, `notes`, `events`, `calendars`, `calprefs`, `folders`, `habits`, `books`, `booknotes`, `shares`. `chat.json` and `token-<user>.json` are handled with hand-built paths.

**Auth.** `require_login('Area')` (`lib/auth.php`) — session-based, plaintext user⇒password map in config, `hash_equals` compare. One session covers the whole suite. Chat is deliberately public (no login). `public/dev/` is a legacy standalone login unrelated to the rest.

**Mutations.** Every write is POST with a CSRF token from `$_SESSION['csrf']`, checked with `hash_equals`, then either a redirect (POST→redirect→GET) or, for AJAX callers, a `json_encode` response. AJAX posts send `X-Requested-With: XMLHttpRequest` and the handler echoes back the fresh authoritative state (e.g. the whole calendar list) rather than a bare `ok`.

**Sharing (sean ⇄ aki only).** `lib/sharing.php`. Nothing is ever copied: `shares-<user>.json` lists which calendar ids and reminder folder names that user exposes, and the reader loads the *owner's* file directly. Shared reminder folders appear in the picker as `@<partner>:<Folder>`; when that view is active, reads *and* writes go to the partner's file. Always re-check membership in the shared list before touching a partner's data.

**Lists with sections.** Reminders, notes, habits and book notes store section headers as rows in the same list (`['type' => 'section', …]`), guarded by an `is_section()`-style helper. List order is display order; drag-reorder posts the new order via AJAX.

**Folders** (`lib/folders.php`) are a separate per-user filter; `General` always exists and is undeletable. Reminders shows them as a dropdown (`render_folder_select`, grouped by owner), Notes as chips (`render_folder_nav`). Adding and removing them happens in `render_folder_modal()` — the window behind the `+` next to the app title — which posts the ordinary `add_folder` / `delete_folder` actions each page already handles, so it's plain POST→redirect, not AJAX. Deleting a folder moves its items back to `General` rather than destroying them.

**Calendars.** Calendars and calendar *sets* share one list, distinguished by `type => 'set'` (`is_calset()`). Events carry a calendar id. `calprefs-<user>.json` holds view prefs: `hidden_folders`, `hidden_shared_folders`, and `default_cal` (which calendar new events land in when you aren't viewing one in particular — always re-validated against the live id list, since the chosen calendar can be deleted). The calendar page reads reminders, notes and events together (`kind_spec()` maps each kind to its file and its text/date field names).

## UI conventions

Dark theme throughout: `#111` background, `#eee` text, `#34d399` accent, pill-shaped controls. Shared chrome comes from `lib/chrome.php` (back button, username menu, Edit toggle) and `lib/tabbar.php` (icon-only bottom bar). Destructive and structural controls (the `+` folder button, new-section fields, delete buttons) are hidden unless `body.editing` is set by the Edit toggle. Inputs use `font-size: 16px` so iOS doesn't zoom on focus. These are iOS home-screen PWAs — respect `env(safe-area-inset-*)`, and note `tabbar.php` intercepts same-origin clicks in `window.navigator.standalone` mode so links stay in the app.

The Calendar's day-panel buttons are the reference for button sizing across the suite: `padding: 0.35rem 0.9rem; font-size: 0.9rem; border-radius: 999px`, green (`#34d399` on `#06251b`, weight 700) for the primary action and outlined (`1px solid #333`/`#444`) for the rest. Manager windows (calendars, folders) share one shape too: `<h2>`, an add row with a green `+`, a list of rows each with an `×`, then a Done button.

## Working here

- Commit granularly and deploy promptly; committing straight to `main` is fine.
- Match the existing style: procedural PHP, short helper functions with one-line docblocks, `e()` for escaping, inline `<style>`/`<script>` in the page.
- `public/akisbookshelf/covers/` is a server-only generated WebP cache and is gitignored — don't expect it locally.
- To exercise a page without credentials, drive it from the CLI: start a session, set `$_SESSION['auth']`/`$_SESSION['user']`, set `$_SERVER['REQUEST_URI']`, then `require` the page. That renders (or POSTs to) it exactly as the server would, and it's the fastest way to check a change end-to-end.
