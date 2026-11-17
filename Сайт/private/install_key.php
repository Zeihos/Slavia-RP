<?php
/** Installation secret: generated per deployment and never committed to Git. */
declare(strict_types=1);

$environmentKey = getenv('SLAVIA_SITE_INSTALL_KEY');
if (is_string($environmentKey) && strlen($environmentKey) >= 32) {
    return $environmentKey;
}

$file = __DIR__ . '/data/.install-key.php';
$directory = dirname($file);
if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
    throw new RuntimeException('Cannot create private installation key directory.');
}
$handle = fopen($file, 'c+');
if ($handle === false) {
    throw new RuntimeException('Cannot open private installation key file.');
}
try {
    if (!flock($handle, LOCK_EX)) {
        throw new RuntimeException('Cannot lock private installation key.');
    }
    rewind($handle);
    $content = stream_get_contents($handle);
    if ($content === false) {
        throw new RuntimeException('Cannot read private installation key.');
    }
    if (trim($content) === '') {
        $key = bin2hex(random_bytes(32));
        $data = "<?php return '" . $key . "';\n";
        rewind($handle);
        if (!ftruncate($handle, 0) || fwrite($handle, $data) !== strlen($data) || !fflush($handle)) {
            throw new RuntimeException('Cannot save private installation key.');
        }
        chmod($file, 0600);
        return $key;
    }
    if (!preg_match("~^<\?php return '([a-f0-9]{64})';\s*$~D", $content, $match)) {
        throw new RuntimeException('Invalid private installation key file.');
    }
    return $match[1];
} finally {
    flock($handle, LOCK_UN);
    fclose($handle);
}
