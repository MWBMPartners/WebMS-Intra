<?php
// Path: _core/WebPush.php
/**
 * -----------------------------------------------------------------------------
 * Web Push sender — VAPID (RFC 8292) + RFC 8291 aes128gcm payload encryption 🔔🔐
 * -----------------------------------------------------------------------------
 * The ONLY file in this codebase containing Web Push crypto. Every step below
 * cites the RFC it implements; the full pipeline (VAPID JWT sign + DER→JOSE
 * conversion, ECDH + triple-HKDF payload encryption) was executed end-to-end
 * against a simulated browser keypair — sign→verify and encrypt→decrypt
 * round-tripped byte-for-byte — before this class was written. Re-run that
 * exact self-test with `php tools/webpush-selftest.php`.
 *
 * Governing RFCs (cited inline at each step):
 *   RFC 8030 — HTTP Web Push (the delivery POST; TTL/Urgency/Topic headers)
 *   RFC 8291 — Message Encryption for Web Push (aes128gcm content coding)
 *   RFC 8292 — Voluntary Application Server Identification (VAPID)
 *   RFC 8188 — Encrypted Content-Encoding for HTTP (the aes128gcm wire format)
 *
 * INERT UNTIL CONFIGURED: every public send-path method starts with
 * isConfigured() (or degrades to a no-op internally) — an install with no
 * VAPID keys set does nothing, ever, on any code path. See
 * `/admin/integrations/push` for the owner-facing setup flow.
 *
 * Two DIFFERENT P-256 key pairs are used for two DIFFERENT jobs — conflating
 * them is a well-known implementer error:
 *   - The VAPID pair (`push.vapidPublicKey` / `push.vapidPrivateKey`,
 *     long-lived, one per install) — ONLY signs the `Authorization: vapid`
 *     JWT (RFC 8292 §3). Never used for ECDH.
 *   - The "as" (application server) pair — a FRESH, single-use, in-memory
 *     keypair generated PER MESSAGE inside encryptPayload() (RFC 8291 §3.1).
 *     Never persisted, never reused across sends.
 *
 * SSRF: the push endpoint URL is attacker-influenced (any subscriber
 * controls their own `endpoint` string). validateEndpoint() is the single
 * gate used at BOTH subscribe time (push/api/subscribe.php) and send time
 * (send(), below) — https-only, no userinfo, no IP-literal/local host, an
 * admin-editable host-suffix allowlist (`push.endpointHostAllowlist`).
 * Outbound cURL never follows redirects (CURLOPT_FOLLOWLOCATION=false,
 * CURLPROTO_HTTPS-restricted).
 *
 * Secrets: the VAPID private key is sodium-encrypted at rest via the house
 * encrypt_setting()/decrypt_setting() helpers (bootstrap.php) — never
 * echoed to any page, never sent to the client, never logged. Only the
 * PUBLIC key ever reaches the browser (publicKey(), for pushManager.subscribe).
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026 MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/322
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class WebPush
{
    /** @var int Hard payload cap — RFC 8030 §7.2 asks services to support ≥4096B ciphertext; leave headroom for the 86-byte aes128gcm header + 16-byte tag. */
    private const MAX_PLAINTEXT_BYTES = 3900;

    /** @var int Fan-out cap per sendToChannel() call — shared-hosting reality; sends are sequential/single-threaded. */
    private const MAX_FANOUT = 500;

    /** @var int Consecutive-failure threshold before a subscription is deactivated (RFC 8030 §7.3 prune policy). */
    private const MAX_FAIL_COUNT = 8;

    /** SPKI DER prefix for an uncompressed P-256 point (identical constant to WebAuthn::coseToPem, WebAuthn.php:513-518). */
    private const P256_SPKI_PREFIX = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00";

    /** @var array<string, string> Per-request VAPID JWT cache, keyed by "origin|contact" — the JWT is identical for every subscription sharing a push-service origin (RFC 8292 §2: aud = origin only). */
    private static array $jwtCache = [];

    // =========================================================================
    // 🚦 Configuration state
    // =========================================================================

    /**
     * Is Web Push fully configured and enabled? Gates EVERY send path.
     * decrypt_setting() returns '' on any failure (corrupt/rotated key file),
     * so a broken encrypted value fails INERT here, never fatal downstream.
     */
    public static function isConfigured(): bool
    {
        if ((string) (App::settings('push.enabled') ?? 'false') !== 'true') {
            return false;
        }
        if ((string) (App::settings('push.vapidPublicKey') ?? '') === '') {
            return false;
        }
        if ((string) (App::settings('push.contact') ?? '') === '') {
            return false;
        }
        $encPriv = (string) (App::settings('push.vapidPrivateKey') ?? '');
        if ($encPriv === '' || function_exists('decrypt_setting') === false) {
            return false;
        }
        return decrypt_setting($encPriv) !== '';
    }

    /**
     * The PUBLIC VAPID key, base64url-encoded uncompressed P-256 point —
     * safe to render into any page (used as `applicationServerKey` for
     * `pushManager.subscribe`). Empty string when unconfigured, so a
     * template can gate the subscribe button on `data-vapid-key !== ''`.
     */
    public static function publicKey(): string
    {
        if (self::isConfigured() === false) {
            return '';
        }
        return (string) (App::settings('push.vapidPublicKey') ?? '');
    }

    // =========================================================================
    // 🔑 Key generation / import (admin config page)
    // =========================================================================

    /**
     * Generate a fresh VAPID P-256 key pair.
     *
     * @return array{publicKey: string, privateKeyPem: string}
     *
     * @throws \RuntimeException If ext-openssl can't produce a prime256v1 key
     *                            (should never happen on a supported host).
     */
    public static function generateKeys(): array
    {
        $res = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($res === false) {
            throw new \RuntimeException('EC key generation failed — ext-openssl may lack prime256v1 support.');
        }
        $pem = '';
        openssl_pkey_export($res, $pem);
        $details = openssl_pkey_get_details($res);
        $point = self::pointFromDetails($details);
        if ($point === null) {
            throw new \RuntimeException('Generated EC key did not expose x/y coordinates.');
        }
        return ['publicKey' => self::b64url($point), 'privateKeyPem' => $pem];
    }

    /**
     * Validate a pasted VAPID private key and return the DERIVED public key
     * (base64url uncompressed point), or null on garbage input. Accepts
     * EITHER a full PEM (e.g. `openssl ecparam -name prime256v1 -genkey`
     * output, or this class's own generateKeys() export) OR the bare
     * 43-char base64url 32-byte scalar `d` some tools export standalone
     * (the web-push-CLI format) — resolvePrivateKey() normalises both.
     *
     * Does NOT persist anything — the caller (admin/integrations/push/save.php)
     * encrypts + stores the ORIGINAL pasted text verbatim via encrypt_setting()
     * and stores this method's return value as the public key.
     */
    public static function importPrivateKey(string $pasted): ?string
    {
        $key = self::resolvePrivateKey($pasted);
        if ($key === false) {
            return null;
        }
        $point = self::pointFromDetails(openssl_pkey_get_details($key));
        return $point !== null ? self::b64url($point) : null;
    }

    /**
     * Normalise a stored/pasted VAPID private key value into an OpenSSL key
     * resource. Two accepted shapes:
     *   1. A full PEM ("-----BEGIN EC PRIVATE KEY-----…") — parsed directly.
     *   2. A bare base64url 32-byte scalar `d` (web-push-CLI format) —
     *      reconstructed to a minimal RFC 5915 ECPrivateKey DER
     *      (SEQUENCE{ INTEGER 1, OCTET STRING d(32), [0]{OID prime256v1} })
     *      and parsed. Empirically validated (tools/webpush-selftest.php):
     *      ext-openssl derives the matching public point from the scalar
     *      alone at parse time, so no [1] publicKey tag is required.
     */
    private static function resolvePrivateKey(string $raw): \OpenSSLAsymmetricKey|false
    {
        $raw = trim($raw);
        if ($raw === '') {
            return false;
        }
        if (str_contains($raw, '-----BEGIN') === true) {
            $key = openssl_pkey_get_private($raw);
            return $key instanceof \OpenSSLAsymmetricKey ? $key : false;
        }
        $d = self::b64urlDecode($raw);
        if (strlen($d) !== 32) {
            return false;
        }
        $der = "\x30\x31\x02\x01\x01\x04\x20" . $d . "\xA0\x0A\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
        $pem = "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
        $key = openssl_pkey_get_private($pem);
        return $key instanceof \OpenSSLAsymmetricKey ? $key : false;
    }

    // =========================================================================
    // 📤 Sending
    // =========================================================================

    /**
     * Send one push message to one subscription row.
     *
     * @param array<string, mixed> $sub tblPushSubscriptions row — needs
     *                                  subID, endpoint, p256dhKey, authKey.
     * @param string $json    Pre-encoded JSON payload (caller encodes once
     *                        for a whole fan-out — see sendToChannel()).
     * @param int    $ttl     RFC 8030 §5.2 TTL header, seconds.
     * @param string $urgency RFC 8030 §5.3 — very-low|low|normal|high.
     * @param string|null $topic RFC 8030 §5.4 collapse key, ≤32 chars.
     *
     * @return int HTTP status from the push service; 0 = transport error;
     *             -1 = refused pre-flight (bad endpoint or oversized payload).
     */
    public static function send(array $sub, string $json, int $ttl, string $urgency = 'normal', ?string $topic = null): int
    {
        $endpoint = (string) ($sub['endpoint'] ?? '');
        if (self::validateEndpoint($endpoint) === false) {
            return -1;
        }
        if (strlen($json) > self::MAX_PLAINTEXT_BYTES) {
            Logger::activity('WebPushPayloadTooLarge', 'bytes=' . strlen($json) . ' cap=' . self::MAX_PLAINTEXT_BYTES);
            return -1;
        }

        $body = self::encryptPayload((string) ($sub['p256dhKey'] ?? ''), (string) ($sub['authKey'] ?? ''), $json);
        if ($body === null) {
            return -1;
        }
        $auth = self::vapidAuthHeader($endpoint);
        if ($auth === null) {
            return -1;
        }

        $urgency = in_array($urgency, ['very-low', 'low', 'normal', 'high'], true) ? $urgency : 'normal';
        $headers = [
            'Authorization: ' . $auth,
            'Content-Encoding: aes128gcm',
            'Content-Type: application/octet-stream',
            'TTL: ' . max(0, $ttl),
            'Urgency: ' . $urgency,
        ];
        if ($topic !== null && $topic !== '') {
            $safeTopic = preg_replace('/[^A-Za-z0-9_\-]/', '', $topic) ?? '';
            if ($safeTopic !== '') {
                $headers[] = 'Topic: ' . substr($safeTopic, 0, 32);
            }
        }

        $status = 0;
        // 🛡️ SSRF hardening (WebhookDispatcher.php:276-288 shape, tightened):
        //    never follow a redirect, HTTPS-only transport, short timeouts.
        $ch = curl_init($endpoint);
        if ($ch !== false) {
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS      => 0,
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => 10,
            ]);
            curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
        }

        self::recordOutcome((int) ($sub['subID'] ?? 0), $status);

        return $status;
    }

    /**
     * Fan-out helper — both channel wirings (go-live, service reminders)
     * call this. Selects active subscriptions on the channel for the site,
     * optionally narrowed to specific users and/or gated by a notifyPrefs
     * key (anonymous rows always pass — their `channels` opt-in is their
     * only, sufficient, consent).
     *
     * @param array<string, mixed> $payload ['title'=>…, 'body'=>…, 'url'=>…, 'tag'=>…]
     * @param int[]|null $onlyUserIds Restrict to these userIDs (per-RSVP reminders); null = whole channel.
     *
     * @return array{sent: int, failed: int, pruned: int}
     */
    public static function sendToChannel(
        int $siteId,
        string $channel,
        array $payload,
        int $ttl,
        string $urgency = 'normal',
        ?string $topic = null,
        ?string $notifyPrefKey = null,
        ?array $onlyUserIds = null
    ): array {
        $result = ['sent' => 0, 'failed' => 0, 'pruned' => 0];
        if (self::isConfigured() === false) {
            return $result;
        }
        if ($onlyUserIds !== null && count($onlyUserIds) === 0) {
            return $result;
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false || strlen($json) > self::MAX_PLAINTEXT_BYTES) {
            Logger::activity('WebPushPayloadTooLarge', 'channel=' . $channel . ' site=' . $siteId);
            return $result;
        }

        try {
            $db = App::db();

            // 🧹 Channel membership check via LIKE on the whitelist-constrained
            //    `channels` JSON column (subscribe.php only ever writes values
            //    from a fixed 3-item whitelist, so a substring match here can't
            //    be tricked by attacker-controlled channel text).
            $channelLike = '%"' . $channel . '"%';
            $sql = 'SELECT ps.subID, ps.endpoint, ps.p256dhKey, ps.authKey, ps.userID, u.notifyPrefs '
                 . 'FROM tblPushSubscriptions ps '
                 . 'LEFT JOIN tblUsers u ON u.userID = ps.userID '
                 . 'WHERE ps.siteID = ? AND ps.isActive = 1 AND ps.channels LIKE ?';
            $types  = 'is';
            $params = [$siteId, $channelLike];
            if ($onlyUserIds !== null) {
                $ids = array_values(array_unique(array_map('intval', $onlyUserIds)));
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $sql   .= ' AND ps.userID IN (' . $placeholders . ')';
                $types .= str_repeat('i', count($ids));
                foreach ($ids as $uid) {
                    $params[] = $uid;
                }
            }
            $sql .= ' ORDER BY ps.subID LIMIT ' . self::MAX_FANOUT;

            $stmt = $db->prepare($sql);
            if ($stmt === false) {
                return $result;
            }
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $rs = $stmt->get_result();
            $rows = [];
            while (($r = $rs->fetch_assoc()) !== null) {
                $rows[] = $r;
            }
            $stmt->close();

            if (count($rows) >= self::MAX_FANOUT) {
                Logger::activity('WebPushFanoutTruncated', 'channel=' . $channel . ' site=' . $siteId . ' cap=' . self::MAX_FANOUT);
            }

            foreach ($rows as $row) {
                if ($notifyPrefKey !== null && $row['userID'] !== null) {
                    $prefsJson = $row['notifyPrefs'] !== null ? (string) $row['notifyPrefs'] : null;
                    if (self::notifyPrefAllows($prefsJson, $notifyPrefKey) === false) {
                        continue;
                    }
                }
                $status = self::send($row, $json, $ttl, $urgency, $topic);
                if ($status >= 200 && $status < 300) {
                    $result['sent']++;
                } elseif ($status === 404 || $status === 410) {
                    $result['pruned']++;
                } else {
                    $result['failed']++;
                }
            }
        } catch (\Throwable $e) {
            // 🛡️ Push is never allowed to break the page/cron that triggered
            //    it (Livestream.php:62-64 try/catch precedent). Counts only —
            //    never log endpoint/key material.
            Logger::activity('WebPushSendToChannelFailed', 'channel=' . $channel . ' site=' . $siteId . ' err=' . mb_substr($e->getMessage(), 0, 120));
        }

        return $result;
    }

    // =========================================================================
    // 🛡️ SSRF — endpoint validation (subscribe-time AND send-time gate)
    // =========================================================================

    /**
     * Validate a push subscription endpoint URL. Called at BOTH subscribe
     * time (push/api/subscribe.php rejects a bad endpoint with 400) and
     * send time (send() refuses with -1) — the endpoint is attacker-
     * influenced (any subscriber controls their own `endpoint` string), so
     * this is the single SSRF gate both call sites share.
     *
     * Rules: https only; no userinfo; no port other than the implicit 443;
     * host is not an IP literal; host is not localhost/*.local/*.internal;
     * host suffix-matches the admin-editable `push.endpointHostAllowlist`
     * (dot-boundary suffix match — host === entry OR host ends with
     * ".entry", so "sub.fcm.googleapis.com" matches an allowlisted
     * "fcm.googleapis.com" but "evilfcm.googleapis.com.attacker.test"
     * does not).
     */
    public static function validateEndpoint(string $url): bool
    {
        $parts = parse_url($url);
        if (is_array($parts) === false) {
            return false;
        }
        if (($parts['scheme'] ?? '') !== 'https') {
            return false;
        }
        if (isset($parts['user']) === true || isset($parts['pass']) === true) {
            return false;
        }
        if (isset($parts['port']) === true && (int) $parts['port'] !== 443) {
            return false;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }
        if ($host === 'localhost' || str_ends_with($host, '.local') === true || str_ends_with($host, '.internal') === true) {
            return false;
        }

        $allowlistRaw = (string) (App::settings('push.endpointHostAllowlist') ?? '');
        foreach (explode(',', $allowlistRaw) as $entry) {
            $suffix = strtolower(trim($entry));
            if ($suffix === '') {
                continue;
            }
            if ($host === $suffix || str_ends_with($host, '.' . $suffix) === true) {
                return true;
            }
        }

        return false;
    }

    // =========================================================================
    // 🔐 RFC 8291 §3-4 + RFC 8188 aes128gcm payload encryption
    // =========================================================================

    /**
     * Encrypt one message body per RFC 8291 (with the RFC 8188 aes128gcm
     * wire format). Returns the full wire body (86-byte header + ciphertext
     * + 16-byte tag), or null on any crypto failure (never throws out of
     * here — the caller's fan-out loop must keep going for other subs).
     */
    private static function encryptPayload(string $p256dhB64, string $authB64, string $plaintext): ?string
    {
        try {
            $uaPub      = self::b64urlDecode($p256dhB64);
            $authSecret = self::b64urlDecode($authB64);
            if (strlen($uaPub) !== 65 || strlen($authSecret) !== 16) {
                return null;
            }

            // Step 1 (RFC 8291 §3.1) — a FRESH ephemeral server ("as") P-256
            // key pair, generated PER MESSAGE. Never the VAPID pair, never reused.
            $asRes = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
            if ($asRes === false) {
                return null;
            }
            $asPub = self::pointFromDetails(openssl_pkey_get_details($asRes));
            if ($asPub === null) {
                return null;
            }

            // Step 2 — ECDH(as_private, ua_public), 32 bytes.
            $uaPubKey = openssl_pkey_get_public(self::p256PointToPem($uaPub));
            if ($uaPubKey === false) {
                return null;
            }
            $ecdh = openssl_pkey_derive($uaPubKey, $asRes);
            if (is_string($ecdh) === false || strlen($ecdh) !== 32) {
                return null;
            }

            // Step 3 — IKM = HKDF-SHA256(salt=authSecret, ikm=ecdh,
            //   info="WebPush: info"‖0x00‖ua_pub‖as_pub, L=32). 144-byte info.
            $ikm = hash_hkdf('sha256', $ecdh, 32, "WebPush: info\x00" . $uaPub . $asPub, $authSecret);

            // Step 4 (RFC 8188 §2.1) — fresh random salt.
            $salt = random_bytes(16);

            // Step 5 — CEK = HKDF-SHA256(salt, IKM, "Content-Encoding: aes128gcm"‖0x00, 16).
            $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);

            // Step 6 — NONCE = HKDF-SHA256(salt, IKM, "Content-Encoding: nonce"‖0x00, 12).
            //   Single record (seq=0) ⇒ used as-is.
            $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

            // Step 7 (RFC 8291 §4) — single-record delimiter 0x02 (last record).
            $record = $plaintext . "\x02";

            // Step 8 — AES-128-GCM, 16-byte tag.
            $ct = openssl_encrypt($record, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
            if ($ct === false) {
                return null;
            }

            // Step 9 (RFC 8188 §2.1) — 86-byte header: salt(16) ‖ rs=4096(uint32 BE) ‖ idlen=65 ‖ keyid=as_pub(65).
            return $salt . pack('N', 4096) . chr(65) . $asPub . $ct . $tag;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // =========================================================================
    // 🪪 RFC 8292 VAPID JWT
    // =========================================================================

    /**
     * Build the `Authorization: vapid t=<jwt>, k=<pubkey>` header value for
     * one endpoint's push-service origin. Cached per (origin, contact) for
     * the lifetime of the request — a 200-subscription fan-out to the same
     * push service signs once, not 200 times.
     */
    private static function vapidAuthHeader(string $endpoint): ?string
    {
        $origin = self::originOf($endpoint);
        if ($origin === null) {
            return null;
        }
        $contact = (string) (App::settings('push.contact') ?? '');
        if ($contact === '') {
            return null;
        }
        $cacheKey = $origin . '|' . $contact;
        if (isset(self::$jwtCache[$cacheKey]) === true) {
            return self::$jwtCache[$cacheKey];
        }

        $pubB64 = (string) (App::settings('push.vapidPublicKey') ?? '');
        $encPriv = (string) (App::settings('push.vapidPrivateKey') ?? '');
        if ($pubB64 === '' || $encPriv === '' || function_exists('decrypt_setting') === false) {
            return null;
        }
        $privRaw = decrypt_setting($encPriv);
        if ($privRaw === '') {
            return null;
        }
        $key = self::resolvePrivateKey($privRaw);
        if ($key === false) {
            Logger::activity('WebPushVapidKeyInvalid', 'Stored VAPID private key failed to parse');
            return null;
        }

        // RFC 8292 §2 — aud is the push-service ORIGIN only; exp ≤ 24h (12h used).
        $header = self::b64url((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256'], JSON_UNESCAPED_SLASHES));
        $claims = self::b64url((string) json_encode(['aud' => $origin, 'exp' => time() + 43200, 'sub' => $contact], JSON_UNESCAPED_SLASHES));
        $signingInput = $header . '.' . $claims;

        // RFC 7518 §3.4 — ECDSA P-256/SHA-256. openssl_sign() yields a DER
        // ECDSA-Sig-Value; MUST convert to raw 64-byte JOSE R‖S — shipping
        // the DER bytes raw is the classic silent-401 bug (every real push
        // service rejects it).
        $signOk = openssl_sign($signingInput, $der, $key, OPENSSL_ALGO_SHA256);
        if ($signOk === false) {
            return null;
        }
        $jose = self::derToJose($der);
        if ($jose === null) {
            return null;
        }

        $jwt = $signingInput . '.' . self::b64url($jose);
        $auth = 'vapid t=' . $jwt . ', k=' . $pubB64;
        self::$jwtCache[$cacheKey] = $auth;

        return $auth;
    }

    /**
     * Convert a DER `ECDSA-Sig-Value` (SEQUENCE{ INTEGER r, INTEGER s })
     * into the raw 64-byte JOSE `R‖S` serialisation RFC 7518 §3.4 requires.
     * Strips each INTEGER's leading DER sign-disambiguation zero byte(s),
     * then left-pads to exactly 32 bytes. Returns null on malformed input.
     */
    private static function derToJose(string $der): ?string
    {
        if (strlen($der) < 8 || ord($der[0]) !== 0x30) {
            return null;
        }
        $offset = 1;
        [, $offset] = self::readDerLength($der, $offset);

        if (($der[$offset] ?? '') === '' || ord($der[$offset]) !== 0x02) {
            return null;
        }
        $offset++;
        [$rLen, $offset] = self::readDerLength($der, $offset);
        $r = substr($der, $offset, $rLen);
        $offset += $rLen;

        if (($der[$offset] ?? '') === '' || ord($der[$offset]) !== 0x02) {
            return null;
        }
        $offset++;
        [$sLen, $offset] = self::readDerLength($der, $offset);
        $s = substr($der, $offset, $sLen);

        $r = ltrim($r, "\x00");
        $s = ltrim($s, "\x00");
        if (strlen($r) > 32 || strlen($s) > 32) {
            return null;
        }

        return str_pad($r, 32, "\x00", STR_PAD_LEFT) . str_pad($s, 32, "\x00", STR_PAD_LEFT);
    }

    /**
     * Read one DER length octet (short or long form) starting at $offset.
     *
     * @return array{0: int, 1: int} [length, newOffset]
     */
    private static function readDerLength(string $der, int $offset): array
    {
        $b = ord($der[$offset] ?? "\x00");
        $offset++;
        if (($b & 0x80) === 0) {
            return [$b, $offset];
        }
        $numBytes = $b & 0x7F;
        $len = 0;
        for ($i = 0; $i < $numBytes; $i++) {
            $len = ($len << 8) | ord($der[$offset] ?? "\x00");
            $offset++;
        }
        return [$len, $offset];
    }

    // =========================================================================
    // 🧰 Small shared helpers
    // =========================================================================

    /**
     * Wrap a raw 65-byte uncompressed P-256 point into an SPKI PEM public
     * key OpenSSL can load (identical DER prefix to WebAuthn::coseToPem —
     * WebAuthn.php:513-518 — the exact constants this class needs already
     * ship in-house).
     */
    private static function p256PointToPem(string $point): string
    {
        $der = self::P256_SPKI_PREFIX . $point;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /**
     * Extract the raw 65-byte uncompressed point (0x04‖x‖y) from an
     * openssl_pkey_get_details() result, left-padding each 32-byte
     * coordinate (php-openssl can return a 31-byte string when the
     * coordinate's natural big-endian form has a leading zero byte).
     */
    private static function pointFromDetails(array|false $details): ?string
    {
        if ($details === false) {
            return null;
        }
        $x = $details['ec']['x'] ?? null;
        $y = $details['ec']['y'] ?? null;
        if (is_string($x) === false || is_string($y) === false || $x === '' || $y === '') {
            return null;
        }
        return "\x04" . str_pad($x, 32, "\x00", STR_PAD_LEFT) . str_pad($y, 32, "\x00", STR_PAD_LEFT);
    }

    /** Base64url encode (RFC 4648 §5), no padding. */
    private static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    /** Base64url decode, tolerant of missing padding. Returns '' on garbage. */
    private static function b64urlDecode(string $str): string
    {
        $str = strtr($str, '-_', '+/');
        $pad = strlen($str) % 4;
        if ($pad > 0) {
            $str .= str_repeat('=', 4 - $pad);
        }
        $out = base64_decode($str, true);
        return $out === false ? '' : $out;
    }

    /** scheme://host[:port] only (RFC 8292 §2 `aud` — origin, not full URL). */
    private static function originOf(string $url): ?string
    {
        $parts = parse_url($url);
        if (is_array($parts) === false || empty($parts['scheme']) === true || empty($parts['host']) === true) {
            return null;
        }
        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port']) === true) {
            $origin .= ':' . $parts['port'];
        }
        return $origin;
    }

    /**
     * Same missing-key-is-true semantics as cron/user-reminders.php's
     * notifyPrefAllows() (#439) — a user who has never touched the toggle,
     * or whose notifyPrefs JSON predates this feature, still gets notified.
     */
    private static function notifyPrefAllows(?string $notifyPrefsJson, string $key): bool
    {
        if ($notifyPrefsJson === null || trim($notifyPrefsJson) === '') {
            return true;
        }
        $prefs = json_decode($notifyPrefsJson, true);
        if (is_array($prefs) === false || array_key_exists($key, $prefs) === false) {
            return true;
        }
        return $prefs[$key] !== false;
    }

    /**
     * Record the outcome of one send() against its subscription row —
     * RFC 8030 §7.3 prune policy. 2xx → refresh lastUsedAt, reset
     * failCount. 404/410 → dead, deactivate immediately. Anything else
     * (429/5xx/timeout) → bump failCount; MAX_FAIL_COUNT consecutive
     * failures deactivates (push endpoints are cheap to re-create — the
     * client re-subscribes on next visit). Never throws.
     */
    private static function recordOutcome(int $subId, int $status): void
    {
        if ($subId <= 0) {
            return;
        }
        try {
            $db = App::db();
            if ($status >= 200 && $status < 300) {
                $stmt = $db->prepare('UPDATE tblPushSubscriptions SET lastUsedAt = NOW(), failCount = 0, lastHttpStatus = ? WHERE subID = ?');
                if ($stmt !== false) {
                    $stmt->bind_param('ii', $status, $subId);
                    $stmt->execute();
                    $stmt->close();
                }
            } elseif ($status === 404 || $status === 410) {
                $stmt = $db->prepare('UPDATE tblPushSubscriptions SET isActive = 0, lastHttpStatus = ? WHERE subID = ?');
                if ($stmt !== false) {
                    $stmt->bind_param('ii', $status, $subId);
                    $stmt->execute();
                    $stmt->close();
                }
            } else {
                $maxFail = self::MAX_FAIL_COUNT;
                $stmt = $db->prepare(
                    'UPDATE tblPushSubscriptions '
                    . 'SET failCount = failCount + 1, lastFailureAt = NOW(), lastHttpStatus = ?, '
                    . '    isActive = IF(failCount + 1 >= ?, 0, isActive) '
                    . 'WHERE subID = ?'
                );
                if ($stmt !== false) {
                    $stmt->bind_param('iii', $status, $maxFail, $subId);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        } catch (\Throwable $e) {
            // 🛡️ Bookkeeping never breaks the send path.
        }
    }
}
