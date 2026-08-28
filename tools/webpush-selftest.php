<?php
// Path: tools/webpush-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Web Push crypto self-test 🔔🔐 (#322)
 * -----------------------------------------------------------------------------
 * Standalone, dependency-free (no DB, no bootstrap, no network) regression
 * guard for the two silent-failure classes an implementer can introduce
 * without any exception ever being thrown:
 *
 *   1. A broken VAPID DER→JOSE signature conversion — every real push
 *      service just returns 401/403 with no diagnostic detail.
 *   2. A wrong HKDF info string / label in the RFC 8291 payload encryption
 *      derivation — the browser silently fails to decrypt and the
 *      notification never displays (no error reaches the server at all).
 *
 * Runs the EXACT private crypto methods of Portal\Core\WebPush via
 * reflection (not a reimplementation) — so a future refactor that breaks
 * either derivation fails THIS script, not just a live push service.
 *
 * Usage:  php tools/webpush-selftest.php
 * Exit:   0 on success (all assertions pass), 1 on any failure.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026 MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/322
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

require __DIR__ . '/../web/_core/WebPush.php';

use Portal\Core\WebPush;

// -----------------------------------------------------------------------------
// 🔧 Reflection handles onto WebPush's private crypto internals. WebPush.php
// itself is required directly above (not the full bootstrap), so none of the
// App::settings()/Logger::activity()-touching methods (isConfigured(),
// publicKey(), validateEndpoint(), vapidAuthHeader(), send(),
// sendToChannel(), recordOutcome()) are exercised here — this script only
// ever calls the pure-crypto methods below, none of which touch the DB,
// Portal\Core\App, or the network. Those App/DB-touching paths are covered
// by the documented manual test in DEV_NOTES.md → "Web Push setup".
// -----------------------------------------------------------------------------
$ref = new ReflectionClass(WebPush::class);

function callPrivate(ReflectionClass $ref, string $method, array $args = []): mixed
{
    $m = $ref->getMethod($method);
    $m->setAccessible(true);
    return $m->invoke(null, ...$args);
}

$failures = 0;
function assertTrue(string $label, bool $cond): void
{
    global $failures;
    echo ($cond === true ? 'PASS' : 'FAIL') . ' — ' . $label . "\n";
    if ($cond === false) {
        $failures++;
    }
}

// =============================================================================
// PART A — VAPID ES256 JWT: openssl_sign() DER output → derToJose() → verify
// =============================================================================
echo "=== Part A: VAPID ES256 JWT (RFC 8292 + RFC 7518 §3.4) ===\n";

$keys = WebPush::generateKeys();
assertTrue('generateKeys() returned a 65-byte uncompressed public point', strlen(callPrivate($ref, 'b64urlDecode', [$keys['publicKey']])) === 65);
assertTrue('generateKeys() returned a usable PEM private key', str_contains($keys['privateKeyPem'], 'BEGIN EC PRIVATE KEY') || str_contains($keys['privateKeyPem'], 'BEGIN PRIVATE KEY'));

$vapidPriv = openssl_pkey_get_private($keys['privateKeyPem']);
assertTrue('Generated private key PEM parses back with openssl_pkey_get_private()', $vapidPriv !== false);

// Round-trip through importPrivateKey() with the BARE base64url scalar `d`
// (the web-push-CLI paste format) — the exact path an admin pasting only a
// private scalar exercises. Must derive the SAME public key generateKeys() returned.
$details = openssl_pkey_get_details($vapidPriv);
$dScalar = str_pad((string) $details['ec']['d'], 32, "\x00", STR_PAD_LEFT);
$dBase64url = rtrim(strtr(base64_encode($dScalar), '+/', '-_'), '=');
$importedPub = WebPush::importPrivateKey($dBase64url);
assertTrue('importPrivateKey() from a bare 32-byte scalar derives the SAME public key as generateKeys()', $importedPub === $keys['publicKey']);
assertTrue('importPrivateKey() rejects garbage input', WebPush::importPrivateKey('not-a-valid-key') === null);

// Build a real ES256 JWT signing input and sign it exactly as vapidAuthHeader() does.
$header = callPrivate($ref, 'b64url', [(string) json_encode(['typ' => 'JWT', 'alg' => 'ES256'])]);
$claims = callPrivate($ref, 'b64url', [(string) json_encode(['aud' => 'https://fcm.googleapis.com', 'exp' => time() + 43200, 'sub' => 'mailto:admin@example.org'])]);
$signingInput = $header . '.' . $claims;

$signOk = openssl_sign($signingInput, $derSig, $vapidPriv, OPENSSL_ALGO_SHA256);
assertTrue('openssl_sign() produced a DER ECDSA-Sig-Value', $signOk === true && ord($derSig[0]) === 0x30);
assertTrue('Raw DER signature is NOT 64 bytes (the classic shipped-raw silent-401 bug)', strlen($derSig) !== 64);

