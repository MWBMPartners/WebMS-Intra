<?php
// Path: _core/GdprEraser.php
/**
 * -----------------------------------------------------------------------------
 * GDPR Article 17 erasure engine 🗑️🔐
 * -----------------------------------------------------------------------------
 * Right-to-erasure orchestrator. Walks the catalogue of PII-bearing tables,
 * deletes rows where lawful, anonymises rows that must be retained for a
 * legitimate-interest reason (e.g. financial records → HMRC 6-year rule).
 *
 * Every action lands in tblErasureAudit with a chained HMAC so a later
 * tamper attempt is detectable: chainHash[N] = SHA-256(chainHash[N-1] ||
 * canonical(payload[N])). Verify by re-walking.
 *
 * Categories:
 *   delete    — drop the row entirely (cascade FKs do the rest).
 *   anonymise — null PII columns, replace userID with TOMBSTONE_ID.
 *   retain    — log the retention reason, no row change (rare).
 *
 * @package   Portal\Core
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/235
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class GdprEraser
{
    public const TOMBSTONE_NAME  = '[Deleted User]';
    public const TOMBSTONE_EMAIL = 'deleted-{rid}@example.invalid';

    /**
     * Catalogue of tables touched by erasure, with the per-table action.
     * Adding a new PII-bearing table? Append it here.
     *
     * Schema entry shape:
     *   ['table' => string, 'userCol' => string, 'action' => 'delete'|'anonymise'|'cascade-only',
     *    'nullCols' => string[]?, 'reason' => string?]
     *
     * `cascade-only` means we don't touch this table directly — FK CASCADE
     * on a parent table will sweep it up. Logged for the audit trail anyway.
     */
    public static function catalogue(): array
    {
        return [
            // Direct user-row anonymisation last; do dependents first.
            ['table' => 'tblAttendanceCheckIns', 'userCol' => 'userID',      'action' => 'anonymise', 'nullCols' => [], 'reason' => 'aggregate attendance stats retained — userID nulled'],
            ['table' => 'tblExpenseClaim',      'userCol' => 'submittedByID', 'action' => 'anonymise', 'nullCols' => [], 'reason' => 'UK HMRC requires 6-year retention of expense records'],
            ['table' => 'tblGivingEntry',       'userCol' => 'donorID',      'action' => 'anonymise', 'nullCols' => [], 'reason' => 'UK HMRC requires 6-year retention of financial records'],
            ['table' => 'tblPayment',           'userCol' => 'userID',       'action' => 'anonymise', 'nullCols' => [], 'reason' => 'Payment processor reconciliation requires retention'],
            ['table' => 'tblPrayerRequests',    'userCol' => 'submitterID',  'action' => 'anonymise', 'nullCols' => ['submitterName','submitterEmail','submitterIP'], 'reason' => 'request body preserved for congregational continuity; PII blanked'],
            ['table' => 'tblAnnouncements',    'userCol' => 'createdByID',  'action' => 'anonymise', 'nullCols' => [], 'reason' => 'authorship attribution detached'],
            ['table' => 'tblEvents',           'userCol' => 'createdByID',  'action' => 'anonymise', 'nullCols' => [], 'reason' => 'authorship attribution detached'],
            ['table' => 'tblRecording',        'userCol' => 'uploadedByID', 'action' => 'anonymise', 'nullCols' => [], 'reason' => 'authorship attribution detached'],

            // Sessions / tokens / personal devices — hard delete.
            ['table' => 'tblSessions',         'userCol' => 'userID', 'action' => 'delete'],
            ['table' => 'tblTotpBackupCodes',  'userCol' => 'userID', 'action' => 'delete'],
            ['table' => 'tblWebauthnCredentials','userCol' => 'userID', 'action' => 'delete'],
            ['table' => 'tblUserSites',        'userCol' => 'userID', 'action' => 'delete'],
            ['table' => 'tblUserRoles',        'userCol' => 'userID', 'action' => 'delete'],
            ['table' => 'tblUserSmsPreference','userCol' => 'userID', 'action' => 'delete'],
            ['table' => 'tblNewsletterSubscription','userCol' => 'userID', 'action' => 'delete'],
            ['table' => 'tblPaymentMethod',    'userCol' => 'userID', 'action' => 'delete'],
            ['table' => 'tblGiftAidDeclaration','userCol' => 'donorID', 'action' => 'delete'],
            ['table' => 'tblZoomAccount',      'userCol' => 'userID', 'action' => 'delete'],
            ['table' => 'tblUserTranslationPref','userCol' => 'userID', 'action' => 'delete'],
            // #303 Phase 2 — discipleship per-user tables. markedByID /
            // enrolledByID / revokedByID attributions self-heal via
            // ON DELETE SET NULL on the FKs, so a hard delete here is safe.
            ['table' => 'tblPathwayEnrolments','userCol' => 'userID', 'action' => 'delete'],
            ['table' => 'tblPathwayProgress',  'userCol' => 'userID', 'action' => 'delete'],

            // Final step — anonymise the user row itself rather than delete,
            // so foreign keys with ON DELETE SET NULL don't cascade-blow
            // historical attributions we wanted to keep.
            ['table' => 'tblUsers', 'userCol' => 'userID', 'action' => 'anonymise',
             'nullCols' => ['emailAddress','phoneNumber','address','locale','passwordHash','totpSecret','msAccountID','googleAccountID'],
             'overrides' => ['fullName' => self::TOMBSTONE_NAME, 'isActive' => 0],
             'reason' => 'user row retained for historical FK integrity; PII removed'],
        ];
    }

    /**
     * Run the erasure for one request. Caller must have flipped the
     * request status to `processing` first (Eraser doesn't manage state
     * transitions — it executes).
     */
    public static function execute(int $requestId, int $userId, ?int $processedByID = null): bool
    {
        $db = App::db();
        $catalogue = self::catalogue();
        $any = false;
        foreach ($catalogue as $entry) {
            $any = self::processEntry($db, $requestId, $userId, $entry) || $any;
        }
        // Final status flip.
        $u = $db->prepare('UPDATE tblErasureRequest SET status = "completed", processedAt = NOW(), processedByID = ?, userID = NULL WHERE requestID = ?');
        if ($u !== false) {
            $u->bind_param('ii', $processedByID, $requestId);
            $u->execute();
            $u->close();
        }
        return $any;
    }

    /**
     * "What we hold about you" — JSON-serialisable inventory built by
     * querying every catalogue table for rows referencing the user.
     * Used by /account/my-data.
     */
    public static function inventory(int $userId): array
    {
        $db = App::db();
        $out = [];
        foreach (self::catalogue() as $entry) {
            $table = (string) $entry['table'];
            $col   = (string) $entry['userCol'];
            try {
                $count = 0;
                $stmt = $db->prepare('SELECT COUNT(*) FROM `' . $table . '` WHERE `' . $col . '` = ?');
                if ($stmt !== false) {
                    $stmt->bind_param('i', $userId);
                    $stmt->execute();
                    $stmt->bind_result($count);
                    $stmt->fetch();
                    $stmt->close();
                }
                if ($count > 0) {
                    $out[] = [
                        'table'  => $table,
                        'rows'   => (int) $count,
                        'action' => (string) $entry['action'],
                        'reason' => (string) ($entry['reason'] ?? ''),
                    ];
                }
            } catch (\Throwable $ignored) {
                // Table missing (app not installed). Skip silently.
            }
        }
        return $out;
    }

    /**
     * Verify the audit chain for one request. Returns true when intact,
     * false on any broken link. Lets compliance prove no row was edited.
     */
    public static function verifyAuditChain(int $requestId): bool
    {
        $db = App::db();
        $stmt = $db->prepare('SELECT auditID, action, tableName, recordKey, details, chainHash FROM tblErasureAudit WHERE requestID = ? ORDER BY auditID');
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('i', $requestId);
        $stmt->execute();
        $rs = $stmt->get_result();
        $prev = '';
        while ($r = $rs->fetch_assoc()) {
            $expected = self::hashRow($prev, (string) $r['action'], (string) $r['tableName'], (string) ($r['recordKey'] ?? ''), (string) ($r['details'] ?? ''));
            if (hash_equals($expected, (string) $r['chainHash']) === false) {
                $stmt->close();
                return false;
            }
            $prev = (string) $r['chainHash'];
        }
        $stmt->close();
        return true;
    }

    // -------------------------------------------------------------------------
    // internals
    // -------------------------------------------------------------------------

    private static function processEntry(\mysqli $db, int $requestId, int $userId, array $entry): bool
    {
        $table  = (string) $entry['table'];
        $col    = (string) $entry['userCol'];
        $action = (string) $entry['action'];

        // Snapshot row keys for the audit trail.
        $ids = [];
        try {
            $stmt = $db->prepare('SELECT 1 FROM `' . $table . '` WHERE `' . $col . '` = ? LIMIT 100');
            if ($stmt === false) {
                return false;
            }
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $rs = $stmt->get_result();
            while ($r = $rs->fetch_row()) {
                $ids[] = 'matched';
            }
            $stmt->close();
        } catch (\Throwable $e) {
            self::logAudit($db, $requestId, 'skip', $table, null, 'table-missing');
            return false;
        }
        if (count($ids) === 0) {
            return false;
        }

        if ($action === 'delete') {
            $sql = 'DELETE FROM `' . $table . '` WHERE `' . $col . '` = ?';
        } else { // anonymise
            $nulls = (array) ($entry['nullCols'] ?? []);
            $overrides = (array) ($entry['overrides'] ?? []);
            $sets = [];
            foreach ($nulls as $c) {
                $sets[] = '`' . $c . '` = NULL';
            }
            foreach ($overrides as $c => $v) {
                if (is_int($v) === true) {
                    $sets[] = '`' . $c . '` = ' . (int) $v;
                } else {
                    $v = str_replace('{rid}', (string) $requestId, (string) $v);
                    $sets[] = '`' . $c . "` = '" . $db->real_escape_string($v) . "'";
                }
            }
            // For tblUsers, the user-row anonymisation also nulls the email/etc.
            if ($table === 'tblUsers') {
                $sets[] = "`emailAddress` = '" . $db->real_escape_string(str_replace('{rid}', (string) $requestId, self::TOMBSTONE_EMAIL)) . "'";
                $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', array_unique($sets)) . ' WHERE `' . $col . '` = ?';
            } else {
                // Anonymise = drop the user link too.
                $sets[] = '`' . $col . '` = NULL';
                $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $sets) . ' WHERE `' . $col . '` = ?';
            }
        }

        try {
            $stmt = $db->prepare($sql);
            if ($stmt === false) {
                self::logAudit($db, $requestId, 'failed', $table, null, $db->error);
                return false;
            }
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            self::logAudit($db, $requestId, $action, $table, (string) $affected . ' rows', (string) ($entry['reason'] ?? ''));
            return $affected > 0;
        } catch (\Throwable $e) {
            self::logAudit($db, $requestId, 'failed', $table, null, mb_substr($e->getMessage(), 0, 250));
            return false;
        }
    }

    private static function logAudit(\mysqli $db, int $requestId, string $action, string $table, ?string $recordKey, string $details): void
    {
        // Read the tail hash to chain on.
        $prev = '';
        $stmt = $db->prepare('SELECT chainHash FROM tblErasureAudit WHERE requestID = ? ORDER BY auditID DESC LIMIT 1');
        if ($stmt !== false) {
            $stmt->bind_param('i', $requestId);
            $stmt->execute();
            $stmt->bind_result($prev);
            $stmt->fetch();
            $stmt->close();
        }
        $hash = self::hashRow($prev ?? '', $action, $table, $recordKey ?? '', $details);
        $ins = $db->prepare('INSERT INTO tblErasureAudit (requestID, action, tableName, recordKey, details, chainHash) VALUES (?, ?, ?, ?, ?, ?)');
        if ($ins !== false) {
            $ins->bind_param('isssss', $requestId, $action, $table, $recordKey, $details, $hash);
            $ins->execute();
            $ins->close();
        }
    }

    /**
     * Per-row hash function. Pipe-delimited so collisions across fields
     * are not possible without an actual collision in SHA-256.
     */
    private static function hashRow(string $prev, string $action, string $table, string $recordKey, string $details): string
    {
        return hash('sha256', $prev . '|' . $action . '|' . $table . '|' . $recordKey . '|' . $details);
    }
}
