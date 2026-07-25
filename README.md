# websitetest — seancheren.com

A small **multi-user app suite** (Reminders · Calendar · Notes · Habits · Chat ·
Aki's Bookshelf) written in plain PHP, hosted on
[NearlyFreeSpeech.NET](https://nearlyfreespeech.net), and edited/tested locally on
the Mac. No framework, no build step, no database — just PHP files and encrypted
JSON on disk.

---

## Structure

```
public/                → the web root   (maps to NFSN /home/public)
  index.php              homepage
  dev/                   login-gated p5.js sketch (its own legacy login)
  reminders/index.php    Reminders app   (folders, sections, due dates, drag order)
  notes/index.php        Notes app       (list -> editor, folders, sections)
  calendar/index.php     Calendar        (multiple calendars, sets, day panel)
  calendar/feed.php      token JSON feed + iOS widget setup page
  calendar/quick.php     one-field quick add, opened by the widget
  habits/index.php       Habits          (7-day grid, sections)
  akisbookshelf/         private bookshelf — only the user "aki" may open it
  chat/index.php         public chat     (NO login — anyone can post)
  */icon-*.png, */manifest.webmanifest   home-screen (PWA) assets

lib/                   → shared code, NOT web-served  (maps to /home/protected/lib)
  auth.php               session login + per-user data-file helpers
  store.php              encrypted-at-rest JSON read/write
  folders.php            per-user folders, the folder nav + folder-manager window
  sharing.php            what sean and aki let each other see
  chrome.php             back button, username menu, Edit toggle
  tabbar.php             bottom tab bar (Reminders / Calendar / Notes / Habits)
  util.php               small shared helpers (time parsing, …)
  config.php             credentials + secrets   ← gitignored, never deployed
  config.sample.php      template for config.php

data/                  → JSON storage, NOT web-served  (maps to /home/protected/data)
  reminders-<user>.json  notes-<user>.json   events-<user>.json
  calendars-<user>.json  calprefs-<user>.json folders-<user>.json
  habits-<user>.json     books-<user>.json    booknotes-<user>.json
  shares-<user>.json     token-<user>.json    chat.json (shared)
                         ← gitignored; the SERVER copy is the real live data

deploy.sh              → one-command deploy (Mac -> server)
CLAUDE.md              → orientation notes for Claude Code
CONNECT.md             → how to resume the Claude Code session from your phone
```

**Golden rule:** anything under `public/` is reachable by a URL; `lib/` and
`data/` are not. Credentials and everyone's data live outside the web root.

## The apps

| App | Login | Storage | Highlights |
|-----|-------|---------|------------|
| Reminders | yes | `reminders-<user>.json` | folders (dropdown) + sections (bold groups), a **+** on each section to add inline, dates and times read out of what you type, a permanent **Calendar** group that rides along on the calendar, inline text edit, Show Completed |
| Notes | yes | `notes-<user>.json` | list view → editor, folders + sections, title/date/body, autosave |
| Calendar | yes | `events-<user>.json` + reminders + notes | month grid, per-day panel, several calendars and calendar sets, per-calendar colours, quick-add window |
| Habits | yes | `habits-<user>.json` | rolling 7-day grid of tick boxes, sections |
| Aki's Bookshelf | yes, **aki only** | `books-<user>.json`, `booknotes-<user>.json` | book cards from Open Library, covers, ratings, shelves, per-book notes with sections |
| Chat | **no** | `chat.json` (shared) | public message board, live AJAX feed |

## Auth & data model

- **Users** are defined in `lib/config.php` as a `username => password` map. Add or
  remove people by editing that file. Login is a PHP session (`$_SESSION['user']`),
  and one login covers the whole suite.
- Each user gets **their own data files** (`reminders-alice.json`, …), so people
  don't see each other's items. Chat is the deliberate exception (shared + public).
- **Everything is encrypted at rest.** All reads and writes go through
  `store_read()` / `store_write()`, which use AES-256-CBC behind an `ENC1:` prefix.
  Legacy plaintext files are still readable and get encrypted on their next write.
- **Folders** are a per-user filter; **sections** are bold headers that group items
  within a list. Both reminders and notes have both. Folders are added, removed and
  given a default in the folder window behind the **+** next to the app title (edit
  mode only); the default is where new items land while you're viewing "All".
- Writes are `POST` + CSRF token, then either a redirect or — for the drag/tick
  style interactions — a JSON reply.
- **Deleting takes two presses.** There is no confirm box and no Undo: the first
  press turns the button red, the second one goes through, and the server refuses
  anything destructive that didn't come from that second press.

## Sharing (sean ⇄ aki)

Nothing is ever copied. `shares-<user>.json` records which of your calendars and
reminder folders the other person may see, and their app reads **your** file
directly. Shared reminder folders show up in the folder dropdown as
`@aki:Groceries`; while one is selected, reads and writes go to the owner's file.
A shared calendar can also be dropped into one of your calendar sets, so a set can
span both people. Un-sharing takes effect immediately — a set that still lists the
calendar simply stops resolving it.
Their sections and folder list stay theirs to arrange. Anyone who isn't in the
sean/aki pair gets no sharing UI at all.

## Run & test locally

```sh
php -S 127.0.0.1:8787 -t public       # start a local server
```

- Reminders → http://127.0.0.1:8787/reminders/
- Calendar  → http://127.0.0.1:8787/calendar/
- Notes     → http://127.0.0.1:8787/notes/
- Habits    → http://127.0.0.1:8787/habits/
- Chat      → http://127.0.0.1:8787/chat/

Log in with a user from `lib/config.php` (default `admin` / `changeme`).
Local test data is written to `./data/` and is completely separate from the
live site's data.

There is no test suite. The check before deploying is a syntax sweep:

```sh
find public lib -name '*.php' -exec php -l {} \;
```

## Deploy

```sh
./deploy.sh              # push to seancheren.com
./deploy.sh --dry-run    # preview exactly what would change, without doing it
```

`deploy.sh` lints all PHP, then `rsync`s **`public/` → `/home/public/`** and
**`lib/` → `/home/protected/lib/`**. It is **one-way (Mac → server)** and:

- never sends `lib/config.php` (the server keeps its own live credentials/secrets),
- never touches `/home/protected/data/` (everyone's real reminders/notes/events),
- never uses `--delete` (it only adds/updates; it won't remove server files).

### Reconcile if you edited on the server

The Mac is the source of truth. If you ever hand-edit files directly on the
server, pull them back down before your next deploy so nothing is overwritten:

```sh
rsync -av <USERNAME>@ssh.nyc1.nearlyfreespeech.net:/home/public/ public/
```

## Secrets

`lib/config.php` is gitignored and never deployed. Put real values there:

```php
'users' => [ 'admin' => '…', 'aki' => '…' ],
'data_key'             => '…',   // key for the at-rest encryption
'nfsn_member_password' => '…',   // NearlyFreeSpeech account password
'nfsn_api_key'         => '…',   // NFSN control panel -> Profile -> API key
```

If `data_key` is left empty a random one is generated into `data/.datakey` on
first use. Keep that file — losing it means losing the data.
