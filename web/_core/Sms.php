<?php
// Path: _core/Sms.php
/**
 * -----------------------------------------------------------------------------
 * SMS dispatch + provider abstraction 📱
 * -----------------------------------------------------------------------------
 * Send time-critical SMS via a pluggable provider (Twilio, MessageBird, AWS
 * SNS). Enforces per-user verification, per-category opt-in, per-site daily
 * cap, and Sabbath quiet-hours deferral for non-critical categories.
 *
 *   sms.provider = twilio | messagebird | aws
 *
 * Critical-alerting integration: Logger::criticalAlert() / wherever the
 * critical pipeline lives can call Sms::sendCategory() to fan out to all
 * verified subscribers of a category, capped + cost-tracked.
 *
 * @package   Portal\Core
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/272
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class Sms
{
    public const CATEGORIES = [
        'critical_alerts',
        'rota_changes',
        'emergency_comms',
        'newsletter_digest',
        // 🙏 Prayer-partner assignment pings (#311) — opt-in via /account/sms
        // like every other category; PrayerChain::notifyAssignment() checks
        // this via FIND_IN_SET before calling Sms::send().
        'prayer_assignment',
    ];

    /**
     * Send to one recipient. Returns true on provider accept; records a
     * row in tblSmsMessage either way for cost + audit.
     */
    public static function send(int $siteId, string $number, string $body, string $category = 'general', ?int $userId = null): bool
    {
        $settings = App::settings()['sms'] ?? [];
        if ((string) ($settings['enabled'] ?? '0') !== '1') {
            return false;
        }

        // Daily cap — block before the wire to avoid runaway cost.
        if (self::sentTodayCount($siteId) >= (int) ($settings['dailyCap'] ?? 100)) {
            self::record($siteId, $userId, $number, $body, $category, 'failed', null, null, 'daily-cap');
            return false;
        }

        // Quiet hours — defer non-critical for users in their Sabbath window.
        if ($category !== 'critical_alerts' && $userId !== null && Sabbath::isQuietNow($userId) === true) {
            self::record($siteId, $userId, $number, $body, $category, 'queued', null, null, 'quiet-hours');
            return false;
        }

        $provider = (string) ($settings['provider'] ?? 'twilio');
        $result = self::providerSend($provider, $settings, $number, $body);

        $status = $result['ok'] === true ? 'sent' : 'failed';
        self::record($siteId, $userId, $number, $body, $category, $status, $result['ref'], $result['costPence'], $result['error']);

        return $result['ok'];
    }

    /**
     * Send to every opted-in, verified subscriber for a category on this site.
     * Returns ['sent' => N, 'skipped' => N].
     */
    public static function sendCategory(int $siteId, string $category, string $body): array
    {
        $db = App::db();
        $stmt = $db->prepare(
            'SELECT userID, phoneNumber FROM tblUserSmsPreference '
            . 'WHERE siteID = ? AND isVerified = 1 AND FIND_IN_SET(?, categories) > 0'
        );
        $sent = 0;
        $skipped = 0;
        if ($stmt !== false) {
            $stmt->bind_param('is', $siteId, $category);
            $stmt->execute();
            $rs = $stmt->get_result();
            while ($r = $rs->fetch_assoc()) {
                $ok = self::send($siteId, (string) $r['phoneNumber'], $body, $category, (int) $r['userID']);
                if ($ok === true) {
                    $sent++;
                } else {
                    $skipped++;
                }
            }
            $stmt->close();
        }
        return ['sent' => $sent, 'skipped' => $skipped];
    }

    /**
     * Per-day count of attempted sends for a site (used by the daily cap).
     */
    public static function sentTodayCount(int $siteId): int
    {
        $db = App::db();
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM tblSmsMessage WHERE siteID = ? '
            . 'AND status IN ("sent","delivered") AND DATE(createdAt) = CURDATE()'
        );
        if ($stmt === false) {
            return 0;
        }
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        $stmt->bind_result($n);
        $stmt->fetch();
        $stmt->close();
        return (int) $n;
    }

    /**
     * Per-day cost rollup, in pence.
     */
    public static function monthSpendPence(int $siteId): int
    {
        $db = App::db();
        $stmt = $db->prepare(
            'SELECT COALESCE(SUM(costPence), 0) FROM tblSmsMessage '
            . 'WHERE siteID = ? AND createdAt >= DATE_FORMAT(NOW(), "%Y-%m-01")'
        );
        if ($stmt === false) {
            return 0;
        }
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        $stmt->bind_result($s);
        $stmt->fetch();
        $stmt->close();
        return (int) $s;
    }

    /**
     * Generate + store a 6-digit verification code (10-min lifetime),
     * then send it to the number. Returns true on dispatch success.
     */
    public static function startVerification(int $siteId, int $userId, string $number): bool
    {
        $code = (string) random_int(100000, 999999);
        $db = App::db();
        $stmt = $db->prepare(
            'INSERT INTO tblUserSmsPreference (siteID, userID, phoneNumber, verificationCode, verificationExpires) '
            . 'VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE)) '
            . 'ON DUPLICATE KEY UPDATE phoneNumber = VALUES(phoneNumber), '
            . '    isVerified = 0, '
            . '    verificationCode = VALUES(verificationCode), '
            . '    verificationExpires = VALUES(verificationExpires)'
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('iiss', $siteId, $userId, $number, $code);
        $stmt->execute();
        $stmt->close();

        return self::send($siteId, $number, 'Your portal verification code is ' . $code . '. It expires in 10 minutes.', 'critical_alerts', $userId);
    }

    /**
     * Verify a user-entered code. Returns true on match (and flags row
     * verified, clears the code).
     */
    public static function completeVerification(int $siteId, int $userId, string $code): bool
    {
        $db = App::db();
        $stmt = $db->prepare(
            'UPDATE tblUserSmsPreference SET isVerified = 1, verificationCode = NULL, verificationExpires = NULL '
            . 'WHERE siteID = ? AND userID = ? AND verificationCode = ? '
            . 'AND verificationExpires IS NOT NULL AND verificationExpires >= NOW()'
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('iis', $siteId, $userId, $code);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        return $affected > 0;
    }

    /**
     * Light E.164 normalisation: strip spaces, accept leading + or 0.
     * Caller stores the result; providers reject invalid numbers at send.
     */
    public static function normaliseNumber(string $raw): string
    {
        $cleaned = preg_replace('/[^0-9+]/', '', $raw);
        if ($cleaned === null || $cleaned === '') {
            return '';
        }
        if (str_starts_with($cleaned, '0') === true && str_starts_with($cleaned, '00') === false) {
            // Assume UK if dialled with leading 0 — caller can override later.
            $cleaned = '+44' . substr($cleaned, 1);
        }
        return $cleaned;
    }

    private static function record(int $siteId, ?int $userId, string $number, string $body, string $category, string $status, ?string $providerRef, ?int $costPence, ?string $error): void
    {
        $db = App::db();
        $stmt = $db->prepare(
            'INSERT INTO tblSmsMessage (siteID, recipientUserID, recipientNumber, body, category, status, '
            . ' provider, providerRef, costPence, errorMsg, sentAt) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        if ($stmt === false) {
            return;
        }
        $provider = (string) (App::settings()['sms']['provider'] ?? 'twilio');
        $stmt->bind_param(
            'iissssssis',
            $siteId, $userId, $number, $body, $category, $status, $provider, $providerRef, $costPence, $error
        );
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Dispatch to the configured provider. Each provider returns
     * ['ok' => bool, 'ref' => string|null, 'costPence' => int|null,
     *  'error' => string|null] so the caller can persist a uniform row.
     */
    private static function providerSend(string $provider, array $settings, string $number, string $body): array
    {
        switch ($provider) {
            case 'twilio':
                return self::sendTwilio($settings, $number, $body);
            case 'messagebird':
                return self::sendMessageBird($settings, $number, $body);
            case 'aws':
                return self::sendAwsSns($settings, $number, $body);
            default:
                return ['ok' => false, 'ref' => null, 'costPence' => null, 'error' => 'unknown-provider'];
        }
    }

    /**
     * Twilio Messages API. Basic-auth with SID + token.
     *
     * @link https://www.twilio.com/docs/sms/send-messages
     */
    private static function sendTwilio(array $settings, string $number, string $body): array
    {
        $sid    = (string) ($settings['twilio']['sid'] ?? '');
        $token  = (string) ($settings['twilio']['token'] ?? '');
        $from   = (string) ($settings['fromNumber'] ?? '');
        if ($sid === '' || $token === '' || $from === '') {
            return ['ok' => false, 'ref' => null, 'costPence' => null, 'error' => 'twilio-not-configured'];
        }
        $url = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($sid) . '/Messages.json';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'To'   => $number,
            'From' => $from,
            'Body' => $body,
        ]));
        curl_setopt($ch, CURLOPT_USERPWD, $sid . ':' . $token);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false || $code < 200 || $code >= 300) {
            return ['ok' => false, 'ref' => null, 'costPence' => null, 'error' => 'twilio-http-' . $code];
        }
        $body = json_decode((string) $resp, true);
        $ref  = is_array($body) === true ? (string) ($body['sid'] ?? '') : '';
        // Twilio doesn't return price on initial accept (it's added on the
        // status callback); 4p is a safe pessimistic placeholder.
        return ['ok' => true, 'ref' => $ref !== '' ? $ref : null, 'costPence' => 4, 'error' => null];
    }

    /**
     * MessageBird Messages API. Authorization header with the API key.
     *
     * @link https://developers.messagebird.com/api/sms-messaging/
     */
    private static function sendMessageBird(array $settings, string $number, string $body): array
    {
        $key  = (string) ($settings['messagebird']['apiKey'] ?? '');
        $from = (string) ($settings['fromNumber'] ?? '');
        if ($key === '' || $from === '') {
            return ['ok' => false, 'ref' => null, 'costPence' => null, 'error' => 'messagebird-not-configured'];
        }
        $ch = curl_init('https://rest.messagebird.com/messages');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'recipients' => $number,
            'originator' => $from,
            'body'       => $body,
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: AccessKey ' . $key,
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false || $code < 200 || $code >= 300) {
            return ['ok' => false, 'ref' => null, 'costPence' => null, 'error' => 'messagebird-http-' . $code];
        }
        $body = json_decode((string) $resp, true);
        $ref  = is_array($body) === true ? (string) ($body['id'] ?? '') : '';
        return ['ok' => true, 'ref' => $ref !== '' ? $ref : null, 'costPence' => 5, 'error' => null];
    }

    /**
     * AWS SNS Publish. Uses SigV4-signed POST to the regional endpoint.
     * This is the most fiddly of the three — minimal canonical request
     * + string-to-sign + hmac-sha256 chain.
     *
     * @link https://docs.aws.amazon.com/sns/latest/api/API_Publish.html
     */
    private static function sendAwsSns(array $settings, string $number, string $body): array
    {
        $accessKey = (string) ($settings['aws']['accessKey'] ?? '');
        $secret    = (string) ($settings['aws']['secret'] ?? '');
        $region    = (string) ($settings['aws']['region'] ?? 'eu-west-1');
        if ($accessKey === '' || $secret === '') {
            return ['ok' => false, 'ref' => null, 'costPence' => null, 'error' => 'aws-not-configured'];
        }
        $host    = 'sns.' . $region . '.amazonaws.com';
        $payload = http_build_query([
            'Action'      => 'Publish',
            'PhoneNumber' => $number,
            'Message'     => $body,
            'Version'     => '2010-03-31',
        ]);
        $ts       = gmdate('Ymd\THis\Z');
        $date     = gmdate('Ymd');
        $bodyHash = hash('sha256', $payload);

        $canonical  = "POST\n/\n\ncontent-type:application/x-www-form-urlencoded\nhost:" . $host
            . "\nx-amz-date:" . $ts . "\n\ncontent-type;host;x-amz-date\n" . $bodyHash;
        $scope      = $date . '/' . $region . '/sns/aws4_request';
        $stringToSign = "AWS4-HMAC-SHA256\n" . $ts . "\n" . $scope . "\n" . hash('sha256', $canonical);

        $kDate    = hash_hmac('sha256', $date, 'AWS4' . $secret, true);
        $kRegion  = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', 'sns', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $auth = 'AWS4-HMAC-SHA256 Credential=' . $accessKey . '/' . $scope
            . ', SignedHeaders=content-type;host;x-amz-date, Signature=' . $signature;

        $ch = curl_init('https://' . $host . '/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded',
            'X-Amz-Date: ' . $ts,
            'Authorization: ' . $auth,
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false || $code < 200 || $code >= 300) {
            return ['ok' => false, 'ref' => null, 'costPence' => null, 'error' => 'aws-http-' . $code];
        }
        $messageId = null;
        if (preg_match('/<MessageId>([^<]+)<\/MessageId>/', (string) $resp, $m) === 1) {
            $messageId = $m[1];
        }
        return ['ok' => true, 'ref' => $messageId, 'costPence' => 6, 'error' => null];
    }
}
