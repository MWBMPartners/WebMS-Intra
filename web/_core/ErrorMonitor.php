<?php
// Path: _core/ErrorMonitor.php
/**
 * -----------------------------------------------------------------------------
 * External error monitor adapter 📡
 * -----------------------------------------------------------------------------
 * Sentry- and GlitchTip-compatible error reporting. Forwards Logger-captured
 * errors to an external service so they're recorded even when the portal's
 * own DB is unreachable (the case `tblErrors` cannot cover).
 *
 * The Sentry store endpoint format also matches GlitchTip — both accept
 * the same JSON envelope and `X-Sentry-Auth` header — so configuring the
 * `monitoring.sentryDsn` setting with a GlitchTip DSN works without code
 * changes.
 *
 * Settings:
 *   monitoring.enabled       — '1' / '0'  (master switch; default off)
 *   monitoring.sentryDsn     — full DSN string (sensitive, encrypted)
 *   monitoring.environment   — defaults to PORTAL_ENV
 *   monitoring.sampleRate    — float 0.0–1.0; events are dropped via
 *                              random sampling when < 1.0 (default 1.0)
 *
 * When `monitoring.enabled` is '0' or `monitoring.sentryDsn` is empty,
 * every method is a silent no-op. This is the common case — most
 * installs won't configure external monitoring.
 *
 * @package   Portal\Core
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/143
 * @link      https://develop.sentry.dev/sdk/data-model/envelopes/
 * @link      https://glitchtip.com/documentation
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class ErrorMonitor
{
    /**
     * Forward an error event to the configured monitor.
     *
     * Called from Logger::errorPlatform() after the DB insert; wrapped
     * in try/catch by the caller so transport failures never break
     * the logging path.
     */
    public static function capture(
        string $platform,
        string $severity,
        string $code,
        string $title,
        string $detail = '',
        ?int $userId = null
    ): void {
        $settings = App::settings()['monitoring'] ?? [];
        if ((string) ($settings['enabled'] ?? '0') !== '1') {
            return;
        }
        $dsn = (string) ($settings['sentryDsn'] ?? '');
        if ($dsn === '') {
            return;
        }

        // Sample-rate gate. Random.org-grade randomness isn't required —
        // we just want to drop some fraction of high-volume events.
        $sample = (float) ($settings['sampleRate'] ?? 1.0);
        if ($sample < 1.0) {
            $roll = (mt_rand() / mt_getrandmax());
            if ($roll > $sample) {
                return;
            }
        }

        $parsed = self::parseDsn($dsn);
        if ($parsed === null) {
            return;
        }
        $environment = (string) ($settings['environment'] ?? App::env());
        $payload = self::buildPayload($platform, $severity, $code, $title, $detail, $userId, $environment);

        self::send($parsed, $payload);
    }

    /**
     * Public hook for sending an arbitrary message event (admin "test
     * monitor" button in /admin/integrations/monitoring uses this).
     */
    public static function sendTestEvent(string $message = 'Portal monitor smoke test'): bool
    {
        $settings = App::settings()['monitoring'] ?? [];
        $dsn = (string) ($settings['sentryDsn'] ?? '');
        if ($dsn === '') {
            return false;
        }
        $parsed = self::parseDsn($dsn);
        if ($parsed === null) {
            return false;
        }
        $environment = (string) ($settings['environment'] ?? App::env());
        $payload = self::buildPayload('Portal', 'Info', 'TEST', $message, 'Manually triggered from /admin/integrations/monitoring.', null, $environment);
        return self::send($parsed, $payload);
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Parse a Sentry/GlitchTip DSN into its constituent parts.
     *
     * Format:
     *   https://PUBLIC_KEY@HOST/PROJECT_ID
     *   https://PUBLIC_KEY:SECRET@HOST/PROJECT_ID   (legacy; secret ignored)
     */
    private static function parseDsn(string $dsn): ?array
    {
        $u = parse_url($dsn);
        if (is_array($u) === false) {
            return null;
        }
        $scheme = (string) ($u['scheme'] ?? '');
        $host   = (string) ($u['host']   ?? '');
        $user   = (string) ($u['user']   ?? '');
        $path   = (string) ($u['path']   ?? '');
        if ($scheme === '' || $host === '' || $user === '' || $path === '') {
            return null;
        }
        $projectId = trim($path, '/');
        if ($projectId === '' || preg_match('/^[0-9]+$/', $projectId) !== 1) {
            return null;
        }
        $portPart = isset($u['port']) === true ? ':' . (int) $u['port'] : '';
        $storeUrl = $scheme . '://' . $host . $portPart . '/api/' . $projectId . '/store/';
        return [
            'public'   => $user,
            'storeUrl' => $storeUrl,
            'project'  => $projectId,
        ];
    }

    /**
     * Build the Sentry "store" envelope (single event JSON).
     *
     * @link https://develop.sentry.dev/sdk/data-model/event-payloads/
     */
    private static function buildPayload(string $platform, string $severity, string $code, string $title, string $detail, ?int $userId, string $environment): array
    {
        $level = self::severityToLevel($severity);
        $payload = [
            'event_id'    => str_replace('-', '', self::uuid4()),
            'timestamp'   => gmdate('Y-m-d\TH:i:s\Z'),
            'platform'    => 'php',
            'level'       => $level,
            'environment' => $environment,
            'logger'      => $platform,
            'release'     => App::version(),
            'message'     => [
                'formatted' => trim($title . ($detail !== '' ? ' — ' . $detail : '')),
            ],
            'tags' => [
                'severity' => $severity,
                'code'     => $code,
                'platform' => $platform,
            ],
            'request' => [
                'url'         => self::currentUrl(),
                'method'      => $_SERVER['REQUEST_METHOD']   ?? 'GET',
                'headers'     => [
                    'User-Agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                ],
            ],
            'server_name' => $_SERVER['SERVER_NAME'] ?? gethostname() ?: 'portal',
        ];
        if ($userId !== null) {
            $payload['user'] = ['id' => (string) $userId];
        }
        return $payload;
    }

    /**
     * POST the event to the resolved store URL with the X-Sentry-Auth
     * header. cURL with a short timeout — best-effort dispatch; we never
     * want monitor failures to slow down the user's response.
     */
    private static function send(array $parsed, array $payload): bool
    {
        $body = json_encode($payload);
        if ($body === false) {
            return false;
        }
        $ch = curl_init($parsed['storeUrl']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-Sentry-Auth: Sentry sentry_version=7, sentry_client=webms-intra/' . App::version() . ', sentry_key=' . $parsed['public'],
        ]);
        // Tight timeouts — under no circumstances should monitoring slow
        // down user-facing response time. If the monitor is unreachable
        // we accept the dropped event silently.
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code >= 200 && $code < 300;
    }

    /**
     * Map Logger's severity strings to Sentry's `level` enum.
     */
    private static function severityToLevel(string $severity): string
    {
        $s = strtolower($severity);
        return match (true) {
            str_contains($s, 'critical') => 'fatal',
            str_contains($s, 'fatal')    => 'fatal',
            str_contains($s, 'error')    => 'error',
            str_contains($s, 'warn')     => 'warning',
            str_contains($s, 'info')     => 'info',
            default                      => 'error',
        };
    }

    /**
     * RFC-4122 UUID v4 (random). Sentry requires `event_id` as a 32-char
     * lowercase hex without hyphens; we strip after generation.
     */
    private static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr(ord($b[6]) & 0x0f | 0x40);
        $b[8] = chr(ord($b[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    private static function currentUrl(): string
    {
        $scheme = (($_SERVER['HTTPS'] ?? '') !== '' && (string) ($_SERVER['HTTPS'] ?? '') !== 'off') ? 'https' : 'http';
        $host   = (string) ($_SERVER['HTTP_HOST'] ?? 'unknown');
        $uri    = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        return $scheme . '://' . $host . $uri;
    }
}
