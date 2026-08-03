#!/bin/sh
# The live usage log, read over SSH — one line per operation (see lib/usagelog.php).
#
#   tools/usagelog.sh                last 50 lines of production's log
#   tools/usagelog.sh -f             follow it live
#   tools/usagelog.sh -n 200         more of it
#   tools/usagelog.sh test           the /test/ instance's log ('dev' likewise)
#   tools/usagelog.sh --real         drop the demo-account noise (example/probe --
#                                    usually Claude verifying something on live)
#
# Modes combine in that order: instance first, then --real, then tail flags —
# e.g. `tools/usagelog.sh test --real -n 200`. The SSH login comes from the
# gitignored deploy.conf (HOST=...) or SUITE_DEPLOY_HOST, exactly like deploy.sh.

set -e
root="$(cd "$(dirname "$0")/.." && pwd)"
[ -f "$root/deploy.conf" ] && . "$root/deploy.conf"
HOST="${HOST:-${SUITE_DEPLOY_HOST:-}}"
if [ -z "$HOST" ]; then
    echo "No deploy target set. Copy deploy.conf.sample to deploy.conf and set HOST." >&2
    exit 1
fi

inst=""
case "$1" in test|dev) inst="-$1"; shift ;; esac
log="/home/protected/data$inst/usage.log"

# Filter after tail, so -f keeps streaming; a real tab is baked into the pattern
# here so no quoting has to survive the trip through the remote shell.
filter="cat"
if [ "$1" = "--real" ]; then
    shift
    tab="$(printf '\t')"
    filter="grep -v -e '${tab}example${tab}' -e '${tab}probe${tab}'"
fi

ssh "$HOST" "tail ${*:--n 50} $log | $filter"
