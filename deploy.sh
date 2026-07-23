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

echo "==> Done. Live data in /home/protected/data/ was not touched."
[[ -n "$DRY" ]] && echo "    (that was a dry run — re-run without --dry-run to apply)"
