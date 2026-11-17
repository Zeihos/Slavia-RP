<?php
// Keep app/ and storage/ outside the web document root.
const WIKI_STORAGE = __DIR__ . '/../storage';
const WIKI_VERSION = '1.0.1';
define('WIKI_INSTALL_KEY', require __DIR__ . '/install_key.php');
