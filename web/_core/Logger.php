<?php
// Path: _core/Logger.php
/**
 * -----------------------------------------------------------------------------
 * Portal Core Logger 📓
 * -----------------------------------------------------------------------------
 * Centralised activity and error logger writing to tblActivityLogs and tblErrors.
 * -----------------------------------------------------------------------------
 *  • Logger::activity(...)      – audit trail for every action.
 *  • Logger::phpError(...)      – registered as set_error_handler.
 *  • Logger::exception(...)     – registered as set_exception_handler.
 *  • Logger::errorPlatform(...) – manual logging for external libs / systems.
 * -----------------------------------------------------------------------------
 * @package    Portal\Core
 * @author     Cambridge SDA
 * @license   All Rights Reserved
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use mysqli;
use mysqli_stmt;
use Throwable;

class Logger
{
    /** Reference to the global MySQLi connection. */
    private static ?mysqli $db = null;

    /** Get DB handle from bootstrap */
    private static function db(): mysqli
    {
        if (self::$db === null) {
            global $mysqli; // Established in bootstrap.php
            self::$db = $mysqli;
        }
        return self::$db;
    }

    /* ---------------------------------------------------------------------- */
    /* Activity Logging                                                       */
    /* ---------------------------------------------------------------------- */

    public static function activity(string $type, string $description = '', ?int $userId = null): void
    {
        $db = self::db();

        $headersJson = json_encode(self::requestHeaders());
        $sessionId   = session_id();
        $ip          = self::clientIp();
        $ua          = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $sessionData = $_SESSION ?? [];
        unset($sessionData['csrf_token'], $sessionData['oauth_state'], $sessionData['oauth_nonce']);
        $sessionSnap = json_encode($sessionData);

        // 🌐 Include siteID for multi-site context
        $siteId = Site::id();

        /** @var mysqli_stmt $stmt */
        $stmt = $db->prepare(
            'INSERT INTO tblActivityLogs ' .
            '(siteID, userID, activityType, activityDescription, requestHeaders, sessionID, visitorIP, userAgent, sessionDataSnapshot) ' .
            'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if ($stmt === false) {
            error_log('Activity log prepare failed: ' . $db->error);
            return;
        }
        $stmt->bind_param(
            'iisssssss',
            $siteId,
            $userId,
            $type,
            $description,
            $headersJson,
            $sessionId,
            $ip,
            $ua,
            $sessionSnap
        );
        $stmt->execute();
        $stmt->close();
    }

    /* ---------------------------------------------------------------------- */
    /* Audit Trail — Before/After Change Tracking                             */
    /* ---------------------------------------------------------------------- */

    /**
     * Log a detailed before/after change for a specific record.
     *
     * @param string      $tableName  Database table name (e.g. 'tblExpenseClaims')
     * @param int         $recordId   Primary key of the affected record
     * @param string      $action     One of: create, update, delete
     * @param array|null  $oldData    Previous state (null for create)
     * @param array|null  $newData    New state (null for delete)
     * @param int|null    $userId     Acting user ID
     */
    public static function audit(
        string $tableName,
        int $recordId,
        string $action,
        ?array $oldData = null,
        ?array $newData = null,
        ?int $userId = null
    ): void {
        $db = self::db();
        $siteId = Site::id();

        // 📋 Build change set for updates (diff old vs new)
        $changeSet = null;
        if ($action === 'update' && $oldData !== null && $newData !== null) {
            $diff = [];
            foreach ($newData as $field => $newVal) {
                $oldVal = $oldData[$field] ?? null;
                if ((string) ($oldVal ?? '') !== (string) ($newVal ?? '')) {
                    $diff[$field] = ['old' => $oldVal, 'new' => $newVal];
                }
            }
            if (count($diff) === 0) {
                return; // No actual changes
            }
            $changeSet = json_encode($diff, JSON_UNESCAPED_UNICODE);
        }

        // 📋 For single-field tracking, use first changed field
        $fieldName = null;
        $oldValue  = null;
        $newValue  = null;

        if ($action === 'create' && $newData !== null) {
            $newValue = json_encode($newData, JSON_UNESCAPED_UNICODE);
        } elseif ($action === 'delete' && $oldData !== null) {
            $oldValue = json_encode($oldData, JSON_UNESCAPED_UNICODE);
        } elseif ($action === 'update' && $changeSet !== null) {
            $diffArr = json_decode($changeSet, true);
            if (is_array($diffArr) === true && count($diffArr) === 1) {
                $fieldName = array_key_first($diffArr);
                $oldValue  = (string) ($diffArr[$fieldName]['old'] ?? '');
                $newValue  = (string) ($diffArr[$fieldName]['new'] ?? '');
            }
        }

        $ip = self::clientIp();

        $stmt = $db->prepare(
            'INSERT INTO tblAuditTrail '
            . '(siteID, userID, tableName, recordID, action, fieldName, oldValue, newValue, changeSet, ipAddress) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if ($stmt === false) {
            error_log('Audit trail prepare failed: ' . $db->error);
            return;
        }
        $stmt->bind_param(
            'iisissssss',
            $siteId, $userId, $tableName, $recordId, $action,
            $fieldName, $oldValue, $newValue, $changeSet, $ip
        );
        $stmt->execute();
        $stmt->close();
    }

    /* ---------------------------------------------------------------------- */
    /* PHP Error & Exception Handlers                                         */
    /* ---------------------------------------------------------------------- */

    public static function phpError(int $errno, string $errstr, string $file, int $line): void
    {
        $severity = self::severityFromErrno($errno);
        self::errorPlatform('PHP', $severity, (string) $errno, $errstr, $file . ':' . $line);
    }

    public static function exception(Throwable $ex): void
    {
        $detail = $ex->getFile() . ':' . $ex->getLine() . "\n" . $ex->getTraceAsString();
        self::errorPlatform('PHP', 'Fatal', (string) $ex->getCode(), $ex->getMessage(), $detail);
    }

    /* ---------------------------------------------------------------------- */
    /* Generic Error Logger                                                   */
    /* ---------------------------------------------------------------------- */

    public static function errorPlatform(
        string $platform,
        string $severity,
        string $code,
        string $title,
        string $detail = '',
        ?int $userId = null
    ): void {
        $db = self::db();

        $headersJson = json_encode(self::requestHeaders());
        $ip          = self::clientIp();
        $ua          = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $url         = $_SERVER['REQUEST_URI'] ?? '';

        // 🌐 Include siteID for multi-site context
        $siteId = Site::id();

        /** @var mysqli_stmt $stmt */
        $stmt = $db->prepare(
            'INSERT INTO tblErrors ' .
            '(siteID, errorPlatform, errorSeverity, errorCode, errorTitle, errorDetail, userID, visitorIP, userAgent, requestURL, requestHeaders) ' .
            'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if ($stmt === false) {
            error_log('Error log prepare failed: ' . $db->error);
            return;
        }
        $stmt->bind_param(
            'isssssissss',
            $siteId,
            $platform,
            $severity,
            $code,
            $title,
            $detail,
            $userId,
            $ip,
            $ua,
            $url,
            $headersJson
        );
        $stmt->execute();
        $stmt->close();

        // 🚨 Critical-error alerting (#229).
        //    Severities matching the configured set fire an email dispatch
        //    to portal.alerts.recipients. Rate-limited per (platform, code,
        //    title) fingerprint with portal.alerts.cooldown_minutes cooldown
        //    so a runaway error doesn't spam admins. Failures here are
        //    swallowed — alert delivery must never break the logging path.
        try {
            self::maybeDispatchAlert($platform, $severity, $code, $title, $detail);
        } catch (\Throwable $ignored) {
            error_log('Alert dispatch failed: ' . $ignored->getMessage());
        }
    }

    private static function maybeDispatchAlert(
        string $platform,
        string $severity,
        string $code,
        string $title,
        string $detail
    ): void {
        $settings   = App::settings();
        $sevList    = (string) ($settings['portal']['alerts']['severities'] ?? 'Critical,Fatal');
        $configured = array_map('trim', explode(',', $sevList));
        if (in_array($severity, $configured, true) === false) {
            return;
        }
        $recipientsRaw = (string) ($settings['portal']['alerts']['recipients'] ?? '');
        $recipients    = array_filter(array_map('trim', explode(',', $recipientsRaw)));
        if (count($recipients) === 0) {
            return;
        }

        $cooldown   = (int) ($settings['portal']['alerts']['cooldown_minutes'] ?? 30);
        $fingerprint = hash('sha256', $platform . '|' . $code . '|' . $title);
        $sentinel    = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_backups'
                     . DIRECTORY_SEPARATOR . '.alert-' . substr($fingerprint, 0, 16);
        if (is_file($sentinel) === true
            && (time() - (int) filemtime($sentinel)) < ($cooldown * 60)
        ) {
            return;
        }
        // Touch sentinel BEFORE sending so a slow mail dispatch doesn't double-fire.
        @touch($sentinel);

        $portalName = (string) ($settings['site']['name'] ?? 'WebMS Intra');
        $subject    = sprintf('[%s] %s — %s', $portalName, strtoupper($severity), substr($title, 0, 100));
        $body       = sprintf(
            "Severity: %s\nPlatform: %s\nCode: %s\nTitle: %s\n\nDetail:\n%s\n\nURL: %s\n",
            $severity,
            $platform,
            $code,
            $title,
            substr($detail, 0, 2000),
            $_SERVER['REQUEST_URI'] ?? ''
        );
        foreach ($recipients as $to) {
            // 🪞 Use the framework Mailer if available; else fall back to mail()
            //    so alerting works even when no provider is fully configured.
            if (class_exists('Portal\\Core\\Mailer') === true
                && method_exists('Portal\\Core\\Mailer', 'send') === true
            ) {
                try {
                    Mailer::send($to, $subject, $body);
                } catch (\Throwable $ignored) {
                    @mail($to, $subject, $body);
                }
            } else {
                @mail($to, $subject, $body);
            }
        }
    }

    /* ---------------------------------------------------------------------- */
    /* Helpers                                                                */
    /* ---------------------------------------------------------------------- */

    private static function severityFromErrno(int $errno): string
    {
        if ($errno === E_NOTICE || $errno === E_USER_NOTICE) {
            return 'Notification';
        }
        if ($errno === E_WARNING || $errno === E_USER_WARNING) {
            return 'Warning';
        }
        if ($errno === E_ERROR || $errno === E_USER_ERROR) {
            return 'Error';
        }
        return 'Fatal';
    }

    private static function requestHeaders(): array
    {
        if (function_exists('getallheaders') === true) {
            return getallheaders();
        }
        // Fallback for non-Apache SAPIs
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (str_starts_with($name, 'HTTP_') === true) {
                $key           = str_replace('_', '-', substr($name, 5));
                $headers[$key] = $value;
            }
        }
        return $headers;
    }

    private static function clientIp(): string
    {
        // Honour X-Forwarded-For / CF-Connecting-IP if present
        if (isset($_SERVER['HTTP_CF_CONNECTING_IP']) === true) {
            return $_SERVER['HTTP_CF_CONNECTING_IP'];
        }
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) === true) {
            $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($parts[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
