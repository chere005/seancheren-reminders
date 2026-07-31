# Databases on NFS.NET (MySQL / PostgreSQL)

> This site uses no database (encrypted JSON files — see CLAUDE.md). Read this
> only if you're actually adding one. For the PHP/PDO query side, see the
> `web-dev` skill's `references/databases.md`.

## A database is a separate, billed process

On NFS.NET a MySQL or PostgreSQL database is **its own process with its own
resource (RAM) cost**, running continuously — not a free feature of the web
site. Add one only when the data model genuinely needs a server DB.

- **Cheaper alternative for small apps:** SQLite as a plain file in
  `/home/protected` (`pdo_sqlite` is built into PHP) costs **no extra process** —
  the file just sits on disk. Good default until concurrency forces a real DB.

## Creating one

- Add it from the member panel (the account's databases area). You choose the
  engine (**MySQL/MariaDB** or **PostgreSQL**), a name, and a DBA password.
- On creation NFS **emails you the full connection details** — host, database
  name, user, password. Keep that email; it's the source of truth for the DSN.
- The database gets **its own internal hostname** (something like
  `<name>.db`), reachable from your site's processes on the internal network.

## Connecting — never use `localhost`

**The single most important NFS database rule:** never put `localhost` in your
connection info. The database is a *separate host* from your web process, so
`localhost` points at the wrong machine and the connection fails. Always use the
**hostname from the creation email**.

```php
// PostgreSQL (default port 5432)
$pdo = new PDO(
    "pgsql:host={$host};port=5432;dbname={$db}",
    $user, $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// MySQL (default port 3306) — set the charset in the DSN
$pdo = new PDO(
    "mysql:host={$host};port=3306;dbname={$db};charset=utf8mb4",
    $user, $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
```

- Put the credentials in a **non-deployed config** in `/home/protected` (this
  repo keeps secrets in `lib/config.php`, which `deploy.sh` never uploads) — not
  in a file under `/home/public`, and not in git.
- The DB host is only reachable **from within your site's processes**, so you
  can't point a local dev machine straight at it; tunnel over SSH if you need to.

## Backups

- NFS documents doing MySQL backups by scheduling `mysqldump` (Postgres:
  `pg_dump`) — run it from a **scheduled task** (see `scheduled-tasks.md`) and
  write the dump somewhere in `/home` (or pull it down over SSH with a key).
- Since the DB is a paid process, deciding you don't need it later means
  dumping the data out first, then removing the process to stop the RAM charge.
