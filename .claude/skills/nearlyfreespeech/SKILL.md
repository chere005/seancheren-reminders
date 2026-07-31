---
name: nearlyfreespeech
description: >-
  How NearlyFreeSpeech.NET (NFS.NET / NFSN) hosting actually works, so deploys
  and server changes don't surprise you. Covers the FreeBSD /home/{public,
  protected,private,logs,conf,tmp} layout, the per-site `web` user and the
  file-permission gotchas it causes, custom php.ini and PHP versions, automatic
  Let's Encrypt TLS, SSH / rsync / scp deploys, scheduled tasks (cron) and
  daemons, adding a MySQL/PostgreSQL database process, and the pay-as-you-go
  resource billing model. Use when deploying this site, debugging a permission
  error, adding a cron job or database, or reasoning about the live server.
---

# NearlyFreeSpeech.NET (NFS.NET)

A pay-as-you-go, **FreeBSD**-based shared host. No control-panel bloat, no
managed framework — you get a Unix account, a document root, and a member panel.
This site (`seancheren.com`) runs here. Deploy details live in this repo's
`deploy.sh` and `README`; this skill is the platform underneath them.

## Directory layout (everything under `/home`)

| Path | Web-reachable? | What it's for |
|------|----------------|---------------|
| `/home/public` | **Yes** | Document root. Only put here what should be served. `$_SERVER['DOCUMENT_ROOT']` and `$_SERVER['NFSN_SITE_ROOT']` both point here. Legacy `htdocs` is a symlink to it. |
| `/home/protected` | No (not directly downloadable) | Readable by your scripts/daemons but not fetchable over the web. Libraries, config, data files. This repo puts `lib/` and `data/` here. |
| `/home/private` | No (fully inaccessible) | Not reachable by the web server at all; also the **SSH login user's shell home**. Source, dev files. |
| `/home/logs` | No | Access/error logs (when enabled) and overflow of cron output. |
| `/home/conf` | No | Server config: `/home/conf/php.ini` for custom PHP settings, plus daemon/proxy configs. |
| `/home/tmp` | No | Temp files, PHP sessions, in-flight uploads. |

This repo maps `public/ → /home/public`, `lib/ → /home/protected/lib`,
`data/ → /home/protected/data` (see CLAUDE.md's web-root boundary).

## The `web` user — the permission gotcha that bites everyone

The web server (and anything it runs — your PHP, HTTP-triggered scripts) runs as
a per-site **`web` user**. Your **SSH/SFTP login is a *different* user** (the
member/site user). Consequences you must plan around:

- **Files written by PHP are owned by `web`.** A data directory PHP created can
  be `drwx------ web` — so a script you run over SSH (as the login user) hits
  **Permission denied** on every write to it. This repo already learned this:
  see the `deploy-perms-and-seed.md` memory and CLAUDE.md's seeding note.
- **To run a script *as `web`*, run it over HTTP**, not SSH: drop a small guarded
  wrapper in `/home/public`, `curl` it once over HTTPS, then delete it. That's
  the seed-the-demo-account procedure in CLAUDE.md — the failure is *silent*
  (the script prints success even after the writes were denied), so verify by
  the real effect, never by the script's own "done" message.
- A `0600`/`0700` file created by one user **403s / can't be read by the other**.
  If a deploy or a page suddenly can't read a file, suspect ownership first.
- The panel offers "fix permissions" / ownership tools; scheduled tasks can be
  set to run **as the owner or as `web`** — pick `web` when the job touches
  web-created data.

## Deploying (this site is Mac → server, one-way)

- `deploy.sh` here lints then `rsync`s `public/` and `lib/` up. It **never**
  sends `lib/config.php`, never touches `/home/protected/data/`, and never uses
  `--delete`. The Mac is the source of truth.
- If anything was hand-edited on the server, `rsync` it **down** first or the
  next deploy silently reverts it (see README).
- SSH/SCP/rsync/SFTP all work. Login is `<sshuser>_<sitename>` at the SSH
  hostname shown on your Site Information panel (e.g.
  `ssh.<realm>.nearlyfreespeech.net`). Public-key auth is supported and is the
  right way to automate.
- **FreeBSD userland**, not GNU/Linux: BSD `sed`/`awk`/`date` differ from GNU
  (e.g. `sed -i` needs an arg, `date` flags differ). Write portable shell, or
  install GNU tools; don't assume Linux coreutils.

## PHP on NFS

- Available versions: **PHP 8.1, 8.2, 8.3, 8.4**. Switch per-site in the panel
  (Sites → your site → edit the PHP Version line). If unspecified you get
  whatever was default when the site was created — pin it deliberately.
- **Custom PHP settings go in `/home/conf/php.ini`**, parsed *after* the system
  `php.ini` (so your values win). Use it for `date.timezone`, `upload_max_filesize`,
  `session.gc_maxlifetime`, `display_errors=0`, etc. `.htaccess` `php_value`
  lines generally do **not** work here — use `php.ini`.
- The server clock is **UTC**; set the timezone in code or `php.ini` or "today"
  turns over in the evening (this repo sets it in `lib/auth.php`).

## TLS / HTTPS — automatic

Since **2024-05-22** NFS manages TLS for you via **Let's Encrypt**, auto-renewed.
Aliases marked with the 🔁 emoji in the panel are auto-managed — **you do
nothing**. The old `tls-setup.sh` dance and manual cert uploads are legacy;
existing sites were migrated. Just make sure your aliases (apex + `www`) are
present and pointed correctly, and serve over `https://`.

## Billing — pay-as-you-go, keep it lean

You fund a prepaid balance; the account **draws down by actual resource use**
(RAM-hours for server processes, bandwidth, storage) — there's no fixed monthly
plan for basic hosting. Practical implications:

- Plain PHP + static files is cheap. **A database or a persistent daemon is a
  separate always-on process that costs RAM continuously** — don't add one you
  don't need. For a small app, SQLite-as-a-file (in `/home/protected`) or this
  repo's JSON files cost nothing extra.
- Keep an eye on the balance; a site can be suspended if it runs dry.

## Reference files (read on demand)

- **`references/scheduled-tasks.md`** — cron jobs (min every 10 min, run-as
  owner/`web`, output-by-email), and when to use a daemon/proxy instead.
- **`references/databases.md`** — adding a MySQL or PostgreSQL process, the DSN
  hostname, and **why you must never connect to `localhost`**. (For the PHP/PDO
  side, see the `web-dev` skill's `references/databases.md`.)