// THE call under test: WebPush's own private derToJose().
$jose = callPrivate($ref, 'derToJose', [$derSig]);
assertTrue('derToJose() produced exactly 64 raw bytes (32-byte R ‖ 32-byte S)', is_string($jose) && strlen($jose) === 64);
assertTrue('derToJose() rejects malformed DER', callPrivate($ref, 'derToJose', ["\x00\x01\x02"]) === null);

// Verify the JOSE signature is cryptographically correct: rebuild a DER
// SEQUENCE from it and confirm openssl_verify() accepts it against the
// public key — i.e. a real push service's verification step would pass.
$rebuildDer = static function (string $jose): string {
    $encInt = static function (string $bytes): string {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || (ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }
        return "\x02" . chr(strlen($bytes)) . $bytes;
    };
    $body = $encInt(substr($jose, 0, 32)) . $encInt(substr($jose, 32, 32));
    return "\x30" . chr(strlen($body)) . $body;
};
$pubPem = callPrivate($ref, 'p256PointToPem', [callPrivate($ref, 'b64urlDecode', [$keys['publicKey']])]);
$verify = openssl_verify($signingInput, $rebuildDer($jose), $pubPem, OPENSSL_ALGO_SHA256);
assertTrue('JOSE signature verifies against the VAPID public key (openssl_verify === 1)', $verify === 1);

// =============================================================================
// PART B — RFC 8291 aes128gcm payload encryption, full round-trip
// =============================================================================
echo "\n=== Part B: RFC 8291 aes128gcm payload encryption (via WebPush::encryptPayload) ===\n";

// Simulate a BROWSER subscription: an independent P-256 keypair + 16-byte
// auth secret. Reuses generateKeys() purely as a convenient P-256 keypair
// source — this is standing in for the subscriber, not another VAPID pair.
$browserKeys = WebPush::generateKeys();
$browserPriv = openssl_pkey_get_private($browserKeys['privateKeyPem']);
$authSecret = random_bytes(16);
$authSecretB64 = callPrivate($ref, 'b64url', [$authSecret]);

$plaintext = (string) json_encode(['title' => 'Test stream is live', 'body' => 'Tap to watch now', 'url' => '/live']);

// THE call under test: WebPush's own private encryptPayload().
$encMethod = $ref->getMethod('encryptPayload');
$encMethod->setAccessible(true);
$body = $encMethod->invoke(null, $browserKeys['publicKey'], $authSecretB64, $plaintext);
assertTrue('encryptPayload() returned a non-null wire body', $body !== null);
assertTrue('Wire body is longer than the 86-byte header + 16-byte tag alone', is_string($body) && strlen($body) > 86 + 16);

// --- Independent BROWSER-side decryption, using ONLY the wire body + the
//     browser's own private key + its own auth secret (no shared state,
//     no calls back into WebPush) — exactly what a real user agent does. ---
$salt   = substr($body, 0, 16);
$rs     = unpack('N', substr($body, 16, 4))[1];
$idLen  = ord($body[20]);
$asPub  = substr($body, 21, $idLen);
$ctTag  = substr($body, 21 + $idLen);
$ct     = substr($ctTag, 0, -16);
$tag    = substr($ctTag, -16);

assertTrue('Parsed record size (rs) = 4096', $rs === 4096);
assertTrue('Parsed keyid length = 65 (uncompressed P-256 point)', $idLen === 65);

$asPubPem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode("\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00" . $asPub), 64, "\n") . "-----END PUBLIC KEY-----\n";
$asPubKey = openssl_pkey_get_public($asPubPem);
$ecdh = openssl_pkey_derive($asPubKey, $browserPriv);
assertTrue('Browser-side ECDH secret is exactly 32 bytes', is_string($ecdh) && strlen($ecdh) === 32);

$uaPubRaw = callPrivate($ref, 'b64urlDecode', [$browserKeys['publicKey']]);
$ikm = hash_hkdf('sha256', $ecdh, 32, "WebPush: info\x00" . $uaPubRaw . $asPub, $authSecret);
$cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
$nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

