<?php
// Path: web/_core/maillib/src/SendOptions.php
/**
 * -----------------------------------------------------------------------------
 * SendOptions — how one particular send is to be made 🎛️
 * -----------------------------------------------------------------------------
 * There are exactly three ways to send, and all three are encrypted:
 *
 *   1. `checked()` — the normal way. The mail server's certificate must be
 *      valid, be for the server's name, and trace back to an authority the
 *      machine trusts.
 *   2. `onlyThisCertificate($fingerprint)` — accept the single certificate
 *      whose fingerprint is given, and no other. This is for the case where a
 *      person has looked at a certificate that failed the normal check
 *      (expired, say) and decided to send through it anyway.
 *   3. `certificateNotChecked()` — the certificate is NOT judged at all. For
 *      an application whose administrator has deliberately switched checking
 *      off while a mail server's certificate is being put right.
 *
 * 🔓 WHAT THE THIRD WAY GIVES UP, said plainly. With it, this library cannot
 * tell the mail server from an impostor. Somebody on the network path can show
 * a certificate of their own, and the mailbox's user name and password, and
 * every message, then go to them. Nothing in this library can prevent that
 * once the check is not made: that is what the check is for. It was left out
 * on purpose until 13 October 2026 and added by the owner's decision.
 *
 * 🔒 WHAT IT STILL KEEPS, and how it is built. It is NOT a connection with
 * the check switched off. Each new connection is made in two steps:
 *   - the certificate is READ on a connection that can write nothing once
 *     encryption is on (`CertificateReader`), and
 *   - the sending connection then accepts exactly THAT certificate, by its
 *     fingerprint, the same way as the second way above.
 * So everything the second way demands still holds: encryption, TLS 1.2 or
 * later, no sign-in line before encryption is on, and a server that offers no
 * encryption is told nothing. And there is still only ONE place in the
 * library where a connection is made without the full check and may write:
 * the "one certificate" mode of `Connection`.
 * REJECTED: a fourth mode of `Connection` with no check at all. It would have
 * been a second door that must be kept locked in every other respect, for
 * ever, to save one connection.
 *
 * WHAT IS STILL NOT HERE: any way to send without encryption.
 *
 * It applies to one call of `send()`. A connection opened under one of these
 * rules is closed before any message that asked for another.
 *
 * @package   MWBM\Mail
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.2.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace MWBM\Mail;

final class SendOptions
{
    /**
     * @param string $onlyCertificateSha256 Empty unless ONE certificate is to be accepted; then the 64
     *                                      lower-case hexadecimal characters of that certificate.
     * @param bool   $notChecked            True for the third way: read the certificate, then accept
     *                                      exactly the one that was read. Never true together with a
     *                                      fingerprint.
     */
    private function __construct(
        public readonly string $onlyCertificateSha256,
        public readonly bool $notChecked = false,
    ) {
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

    /**
     * 🔓 Send without judging the certificate. Read the header of this file
     * before using it: an impostor on the network path can then read the
     * mailbox's password and every message.
     */
    public static function certificateNotChecked(): self
    {
        return new self('', true);
    }

    /** True only for 64 lower-case hexadecimal characters: a whole SHA-256 value. */
    public static function isFingerprint(string $value): bool
    {
        return preg_match('/\A[0-9a-f]{64}\z/', $value) === 1;
    }
}
