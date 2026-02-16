<?php
// Path: vendor/simplejwt/JWT.php
/**
 * -----------------------------------------------------------------------------
 * Lightweight JWT Decoder & Verifier 🔐
 * -----------------------------------------------------------------------------
 * Minimal RS256 JWT verification library for Microsoft 365 ID tokens. Fetches
 * JWKS keys from the identity provider, matches by kid, and verifies the
 * RS256 signature using PHP's openssl_verify().
 *
 * No external dependencies – uses only built-in PHP extensions (openssl, json).
 *
 * Usage:
 *   use SimpleJWT\JWT;
 *
 *   $jwksJson = file_get_contents($jwksUri);
 *   $jwks     = json_decode($jwksJson, true);
 *   $payload  = JWT::decode($idToken, $jwks);
 *   // $payload is now an associative array of claims
 *
 * @see       https://datatracker.ietf.org/doc/html/rfc7519  (JWT)
 * @see       https://datatracker.ietf.org/doc/html/rfc7517  (JWK)
 * @see       https://datatracker.ietf.org/doc/html/rfc7518  (JWA – RS256)
 * @package   SimpleJWT
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   MIT
 * @version   0.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace SimpleJWT;

use RuntimeException;

class JWT
{
    /** @var int Clock skew tolerance in seconds for exp/nbf/iat checks */
    private const LEEWAY_SECONDS = 120;

    /**
     * Decode and verify a JWT token using JWKS keys.
     *
     * @param string $token The raw JWT string (header.payload.signature)
     * @param array  $jwks  The JWKS key set as an associative array with 'keys' array
     * @param array  $options Optional validation options:
     *                        - 'iss'    => expected issuer (string or array of strings)
     *                        - 'aud'    => expected audience (string)
     *                        - 'leeway' => clock skew tolerance in seconds (int)
     *
     * @return array The decoded payload claims as an associative array
     *
     * @throws RuntimeException If the token is malformed, signature invalid, or claims fail validation
     */
    public static function decode(string $token, array $jwks, array $options = []): array
    {
        // 📐 Split the token into its three parts
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new RuntimeException('JWT: Malformed token – expected 3 parts, got ' . count($parts));
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        // 📝 Decode header to determine algorithm and key ID
        $header = self::decodeJsonSegment($headerB64);
        if (isset($header['alg']) === false) {
            throw new RuntimeException('JWT: Missing "alg" in header');
        }

        // 🛡️ Only RS256 is supported (used by MS365 and Google)
        if ($header['alg'] !== 'RS256') {
            throw new RuntimeException('JWT: Unsupported algorithm "' . $header['alg'] . '" – only RS256 is supported');
        }

        if (isset($header['kid']) === false) {
            throw new RuntimeException('JWT: Missing "kid" in header');
        }

        // 🔑 Find the matching public key from JWKS
        $publicKeyPem = self::findKey($header['kid'], $jwks);

        // ✅ Verify the RS256 signature
        $dataToVerify = $headerB64 . '.' . $payloadB64;
        $signature    = self::base64UrlDecode($signatureB64);

        $result = openssl_verify(
            $dataToVerify,
            $signature,
            $publicKeyPem,
            OPENSSL_ALGO_SHA256
        );

        if ($result !== 1) {
            $opensslError = openssl_error_string();
            throw new RuntimeException('JWT: Signature verification failed' . ($opensslError !== false ? ' – ' . $opensslError : ''));
        }

        // 📦 Decode the payload
        $payload = self::decodeJsonSegment($payloadB64);

        // ⏱️ Validate time-based claims
        $leeway = (int) ($options['leeway'] ?? self::LEEWAY_SECONDS);
        self::validateTimeClaims($payload, $leeway);

        // 🔍 Validate issuer if specified
        if (isset($options['iss']) === true) {
            self::validateIssuer($payload, $options['iss']);
        }

        // 🔍 Validate audience if specified
        if (isset($options['aud']) === true) {
            self::validateAudience($payload, $options['aud']);
        }

        return $payload;
    }

    /**
     * Find a public key by kid in the JWKS key set and convert to PEM.
     *
     * @param string $kid  The key ID from the JWT header
     * @param array  $jwks The JWKS key set (must contain 'keys' array)
     *
     * @return string The public key in PEM format
     *
     * @throws RuntimeException If the key is not found or cannot be converted
     */
    private static function findKey(string $kid, array $jwks): string
    {
        if (isset($jwks['keys']) === false || is_array($jwks['keys']) === false) {
            throw new RuntimeException('JWT: Invalid JWKS – missing "keys" array');
        }

        // 🔍 Search for the key with matching kid
        foreach ($jwks['keys'] as $key) {
            if (isset($key['kid']) === true && $key['kid'] === $kid) {
                // 🛡️ Ensure it's an RSA key
                if (($key['kty'] ?? '') !== 'RSA') {
                    throw new RuntimeException('JWT: Key type "' . ($key['kty'] ?? 'unknown') . '" not supported – expected RSA');
                }

                if (isset($key['n']) === false || isset($key['e']) === false) {
                    throw new RuntimeException('JWT: RSA key missing "n" or "e" component');
                }

                return self::rsaKeyToPem($key['n'], $key['e']);
            }
        }

        throw new RuntimeException('JWT: No key found matching kid "' . $kid . '"');
    }

    /**
     * Convert RSA modulus (n) and exponent (e) from Base64url to PEM format.
     *
     * Builds a DER-encoded ASN.1 RSAPublicKey structure and wraps it in PEM
     * headers. This avoids needing any external library for key conversion.
     *
     * @see https://datatracker.ietf.org/doc/html/rfc3447#appendix-A.1.1
     * @see https://lapo.it/asn1js/ (useful for debugging ASN.1 structures)
     *
     * @param string $nB64 Base64url-encoded RSA modulus
     * @param string $eB64 Base64url-encoded RSA exponent
     *
     * @return string PEM-formatted RSA public key
     */
    private static function rsaKeyToPem(string $nB64, string $eB64): string
    {
        // 🔢 Decode the modulus and exponent from Base64url
        $modulus  = self::base64UrlDecode($nB64);
        $exponent = self::base64UrlDecode($eB64);

        // 📐 ASN.1 DER encoding of RSAPublicKey
        // See: https://datatracker.ietf.org/doc/html/rfc8017#appendix-A.1.1
        //
        // RSAPublicKey ::= SEQUENCE {
        //     modulus           INTEGER,  -- n
        //     publicExponent    INTEGER   -- e
        // }
        $modInteger = self::asn1Integer($modulus);
        $expInteger = self::asn1Integer($exponent);

        // 📦 Inner SEQUENCE containing the two integers
        $rsaPublicKey = self::asn1Sequence($modInteger . $expInteger);

        // 📦 Wrap in SubjectPublicKeyInfo structure:
        // SEQUENCE {
        //   SEQUENCE { OID rsaEncryption, NULL }  -- AlgorithmIdentifier
        //   BIT STRING { RSAPublicKey }           -- subjectPublicKey
        // }
        // OID for rsaEncryption: 1.2.840.113549.1.1.1
        $algorithmIdentifier = self::asn1Sequence(
            "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01" // OID rsaEncryption
            . "\x05\x00"                                       // NULL parameters
        );

        // BIT STRING wrapping the RSAPublicKey (prepend 0x00 for unused bits count)
        $bitString = self::asn1BitString($rsaPublicKey);

        $subjectPublicKeyInfo = self::asn1Sequence($algorithmIdentifier . $bitString);

        // 🏷️ Wrap in PEM headers
        $pem = "-----BEGIN PUBLIC KEY-----\n"
             . chunk_split(base64_encode($subjectPublicKeyInfo), 64, "\n")
             . "-----END PUBLIC KEY-----";

        return $pem;
    }

    /**
     * Validate time-based JWT claims (exp, nbf, iat).
     *
     * @param array $payload The decoded JWT payload
     * @param int   $leeway  Clock skew tolerance in seconds
     *
     * @throws RuntimeException If any time claim fails validation
     */
    private static function validateTimeClaims(array $payload, int $leeway): void
    {
        $now = time();

        // ⏰ Check expiration (exp)
        if (isset($payload['exp']) === true) {
            if (($payload['exp'] + $leeway) < $now) {
                throw new RuntimeException('JWT: Token has expired (exp: ' . $payload['exp'] . ', now: ' . $now . ')');
            }
        }

        // ⏰ Check not-before (nbf)
        if (isset($payload['nbf']) === true) {
            if (($payload['nbf'] - $leeway) > $now) {
                throw new RuntimeException('JWT: Token not yet valid (nbf: ' . $payload['nbf'] . ', now: ' . $now . ')');
            }
        }

        // ⏰ Check issued-at (iat) – reject tokens issued far in the future
        if (isset($payload['iat']) === true) {
            if (($payload['iat'] - $leeway) > $now) {
                throw new RuntimeException('JWT: Token issued in the future (iat: ' . $payload['iat'] . ', now: ' . $now . ')');
            }
        }
    }

    /**
     * Validate the issuer (iss) claim.
     *
     * @param array        $payload  The decoded JWT payload
     * @param string|array $expected Expected issuer(s) – string or array of strings
     *
     * @throws RuntimeException If the issuer does not match
     */
    private static function validateIssuer(array $payload, string|array $expected): void
    {
        if (isset($payload['iss']) === false) {
            throw new RuntimeException('JWT: Missing "iss" claim');
        }

        $allowedIssuers = is_array($expected) ? $expected : [$expected];

        if (in_array($payload['iss'], $allowedIssuers, true) === false) {
            throw new RuntimeException('JWT: Issuer "' . $payload['iss'] . '" does not match expected');
        }
    }

    /**
     * Validate the audience (aud) claim.
     *
     * @param array  $payload  The decoded JWT payload
     * @param string $expected Expected audience (client ID)
     *
     * @throws RuntimeException If the audience does not match
     */
    private static function validateAudience(array $payload, string $expected): void
    {
        if (isset($payload['aud']) === false) {
            throw new RuntimeException('JWT: Missing "aud" claim');
        }

        // 🔍 aud can be a string or an array per RFC 7519 Section 4.1.3
        $audiences = is_array($payload['aud']) ? $payload['aud'] : [$payload['aud']];

        if (in_array($expected, $audiences, true) === false) {
            throw new RuntimeException('JWT: Audience "' . $expected . '" not found in token');
        }
    }

    /* ====================================================================== */
    /* Encoding / Decoding helpers                                            */
    /* ====================================================================== */

    /**
     * Decode a Base64url-encoded JSON segment into an associative array.
     *
     * @param string $segment Base64url-encoded JSON string
     *
     * @return array Decoded associative array
     *
     * @throws RuntimeException If decoding fails
     */
    private static function decodeJsonSegment(string $segment): array
    {
        $json = self::base64UrlDecode($segment);
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('JWT: Failed to decode JSON segment – ' . json_last_error_msg());
        }

        if (is_array($data) === false) {
            throw new RuntimeException('JWT: JSON segment did not decode to an array');
        }

        return $data;
    }

    /**
     * Decode a Base64url-encoded string per RFC 4648 Section 5.
     *
     * @see https://datatracker.ietf.org/doc/html/rfc4648#section-5
     *
     * @param string $input Base64url-encoded string
     *
     * @return string Decoded binary string
     */
    private static function base64UrlDecode(string $input): string
    {
        // 🔄 Replace URL-safe characters with standard Base64 characters
        $base64 = strtr($input, '-_', '+/');

        // 📐 Add padding if necessary (Base64url omits trailing '=')
        $remainder = strlen($base64) % 4;
        if ($remainder !== 0) {
            $base64 .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode($base64, true);
        if ($decoded === false) {
            throw new RuntimeException('JWT: Base64url decode failed');
        }

        return $decoded;
    }

    /* ====================================================================== */
    /* ASN.1 DER encoding helpers                                             */
    /* ====================================================================== */

    /**
     * Encode a binary string as an ASN.1 INTEGER.
     *
     * @param string $data Raw binary integer data
     *
     * @return string DER-encoded INTEGER
     */
    private static function asn1Integer(string $data): string
    {
        // 🔢 If the high bit is set, prepend a zero byte to indicate positive
        if (ord($data[0]) > 0x7f) {
            $data = "\x00" . $data;
        }

        return "\x02" . self::asn1Length(strlen($data)) . $data;
    }

    /**
     * Encode data as an ASN.1 SEQUENCE.
     *
     * @param string $data The contents of the sequence
     *
     * @return string DER-encoded SEQUENCE
     */
    private static function asn1Sequence(string $data): string
    {
        return "\x30" . self::asn1Length(strlen($data)) . $data;
    }

    /**
     * Encode data as an ASN.1 BIT STRING.
     *
     * @param string $data The bit string contents
     *
     * @return string DER-encoded BIT STRING
     */
    private static function asn1BitString(string $data): string
    {
        // 📐 Prepend unused-bits byte (0x00 = no unused bits)
        $content = "\x00" . $data;
        return "\x03" . self::asn1Length(strlen($content)) . $content;
    }

    /**
     * Encode a length value in ASN.1 DER format.
     *
     * @see https://en.wikipedia.org/wiki/X.690#Length_octets
     *
     * @param int $length The length to encode
     *
     * @return string DER-encoded length bytes
     */
    private static function asn1Length(int $length): string
    {
        // 📏 Short form: single byte for lengths 0-127
        if ($length < 0x80) {
            return chr($length);
        }

        // 📏 Long form: first byte = 0x80 | number of length bytes, then length bytes
        $lengthBytes = '';
        $temp = $length;
        while ($temp > 0) {
            $lengthBytes = chr($temp & 0xff) . $lengthBytes;
            $temp >>= 8;
        }

        return chr(0x80 | strlen($lengthBytes)) . $lengthBytes;
    }
}
