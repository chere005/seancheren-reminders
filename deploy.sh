#!/usr/bin/env bash
#
# Deploy the site to NearlyFreeSpeech.  One-way:  your Mac  ->  the server.
#
# Three live instances share ONE source tree (no forked copy of the code):
#
#   PRODUCTION      served at  /            public -> /home/public
#                                           lib    -> /home/protected/lib
#                                           data   -> /home/protected/data
#   TEST (sandbox)  served at  /test/       public -> /home/public/test
#                                           lib    -> /home/protected/lib-test
#                                           data   -> /home/protected/data-test
#   DEV (2nd slot)  served at  /dev/        public -> /home/public/dev
#                                           lib    -> /home/protected/lib-dev
#                                           data   -> /home/protected/data-dev
#
# The same PHP serves all three. A page under /test/ or /dev/ loads the matching
# lib-*/, whose config.php sets base=/test or /dev and data_dir accordingly, so each
# mirror is isolated in code, config AND data. Which instance a cross-app link points at
# comes from suite_base() at runtime, never a hand-kept second copy of the code — edit
# once, it works everywhere. DEV exists purely as a second sandbox slot, kept out of
# the way of TEST's own reviewed/ready-to-promote state; it is never part of `promote`.
#
# Usage:
#   ./deploy.sh              deploy the working tree to TEST only        (default; safe)
#   ./deploy.sh test         same
#   ./deploy.sh prod         deploy the working tree straight to PRODUCTION
#   ./deploy.sh both         deploy to TEST *and* PRODUCTION in one go
#   ./deploy.sh dev          deploy the working tree to the DEV sandbox only
#   ./deploy.sh promote      copy the live TEST tree onto PRODUCTION, server-side —
#                            ship exactly what you verified on /test/, no re-upload
#   add --dry-run (or -n) to any of the above to preview and touch nothing
#
# What it NEVER touches, in any mode:
#   - lib/config.php, lib-test/config.php and lib-dev/config.php (each instance
#     keeps its own secrets)
#   - /home/protected/data/, /home/protected/data-test/ and /home/protected/data-dev/
#     (everyone's live data)
#   - and it never uses --delete
#
set -euo pipefail
cd "$(dirname "$0")"

# The deploy target (SSH <USERNAME>@host) names a real login, so it's kept OUT of the repo:
# it lives in a gitignored deploy.conf beside this script. Copy deploy.conf.sample to
# deploy.conf and set HOST, or export SUITE_DEPLOY_HOST. See README ("Reconcile"/"Secrets").
[ -f "$(dirname "$0")/deploy.conf" ] && . "$(dirname "$0")/deploy.conf"
HOST="${HOST:-${SUITE_DEPLOY_HOST:-}}"
if [ -z "$HOST" ]; then
  echo "No deploy target set. Create deploy.conf (gitignored) from deploy.conf.sample with" >&2
  echo "  HOST=<USERNAME>@ssh.<region>.nearlyfreespeech.net   (or export SUITE_DEPLOY_HOST)." >&2
  exit 2
fi
SSH="ssh -o BatchMode=yes"

DRY=""
MODE=""
for arg in "$@"; do
  case "$arg" in
    -n|--dry-run)                 DRY="--dry-run" ;;
    test|prod|both|promote|dev)   MODE="$arg" ;;
    *) echo "Unknown argument: $arg"
       echo "Usage: ./deploy.sh [test|prod|both|promote|dev] [--dry-run]"; exit 2 ;;
  esac
done
MODE="${MODE:-test}"      # a bare deploy is TEST-only, so prod is never hit by accident
[[ -n "$DRY" ]] && echo "──  DRY RUN — nothing will actually change  ──"
echo "==> Mode: $MODE"

# 1. Lint every PHP file first. Both instances run the *same* source, so one pass covers
#    them both. Abort the whole deploy if anything is broken.
echo "==> Linting PHP…"
errors=0
while IFS= read -r f; do
  if ! php -l "$f" >/dev/null 2>&1; then
    echo "    SYNTAX ERROR in $f"; php -l "$f" 2>&1 | tail -1
    errors=1
  fi
done < <(find public lib -name '*.php')
if [[ $errors -ne 0 ]]; then
  echo "Aborting — fix the syntax errors above and try again."
  exit 1
fi
echo "    all PHP OK."

# rsync the source into one instance's public + lib dirs, then make it web-readable
# there. config.php is never sent (each instance keeps its own), data dirs are never in
# these paths, and --delete is never used — so a plain deploy can only ever add/update
# code. openrsync on macOS has no --chmod, so a file left at 0600 is fixed on the server
# (add-only: a+rX never grants write or strips anything, and config.php is skipped).
push_instance() {   # $1 = public dest   $2 = lib dest   $3 = human label
  local pub="$1" lib="$2" label="$3"
  echo "==> [$label] public/ -> $pub/"
  rsync -rlptzv $DRY -e "$SSH" \
    --exclude='.DS_Store' --exclude='*.swp' \
    public/ "$HOST:$pub/"
  echo "==> [$label] lib/    -> $lib/   (config.php protected)"
  rsync -rlptzv $DRY -e "$SSH" \
    --exclude='config.php' --exclude='.DS_Store' --exclude='*.swp' \
    lib/ "$HOST:$lib/"
  if [[ -z "$DRY" ]]; then
    echo "==> [$label] ensuring web-readable perms…"
    $SSH "$HOST" "
      chmod -R a+rX '$pub'
      find '$lib' -type d -exec chmod a+rx {} +
      find '$lib' -type f ! -name config.php -exec chmod a+r {} +
    "
  fi
}

