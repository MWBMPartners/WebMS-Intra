<?php
// Path: _core/WebAuthn.php
/**
 * -----------------------------------------------------------------------------
 * WebAuthn / PassKey Helper
 * -----------------------------------------------------------------------------
 * Server-side WebAuthn registration and authentication. Generates options for
 * the browser's navigator.credentials API and verifies attestation/assertion
 * responses. No external dependencies (no Composer).
 *
 * Supports:
 *   - Registration (attestation) with "none" attestation conveyance
 *   - Authentication (assertion) with signature verification
 *   - CBOR decoding for attestation objects (minimal subset)
 *   - COSE ES256 (P-256) and RS256 public key extraction
 *
 * @see       https://www.w3.org/TR/webauthn-2/
 * @see       https://developer.mozilla.org/en-US/docs/Web/API/Web_Authentication_API
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.5.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use RuntimeException;

class WebAuthn
{
    /**
     * Generate registration (attestation) options for the browser.
     *
     * @param int    $userId       Portal user ID
     * @param string $userName     User's display name
     * @param string $userEmail    User's email address
     * @param string $rpName       Relying party name
     * @param string $rpID         Relying party ID (domain)
     * @param array  $excludeCredIDs  Base64url credential IDs to exclude (already registered)
     *
     * @return array Options array to send to the browser + challenge stored in session
     */
    public static function registrationOptions(
        int $userId,
        string $userName,
        string $userEmail,
        string $rpName,
        string $rpID,
        array $excludeCredIDs = []
    ): array {
        // 🔐 Generate a random challenge (32 bytes)
        $challenge = random_bytes(32);

        // 📋 Store challenge in session for verification
        Auth::ensureSession();
        $_SESSION['webauthn_challenge'] = self::base64urlEncode($challenge);
        $_SESSION['webauthn_action']    = 'register';

        // 📋 Build user handle (stable, non-PII identifier)
        $userHandle = hash('sha256', 'webauthn_user_' . $userId, true);

        $options = [
            'rp' => [
                'name' => $rpName,
                'id'   => $rpID,
            ],
            'user' => [
                'id'          => self::base64urlEncode($userHandle),
                'name'        => $userEmail,
                'displayName' => $userName,
            ],
            'challenge'            => self::base64urlEncode($challenge),
            'pubKeyCredParams'     => [
                ['type' => 'public-key', 'alg' => -7],   // ES256 (P-256)
                ['type' => 'public-key', 'alg' => -257],  // RS256
            ],
            'timeout'              => 60000,
            'attestation'          => 'none',
            'authenticatorSelection' => [
                'residentKey'      => 'preferred',
                'userVerification' => 'preferred',
            ],
        ];

        // 📋 Exclude already-registered credentials
        if (count($excludeCredIDs) > 0) {
            $options['excludeCredentials'] = [];
            foreach ($excludeCredIDs as $credId) {
                $options['excludeCredentials'][] = [
                    'type' => 'public-key',
                    'id'   => $credId,
                ];
            }
        }

        return $options;
    }

    /**
     * Verify a registration (attestation) response from the browser.
     * Extracts and returns the credential ID, public key, and sign count.
     *
     * @param array  $credential  The credential object from the browser
     * @param string $rpID        Expected relying party ID
     * @param string $origin      Expected origin (e.g. https://portal.example.com)
     *
     * @return array{credentialID: string, publicKey: string, signCount: int, aaguid: string, transports: string}
     *
     * @throws RuntimeException If verification fails
     */
    public static function verifyRegistration(array $credential, string $rpID, string $origin): array
    {
        Auth::ensureSession();

        // 🔐 Retrieve and clear the stored challenge
        $expectedChallenge = $_SESSION['webauthn_challenge'] ?? '';
        $expectedAction    = $_SESSION['webauthn_action'] ?? '';
        unset($_SESSION['webauthn_challenge'], $_SESSION['webauthn_action']);

        if ($expectedChallenge === '' || $expectedAction !== 'register') {
            throw new RuntimeException('No pending registration challenge.');
        }

        $response = $credential['response'] ?? [];

        // 📋 Decode clientDataJSON
        $clientDataJSON = self::base64urlDecode($response['clientDataJSON'] ?? '');
        $clientData     = json_decode($clientDataJSON, true);

        if ($clientData === null) {
            throw new RuntimeException('Invalid clientDataJSON.');
        }

        // 📋 Verify type
        if (($clientData['type'] ?? '') !== 'webauthn.create') {
            throw new RuntimeException('Unexpected clientData type.');
        }

        // 📋 Verify challenge
        if (($clientData['challenge'] ?? '') !== $expectedChallenge) {
            throw new RuntimeException('Challenge mismatch.');
        }

        // 📋 Verify origin
        if (($clientData['origin'] ?? '') !== $origin) {
            throw new RuntimeException('Origin mismatch: expected ' . $origin . ', got ' . ($clientData['origin'] ?? ''));
        }

        // 📋 Decode attestation object (CBOR)
        $attestationObject = self::base64urlDecode($response['attestationObject'] ?? '');
        $attestation       = self::cborDecode($attestationObject);

        if (isset($attestation['authData']) === false) {
            throw new RuntimeException('Missing authData in attestation object.');
        }

        $authData = $attestation['authData'];

        // 📋 Parse authenticator data
        // See: https://www.w3.org/TR/webauthn-2/#sctn-authenticator-data
        if (strlen($authData) < 37) {
            throw new RuntimeException('AuthData too short.');
        }

        // 📋 Bytes 0-31: RP ID hash (SHA-256)
        $rpIdHash = substr($authData, 0, 32);
        $expectedRpIdHash = hash('sha256', $rpID, true);

        if (hash_equals($expectedRpIdHash, $rpIdHash) === false) {
            throw new RuntimeException('RP ID hash mismatch.');
        }

        // 📋 Byte 32: flags
        $flags = ord($authData[32]);
        $userPresent  = ($flags & 0x01) !== 0;
        $attestedData = ($flags & 0x40) !== 0;

        if ($userPresent === false) {
            throw new RuntimeException('User presence flag not set.');
        }

        if ($attestedData === false) {
            throw new RuntimeException('Attested credential data flag not set.');
        }

        // 📋 Bytes 33-36: sign count (big-endian uint32)
        $signCount = unpack('N', substr($authData, 33, 4))[1];

        // 📋 Bytes 37-52: AAGUID (16 bytes)
        $aaguidBytes = substr($authData, 37, 16);
        $aaguid = self::formatAaguid($aaguidBytes);

        // 📋 Bytes 53-54: credential ID length (big-endian uint16)
        $credIdLen = unpack('n', substr($authData, 53, 2))[1];

        // 📋 Bytes 55-(55+credIdLen-1): credential ID
        $credentialIdRaw = substr($authData, 55, $credIdLen);
        $credentialID    = self::base64urlEncode($credentialIdRaw);

        // 📋 Remaining bytes: COSE public key (CBOR-encoded)
        $publicKeyBytes = substr($authData, 55 + $credIdLen);
        $publicKey      = self::base64urlEncode($publicKeyBytes);

        // 📋 Transports from credential
        $transports = '';
        if (isset($credential['transports']) === true && is_array($credential['transports']) === true) {
            $transports = implode(',', $credential['transports']);
        }

        return [
            'credentialID' => $credentialID,
            'publicKey'    => $publicKey,
            'signCount'    => $signCount,
            'aaguid'       => $aaguid,
            'transports'   => $transports,
        ];
    }

    /**
     * Generate authentication (assertion) options for the browser.
     *
     * @param array  $allowCredentials Array of ['id' => base64url, 'transports' => 'usb,ble,...']
     * @param string $rpID             Relying party ID
     *
     * @return array Options array to send to the browser
     */
    public static function authenticationOptions(array $allowCredentials, string $rpID): array
    {
        $challenge = random_bytes(32);

        Auth::ensureSession();
        $_SESSION['webauthn_challenge'] = self::base64urlEncode($challenge);
        $_SESSION['webauthn_action']    = 'authenticate';

        $options = [
            'challenge'        => self::base64urlEncode($challenge),
            'timeout'          => 60000,
            'rpId'             => $rpID,
            'userVerification' => 'preferred',
        ];

        if (count($allowCredentials) > 0) {
            $options['allowCredentials'] = [];
            foreach ($allowCredentials as $cred) {
                $entry = [
                    'type' => 'public-key',
                    'id'   => $cred['id'],
                ];
                if (($cred['transports'] ?? '') !== '') {
                    $entry['transports'] = explode(',', $cred['transports']);
                }
                $options['allowCredentials'][] = $entry;
            }
        }

        return $options;
    }

    /**
     * Verify an authentication (assertion) response from the browser.
     *
     * @param array  $credential  The credential object from the browser
     * @param string $publicKey   Base64url-encoded COSE public key
     * @param int    $storedSignCount Previously stored sign count
     * @param string $rpID        Expected relying party ID
     * @param string $origin      Expected origin
     *
     * @return array{verified: bool, newSignCount: int}
     *
     * @throws RuntimeException If verification fails
     */
    public static function verifyAuthentication(
        array $credential,
        string $publicKey,
        int $storedSignCount,
        string $rpID,
        string $origin
    ): array {
        Auth::ensureSession();

        $expectedChallenge = $_SESSION['webauthn_challenge'] ?? '';
        $expectedAction    = $_SESSION['webauthn_action'] ?? '';
        unset($_SESSION['webauthn_challenge'], $_SESSION['webauthn_action']);

        if ($expectedChallenge === '' || $expectedAction !== 'authenticate') {
            throw new RuntimeException('No pending authentication challenge.');
        }

        $response = $credential['response'] ?? [];

        // 📋 Decode and verify clientDataJSON
        $clientDataJSON    = self::base64urlDecode($response['clientDataJSON'] ?? '');
        $clientData        = json_decode($clientDataJSON, true);

        if ($clientData === null) {
            throw new RuntimeException('Invalid clientDataJSON.');
        }

        if (($clientData['type'] ?? '') !== 'webauthn.get') {
            throw new RuntimeException('Unexpected clientData type.');
        }

        if (($clientData['challenge'] ?? '') !== $expectedChallenge) {
            throw new RuntimeException('Challenge mismatch.');
        }

        if (($clientData['origin'] ?? '') !== $origin) {
            throw new RuntimeException('Origin mismatch.');
        }

        // 📋 Parse authenticator data
        $authDataRaw = self::base64urlDecode($response['authenticatorData'] ?? '');
        if (strlen($authDataRaw) < 37) {
            throw new RuntimeException('AuthData too short.');
        }

        $rpIdHash = substr($authDataRaw, 0, 32);
        if (hash_equals(hash('sha256', $rpID, true), $rpIdHash) === false) {
            throw new RuntimeException('RP ID hash mismatch.');
        }

        $flags = ord($authDataRaw[32]);
        if (($flags & 0x01) === 0) {
            throw new RuntimeException('User presence flag not set.');
        }

        $newSignCount = unpack('N', substr($authDataRaw, 33, 4))[1];

        // 📋 Check sign count to detect cloned authenticators
        if ($newSignCount !== 0 && $storedSignCount !== 0 && $newSignCount <= $storedSignCount) {
            Logger::errorPlatform('WebAuthn', 'Warning', 'SIGN_COUNT', 'Possible cloned authenticator detected', '');
        }

        // 📋 Verify signature
        $signature     = self::base64urlDecode($response['signature'] ?? '');
        $clientDataHash = hash('sha256', $clientDataJSON, true);
        $signedData     = $authDataRaw . $clientDataHash;

        // 🔐 Convert COSE public key to PEM
        $publicKeyBytes = self::base64urlDecode($publicKey);
        $coseKey        = self::cborDecode($publicKeyBytes);
        $pemKey         = self::coseToPem($coseKey);

        $verified = openssl_verify($signedData, $signature, $pemKey, OPENSSL_ALGO_SHA256);

        if ($verified !== 1) {
            throw new RuntimeException('Signature verification failed.');
        }

        return [
            'verified'     => true,
            'newSignCount' => $newSignCount,
        ];
    }

    /* ====================================================================== */
    /* CBOR decoder (minimal subset for WebAuthn)                             */
    /* ====================================================================== */

    /**
     * Decode a CBOR byte string into a PHP value.
     * Handles: unsigned ints, negative ints, byte strings, text strings, arrays, maps.
     *
     * @see https://www.rfc-editor.org/rfc/rfc8949
     *
     * @param string $data Raw CBOR bytes
     *
     * @return mixed Decoded PHP value
     */
    public static function cborDecode(string $data): mixed
    {
        $offset = 0;
        return self::cborDecodeItem($data, $offset);
    }

    /**
     * Decode a single CBOR item starting at the given offset.
     */
    private static function cborDecodeItem(string $data, int &$offset): mixed
    {
        if ($offset >= strlen($data)) {
            throw new RuntimeException('CBOR: unexpected end of data.');
        }

        $byte  = ord($data[$offset]);
        $major = ($byte >> 5) & 0x07;
        $add   = $byte & 0x1f;
        $offset++;

        $val = self::cborDecodeLength($data, $offset, $add);

        switch ($major) {
            case 0: // Unsigned integer
                return $val;

            case 1: // Negative integer
                return -1 - $val;

            case 2: // Byte string
                $bytes = substr($data, $offset, $val);
                $offset += $val;
                return $bytes;

            case 3: // Text string
                $text = substr($data, $offset, $val);
                $offset += $val;
                return $text;

            case 4: // Array
                $arr = [];
                for ($i = 0; $i < $val; $i++) {
                    $arr[] = self::cborDecodeItem($data, $offset);
                }
                return $arr;

            case 5: // Map
                $map = [];
                for ($i = 0; $i < $val; $i++) {
                    $key = self::cborDecodeItem($data, $offset);
                    $map[$key] = self::cborDecodeItem($data, $offset);
                }
                return $map;

            case 6: // Tag (skip tag number, decode content)
                return self::cborDecodeItem($data, $offset);

            case 7: // Simple/float
                if ($add === 20) {
                    return false;
                }
                if ($add === 21) {
                    return true;
                }
                if ($add === 22) {
                    return null;
                }
                return $val;

            default:
                throw new RuntimeException('CBOR: unsupported major type ' . $major);
        }
    }

    /**
     * Decode a CBOR length/value based on the additional info byte.
     */
    private static function cborDecodeLength(string $data, int &$offset, int $add): int
    {
        if ($add < 24) {
            return $add;
        }
        if ($add === 24) {
            $val = ord($data[$offset]);
            $offset++;
            return $val;
        }
        if ($add === 25) {
            $val = unpack('n', substr($data, $offset, 2))[1];
            $offset += 2;
            return $val;
        }
        if ($add === 26) {
            $val = unpack('N', substr($data, $offset, 4))[1];
            $offset += 4;
            return $val;
        }
        if ($add === 27) {
            $val = unpack('J', substr($data, $offset, 8))[1];
            $offset += 8;
            return $val;
        }
        return $add;
    }

    /* ====================================================================== */
    /* COSE key → PEM conversion                                              */
    /* ====================================================================== */

    /**
     * Convert a COSE public key (decoded from CBOR) to PEM format.
     * Supports ES256 (alg -7) and RS256 (alg -257).
     *
     * @param array $coseKey Decoded COSE key map
     *
     * @return string PEM-encoded public key
     *
     * @throws RuntimeException If the key type is unsupported
     */
    private static function coseToPem(array $coseKey): string
    {
        // COSE key type: 3 = RSA, 2 = EC2
        $kty = $coseKey[1] ?? 0;
        $alg = $coseKey[3] ?? 0;

        if ($kty === 2 && $alg === -7) {
            // 🔐 EC2 ES256 (P-256)
            $x = $coseKey[-2] ?? '';
            $y = $coseKey[-3] ?? '';

            if (strlen($x) !== 32 || strlen($y) !== 32) {
                throw new RuntimeException('Invalid EC2 key coordinates.');
            }

            // 📋 Build uncompressed EC point: 0x04 + x + y
            $point = "\x04" . $x . $y;

            // 📋 Wrap in SubjectPublicKeyInfo ASN.1 structure
            // OID for P-256: 1.2.840.10045.3.1.7
            $der = "\x30\x59" // SEQUENCE (89 bytes)
                 . "\x30\x13" // SEQUENCE (19 bytes) - AlgorithmIdentifier
                 . "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01" // OID ecPublicKey
                 . "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07" // OID prime256v1
                 . "\x03\x42\x00" // BIT STRING (66 bytes, 0 unused bits)
                 . $point;

            return "-----BEGIN PUBLIC KEY-----\n"
                 . chunk_split(base64_encode($der), 64, "\n")
                 . "-----END PUBLIC KEY-----\n";
        }

        if ($kty === 3 && $alg === -257) {
            // 🔐 RSA RS256
            $n = $coseKey[-1] ?? '';
            $e = $coseKey[-2] ?? '';

            $nDer = self::asn1Integer($n);
            $eDer = self::asn1Integer($e);

            $rsaSeq = "\x30" . self::asn1Length(strlen($nDer) + strlen($eDer)) . $nDer . $eDer;
            $bitString = "\x03" . self::asn1Length(strlen($rsaSeq) + 1) . "\x00" . $rsaSeq;

            // OID for rsaEncryption: 1.2.840.113549.1.1.1
            $algId = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";

            $spki = "\x30" . self::asn1Length(strlen($algId) + strlen($bitString)) . $algId . $bitString;

            return "-----BEGIN PUBLIC KEY-----\n"
                 . chunk_split(base64_encode($spki), 64, "\n")
                 . "-----END PUBLIC KEY-----\n";
        }

        throw new RuntimeException('Unsupported COSE key type/alg: kty=' . $kty . ' alg=' . $alg);
    }

    /**
     * Encode an ASN.1 INTEGER from raw bytes.
     */
    private static function asn1Integer(string $bytes): string
    {
        // 📋 Ensure the integer is positive (prepend 0x00 if high bit is set)
        if (ord($bytes[0]) & 0x80) {
            $bytes = "\x00" . $bytes;
        }
        return "\x02" . self::asn1Length(strlen($bytes)) . $bytes;
    }

    /**
     * Encode an ASN.1 length.
     */
    private static function asn1Length(int $len): string
    {
        if ($len < 128) {
            return chr($len);
        }
        if ($len < 256) {
            return "\x81" . chr($len);
        }
        return "\x82" . pack('n', $len);
    }

    /* ====================================================================== */
    /* Base64url utilities                                                     */
    /* ====================================================================== */

    /**
     * Encode binary data as base64url (URL-safe, no padding).
     */
    public static function base64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Decode a base64url-encoded string.
     */
    public static function base64urlDecode(string $data): string
    {
        $b64 = strtr($data, '-_', '+/');
        $mod = strlen($b64) % 4;
        if ($mod !== 0) {
            $b64 .= str_repeat('=', 4 - $mod);
        }
        $decoded = base64_decode($b64, true);
        if ($decoded === false) {
            throw new RuntimeException('Invalid base64url data.');
        }
        return $decoded;
    }

    /**
     * Format a 16-byte AAGUID as a UUID string.
     */
    private static function formatAaguid(string $bytes): string
    {
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-'
             . substr($hex, 8, 4) . '-'
             . substr($hex, 12, 4) . '-'
             . substr($hex, 16, 4) . '-'
             . substr($hex, 20, 12);
    }
}
