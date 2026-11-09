<?php
// Path: _core/NoticeboardFoyer.php
/**
 * -----------------------------------------------------------------------------
 * The noticeboard's foyer slideshow: its secret link and what it shows 📺
 * -----------------------------------------------------------------------------
 * A screen in the building plays the organisation's posters on a loop, opened
 * by a SECRET LINK with no sign-in (#493: "an auto-playing slideshow for a
 * foyer display, reached by a secret link or QR code with no login at all").
 * This class is the ONE place that:
 *
 *   1. makes, replaces and checks that link (`tokenFor()`, `replaceToken()`,
 *      `siteForToken()`) — one per organisation, in `tblNoticeboardFoyer`;
 *   2. decides what the slideshow shows (`showsFor()`): D-086's tiered setting,
 *      the installation permitting "public posters only" or "either" and an
 *      organisation choosing within it — the stricter wins, and anything that
 *      cannot be read counts as "public posters only";
 *   3. lists the posters it shows (`posters()`): when it shows public posters
 *      only, they come from `PublicWebsite::posterWhere()` and nothing else;
 *   4. keeps the two settings out of the general settings editor
 *      (`lockedInGeneralEditor()`), because only this page judges the tiers.
 *
 * WHAT THE LINK IS, AND IS NOT
 * ----------------------------
 * The link is a capability: anybody holding it sees the slideshow, which is
 * why it can be replaced (the old address stops at once) and why the setup
 * page draws its QR code into the page rather than fetching it from an address
 * (`Qr::dataUri()`, #623: an address ends up in logs). It names exactly ONE
 * organisation, and that organisation is taken from the link alone — never
 * from the address the request arrived at — so one organisation's screen can
 * never show another's posters.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright All Rights Reserved — MWBM Partners Ltd (t/a MWservices)
 * @version   1.0.0
 */

declare(strict_types=1);

namespace Portal\Core;

final class NoticeboardFoyer
{
    /** INSTALLATION ONLY: what the installation permits — 'public' or 'either'. */
    public const KEY_PERMITTED = 'noticeboard.foyer.permitted';

    /**
     * What the slideshow shows — 'public' or 'all'. An organisation's own row
     * chooses; with none, the installation's row is read, which is seeded
     * 'public' and is not offered on any page (the setup page sets only an
     * organisation's choice, and the general editor refuses the key), so in
     * practice an organisation that has not chosen shows public posters only.
     */
    public const KEY_SHOWS = 'noticeboard.foyer.shows';

    /** A token is exactly this: 48 lower-case hex characters (random_bytes(24)). Anything else is refused before the database is asked. */
    private const TOKEN_PATTERN = '/^[a-f0-9]{48}$/';

    /** What the general settings editor says when it refuses one of the two keys. */
    public const LOCKED_MESSAGE = 'What the noticeboard\'s foyer slideshow shows is changed only on Noticeboard, Foyer screen, '
        . 'which holds an organisation\'s choice to what the installation permits. Nothing was changed.';

    // =========================================================================
    // 1️⃣ The secret link
    // =========================================================================

    /** The organisation's current token, or null when it has none yet. */
    public static function tokenFor(\mysqli $db, int $siteId): ?string
    {
        $stmt = $db->prepare('SELECT `token` FROM `tblNoticeboardFoyer` WHERE `siteID` = ?');
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row === null ? null : (string) $row['token'];
    }

    /**
     * Make a new token for the organisation, replacing any old one — so the
     * old address stops working at once. Returns the new token.
     */
    public static function replaceToken(\mysqli $db, int $siteId): string
    {
        $token = bin2hex(random_bytes(24));
        $stmt = $db->prepare(
            'INSERT INTO `tblNoticeboardFoyer` (`siteID`, `token`, `createdAt`) VALUES (?, ?, CURRENT_TIMESTAMP) '
            . 'ON DUPLICATE KEY UPDATE `token` = VALUES(`token`), `createdAt` = VALUES(`createdAt`)'
        );
        $stmt->bind_param('is', $siteId, $token);
        $stmt->execute();
        $stmt->close();

        return $token;
    }

