<?php
/**
 * Server-side verification of a Google Identity Services ID token (JWT).
 *
 * This intentionally does NOT trust anything the browser claims about
 * the user. It re-derives the user's verified identity by:
 *   1. Fetching Google's current public signing keys (JWKS)
 *   2. Verifying the JWT's RS256 signature against the matching key
 *   3. Verifying issuer, audience, and expiry claims
 *
 * Only after all of that passes do we trust the token's payload
 * (in particular the 'sub' claim, which is the stable Google account
 * identifier we store).
 *
 * Uses Google's tokeninfo-free JWKS + OpenSSL verification approach,
 * which is the current Google-recommended method and avoids a runtime
 * dependency on Google's rate-limited tokeninfo endpoint.
 */

class GoogleAuthException extends Exception {}

/**
 * Verifies a Google ID token and returns its decoded, trusted payload.
 * Throws GoogleAuthException on any failure -- callers must not treat
 * a caught exception as "logged in as someone."
 *
 * @return array{sub:string,email:string,email_verified:bool,name:string}
 */
function verify_google_id_token(string $idToken, string $expectedClientId): array
{
    $parts = explode('.', $idToken);
    if (count($parts) !== 3) {
        throw new GoogleAuthException('Malformed token.');
    }

    [$headerB64, $payloadB64, $sigB64] = $parts;

    $header  = json_decode(base64url_decode($headerB64), true);
    $payload = json_decode(base64url_decode($payloadB64), true);

    if (!is_array($header) || !is_array($payload)) {
        throw new GoogleAuthException('Malformed token contents.');
    }

    if (($header['alg'] ?? null) !== 'RS256') {
        throw new GoogleAuthException('Unexpected signing algorithm.');
    }

    $kid = $header['kid'] ?? null;
    if (!$kid) {
        throw new GoogleAuthException('Token missing key ID.');
    }

    // Fetch Google's current public keys and find the one that signed this token.
    $jwk = fetch_google_jwk($kid);
    if ($jwk === null) {
        throw new GoogleAuthException('No matching Google signing key found.');
    }

    $publicKeyPem = jwk_to_pem($jwk);

    $signedData = $headerB64 . '.' . $payloadB64;
    $signature  = base64url_decode($sigB64);

    $verifyResult = openssl_verify($signedData, $signature, $publicKeyPem, OPENSSL_ALGO_SHA256);
    if ($verifyResult !== 1) {
        throw new GoogleAuthException('Token signature verification failed.');
    }

    // --- Claim checks ---

    $validIssuers = ['accounts.google.com', 'https://accounts.google.com'];
    if (!in_array($payload['iss'] ?? '', $validIssuers, true)) {
        throw new GoogleAuthException('Unexpected token issuer.');
    }

    if (($payload['aud'] ?? '') !== $expectedClientId) {
        throw new GoogleAuthException('Token was not issued for this application.');
    }

    $now = time();
    if (!isset($payload['exp']) || $now >= (int) $payload['exp']) {
        throw new GoogleAuthException('Token has expired.');
    }
    if (isset($payload['iat']) && $now < ((int) $payload['iat'] - 60)) {
        throw new GoogleAuthException('Token issued in the future.');
    }

    if (empty($payload['sub'])) {
        throw new GoogleAuthException('Token missing subject claim.');
    }

    return [
        'sub'            => (string) $payload['sub'],
        'email'          => (string) ($payload['email'] ?? ''),
        'email_verified' => (bool) ($payload['email_verified'] ?? false),
        'name'           => (string) ($payload['name'] ?? ''),
    ];
}

function base64url_decode(string $data): string
{
    $padded = str_pad($data, strlen($data) % 4 === 0 ? strlen($data) : strlen($data) + (4 - strlen($data) % 4), '=');
    return base64_decode(strtr($padded, '-_', '+/'));
}

/**
 * Fetches Google's current JSON Web Key Set and returns the key
 * matching the given key ID, or null if not found.
 */
