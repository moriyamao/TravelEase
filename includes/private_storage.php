<?php
/** Supabase private Storage, server-side only. Never log keys, paths or bodies. */
function private_storage_config(): array
{
    $base = rtrim(getenv('SUPABASE_URL') ?: '', '/');
    $key = getenv('SUPABASE_SECRET_KEY') ?: '';
    $bucket = getenv('SUPABASE_STORAGE_BUCKET') ?: 'travelease-documents';
    $hosted = preg_match('#^https://[a-z0-9-]+\.supabase\.co$#D', $base) === 1;
    // A local stub is permitted only in local PHP runtimes, never production.
    $local = in_array(PHP_SAPI, ['cli', 'cli-server'], true)
        && getenv('APP_ENV') === 'development'
        && preg_match('#^http://127\.0\.0\.1:[0-9]{2,5}$#D', $base) === 1;
    if ((!$hosted && !$local) || !preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/D', $bucket)
        || !preg_match('/^sb_secret_[a-zA-Z0-9_-]+$/D', $key)) {
        throw new RuntimeException('Private document storage is not configured.');
    }
    return [$base, $key, $bucket];
}

function private_storage_request(string $method, ?string $objectKey, string $body = '', string $mime = 'application/json'): string
{
    [$base, $key, $bucket] = private_storage_config();
    if ($objectKey !== null && !preg_match('/^[a-f0-9]{64}$/D', $objectKey)) {
        throw new RuntimeException('Invalid private storage object.');
    }
    $path = '/storage/v1/object/' . rawurlencode($bucket);
    if ($objectKey === null && $method === 'GET') {
        $path = '/storage/v1/bucket/' . rawurlencode($bucket);
    } elseif ($method === 'DELETE') {
        $body = json_encode(['prefixes' => [$objectKey]], JSON_THROW_ON_ERROR);
    } else {
        if ($method === 'GET') { $path = '/storage/v1/object/authenticated/' . rawurlencode($bucket); }
        $path .= '/' . $objectKey;
    }
    $response = '';
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['apikey: ' . $key, 'Content-Type: ' . $mime, 'x-upsert: false'],
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_PROTOCOLS => str_starts_with($base, 'https:') ? CURLPROTO_HTTPS : CURLPROTO_HTTP,
        CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$response): int {
            // Downloads and provider error responses are always memory bounded.
            if (strlen($response) + strlen($chunk) > 2097152) { return 0; }
            $response .= $chunk;
            return strlen($chunk);
        },
    ]);
    if ($method !== 'GET') { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
    $ok = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($ok === false || $status < 200 || $status >= 300) {
        throw new RuntimeException('Private storage is unavailable. Please try again.');
    }
    return $response;
}

/** No signed/public URLs: every retrieval must pass the PHP permission guard. */
function private_storage_upload(string $key, string $bytes, string $mime): void
{
    $bucket = json_decode(private_storage_request('GET', null), true);
    if (!is_array($bucket) || ($bucket['public'] ?? null) !== false) {
        throw new RuntimeException('Document storage must use a private bucket.');
    }
    private_storage_request('POST', $key, $bytes, $mime);
}

function private_storage_download(string $key): string
{
    return private_storage_request('GET', $key);
}

function private_storage_delete(string $key): void
{
    // Batch deletion is idempotent, including objects absent after a failed upload.
    private_storage_request('DELETE', $key);
}
