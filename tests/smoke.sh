#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

php <<'PHP'
<?php
function check_key(string $path, string $key, string $secret): void {
    if (!preg_match('/^[a-f0-9]{64}$/D', $key)) throw new RuntimeException("Unexpected key format: $path");
    if (!is_file($secret)) throw new RuntimeException("Missing per-installation secret: $path");
    if ((require $secret) !== $key) throw new RuntimeException("Saved key mismatch: $path");
    if (fileperms($secret) & 0007) throw new RuntimeException("Secret is world-readable: $path");
    echo "$path: generated installation key OK\n";
}
require 'Сайт/private/config.php';
check_key('Сайт', SLAVIA_INSTALL_KEY, 'Сайт/private/data/.install-key.php');
require 'Вики/app/config.php';
check_key('Вики', WIKI_INSTALL_KEY, 'Вики/storage/.install-key.php');
$key = require 'Форум/app/install_key.php';
check_key('Форум', $key, 'Форум/storage/.install-key.php');
PHP

# Local HTTP tests verify first-run installer pages without creating test users.
pids=()
cleanup() { for pid in "${pids[@]}"; do kill "$pid" 2>/dev/null || true; done; }
trap cleanup EXIT

start_app() {
  local dir="$1" port="$2" uri="$3" phrase="$4"
  php -S "127.0.0.1:$port" -t "$dir" > "/tmp/slavia-php-$port.log" 2>&1 &
  pids+=("$!")
  local response=""
  for _ in {1..40}; do
    if response=$(curl --fail --silent "http://127.0.0.1:$port/$uri"); then break; fi
    sleep 0.15
  done
  if [[ "$response" != *"$phrase"* ]]; then
    echo "HTTP smoke test failed: $dir/$uri" >&2
    cat "/tmp/slavia-php-$port.log" >&2
    exit 1
  fi
  echo "$dir: HTTP installer OK"
}
start_app 'Сайт' 18711 'admin.php' 'ПЕРВОНАЧАЛЬНАЯ УСТАНОВКА'
start_app 'Вики/public' 18712 'install.php' 'Установка SLAVIA Wiki'
start_app 'Форум/public' 18713 'install.php' 'Установка форума'
