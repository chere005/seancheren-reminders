# websitetest — seancheren.com

A small **multi-user app suite** (Reminders · Calendar · Notes · Chat) written in
plain PHP, hosted on [NearlyFreeSpeech.NET](https://nearlyfreespeech.net), and
edited/tested locally on the Mac. No framework, no build step, no database —
just PHP files and JSON on disk.

---

## Structure

```
public/                → the web root   (maps to NFSN /home/public)
  index.php              homepage
  dev/                   login-gated p5.js sketch
  reminders/index.php    Reminders app   (folders, sections, due dates, sort)
  notes/index.php        Notes app       (list -> editor, folders, sections)
  calendar/index.php     Calendar        (events, reminders, notes by day)
  chat/index.php         public chat     (NO login — anyone can post)
  */icon-*.png, */manifest.webmanifest   home-screen (PWA) assets

lib/                   → shared code, NOT web-served  (maps to /home/protected/lib)
  auth.php               session login + multi-user helpers
  folders.php            per-user folders + folder/section nav UI
  tabbar.php             bottom tab bar (Reminders / Calendar / Notes)
  config.php             credentials + secrets   ← gitignored, never deployed
  config.sample.php      template for config.php

data/                  → JSON storage, NOT web-served  (maps to /home/protected/data)
  reminders-<user>.json  notes-<user>.json  events-<user>.json
  folders-<user>.json    chat.json (shared)
                         ← gitignored; the SERVER copy is the real live data

deploy.sh              → one-command deploy (Mac -> server)
CONNECT.md            → how to resume the Claude Code session from your phone
```

**Golden rule:** anything under `public/` is reachable by a URL; `lib/` and
`data/` are not. Credentials and everyone's data live outside the web root.

## The apps

| App | Login | Storage | Highlights |
|-----|-------|---------|------------|
| Reminders | yes | `reminders-<user>.json` | folders (filter) + sections (bold groups), optional due date, Date/Name sort, clear-completed |
| Notes | yes | `notes-<user>.json` | list view → editor, folders + sections, title/date/body |
| Calendar | yes | reads reminders + notes + `events-<user>.json` | month grid, per-day panel, quick-add (Event/Reminder/Note), check reminders off |
| Chat | **no** | `chat.json` (shared) | public message board, live AJAX feed |

## Auth & data model

- **Users** are defined in `lib/config.php` as a `username => password` map. Add or
  remove people by editing that file. Login is a PHP session (`$_SESSION['user']`).
- Each user gets **their own data files** (`reminders-alice.json`, …), so people
  don't see each other's items. Chat is the deliberate exception (shared + public).
- **Folders** are a per-user filter (chips). **Sections** are bold headers that group
  items within a list; both reminders and notes support them.
- Everything is JSON on disk with `flock` on write. No database needed.

## Run & test locally

```sh
php -S 127.0.0.1:8787 -t public       # start a local server
```

- Reminders → http://127.0.0.1:8787/reminders/
- Notes     → http://127.0.0.1:8787/notes/
- Calendar  → http://127.0.0.1:8787/calendar/
- Chat      → http://127.0.0.1:8787/chat/

Log in with a user from `lib/config.php` (default `admin` / `changeme`).
Local test data is written to `./data/` and is completely separate from the
live site's data.

Quick syntax check of everything:

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
'users' => [ 'admin' => '…', 'jacob' => '…' ],
'nfsn_member_password' => '…',   // NearlyFreeSpeech account password
'nfsn_api_key'         => '…',   // NFSN control panel -> Profile -> API key
```
