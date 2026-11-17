#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
# Do not check in player records, runtime configuration or build outputs.
while IFS= read -r tracked; do
  case "$tracked" in
    Сайт/private/data/*.php|Сайт/private/data/*.json|Форум/storage/*|Вики/storage/*|Игра/scriptfiles/*.db|Игра/scriptfiles/*.sqlite*|Игра/gamemodes/*.amx|.env|*/.env|*.pem|*/.install-key.php)
      echo "Tracked runtime state or secret: $tracked" >&2
      exit 1
      ;;
  esac
done < <(git ls-files)
test -f 'Сайт/private/.htaccess'
grep -Eq 'Require[[:space:]]+all[[:space:]]+denied' 'Сайт/private/.htaccess'
test -f 'Сайт/private/data/.htaccess'
test -f 'Сайт/private/data/.gitkeep'
for file in 'Сайт/index.php' 'Сайт/admin.php' 'Форум/public/index.php' 'Форум/public/install.php' 'Вики/public/index.php' 'Вики/public/install.php' 'Игра/gamemodes/slavia.pwn' 'Игра/pawn.json'; do
  test -f "$file" || { echo "Missing entry point: $file" >&2; exit 1; }
done
echo "Repository structure and private-data checks passed."
