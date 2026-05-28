<?php
// Path: _core/Totp.php
/**
 * -----------------------------------------------------------------------------
 * Portal Core TOTP Helper
 * -----------------------------------------------------------------------------
 * Implements RFC 6238 Time-Based One-Time Password generation and verification.
 * No external dependencies — pure PHP implementation.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.8.2
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/92
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class Totp
{
    /** @var int TOTP period in seconds */
    private const PERIOD = 30;

    /** @var int Number of digits in the OTP */
    private const DIGITS = 6;

    /** @var int Window of periods to accept (1 = ±30s) */
    private const WINDOW = 1;

    /** @var int Length of secret in bytes (produces 32-char base32) */
    private const SECRET_BYTES = 20;

    /* ---------------------------------------------------------------------- */
    /* Secret Generation                                                      */
    /* ---------------------------------------------------------------------- */

    /**
     * Generate a random base32-encoded secret.
     */
    public static function generateSecret(): string
    {
        $bytes = random_bytes(self::SECRET_BYTES);
        return self::base32Encode($bytes);
    }

    /* ---------------------------------------------------------------------- */
    /* OTP Generation & Verification                                          */
    /* ---------------------------------------------------------------------- */

    /**
     * Generate the current TOTP code for a given secret.
     */
    public static function getCode(string $base32Secret, ?int $timestamp = null): string
    {
        $time    = $timestamp ?? time();
        $counter = (int) floor($time / self::PERIOD);
        return self::hotp(self::base32Decode($base32Secret), $counter);
    }

    /**
     * Verify a user-supplied code against the secret with window tolerance.
     */
    public static function verify(string $base32Secret, string $code, ?int $timestamp = null): bool
    {
        $time    = $timestamp ?? time();
        $counter = (int) floor($time / self::PERIOD);
        $secret  = self::base32Decode($base32Secret);

        for ($i = -self::WINDOW; $i <= self::WINDOW; $i++) {
            $expected = self::hotp($secret, $counter + $i);
            if (hash_equals($expected, $code) === true) {
                return true;
            }
        }

        return false;
    }

    /* ---------------------------------------------------------------------- */
    /* QR / Provisioning URI                                                  */
    /* ---------------------------------------------------------------------- */

    /**
     * Build an otpauth:// URI for QR code generation.
     */
    public static function getUri(string $base32Secret, string $email, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($email);
        $params = http_build_query([
            'secret'    => $base32Secret,
            'issuer'    => $issuer,
            'algorithm' => 'SHA1',
            'digits'    => self::DIGITS,
            'period'    => self::PERIOD,
        ]);

        return 'otpauth://totp/' . $label . '?' . $params;
    }

    /**
     * Generate a URL for a QR code image (uses Google Charts API).
     */
    public static function getQrUrl(string $uri, int $size = 200): string
    {
        return 'https://chart.googleapis.com/chart?chs=' . $size . 'x' . $size
            . '&cht=qr&chl=' . urlencode($uri) . '&choe=UTF-8';
    }

    /* ---------------------------------------------------------------------- */
    /* Backup Codes                                                           */
    /* ---------------------------------------------------------------------- */

    /**
     * Generate a set of single-use backup codes.
     *
     * @return string[] Array of plain-text backup codes
     */
    public static function generateBackupCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(4))); // 8-char hex code
        }
        return $codes;
    }

    /* ---------------------------------------------------------------------- */
    /* Internal Helpers                                                        */
    /* ---------------------------------------------------------------------- */

    /**
     * HOTP: RFC 4226 HMAC-Based One-Time Password.
     */
    private static function hotp(string $secret, int $counter): string
    {
        // Pack counter as 64-bit big-endian
        $counterBytes = pack('N*', 0, $counter);
        $hash = hash_hmac('sha1', $counterBytes, $secret, true);

        // Dynamic truncation
        $offset = ord($hash[19]) & 0x0F;
        $code   = (
            ((ord($hash[$offset + 0]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8)  |
            (ord($hash[$offset + 3]) & 0xFF)
        ) % (10 ** self::DIGITS);

        return str_pad((string) $code, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Base32 encode raw bytes (RFC 4648).
     */
    private static function base32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $binary   = '';
        foreach (str_split($data) as $char) {
            $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $result = '';
        $chunks = str_split($binary, 5);
        foreach ($chunks as $chunk) {
            $chunk   = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $result .= $alphabet[bindec($chunk)];
        }

        return $result;
    }

    /**
     * Base32 decode to raw bytes (RFC 4648).
     */
    private static function base32Decode(string $encoded): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $encoded  = strtoupper(rtrim($encoded, '='));
        $binary   = '';

        for ($i = 0; $i < strlen($encoded); $i++) {
            $pos = strpos($alphabet, $encoded[$i]);
            if ($pos === false) {
                continue;
            }
            $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $result = '';
        $chunks = str_split($binary, 8);
        foreach ($chunks as $chunk) {
            if (strlen($chunk) === 8) {
                $result .= chr((int) bindec($chunk));
            }
        }

        return $result;
    }
}
