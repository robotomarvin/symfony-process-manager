#!/usr/bin/env bash

set -euo pipefail

if [ "$(id -u)" = "0" ]; then
    # If /app/vendor is a named volume, it is commonly owned by root on first use.
    # Ensure it is writable by the non-root "app" user before running commands.
    if grep -q " /app/vendor " /proc/self/mountinfo 2>/dev/null; then
        if [ -e /app/vendor ] && ! gosu app test -w /app/vendor; then
            chown -R "$(id -u app):$(id -g app)" /app/vendor
        fi
    fi

    exec gosu app "$@"
fi

exec "$@"
