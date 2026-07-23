<?php
/**
 * Encrypted-at-rest JSON storage.
 *
 * All app data goes through store_read()/store_write(). Files are written as
 * AES-256-CBC ciphertext (prefixed "ENC1:") so the raw JSON isn't sitting in
 * plaintext on disk. Reads transparently accept BOTH encrypted files and legacy
 * plaintext JSON, so existing data keeps working and re-encrypts on next write.
 *
 * This is BASIC protection: the key lives alongside the data (config `data_key`,
 * or an auto-generated data/.datakey). Good enough to stop casual reading of
 * other people's files; real per-user security comes later.
 */

/** 32-byte key derived from config `data_key`, or an auto-generated data/.datakey. */
function data_key(): string
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }
    $cfg    = app_config();
    $secret = (string) ($cfg['data_key'] ?? '');
    if ($secret === '') {
        $keyFile = rtrim($cfg['data_dir'], '/') . '/.datakey';
        if (is_file($keyFile)) {
            $secret = trim((string) file_get_contents($keyFile));
        }
        if ($secret === '') {
            $secret = bin2hex(random_bytes(32));
            $dir    = dirname($keyFile);
            if (!is_dir($dir)) { mkdir($dir, 0700, true); }
            file_put_contents($keyFile, $secret, LOCK_EX);
            @chmod($keyFile, 0600);
        }
    }
    $key = hash('sha256', $secret, true);
    return $key;
}

function store_encrypt(string $plain): string
{
    $iv = random_bytes(16);
    $ct = openssl_encrypt($plain, 'aes-256-cbc', data_key(), OPENSSL_RAW_DATA, $iv);
    return 'ENC1:' . base64_encode($iv . $ct);
}

function store_decrypt(string $raw): string
{
    $raw = ltrim($raw);
    if (strncmp($raw, 'ENC1:', 5) !== 0) {
        return $raw;                      // legacy plaintext JSON — return as-is
    }
    $blob = base64_decode(substr(trim($raw), 5), true);
    if ($blob === false || strlen($blob) < 17) {
        return '';
    }
    $pt = openssl_decrypt(substr($blob, 16), 'aes-256-cbc', data_key(), OPENSSL_RAW_DATA, substr($blob, 0, 16));
    return $pt === false ? '' : $pt;
}

/** Read a JSON data file (encrypted or legacy plaintext) into an array. */
function store_read(string $file): array
{
    if (!is_file($file)) {
        return [];
    }
    $data = json_decode(store_decrypt((string) file_get_contents($file)), true);
    return is_array($data) ? $data : [];
}

/** Write an array as an encrypted JSON data file. */
function store_write(string $file, array $data): void
{
    $dir = dirname($file);
    if (!is_dir($dir)) { mkdir($dir, 0700, true); }
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    file_put_contents($file, store_encrypt($json), LOCK_EX);
}