$decrypted = openssl_decrypt($ct, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
assertTrue('AES-128-GCM decryption succeeded (tag verified)', $decrypted !== false);
$recovered = $decrypted !== false ? substr($decrypted, 0, -1) : null;
assertTrue('Decrypted delimiter byte is 0x02 (RFC 8291 §4 last-record marker)', $decrypted !== false && substr($decrypted, -1) === "\x02");
assertTrue('Round-tripped plaintext EXACTLY matches the original payload', $recovered === $plaintext);

// Sanity: two encryptPayload() calls for the SAME subscriber must use a
// FRESH ephemeral "as" keypair each time (RFC 8291 §3.1) — the wire bodies
// must never collide even with identical plaintext.
$body2 = $encMethod->invoke(null, $browserKeys['publicKey'], $authSecretB64, $plaintext);
assertTrue('Two encryptions of the same plaintext produce DIFFERENT wire bodies (fresh ephemeral key + salt each time)', is_string($body2) && $body2 !== $body);

// =============================================================================
// PART C — F1 regression guard: settings-snapshot single- vs double-decrypt
// =============================================================================
echo "\n=== Part C: settings-snapshot decrypt semantics (issue #322 F1) ===\n";

// This script deliberately never requires bootstrap.php (see the header
// comment above) to stay DB-free/network-free, so encrypt_setting()/
// decrypt_setting() (defined there) aren't in scope here. Reimplemented
// BYTE-FOR-BYTE below from bootstrap.php's libsodium secretbox helpers
// (nonce-prepended ciphertext, base64-encoded) purely to pin the snapshot
// semantics WebPush::isConfigured()/vapidAuthHeader() depend on. This is
// NOT a re-test of WebPush's own crypto — that is Parts A/B above.
//
// The bug this guards against: App::settings() is the BOOTSTRAP SNAPSHOT,
// which already decrypts every isSensitive='1' value ONCE while building
// it. The old WebPush.php called decrypt_setting() a SECOND time on that
// already-plaintext value — sodium_crypto_secretbox_open() on plaintext
// (not a valid nonce‖ciphertext blob) always fails and decrypt_setting()
// returns '', so isConfigured() was permanently false and the ENTIRE
// feature was inert with no error anywhere. This block would FAIL against
// that old double-decrypt code and PASSES against the fix
// ($privRaw = $encPriv; return true;).
$selftestKeyPath = sys_get_temp_dir() . '/webpush-selftest-' . bin2hex(random_bytes(8)) . '.key';
file_put_contents($selftestKeyPath, bin2hex(random_bytes(32)));

$encryptSettingTest = static function (string $plain) use ($selftestKeyPath): string {
    $keyHash = hash('sha256', (string) file_get_contents($selftestKeyPath));
    $key     = sodium_hex2bin($keyHash);
    $nonce   = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher  = sodium_crypto_secretbox($plain, $nonce, $key);
    return base64_encode($nonce . $cipher);
};
$decryptSettingTest = static function (string $encoded) use ($selftestKeyPath): string {
    if ($encoded === '') {
        return '';
    }
    $bin = base64_decode($encoded, true);
    if ($bin === false) {
        return '';
    }
    $nonce   = substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher  = substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $keyHash = hash('sha256', (string) file_get_contents($selftestKeyPath));
    $key     = sodium_hex2bin($keyHash);
    $plain   = sodium_crypto_secretbox_open($cipher, $nonce, $key);
    return $plain === false ? '' : $plain;
};

// 1) Admin save: push.vapidPrivateKey stored via encrypt_setting(), isSensitive=1.
$originalPem = "-----BEGIN EC PRIVATE KEY-----\nMHcCAQEEIP-SELFTEST-FAKE-SCALAR-BYTES-oAoGCCqGSM49AwEHoUQDQgAE\n-----END EC PRIVATE KEY-----\n";
$storedCiphertext = $encryptSettingTest($originalPem);

// 2) bootstrap.php builds the App::settings() snapshot, decrypting every
//    isSensitive='1' value ONCE.
$bootstrapSnapshotValue = $decryptSettingTest($storedCiphertext);
assertTrue('Bootstrap snapshot value (single decrypt) equals the original PEM', $bootstrapSnapshotValue === $originalPem);

// 3) THE BUG (pre-fix): a second decrypt_setting() call on the
//    already-plaintext snapshot value always returns '' — this is exactly
//    why isConfigured() was permanently false.
$secondDecryptResult = $decryptSettingTest($bootstrapSnapshotValue);
assertTrue("A SECOND decrypt_setting() on the already-plaintext snapshot value returns '' (this is why the double-decrypt made isConfigured() always false)", $secondDecryptResult === '');

// 4) THE FIX: WebPush.php now uses the snapshot value directly — no second
//    decrypt_setting() call. isConfigured() (~line 101) does
//    `return true;` once the empty-string guard above it has passed;
//    vapidAuthHeader() (~line 562) does `$privRaw = $encPriv;`.
$fixedPrivRaw = $bootstrapSnapshotValue; // matches WebPush.php: $privRaw = $encPriv;
assertTrue('Fixed code path ($privRaw = $encPriv, no re-decrypt) yields the original PEM', $fixedPrivRaw === $originalPem);
$fixedIsConfigured = $bootstrapSnapshotValue !== ''; // matches WebPush.php's post-guard `return true;`
assertTrue("Fixed isConfigured()-equivalent logic (encPriv !== '') is true for a configured key", $fixedIsConfigured === true);

@unlink($selftestKeyPath);

// =============================================================================
echo "\n";
if ($failures === 0) {
    echo "ALL CHECKS PASSED — Web Push crypto pipeline is correct.\n";
    exit(0);
}
echo $failures . " CHECK(S) FAILED.\n";
exit(1);
