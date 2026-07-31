# Databases: SQLite / PostgreSQL / MySQL from PHP

> This repo currently stores everything in encrypted JSON files, not a database
> (see CLAUDE.md "Storage"). Reach for this only when a real DB is actually
> introduced. For NFS.NET-specific setup (adding a DB process, DSN host,
> `never use localhost`), see the `nearlyfreespeech` skill.

## Choosing one

- **SQLite** — a single file, zero server, bundled with PHP (`pdo_sqlite`).
  Perfect for a small single-writer app; on NFS.NET it needs **no paid database
  process** — the file just lives in `/home/protected`. Weakness: one writer at
  a time (write lock); fine at this scale, painful under real concurrency.
- **PostgreSQL** — the richest, strictest engine: real types, `JSONB`, CTEs,
  strong constraints, concurrent writers via MVCC. Default choice when you need
  a server DB. On NFS.NET it's a separate process you pay RAM for.
- **MySQL/MariaDB** — also solid; pick Postgres unless something forces MySQL.

Prefer one uniform access layer (PDO) so switching engines is a DSN change, not
a rewrite.

## PDO: the one right way to query

```php
$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,  // throw on error
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,        // assoc arrays
    PDO::ATTR_EMULATE_PREPARES   => false,                   // real prepares
]);

// SQLite:     new PDO("sqlite:/home/protected/data/app.db")
// PostgreSQL: new PDO("pgsql:host=$host;port=5432;dbname=$db", $user, $pass)
// MySQL:      new PDO("mysql:host=$host;port=3306;dbname=$db;charset=utf8mb4", …)
```

**Always bind parameters — never interpolate input into SQL:**

```php
$stmt = $pdo->prepare('SELECT * FROM notes WHERE user = ? AND id = ?');
$stmt->execute([$user, $id]);
$row = $stmt->fetch();            // false when no row
$all = $stmt->fetchAll();
```

- Placeholders can't be table/column names — validate those against a whitelist
  and interpolate only from that fixed set, never from raw input.
- `ATTR_EMULATE_PREPARES => false` gives real server-side prepares (better
  typing, genuine separation of code and data). Set `charset=utf8mb4` on MySQL
  in the DSN so multibyte input isn't a truncation/injection vector.
- `$pdo->lastInsertId()` after an INSERT (on Postgres, prefer
  `INSERT … RETURNING id`).

## Transactions

Wrap multi-statement changes so a failure can't leave half-applied state:

```php
$pdo->beginTransaction();
try {
    // several execute() calls that must all succeed or all fail
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}
```

## Schema & migrations (no framework)

- Keep an ordered set of plain `.sql` migration files and a tiny runner that
  records which have run in a `schema_migrations` table. Forward-only is fine at
  this scale.
- SQLite: enable `PRAGMA foreign_keys = ON;` on every connection (off by
  default), and `PRAGMA journal_mode = WAL;` for better read/write concurrency.
- Prefer `NOT NULL` + sensible defaults and real foreign keys; let the DB reject
  bad data rather than trusting the app.

## Performance

- **Index the columns you filter and join on.** A query in a loop over an
  unindexed column is the usual cause of a slow page.
- **Kill N+1:** don't run a query per row in a loop — fetch with a JOIN or a
  single `WHERE id IN (…)` (bind each value). One round trip beats N.
- `EXPLAIN` (Postgres/MySQL) / `EXPLAIN QUERY PLAN` (SQLite) to see if an index
  is used.
- Fetch only the columns you need; `SELECT *` drags unused data across.

## Security recap

- Parameterize **every** query, always. This is the whole ballgame for SQLi.
- Least-privilege DB user where the engine supports it.
- Store password *hashes* with `password_hash()` / `password_verify()`, never
  reversible or plain. (This repo's own auth already hashes — mirror it.)
- Don't echo DB errors to the client; log them.
