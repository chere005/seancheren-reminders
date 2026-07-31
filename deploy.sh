#!/usr/bin/env bash
#
# Deploy the site to NearlyFreeSpeech.  One-way:  your Mac  ->  the server.
#
#   ./deploy.sh            push for real
#   ./deploy.sh --dry-run  show what WOULD change, touch nothing
#
# What it does NOT touch:
#   - lib/config.php          (live credentials + secrets stay on the server)
#   - /home/protected/data/   (everyone's reminders/notes/events live data)
#
set -euo pipefail
cd "$(dirname "$0")"

HOST="<USERNAME>@ssh.nyc1.nearlyfreespeech.net"
SSH="ssh -o BatchMode=yes"

DRY=""
if [[ "${1:-}" =~ ^(-n|--dry-run)$ ]]; then
  DRY="--dry-run"
  echo "──  DRY RUN — nothing will actually change  ──"
fi

# 1. Lint every PHP file first. Abort the whole deploy if anything is broken.
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

# 2. Web root: everything the browser can reach (index, apps, dev/, chat/, icons).
echo "==> public/  ->  /home/public/"
rsync -rlptzv $DRY -e "$SSH" \
  --exclude='.DS_Store' --exclude='*.swp' \
  public/ "$HOST:/home/public/"

# 3. Shared library, OUTSIDE the web root. config.php is never sent, so the
#    server keeps its own credentials/secrets.
echo "==> lib/     ->  /home/protected/lib/   (config.php protected)"
rsync -rlptzv $DRY -e "$SSH" \
  --exclude='config.php' --exclude='.DS_Store' --exclude='*.swp' \
  lib/ "$HOST:/home/protected/lib/"

# 4. Make the tree web-readable on the SERVER. PHP runs as the `web` user, which
#    owns neither the files nor their group, so it reads them via the "other"
#    bits — a file left at 0600 is unreadable by web and Apache 403s that one
#    page while every sibling serves fine (git tracks only the exec bit, so it
#    never warns, and rsync -p copies a bad local mode up verbatim). openrsync
#    on macOS has no --chmod, so fix it here instead: add-only (a+rX never
#    grants write or strips anything), and never touch config.php.
if [[ -z "$DRY" ]]; then
  echo "==> Ensuring web-readable perms on the server…"
  $SSH "$HOST" '
    chmod -R a+rX /home/public
    find /home/protected/lib -type d -exec chmod a+rx {} +
    find /home/protected/lib -type f ! -name config.php -exec chmod a+r {} +
  '
fi

echo "==> Done. Live data in /home/protected/data/ was not touched."
# Note: this must not be the script's last command as a bare `&& ` list — on a
# real deploy $DRY is empty, the test is false, and its exit 1 would become the
# script's exit code, breaking `./deploy.sh && git push`. An `if` returns 0.
if [[ -n "$DRY" ]]; then
  echo "    (that was a dry run — re-run without --dry-run to apply)"
fi
