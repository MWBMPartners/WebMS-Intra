<?php
// Path: _core/GeocodeCacheRetention.php
/**
 * -----------------------------------------------------------------------------
 * How long a remembered map answer is kept 🗺️🧹 (#1037, D-182)
 * -----------------------------------------------------------------------------
 * The ONE place that answers "how many days may an answer in the address
 * look-up cache (`tblGeocodeCache`) go unused before the nightly clear-out
 * deletes it?", and the ONE writer of the setting that decides it,
 * `geo.cache.retentionDays`. Do not read or write it anywhere else.
 *
 * WHAT IS BEING KEPT
 * -----------------------------------------------------------------------------
 * Since #1037 (migration 290) a cache row holds no address text: a fingerprint
 * of the address and country, the map position found, which service found it,
 * whether the address is a home or a public place, a use count and two dates.
 * For a HOME the position is the house — it is kept to about a centimetre, and
 * any online map turns it back into the address — so a home row is personal
 * data (D-183) and its fingerprint is mixed with the installation's secret.
 * That is why the owner chose a keeping period (D-182), and why "keep for
 * ever" is NOT offered: D-182 rules it out by name. So unlike the activity log (D-117), 0 here means
 * nothing; a stored 0, empty or unreadable figure is "nothing chosen".
 *
 * THE RULE (D-182, following #668 and D-013)
 * -----------------------------------------------------------------------------
 * Three tiers, each able to tighten the one above and none to loosen it: a hard
 * maximum in code (HARD_MAX_DAYS), then the installation, then the
 * organisation. An organisation that chose nothing follows the installation —
 * never the built-in year (#668's first trap).
 *
 *   - The INSTALLATION's figure: a global administrator only.
 *   - An ORGANISATION's figure: its own administrator (and a global
 *     administrator), within the installation's — the same people as the
 *     activity log's (D-118), on the same page, Admin, Maintenance, Retention.
 *   - An installation figure shorter than an organisation's own is REFUSED,
 *     naming the organisations (D-119's shape), read live and locked inside the
 *     transaction that writes (#687, #697).
 *
 * HOMES AND PUBLIC PLACES (D-183). A row is marked `addressKind`. A home's
 * remembered position is personal data — it is kept to about a centimetre, so
 * it is the house — while a public place's is not. Both kinds are swept by the
 * same period: deleting a venue's answer early costs one look-up, and one rule
 * is easier to state truthfully than two.
 *
 * THE CACHE IS SHARED, SO THE STRICTEST PERIOD DECIDES
 * -----------------------------------------------------------------------------
 * `tblGeocodeCache` has NO organisation column: one remembered answer serves
 * every organisation that looks the same address up, which is what a cache is
 * for (it is how Nominatim's "ask once" policy is kept, see Geocoder). So a row
 * cannot be swept by "its organisation's" period — it has none. The clear-out
 * therefore uses periodInForce(): the SHORTEST period in force anywhere — the
 * installation's own and every organisation's (each already held to the
 * installation's). An organisation that chose 30 days thereby shortens the
 * cache for every organisation. That is deliberate and costs nothing anybody
 * can lose: a deleted answer is simply asked of the provider again the next
 * time somebody needs it. The other way round — the longest period — would let
 * one organisation keep a fingerprint of another's member's home longer than
 * that organisation chose.
 *
 * A SAVE-TIME REFUSAL IS NOT A CAP (#668): daysForSite() holds a figure written
 * by SQL or restored from a backup to the installation's, rather than obeying
 * it.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/1037
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

final class GeocodeCacheRetention
{
    /** The setting, dotted. Seeded installation-wide (365, migration 290); an organisation may have its own row. */
    public const KEY = 'geo.cache.retentionDays';

    /** What an installation that has chosen nothing keeps an unused answer for: a year. */
    public const DEFAULT_DAYS = 365;

    /** The most a figure may be: ten years, the ceiling the other retention periods use. */
    public const HARD_MAX_DAYS = 3650;

    /**
     * What the general settings editor says when it refuses the key. It names
     * the page that DOES write it and not who may change it there.
     */
    public const LOCKED_MESSAGE = 'How long remembered map answers are kept is set on Admin, Maintenance, '
        . 'Retention, where an organisation\'s figure is checked against the installation\'s and a '
        . 'refusal is recorded. It is not changed here. Nothing was changed.';

    // #########################################################################
    // 🔒 The general settings editor
    // #########################################################################

    /**
     * Whether the general settings editor must refuse this key — to change, to
     * add and to delete, for everybody. That editor judges nothing, so a figure
     * typed there would skip the three-tier refusal; deleting the
     * installation's row would quietly move every organisation that follows it
     * to the built-in year. Compared without regard to capitals, as the
     * database compares names.
     */
    public static function lockedInGeneralEditor(string $key): bool
    {
        return strcasecmp($key, self::KEY) === 0;
    }

    // #########################################################################
    // 📖 Reading
    // #########################################################################

    /**
     * 🏛️ The INSTALLATION's figure, from start-up's installation-wide layer.
     *    Unusable (missing, empty, 0, not a whole number) is the built-in year.
     */
    public static function installationDays(): int
    {
        self::needSettingLimit();

        return self::installationDaysFromStored(self::fromLayer(InstallationSettings::layer()));
    }

    /**
     * 🏛️ The same reading, of a value the CALLER read (a locked row inside a
     *    save). Asked of `SettingLimit::ceilingFrom()`, which applies the hard
     *    maximum; a stored 0 is turned into "nothing stored" FIRST, because
     *    SettingLimit keeps 0 as a real answer and here it is not one — a
     *    ceiling of 0 days would delete every remembered answer every night.
     */
    public static function installationDaysFromStored(mixed $stored): int
    {
        self::needSettingLimit();

        return SettingLimit::ceilingFrom(self::usable($stored), self::DEFAULT_DAYS, self::HARD_MAX_DAYS);
    }

    /**
     * 🏢 The figure in force for ONE NAMED organisation, held to the
     *    installation's. Asked by number, never from the merged `$SETTINGS`:
     *    the scheduled job has no current organisation (#491, #683).
     *    `App::settingForSite()` falls back to the installation's row by itself,
     *    so an organisation that chose nothing follows the installation.
     */
    public static function daysForSite(int $siteId): int
    {
        self::needSettingLimit();

        $installation = self::installationDays();
        $own          = self::usable(App::settingForSite(self::KEY, $siteId));
        if ($own === null) {
            return $installation;
        }

        // The smaller of the two; `$installation` is already held to the hard
        // maximum, so the answer is too.
        return min($own, $installation);
    }

    /**
     * 🧹 The period the clear-out uses for the WHOLE cache: the shortest in
     *    force anywhere. See "THE CACHE IS SHARED" in the class header.
     *
     * THROWS when the organisations cannot be read, rather than answering the
     * installation's figure: "could not read" must never become "nobody chose
     * a shorter period". The clear-out catches it and deletes nothing that run.
     */
    public static function periodInForce(\mysqli $db): int
    {
        return self::periodFacts($db)['days'];
    }

    /**
     * 🧹 The period in force AND where it comes from: `byOrganisation` is true
     *    only when some organisation's own figure is SHORTER than the
     *    installation's, so the words can say truly whose period it is.
     *    #1037 round 3: the words said "the shortest period any organisation
     *    has chosen" even when nobody had chosen one and the figure shown was
     *    the installation's. Throws as periodInForce() does.
     *
     * @return array{days: int, byOrganisation: bool}
     */
    public static function periodFacts(\mysqli $db): array
    {
        self::needSettingLimit();

        // 🔄 Read FRESH from the database, every row of the key at once, never
        //    from start-up's settings layer: a save calls this straight after
        //    its own commit to tell the person what is now in force, and the
        //    layer still holds what the request started with. An organisation
        //    with no usable row of its own follows the installation, which is
        //    already in the minimum, so only usable own rows need reading.
        $stmt = $db->prepare('SELECT `siteID`, `settingValue` FROM `tblSettings` WHERE `settingKey` = ?');
        if ($stmt === false) {
            throw new \RuntimeException('The periods could not be read.');
        }
        $key = self::KEY;
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result === false) {
            throw new \RuntimeException('The periods could not be read.');
        }
        $installationStored = null;
        $own                = [];
        while ($row = $result->fetch_assoc()) {
            if ($row['siteID'] === null) {
                $installationStored = $row['settingValue'];
            } elseif (($figure = self::usable($row['settingValue'])) !== null) {
                $own[] = $figure;
            }
        }
        $stmt->close();

        $installation = self::installationDaysFromStored($installationStored);
        $shortest     = $installation;
        foreach ($own as $figure) {
            // Each organisation's own figure is held to the installation's
            // (the minimum does that by itself) and so to the hard maximum.
            $shortest = min($shortest, $figure);
        }

        return ['days' => $shortest, 'byOrganisation' => $shortest < $installation];
    }

    /**
     * 🗣️ What is in force, in words, for a success message or a page: the
     *    shortest period and the condition that the clear-out runs at all.
     *    "Could not read" names no figure rather than inventing one.
     */
    public static function inForceWords(\mysqli $db): string
    {
        try {
            $facts = self::periodFacts($db);

            return 'One remembered answer serves every organisation on this installation, so an answer is deleted '
                . 'once it has not been used for '
                . ($facts['byOrganisation'] === true
                    ? 'the shortest period any organisation has chosen, which is now ' . self::inWords($facts['days'])
                    : 'the period set for the whole installation, which is now ' . self::inWords($facts['days'])
                        . ' (no organisation has chosen a shorter one)')
                . ', and only if the nightly clear-out job has been set up.';
        } catch (\Throwable $e) {
            // Neither "an organisation's" nor "the installation's": which one is
            // in force is exactly what could not be read (#1037 round 3).
            return 'One remembered answer serves every organisation on this installation, so an answer is deleted '
                . 'once it has not been used for the shortest period in force anywhere on it (the installation\'s '
                . 'own, or a shorter one an organisation has chosen), and only if the nightly clear-out job has been '
                . 'set up. The period now in force could not be read just now.';
        }
    }

    /**
     * 📄 The privacy notice's sentence about remembered map positions of homes
     *    (D-183), as plain text; the page escapes it. One place, so the notice
     *    and the "what we store" list cannot say different things. Nothing on a
     *    row says whose home it is, so an erasure request cannot single one
     *    out — said, because a member deciding what to ask for needs it.
     */
    public static function privacyWords(\mysqli $db): string
    {
        try {
            $facts  = self::periodFacts($db);
            $period = 'once it has not been used for ' . self::inWords($facts['days'])
                . ($facts['byOrganisation'] === true
                    ? ' (the shortest period any organisation on this portal has chosen)'
                    : ' (the period set for this whole portal; no organisation has chosen a shorter one)');
        } catch (\Throwable $e) {
            $period = 'once it has not been used for the period in force on this portal (which could not be read '
                . 'just now)';
        }

        // 🔑 #1037 round 4: "mixed with a secret key belonging to this
        //    installation" was said whether or not that key could be read, and
        //    when it could not, a home was remembered under a salt printed in
        //    every release. Now a home is not remembered at all while the key
        //    cannot be read (`Geocoder::homeCacheKey()`), and this says so.
        //    Positions remembered while it could be read are still there, and
        //    are still removed by the time limit, so that half is kept.
        $remembers = self::homeIsRemembered() === true
            ? 'the portal remembers the position it was given so that it need not ask the map service again. The '
                . 'position is precise enough to identify the house. It is kept with a scrambled form of the address, '
                . 'mixed with a secret key belonging to this installation, and not with the address itself, and it is '
                . 'deleted '
            : 'the portal would normally remember the position it was given, under a scrambled form of the address '
                . 'mixed with a secret key belonging to this installation. That key cannot be read just now, so while '
                . 'it cannot, the position of a home is not remembered at all and each look-up asks the map service '
                . 'again. Any position remembered before then is precise enough to identify the house, is kept with '
                . 'the scrambled form and not with the address itself, and is deleted ';

        return 'When an address marked as somebody\'s home (for example a small group\'s host, or your own address '
            . 'in the directory) is looked up on a map, ' . $remembers . $period . ', if the nightly clear-out has '
            . 'been set up. Nothing on it says whose home it is, so an erasure request cannot pick it out; the time '
            . 'limit is what removes it. Positions of public places, such as venues, are treated as not about anybody.';
    }

    /**
     * 🗺️ What the retention page's card says about remembered map answers, as
     *    plain text; the page escapes it (#1037 round 4). The clear-out half is
     *    inForceWords(), so the card, the success message and the privacy
     *    notice are built from the same facts: the card used to say the
     *    clear-out "uses the shortest period any organisation has chosen" even
     *    when no organisation had chosen one and the period was the
     *    installation's.
     */
    public static function cardWords(\mysqli $db): string
    {
        return 'When an address is typed, the portal asks a map service where it is and remembers the answer so it '
            . 'does not ask again. The address itself is not kept, but the position is, to about a centimetre: for '
            . 'somebody\'s home that is the house, and anyone who can read the database can find the address with any '
            . 'online map. So an answer for an address marked as a home is personal data. '
            . self::inForceWords($db)
            . ' A deleted answer is simply asked again when it is next needed.';
    }

    /**
     * 🔑 Whether a home's position is being remembered at all: only while the
     *    installation's secret key can be read (`AddressFingerprint`).
     */
    private static function homeIsRemembered(): bool
    {
        if (class_exists(AddressFingerprint::class) === false) {
            require_once __DIR__ . DIRECTORY_SEPARATOR . 'AddressFingerprint.php';
        }

        return AddressFingerprint::hasInstallationKey();
    }

    /**
     * 🧹 Delete (or, with `$reallyDelete` false, count) every remembered answer
     *    not used within `$days`. `lastUsedAt` is written with the database's
     *    own `NOW()` by `Geocoder`, so it is compared with `NOW()` here: one
     *    clock on both sides.
     */
    public static function sweep(\mysqli $db, int $days, bool $reallyDelete): int
    {
        if ($days < 1) {
            // Unreachable through periodInForce() (every reader answers 1 or
            // more); here so that no caller can delete the whole cache with 0.
            return 0;
        }
        $sql = $reallyDelete === true
            ? 'DELETE FROM `tblGeocodeCache` WHERE `lastUsedAt` < DATE_SUB(NOW(), INTERVAL ? DAY)'
            : 'SELECT COUNT(*) AS `n` FROM `tblGeocodeCache` WHERE `lastUsedAt` < DATE_SUB(NOW(), INTERVAL ? DAY)';
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('The remembered map answers could not be cleared.');
        }
        $stmt->bind_param('i', $days);
        $stmt->execute();
        if ($reallyDelete === true) {
            $n = (int) $stmt->affected_rows;
        } else {
            $n = (int) ($stmt->get_result()->fetch_assoc()['n'] ?? 0);
        }
        $stmt->close();

        return $n;
    }

    /**
     * 🏢 What ONE organisation has chosen FOR ITSELF, or null when it has no
     *    usable row of its own. Read at the organisation's own level, never
     *    through settingForSite() or the merged array, which fall back to the
     *    installation's row: a form filled from either shows a figure the
     *    organisation never chose (#674, #677).
     */
    public static function ownDays(\mysqli $db, int $siteId): ?int
    {
        return self::usable(self::storedValue($db, $siteId, false));
    }

    /** A figure in words: "365 days", "1 day". */
    public static function inWords(int $days): string
    {
        return $days . ' ' . ($days === 1 ? 'day' : 'days');
    }

    // #########################################################################
    // 💾 Saving
    // #########################################################################

    /**
     * 🏛️ Store the INSTALLATION's figure, 1 to HARD_MAX_DAYS. A global
     *    administrator only, checked here as well as at the page.
     *
     * A figure SHORTER than an organisation's own is refused, naming them
     * (D-119's shape): what matters is that the new figure would sit below a
     * figure somebody has already chosen. Judged only when the figure CHANGES,
     * so an untouched Save changes nothing and says so (#687).
     *
     * @return array{saved: bool, type: string, message: string, refusal: string, outcome: string, blockedOrganisations: int}
     */
    public static function saveInstallation(\mysqli $db, int $days, bool $globalAdmin): array
    {
        $refuse = static fn (string $message, string $reason, int $blocked = 0): array => [
            'saved' => false, 'type' => 'danger', 'message' => $message, 'refusal' => $reason,
            'outcome' => 'refused', 'blockedOrganisations' => $blocked,
        ];
        if ($globalAdmin === false) {
            return $refuse('Only a global administrator can change what applies to every organisation. Nothing was saved.', 'not-global-admin');
        }
        if ($days < 1) {
            return $refuse(self::notANumberWords(self::HARD_MAX_DAYS), 'not-a-number');
        }
        if ($days > self::HARD_MAX_DAYS) {
            // 🚫 Refused, never clamped (#668, D-013).
            return $refuse('A number of days can be at most ' . self::HARD_MAX_DAYS . ' (ten years). Nothing was saved.', 'above-hard-max');
        }

        try {
            // 🔒 Transaction FIRST, so the lock below is real (#697). The SAME
            //    row saveForSite() locks, so the two cannot interleave.
            $db->begin_transaction();
            $current = self::installationDaysFromStored(self::storedValue($db, null, true));
            if ($current === $days) {
                $db->commit();

                return [
                    'saved' => true, 'type' => 'info', 'refusal' => '', 'outcome' => 'unchanged',
                    'blockedOrganisations' => 0, 'message' => 'Nothing was different, so nothing was changed.',
                ];
            }

            // 🚫 Never below a figure an organisation has chosen. Read INSIDE
            //    this transaction, after the installation's row is locked, and
            //    locked itself; it THROWS rather than answer "none" when it
            //    cannot read, which lands in the catch below as a refusal.
            $above = self::organisationsAbove($db, $days);
            if ($above !== []) {
                $db->rollback();

                return $refuse(self::aboveRefusalWords($days, $above), 'organisations-above', count($above));
            }

            self::writeRow($db, null, (string) $days);
            $db->commit();
        } catch (\Throwable $e) {
            self::rollbackQuietly($db);
            // The fault's own words are not logged: under strict reporting they
            // can quote the statement.
            error_log('[WebMS-Intra] GeocodeCacheRetention: the installation\'s period could not be saved ('
                . get_class($e) . ').');

            return $refuse('The change could not be saved: either the organisations\' own figures could not be read '
                . 'to check it, or the database refused it. Nothing was changed.', 'database');
        }

        return [
            'saved' => true, 'type' => 'success', 'refusal' => '', 'outcome' => 'stored', 'blockedOrganisations' => 0,
            // (d) of #1037 round 2: this said an answer "not used for N days is
            //     now deleted", untrue when an organisation has chosen fewer
            //     days (the shortest wins) and when the job is not set up.
            'message' => 'Saved. The installation\'s figure is now ' . self::inWords($days)
                . ': no organisation may keep a remembered map answer longer. ' . self::inForceWords($db),
        ];
    }

    /**
     * 💬 The refusal for the installation's administrator: the organisations
     *    by NAME with the figure each chose. Only the person who pressed Save
     *    reads it; the activity log carries a count.
     *
     * @param array<int, array{name: string, days: int}> $above
     */
    public static function aboveRefusalWords(int $days, array $above): string
    {
        $named = [];
        foreach ($above as $one) {
            $named[] = $one['name'] . ' (' . self::inWords($one['days']) . ')';
        }

        return 'This was not saved. ' . self::inWords($days) . ' is shorter than the period '
            . (count($above) === 1 ? 'this organisation has' : 'these organisations have')
            . ' chosen for keeping remembered map answers: ' . implode(', ', $named) . '. '
            . 'Ask ' . (count($above) === 1 ? 'it' : 'them') . ' to choose a shorter period first, '
            . 'or choose a longer period here. Nothing was changed.';
    }

    /**
     * 🏢 Store, clear or refuse ONE organisation's own figure.
     *
     * `$days` of null means "follow the installation": the organisation's own
     * row is DELETED. The caller must have established that the choice really
     * ARRIVED before passing null (#677). A figure above the installation's is
     * REFUSED with the reason, never clamped (D-013), judged by
     * `SettingLimit::whyNotAllowedFor()` against the installation's row read
     * live and locked (#687).
     *
     * @return array{saved: bool, type: string, message: string, refusal: string, outcome: string}
     */
    public static function saveForSite(\mysqli $db, int $siteId, ?int $days, bool $adminOfOrganisation): array
    {
        self::needSettingLimit();

        $refuse = static fn (string $message, string $reason): array => [
            'saved' => false, 'type' => 'danger', 'message' => $message, 'refusal' => $reason, 'outcome' => 'refused',
        ];
        if ($adminOfOrganisation === false) {
            return $refuse('Only an administrator of this organisation can change how long it keeps remembered map answers. Nothing was saved.', 'not-admin');
        }

        try {
            $db->begin_transaction();
            $installationStored = self::usable(self::storedValue($db, null, true));
            $currentText        = self::storedValue($db, $siteId, true);
            $currentOwn         = self::usable($currentText);
            $allowed            = self::installationDaysFromStored($installationStored);

            if ($days !== null && $days < 1) {
                $db->rollback();

                return $refuse(self::notANumberWords($allowed), 'not-a-number');
            }

            $unchanged = $days === null ? $currentText === null : $currentOwn === $days;
            if ($unchanged === true) {
                $db->commit();

                return [
                    'saved' => true, 'type' => 'info', 'refusal' => '', 'outcome' => 'unchanged',
                    'message' => 'Nothing was different, so nothing was changed.',
                ];
            }

            if ($days !== null) {
                $reason = SettingLimit::whyNotAllowedFor($days, $installationStored, self::DEFAULT_DAYS, self::HARD_MAX_DAYS);
                if ($reason !== '') {
                    $db->rollback();

                    return $refuse(
                        $reason === 'above-hard-max' && $allowed < self::HARD_MAX_DAYS
                            ? SettingLimit::refusalWords('above-installation', $allowed, 'days')
                            : SettingLimit::refusalWords($reason, $allowed, 'days'),
                        $reason
                    );
                }
                self::writeRow($db, $siteId, (string) $days);
            } else {
                $del = $db->prepare('DELETE FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` = ?');
                if ($del === false) {
                    throw new \RuntimeException('The period could not be removed.');
                }
                $key = self::KEY;
                $del->bind_param('si', $key, $siteId);
                $del->execute();
                $del->close();
            }
            $db->commit();
        } catch (\Throwable $e) {
            self::rollbackQuietly($db);
            error_log('[WebMS-Intra] GeocodeCacheRetention: the period of organisation #'
                . $siteId . ' could not be saved (' . get_class($e) . ').');

            return $refuse('The change could not be saved because the database refused it. Nothing was changed.', 'database');
        }

        if ($days === null) {
            return [
                'saved' => true, 'type' => 'success', 'refusal' => '', 'outcome' => 'cleared',
                'message' => 'Saved. This organisation now follows the installation\'s figure for remembered map answers. '
                    . self::inForceWords($db),
            ];
        }

        return [
            'saved' => true, 'type' => 'success', 'refusal' => '', 'outcome' => 'stored',
            // (d) of #1037 round 2: this said the organisation "now keeps" an
            //     answer for N days, untrue when another organisation has
            //     chosen fewer (the cache is shared and the shortest wins) and
            //     when the job is not set up.
            'message' => 'Saved. This organisation\'s own figure is now ' . self::inWords($days) . '. '
                . self::inForceWords($db),
        ];
    }

    /** The "not a whole number of days" refusal, with the range really allowed. */
    public static function notANumberWords(int $allowed): string
    {
        return 'A number of days has to be a whole number from 1 to ' . $allowed . '. Nothing was saved.';
    }

    // #########################################################################
    // 🔧 Loading, and the statements
    // #########################################################################

    /** Load what this class reads, if nothing else has (the house pattern). */
    private static function needSettingLimit(): void
    {
        if (class_exists(InstallationSettings::class) === false) {
            require_once __DIR__ . DIRECTORY_SEPARATOR . 'InstallationSettings.php';
        }
        if (class_exists(App::class) === false) {
            require_once __DIR__ . DIRECTORY_SEPARATOR . 'App.php';
        }
        if (class_exists(SettingLimit::class) === false) {
            require_once __DIR__ . DIRECTORY_SEPARATOR . 'SettingLimit.php';
        }
    }

    /** The key's value in a nested settings layer (the loader nests 'a.b.c'), or null. */
    private static function fromLayer(array $layer): mixed
    {
        return $layer['geo']['cache']['retentionDays'] ?? null;
    }

    /**
     * A stored or read figure as a number of days, or null when it is absent or
     * unusable. 0 is UNUSABLE here: D-182 rules out "keep for ever", and a
     * period of 0 days would delete everything.
     */
    private static function usable(mixed $stored): ?int
    {
        if (is_int($stored) === true) {
            return $stored >= 1 ? $stored : null;
        }
        $text = is_string($stored) === true ? trim($stored) : '';
        if (preg_match('/^\d{1,18}$/', $text) !== 1 || (int) $text < 1) {
            return null;
        }

        return (int) $text;
    }

    /**
     * 🏢 The organisations whose OWN figure is longer than `$days`, keyed by
     *    number, with name and figure. Read LOCKED inside saveInstallation()'s
     *    transaction, and THROWS rather than answer an empty list it cannot
     *    stand behind (`GivingRetention::organisationsAboveCap()`'s rule).
     *
     * @return array<int, array{name: string, days: int}>
     */
    private static function organisationsAbove(\mysqli $db, int $days): array
    {
        $stmt = $db->prepare(
            'SELECT `s`.`siteID`, `s`.`siteName`, `t`.`settingValue` FROM `tblSettings` AS `t` '
            . 'INNER JOIN `tblSites` AS `s` ON `s`.`siteID` = `t`.`siteID` '
            . 'WHERE `t`.`settingKey` = ? AND `t`.`siteID` IS NOT NULL ORDER BY `s`.`siteName` FOR UPDATE'
        );
        if ($stmt === false) {
            throw new \RuntimeException('The organisations\' periods could not be read.');
        }
        $key = self::KEY;
        $stmt->bind_param('s', $key);
        if ($stmt->execute() === false) {
            throw new \RuntimeException('The organisations\' periods could not be read.');
        }
        $result = $stmt->get_result();
        if ($result === false) {
            throw new \RuntimeException('The organisations\' periods could not be read.');
        }
        $above = [];
        while ($row = $result->fetch_assoc()) {
            $own = self::usable($row['settingValue']);
            if ($own !== null && $own > $days) {
                $above[(int) $row['siteID']] = ['name' => (string) $row['siteName'], 'days' => $own];
            }
        }
        $stmt->close();

        return $above;
    }

    /**
     * One settings row's text at one level (null = the installation's), or null
     * when there is no row. With `$lock`, read `FOR UPDATE`; the caller must
     * already be inside a transaction or the lock is let go at once (#697).
     */
    private static function storedValue(\mysqli $db, ?int $siteId, bool $lock): ?string
    {
        $stmt = $db->prepare(
            'SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` '
            . ($siteId === null ? 'IS NULL' : '= ?')
            . ($lock === true ? ' FOR UPDATE' : '')
        );
        if ($stmt === false) {
            throw new \RuntimeException('The period could not be read.');
        }
        $key = self::KEY;
        if ($siteId === null) {
            $stmt->bind_param('s', $key);
        } else {
            $stmt->bind_param('si', $key, $siteId);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row === null || $row['settingValue'] === null ? null : (string) $row['settingValue'];
    }

    /** Write the setting at one level (null = installation-wide). */
    private static function writeRow(\mysqli $db, ?int $siteId, string $value): void
    {
        $stmt = $db->prepare(
            'INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`, `updatedAt`) '
            . 'VALUES (?, ?, ?, ?, 0, NOW()) '
            . 'ON DUPLICATE KEY UPDATE `settingValue` = VALUES(`settingValue`), `updatedAt` = NOW()'
        );
        if ($stmt === false) {
            throw new \RuntimeException('The period could not be written.');
        }
        $key = self::KEY;
        // The installation's row keeps the built-in year as its default, as
        // migration 290 seeded it; an organisation's row has none.
        $default = $siteId === null ? (string) self::DEFAULT_DAYS : '';
        $stmt->bind_param('isss', $siteId, $key, $value, $default);
        $stmt->execute();
        $stmt->close();
    }

    /** Roll back, swallowing a second fault. */
    private static function rollbackQuietly(\mysqli $db): void
    {
        try {
            $db->rollback();
        } catch (\Throwable $ignored) {
            // Nothing more can be done.
        }
    }
}
