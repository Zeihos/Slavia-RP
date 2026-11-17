#!/usr/bin/env bash
# Uses fresh isolated copies. No real users, passwords or production files touched.
set -euo pipefail
cd "$(dirname "$0")/.."
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
mkdir -p "$tmp/site/private/data" "$tmp/wiki/app" "$tmp/forum/app"
cp "Сайт/private/core.php" "$tmp/site/private/core.php"
cp "Вики/app/core.php" "Вики/app/config.php" "Вики/app/install_key.php" "$tmp/wiki/app/"
cp "Форум/app/core.php" "$tmp/forum/app/core.php"
php tests/regression_site.php "$tmp/site/private/core.php"
php tests/regression_wiki.php "$tmp/wiki/app/core.php"
php tests/regression_forum.php "$tmp/forum/app/core.php"
