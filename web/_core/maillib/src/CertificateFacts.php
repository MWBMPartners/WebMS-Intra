<?php
// Path: web/_core/maillib/src/CertificateFacts.php
/**
 * -----------------------------------------------------------------------------
 * CertificateFacts — what a mail server's certificate says, and what is wrong 🪪
 * -----------------------------------------------------------------------------
 * A certificate is the document a mail server shows to prove it is the server
 * it claims to be. When that proof fails, PHP says only "certificate verify
 * failed", whatever the cause. An administrator cannot act on that. This class
 * reads the certificate itself and names the cause:
 *
 *   expired · not valid yet · issued for another name · signed by itself ·
 *   a key too short · signed with an old method · not marked for a server ·
 *   not the certificate a person approved · and, when the certificate itself
 *   shows none of these, "most likely cannot be traced to a trusted authority".
 *
 * WHAT THIS IS, AND IS NOT. PHP and OpenSSL decide whether a connection is
 * trusted, and nothing here can make a connection trusted. This class EXPLAINS
 * a refusal. It also holds ONE rule that can REFUSE on top of PHP: the name
 * rule in `nameMatches()`, which `Connection` applies to a certificate PHP has
 * already accepted. So a certificate must pass both, and this class can only
 * ever make the answer stricter.
 *
 * WHAT IT CANNOT TELL. It reads one certificate, not the chain behind it.
 *   - "Cannot be traced to a trusted authority" is therefore the answer LEFT
 *     OVER when the certificate itself shows nothing wrong. A private
 *     authority and a missing intermediate certificate both land there, and
 *     so would any cause this class does not know how to see. Its sentence
 *     says "most often", not "is".
 *   - The three weaknesses (short key, old signature, not for a server) are
 *     what the certificate SHOWS. How short is too short is decided by each
 *     machine's OpenSSL settings, which PHP does not let this class read. So
 *     they are offered as the reason only when PHP refused the certificate
 *     and nothing more certain was found, and a certificate with a short key
 *     AND an unknown issuer is reported for its key alone.
 *
 * Every word taken from the certificate (names, the issuer) was written by
 * whoever made it, possibly an attacker, so it is stripped of control
 * characters and cut to a sensible length before being kept.
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

final class CertificateFacts
{
    public const EXPIRED = 'expired';
    public const NOT_YET_VALID = 'not-yet-valid';
    public const WRONG_NAME = 'wrong-name';
    public const SELF_SIGNED = 'self-signed';
    public const UNKNOWN_ISSUER = 'unknown-issuer';
    public const NOT_APPROVED = 'not-the-approved-certificate';
    public const WEAK_KEY = 'weak-key';
    public const WEAK_SIGNATURE = 'weak-signature';
    public const NOT_FOR_A_SERVER = 'not-for-a-server';

    /**
     * @param list<string> $reasons    What is wrong, as the constants above. Empty when nothing was found.
     * @param list<string> $names      The server names the certificate is for.
     * @param string       $subject    Who the certificate says it belongs to.
     * @param string       $issuer     Who signed it.
     * @param int          $validFrom  The moment it starts to be valid (seconds since 1970, UTC).
     * @param int          $validTo    The moment it stops being valid (seconds since 1970, UTC).
     * @param string       $sha256     Its SHA-256 fingerprint: 64 lower-case hexadecimal characters.
     * @param string       $serverName The server name it was compared with (from the settings).
     * @param array<string,string> $weaknesses Weaknesses the certificate shows, as one of the three
     *                                 weakness constants => a few words of detail (such as "RSA, 1024 bits").
     *                                 NOT part of `$reasons` until `withLikelyCause()` puts them there.
     */
    public function __construct(
        public readonly array $reasons,
        public readonly array $names,
        public readonly string $subject,
        public readonly string $issuer,
        public readonly int $validFrom,
        public readonly int $validTo,
        public readonly string $sha256,
        public readonly string $serverName,
        public readonly array $weaknesses = [],
    ) {
    }

    /**
     * Read a certificate and list what is wrong with it for this server name
     * at this moment. Returns null if the certificate cannot be read at all.
     *
     * @param int $now The moment to judge the dates against (from the `Clock`).
     */
    public static function fromCertificate(\OpenSSLCertificate $certificate, string $serverName, int $now): ?self
    {
        $parsed = openssl_x509_parse($certificate);
        $fingerprint = openssl_x509_fingerprint($certificate, 'sha256');
        if (is_array($parsed) !== true || is_string($fingerprint) !== true) {
            return null;
        }
        $validFrom = (int) ($parsed['validFrom_time_t'] ?? 0);
        $validTo = (int) ($parsed['validTo_time_t'] ?? 0);

        // 🪪 The names the certificate is for. See `nameMatches()` for the rule.
        // The list ("subjectAltName") is read from OpenSSL's one-line text
        // form of it, split at commas. A name that itself held a comma would
        // be split wrongly; that cannot let a certificate in, because PHP's
        // own check of the same certificate has to pass as well.
        $dnsNames = [];
        $addresses = [];
        $hasList = isset($parsed['extensions']['subjectAltName']) === true;
        foreach (explode(',', (string) ($parsed['extensions']['subjectAltName'] ?? '')) as $entry) {
            $entry = trim($entry);
            if (stripos($entry, 'DNS:') === 0) {
                $dnsNames[] = self::clean(substr($entry, 4));
            } elseif (stripos($entry, 'IP Address:') === 0) {
                $addresses[] = self::clean(substr($entry, 11));
            }
        }
        // The old "common name" counts ONLY when the certificate has no list
        // of names at all. (This comment used to add "PHP's own check follows
        // the same order". It does not: PHP 8.5.10 falls back to the common
        // name when the list is for another server, and both 8.4 and 8.5 do
        // so when the list holds only an address.)
        if ($hasList !== true && isset($parsed['subject']['CN']) === true && is_string($parsed['subject']['CN']) === true) {
            $dnsNames[] = self::clean($parsed['subject']['CN']);
        }

        $reasons = [];
        if ($validTo < $now) {
            $reasons[] = self::EXPIRED;
        }
        if ($validFrom > $now) {
            $reasons[] = self::NOT_YET_VALID;
        }
        if (self::nameMatches($serverName, $dnsNames, $addresses) !== true) {
            $reasons[] = self::WRONG_NAME;
        }
        // "Signed by itself" is tested the exact way: does the certificate's
        // own key verify its own signature? Comparing the subject and issuer
        // names instead would be fooled by an authority that shares a name
        // with the server.
        $ownKey = openssl_pkey_get_public($certificate);
        if ($ownKey !== false && openssl_x509_verify($certificate, $ownKey) === 1) {
            $reasons[] = self::SELF_SIGNED;
        }

        return new self(
            $reasons,
            array_merge($dnsNames, $addresses),
            self::distinguishedName($parsed['subject'] ?? []),
            self::distinguishedName($parsed['issuer'] ?? []),
            $validFrom,
            $validTo,
            strtolower($fingerprint),
            $serverName,
            self::weaknessesOf($parsed, $ownKey),
        );
    }

    /**
     * What a certificate shows that today's machines commonly refuse.
     *
     * The limits are those of OpenSSL's "security level 2" (the usual setting
     * since OpenSSL 3.2): 2048 bits for an RSA, DSA or DH key, 224 for the
     * other kinds, and no MD5 or SHA-1 signature. A machine set more loosely
     * accepts less than that; see the file header for what follows from it.
     *
     * @param array<string,mixed> $parsed What `openssl_x509_parse` gave.
     *
     * @return array<string,string> weakness => a few words of detail
     */
    private static function weaknessesOf(array $parsed, \OpenSSLAsymmetricKey|false $key): array
    {
        $found = [];
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if (is_array($details) === true && isset($details['bits'], $details['type']) === true) {
            $long = in_array($details['type'], [OPENSSL_KEYTYPE_RSA, OPENSSL_KEYTYPE_DSA, OPENSSL_KEYTYPE_DH], true);
            if ((int) $details['bits'] < ($long === true ? 2048 : 224)) {
                $found[self::WEAK_KEY] = (int) $details['bits'] . ' bits';
            }
        }
        $signature = (string) ($parsed['signatureTypeLN'] ?? $parsed['signatureTypeSN'] ?? '');
        if (preg_match('/md2|md4|md5|sha-?1(?![0-9])/i', $signature) === 1) {
            $found[self::WEAK_SIGNATURE] = self::clean($signature, 60);
        }
        // OpenSSL's own answer to "may this certificate be used by a TLS
        // server?", which weighs every marking the certificate carries.
        $asServer = $parsed['purposes'][X509_PURPOSE_SSL_SERVER][0] ?? null;
        if ($asServer === false) {
            $found[self::NOT_FOR_A_SERVER] = self::clean((string) ($parsed['extensions']['extendedKeyUsage'] ?? 'another use'), 120);
        }
        return $found;
    }

    /**
     * Is this server name one of the certificate's names?
     *
     * 🪪 THE RULE, and where it comes from. It follows the standard for mail
     * servers, RFC 7817 section 3 (which builds on RFC 6125), and is stricter
     * where the newer RFC 9525 is stricter:
     *   - A SERVER NAME is compared only with the names in the certificate's
     *     list ("subjectAltName", kind DNS). The old "common name" counts only
     *     for a certificate with NO list at all (RFC 7817 allows it "for
     *     backward compatibility", and older mail servers still need it).
     *     When a list is present, even one holding only an address, the
     *     common name is ignored (RFC 6125 section 6.4.4; RFC 9525 drops the
     *     common name altogether). The caller applies that: it passes the
     *     common name in `$dnsNames` only when there is no list.
     *   - AN ADDRESS (the settings name the server by IP address) is compared
     *     only with the ADDRESSES in the list, never with a name that merely
     *     looks like one, and never through a star.
     *   - A STAR may stand only as the WHOLE first label (`*.example.org`),
     *     and covers exactly ONE label: `mail.example.org`, but neither
     *     `example.org` nor `a.mail.example.org`. A star inside a label
     *     (`m*.example.org`), anywhere but first (`mail.*`), or alone (`*`)
     *     matches nothing (RFC 7817 section 3, last rule).
     *   - Letters compare without regard to case; one dot at the end is ignored.
     *
     * WHAT IT CANNOT DO. It does not convert a name written in non-English
     * letters; such a name must already be in its ASCII form on both sides.
     *
     * @param list<string> $dnsNames  The names to compare a server NAME with.
     * @param list<string> $addresses The IP addresses to compare a server ADDRESS with.
     */
    public static function nameMatches(string $serverName, array $dnsNames, array $addresses = []): bool
    {
        $server = strtolower(rtrim($serverName, '.'));
        if ($server === '') {
            return false;
        }
        $serverAddress = self::addressBytes($server);
        if ($serverAddress !== '') {
            // Compared as addresses, not as text, so that two spellings of
            // one IPv6 address are still the same address.
            foreach ($addresses as $address) {
                if (self::addressBytes(trim($address)) === $serverAddress) {
                    return true;
                }
            }
            return false;
        }
        foreach ($dnsNames as $name) {
            $name = strtolower(rtrim($name, '.'));
            if ($name === '' || self::addressBytes($name) !== '') {
                continue;
            }
            if (strpos($name, '*') === false) {
                if ($name === $server) {
                    return true;
                }
                continue;
            }
            // A star: only as the whole first label, and only one star.
            if (strncmp($name, '*.', 2) !== 0 || strpos($name, '*', 1) !== false) {
                continue;
            }
            $firstDot = strpos($server, '.');
            if ($firstDot !== false && $firstDot > 0 && substr($server, $firstDot) === substr($name, 1)) {
                return true;
            }
        }
        return false;
    }

    /**
     * An IP address as its raw bytes, or '' when the text is not an address.
     * Tested with `filter_var` first, because `inet_pton` raises a warning for
     * anything else, and no warning of this library may reach the application.
     */
    private static function addressBytes(string $text): string
    {
        return filter_var($text, FILTER_VALIDATE_IP) !== false ? (string) inet_pton($text) : '';
    }

    /** The same facts with one more reason added. */
    public function withReason(string $reason): self
    {
        if (in_array($reason, $this->reasons, true) === true) {
            return $this;
        }
        return new self(
            array_merge($this->reasons, [$reason]),
            $this->names,
            $this->subject,
            $this->issuer,
            $this->validFrom,
            $this->validTo,
            $this->sha256,
            $this->serverName,
            $this->weaknesses,
        );
    }

    /**
     * For a certificate PHP refused although it shows none of the certain
     * faults: give the most likely cause. A weakness it shows, if any;
     * otherwise the leftover, "cannot be traced to a trusted authority".
     * (Every such certificate used to be called untraceable, including a
     * 1024-bit one that traced perfectly well.)
     */
    public function withLikelyCause(): self
    {
        if ($this->reasons !== []) {
            return $this;
        }
        $facts = $this;
        foreach (array_keys($this->weaknesses) as $weakness) {
            $facts = $facts->withReason($weakness);
        }
        return $facts->reasons === [] ? $facts->withReason(self::UNKNOWN_ISSUER) : $facts;
    }

    /**
     * Each reason as a sentence an administrator can act on, in English. An
     * application that speaks other languages translates from `$reasons`
     * instead. Dates are UTC and say so.
     *
     * @return list<string>
     */
    public function inWords(): array
    {
        $names = $this->names === [] ? 'no name at all' : implode(', ', $this->names);
        $sentences = [];
        foreach ($this->reasons as $reason) {
            $sentences[] = match ($reason) {
                self::EXPIRED => 'The certificate expired on ' . gmdate('j F Y', $this->validTo) . ' (UTC).',
                self::NOT_YET_VALID => 'The certificate is not valid until ' . gmdate('j F Y', $this->validFrom) . ' (UTC).',
                self::WRONG_NAME => 'The certificate is for another name (' . $names . '), not the server name in the settings ('
                    . $this->serverName . ').',
                self::SELF_SIGNED => 'The certificate is signed by itself, not by a certificate authority.',
                self::UNKNOWN_ISSUER => 'The certificate did not pass the check, and nothing in the certificate itself says '
                    . 'why. Most often that means it cannot be traced to an authority this server trusts (a missing intermediate '
                    . 'certificate on the mail server, or a private authority). It was issued by: ' . $this->issuer . '.',
                self::WEAK_KEY => "The certificate's key is too short to be trusted today ("
                    . ($this->weaknesses[self::WEAK_KEY] ?? 'short') . '; 2048 bits is the usual least).',
                self::WEAK_SIGNATURE => 'The certificate is signed with an old method that is no longer trusted ('
                    . ($this->weaknesses[self::WEAK_SIGNATURE] ?? 'old') . ').',
                self::NOT_FOR_A_SERVER => 'The certificate is not marked for use by a server (it is marked for: '
                    . ($this->weaknesses[self::NOT_FOR_A_SERVER] ?? 'another use') . ').',
                self::NOT_APPROVED => 'The mail server showed a different certificate from the one that was approved.',
                default => 'The certificate was refused (' . self::clean($reason) . ').',
            };
        }
        return $sentences;
    }

    /**
     * Make text that came from outside safe to keep and show: control
     * characters become "?", and it is cut to a sensible length. It is still
     * plain text; whoever shows it on a page must escape it for HTML as usual.
     */
    public static function clean(string $text, int $maxBytes = 300): string
    {
        $text = (string) preg_replace('/[\x00-\x1F\x7F]/', '?', $text);
        if (strlen($text) > $maxBytes) {
            $text = substr($text, 0, $maxBytes) . '...';
        }
        return $text;
    }

    /**
     * A certificate's subject or issuer as one line, such as "CN=mail.example.org, O=Example".
     *
     * @param mixed $parts What `openssl_x509_parse` gave for the subject or issuer.
     */
    private static function distinguishedName(mixed $parts): string
    {
        if (is_array($parts) !== true) {
            return '';
        }
        $pieces = [];
        foreach ($parts as $key => $value) {
            // A part can appear more than once, in which case PHP gives a list.
            $pieces[] = $key . '=' . (is_array($value) === true ? implode('+', array_map(static fn (mixed $part): string => is_scalar($part) === true ? (string) $part : '', $value)) : (string) $value);
        }
        return self::clean(implode(', ', $pieces));
    }
}
