<?php
// Path: _core/ApiKey.php
/**
 * -----------------------------------------------------------------------------
 * Portal Core — ApiKey 🔑
 * -----------------------------------------------------------------------------
 * Bearer-token issuance + verification for the public REST API (#323).
 *
 * SECURITY MODEL:
 *   • Plaintext token (40 chars: 'wbms_' + 32 hex) is returned ONLY at mint.
 *   • DB stores sha256(plaintext) in tblApiKeys.keyHash.
 *   • Verification is constant-time: SELECT by keyHash directly, then
 *     hash_equals on the row's keyHash vs sha256(submitted plaintext).
 *   • Updates lastUsedAt + lastUsedIP on every successful match.
 *   • Expired keys (expiresAt < NOW) fail verification.
 *   • Revoked keys (isActive = 0) fail verification.
 *
 * Public methods:
 *   ApiKey::mint(siteId, name, scopes, expiresAt, createdById)   → ['plaintext'=>, 'keyID'=>, 'prefix'=>]
 *   ApiKey::findByPlaintext(plaintext)                           → ?array (full row + side-effect update)
 *   ApiKey::hasScope(keyRow, required)                           → bool
 *   ApiKey::revoke(keyID, byUserID)                              → bool
 *   ApiKey::rotate(keyID, byUserID, graceHours=null)              → ['plaintext'=>, 'keyID'=>, 'prefix'=>]
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/323
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class ApiKey
{
    /** @var string Plaintext token prefix — human-recognisable in operator paste-bins */
    public const TOKEN_PREFIX = 'wbms_';

    /** @var int Random bytes for the token body — 32 bytes → 64 hex chars → 256 bits entropy */
    public const TOKEN_BYTES = 16; // 16 bytes → 32 hex chars → 128 bits — sufficient post-hashing

    /**
     * @var array<int, string> Scope strings recognised by the v1 write
     *      surface (#323 Phase 2). Each app exposes `{app}:read` and/or
     *      `{app}:write` — ApiKey::hasScope() also honours a trailing
     *      wildcard (e.g. `events:*`) against any of these.
     */
    public const SCOPES = [
        'events:read', 'events:write',
        'announcements:read', 'announcements:write',
        'attendance:read', 'attendance:write',
        'prayer-requests:read', 'prayer-requests:write',
        'documents:read', 'documents:write',
        'expenses:read', 'expenses:write',
        'leadership:read', 'leadership:write',
        'tasks:read', 'tasks:write',
        'noticeboard:read', 'noticeboard:write',
        'users:read', 'users:write',
    ];

    /**
     * Mint a new API key. Returns the plaintext EXACTLY ONCE — caller MUST
     * show it to the admin and never persist it.
     *
     * @param array<int, string>      $scopes    Scope strings (e.g. ['events:read', 'attendance:write'])
     * @param \DateTimeImmutable|null $expiresAt Optional hard expiry; null = no expiry
     *
     * @return array{plaintext: string, keyID: int, prefix: string}
     */
    public static function mint(int $siteId, string $name, array $scopes, ?\DateTimeImmutable $expiresAt, int $createdById): array
    {
        $plaintext = self::TOKEN_PREFIX . bin2hex(random_bytes(self::TOKEN_BYTES));
        $keyHash   = hash('sha256', $plaintext);
        $keyPrefix = mb_substr($plaintext, 0, 12); // 'wbms_' + first 7 chars
        $nameClean = mb_substr(trim($name), 0, 120);
        if ($nameClean === '') {
            $nameClean = 'Unnamed key';
        }
        $scopeStr  = implode(',', array_map(static fn($s) => trim((string) $s), $scopes));
        $scopeStr  = mb_substr($scopeStr, 0, 500);
        $scopeArg  = $scopeStr !== '' ? $scopeStr : null;
        $expArg    = $expiresAt !== null ? $expiresAt->format('Y-m-d H:i:s') : null;

        $db = App::db();
        $stmt = $db->prepare(
            'INSERT INTO tblApiKeys (siteID, name, keyHash, keyPrefix, scopes, expiresAt, createdByID) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare ApiKey insert');
        }
        $stmt->bind_param('isssssi', $siteId, $nameClean, $keyHash, $keyPrefix, $scopeArg, $expArg, $createdById);
        $stmt->execute();
        $newId = (int) $stmt->insert_id;
        $stmt->close();

        Logger::activity('ApiKeyMinted', 'Key #' . $newId . ' (' . $keyPrefix . '…) siteID=' . $siteId);

        return ['plaintext' => $plaintext, 'keyID' => $newId, 'prefix' => $keyPrefix];
    }

    /**
     * Look up the key row by plaintext token. Returns the row on success
     * AND updates lastUsedAt + lastUsedIP. Returns null on any failure path
     * (wrong prefix, no match, expired, revoked).
     *
     * @return array<string, mixed>|null
     */
    public static function findByPlaintext(string $plaintext): ?array
    {
        // 🛡️ Format gate BEFORE any DB hit.
        if (str_starts_with($plaintext, self::TOKEN_PREFIX) === false) {
            return null;
        }
        $bodyLen = self::TOKEN_BYTES * 2;
        if (strlen($plaintext) !== strlen(self::TOKEN_PREFIX) + $bodyLen) {
            return null;
        }
        $body = substr($plaintext, strlen(self::TOKEN_PREFIX));
        if (ctype_xdigit($body) === false) {
            return null;
        }

        $keyHash = hash('sha256', $plaintext);
        $db = App::db();
        $stmt = $db->prepare(
            'SELECT keyID, siteID, name, keyHash, keyPrefix, scopes, expiresAt, isActive '
            . 'FROM tblApiKeys WHERE keyHash = ? LIMIT 1'
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('s', $keyHash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row === null) {
            return null;
        }

        // 🛡️ Constant-time confirmation against the stored hash (belt + braces
        //    in case of any indexing/collision weirdness).
        if (hash_equals((string) $row['keyHash'], $keyHash) === false) {
            return null;
        }
        if ((int) $row['isActive'] !== 1) {
            return null;
        }
        if ($row['expiresAt'] !== null && strtotime((string) $row['expiresAt']) < time()) {
            return null;
        }

        // 📋 Stamp last-used. Best-effort — failure here doesn't reject the key.
        $ip = mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $stmt = $db->prepare('UPDATE tblApiKeys SET lastUsedAt = NOW(), lastUsedIP = ? WHERE keyID = ?');
        if ($stmt !== false) {
            $kid = (int) $row['keyID'];
            $stmt->bind_param('si', $ip, $kid);
            @$stmt->execute();
            $stmt->close();
        }

        return $row;
    }

    /**
     * Does the given key row carry the required scope? Supports wildcard
     * matching — a key with scope 'events:*' satisfies 'events:read'.
     *
     * @param array<string, mixed> $keyRow Row returned by findByPlaintext
     */
    public static function hasScope(array $keyRow, string $required): bool
    {
        $scopes = (string) ($keyRow['scopes'] ?? '');
        if ($scopes === '') {
            return false;
        }
        $required = trim($required);
        if ($required === '') {
            return true;
        }
        $list = array_map('trim', explode(',', $scopes));
        foreach ($list as $scope) {
            if ($scope === '*' || $scope === $required) {
                return true;
            }
            // Wildcard tail: 'events:*' matches 'events:read' / 'events:write' / etc.
            if (str_ends_with($scope, ':*') === true) {
                $base = substr($scope, 0, -2);
                if (str_starts_with($required, $base . ':') === true) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Revoke a key. Idempotent — re-revoking is a no-op.
     */
    public static function revoke(int $keyId, int $byUserId): bool
    {
        if ($keyId <= 0) {
            return false;
        }
        $db = App::db();
        $stmt = $db->prepare(
            'UPDATE tblApiKeys SET isActive = 0, revokedAt = NOW(), revokedByID = ? '
            . 'WHERE keyID = ? AND isActive = 1'
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('ii', $byUserId, $keyId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected > 0) {
            Logger::activity('ApiKeyRevoked', 'Key #' . $keyId);
        }
        return $affected > 0;
    }

    /**
     * Rotate: mint a replacement key with the same siteID / name / scopes /
     * expiresAt, then retire the existing key.
     *
     * Retirement mode depends on the grace window (#323 Phase 2):
     *   • $graceHours === null → read `api.keys.rotationGraceHours` from
     *     settings (default 24 when unset).
     *   • grace > 0  → the OLD key is NOT revoked immediately. Its
     *     `expiresAt` is capped at now + grace hours (never extended past an
     *     existing sooner expiry) and `rotatedToID` is stamped with the new
     *     key's ID. The old key stays `isActive = 1` and dies naturally via
     *     the existing expiresAt check in findByPlaintext() once the cutoff
     *     passes — giving in-flight callers a window to switch to the new
     *     token before the old one stops working.
     *   • grace === 0 → preserves the original, backward-compatible
     *     behaviour: the old key is revoked immediately via revoke().
     *
     * @return array{plaintext: string, keyID: int, prefix: string}
     */
    public static function rotate(int $keyId, int $byUserId, ?int $graceHours = null): array
    {
        $db = App::db();
        $stmt = $db->prepare(
            'SELECT siteID, name, scopes, expiresAt FROM tblApiKeys WHERE keyID = ? AND isActive = 1'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare rotate lookup');
        }
        $stmt->bind_param('i', $keyId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row === null) {
            throw new \RuntimeException('Key not found or not active');
        }

        $grace = $graceHours ?? (int) (App::settings('api.keys.rotationGraceHours') ?? 24);

        $scopes  = array_map('trim', explode(',', (string) ($row['scopes'] ?? '')));
        $scopes  = array_values(array_filter($scopes, static fn($s) => $s !== ''));
        $expires = $row['expiresAt'] !== null ? new \DateTimeImmutable((string) $row['expiresAt']) : null;

        if ($grace <= 0) {
            // 🔒 Backward-compatible immediate-revoke path (grace disabled).
            self::revoke($keyId, $byUserId);

            return self::mint(
                (int) $row['siteID'],
                (string) $row['name'],
                $scopes,
                $expires,
                $byUserId
            );
        }

        // 🕐 Grace-window rotation — mint the replacement FIRST so the
        //    caller always gets a fresh key even if the follow-up UPDATE
        //    below fails for any reason.
        $minted = self::mint(
            (int) $row['siteID'],
            (string) $row['name'],
            $scopes,
            $expires,
            $byUserId
        );

        $cutoff = (new \DateTimeImmutable('+' . $grace . ' hours'))->format('Y-m-d H:i:s');
        $newId  = $minted['keyID'];
        $updateStmt = $db->prepare(
            'UPDATE tblApiKeys SET expiresAt = LEAST(COALESCE(expiresAt, ?), ?), rotatedToID = ? WHERE keyID = ?'
        );
        if ($updateStmt !== false) {
            $updateStmt->bind_param('ssii', $cutoff, $cutoff, $newId, $keyId);
            $updateStmt->execute();
            $updateStmt->close();
        }

        Logger::activity('ApiKeyRotated', 'Key #' . $keyId . ' → #' . $newId . ' (grace ' . $grace . 'h)');

        return $minted;
    }
}
