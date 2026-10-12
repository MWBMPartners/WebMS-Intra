<?php
// Path: web/_core/maillib/src/SendOptions.php
/**
 * -----------------------------------------------------------------------------
 * SendOptions — how one particular send is to be made 🎛️
 * -----------------------------------------------------------------------------
 * There are exactly two ways to send, and both are encrypted:
 *
 *   1. `checked()` — the normal way. The mail server's certificate must be
 *      valid, be for the server's name, and trace back to an authority the
 *      machine trusts.
 *   2. `onlyThisCertificate($fingerprint)` — for ONE send, accept the single
 *      certificate whose fingerprint is given, and no other. This is for the
 *      case where a person has looked at a certificate that failed the normal
 *      check (expired, say) and decided to send through it anyway.
 *
 * 🔒 WHAT IS DELIBERATELY NOT HERE: any way to say "accept whatever certificate
 * turns up", and any way to send without encryption. The second way names one
 * certificate by its SHA-256 fingerprint, which nobody else can present, so it
 * cannot be stretched into "accept anything": an empty value, a short one or a
 * pattern is refused when the object is made.
 *
 * It applies to one call of `send()`. A connection opened for a named
 * certificate is closed before any message that did not ask for it.
 *
 * @package   MWBM\Mail
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.1.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace MWBM\Mail;

final class SendOptions
{
    /**
     * @param string $onlyCertificateSha256 Empty for a checked send; otherwise the 64 lower-case
     *                                      hexadecimal characters of the one certificate to accept.
     */
    private function __construct(public readonly string $onlyCertificateSha256)
    {
    }

    /** The normal send: the certificate is fully checked. */
    public static function checked(): self
    {
        return new self('');
    }

    /**
     * Accept exactly one certificate, named by its SHA-256 fingerprint.
     *
     * @throws \InvalidArgumentException If the value is not a whole SHA-256 fingerprint.
     */
    public static function onlyThisCertificate(string $sha256): self
    {
        $fingerprint = strtolower($sha256);
        if (self::isFingerprint($fingerprint) !== true) {
            throw new \InvalidArgumentException(
                'A certificate is named by its whole SHA-256 fingerprint: 64 hexadecimal characters.'
            );
        }
        return new self($fingerprint);
    }

    /** True only for 64 lower-case hexadecimal characters: a whole SHA-256 value. */
    public static function isFingerprint(string $value): bool
    {
        return preg_match('/\A[0-9a-f]{64}\z/', $value) === 1;
    }
}