    /**
     * The ONE organisation a token names, or null. A value that is not a
     * string or not exactly a token's shape is refused before the database is
     * asked; the row found is compared again with hash_equals().
     */
    public static function siteForToken(\mysqli $db, mixed $token): ?int
    {
        if (is_string($token) === false || preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            return null;
        }
        $stmt = $db->prepare(
            'SELECT f.`siteID`, f.`token` FROM `tblNoticeboardFoyer` f JOIN `tblSites` s ON s.`siteID` = f.`siteID` '
            . 'WHERE f.`token` = ? AND s.`isActive` = 1'
        );
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row === null || hash_equals((string) $row['token'], $token) === false) {
            return null;
        }

        return (int) $row['siteID'];
    }

    // =========================================================================
    // 2️⃣ What it shows (D-086)
    // =========================================================================

    /** What the installation permits: 'public' or 'either'. Unreadable, missing or unknown counts as 'public'. */
    public static function permitted(\mysqli $db): string
    {
        $value = self::settingRow($db, null, self::KEY_PERMITTED);

        return $value === 'either' ? 'either' : 'public';
    }

    /**
     * What one organisation's slideshow shows: 'public' or 'all'. 'all' only
     * when the installation permits 'either' AND the organisation's choice
     * (its own row, else the installation's default row) is 'all'. Anything
     * that cannot be read counts as 'public' — the safe direction, since
     * anyone walking in can read the screen. Never throws.
     */
    public static function showsFor(\mysqli $db, int $siteId): string
    {
        try {
            if (self::permitted($db) !== 'either') {
                return 'public';
            }
            $own = self::settingRow($db, $siteId, self::KEY_SHOWS);
            $chosen = $own ?? self::settingRow($db, null, self::KEY_SHOWS);

            return $chosen === 'all' ? 'all' : 'public';
        } catch (\Throwable $e) {
            return 'public';
        }
    }

    /**
     * Save what the installation permits ('public' or 'either'). Only a
     * global administrator of the installation (`$isRootAdmin`).
     *
     * @return array{saved:bool, type:string, message:string}
     */
    public static function saveForInstallation(\mysqli $db, string $value, bool $isRootAdmin): array
    {
        if ($isRootAdmin === false) {
            return ['saved' => false, 'type' => 'danger', 'message' => 'Only a global administrator of the whole installation can change what it permits. Nothing was saved.'];
        }
        if (in_array($value, ['public', 'either'], true) === false) {
            return ['saved' => false, 'type' => 'danger', 'message' => 'That choice was not recognised. Nothing was saved.'];
        }
        // An upsert, not a plain UPDATE (round 1 of the check): with the row
        // missing (a hand edit, a partial restore) an UPDATE changes nothing
        // and the page would still say "Saved".
        $stmt = $db->prepare(
            'INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (NULL, ?, ?, ?, 0) '
            . 'ON DUPLICATE KEY UPDATE `settingValue` = VALUES(`settingValue`)'
        );
        $key = self::KEY_PERMITTED;
        $default = 'public';
        $stmt->bind_param('sss', $key, $value, $default);
        $stmt->execute();
        $stmt->close();

        return ['saved' => true, 'type' => 'success', 'message' => $value === 'either'
            ? 'Saved: each organisation may now choose whether its foyer screen shows public posters only or every poster.'
            : 'Saved: every organisation\'s foyer screen shows public posters only.'];
    }

    /**
     * Save one organisation's choice ('public' or 'all'). Only that
     * organisation's global administrator (`$isSiteRootAdmin`), and 'all' only
     * when the installation permits it — refused with the reason otherwise,
     * never quietly changed (#668).
     *
     * @return array{saved:bool, type:string, message:string}
     */
    public static function saveForOrganisation(\mysqli $db, int $siteId, string $value, bool $isSiteRootAdmin): array
    {
        if ($isSiteRootAdmin === false) {
            return ['saved' => false, 'type' => 'danger', 'message' => 'Only a global administrator of this organisation can choose what its foyer screen shows. Nothing was saved.'];
        }
        if (in_array($value, ['public', 'all'], true) === false) {
            return ['saved' => false, 'type' => 'danger', 'message' => 'That choice was not recognised. Nothing was saved.'];
        }
        if ($value === 'all' && self::permitted($db) !== 'either') {
            return ['saved' => false, 'type' => 'danger', 'message' => 'This installation permits the foyer screen to show public posters only, so it cannot be set to show every poster. Nothing was saved.'];
        }
        $key = self::KEY_SHOWS;
        $stmt = $db->prepare(
            'INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (?, ?, ?, ?, 0) '
            . 'ON DUPLICATE KEY UPDATE `settingValue` = VALUES(`settingValue`)'
        );
        $default = 'public';
        $stmt->bind_param('isss', $siteId, $key, $value, $default);
        $stmt->execute();
        $stmt->close();

        return ['saved' => true, 'type' => 'success', 'message' => $value === 'all'
            ? 'Saved: the foyer screen shows every poster on the noticeboard, including ones not on the public website.'
            : 'Saved: the foyer screen shows only posters on the public website.'];
    }

    // =========================================================================
    // 3️⃣ The posters it shows
    // =========================================================================

    /**
     * The posters one organisation's slideshow shows, in the board's order.
     * 'public': exactly the posters `PublicWebsite::posterWhere()` lets
     * through, judged on THAT organisation's calendar date. 'all': every
     * poster that is not deleted.
     *
     * @return list<array<string,mixed>>
     */
    public static function posters(\mysqli $db, int $siteId): array
    {
        $shows = self::showsFor($db, $siteId);
        $sql   = 'SELECT p.`posterID`, p.`title`, p.`kicker`, p.`category`, p.`scheduleType`, p.`eventDate`, p.`weekday`, '
            . 'p.`eventTime`, p.`location`, p.`mediaType`, p.`mediaUrl`, p.`canvaUrl`, p.`thumbUrl`, p.`colorIndex` '
            . 'FROM `tblNoticeboardPosters` p WHERE p.`siteID` = ?';
        $types  = 'i';
        $params = [$siteId];
        if ($shows === 'all') {
            $sql .= ' AND p.`isDeleted` = 0';
        } else {
            $today = (new \DateTimeImmutable('now', new \DateTimeZone(Site::timezone($siteId))))->format('Y-m-d');
            $rule  = PublicWebsite::posterWhere('p', $today);
            $sql   .= $rule['sql'];
            $types .= $rule['types'];
            $params = array_merge($params, $rule['params']);
        }
        $sql .= ' ORDER BY p.`sortOrder` ASC, p.`eventDate` ASC, p.`posterID` ASC';
        $stmt = $db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }

    // =========================================================================
    // 4️⃣ The general settings editor
    // =========================================================================

    /** Is this key changed only on Noticeboard, Foyer screen (and refused by the general editor)? */
    public static function lockedInGeneralEditor(string $key): bool
    {
        return strcasecmp($key, self::KEY_PERMITTED) === 0 || strcasecmp($key, self::KEY_SHOWS) === 0;
    }

    /** One setting's stored value at one level (null = the installation), or null when there is no such row. */
    private static function settingRow(\mysqli $db, ?int $siteId, string $key): ?string
    {
        if ($siteId === null) {
            $stmt = $db->prepare('SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL');
            $stmt->bind_param('s', $key);
        } else {
            $stmt = $db->prepare('SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` = ?');
            $stmt->bind_param('si', $key, $siteId);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row === null ? null : (string) $row['settingValue'];
    }
}