function fetch_google_jwk(string $kid): ?array
{
    static $cachedKeys = null;

    if ($cachedKeys === null) {
        $response = fetch_url_safely('https://www.googleapis.com/oauth2/v3/certs');

        if ($response === null) {
            throw new GoogleAuthException('Could not verify sign-in with Google right now.');
        }

        $decoded = json_decode($response, true);
        $cachedKeys = $decoded['keys'] ?? [];
    }

    foreach ($cachedKeys as $key) {
        if (($key['kid'] ?? null) === $kid) {
            return $key;
        }
    }

    return null;
}

/**
 * Fetches a URL using cURL if available, falling back to
 * file_get_contents (allow_url_fopen) otherwise -- not every PHP
 * install (notably some default Windows setups) has the cURL
 * extension enabled.
 */
function fetch_url_safely(string $url): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_FAILONERROR    => true,
        ]);
        $response = curl_exec($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            error_log('TravelEase: cURL fetch failed for ' . $url . ': ' . $error);
            return null;
        }

        return $response;
    }

    $context = stream_context_create(['http' => ['timeout' => 5]]);
    $response = @file_get_contents($url, false, $context);

    if ($response === false) {
        error_log('TravelEase: file_get_contents fetch failed for ' . $url);
        return null;
    }

    return $response;
}

/**
 * Converts a Google JWK (RSA, n/e as base64url) into a PEM public key
 * usable by openssl_verify. Avoids requiring a JWT/JOSE library, and
 * deliberately avoids GMP/bcmath (not guaranteed enabled on every PHP
 * install, notably some Windows setups) by working directly on the
 * raw big-endian byte strings instead of doing big-integer math.
 */
function jwk_to_pem(array $jwk): string
{
    if (($jwk['kty'] ?? null) !== 'RSA') {
        throw new GoogleAuthException('Unsupported key type.');
    }

    $modulus  = base64url_decode($jwk['n']);
    $exponent = base64url_decode($jwk['e']);

    // Build a DER-encoded RSA public key (PKCS#1 wrapped in X.509 SubjectPublicKeyInfo),
    // then PEM-encode it, since PHP's OpenSSL functions expect PEM/DER, not raw n/e.
    // Operates directly on the raw big-endian byte strings -- no GMP/bcmath
    // dependency needed, since we only need to re-encode bytes, not do math.
    $rsaPublicKeyDer = der_sequence(
        der_integer($modulus) . der_integer($exponent)
    );

    $algorithmIdentifier = der_sequence(
        der_oid("\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01") . // rsaEncryption OID
        "\x05\x00" // NULL
    );

    $subjectPublicKey = der_bit_string($rsaPublicKeyDer);

    $spki = der_sequence($algorithmIdentifier . $subjectPublicKey);

    $pem = "-----BEGIN PUBLIC KEY-----\n"
         . chunk_split(base64_encode($spki), 64, "\n")
         . "-----END PUBLIC KEY-----\n";

    return $pem;
}

// --- Minimal DER encoding helpers (only what's needed for an RSA public key) ---

function der_length(int $length): string
{
    if ($length < 0x80) {
        return chr($length);
    }
    $bytes = ltrim(pack('N', $length), "\x00");
    return chr(0x80 | strlen($bytes)) . $bytes;
}

/**
 * DER-encodes an unsigned big-endian integer given as a raw byte string.
 * Strips leading zero bytes (keeping at least one), then re-adds a
 * single leading zero if the high bit is set, so it isn't misread as
 * a negative number.
 */
function der_integer(string $bin): string
{
    // Strip redundant leading zero bytes, but keep at least one byte.
    $bin = ltrim($bin, "\x00");
    if ($bin === '') {
        $bin = "\x00";
    }

    if ((ord($bin[0]) & 0x80) !== 0) {
        $bin = "\x00" . $bin;
    }

    return "\x02" . der_length(strlen($bin)) . $bin;
}

function der_sequence(string $contents): string
{
    return "\x30" . der_length(strlen($contents)) . $contents;
}

function der_bit_string(string $contents): string
{
    $withUnusedBitsByte = "\x00" . $contents;
    return "\x03" . der_length(strlen($withUnusedBitsByte)) . $withUnusedBitsByte;
}

function der_oid(string $encodedOid): string
{
    return "\x06" . der_length(strlen($encodedOid)) . $encodedOid;
}
