#!/usr/bin/env bash
# Packages a clean copy of the app for uploading to a web host.
#
# Uses `git archive`, so the output only ever contains files tracked in git —
# this automatically excludes data/feeds.json, data/auth.json,
# data/items/*.json, data/cron.log, data/cron-stderr.log, and src/config.php
# (all gitignored), regardless of what's sitting on disk in this working
# copy. Your real subscriptions, login, cached articles, and cron secret
# never leave this machine.
#
# Usage: bin/package-for-deploy.sh [output-dir]   (defaults to ./deploy)

set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

if [[ -n "$(git status --porcelain)" ]]; then
    echo "Warning: you have uncommitted changes — they will NOT be included in the package." >&2
fi

out="${1:-deploy}"
rm -rf "$out"
mkdir -p "$out"
# ':!bin' excludes this dev-tooling directory itself, ':!README.md' the dev docs, and
# ':!site' the marketing landing page — none of it is needed on the app server.
git archive HEAD -- . ':!bin' ':!README.md' ':!site' | tar -x -C "$out"

# Stamp the exact commit being archived into the package, since each deploy is an
# independent snapshot on its own host, not a live git checkout. Falls back to a short
# commit hash until you start tagging releases (git tag), then picks those up automatically.
version="$(git describe --tags --always HEAD)"
cat > "$out/src/version.php" <<PHP
<?php
define('APP_VERSION', '$version');
PHP

# config.php itself is gitignored (never committed), so git archive never produces one —
# copy the sample in so the app runs out of the box. CRON_TOKEN is deliberately left blank
# (see the loud reminder below): auto-generating it here would bury a secret the user needs
# to actually see, copy, and keep track of.
cp "$out/src/config.sample.php" "$out/src/config.php"

echo "Packaged a clean copy at ./$out — safe to upload, no personal data included."
echo
echo "src/config.php was created from src/config.sample.php with default settings."
echo
echo "IMPORTANT — if you want scheduled refresh via public/cron.php (Option A in the"
echo "README's \"Scheduled refresh\" section), you must generate a CRON_TOKEN yourself"
echo "and set it in $out/src/config.php before uploading:"
echo "  1. Generate one:  php -r \"echo bin2hex(random_bytes(16)), PHP_EOL;\""
echo "  2. Add near the other define(...) lines in $out/src/config.php:"
echo "       define('CRON_TOKEN', 'paste-the-generated-value-here');"
echo "  3. Store that value somewhere safe (e.g. a password manager) — you'll need it"
echo "     again to build the cron URL, and it can't be recovered from the server."
echo "Leave it blank (as shipped) to keep the endpoint disabled."
echo
echo "Also still needed before it works on the server:"
echo "  - make sure data/ and data/items/ are writable by the web server"
