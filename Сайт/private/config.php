<?php
// Never commit a real installation key. The loader creates a secret per deployment.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
define('SLAVIA_INSTALL_KEY', require __DIR__ . '/install_key.php');