# A sandbox instance gets a STANDALONE config.php — it deliberately does NOT read
# production's. That is the whole point of the separation: its accounts, its session
# cookie and its encryption key are its own, so signing into /test/ or /dev/ has nothing
# to do with signing into the live site, and a sandbox account can't reach real data.
#
# Splitting the configs cannot make existing sandbox data unreadable: no config here sets
# data_key, so every instance already generates its own .datakey inside its own data dir.
#
# A standalone instance starts with no accounts, which would leave no way in — so one
# bootstrap login is generated on the server and printed ONCE. It is stored in plain text
# in that config, the way every account in this suite is. Delete the config and re-run to
# get a fresh one. An older config that still inherits production is reported, not
# rewritten, since replacing it changes who can log in.
ensure_instance_config() {   # $1 = instance name (test|dev)   $2 = link prefix (/test|/dev)
  local name="$1" base="$2" up lib data
  up=$(printf '%s' "$name" | tr '[:lower:]' '[:upper:]')
  lib="/home/protected/lib-$name"
  data="/home/protected/data-$name"
  if [[ -n "$DRY" ]]; then
    echo "==> [$up] would ensure $lib/config.php exists (standalone: own accounts, own session cookie)"
    return 0
  fi
  echo "==> [$up] ensuring $lib/config.php exists…"
  $SSH "$HOST" sh -s -- "$name" "$up" "$base" "$lib" "$data" <<'REMOTE'
set -e
name="$1"; up="$2"; base="$3"; lib="$4"; data="$5"
mkdir -p "$lib"
if [ -f "$lib/config.php" ]; then
  if grep -q '/home/protected/lib/config.php' "$lib/config.php"; then
    echo "    !! $lib/config.php still INHERITS production's config, so this instance"
    echo "    !! shares production's accounts and secrets."
    echo "    !! To give it its own login instead:   rm $lib/config.php"
    echo "    !! then re-run this deploy. Safe: the instance has its own .datakey, so"
    echo "    !! its existing data stays readable."
  else
    echo "    already present (standalone)"
  fi
  exit 0
fi
pw=$(php -r 'echo bin2hex(random_bytes(5));')
cat > "$lib/config.php" <<PHPCONF
<?php
// $up sandbox config — STANDALONE. This instance deliberately does NOT read
// production's config: its accounts, its session cookie name and its encryption key are
// its own, so signing in here is unrelated to signing in on the live site or on the
// other sandbox. Not deployed; created once by deploy.sh. Delete it to reset this
// instance (its data keeps its own .datakey, so the data stays readable either way).
return [
    'users'        => ['$name' => '$pw'],
    'data_dir'     => '$data',
    'base'         => '$base',
    'session_name' => 'SCSESS_$up',
    'timezone'     => 'America/Chicago',
];
PHPCONF
chmod a+r "$lib/config.php"
echo "    created $lib/config.php"
echo "    ----------------------------------------------------------------"
echo "    LOGIN for $base/    user: $name    password: $pw"
echo "    Shown once. Delete that config and re-run to generate a new one."
echo "    ----------------------------------------------------------------"
REMOTE
}

ensure_test_config() { ensure_instance_config test /test; }
ensure_dev_config()  { ensure_instance_config dev  /dev; }

case "$MODE" in
  test)
    ensure_test_config
    push_instance /home/public/test /home/protected/lib-test TEST
    ;;
  prod)
    push_instance /home/public /home/protected/lib PROD
    ;;
  both)
    push_instance /home/public /home/protected/lib PROD
    ensure_test_config
    push_instance /home/public/test /home/protected/lib-test TEST
    ;;
  dev)
    ensure_dev_config
    push_instance /home/public/dev /home/protected/lib-dev DEV
    ;;
  promote)
    # Copy the *live test* tree onto production, entirely on the server, so prod ends up
    # running exactly what you verified on /test/ without re-uploading from the Mac.
    # config.php (both instances), the data dirs and the nested test/ tree are left alone.
    if [[ -n "$DRY" ]]; then
      echo "==> [promote] DRY RUN — would copy, server-side:"
      echo "        /home/public/test/       -> /home/public/          (excl. config.php n/a)"
      echo "        /home/protected/lib-test/ -> /home/protected/lib/   (config.php protected)"
      echo "    Data dirs and both config.php files untouched; no --delete."
    else
      echo "==> [promote] /home/public/test/ -> /home/public/  and  lib-test -> lib (server-side)…"
      $SSH "$HOST" '
        set -e
        rsync -rlpt --exclude=.DS_Store /home/public/test/ /home/public/
        rsync -rlpt --exclude=config.php --exclude=.DS_Store /home/protected/lib-test/ /home/protected/lib/
        chmod -R a+rX /home/public
        find /home/protected/lib -type d -exec chmod a+rx {} +
        find /home/protected/lib -type f ! -name config.php -exec chmod a+r {} +
      '
    fi
    ;;
esac

echo "==> Done ($MODE). Live data in /home/protected/data{,-test,-dev}/ was not touched."
# This must not be the script's last command as a bare `&&` list — on a real run $DRY is
# empty, the test is false, and its exit 1 would become the script's exit code, breaking
# `./deploy.sh && git push`. An `if` returns 0.
if [[ -n "$DRY" ]]; then
  echo "    (that was a dry run — re-run without --dry-run to apply)"
fi
