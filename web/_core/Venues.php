<?php
// Path: _core/Venues.php
/**
 * -----------------------------------------------------------------------------
 * Venue Bookings — Register + Audit Choke-Point 🏛️
 * -----------------------------------------------------------------------------
 * Service class for the Venue Bookings app (slug `venues`, #429). Tenant-side
 * register of hired external buildings: schedule, configurable vocabularies,
 * hire agreements, payable invoice/payment ledger, XLSX/CSV import wizard,
 * calendar conflict engine, reminder sweeps.
 *
 * WALL-CLOCK RULE (make-or-break, non-negotiable): Booking rows are
 * wall-clock venue-local DATE+TIME. tblEvents datetimes are wall-clock
 * event-local in event.timezone/eventTimezone (the column comment saying
 * "stored in UTC" is a known doc bug — see #429 follow-ups).
 * classifyEventCoverage() compares wall-clock to wall-clock; when the two
 * IANA zones are equal there is NO conversion at all; UTC is never involved.
 * Never "fix" bookings or events into UTC.
 *
 * ROOM-AWARE COVERAGE (#436, implemented): classifyEventCoverage() takes an
 * optional trailing $roomId — when set, per-day booking rows are filtered
 * to (b.roomID IS NULL OR b.roomID = event.roomID) before the day-cascade
 * runs; a whole-venue booking (roomID NULL) still covers every room. Null
 * $roomId (both pre-#436 call sites) reproduces the exact prior behaviour.
 *
 * AUDIT FUNNEL: Every mutation funnels through self::audit() ->
 * Logger::audit() -> tblAuditTrail; bulk ops write ONE summary row.
 *
 * All SQL is prepared + site-scoped (siteID = ? in every WHERE that touches
 * a site-owned row). Money is integer pence throughout (#266) — pounds are
 * never stored, only formatted at the input/display boundary by callers.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/429
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/436
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class Venues
{
    /* ==========================================================================
     * 📋 Allow-lists — single source of truth for every ENUM column the
     * venue tables define. Shared between save handlers' validation and
     * dropdown rendering so the two can never silently drift apart.
     * ======================================================================== */

    /** @var string[] tblVenueUsageTypes.usageKind */
    public const USAGE_KINDS = ['hire', 'closed', 'unavailable'];

    /** @var string[] tblVenueStatuses.statusCategory */
    public const STATUS_CATEGORIES = ['proposed', 'agreed', 'rejected', 'standing', 'unavailable'];

    /** @var string[] tblVenueAgreements.rateUnit */
    public const RATE_UNITS = [
        'per-booking', 'per-hour', 'per-day', 'per-week',
        'per-month', 'per-quarter', 'per-year', 'fixed-total',
    ];

    /** @var string[] tblVenueAgreements.status */
    public const AGREEMENT_STATUSES = ['draft', 'active', 'expired', 'terminated', 'superseded'];

    /** @var string[] tblVenueAgreements.agreementType */
    public const AGREEMENT_TYPES = ['standing', 'ad-hoc'];

    /** @var string[] tblVenueInvoices.status */
    public const INVOICE_STATUSES = ['pending', 'part-paid', 'paid', 'disputed', 'cancelled'];

    /**
     * The ONLY statuses setInvoiceStatus() may set manually. The paid
     * family (pending/part-paid/paid) is machine-managed by
     * recomputeInvoiceStatus() — it can never be spoofed via a form post.
     *
     * @var string[]
     */
    public const INVOICE_MANUAL_STATUSES = ['disputed', 'cancelled'];

    /** @var string[] tblVenueInvoicePayments.method */
    public const PAYMENT_METHODS = ['bank-transfer', 'standing-order', 'cash', 'cheque', 'card', 'online', 'other'];

    /** @var string[] tblVenueBookingGroups.groupType */
    public const GROUP_TYPES = ['multi-day', 'recurring'];

    /** @var string[] tblVenueBookingGroups.frequency */
    public const FREQUENCIES = ['weekly', 'fortnightly', 'monthly', 'custom'];

    /* ==========================================================================
     * 🗓️ Calendar coverage classifications (§8.2). WORST_ORDER below IS the
     * worst-first precedence array — pick the FIRST classification present,
     * never derive order from COVERAGE_SEVERITY (that map is display-only).
     * ======================================================================== */
    public const COVERAGE_UNAVAILABLE    = 'unavailable';
    public const COVERAGE_NO_BOOKING     = 'no-booking';
    /** #436 — room-aware verdict: the venue has a confirmed bookable hire that day, but NOT for the requested room. Only reachable when classifyEventCoverage() is called with a non-null $roomId. */
    public const COVERAGE_ROOM_NOT_COVERED = 'room-not-covered';
    public const COVERAGE_OUTSIDE_HOURS  = 'outside-hours';
    public const COVERAGE_UNCONFIRMED    = 'unconfirmed';
    public const COVERAGE_CLOSED         = 'closed';
    public const COVERAGE_CONFIRMED      = 'confirmed';

    /**
     * Worst-first order — the event's overall classification is the first
     * of these present across its days. COVERAGE_ROOM_NOT_COVERED sits
     * immediately after COVERAGE_NO_BOOKING (#436) — it is unreachable
     * when $roomId is null, so this insertion cannot affect any existing
     * (roomless) call's ordering.
     */
    private const WORST_ORDER = [
        self::COVERAGE_UNAVAILABLE,
        self::COVERAGE_NO_BOOKING,
        self::COVERAGE_ROOM_NOT_COVERED,
        self::COVERAGE_OUTSIDE_HOURS,
        self::COVERAGE_UNCONFIRMED,
        self::COVERAGE_CLOSED,
        self::COVERAGE_CONFIRMED,
    ];

    public const COVERAGE_SEVERITY = [
        self::COVERAGE_UNAVAILABLE      => 'danger',
        self::COVERAGE_NO_BOOKING       => 'danger',
        self::COVERAGE_ROOM_NOT_COVERED => 'danger',
        self::COVERAGE_OUTSIDE_HOURS    => 'warning',
        self::COVERAGE_UNCONFIRMED      => 'warning',
        self::COVERAGE_CLOSED           => 'warning',
        self::COVERAGE_CONFIRMED        => 'success',
    ];

    /** Colour fallback when tblVenueStatuses.color IS NULL, keyed by statusCategory. */
    public const CATEGORY_COLORS = [
        'standing'    => '#198754',
        'agreed'      => '#198754',
        'proposed'    => '#ffc107',
        'rejected'    => '#dc3545',
        'unavailable' => '#dc3545',
    ];

    /** Colour fallback for non-hire usage kinds, independent of status colour. */
    public const KIND_COLORS = ['closed' => '#6c757d', 'unavailable' => '#dc3545'];

    /** Allow-listed agreement file MIME types -> the extension they are stored under (finfo-sniffed, never client-trusted). */
    public const AGREEMENT_FILE_MIME_EXT = [
        'application/pdf'                                                        => 'pdf',
        'image/png'                                                              => 'png',
        'image/jpeg'                                                             => 'jpg',
        'image/webp'                                                             => 'webp',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    ];

    /**
     * Six default booking statuses seeded per SITE by seedStatuses().
     * [statusName, statusCategory, countsAsConfirmed, isAvailable, sortOrder]
     */
    private const DEFAULT_STATUSES = [
        ['Standard Agreement',                 'standing', 1, 1, 10],
        ['Pending Leadership Agreement',       'proposed', 0, 1, 20],
        ['Proposed to Landlord',               'proposed', 0, 1, 30],
        ['Agreed by Landlord',                 'agreed',   1, 1, 40],
        ['Rejected by Landlord',               'rejected', 0, 0, 50],
        ['Rejected – building already in use', 'rejected', 0, 0, 60], // en dash, NOT --
    ];

    /**
     * Six default usage types seeded per VENUE at creation by seedUsageTypes().
     * [typeName, usageKind, defaultStart|null, defaultEnd|null, sortOrder]
     */
    private const DEFAULT_USAGE_TYPES = [
        ['Regular Hours',        'hire',        '09:30', '13:30', 10],
        ['Extended',             'hire',        '09:00', '15:00', 20],
        ['Extended (All Day)',   'hire',        '09:30', '17:30', 30],
        ['Custom',               'hire',        null,    null,    40],
        ['Closed - Not Needed',  'closed',      null,    null,    50],
        ['Building Unavailable', 'unavailable', null,    null,    60],
    ];

    /* ==========================================================================
     * 🌐 Gate & seeding
     * ======================================================================== */

    /** The ONE capability check every manager handler calls. */
    public static function canManage(): bool
    {
        return App::isAdmin() === true || App::hasRole('venue_manager') === true;
    }

    /**
     * Idempotent per-site seed of the six DEFAULT_STATUSES. Cheap short-
     * circuit: a single indexed probe on the hot "already seeded" path.
     * No audit rows — this is system bootstrap, not a user mutation.
     */
    public static function seedStatuses(int $siteId): void
    {
        $db = self::db();
        $probe = $db->prepare('SELECT 1 FROM tblVenueStatuses WHERE siteID = ? LIMIT 1');
        if ($probe === false) {
            return;
        }
        $probe->bind_param('i', $siteId);
        $probe->execute();
        $exists = $probe->get_result()->fetch_assoc() !== null;
        $probe->close();
        if ($exists === true) {
            return;
        }

        foreach (self::DEFAULT_STATUSES as $row) {
            [$name, $category, $confirmed, $available, $sort] = $row;
            $chk = $db->prepare('SELECT 1 FROM tblVenueStatuses WHERE siteID = ? AND statusName = ? LIMIT 1');
            if ($chk === false) {
                continue;
            }
            $chk->bind_param('is', $siteId, $name);
            $chk->execute();
            $already = $chk->get_result()->fetch_assoc() !== null;
            $chk->close();
            if ($already === true) {
                continue;
            }
            try {
                $ins = $db->prepare(
                    'INSERT INTO tblVenueStatuses (siteID, statusName, statusCategory, countsAsConfirmed, isAvailable, sortOrder) '
                    . 'VALUES (?, ?, ?, ?, ?, ?)'
                );
                if ($ins === false) {
                    continue;
                }
                $ins->bind_param('issiii', $siteId, $name, $category, $confirmed, $available, $sort);
                $ins->execute();
                $ins->close();
            } catch (\mysqli_sql_exception $e) {
                // 🏁 uq_venst_site_name race backstop — a concurrent request
                // seeded this exact row between our check and insert.
                error_log('Venues::seedStatuses() duplicate race for "' . $name . '": ' . $e->getMessage());
            }
        }
    }

    /**
     * Admin "restore default statuses" action: re-inserts any missing
     * DEFAULT_STATUSES row AND re-activates any default-named row that was
     * previously retired. Returns the number of rows touched.
     */
    public static function restoreDefaultStatuses(int $siteId, int $actorUserId): int
    {
        $db = self::db();
        $touched = 0;
        $restoredNames = [];

        foreach (self::DEFAULT_STATUSES as $row) {
            [$name, $category, $confirmed, $available, $sort] = $row;
            $chk = $db->prepare('SELECT statusID, isActive FROM tblVenueStatuses WHERE siteID = ? AND statusName = ? LIMIT 1');
            if ($chk === false) {
                continue;
            }
            $chk->bind_param('is', $siteId, $name);
            $chk->execute();
            $existing = $chk->get_result()->fetch_assoc();
            $chk->close();

            if ($existing === null) {
                try {
                    $ins = $db->prepare(
                        'INSERT INTO tblVenueStatuses (siteID, statusName, statusCategory, countsAsConfirmed, isAvailable, sortOrder) '
                        . 'VALUES (?, ?, ?, ?, ?, ?)'
                    );
                    if ($ins !== false) {
                        $ins->bind_param('issiii', $siteId, $name, $category, $confirmed, $available, $sort);
                        $ins->execute();
                        $ins->close();
                        $touched++;
                        $restoredNames[] = $name;
                    }
                } catch (\mysqli_sql_exception $e) {
                    error_log('Venues::restoreDefaultStatuses() insert race: ' . $e->getMessage());
                }
            } elseif ((int) $existing['isActive'] === 0) {
                $upd = $db->prepare('UPDATE tblVenueStatuses SET isActive = 1 WHERE statusID = ? AND siteID = ?');
                if ($upd !== false) {
                    $sid = (int) $existing['statusID'];
                    $upd->bind_param('ii', $sid, $siteId);
                    $upd->execute();
                    $upd->close();
                    $touched++;
                    $restoredNames[] = $name;
                }
            }
        }

        if ($touched > 0) {
            self::audit('tblVenueStatuses', 0, 'create', null, ['restored' => $restoredNames], $actorUserId);
        }

        return $touched;
    }

    /**
     * At venue creation: seed the six DEFAULT_USAGE_TYPES for $venueId, each
     * with one effectiveFrom='1970-01-01' window row (hire kinds carry real
     * default times; closed/unavailable/Custom get a NULL-times window so
     * resolveWindow() stays uniform across every type). No own audit row —
     * bundled into the venue-create audit's newData as seededUsageTypes: 6.
     */
    public static function seedUsageTypes(int $siteId, int $venueId, int $actorUserId): void
    {
        $db = self::db();
        foreach (self::DEFAULT_USAGE_TYPES as $row) {
            [$name, $kind, $start, $end, $sort] = $row;
            $isBookable = $kind === 'hire' ? 1 : 0;
            $ins = $db->prepare(
                'INSERT INTO tblVenueUsageTypes (siteID, venueID, typeName, usageKind, isBookable, sortOrder) '
                . 'VALUES (?, ?, ?, ?, ?, ?)'
            );
            if ($ins === false) {
                continue;
            }
            $ins->bind_param('iissii', $siteId, $venueId, $name, $kind, $isBookable, $sort);
            $ok = $ins->execute();
            $usageTypeId = (int) $ins->insert_id;
            $ins->close();
            if ($ok === false || $usageTypeId <= 0) {
                continue;
            }

            $effectiveFrom = '1970-01-01';
            $win = $db->prepare(
                'INSERT INTO tblVenueUsageTypeWindows (siteID, usageTypeID, effectiveFrom, defaultStartTime, defaultEndTime, createdByID) '
                . 'VALUES (?, ?, ?, ?, ?, ?)'
            );
            if ($win === false) {
                continue;
            }
            $startVal = $start !== null ? $start . ':00' : null;
            $endVal   = $end !== null ? $end . ':00' : null;
            // SEC-01 fix: 6 placeholders/6 vars need 6 type chars (was 5: 'iissi') — siteID(i), usageTypeID(i), effectiveFrom DATE(s), defaultStartTime/EndTime TIME-or-NULL(s,s), createdByID(i).
            $win->bind_param('iisssi', $siteId, $usageTypeId, $effectiveFrom, $startVal, $endVal, $actorUserId);
            $win->execute();
            $win->close();
        }
    }

    /* ==========================================================================
     * 📝 Audit choke-point
     * ======================================================================== */

    /**
     * Thin wrapper over Logger::audit(). Every venue mutation funnels
     * through here — one call site per logical mutation, bulk ops write
     * ONE summary row. No redaction needed: no secret columns exist in any
     * venue table by design.
     */
    public static function audit(
        string $tableName,
        int $recordId,
        string $action,
        ?array $old,
        ?array $new,
        ?int $userId = null
    ): void {
        if ($userId === null) {
            $sessionUserId = (int) ($_SESSION['user_id'] ?? 0);
            $userId = $sessionUserId > 0 ? $sessionUserId : null;
        }
        Logger::audit($tableName, $recordId, $action, $old, $new, $userId);
    }

    /* ==========================================================================
     * 🏛️ Venues / rooms
     * ======================================================================== */

    public static function listVenues(int $siteId, bool $activeOnly = false): array
    {
        $db = self::db();
        $sql = 'SELECT v.*, o.orgName AS landlordName FROM tblVenues v '
            . 'LEFT JOIN tblAssetOrgs o ON o.orgID = v.landlordOrgID '
            . 'WHERE v.siteID = ?'
            . ($activeOnly === true ? ' AND v.isActive = 1' : '')
            . ' ORDER BY v.venueName ASC';
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            error_log('Venues::listVenues() prepare failed: ' . $db->error);
            return [];
        }
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        $rows = self::fetchAll($stmt);
        $stmt->close();
        return $rows;
    }

    public static function getVenue(int $venueId, int $siteId): ?array
    {
        $db = self::db();
        $stmt = $db->prepare(
            'SELECT v.*, o.orgName AS landlordName FROM tblVenues v '
            . 'LEFT JOIN tblAssetOrgs o ON o.orgID = v.landlordOrgID '
            . 'WHERE v.venueID = ? AND v.siteID = ? LIMIT 1'
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('ii', $venueId, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row !== null ? $row : null;
    }

    /**
     * $venueId = 0 creates; a positive id updates that row (site-scoped).
     * On create, seeds the six default usage types. Returns the id, or 0 on
     * validation failure (blank name / invalid landlord / etc).
     *
     * @param array<string, mixed> $data
     */
    public static function saveVenue(int $siteId, int $venueId, array $data, int $actorUserId): int
    {
        $db = self::db();
        $name = trim((string) ($data['venueName'] ?? ''));
        if ($name === '') {
            return 0;
        }
        $name = mb_substr($name, 0, 255);

        $landlordOrgId = (int) ($data['landlordOrgID'] ?? 0);
        if ($landlordOrgId > 0) {
            $chk = $db->prepare('SELECT 1 FROM tblAssetOrgs WHERE orgID = ? AND siteID = ? LIMIT 1');
            if ($chk === false) {
                return 0;
            }
            $chk->bind_param('ii', $landlordOrgId, $siteId);
            $chk->execute();
            $ok = $chk->get_result()->fetch_assoc() !== null;
            $chk->close();
            if ($ok === false) {
                $landlordOrgId = 0;
            }
        }
        $landlordOrgIdVal = $landlordOrgId > 0 ? $landlordOrgId : null;

        $addr1 = self::nullableTrim($data['addressLine1'] ?? null, 255);
        $addr2 = self::nullableTrim($data['addressLine2'] ?? null, 255);
        $city = self::nullableTrim($data['city'] ?? null, 100);
        $region = self::nullableTrim($data['region'] ?? null, 100);
        $postcode = self::nullableTrim($data['postcode'] ?? null, 20);

        $countryCode = strtoupper(trim((string) ($data['countryCode'] ?? 'GB')));
        if (preg_match('/^[A-Z]{2}$/', $countryCode) !== 1) {
            $countryCode = 'GB';
        }

        // 📍 Geocoordinates / what3words (#456 Chunk A) — validated the
        // same as every other new location field; invalid non-empty W3W
        // is treated as null here (the HTTP handler pre-validates and
        // flashes — the model layer never fatals).
        $coords = GeoLocation::validateCoords($data['latitude'] ?? null, $data['longitude'] ?? null);
        $w3w    = GeoLocation::validateW3W($data['what3words'] ?? null);
        $geocodedAt    = null;
        $geocodeSource = null;

        if ($w3w !== null && What3Words::isConfigured() === true) {
            $verifiedCoords = What3Words::convertToCoordinates($w3w);
            if ($verifiedCoords !== null) {
                if ($coords === null) {
                    $coords = $verifiedCoords;
                    $geocodedAt = date('Y-m-d H:i:s');
                    $geocodeSource = 'w3w';
                }
            }
            // ⚠️ Verification failure is a warning at the HTTP layer, never
            // a rejection here — best-effort, save must still succeed.
        }

        if ($coords !== null && $geocodeSource === null) {
            $geocodeSource = 'manual';
        }

        if ($coords === null && Geocoder::autoEnabled() === true) {
            $addressForGeocode = GeoLocation::formatAddress([
                'line1' => $addr1 ?? null, 'line2' => $addr2 ?? null, 'city' => $city ?? null,
                'region' => $region ?? null, 'postcode' => $postcode ?? null,
            ]);
            if ($addressForGeocode !== '') {
                $geocoded = Geocoder::forward($addressForGeocode, $countryCode);
                if ($geocoded !== null) {
                    $coords = ['lat' => $geocoded['lat'], 'lng' => $geocoded['lng']];
                    $geocodedAt = date('Y-m-d H:i:s');
                    $geocodeSource = $geocoded['source'];
                }
            }
        }

        $latVal = $coords['lat'] ?? null;
        $lngVal = $coords['lng'] ?? null;

        $timezone = trim((string) ($data['timezone'] ?? 'Europe/London'));
        try {
            new \DateTimeZone($timezone);
        } catch (\Throwable $e) {
            $timezone = 'Europe/London';
        }

        $caretakerName = self::nullableTrim($data['caretakerName'] ?? null, 150);
        $caretakerPhone = self::nullableTrim($data['caretakerPhone'] ?? null, 50);
        $notes = self::nullableTrim($data['notes'] ?? null, 65000);

        $old = null;
        if ($venueId > 0) {
            $old = self::getVenue($venueId, $siteId);
            if ($old === null) {
                return 0;
            }
        }

        try {
            if ($venueId > 0) {
                $stmt = $db->prepare(
                    'UPDATE tblVenues SET venueName = ?, landlordOrgID = ?, addressLine1 = ?, addressLine2 = ?, '
                    . 'city = ?, region = ?, postcode = ?, countryCode = ?, timezone = ?, caretakerName = ?, '
                    . 'caretakerPhone = ?, notes = ?, latitude = ?, longitude = ?, what3words = ?, '
                    . 'geocodedAt = ?, geocodeSource = ? WHERE venueID = ? AND siteID = ?'
                );
                if ($stmt === false) {
                    return 0;
                }
                // 📍 #456 Chunk A: 14 -> 19 placeholders/vars (+lat[d], +lng[d], +w3w[s], +geocodedAt[s], +geocodeSource[s]).
                $stmt->bind_param(
                    'sissssssssssddsssii',
                    $name, $landlordOrgIdVal, $addr1, $addr2, $city, $region, $postcode,
                    $countryCode, $timezone, $caretakerName, $caretakerPhone, $notes,
                    $latVal, $lngVal, $w3w, $geocodedAt, $geocodeSource, $venueId, $siteId
                );
                $ok = $stmt->execute();
                $stmt->close();
                if ($ok === false) {
                    return 0;
                }
                self::audit('tblVenues', $venueId, 'update', $old, [
                    'venueName' => $name, 'landlordOrgID' => $landlordOrgIdVal, 'timezone' => $timezone,
                ], $actorUserId);
                return $venueId;
            }

            $stmt = $db->prepare(
                'INSERT INTO tblVenues (siteID, venueName, landlordOrgID, addressLine1, addressLine2, city, region, '
                . 'postcode, countryCode, timezone, caretakerName, caretakerPhone, notes, '
                . 'latitude, longitude, what3words, geocodedAt, geocodeSource, createdByID) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if ($stmt === false) {
                return 0;
            }
            // 📍 #456 Chunk A: 14 -> 19 placeholders/vars (+lat[d], +lng[d], +w3w[s], +geocodedAt[s], +geocodeSource[s]).
            $stmt->bind_param(
                'isissssssssssddsssi',
                $siteId, $name, $landlordOrgIdVal, $addr1, $addr2, $city, $region,
                $postcode, $countryCode, $timezone, $caretakerName, $caretakerPhone, $notes,
                $latVal, $lngVal, $w3w, $geocodedAt, $geocodeSource, $actorUserId
            );
            $ok = $stmt->execute();
            $newId = (int) $stmt->insert_id;
            $stmt->close();
            if ($ok === false || $newId <= 0) {
                return 0;
            }
            self::seedUsageTypes($siteId, $newId, $actorUserId);
            self::audit('tblVenues', $newId, 'create', null, [
                'venueName' => $name, 'landlordOrgID' => $landlordOrgIdVal, 'timezone' => $timezone,
                'seededUsageTypes' => count(self::DEFAULT_USAGE_TYPES),
            ], $actorUserId);
            return $newId;
        } catch (\mysqli_sql_exception $e) {
            error_log('Venues::saveVenue() failed: ' . $e->getMessage());
            return 0;
        }
    }

    public static function toggleVenueActive(int $venueId, int $siteId, int $actorUserId): bool
    {
        $db = self::db();
        $stmt = $db->prepare('UPDATE tblVenues SET isActive = 1 - isActive WHERE venueID = ? AND siteID = ?');
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('ii', $venueId, $siteId);
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($ok === true && $affected > 0) {
            self::audit('tblVenues', $venueId, 'update', null, ['toggled' => true], $actorUserId);
        }
        return $ok === true && $affected > 0;
    }

    /**
     * HARD delete — caller must already be admin (the handler's job to
     * check). Refuses while any booking/invoice/agreement row references
     * the venue, even though the FKs would CASCADE, so history is retired
     * via isActive, never silently destroyed.
     *
     * @return array{ok: bool, error: ?string}
     */
    public static function deleteVenue(int $venueId, int $siteId, int $actorUserId): array
    {
        $db = self::db();
        $venue = self::getVenue($venueId, $siteId);
        if ($venue === null) {
            return ['ok' => false, 'error' => 'Venue not found.'];
        }

        foreach (
            [
                'tblVenueBookings'    => 'bookingID',
                'tblVenueInvoices'    => 'invoiceID',
                'tblVenueAgreements'  => 'agreementID',
            ] as $table => $pk
        ) {
            $stmt = $db->prepare("SELECT 1 FROM `{$table}` WHERE venueID = ? AND siteID = ? LIMIT 1");
            if ($stmt === false) {
                continue;
            }
            $stmt->bind_param('ii', $venueId, $siteId);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_assoc() !== null;
            $stmt->close();
            if ($exists === true) {
                return ['ok' => false, 'error' => 'This venue still has records attached — retire it (make inactive) instead of deleting.'];
            }
        }

        $del = $db->prepare('DELETE FROM tblVenues WHERE venueID = ? AND siteID = ?');
        if ($del === false) {
            return ['ok' => false, 'error' => 'Could not delete venue.'];
        }
        $del->bind_param('ii', $venueId, $siteId);
        $ok = $del->execute();
        $del->close();
        if ($ok === false) {
            return ['ok' => false, 'error' => 'Could not delete venue.'];
        }
        self::audit('tblVenues', $venueId, 'delete', $venue, null, $actorUserId);
        return ['ok' => true, 'error' => null];
    }

    public static function listRooms(int $venueId, int $siteId, bool $activeOnly = false): array
    {
        $db = self::db();
        $sql = 'SELECT * FROM tblVenueRooms WHERE venueID = ? AND siteID = ?'
            . ($activeOnly === true ? ' AND isActive = 1' : '')
            . ' ORDER BY sortOrder ASC, roomName ASC';
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('ii', $venueId, $siteId);
        $stmt->execute();
        $rows = self::fetchAll($stmt);
        $stmt->close();
        return $rows;
    }

    public static function saveRoom(int $siteId, int $venueId, int $roomId, array $data, int $actorUserId): int
    {
        $db = self::db();
        if (self::validateVenue($venueId, $siteId) === null) {
            return 0;
        }
        $name = trim((string) ($data['roomName'] ?? ''));
        if ($name === '') {
            return 0;
        }
        $name = mb_substr($name, 0, 150);
        $description = self::nullableTrim($data['description'] ?? null, 500);
        $capacityRaw = $data['capacity'] ?? null;
        $capacity = ($capacityRaw !== null && $capacityRaw !== '') ? max(0, (int) $capacityRaw) : null;
        $sortOrder = (int) ($data['sortOrder'] ?? 0);

        try {
            if ($roomId > 0) {
                $stmt = $db->prepare(
                    'UPDATE tblVenueRooms SET roomName = ?, description = ?, capacity = ?, sortOrder = ? '
                    . 'WHERE roomID = ? AND venueID = ? AND siteID = ?'
                );
                if ($stmt === false) {
                    return 0;
                }
                $stmt->bind_param('ssiiiii', $name, $description, $capacity, $sortOrder, $roomId, $venueId, $siteId);
                $ok = $stmt->execute();
                $stmt->close();
                if ($ok === false) {
                    return 0;
                }
                self::audit('tblVenueRooms', $roomId, 'update', null, ['roomName' => $name], $actorUserId);
                return $roomId;
            }

            $stmt = $db->prepare(
                'INSERT INTO tblVenueRooms (siteID, venueID, roomName, description, capacity, sortOrder) '
                . 'VALUES (?, ?, ?, ?, ?, ?)'
            );
            if ($stmt === false) {
                return 0;
            }
            $stmt->bind_param('iissii', $siteId, $venueId, $name, $description, $capacity, $sortOrder);
            $ok = $stmt->execute();
            $newId = (int) $stmt->insert_id;
            $stmt->close();
            if ($ok === false || $newId <= 0) {
                return 0;
            }
            self::audit('tblVenueRooms', $newId, 'create', null, ['roomName' => $name], $actorUserId);
            return $newId;
        } catch (\mysqli_sql_exception $e) {
            error_log('Venues::saveRoom() failed (likely duplicate name): ' . $e->getMessage());
            return 0;
        }
    }

    public static function toggleRoomActive(int $roomId, int $siteId, int $actorUserId): bool
    {
        $db = self::db();
        $stmt = $db->prepare('UPDATE tblVenueRooms SET isActive = 1 - isActive WHERE roomID = ? AND siteID = ?');
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('ii', $roomId, $siteId);
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($ok === true && $affected > 0) {
            self::audit('tblVenueRooms', $roomId, 'update', null, ['toggled' => true], $actorUserId);
        }
        return $ok === true && $affected > 0;
    }

    /**
     * Refuses while any non-deleted, future-dated booking references the
     * room (past-only room history is safe to delete — bookings' roomID
     * SET NULLs).
     *
     * @return array{ok: bool, error: ?string}
     */
    public static function deleteRoom(int $roomId, int $siteId, int $actorUserId): array
    {
        $db = self::db();
        $chk = $db->prepare(
            'SELECT 1 FROM tblVenueRooms r WHERE r.roomID = ? AND r.siteID = ? LIMIT 1'
        );
        if ($chk === false) {
            return ['ok' => false, 'error' => 'Room not found.'];
        }
        $chk->bind_param('ii', $roomId, $siteId);
        $chk->execute();
        $room = $chk->get_result()->fetch_assoc();
        $chk->close();
        if ($room === null) {
            return ['ok' => false, 'error' => 'Room not found.'];
        }

        $future = $db->prepare(
            'SELECT 1 FROM tblVenueBookings WHERE roomID = ? AND siteID = ? AND isDeleted = 0 AND bookingDate >= CURDATE() LIMIT 1'
        );
        if ($future !== false) {
            $future->bind_param('ii', $roomId, $siteId);
            $future->execute();
            $hasFuture = $future->get_result()->fetch_assoc() !== null;
            $future->close();
            if ($hasFuture === true) {
                return ['ok' => false, 'error' => 'This room has future bookings — retire it (make inactive) instead of deleting.'];
            }
        }

        $del = $db->prepare('DELETE FROM tblVenueRooms WHERE roomID = ? AND siteID = ?');
        if ($del === false) {
            return ['ok' => false, 'error' => 'Could not delete room.'];
        }
        $del->bind_param('ii', $roomId, $siteId);
        $ok = $del->execute();
        $del->close();
        if ($ok === false) {
            return ['ok' => false, 'error' => 'Could not delete room.'];
        }
        self::audit('tblVenueRooms', $roomId, 'delete', $room, null, $actorUserId);
        return ['ok' => true, 'error' => null];
    }

    /* ==========================================================================
     * ⏰ Usage types & windows
     * ======================================================================== */

    public static function listUsageTypes(int $venueId, int $siteId, bool $activeOnly = false, ?string $forDate = null): array
    {
        $db = self::db();
        $sql = 'SELECT * FROM tblVenueUsageTypes WHERE venueID = ? AND siteID = ?'
            . ($activeOnly === true ? ' AND isActive = 1' : '')
            . ' ORDER BY sortOrder ASC, typeName ASC';
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('ii', $venueId, $siteId);
        $stmt->execute();
        $rows = self::fetchAll($stmt);
        $stmt->close();

        $date = $forDate ?? date('Y-m-d');
        foreach ($rows as &$row) {
            $row['resolvedWindow'] = self::resolveWindow((int) $row['usageTypeID'], $date, $siteId);
        }
        unset($row);
        return $rows;
    }

    /**
     * @return array{id: int, error: ?string}
     */
    public static function saveUsageType(int $siteId, int $venueId, int $usageTypeId, array $data, int $actorUserId): array
    {
        $db = self::db();
        if (self::validateVenue($venueId, $siteId) === null) {
            return ['id' => 0, 'error' => 'Invalid venue.'];
        }
        $name = trim((string) ($data['typeName'] ?? ''));
        if ($name === '') {
            return ['id' => 0, 'error' => 'Name is required.'];
        }
        $name = mb_substr($name, 0, 100);

        $kind = (string) ($data['usageKind'] ?? 'hire');
        if (in_array($kind, self::USAGE_KINDS, true) === false) {
            $kind = 'hire';
        }
        $isBookable = $kind === 'hire' ? 1 : 0;
        $sortOrder = (int) ($data['sortOrder'] ?? 0);

        if ($usageTypeId > 0) {
            $existing = $db->prepare('SELECT usageKind FROM tblVenueUsageTypes WHERE usageTypeID = ? AND venueID = ? AND siteID = ? LIMIT 1');
            if ($existing === false) {
                return ['id' => 0, 'error' => 'Usage type not found.'];
            }
            $existing->bind_param('iii', $usageTypeId, $venueId, $siteId);
            $existing->execute();
            $row = $existing->get_result()->fetch_assoc();
            $existing->close();
            if ($row === null) {
                return ['id' => 0, 'error' => 'Usage type not found.'];
            }
            if ((string) $row['usageKind'] !== $kind) {
                $inUse = $db->prepare('SELECT 1 FROM tblVenueBookings WHERE usageTypeID = ? AND isDeleted = 0 LIMIT 1');
                if ($inUse !== false) {
                    $inUse->bind_param('i', $usageTypeId);
                    $inUse->execute();
                    $used = $inUse->get_result()->fetch_assoc() !== null;
                    $inUse->close();
                    if ($used === true) {
                        return ['id' => 0, 'error' => 'in-use — create a new type instead'];
                    }
                }
            }
        }

        try {
            if ($usageTypeId > 0) {
                $stmt = $db->prepare(
                    'UPDATE tblVenueUsageTypes SET typeName = ?, usageKind = ?, isBookable = ?, sortOrder = ? '
                    . 'WHERE usageTypeID = ? AND venueID = ? AND siteID = ?'
                );
                if ($stmt === false) {
                    return ['id' => 0, 'error' => 'Could not save.'];
                }
                $stmt->bind_param('ssiiiii', $name, $kind, $isBookable, $sortOrder, $usageTypeId, $venueId, $siteId);
                $ok = $stmt->execute();
                $stmt->close();
                if ($ok === false) {
                    return ['id' => 0, 'error' => 'Could not save.'];
                }
                self::audit('tblVenueUsageTypes', $usageTypeId, 'update', null, ['typeName' => $name, 'usageKind' => $kind], $actorUserId);
                return ['id' => $usageTypeId, 'error' => null];
            }

            $stmt = $db->prepare(
                'INSERT INTO tblVenueUsageTypes (siteID, venueID, typeName, usageKind, isBookable, sortOrder) VALUES (?, ?, ?, ?, ?, ?)'
            );
            if ($stmt === false) {
                return ['id' => 0, 'error' => 'Could not save.'];
            }
            $stmt->bind_param('iissii', $siteId, $venueId, $name, $kind, $isBookable, $sortOrder);
            $ok = $stmt->execute();
            $newId = (int) $stmt->insert_id;
            $stmt->close();
            if ($ok === false || $newId <= 0) {
                return ['id' => 0, 'error' => 'Could not save.'];
            }
            self::audit('tblVenueUsageTypes', $newId, 'create', null, ['typeName' => $name, 'usageKind' => $kind], $actorUserId);
            return ['id' => $newId, 'error' => null];
        } catch (\mysqli_sql_exception $e) {
            return ['id' => 0, 'error' => 'A usage type with that name already exists for this venue.'];
        }
    }

    public static function toggleUsageTypeActive(int $usageTypeId, int $siteId, int $actorUserId): bool
    {
        $db = self::db();
        $stmt = $db->prepare('UPDATE tblVenueUsageTypes SET isActive = 1 - isActive WHERE usageTypeID = ? AND siteID = ?');
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('ii', $usageTypeId, $siteId);
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($ok === true && $affected > 0) {
            self::audit('tblVenueUsageTypes', $usageTypeId, 'update', null, ['toggled' => true], $actorUserId);
        }
        return $ok === true && $affected > 0;
    }

    /**
     * @return array{id: int, error: ?string}
     */
    public static function saveWindow(int $siteId, int $usageTypeId, array $data, int $actorUserId): array
    {
        $db = self::db();
        $typeChk = $db->prepare('SELECT 1 FROM tblVenueUsageTypes WHERE usageTypeID = ? AND siteID = ? LIMIT 1');
        if ($typeChk === false) {
            return ['id' => 0, 'error' => 'Usage type not found.'];
        }
        $typeChk->bind_param('ii', $usageTypeId, $siteId);
        $typeChk->execute();
        $typeOk = $typeChk->get_result()->fetch_assoc() !== null;
        $typeChk->close();
        if ($typeOk === false) {
            return ['id' => 0, 'error' => 'Usage type not found.'];
        }

        $effectiveFrom = (string) ($data['effectiveFrom'] ?? '');
        $parts = explode('-', $effectiveFrom);
        if (count($parts) !== 3 || checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0]) === false) {
            return ['id' => 0, 'error' => 'Invalid effective-from date.'];
        }

        $start = trim((string) ($data['defaultStartTime'] ?? ''));
        $end = trim((string) ($data['defaultEndTime'] ?? ''));
        if (($start === '') !== ($end === '')) {
            return ['id' => 0, 'error' => 'Provide both a start and end time, or leave both blank.'];
        }
        $startVal = null;
        $endVal = null;
        if ($start !== '' && $end !== '') {
            // 🕑 Accept HH:MM or HH:MM:SS — the BackingData importer
            //     (importBackingDataWindows) feeds parsed times that already
            //     carry a :SS suffix, so a strict HH:MM-only rule silently
            //     dropped every imported default window. Normalise to HH:MM
            //     then re-append :00 for storage (windows are minute-grained).
            if (preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $start) !== 1
                || preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $end) !== 1) {
                return ['id' => 0, 'error' => 'Times must be HH:MM.'];
            }
            $startVal = substr($start, 0, 5) . ':00';
            $endVal = substr($end, 0, 5) . ':00';
            if ($endVal <= $startVal) {
                return ['id' => 0, 'error' => 'End time must be after start time.'];
            }
        }
        $note = self::nullableTrim($data['note'] ?? null, 255);

        try {
            $stmt = $db->prepare(
                'INSERT INTO tblVenueUsageTypeWindows (siteID, usageTypeID, effectiveFrom, defaultStartTime, defaultEndTime, note, createdByID) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            if ($stmt === false) {
                return ['id' => 0, 'error' => 'Could not save.'];
            }
            // SEC-01 fix: 7 placeholders/7 vars need 7 type chars (was 6: 'iisssi').
            $stmt->bind_param('iissssi', $siteId, $usageTypeId, $effectiveFrom, $startVal, $endVal, $note, $actorUserId);
            $ok = $stmt->execute();
            $newId = (int) $stmt->insert_id;
            $stmt->close();
            if ($ok === false || $newId <= 0) {
                return ['id' => 0, 'error' => 'Could not save.'];
            }
            self::audit('tblVenueUsageTypeWindows', $newId, 'create', null, [
                'effectiveFrom' => $effectiveFrom, 'defaultStartTime' => $startVal, 'defaultEndTime' => $endVal,
            ], $actorUserId);
            return ['id' => $newId, 'error' => null];
        } catch (\mysqli_sql_exception $e) {
            return ['id' => 0, 'error' => 'A window already exists for this usage type effective from that date.'];
        }
    }

    public static function deleteWindow(int $windowId, int $siteId, int $actorUserId): bool
    {
        $db = self::db();
        $del = $db->prepare('DELETE FROM tblVenueUsageTypeWindows WHERE windowID = ? AND siteID = ?');
        if ($del === false) {
            return false;
        }
        $del->bind_param('ii', $windowId, $siteId);
        $ok = $del->execute();
        $affected = $del->affected_rows;
        $del->close();
        if ($ok === true && $affected > 0) {
            self::audit('tblVenueUsageTypeWindows', $windowId, 'delete', null, null, $actorUserId);
        }
        return $ok === true && $affected > 0;
    }

    /**
     * THE data-model resolution rule, exactly: the row for usage type U on
     * date D is the one with usageTypeID=U AND effectiveFrom <= D having
     * the GREATEST effectiveFrom. No row => null (times manual). A row
     * with NULL times is a real "no default" answer.
     *
     * SEC-04 (defence-in-depth): site-scoped — every live caller already
     * pre-validates usageTypeId against the caller's siteId before reaching
     * here, so this was not previously reachable cross-tenant, but the
     * SELECT now carries its own AND siteID = ? guard too.
     *
     * @return ?array{start: ?string, end: ?string}
     */
    public static function resolveWindow(int $usageTypeId, string $date, int $siteId): ?array
    {
        $db = self::db();
        $stmt = $db->prepare(
            'SELECT defaultStartTime, defaultEndTime FROM tblVenueUsageTypeWindows '
            . 'WHERE usageTypeID = ? AND effectiveFrom <= ? AND siteID = ? ORDER BY effectiveFrom DESC LIMIT 1'
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('isi', $usageTypeId, $date, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row === null) {
            return null;
        }
        return ['start' => $row['defaultStartTime'], 'end' => $row['defaultEndTime']];
    }

    /**
     * "Re-apply changed defaults" tool: for future non-deleted bookings of
     * this usage type with timesOverridden=0 and bookingDate >= $fromDate,
     * re-resolve EACH booking's own date's window and update. Returns the
     * number of rows touched; ONE summary audit row.
     */
    public static function reapplyWindowDefaults(int $siteId, int $usageTypeId, string $fromDate, int $actorUserId): int
    {
        $db = self::db();
        $stmt = $db->prepare(
            'SELECT bookingID, bookingDate FROM tblVenueBookings '
            . 'WHERE usageTypeID = ? AND siteID = ? AND isDeleted = 0 AND timesOverridden = 0 AND bookingDate >= ?'
        );
        if ($stmt === false) {
            return 0;
        }
        $stmt->bind_param('iis', $usageTypeId, $siteId, $fromDate);
        $stmt->execute();
        $rows = self::fetchAll($stmt);
        $stmt->close();

        $touched = 0;
        foreach ($rows as $row) {
            $bookingId = (int) $row['bookingID'];
            $window = self::resolveWindow($usageTypeId, (string) $row['bookingDate'], $siteId);
            $upd = $db->prepare('UPDATE tblVenueBookings SET startTime = ?, endTime = ? WHERE bookingID = ? AND siteID = ?');
            if ($upd === false) {
                continue;
            }
            $start = $window['start'] ?? null;
            $end = $window['end'] ?? null;
            $upd->bind_param('ssii', $start, $end, $bookingId, $siteId);
            $upd->execute();
            $upd->close();
            $touched++;
        }

        if ($touched > 0) {
            self::audit('tblVenueUsageTypes', $usageTypeId, 'update', null, ['reappliedWindows' => $touched, 'fromDate' => $fromDate], $actorUserId);
        }
        return $touched;
    }

    /* ==========================================================================
     * 🚦 Statuses
     * ======================================================================== */

    public static function listStatuses(int $siteId, bool $activeOnly = false): array
    {
        $db = self::db();
        $sql = 'SELECT * FROM tblVenueStatuses WHERE siteID = ?'
            . ($activeOnly === true ? ' AND isActive = 1' : '')
            . ' ORDER BY sortOrder ASC, statusName ASC';
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        $rows = self::fetchAll($stmt);
        $stmt->close();
        return $rows;
    }

    /**
     * @return array{id: int, error: ?string}
     */
    public static function saveStatus(int $siteId, int $statusId, array $data, int $actorUserId): array
    {
        $db = self::db();
        $name = trim((string) ($data['statusName'] ?? ''));
        if ($name === '' || mb_strlen($name) > 150) {
            return ['id' => 0, 'error' => 'Name is required (max 150 characters).'];
        }
        $category = (string) ($data['statusCategory'] ?? 'proposed');
        if (in_array($category, self::STATUS_CATEGORIES, true) === false) {
            $category = 'proposed';
        }
        $confirmed = !empty($data['countsAsConfirmed']) ? 1 : 0;
        $available = !empty($data['isAvailable']) ? 1 : 0;
        $sortOrder = (int) ($data['sortOrder'] ?? 0);

        $color = trim((string) ($data['color'] ?? ''));
        $colorVal = null;
        if ($color !== '' && preg_match('/^#[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/', $color) === 1) {
            $colorVal = $color;
        }

        try {
            if ($statusId > 0) {
                $stmt = $db->prepare(
                    'UPDATE tblVenueStatuses SET statusName = ?, statusCategory = ?, countsAsConfirmed = ?, isAvailable = ?, color = ?, sortOrder = ? '
                    . 'WHERE statusID = ? AND siteID = ?'
                );
                if ($stmt === false) {
                    return ['id' => 0, 'error' => 'Could not save.'];
                }
                $stmt->bind_param('ssiisiii', $name, $category, $confirmed, $available, $colorVal, $sortOrder, $statusId, $siteId);
                $ok = $stmt->execute();
                $stmt->close();
                if ($ok === false) {
                    return ['id' => 0, 'error' => 'Could not save.'];
                }
                self::audit('tblVenueStatuses', $statusId, 'update', null, ['statusName' => $name, 'countsAsConfirmed' => $confirmed], $actorUserId);
                return ['id' => $statusId, 'error' => null];
            }

            $stmt = $db->prepare(
                'INSERT INTO tblVenueStatuses (siteID, statusName, statusCategory, countsAsConfirmed, isAvailable, color, sortOrder) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            if ($stmt === false) {
                return ['id' => 0, 'error' => 'Could not save.'];
            }
            $stmt->bind_param('issiisi', $siteId, $name, $category, $confirmed, $available, $colorVal, $sortOrder);
            $ok = $stmt->execute();
            $newId = (int) $stmt->insert_id;
            $stmt->close();
            if ($ok === false || $newId <= 0) {
                return ['id' => 0, 'error' => 'Could not save.'];
            }
            self::audit('tblVenueStatuses', $newId, 'create', null, ['statusName' => $name], $actorUserId);
            return ['id' => $newId, 'error' => null];
        } catch (\mysqli_sql_exception $e) {
            return ['id' => 0, 'error' => 'A status with that name already exists for this site.'];
        }
    }

    public static function toggleStatusActive(int $statusId, int $siteId, int $actorUserId): bool
    {
        $db = self::db();
        $stmt = $db->prepare('UPDATE tblVenueStatuses SET isActive = 1 - isActive WHERE statusID = ? AND siteID = ?');
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('ii', $statusId, $siteId);
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($ok === true && $affected > 0) {
            self::audit('tblVenueStatuses', $statusId, 'update', null, ['toggled' => true], $actorUserId);
        }
        return $ok === true && $affected > 0;
    }

    public static function statusColor(array $row): string
    {
        $color = (string) ($row['color'] ?? '');
        if (preg_match('/^#[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/', $color) === 1) {
            return $color;
        }
        $category = (string) ($row['statusCategory'] ?? '');
        return self::CATEGORY_COLORS[$category] ?? '#6c757d';
    }

    /* ==========================================================================
     * 📅 Bookings
     * ======================================================================== */

    public static function getBooking(int $bookingId, int $siteId): ?array
    {
        $db = self::db();
        $stmt = $db->prepare(
            'SELECT b.*, v.venueName, r.roomName, ut.typeName AS usageTypeName, ut.usageKind, '
            . 'st.statusName, st.statusCategory, st.countsAsConfirmed, st.color AS statusColorRaw, '
            . 'g.groupType, g.label AS groupLabel, ag.title AS agreementTitle, e.eventName '
            . 'FROM tblVenueBookings b '
            . 'JOIN tblVenues v ON v.venueID = b.venueID '
            . 'LEFT JOIN tblVenueRooms r ON r.roomID = b.roomID '
            . 'JOIN tblVenueUsageTypes ut ON ut.usageTypeID = b.usageTypeID '
            . 'JOIN tblVenueStatuses st ON st.statusID = b.statusID '
            . 'LEFT JOIN tblVenueBookingGroups g ON g.groupID = b.groupID '
            . 'LEFT JOIN tblVenueAgreements ag ON ag.agreementID = b.agreementID '
            . 'LEFT JOIN tblEvents e ON e.eventID = b.eventID '
            . 'WHERE b.bookingID = ? AND b.siteID = ? LIMIT 1'
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('ii', $bookingId, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row !== null ? $row : null;
    }

    /**
     * @param array<string, mixed> $filters venueID, roomID, dateFrom, dateTo, year, statusID, usageTypeID,
     *                                       groupID, includeRejected (default true), includeDeleted (default false)
     */
    public static function listBookings(int $siteId, array $filters): array
    {
        $db = self::db();
        $where = ['b.siteID = ?'];
        $types = 'i';
        $params = [$siteId];

        if (($filters['includeDeleted'] ?? false) !== true) {
            $where[] = 'b.isDeleted = 0';
        }
        if (!empty($filters['venueID'])) {
            $where[] = 'b.venueID = ?';
            $types .= 'i';
            $params[] = (int) $filters['venueID'];
        }
        if (!empty($filters['roomID'])) {
            $where[] = 'b.roomID = ?';
            $types .= 'i';
            $params[] = (int) $filters['roomID'];
        }
        if (!empty($filters['statusID'])) {
            $where[] = 'b.statusID = ?';
            $types .= 'i';
            $params[] = (int) $filters['statusID'];
        }
        if (!empty($filters['usageTypeID'])) {
            $where[] = 'b.usageTypeID = ?';
            $types .= 'i';
            $params[] = (int) $filters['usageTypeID'];
        }
        if (!empty($filters['groupID'])) {
            $where[] = 'b.groupID = ?';
            $types .= 'i';
            $params[] = (int) $filters['groupID'];
        }
        if (!empty($filters['year'])) {
            $where[] = 'YEAR(b.bookingDate) = ?';
            $types .= 'i';
            $params[] = (int) $filters['year'];
        }
        if (!empty($filters['dateFrom'])) {
            $where[] = 'b.bookingDate >= ?';
            $types .= 's';
            $params[] = (string) $filters['dateFrom'];
        }
        if (!empty($filters['dateTo'])) {
            $where[] = 'b.bookingDate <= ?';
            $types .= 's';
            $params[] = (string) $filters['dateTo'];
        }
        if (($filters['includeRejected'] ?? true) !== true) {
            $where[] = "NOT (ut.isBookable = 1 AND st.statusCategory = 'rejected')";
        }

        $sql = 'SELECT b.*, v.venueName, r.roomName, ut.typeName AS usageTypeName, ut.usageKind, '
            . 'st.statusName, st.statusCategory, st.countsAsConfirmed, st.color AS statusColorRaw '
            . 'FROM tblVenueBookings b '
            . 'JOIN tblVenues v ON v.venueID = b.venueID '
            . 'LEFT JOIN tblVenueRooms r ON r.roomID = b.roomID '
            . 'JOIN tblVenueUsageTypes ut ON ut.usageTypeID = b.usageTypeID '
            . 'JOIN tblVenueStatuses st ON st.statusID = b.statusID '
            . 'WHERE ' . implode(' AND ', $where)
            . ' ORDER BY b.bookingDate ASC, b.startTime ASC';

        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = self::fetchAll($stmt);
        $stmt->close();
        return $rows;
    }

    /**
     * THE invariant choke-point. Every referenced id is re-validated
     * site/venue-scoped here regardless of what the caller already checked
     * (security item 2). Errors non-empty => NO write.
     *
     * @param array<string, mixed> $data
     * @return array{id: int, errors: string[]}
     */
    public static function saveBooking(int $siteId, int $bookingId, array $data, int $actorUserId): array
    {
        $db = self::db();
        $errors = [];
        $old = null;

        if ($bookingId > 0) {
            $old = self::getBooking($bookingId, $siteId);
            if ($old === null) {
                return ['id' => 0, 'errors' => ['Booking not found.']];
            }
        }
        $isCreate = $old === null;

        $venueId = (int) ($data['venueID'] ?? 0);
        $venue = self::validateVenue($venueId, $siteId, $isCreate);
        if ($venue === null) {
            $errors[] = 'Invalid venue.';
        }

        $statusId = (int) ($data['statusID'] ?? 0);
        $statusChanged = $isCreate === true || $statusId !== (int) ($old['statusID'] ?? 0);
        $status = self::validateStatusForSite($statusId, $siteId, $statusChanged);
        if ($status === null) {
            $errors[] = 'Invalid status.';
        }

        $usageTypeId = (int) ($data['usageTypeID'] ?? 0);
        $usageTypeChanged = $isCreate === true || $usageTypeId !== (int) ($old['usageTypeID'] ?? 0);
        $usageType = self::validateUsageTypeForVenue($usageTypeId, $venueId, $siteId, $usageTypeChanged);
        if ($usageType === null) {
            $errors[] = 'Invalid usage type.';
        }

        $roomId = null;
        $roomRaw = (int) ($data['roomID'] ?? 0);
        if ($roomRaw > 0) {
            if (self::validateRoomForVenue($roomRaw, $venueId, $siteId) === null) {
                $errors[] = 'Invalid room.';
            } else {
                $roomId = $roomRaw;
            }
        }

        $eventId = null;
        $eventRaw = (int) ($data['eventID'] ?? 0);
        if ($eventRaw > 0) {
            if (self::validateEventForSite($eventRaw, $siteId) === null) {
                $errors[] = 'Invalid event.';
            } else {
                $eventId = $eventRaw;
            }
        }

        $agreementId = null;
        $agreementRaw = (int) ($data['agreementID'] ?? 0);
        if ($agreementRaw > 0) {
            if (self::validateAgreementForVenue($agreementRaw, $venueId, $siteId) === null) {
                $errors[] = 'Invalid agreement.';
            } else {
                $agreementId = $agreementRaw;
            }
        }

        $groupId = null;
        $groupRaw = (int) ($data['groupID'] ?? 0);
        if ($groupRaw > 0) {
            if (self::validateGroupForVenue($groupRaw, $venueId, $siteId) === null) {
                $errors[] = 'Invalid booking group.';
            } else {
                $groupId = $groupRaw;
            }
        }

        $bookingDate = (string) ($data['bookingDate'] ?? '');
        $dp = explode('-', $bookingDate);
        $validDate = count($dp) === 3 && checkdate((int) $dp[1], (int) $dp[2], (int) $dp[0]);
        if ($validDate === false) {
            $errors[] = 'Invalid booking date.';
        }

        $costPence = null;
        $costRaw = $data['costPence'] ?? null;
        if ($costRaw !== null && $costRaw !== '') {
            $costPence = (int) $costRaw;
            if ($costPence < 0) {
                $errors[] = 'Cost cannot be negative.';
                $costPence = null;
            }
        }
        $currency = strtoupper(trim((string) ($data['currency'] ?? 'GBP')));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            $currency = 'GBP';
        }

        // ⏰ Time resolution (§3.4 rule)
        $startTime = null;
        $endTime = null;
        $timesOverridden = 0;
        $isHireKind = $usageType !== null && (string) $usageType['usageKind'] === 'hire';
        if ($isHireKind === true) {
            $postedStart = trim((string) ($data['startTime'] ?? ''));
            $postedEnd = trim((string) ($data['endTime'] ?? ''));
            $timeRe = '/^([01]\d|2[0-3]):[0-5]\d$/';
            if ($postedStart === '' && $postedEnd === '') {
                $resolved = $validDate === true ? self::resolveWindow($usageTypeId, $bookingDate, $siteId) : null;
                $startTime = $resolved['start'] ?? null;
                $endTime = $resolved['end'] ?? null;
                $timesOverridden = 0;
            } elseif ($postedStart !== '' && $postedEnd !== '') {
                if (preg_match($timeRe, $postedStart) !== 1 || preg_match($timeRe, $postedEnd) !== 1) {
                    $errors[] = 'Times must be in HH:MM format.';
                } else {
                    $sVal = $postedStart . ':00';
                    $eVal = $postedEnd . ':00';
                    if ($eVal <= $sVal) {
                        $errors[] = 'End time must be after start time.';
                    } else {
                        $startTime = $sVal;
                        $endTime = $eVal;
                        $resolved = $validDate === true ? self::resolveWindow($usageTypeId, $bookingDate, $siteId) : null;
                        $matchesDefault = $resolved !== null && ($resolved['start'] ?? null) === $sVal && ($resolved['end'] ?? null) === $eVal;
                        $timesOverridden = $matchesDefault === true ? 0 : 1;
                    }
                }
            } else {
                $errors[] = 'Provide both a start and end time, or leave both blank to use the default.';
            }
        }

        $notes = self::nullableTrim($data['notes'] ?? null, 65000);

        if (count($errors) > 0) {
            return ['id' => $bookingId, 'errors' => $errors];
        }

        $newData = [
            'venueID' => $venueId, 'roomID' => $roomId, 'groupID' => $groupId, 'bookingDate' => $bookingDate,
            'usageTypeID' => $usageTypeId, 'startTime' => $startTime, 'endTime' => $endTime,
            'timesOverridden' => $timesOverridden, 'statusID' => $statusId, 'notes' => $notes,
            'eventID' => $eventId, 'agreementID' => $agreementId, 'costPence' => $costPence, 'currency' => $currency,
        ];

        try {
            if ($isCreate === true) {
                $stmt = $db->prepare(
                    'INSERT INTO tblVenueBookings (siteID, venueID, roomID, groupID, bookingDate, usageTypeID, '
                    . 'startTime, endTime, timesOverridden, statusID, notes, eventID, agreementID, costPence, currency, createdByID) '
                    . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                if ($stmt === false) {
                    return ['id' => 0, 'errors' => ['Could not save booking.']];
                }
                // SEC-01 fix: 16 placeholders/16 vars need 16 type chars (was 15: 'iiiisissiisiisi' — missing the 'i' for agreementID).
                $stmt->bind_param(
                    'iiiisissiisiiisi',
                    $siteId, $venueId, $roomId, $groupId, $bookingDate, $usageTypeId,
                    $startTime, $endTime, $timesOverridden, $statusId, $notes,
                    $eventId, $agreementId, $costPence, $currency, $actorUserId
                );
                $ok = $stmt->execute();
                $newId = (int) $stmt->insert_id;
                $stmt->close();
                if ($ok === false || $newId <= 0) {
                    return ['id' => 0, 'errors' => ['Could not save booking.']];
                }
                self::audit('tblVenueBookings', $newId, 'create', null, $newData, $actorUserId);
                return ['id' => $newId, 'errors' => []];
            }

            $stmt = $db->prepare(
                'UPDATE tblVenueBookings SET venueID = ?, roomID = ?, groupID = ?, bookingDate = ?, usageTypeID = ?, '
                . 'startTime = ?, endTime = ?, timesOverridden = ?, statusID = ?, notes = ?, eventID = ?, '
                . 'agreementID = ?, costPence = ?, currency = ?, updatedByID = ? WHERE bookingID = ? AND siteID = ?'
            );
            if ($stmt === false) {
                return ['id' => $bookingId, 'errors' => ['Could not save booking.']];
            }
            // SEC-01 fix: 17 placeholders (15 SET + 2 WHERE)/17 vars need 17 type chars (was 16: 'iiisiissisiisiii', also mis-ordered around startTime/usageTypeID).
            $stmt->bind_param(
                'iiisissiisiiisiii',
                $venueId, $roomId, $groupId, $bookingDate, $usageTypeId,
                $startTime, $endTime, $timesOverridden, $statusId, $notes,
                $eventId, $agreementId, $costPence, $currency, $actorUserId, $bookingId, $siteId
            );
            $ok = $stmt->execute();
            $stmt->close();
            if ($ok === false) {
                return ['id' => $bookingId, 'errors' => ['Could not save booking.']];
            }
            self::audit('tblVenueBookings', $bookingId, 'update', $old, $newData, $actorUserId);
            return ['id' => $bookingId, 'errors' => []];
        } catch (\mysqli_sql_exception $e) {
            error_log('Venues::saveBooking() failed: ' . $e->getMessage());
            return ['id' => $bookingId, 'errors' => ['Could not save booking.']];
        }
    }

    public static function softDeleteBooking(int $bookingId, int $siteId, int $actorUserId): bool
    {
        $db = self::db();
        $old = self::getBooking($bookingId, $siteId);
        if ($old === null) {
            return false;
        }
        $stmt = $db->prepare('UPDATE tblVenueBookings SET isDeleted = 1, updatedByID = ? WHERE bookingID = ? AND siteID = ?');
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('iii', $actorUserId, $bookingId, $siteId);
        $ok = $stmt->execute();
        $stmt->close();
        if ($ok === true) {
            self::audit('tblVenueBookings', $bookingId, 'delete', $old, null, $actorUserId);
        }
        return $ok === true;
    }

    public static function setGroupStatus(int $groupId, int $siteId, int $statusId, int $actorUserId): int
    {
        $db = self::db();
        $group = self::validateGroupForVenueAny($groupId, $siteId);
        if ($group === null) {
            return 0;
        }
        if (self::validateStatusForSite($statusId, $siteId, false) === null) {
            return 0;
        }
        $stmt = $db->prepare('UPDATE tblVenueBookings SET statusID = ?, updatedByID = ? WHERE groupID = ? AND siteID = ? AND isDeleted = 0');
        if ($stmt === false) {
            return 0;
        }
        $stmt->bind_param('iiii', $statusId, $actorUserId, $groupId, $siteId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected > 0) {
            self::audit('tblVenueBookingGroups', $groupId, 'update', null, ['statusID' => $statusId, 'rowsAffected' => $affected], $actorUserId);
        }
        return $affected;
    }

    public static function softDeleteGroup(int $groupId, int $siteId, int $actorUserId): int
    {
        $db = self::db();
        $group = self::validateGroupForVenueAny($groupId, $siteId);
        if ($group === null) {
            return 0;
        }
        $stmt = $db->prepare('UPDATE tblVenueBookings SET isDeleted = 1, updatedByID = ? WHERE groupID = ? AND siteID = ? AND isDeleted = 0');
        if ($stmt === false) {
            return 0;
        }
        $stmt->bind_param('iii', $actorUserId, $groupId, $siteId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected > 0) {
            self::audit('tblVenueBookingGroups', $groupId, 'delete', null, ['rowsAffected' => $affected], $actorUserId);
        }
        return $affected;
    }

    /* ==========================================================================
     * 🔁 Recurring / multi-day generator
     * ======================================================================== */

    /**
     * Pure expansion + duplicate probe, NO writes. Used by generate.php's
     * preview step; commit (generateSeries) re-runs this from scratch and
     * never trusts a client-posted date list.
     *
     * @param array<string, mixed> $params
     * @return array{dates: string[], skipped: string[], errors: string[]}
     */
    public static function previewSeries(int $siteId, int $venueId, array $params): array
    {
        return self::expandSeries($siteId, $venueId, $params);
    }

    /**
     * @param array<string, mixed> $params
     * @return array{groupID: int, created: string[], skipped: string[], errors: string[]}
     */
    public static function generateSeries(int $siteId, int $venueId, array $params, int $actorUserId): array
    {
        $db = self::db();
        $expansion = self::expandSeries($siteId, $venueId, $params);
        if (count($expansion['errors']) > 0 && count($expansion['dates']) === 0) {
            return ['groupID' => 0, 'created' => [], 'skipped' => $expansion['skipped'], 'errors' => $expansion['errors']];
        }

        $groupType = (string) ($params['groupType'] ?? 'multi-day');
        if (in_array($groupType, self::GROUP_TYPES, true) === false) {
            $groupType = 'multi-day';
        }
        $frequency = $groupType === 'recurring' ? (string) ($params['frequency'] ?? null) : null;
        if ($frequency !== null && in_array($frequency, self::FREQUENCIES, true) === false) {
            $frequency = null;
        }
        $intervalVal = isset($params['intervalVal']) ? (int) $params['intervalVal'] : null;
        $daysOfWeekArr = array_map('intval', (array) ($params['daysOfWeek'] ?? []));
        $daysOfWeekCsv = count($daysOfWeekArr) > 0 ? implode(',', $daysOfWeekArr) : null;
        $dateFrom = (string) ($params['dateFrom'] ?? '');
        $dateTo = (string) ($params['dateTo'] ?? '');
        $label = self::nullableTrim($params['label'] ?? null, 150);
        $usageTypeId = (int) ($params['usageTypeID'] ?? 0);
        $statusId = (int) ($params['statusID'] ?? 0);
        $roomId = (int) ($params['roomID'] ?? 0);
        $roomVal = $roomId > 0 ? $roomId : null;
        $notes = self::nullableTrim($params['notes'] ?? null, 65000);
        $extendGroupId = (int) ($params['extendGroupID'] ?? 0);

        $db->begin_transaction();
        try {
            $groupId = 0;
            $oldGroup = null;
            if ($extendGroupId > 0) {
                $oldGroup = self::validateGroupForVenueAny($extendGroupId, $siteId);
                if ($oldGroup === null || (int) $oldGroup['venueID'] !== $venueId) {
                    $db->rollback();
                    return ['groupID' => 0, 'created' => [], 'skipped' => [], 'errors' => ['Invalid series to extend.']];
                }
                $groupId = $extendGroupId;
                $upd = $db->prepare('UPDATE tblVenueBookingGroups SET dateTo = ? WHERE groupID = ? AND siteID = ?');
                if ($upd !== false) {
                    $upd->bind_param('sii', $dateTo, $groupId, $siteId);
                    $upd->execute();
                    $upd->close();
                }
            } else {
                $ins = $db->prepare(
                    'INSERT INTO tblVenueBookingGroups (siteID, venueID, groupType, label, frequency, intervalVal, daysOfWeek, dateFrom, dateTo, createdByID) '
                    . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                if ($ins === false) {
                    $db->rollback();
                    return ['groupID' => 0, 'created' => [], 'skipped' => [], 'errors' => ['Could not create series.']];
                }
                $ins->bind_param(
                    'iisssisssi',
                    $siteId, $venueId, $groupType, $label, $frequency, $intervalVal, $daysOfWeekCsv, $dateFrom, $dateTo, $actorUserId
                );
                $ins->execute();
                $groupId = (int) $ins->insert_id;
                $ins->close();
                if ($groupId <= 0) {
                    $db->rollback();
                    return ['groupID' => 0, 'created' => [], 'skipped' => [], 'errors' => ['Could not create series.']];
                }
            }

            $created = [];
            foreach ($expansion['dates'] as $date) {
                $window = self::resolveWindow($usageTypeId, $date, $siteId);
                $start = $window['start'] ?? null;
                $end = $window['end'] ?? null;
                $bstmt = $db->prepare(
                    'INSERT INTO tblVenueBookings (siteID, venueID, roomID, groupID, bookingDate, usageTypeID, '
                    . 'startTime, endTime, timesOverridden, statusID, notes, createdByID) '
                    . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)'
                );
                if ($bstmt === false) {
                    continue;
                }
                $bstmt->bind_param(
                    'iiiisissisi',
                    $siteId, $venueId, $roomVal, $groupId, $date, $usageTypeId, $start, $end, $statusId, $notes, $actorUserId
                );
                $ok = $bstmt->execute();
                $bstmt->close();
                if ($ok === true) {
                    $created[] = $date;
                }
            }

            $db->commit();
            self::audit(
                'tblVenueBookingGroups',
                $groupId,
                $extendGroupId > 0 ? 'update' : 'create',
                $oldGroup,
                ['params' => $params, 'dates' => $created, 'skipped' => $expansion['skipped'], 'count' => count($created)],
                $actorUserId
            );
            return ['groupID' => $groupId, 'created' => $created, 'skipped' => $expansion['skipped'], 'errors' => $expansion['errors']];
        } catch (\Throwable $e) {
            $db->rollback();
            error_log('Venues::generateSeries() failed: ' . $e->getMessage());
            return ['groupID' => 0, 'created' => [], 'skipped' => [], 'errors' => ['Could not generate series.']];
        }
    }

    /**
     * Shared expansion + validation + NULL-safe duplicate probe used by
     * both previewSeries() and generateSeries() — generateSeries() calls
     * this itself rather than trusting a client-posted date list.
     *
     * @param array<string, mixed> $params
     * @return array{dates: string[], skipped: string[], errors: string[]}
     */
    private static function expandSeries(int $siteId, int $venueId, array $params): array
    {
        $errors = [];
        $skipped = [];

        if (self::validateVenue($venueId, $siteId, true) === null) {
            $errors[] = 'Invalid venue.';
        }
        $usageTypeId = (int) ($params['usageTypeID'] ?? 0);
        if (self::validateUsageTypeForVenue($usageTypeId, $venueId, $siteId, true) === null) {
            $errors[] = 'Invalid usage type.';
        }
        $statusId = (int) ($params['statusID'] ?? 0);
        if (self::validateStatusForSite($statusId, $siteId, true) === null) {
            $errors[] = 'Invalid status.';
        }
        $roomId = (int) ($params['roomID'] ?? 0);
        if ($roomId > 0 && self::validateRoomForVenue($roomId, $venueId, $siteId) === null) {
            $errors[] = 'Invalid room.';
            $roomId = 0;
        }

        $groupType = (string) ($params['groupType'] ?? 'multi-day');
        if (in_array($groupType, self::GROUP_TYPES, true) === false) {
            $errors[] = 'Invalid group type.';
        }

        $dateFrom = (string) ($params['dateFrom'] ?? '');
        $dateTo = (string) ($params['dateTo'] ?? '');
        $fromParts = explode('-', $dateFrom);
        $toParts = explode('-', $dateTo);
        $fromValid = count($fromParts) === 3 && checkdate((int) $fromParts[1], (int) $fromParts[2], (int) $fromParts[0]);
        $toValid = count($toParts) === 3 && checkdate((int) $toParts[1], (int) $toParts[2], (int) $toParts[0]);
        if ($fromValid === false || $toValid === false) {
            $errors[] = 'Invalid date range.';
        }
        if (count($errors) > 0) {
            return ['dates' => [], 'skipped' => [], 'errors' => $errors];
        }
        if ($dateTo < $dateFrom) {
            return ['dates' => [], 'skipped' => [], 'errors' => ['End date must be on or after the start date.']];
        }

        try {
            $from = new \DateTimeImmutable($dateFrom);
            $to = new \DateTimeImmutable($dateTo);
        } catch (\Throwable $e) {
            return ['dates' => [], 'skipped' => [], 'errors' => ['Invalid date range.']];
        }
        $spanDays = (int) $from->diff($to)->days;
        if ($spanDays > 366) {
            return ['dates' => [], 'skipped' => [], 'errors' => ['The date range cannot exceed 366 days.']];
        }

        $candidates = [];
        if ($groupType === 'multi-day') {
            $cursor = $from;
            while ($cursor <= $to) {
                $candidates[] = $cursor->format('Y-m-d');
                $cursor = $cursor->modify('+1 day');
            }
        } elseif ($frequency = (string) ($params['frequency'] ?? '')) {
            if (in_array($frequency, self::FREQUENCIES, true) === false) {
                return ['dates' => [], 'skipped' => [], 'errors' => ['Invalid frequency.']];
            }
            if ($frequency === 'custom') {
                foreach ((array) ($params['dates'] ?? []) as $d) {
                    $d = (string) $d;
                    $dp = explode('-', $d);
                    if (count($dp) === 3 && checkdate((int) $dp[1], (int) $dp[2], (int) $dp[0]) === true && $d >= $dateFrom && $d <= $dateTo) {
                        $candidates[] = $d;
                    }
                }
            } elseif ($frequency === 'monthly') {
                $day = (int) $from->format('j');
                $cursor = $from->modify('first day of this month');
                while ($cursor <= $to) {
                    $y = (int) $cursor->format('Y');
                    $m = (int) $cursor->format('n');
                    if (checkdate($m, $day, $y) === true) {
                        $candidateDate = sprintf('%04d-%02d-%02d', $y, $m, $day);
                        if ($candidateDate >= $dateFrom && $candidateDate <= $dateTo) {
                            $candidates[] = $candidateDate;
                        }
                    } else {
                        $errors[] = sprintf('%04d-%02d has no day %d — skipped.', $y, $m, $day);
                    }
                    $cursor = $cursor->modify('+1 month');
                }
            } else {
                // weekly / fortnightly
                $daysOfWeek = array_map('intval', (array) ($params['daysOfWeek'] ?? []));
                if (count($daysOfWeek) === 0) {
                    return ['dates' => [], 'skipped' => [], 'errors' => ['Select at least one day of the week.']];
                }
                $intervalVal = (int) ($params['intervalVal'] ?? ($frequency === 'fortnightly' ? 2 : 1));
                if ($intervalVal < 1) {
                    $intervalVal = 1;
                }
                $mondayFrom = self::mondayOf($from);
                $cursor = $from;
                while ($cursor <= $to) {
                    $dow = (int) $cursor->format('w');
                    if (in_array($dow, $daysOfWeek, true) === true) {
                        $mondayCursor = self::mondayOf($cursor);
                        $weeksBetween = (int) intdiv($mondayCursor->diff($mondayFrom)->days, 7);
                        if ($weeksBetween % $intervalVal === 0) {
                            $candidates[] = $cursor->format('Y-m-d');
                        }
                    }
                    $cursor = $cursor->modify('+1 day');
                }
            }
        } else {
            return ['dates' => [], 'skipped' => [], 'errors' => ['Invalid series parameters.']];
        }

        if (count($candidates) > 200) {
            return ['dates' => [], 'skipped' => [], 'errors' => ['Too many dates would be generated (limit 200) — narrow the range.']];
        }

        $db = self::db();
        $dates = [];
        foreach ($candidates as $date) {
            $probe = $db->prepare(
                'SELECT 1 FROM tblVenueBookings WHERE venueID = ? AND bookingDate = ? AND isDeleted = 0 AND roomID <=> ? LIMIT 1'
            );
            if ($probe === false) {
                continue;
            }
            $roomVal = $roomId > 0 ? $roomId : null;
            $probe->bind_param('isi', $venueId, $date, $roomVal);
            $probe->execute();
            $hit = $probe->get_result()->fetch_assoc() !== null;
            $probe->close();
            if ($hit === true) {
                $skipped[] = $date;
            } else {
                $dates[] = $date;
            }
        }

        return ['dates' => $dates, 'skipped' => $skipped, 'errors' => $errors];
    }

    /** Deterministic Monday-of-week helper (PHP's "monday this week" goes FORWARD on a Sunday — this never does). */
    private static function mondayOf(\DateTimeImmutable $d): \DateTimeImmutable
    {
        return $d->modify('-' . (((int) $d->format('N')) - 1) . ' days');
    }

    /* ==========================================================================
     * 📥 Import wizard (XLSX/CSV) — native parse, no Composer.
     * ======================================================================== */

    public static function createImportBatch(int $siteId, int $venueId, string $fileName, string $fileHash, string $sourceKind, int $actorUserId): int
    {
        if (self::validateVenue($venueId, $siteId) === null) {
            return 0;
        }
        $sourceKind = in_array($sourceKind, ['xlsx', 'csv'], true) === true ? $sourceKind : 'xlsx';
        $db = self::db();
        $stmt = $db->prepare(
            'INSERT INTO tblVenueImportBatches (siteID, venueID, fileName, fileHash, sourceKind, createdByID) VALUES (?, ?, ?, ?, ?, ?)'
        );
        if ($stmt === false) {
            return 0;
        }
        $stmt->bind_param('iisssi', $siteId, $venueId, $fileName, $fileHash, $sourceKind, $actorUserId);
        $ok = $stmt->execute();
        $newId = (int) $stmt->insert_id;
        $stmt->close();
        return $ok === true && $newId > 0 ? $newId : 0;
    }

    /**
     * Native XLSX/CSV parse. XLSX: ZipArchive + SimpleXML only, hardened
     * against zip-bombs and entity expansion (security item 5). CSV:
     * fgetcsv, header-mapped, order-free.
     *
     * @return array{sheets: array<int, array{name: string, year: ?int, rows: array<int, array<string, mixed>>}>, backingData: array{statuses: string[], usageWindowsByYear: array<int, array<string, array{start: ?string, end: ?string}>>}}
     * @throws \RuntimeException on malformed input (user-safe message only)
     */
    public static function parseWorkbook(string $tmpPath, string $sourceKind): array
    {
        if ($sourceKind === 'csv') {
            return self::parseCsvWorkbook($tmpPath);
        }
        return self::parseXlsxWorkbook($tmpPath);
    }

    private static function parseCsvWorkbook(string $tmpPath): array
    {
        $handle = fopen($tmpPath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Could not read the uploaded file.');
        }

        $header = null;
        $colMap = [];
        $rows = [];
        $rowNum = 0;
        $dataRowCount = 0;
        while (($cells = fgetcsv($handle)) !== false) {
            $rowNum++;
            $isEmpty = count(array_filter($cells, static fn ($c): bool => trim((string) $c) !== '')) === 0;
            if ($isEmpty === true) {
                continue;
            }
            if ($header === null) {
                foreach ($cells as $i => $cell) {
                    $key = strtoupper(trim((string) $cell));
                    if (in_array($key, ['DATE', 'HOURS', 'TIMES', 'NOTES', 'STATUS'], true) === true) {
                        $colMap[$key] = $i;
                    }
                }
                if (isset($colMap['DATE']) === false || isset($colMap['STATUS']) === false) {
                    fclose($handle);
                    throw new \RuntimeException('The CSV must have DATE and STATUS columns.');
                }
                $header = $cells;
                continue;
            }
            $dataRowCount++;
            if ($dataRowCount > 5000) {
                break;
            }
            $get = static function (string $key) use ($cells, $colMap): string {
                $i = $colMap[$key] ?? null;
                return $i !== null && isset($cells[$i]) ? mb_substr((string) $cells[$i], 0, 500) : '';
            };
            $rows[] = [
                'rowNum' => $rowNum,
                'rawDate' => $get('DATE'),
                'rawHours' => $get('HOURS'),
                'rawTimes' => $get('TIMES'),
                'rawStatus' => $get('STATUS'),
                'rawNotes' => $get('NOTES'),
            ];
        }
        fclose($handle);

        return [
            'sheets' => [['name' => basename($tmpPath), 'year' => null, 'rows' => $rows]],
            'backingData' => ['statuses' => [], 'usageWindowsByYear' => []],
        ];
    }

    private static function parseXlsxWorkbook(string $tmpPath): array
    {
        if (class_exists('ZipArchive') === false) {
            throw new \RuntimeException('XLSX import is not available on this server — please use the CSV format instead.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($tmpPath) !== true) {
            throw new \RuntimeException('The uploaded file could not be opened as a workbook.');
        }
        if ($zip->numFiles > 200) {
            $zip->close();
            throw new \RuntimeException('The workbook has too many internal parts to be safely read.');
        }

        $readEntry = static function (string $name) use ($zip): ?string {
            $idx = $zip->locateName($name);
            if ($idx === false) {
                return null;
            }
            $stat = $zip->statIndex($idx);
            if ($stat === false || $stat['size'] > 20971520) {
                // 🛡️ 20 MB per-entry cap, checked BEFORE extraction (zip-bomb guard).
                return null;
            }
            $data = $zip->getFromIndex($idx);
            return $data === false ? null : $data;
        };

        $workbookXml = $readEntry('xl/workbook.xml');
        $relsXml = $readEntry('xl/_rels/workbook.xml.rels');
        if ($workbookXml === null || $relsXml === null) {
            $zip->close();
            throw new \RuntimeException('The workbook structure could not be read.');
        }

        $loadXml = static function (string $xml): ?\SimpleXMLElement {
            $prev = libxml_use_internal_errors(true);
            $el = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
            libxml_use_internal_errors($prev);
            return $el === false ? null : $el;
        };

        $workbook = $loadXml($workbookXml);
        $rels = $loadXml($relsXml);
        if ($workbook === null || $rels === null) {
            $zip->close();
            throw new \RuntimeException('The workbook XML could not be parsed.');
        }

        $ridToTarget = [];
        foreach ($rels->children() as $rel) {
            $id = (string) $rel->attributes()['Id'];
            $target = (string) $rel->attributes()['Target'];
            if ($id !== '') {
                $ridToTarget[$id] = ltrim(str_replace('./', '', $target), '/');
            }
        }

        $relNs = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $sheetMeta = [];
        foreach ($workbook->sheets->sheet ?? [] as $sheet) {
            $name = (string) $sheet->attributes()['name'];
            $ridAttrs = $sheet->attributes($relNs);
            $rid = isset($ridAttrs['id']) ? (string) $ridAttrs['id'] : '';
            $target = $ridToTarget[$rid] ?? null;
            if ($target === null) {
                continue;
            }
            if (str_starts_with($target, 'xl/') === false) {
                $target = 'xl/' . $target;
            }
            $year = preg_match('/^\d{4}$/', $name) === 1 ? (int) $name : null;
            $sheetMeta[] = ['name' => $name, 'target' => $target, 'year' => $year];
        }

        $sharedStrings = [];
        $sstXml = $readEntry('xl/sharedStrings.xml');
        if ($sstXml !== null) {
            $sst = $loadXml($sstXml);
            if ($sst !== null) {
                foreach ($sst->si as $si) {
                    $text = '';
                    foreach ($si->xpath('.//t') ?: [] as $t) {
                        $text .= (string) $t;
                    }
                    $sharedStrings[] = $text;
                }
            }
        }

        $sheets = [];
        $backingData = ['statuses' => [], 'usageWindowsByYear' => []];

        foreach ($sheetMeta as $meta) {
            $sheetXml = $readEntry($meta['target']);
            if ($sheetXml === null) {
                continue;
            }
            $sheetEl = $loadXml($sheetXml);
            if ($sheetEl === null) {
                continue;
            }

            $grid = [];
            foreach ($sheetEl->sheetData->row ?? [] as $rowEl) {
                $rowNum = (int) $rowEl->attributes()['r'];
                $cells = [];
                foreach ($rowEl->c ?? [] as $cellEl) {
                    $ref = (string) $cellEl->attributes()['r'];
                    preg_match('/^([A-Z]+)/', $ref, $m);
                    $col = $m[1] ?? '';
                    $type = (string) $cellEl->attributes()['t'];
                    if ($type === 's') {
                        $idx = (int) $cellEl->v;
                        $val = $sharedStrings[$idx] ?? '';
                    } elseif ($type === 'inlineStr') {
                        $val = (string) ($cellEl->is->t ?? '');
                    } else {
                        $val = (string) $cellEl->v;
                    }
                    $cells[$col] = mb_substr($val, 0, 500);
                }
                if (count($cells) > 0) {
                    $grid[$rowNum] = $cells;
                }
                if (count($grid) > 5000) {
                    break;
                }
            }

            if (strcasecmp($meta['name'], 'BackingData') === 0) {
                self::parseBackingDataGrid($grid, $backingData);
                continue;
            }

            // Find the header row: first row containing both DATE and STATUS (case-insensitive).
            $headerColMap = [];
            $headerRowNum = null;
            foreach ($grid as $rn => $cells) {
                $upper = array_map(static fn ($v): string => strtoupper(trim((string) $v)), $cells);
                if (in_array('DATE', $upper, true) === true && in_array('STATUS', $upper, true) === true) {
                    foreach ($upper as $col => $label) {
                        if (in_array($label, ['DATE', 'HOURS', 'TIMES', 'NOTES', 'STATUS'], true) === true) {
                            $headerColMap[$label] = $col;
                        }
                    }
                    $headerRowNum = $rn;
                    break;
                }
            }
            if ($headerRowNum === null) {
                // No header row found in this sheet — skip it, not fatal.
                continue;
            }

            $rows = [];
            foreach ($grid as $rn => $cells) {
                if ($rn <= $headerRowNum) {
                    continue;
                }
                $get = static function (string $key) use ($cells, $headerColMap): string {
                    $col = $headerColMap[$key] ?? null;
                    return $col !== null ? (string) ($cells[$col] ?? '') : '';
                };
                $hasAny = trim($get('DATE')) !== '' || trim($get('HOURS')) !== '' || trim($get('STATUS')) !== '';
                if ($hasAny === false) {
                    continue;
                }
                $rows[] = [
                    'rowNum' => $rn,
                    'rawDate' => $get('DATE'),
                    'rawHours' => $get('HOURS'),
                    'rawTimes' => $get('TIMES'),
                    'rawStatus' => $get('STATUS'),
                    'rawNotes' => $get('NOTES'),
                ];
            }

            $sheets[] = ['name' => $meta['name'], 'year' => $meta['year'], 'rows' => $rows];
        }

        $zip->close();
        return ['sheets' => $sheets, 'backingData' => $backingData];
    }

    /**
     * Best-effort BackingData parse — never fatal; undetected shapes just
     * yield empty suggestions.
     *
     * @param array<int, array<string, string>> $grid
     * @param array{statuses: string[], usageWindowsByYear: array<int, array<string, array{start: ?string, end: ?string}>>} $backingData
     */
    private static function parseBackingDataGrid(array $grid, array &$backingData): void
    {
        $statusCol = null;
        $yearCols = [];
        $typeCol = null;
        foreach ($grid as $rn => $cells) {
            foreach ($cells as $col => $val) {
                $upper = strtoupper(trim((string) $val));
                if ($upper === 'STATUS' && $statusCol === null) {
                    $statusCol = $col;
                }
                if (preg_match('/^\d{4}$/', trim((string) $val)) === 1) {
                    $yearCols[$col] = (int) trim((string) $val);
                }
            }
        }
        if ($statusCol === null) {
            $statusCol = 'A';
        }
        foreach ($grid as $cells) {
            $val = trim((string) ($cells[$statusCol] ?? ''));
            if ($val !== '' && in_array($val, $backingData['statuses'], true) === false) {
                $backingData['statuses'][] = $val;
            }
            if ($typeCol === null) {
                foreach ($cells as $col => $v) {
                    if (array_key_exists($col, $yearCols) === false && trim((string) $v) !== '') {
                        $typeCol = $col;
                        break;
                    }
                }
            }
        }
        if ($typeCol !== null && count($yearCols) > 0) {
            foreach ($grid as $cells) {
                $typeName = trim((string) ($cells[$typeCol] ?? ''));
                if ($typeName === '' || in_array(strtoupper($typeName), ['STATUS'], true) === true) {
                    continue;
                }
                foreach ($yearCols as $col => $year) {
                    $rangeText = (string) ($cells[$col] ?? '');
                    $parsed = self::parseTimeRange($rangeText);
                    if ($parsed !== null) {
                        $backingData['usageWindowsByYear'][$year][$typeName] = $parsed;
                    }
                }
            }
        }
    }

    /**
     * Stage parsed rows into tblVenueImportRows applying the 01 §11.1 parse
     * rules (Excel-serial epoch, sanity window, CI vocab matching, time
     * sentinels). Updates the batch's rowCount/sheetCount/vocabMap/status.
     *
     * @param array<string, mixed> $parsed the parseWorkbook() return shape
     * @return array{staged: int, unknownHours: string[], unknownStatuses: string[]}
     */
    public static function stageRows(int $batchId, int $siteId, int $venueId, array $parsed): array
    {
        $db = self::db();
        $staged = 0;
        $unknownHours = [];
        $unknownStatuses = [];
        $sheetCount = count($parsed['sheets'] ?? []);

        foreach (($parsed['sheets'] ?? []) as $sheet) {
            $sheetName = (string) ($sheet['name'] ?? '');
            $sheetYear = $sheet['year'] ?? null;
            foreach (($sheet['rows'] ?? []) as $row) {
                $rawDate = trim((string) ($row['rawDate'] ?? ''));
                $rawHours = trim((string) ($row['rawHours'] ?? ''));
                $rawTimes = trim((string) ($row['rawTimes'] ?? ''));
                $rawStatus = trim((string) ($row['rawStatus'] ?? ''));
                $rawNotes = trim((string) ($row['rawNotes'] ?? ''));

                $parsedDate = null;
                $rowState = 'pending';
                $stateNote = null;

                if ($rawDate === '' && $rawStatus === '') {
                    continue; // blank/header-echo row
                }

                if (is_numeric($rawDate) === true) {
                    $parsedDate = self::excelSerialToDate((float) $rawDate);
                } elseif ($rawDate !== '') {
                    $parsedDate = self::parseTextDate($rawDate);
                }
                if ($parsedDate === null) {
                    $rowState = 'error';
                    $stateNote = 'implausible date (1904 system?)';
                } elseif ($sheetYear !== null && (int) date('Y', strtotime($parsedDate)) !== (int) $sheetYear) {
                    $stateNote = 'sheet-year mismatch';
                }

                $mappedUsageTypeId = null;
                if ($rawHours !== '') {
                    $mappedUsageTypeId = self::findUsageTypeByName($venueId, $siteId, $rawHours);
                    if ($mappedUsageTypeId === null && in_array($rawHours, $unknownHours, true) === false) {
                        $unknownHours[] = $rawHours;
                    }
                }
                $mappedStatusId = null;
                if ($rawStatus !== '') {
                    $mappedStatusId = self::findStatusByName($siteId, $rawStatus);
                    if ($mappedStatusId === null && in_array($rawStatus, $unknownStatuses, true) === false) {
                        $unknownStatuses[] = $rawStatus;
                    }
                }

                $timeRange = self::parseTimeRange($rawTimes);
                $parsedStart = $timeRange['start'] ?? null;
                $parsedEnd = $timeRange['end'] ?? null;

                if ($rowState !== 'error') {
                    if ($parsedDate !== null && $mappedUsageTypeId !== null && $mappedStatusId !== null) {
                        $rowState = 'ready';
                        if ($parsedStart === null && $parsedEnd === null) {
                            $stateNote = $stateNote !== null ? $stateNote : 'times missing';
                        }
                    } else {
                        $rowState = 'pending';
                    }
                }

                $stmt = $db->prepare(
                    'INSERT INTO tblVenueImportRows (batchID, siteID, sheetName, sheetYear, rowNum, rawDate, parsedDate, '
                    . 'rawHours, rawTimes, rawStatus, rawNotes, parsedStartTime, parsedEndTime, mappedUsageTypeID, '
                    . 'mappedStatusID, rowState, stateNote) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                if ($stmt === false) {
                    continue;
                }
                $rowNum = (int) ($row['rowNum'] ?? 0);
                $stmt->bind_param(
                    'iisiissssssssiiss',
                    $batchId, $siteId, $sheetName, $sheetYear, $rowNum, $rawDate, $parsedDate,
                    $rawHours, $rawTimes, $rawStatus, $rawNotes, $parsedStart, $parsedEnd,
                    $mappedUsageTypeId, $mappedStatusId, $rowState, $stateNote
                );
                $ok = $stmt->execute();
                $stmt->close();
                if ($ok === true) {
                    $staged++;
                }
            }
        }

        $vocabMap = json_encode([
            'hours' => array_fill_keys($unknownHours, ['pending' => true]),
            'status' => array_fill_keys($unknownStatuses, ['pending' => true]),
            'backingData' => $parsed['backingData'] ?? [],
        ], JSON_UNESCAPED_UNICODE);

        $upd = $db->prepare('UPDATE tblVenueImportBatches SET rowCount = ?, sheetCount = ?, vocabMap = ?, status = ? WHERE batchID = ? AND siteID = ?');
        if ($upd !== false) {
            $status = 'mapping';
            $upd->bind_param('iissii', $staged, $sheetCount, $vocabMap, $status, $batchId, $siteId);
            $upd->execute();
            $upd->close();
        }

        return ['staged' => $staged, 'unknownHours' => $unknownHours, 'unknownStatuses' => $unknownStatuses];
    }

    /**
     * @param array<string, mixed> $resolutions {"hours":{"<raw>":{"usageTypeID":n}|{"create":{...}}},"status":{...}}
     * @return array{resolved: int, created: int}
     */
    public static function applyVocabMap(int $batchId, int $siteId, array $resolutions, int $actorUserId): array
    {
        $db = self::db();
        $batch = self::validateImportBatch($batchId, $siteId);
        if ($batch === null) {
            return ['resolved' => 0, 'created' => 0];
        }
        $venueId = (int) $batch['venueID'];
        $resolved = 0;
        $created = 0;

        foreach (($resolutions['hours'] ?? []) as $raw => $resolution) {
            $usageTypeId = 0;
            if (isset($resolution['usageTypeID'])) {
                $candidate = (int) $resolution['usageTypeID'];
                if (self::validateUsageTypeForVenue($candidate, $venueId, $siteId, false) !== null) {
                    $usageTypeId = $candidate;
                }
            } elseif (isset($resolution['create'])) {
                $result = self::saveUsageType($siteId, $venueId, 0, (array) $resolution['create'], $actorUserId);
                if ($result['id'] > 0) {
                    $usageTypeId = $result['id'];
                    $created++;
                }
            }
            if ($usageTypeId > 0) {
                $upd = $db->prepare('UPDATE tblVenueImportRows SET mappedUsageTypeID = ?, rowState = ? WHERE batchID = ? AND rawHours = ? AND mappedUsageTypeID IS NULL');
                if ($upd !== false) {
                    $pending = 'pending';
                    $upd->bind_param('isis', $usageTypeId, $pending, $batchId, $raw);
                    $upd->execute();
                    $upd->close();
                }
                $resolved++;
            }
        }

        foreach (($resolutions['status'] ?? []) as $raw => $resolution) {
            $statusId = 0;
            if (isset($resolution['statusID'])) {
                $candidate = (int) $resolution['statusID'];
                if (self::validateStatusForSite($candidate, $siteId, false) !== null) {
                    $statusId = $candidate;
                }
            } elseif (isset($resolution['create'])) {
                $result = self::saveStatus($siteId, 0, (array) $resolution['create'], $actorUserId);
                if ($result['id'] > 0) {
                    $statusId = $result['id'];
                    $created++;
                }
            }
            if ($statusId > 0) {
                $upd = $db->prepare('UPDATE tblVenueImportRows SET mappedStatusID = ? WHERE batchID = ? AND rawStatus = ? AND mappedStatusID IS NULL');
                if ($upd !== false) {
                    $upd->bind_param('iis', $statusId, $batchId, $raw);
                    $upd->execute();
                    $upd->close();
                }
                $resolved++;
            }
        }

        // Re-evaluate pending -> ready where both vocab ids are now mapped and the date parsed.
        $readyState = 'ready';
        $pendingState = 'pending';
        $reeval = $db->prepare(
            'UPDATE tblVenueImportRows SET rowState = ? WHERE batchID = ? AND rowState = ? '
            . 'AND mappedUsageTypeID IS NOT NULL AND mappedStatusID IS NOT NULL AND parsedDate IS NOT NULL'
        );
        if ($reeval !== false) {
            $reeval->bind_param('sis', $readyState, $batchId, $pendingState);
            $reeval->execute();
            $reeval->close();
        }

        return ['resolved' => $resolved, 'created' => $created];
    }

    /**
     * @return array{imported: int, skipped: int, errors: int}
     */
    public static function commitBatch(int $batchId, int $siteId, int $actorUserId): array
    {
        $db = self::db();
        $batch = self::validateImportBatch($batchId, $siteId);
        if ($batch === null || (string) $batch['status'] !== 'mapping') {
            return ['imported' => 0, 'skipped' => 0, 'errors' => 0];
        }

        $pendingChk = $db->prepare("SELECT COUNT(*) AS c FROM tblVenueImportRows WHERE batchID = ? AND rowState = 'pending'");
        if ($pendingChk !== false) {
            $pendingChk->bind_param('i', $batchId);
            $pendingChk->execute();
            $pendingCount = (int) ($pendingChk->get_result()->fetch_assoc()['c'] ?? 0);
            $pendingChk->close();
            if ($pendingCount > 0) {
                return ['imported' => 0, 'skipped' => 0, 'errors' => 0];
            }
        }

        $venueId = (int) $batch['venueID'];
        $rowsStmt = $db->prepare("SELECT * FROM tblVenueImportRows WHERE batchID = ? AND rowState = 'ready'");
        if ($rowsStmt === false) {
            return ['imported' => 0, 'skipped' => 0, 'errors' => 0];
        }
        $rowsStmt->bind_param('i', $batchId);
        $rowsStmt->execute();
        $readyRows = self::fetchAll($rowsStmt);
        $rowsStmt->close();

        $imported = 0;
        $db->begin_transaction();
        try {
            foreach ($readyRows as $row) {
                $usageTypeId = (int) $row['mappedUsageTypeID'];
                $statusId = (int) $row['mappedStatusID'];
                $bookingDate = (string) $row['parsedDate'];
                $usageType = self::getUsageTypeRaw($usageTypeId, $siteId);
                $isHire = $usageType !== null && (string) $usageType['usageKind'] === 'hire';

                $start = null;
                $end = null;
                $overridden = 0;
                if ($isHire === true) {
                    if ($row['parsedStartTime'] !== null && $row['parsedEndTime'] !== null) {
                        $start = $row['parsedStartTime'];
                        $end = $row['parsedEndTime'];
                        $window = self::resolveWindow($usageTypeId, $bookingDate, $siteId);
                        $matches = $window !== null && ($window['start'] ?? null) === $start && ($window['end'] ?? null) === $end;
                        $overridden = $matches === true ? 0 : 1;
                    } else {
                        $window = self::resolveWindow($usageTypeId, $bookingDate, $siteId);
                        $start = $window['start'] ?? null;
                        $end = $window['end'] ?? null;
                    }
                }

                $notes = self::nullableTrim($row['rawNotes'] ?? null, 65000);
                $ins = $db->prepare(
                    'INSERT INTO tblVenueBookings (siteID, venueID, bookingDate, usageTypeID, startTime, endTime, '
                    . 'timesOverridden, statusID, notes, createdByID) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                if ($ins === false) {
                    continue;
                }
                $ins->bind_param('iisississi', $siteId, $venueId, $bookingDate, $usageTypeId, $start, $end, $overridden, $statusId, $notes, $actorUserId);
                $ok = $ins->execute();
                $newBookingId = (int) $ins->insert_id;
                $ins->close();
                if ($ok === true && $newBookingId > 0) {
                    $imported++;
                    $mark = $db->prepare("UPDATE tblVenueImportRows SET rowState = 'imported', importedBookingID = ? WHERE rowID = ?");
                    if ($mark !== false) {
                        $rid = (int) $row['rowID'];
                        $mark->bind_param('ii', $newBookingId, $rid);
                        $mark->execute();
                        $mark->close();
                    }
                }
            }

            $countsStmt = $db->prepare(
                "SELECT SUM(rowState = 'skipped') AS skipped, SUM(rowState = 'error') AS errored FROM tblVenueImportRows WHERE batchID = ?"
            );
            $skippedCount = 0;
            $erroredCount = 0;
            if ($countsStmt !== false) {
                $countsStmt->bind_param('i', $batchId);
                $countsStmt->execute();
                $counts = $countsStmt->get_result()->fetch_assoc();
                $countsStmt->close();
                $skippedCount = (int) ($counts['skipped'] ?? 0);
                $erroredCount = (int) ($counts['errored'] ?? 0);
            }

            $status = 'committed';
            $upd = $db->prepare('UPDATE tblVenueImportBatches SET status = ?, committedAt = NOW(), importedCount = ?, skippedCount = ? WHERE batchID = ? AND siteID = ?');
            if ($upd !== false) {
                $upd->bind_param('siiii', $status, $imported, $skippedCount, $batchId, $siteId);
                $upd->execute();
                $upd->close();
            }

            $db->commit();
            self::audit('tblVenueImportBatches', $batchId, 'update', null, [
                'imported' => $imported, 'skipped' => $skippedCount, 'errors' => $erroredCount, 'fileName' => $batch['fileName'],
            ], $actorUserId);
            return ['imported' => $imported, 'skipped' => $skippedCount, 'errors' => $erroredCount];
        } catch (\Throwable $e) {
            $db->rollback();
            error_log('Venues::commitBatch() failed: ' . $e->getMessage());
            return ['imported' => 0, 'skipped' => 0, 'errors' => 0];
        }
    }

    public static function abandonBatch(int $batchId, int $siteId, int $actorUserId): bool
    {
        $db = self::db();
        $batch = self::validateImportBatch($batchId, $siteId);
        if ($batch === null) {
            return false;
        }
        $status = 'abandoned';
        $stmt = $db->prepare('UPDATE tblVenueImportBatches SET status = ? WHERE batchID = ? AND siteID = ?');
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('sii', $status, $batchId, $siteId);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok === true;
    }

    /**
     * @param array<int, int> $accepted the year keys from vocabMap.backingData.usageWindowsByYear to actually create
     * @return int rows created
     */
    public static function importBackingDataWindows(int $batchId, int $siteId, int $venueId, array $accepted, int $actorUserId): int
    {
        $batch = self::validateImportBatch($batchId, $siteId);
        if ($batch === null) {
            return 0;
        }
        $vocabMap = json_decode((string) ($batch['vocabMap'] ?? '{}'), true) ?: [];
        $windowsByYear = $vocabMap['backingData']['usageWindowsByYear'] ?? [];
        $created = 0;
        foreach ($accepted as $year) {
            $typeWindows = $windowsByYear[(string) $year] ?? $windowsByYear[$year] ?? [];
            foreach ($typeWindows as $typeName => $window) {
                $usageTypeId = self::findUsageTypeByName($venueId, $siteId, (string) $typeName);
                if ($usageTypeId === null) {
                    continue;
                }
                $result = self::saveWindow($siteId, $usageTypeId, [
                    'effectiveFrom' => sprintf('%04d-01-01', (int) $year),
                    'defaultStartTime' => $window['start'] ?? '',
                    'defaultEndTime' => $window['end'] ?? '',
                    'note' => (int) $year . ' schedule (imported)',
                ], $actorUserId);
                if ($result['id'] > 0) {
                    $created++;
                }
            }
        }
        return $created;
    }

    /* ==========================================================================
     * 📜 Agreements
     * ======================================================================== */

    /**
     * @param array<string, mixed> $filters venueID, status, renewalWithinDays
     */
    public static function listAgreements(int $siteId, array $filters): array
    {
        $db = self::db();
        $where = ['a.siteID = ?'];
        $types = 'i';
        $params = [$siteId];
        if (!empty($filters['venueID'])) {
            $where[] = 'a.venueID = ?';
            $types .= 'i';
            $params[] = (int) $filters['venueID'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'a.status = ?';
            $types .= 's';
            $params[] = (string) $filters['status'];
        }
        if (!empty($filters['renewalWithinDays'])) {
            $where[] = 'a.renewalDate IS NOT NULL AND a.renewalDate <= DATE_ADD(CURDATE(), INTERVAL ? DAY)';
            $types .= 'i';
            $params[] = (int) $filters['renewalWithinDays'];
        }
        $sql = 'SELECT a.*, v.venueName FROM tblVenueAgreements a JOIN tblVenues v ON v.venueID = a.venueID '
            . 'WHERE ' . implode(' AND ', $where) . ' ORDER BY a.status ASC, a.renewalDate ASC';
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = self::fetchAll($stmt);
        $stmt->close();
        return $rows;
    }

    public static function getAgreement(int $agreementId, int $siteId): ?array
    {
        $db = self::db();
        $stmt = $db->prepare(
            'SELECT a.*, v.venueName FROM tblVenueAgreements a JOIN tblVenues v ON v.venueID = a.venueID '
            . 'WHERE a.agreementID = ? AND a.siteID = ? LIMIT 1'
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('ii', $agreementId, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row === null) {
            return null;
        }

        $filesStmt = $db->prepare('SELECT * FROM tblVenueAgreementFiles WHERE agreementID = ? AND siteID = ? ORDER BY createdAt DESC');
        if ($filesStmt !== false) {
            $filesStmt->bind_param('ii', $agreementId, $siteId);
            $filesStmt->execute();
            $row['files'] = self::fetchAll($filesStmt);
            $filesStmt->close();
        } else {
            $row['files'] = [];
        }
        return $row;
    }

    /**
     * @return array{id: int, errors: string[]}
     */
    public static function saveAgreement(int $siteId, int $agreementId, array $data, int $actorUserId): array
    {
        $db = self::db();
        $errors = [];
        $venueId = (int) ($data['venueID'] ?? 0);
        if (self::validateVenue($venueId, $siteId) === null) {
            $errors[] = 'Invalid venue.';
        }
        $type = (string) ($data['agreementType'] ?? 'standing');
        if (in_array($type, self::AGREEMENT_TYPES, true) === false) {
            $type = 'standing';
        }
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            $errors[] = 'Title is required.';
        }
        $title = mb_substr($title, 0, 255);
        $reference = self::nullableTrim($data['reference'] ?? null, 100);
        $termStart = self::nullableDate($data['termStart'] ?? null);
        $termEnd = self::nullableDate($data['termEnd'] ?? null);
        if ($termStart !== null && $termEnd !== null && $termEnd < $termStart) {
            $errors[] = 'Term end must be on or after term start.';
        }
        $renewalDate = self::nullableDate($data['renewalDate'] ?? null);
        $noticePeriodRaw = $data['noticePeriodDays'] ?? null;
        $noticePeriodDays = ($noticePeriodRaw !== null && $noticePeriodRaw !== '') ? max(0, (int) $noticePeriodRaw) : null;

        $rateAmountRaw = $data['rateAmountPence'] ?? null;
        $rateAmountPence = null;
        if ($rateAmountRaw !== null && $rateAmountRaw !== '') {
            $rateAmountPence = (int) $rateAmountRaw;
            if ($rateAmountPence < 0) {
                $errors[] = 'Rate cannot be negative.';
                $rateAmountPence = null;
            }
        }
        $rateUnit = (string) ($data['rateUnit'] ?? '');
        $rateUnitVal = in_array($rateUnit, self::RATE_UNITS, true) === true ? $rateUnit : null;
        $currency = strtoupper(trim((string) ($data['currency'] ?? 'GBP')));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            $currency = 'GBP';
        }
        $status = (string) ($data['status'] ?? 'draft');
        if (in_array($status, self::AGREEMENT_STATUSES, true) === false) {
            $status = 'draft';
        }
        $notes = self::nullableTrim($data['notes'] ?? null, 65000);

        if (count($errors) > 0) {
            return ['id' => $agreementId, 'errors' => $errors];
        }

        $newData = [
            'venueID' => $venueId, 'agreementType' => $type, 'title' => $title, 'status' => $status,
            'rateAmountPence' => $rateAmountPence, 'rateUnit' => $rateUnitVal,
        ];

        try {
            if ($agreementId > 0) {
                $old = self::getAgreement($agreementId, $siteId);
                if ($old === null) {
                    return ['id' => 0, 'errors' => ['Agreement not found.']];
                }
                $stmt = $db->prepare(
                    'UPDATE tblVenueAgreements SET agreementType = ?, title = ?, reference = ?, termStart = ?, termEnd = ?, '
                    . 'renewalDate = ?, noticePeriodDays = ?, rateAmountPence = ?, rateUnit = ?, currency = ?, status = ?, notes = ? '
                    . 'WHERE agreementID = ? AND siteID = ?'
                );
                if ($stmt === false) {
                    return ['id' => 0, 'errors' => ['Could not save agreement.']];
                }
                $stmt->bind_param(
                    'ssssssiissssii',
                    $type, $title, $reference, $termStart, $termEnd, $renewalDate, $noticePeriodDays,
                    $rateAmountPence, $rateUnitVal, $currency, $status, $notes, $agreementId, $siteId
                );
                $ok = $stmt->execute();
                $stmt->close();
                if ($ok === false) {
                    return ['id' => 0, 'errors' => ['Could not save agreement.']];
                }
                self::audit('tblVenueAgreements', $agreementId, 'update', $old, $newData, $actorUserId);
                return ['id' => $agreementId, 'errors' => []];
            }

            $stmt = $db->prepare(
                'INSERT INTO tblVenueAgreements (siteID, venueID, agreementType, title, reference, termStart, termEnd, '
                . 'renewalDate, noticePeriodDays, rateAmountPence, rateUnit, currency, status, notes, createdByID) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if ($stmt === false) {
                return ['id' => 0, 'errors' => ['Could not save agreement.']];
            }
            // SEC-01 fix: 15 placeholders/15 vars need 15 type chars (was 14: 'iisssssiissssi').
            $stmt->bind_param(
                'iissssssiissssi',
                $siteId, $venueId, $type, $title, $reference, $termStart, $termEnd, $renewalDate,
                $noticePeriodDays, $rateAmountPence, $rateUnitVal, $currency, $status, $notes, $actorUserId
            );
            $ok = $stmt->execute();
            $newId = (int) $stmt->insert_id;
            $stmt->close();
            if ($ok === false || $newId <= 0) {
                return ['id' => 0, 'errors' => ['Could not save agreement.']];
            }
            self::audit('tblVenueAgreements', $newId, 'create', null, $newData, $actorUserId);
            return ['id' => $newId, 'errors' => []];
        } catch (\mysqli_sql_exception $e) {
            error_log('Venues::saveAgreement() failed: ' . $e->getMessage());
            return ['id' => $agreementId, 'errors' => ['Could not save agreement.']];
        }
    }

    /**
     * Creates the renewal agreement, sets the old one status='superseded'
     * and supersededByID = the new id. Two audit rows: create (new) +
     * update (old).
     *
     * @return array{newId: int, errors: string[]}
     */
    public static function supersedeAgreement(int $oldAgreementId, int $siteId, array $newData, int $actorUserId): array
    {
        $old = self::getAgreement($oldAgreementId, $siteId);
        if ($old === null) {
            return ['newId' => 0, 'errors' => ['Agreement not found.']];
        }
        $newData['venueID'] = $newData['venueID'] ?? $old['venueID'];
        $result = self::saveAgreement($siteId, 0, $newData, $actorUserId);
        if ($result['id'] <= 0) {
            return ['newId' => 0, 'errors' => $result['errors']];
        }
        $newId = $result['id'];

        $db = self::db();
        $status = 'superseded';
        $upd = $db->prepare('UPDATE tblVenueAgreements SET status = ?, supersededByID = ? WHERE agreementID = ? AND siteID = ?');
        if ($upd !== false) {
            $upd->bind_param('siii', $status, $newId, $oldAgreementId, $siteId);
            $upd->execute();
            $upd->close();
            self::audit('tblVenueAgreements', $oldAgreementId, 'update', $old, ['status' => $status, 'supersededByID' => $newId], $actorUserId);
        }
        return ['newId' => $newId, 'errors' => []];
    }

    /**
     * @param array{tmp_name: string, name: string, size: int, mime: string} $file caller has ALREADY finfo-sniffed the mime + validated it against AGREEMENT_FILE_MIME_EXT
     * @return array{fileID: int, error: ?string}
     */
    public static function attachAgreementFile(int $siteId, int $agreementId, array $file, string $title, int $actorUserId): array
    {
        $db = self::db();
        $chk = $db->prepare('SELECT 1 FROM tblVenueAgreements WHERE agreementID = ? AND siteID = ? LIMIT 1');
        if ($chk === false) {
            return ['fileID' => 0, 'error' => 'Agreement not found.'];
        }
        $chk->bind_param('ii', $agreementId, $siteId);
        $chk->execute();
        $exists = $chk->get_result()->fetch_assoc() !== null;
        $chk->close();
        if ($exists === false) {
            return ['fileID' => 0, 'error' => 'Agreement not found.'];
        }

        $mime = (string) ($file['mime'] ?? '');
        $ext = self::AGREEMENT_FILE_MIME_EXT[$mime] ?? null;
        if ($ext === null) {
            return ['fileID' => 0, 'error' => 'Unsupported file type.'];
        }

        $dir = rtrim(PORTAL_ROOT, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '_uploads' . DIRECTORY_SEPARATOR
            . 'venues' . DIRECTORY_SEPARATOR . 'agreements' . DIRECTORY_SEPARATOR;
        if (is_dir($dir) === false) {
            mkdir($dir, 0755, true);
        }
        $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
        $destPath = $dir . $storedName;
        if (move_uploaded_file((string) $file['tmp_name'], $destPath) === false
            && copy((string) $file['tmp_name'], $destPath) === false
        ) {
            return ['fileID' => 0, 'error' => 'Could not store the uploaded file.'];
        }

        $title = trim($title) !== '' ? mb_substr(trim($title), 0, 255) : mb_substr((string) ($file['name'] ?? 'Document'), 0, 255);
        $origName = mb_substr((string) ($file['name'] ?? $storedName), 0, 255);
        $size = (int) ($file['size'] ?? 0);

        $stmt = $db->prepare(
            'INSERT INTO tblVenueAgreementFiles (siteID, agreementID, title, fileName, filePath, fileSize, mimeType, uploadedByID) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if ($stmt === false) {
            return ['fileID' => 0, 'error' => 'Could not save file record.'];
        }
        $stmt->bind_param('iisssisi', $siteId, $agreementId, $title, $origName, $storedName, $size, $mime, $actorUserId);
        $ok = $stmt->execute();
        $newId = (int) $stmt->insert_id;
        $stmt->close();
        if ($ok === false || $newId <= 0) {
            return ['fileID' => 0, 'error' => 'Could not save file record.'];
        }
        self::audit('tblVenueAgreementFiles', $newId, 'create', null, ['title' => $title, 'fileName' => $origName], $actorUserId);
        return ['fileID' => $newId, 'error' => null];
    }

    public static function deleteAgreementFile(int $fileId, int $siteId, int $actorUserId): bool
    {
        $db = self::db();
        $stmt = $db->prepare('SELECT * FROM tblVenueAgreementFiles WHERE fileID = ? AND siteID = ? LIMIT 1');
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('ii', $fileId, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row === null) {
            return false;
        }

        $del = $db->prepare('DELETE FROM tblVenueAgreementFiles WHERE fileID = ? AND siteID = ?');
        if ($del === false) {
            return false;
        }
        $del->bind_param('ii', $fileId, $siteId);
        $ok = $del->execute();
        $del->close();
        if ($ok === true) {
            $path = rtrim(PORTAL_ROOT, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '_uploads' . DIRECTORY_SEPARATOR
                . 'venues' . DIRECTORY_SEPARATOR . 'agreements' . DIRECTORY_SEPARATOR . basename((string) $row['filePath']);
            if (is_file($path) === true) {
                unlink($path);
            }
            self::audit('tblVenueAgreementFiles', $fileId, 'delete', $row, null, $actorUserId);
        }
        return $ok === true;
    }

    /* ==========================================================================
     * 🧾 Invoices & payments — pure outgoing ledger, NO tblPayment linkage
     * anywhere (01b-review-resolutions.md Q3).
     * ======================================================================== */

    public static function listInvoices(int $siteId, array $filters): array
    {
        $db = self::db();
        $where = ['i.siteID = ?'];
        $types = 'i';
        $params = [$siteId];
        if (!empty($filters['venueID'])) {
            $where[] = 'i.venueID = ?';
            $types .= 'i';
            $params[] = (int) $filters['venueID'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'i.status = ?';
            $types .= 's';
            $params[] = (string) $filters['status'];
        }
        $sql = 'SELECT i.*, v.venueName, '
            . '(SELECT COALESCE(SUM(p.amountPence), 0) FROM tblVenueInvoicePayments p WHERE p.invoiceID = i.invoiceID) AS paidPence '
            . 'FROM tblVenueInvoices i JOIN tblVenues v ON v.venueID = i.venueID '
            . 'WHERE ' . implode(' AND ', $where) . ' ORDER BY i.dueDate ASC, i.issueDate ASC';
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = self::fetchAll($stmt);
        $stmt->close();
        return $rows;
    }

    public static function getInvoice(int $invoiceId, int $siteId): ?array
    {
        $db = self::db();
        $stmt = $db->prepare(
            'SELECT i.*, v.venueName FROM tblVenueInvoices i JOIN tblVenues v ON v.venueID = i.venueID '
            . 'WHERE i.invoiceID = ? AND i.siteID = ? LIMIT 1'
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('ii', $invoiceId, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row === null) {
            return null;
        }

        $linesStmt = $db->prepare(
            'SELECT l.*, b.bookingDate, b.notes AS bookingNotes FROM tblVenueInvoiceLines l '
            . 'JOIN tblVenueBookings b ON b.bookingID = l.bookingID WHERE l.invoiceID = ? AND l.siteID = ? ORDER BY b.bookingDate ASC'
        );
        if ($linesStmt !== false) {
            $linesStmt->bind_param('ii', $invoiceId, $siteId);
            $linesStmt->execute();
            $row['lines'] = self::fetchAll($linesStmt);
            $linesStmt->close();
        } else {
            $row['lines'] = [];
        }

        $paymentsStmt = $db->prepare('SELECT * FROM tblVenueInvoicePayments WHERE invoiceID = ? AND siteID = ? ORDER BY paidDate DESC');
        if ($paymentsStmt !== false) {
            $paymentsStmt->bind_param('ii', $invoiceId, $siteId);
            $paymentsStmt->execute();
            $row['payments'] = self::fetchAll($paymentsStmt);
            $paymentsStmt->close();
        } else {
            $row['payments'] = [];
        }

        return $row;
    }

    /**
     * @param array{tmp_name?: string, name?: string, size?: int, mime?: string} $data may include an optional single-file attachment already finfo-sniffed by the caller
     * @return array{id: int, errors: string[]}
     */
    public static function saveInvoice(int $siteId, int $invoiceId, array $data, int $actorUserId): array
    {
        $db = self::db();
        $errors = [];
        $venueId = (int) ($data['venueID'] ?? 0);
        if (self::validateVenue($venueId, $siteId) === null) {
            $errors[] = 'Invalid venue.';
        }
        $agreementId = null;
        $agreementRaw = (int) ($data['agreementID'] ?? 0);
        if ($agreementRaw > 0) {
            if (self::validateAgreementForVenue($agreementRaw, $venueId, $siteId) === null) {
                $errors[] = 'Invalid agreement.';
            } else {
                $agreementId = $agreementRaw;
            }
        }
        $invoiceRef = self::nullableTrim($data['invoiceRef'] ?? null, 100);
        $description = self::nullableTrim($data['description'] ?? null, 500);
        $periodStart = self::nullableDate($data['periodStart'] ?? null);
        $periodEnd = self::nullableDate($data['periodEnd'] ?? null);
        $issueDate = self::nullableDate($data['issueDate'] ?? null);
        if ($issueDate === null) {
            $errors[] = 'Issue date is required.';
        }
        $dueDate = self::nullableDate($data['dueDate'] ?? null);
        $amountPence = (int) ($data['amountPence'] ?? -1);
        if ($amountPence <= 0) {
            $errors[] = 'Amount must be a positive number of pence.';
        }
        $currency = strtoupper(trim((string) ($data['currency'] ?? 'GBP')));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            $currency = 'GBP';
        }
        $notes = self::nullableTrim($data['notes'] ?? null, 65000);

        if (count($errors) > 0) {
            return ['id' => $invoiceId, 'errors' => $errors];
        }

        $fileName = null;
        $filePath = null;
        $fileSize = null;
        $mimeType = null;
        if (!empty($data['tmp_name']) && !empty($data['mime'])) {
            $mime = (string) $data['mime'];
            $allowed = ['application/pdf' => 'pdf', 'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
            $ext = $allowed[$mime] ?? null;
            if ($ext !== null) {
                $dir = rtrim(PORTAL_ROOT, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '_uploads' . DIRECTORY_SEPARATOR
                    . 'venues' . DIRECTORY_SEPARATOR . 'invoices' . DIRECTORY_SEPARATOR;
                if (is_dir($dir) === false) {
                    mkdir($dir, 0755, true);
                }
                $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
                if (move_uploaded_file((string) $data['tmp_name'], $dir . $storedName) === true
                    || copy((string) $data['tmp_name'], $dir . $storedName) === true
                ) {
                    $fileName = mb_substr((string) ($data['name'] ?? $storedName), 0, 255);
                    $filePath = $storedName;
                    $fileSize = (int) ($data['size'] ?? 0);
                    $mimeType = $mime;
                }
            }
        }

        $newData = [
            'venueID' => $venueId, 'agreementID' => $agreementId, 'invoiceRef' => $invoiceRef,
            'issueDate' => $issueDate, 'dueDate' => $dueDate, 'amountPence' => $amountPence, 'currency' => $currency,
        ];

        try {
            if ($invoiceId > 0) {
                $old = self::getInvoice($invoiceId, $siteId);
                if ($old === null) {
                    return ['id' => 0, 'errors' => ['Invoice not found.']];
                }
                $oldFilePath = $old['filePath'] ?? null;
                if ($filePath !== null && $oldFilePath !== null) {
                    $stmt = $db->prepare(
                        'UPDATE tblVenueInvoices SET agreementID = ?, invoiceRef = ?, description = ?, periodStart = ?, '
                        . 'periodEnd = ?, issueDate = ?, dueDate = ?, amountPence = ?, currency = ?, notes = ?, '
                        . 'fileName = ?, filePath = ?, fileSize = ?, mimeType = ? WHERE invoiceID = ? AND siteID = ?'
                    );
                } else {
                    $stmt = $db->prepare(
                        'UPDATE tblVenueInvoices SET agreementID = ?, invoiceRef = ?, description = ?, periodStart = ?, '
                        . 'periodEnd = ?, issueDate = ?, dueDate = ?, amountPence = ?, currency = ?, notes = ? '
                        . 'WHERE invoiceID = ? AND siteID = ?'
                    );
                }
                if ($stmt === false) {
                    return ['id' => 0, 'errors' => ['Could not save invoice.']];
                }
                if ($filePath !== null) {
                    // SEC-01 fix: 16 placeholders (14 SET + 2 WHERE)/16 vars need 16 type chars (was 15: 'issssssisssssii').
                    $stmt->bind_param(
                        'issssssissssisii',
                        $agreementId, $invoiceRef, $description, $periodStart, $periodEnd, $issueDate, $dueDate,
                        $amountPence, $currency, $notes, $fileName, $filePath, $fileSize, $mimeType, $invoiceId, $siteId
                    );
                } else {
                    $stmt->bind_param(
                        'issssssissii',
                        $agreementId, $invoiceRef, $description, $periodStart, $periodEnd, $issueDate, $dueDate,
                        $amountPence, $currency, $notes, $invoiceId, $siteId
                    );
                }
                $ok = $stmt->execute();
                $stmt->close();
                if ($ok === false) {
                    return ['id' => 0, 'errors' => ['Could not save invoice.']];
                }
                if ($filePath !== null && $oldFilePath !== null) {
                    $oldFull = rtrim(PORTAL_ROOT, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '_uploads' . DIRECTORY_SEPARATOR
                        . 'venues' . DIRECTORY_SEPARATOR . 'invoices' . DIRECTORY_SEPARATOR . basename((string) $oldFilePath);
                    if (is_file($oldFull) === true) {
                        unlink($oldFull);
                    }
                }
                self::audit('tblVenueInvoices', $invoiceId, 'update', $old, $newData, $actorUserId);
                return ['id' => $invoiceId, 'errors' => []];
            }

            $stmt = $db->prepare(
                'INSERT INTO tblVenueInvoices (siteID, venueID, agreementID, invoiceRef, description, periodStart, '
                . 'periodEnd, issueDate, dueDate, amountPence, currency, notes, fileName, filePath, fileSize, mimeType, createdByID) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if ($stmt === false) {
                return ['id' => 0, 'errors' => ['Could not save invoice.']];
            }
            // SEC-01 fix: 17 placeholders/17 vars need 17 type chars (was 16: 'iiisssssissssisi').
            $stmt->bind_param(
                'iiissssssissssisi',
                $siteId, $venueId, $agreementId, $invoiceRef, $description, $periodStart, $periodEnd, $issueDate,
                $dueDate, $amountPence, $currency, $notes, $fileName, $filePath, $fileSize, $mimeType, $actorUserId
            );
            $ok = $stmt->execute();
            $newId = (int) $stmt->insert_id;
            $stmt->close();
            if ($ok === false || $newId <= 0) {
                return ['id' => 0, 'errors' => ['Could not save invoice.']];
            }
            self::audit('tblVenueInvoices', $newId, 'create', null, $newData, $actorUserId);
            return ['id' => $newId, 'errors' => []];
        } catch (\mysqli_sql_exception $e) {
            error_log('Venues::saveInvoice() failed: ' . $e->getMessage());
            return ['id' => $invoiceId, 'errors' => ['Could not save invoice.']];
        }
    }

    /** Manual status set — disputed/cancelled ONLY (INVOICE_MANUAL_STATUSES). The paid family is machine-managed. */
    public static function setInvoiceStatus(int $invoiceId, int $siteId, string $status, int $actorUserId): bool
    {
        if (in_array($status, self::INVOICE_MANUAL_STATUSES, true) === false) {
            return false;
        }
        $db = self::db();
        $old = self::getInvoice($invoiceId, $siteId);
        if ($old === null) {
            return false;
        }
        $stmt = $db->prepare('UPDATE tblVenueInvoices SET status = ? WHERE invoiceID = ? AND siteID = ?');
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('sii', $status, $invoiceId, $siteId);
        $ok = $stmt->execute();
        $stmt->close();
        if ($ok === true) {
            self::audit('tblVenueInvoices', $invoiceId, 'update', $old, ['status' => $status], $actorUserId);
        }
        return $ok === true;
    }

    /**
     * Admin-only caller. Refuses while payments exist.
     *
     * @return array{ok: bool, error: ?string}
     */
    public static function deleteInvoice(int $invoiceId, int $siteId, int $actorUserId): array
    {
        $db = self::db();
        $invoice = self::getInvoice($invoiceId, $siteId);
        if ($invoice === null) {
            return ['ok' => false, 'error' => 'Invoice not found.'];
        }
        if (count($invoice['payments']) > 0) {
            return ['ok' => false, 'error' => 'This invoice has payments recorded against it — it cannot be deleted.'];
        }
        $del = $db->prepare('DELETE FROM tblVenueInvoices WHERE invoiceID = ? AND siteID = ?');
        if ($del === false) {
            return ['ok' => false, 'error' => 'Could not delete invoice.'];
        }
        $del->bind_param('ii', $invoiceId, $siteId);
        $ok = $del->execute();
        $del->close();
        if ($ok === false) {
            return ['ok' => false, 'error' => 'Could not delete invoice.'];
        }
        self::audit('tblVenueInvoices', $invoiceId, 'delete', $invoice, null, $actorUserId);
        return ['ok' => true, 'error' => null];
    }

    /**
     * @return array{lineID: int, error: ?string}
     */
    public static function allocateInvoiceLine(int $siteId, int $invoiceId, int $bookingId, ?int $amountPence, int $actorUserId): array
    {
        $db = self::db();
        $invoice = self::getInvoice($invoiceId, $siteId);
        if ($invoice === null) {
            return ['lineID' => 0, 'error' => 'Invoice not found.'];
        }
        $bstmt = $db->prepare('SELECT venueID FROM tblVenueBookings WHERE bookingID = ? AND siteID = ? LIMIT 1');
        if ($bstmt === false) {
            return ['lineID' => 0, 'error' => 'Booking not found.'];
        }
        $bstmt->bind_param('ii', $bookingId, $siteId);
        $bstmt->execute();
        $booking = $bstmt->get_result()->fetch_assoc();
        $bstmt->close();
        if ($booking === null || (int) $booking['venueID'] !== (int) $invoice['venueID']) {
            return ['lineID' => 0, 'error' => 'That booking does not belong to this invoice\'s venue.'];
        }
        if ($amountPence !== null && $amountPence < 0) {
            return ['lineID' => 0, 'error' => 'Amount cannot be negative.'];
        }

        try {
            $stmt = $db->prepare('INSERT INTO tblVenueInvoiceLines (siteID, invoiceID, bookingID, amountPence) VALUES (?, ?, ?, ?)');
            if ($stmt === false) {
                return ['lineID' => 0, 'error' => 'Could not allocate line.'];
            }
            $stmt->bind_param('iiii', $siteId, $invoiceId, $bookingId, $amountPence);
            $ok = $stmt->execute();
            $newId = (int) $stmt->insert_id;
            $stmt->close();
            if ($ok === false || $newId <= 0) {
                return ['lineID' => 0, 'error' => 'Could not allocate line.'];
            }
            self::audit('tblVenueInvoiceLines', $newId, 'create', null, ['invoiceID' => $invoiceId, 'bookingID' => $bookingId], $actorUserId);
            return ['lineID' => $newId, 'error' => null];
        } catch (\mysqli_sql_exception $e) {
            return ['lineID' => 0, 'error' => 'That booking is already allocated to this invoice.'];
        }
    }

    public static function removeInvoiceLine(int $lineId, int $siteId, int $actorUserId): bool
    {
        $db = self::db();
        $stmt = $db->prepare('DELETE FROM tblVenueInvoiceLines WHERE lineID = ? AND siteID = ?');
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('ii', $lineId, $siteId);
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($ok === true && $affected > 0) {
            self::audit('tblVenueInvoiceLines', $lineId, 'delete', null, null, $actorUserId);
        }
        return $ok === true && $affected > 0;
    }

    /**
     * @return array{payID: int, errors: string[]}
     */
    public static function recordPayment(int $siteId, int $invoiceId, array $data, int $actorUserId): array
    {
        $db = self::db();
        $invoice = self::getInvoice($invoiceId, $siteId);
        if ($invoice === null) {
            return ['payID' => 0, 'errors' => ['Invoice not found.']];
        }
        $errors = [];
        $amountPence = (int) ($data['amountPence'] ?? -1);
        if ($amountPence <= 0) {
            $errors[] = 'Amount must be a positive number of pence.';
        }
        $paidDate = self::nullableDate($data['paidDate'] ?? null);
        if ($paidDate === null) {
            $errors[] = 'Paid date is required.';
        }
        $method = (string) ($data['method'] ?? 'bank-transfer');
        if (in_array($method, self::PAYMENT_METHODS, true) === false) {
            $method = 'bank-transfer';
        }
        $reference = self::nullableTrim($data['reference'] ?? null, 100);
        $notes = self::nullableTrim($data['notes'] ?? null, 500);

        // 🛡️ SEC-03: reject a payment that would push total-paid past the
        //     invoice's amountPence. Mirrors the already-paid SUM query in
        //     recomputeInvoiceStatus() below. Partial payments that sum to
        //     <= the invoice total are still allowed.
        if ($amountPence > 0) {
            $sumStmt = $db->prepare('SELECT COALESCE(SUM(amountPence), 0) AS total FROM tblVenueInvoicePayments WHERE invoiceID = ? AND siteID = ?');
            if ($sumStmt !== false) {
                $sumStmt->bind_param('ii', $invoiceId, $siteId);
                $sumStmt->execute();
                $alreadyPaid = (int) ($sumStmt->get_result()->fetch_assoc()['total'] ?? 0);
                $sumStmt->close();
                $invoiceAmount = (int) ($invoice['amountPence'] ?? 0);
                if (($alreadyPaid + $amountPence) > $invoiceAmount) {
                    $errors[] = 'Payment exceeds the invoice\'s outstanding balance.';
                }
            }
        }

        if (count($errors) > 0) {
            return ['payID' => 0, 'errors' => $errors];
        }

        $stmt = $db->prepare(
            'INSERT INTO tblVenueInvoicePayments (siteID, invoiceID, paidDate, amountPence, method, reference, notes, recordedByID) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if ($stmt === false) {
            return ['payID' => 0, 'errors' => ['Could not record payment.']];
        }
        $stmt->bind_param('iisisssi', $siteId, $invoiceId, $paidDate, $amountPence, $method, $reference, $notes, $actorUserId);
        $ok = $stmt->execute();
        $newId = (int) $stmt->insert_id;
        $stmt->close();
        if ($ok === false || $newId <= 0) {
            return ['payID' => 0, 'errors' => ['Could not record payment.']];
        }
        self::audit('tblVenueInvoicePayments', $newId, 'create', null, ['amountPence' => $amountPence, 'paidDate' => $paidDate], $actorUserId);
        self::recomputeInvoiceStatus($invoiceId);
        return ['payID' => $newId, 'errors' => []];
    }

    public static function deletePayment(int $payId, int $siteId, int $actorUserId): bool
    {
        $db = self::db();
        $stmt = $db->prepare('SELECT * FROM tblVenueInvoicePayments WHERE payID = ? AND siteID = ? LIMIT 1');
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('ii', $payId, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row === null) {
            return false;
        }
        $del = $db->prepare('DELETE FROM tblVenueInvoicePayments WHERE payID = ? AND siteID = ?');
        if ($del === false) {
            return false;
        }
        $del->bind_param('ii', $payId, $siteId);
        $ok = $del->execute();
        $del->close();
        if ($ok === true) {
            self::audit('tblVenueInvoicePayments', $payId, 'delete', $row, null, $actorUserId);
            self::recomputeInvoiceStatus((int) $row['invoiceID']);
        }
        return $ok === true;
    }

    /**
     * disputed/cancelled are sticky (manual family — left untouched). Else
     * derived from SUM(payments) vs amountPence. Machine-only status write
     * bypasses audit (the payment row IS the audited event).
     */
    public static function recomputeInvoiceStatus(int $invoiceId): string
    {
        $db = self::db();
        $stmt = $db->prepare('SELECT siteID, amountPence, status, paidAt FROM tblVenueInvoices WHERE invoiceID = ? LIMIT 1');
        if ($stmt === false) {
            return 'pending';
        }
        $stmt->bind_param('i', $invoiceId);
        $stmt->execute();
        $invoice = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($invoice === null) {
            return 'pending';
        }
        if (in_array((string) $invoice['status'], self::INVOICE_MANUAL_STATUSES, true) === true) {
            return (string) $invoice['status'];
        }

        $sumStmt = $db->prepare('SELECT COALESCE(SUM(amountPence), 0) AS total FROM tblVenueInvoicePayments WHERE invoiceID = ?');
        if ($sumStmt === false) {
            return (string) $invoice['status'];
        }
        $sumStmt->bind_param('i', $invoiceId);
        $sumStmt->execute();
        $paid = (int) ($sumStmt->get_result()->fetch_assoc()['total'] ?? 0);
        $sumStmt->close();

        $amount = (int) $invoice['amountPence'];
        if ($paid <= 0) {
            $newStatus = 'pending';
        } elseif ($paid < $amount) {
            $newStatus = 'part-paid';
        } else {
            $newStatus = 'paid';
        }

        $wasPaid = (string) $invoice['status'] === 'paid';
        $nowPaid = $newStatus === 'paid';
        if ($nowPaid === true && $wasPaid === false) {
            $upd = $db->prepare('UPDATE tblVenueInvoices SET status = ?, paidAt = NOW() WHERE invoiceID = ?');
        } elseif ($nowPaid === false && $wasPaid === true) {
            $upd = $db->prepare('UPDATE tblVenueInvoices SET status = ?, paidAt = NULL WHERE invoiceID = ?');
        } else {
            $upd = $db->prepare('UPDATE tblVenueInvoices SET status = ? WHERE invoiceID = ?');
        }
        if ($upd !== false) {
            $upd->bind_param('si', $newStatus, $invoiceId);
            $upd->execute();
            $upd->close();
        }
        return $newStatus;
    }

    /* ==========================================================================
     * 🗓️ Calendar overlay / conflict ("is it booked?") engine.
     *
     * WALL-CLOCK RULE (repeated here at the point of use — see class
     * header): booking rows are venue-LOCAL wall-clock. tblEvents rows are
     * event-LOCAL wall-clock (its "stored in UTC" column comment is a known
     * doc bug). classifyEventCoverage() below compares wall-clock to
     * wall-clock: identical IANA zones => direct comparison, ZERO
     * conversion, UTC never touched; differing zones => convert the
     * event's wall-clock from event.timezone to venue.timezone via
     * DateTimeImmutable/DateTimeZone. Never route through UTC.
     * ======================================================================== */

    /**
     * The §8.1 overlay query, PHP-grouped by date and colour-decorated. THE
     * overlay data source for the calendar layer and the event-coverage
     * classifier below. No cost columns.
     *
     * @return array<string, array<int, array<string, mixed>>> keyed 'Y-m-d' => [row, ...]
     */
    public static function availabilityForRange(int $siteId, ?int $venueId, string $dateFrom, string $dateTo): array
    {
        $fp = explode('-', $dateFrom);
        $tp = explode('-', $dateTo);
        if (count($fp) !== 3 || count($tp) !== 3
            || checkdate((int) $fp[1], (int) $fp[2], (int) $fp[0]) === false
            || checkdate((int) $tp[1], (int) $tp[2], (int) $tp[0]) === false
        ) {
            return [];
        }

        $db = self::db();
        $sql = 'SELECT b.bookingID, b.venueID, v.venueName, b.roomID, r.roomName, '
            . 'b.bookingDate, b.startTime, b.endTime, b.notes, b.eventID, '
            . 'ut.typeName AS usageTypeName, ut.usageKind, ut.isBookable, '
            . 'st.statusName, st.statusCategory, st.countsAsConfirmed, st.isAvailable, st.color '
            . 'FROM tblVenueBookings b '
            . 'JOIN tblVenues v ON v.venueID = b.venueID '
            . 'JOIN tblVenueUsageTypes ut ON ut.usageTypeID = b.usageTypeID '
            . 'JOIN tblVenueStatuses st ON st.statusID = b.statusID '
            . 'LEFT JOIN tblVenueRooms r ON r.roomID = b.roomID '
            . 'WHERE b.siteID = ? AND b.isDeleted = 0 AND b.bookingDate BETWEEN ? AND ?'
            . ($venueId !== null ? ' AND b.venueID = ?' : '')
            . ' ORDER BY b.bookingDate, b.venueID, b.startTime';
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            return [];
        }
        if ($venueId !== null) {
            $stmt->bind_param('issi', $siteId, $dateFrom, $dateTo, $venueId);
        } else {
            $stmt->bind_param('iss', $siteId, $dateFrom, $dateTo);
        }
        $stmt->execute();
        $rows = self::fetchAll($stmt);
        $stmt->close();

        $byDate = [];
        foreach ($rows as $row) {
            $row['resolvedColor'] = self::KIND_COLORS[(string) $row['usageKind']] ?? self::statusColor($row);
            $row['isRejected'] = (int) $row['isBookable'] === 1 && (string) $row['statusCategory'] === 'rejected';
            $byDate[(string) $row['bookingDate']][] = $row;
        }
        return $byDate;
    }

    /**
     * THE wall-clock coverage algorithm. $event carries tblEvents' own
     * columns verbatim (startDateTime/endDateTime/timezone) — never
     * pre-converted by the caller.
     *
     * $roomId (#436, optional) narrows coverage to one room of the venue:
     * per-day booking rows are filtered to (roomID IS NULL OR roomID =
     * $roomId) before the day-cascade below — a whole-venue booking still
     * covers every room, but a booking scoped to a DIFFERENT room no
     * longer counts. Omitted/null reproduces today's venue-wide behaviour
     * bit-for-bit (both pre-#436 call sites never pass it). An unresolvable
     * $roomId (wrong venue/site, deleted, <= 0) silently degrades to
     * venue-level coverage — same "no existence oracle" philosophy as the
     * venue-missing sentinel below.
     *
     * @param array{startDateTime: string, endDateTime?: ?string, timezone?: ?string} $event
     * @return array{classification: string, severity: string, message: string, perDay: array<int, array<string, mixed>>, dataConflict: bool, roomID: ?int}
     */
    public static function classifyEventCoverage(array $event, int $venueId, ?int $roomId = null): array
    {
        $venue = self::getVenue($venueId, Site::id());
        if ($venue === null) {
            // 🚪 Dormant sentinel — no venue configured. Severity 'success' so
            // calendar/save.php treats it as no-warning; api/check.php reports
            // the classification explicitly.
            return [
                'classification' => 'venue-missing',
                'severity' => 'success',
                'message' => I18n::t('venues.coverage.venue_missing'),
                'perDay' => [],
                'dataConflict' => false,
                'roomID' => null,
            ];
        }

        // 🚪 #436 — resolve+re-validate the room server-side (site+venue
        // scoped, via the same private helper save.php's link-persistence
        // guard uses through the public getRoom() mirror). Unresolvable ⇒
        // degrade silently to venue-level coverage, exactly today's output.
        $room = null;
        if ($roomId !== null && $roomId > 0) {
            $room = self::validateRoomForVenue($roomId, $venueId, Site::id());
            if ($room === null) {
                $roomId = null;
            }
        } else {
            $roomId = null;
        }

        try {
            $evTzName = (string) ($event['timezone'] ?? '');
            $evTz = new \DateTimeZone($evTzName !== '' ? $evTzName : 'Europe/London');
        } catch (\Throwable $e) {
            $evTz = new \DateTimeZone('Europe/London');
        }
        $venTz = self::venueTimezone($venue);

        $startRaw = (string) $event['startDateTime'];
        $endRaw = isset($event['endDateTime']) && $event['endDateTime'] !== null ? (string) $event['endDateTime'] : null;

        try {
            if ($evTz->getName() === $venTz->getName()) {
                // 🎯 FAST PATH — the overwhelmingly common case. The event's
                // stored wall-clock IS venue wall-clock already: identity,
                // NEVER call setTimezone(). No conversion happens at all.
                $start = new \DateTimeImmutable($startRaw, $venTz);
                $end = $endRaw !== null ? new \DateTimeImmutable($endRaw, $venTz) : $start->modify('+1 hour');
            } else {
                // 🌍 CROSS-ZONE PATH (rare) — interpret the stored value in
                // the EVENT's own zone, then re-express it in the VENUE's
                // zone. PHP's zone database absorbs DST correctly here.
                $start = (new \DateTimeImmutable($startRaw, $evTz))->setTimezone($venTz);
                $end = $endRaw !== null
                    ? (new \DateTimeImmutable($endRaw, $evTz))->setTimezone($venTz)
                    : $start->modify('+1 hour');
            }
        } catch (\Throwable $e) {
            return [
                'classification' => self::COVERAGE_NO_BOOKING,
                'severity' => self::COVERAGE_SEVERITY[self::COVERAGE_NO_BOOKING],
                'message' => I18n::t('venues.coverage.no_booking', ['venue' => $venue['venueName'], 'date' => '']),
                'perDay' => [],
                'dataConflict' => false,
                'roomID' => $roomId,
            ];
        }
        if ($end <= $start) {
            $end = $start->modify('+1 hour');
        }

        $firstDate = $start->format('Y-m-d');
        $lastDate = $end->format('Y-m-d');
        $dates = [];
        $cursor = new \DateTimeImmutable($firstDate, $venTz);
        $lastCursor = new \DateTimeImmutable($lastDate, $venTz);
        $cap = 31;
        while ($cursor <= $lastCursor && count($dates) < $cap) {
            $dates[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('+1 day');
        }
        $truncatedNote = $cursor <= $lastCursor ? sprintf(' (showing the first %d days of a longer event)', $cap) : '';

        $days = self::availabilityForRange(Site::id(), $venueId, $firstDate, $lastDate);
        $perDay = [];
        $dataConflict = false;

        foreach ($dates as $date) {
            $isFirst = $date === $firstDate;
            $isLast = $date === $lastDate;
            $ls = $isFirst ? $start->format('H:i:s') : '00:00:00';
            $le = $isLast ? $end->format('H:i:s') : '24:00:00';
            $rows = $days[$date] ?? [];

            // 🚪 #436 — room-aware filter. $allRows stays UNFILTERED (kept
            // for the room-not-covered probe below); $rows narrows to rows
            // that apply to this room — a whole-venue booking (roomID
            // NULL) still covers every room, but a row scoped to a
            // DIFFERENT room (including its own unavailable/closed rows)
            // never counts for this one. No-op when $roomId is null.
            $allRows = $rows;
            if ($roomId !== null) {
                $rows = array_values(array_filter($rows, static function (array $r) use ($roomId): bool {
                    return $r['roomID'] === null || (int) $r['roomID'] === $roomId;
                }));
            }

            $classification = self::COVERAGE_NO_BOOKING;
            $matchedRows = [];

            $unavailableRows = array_values(array_filter($rows, static fn (array $r): bool => (string) $r['usageKind'] === 'unavailable'));
            $confirmedCovering = array_values(array_filter($rows, static function (array $r) use ($ls, $le): bool {
                return (int) $r['countsAsConfirmed'] === 1 && (int) $r['isBookable'] === 1
                    && $r['startTime'] !== null && $r['endTime'] !== null
                    && (string) $r['startTime'] <= $ls && (string) $r['endTime'] >= $le;
            }));
            $anyConfirmedBookable = array_values(array_filter($rows, static fn (array $r): bool => (int) $r['countsAsConfirmed'] === 1 && (int) $r['isBookable'] === 1));
            $closedRows = array_values(array_filter($rows, static fn (array $r): bool => (string) $r['usageKind'] === 'closed'));
            $unconfirmedRows = array_values(array_filter($rows, static function (array $r): bool {
                return (int) $r['isBookable'] === 1 && (int) $r['countsAsConfirmed'] === 0 && (string) $r['statusCategory'] !== 'rejected';
            }));

            if (count($unavailableRows) > 0) {
                $classification = self::COVERAGE_UNAVAILABLE;
                $matchedRows = $unavailableRows;
                if (count($anyConfirmedBookable) > 0) {
                    $dataConflict = true;
                }
            } elseif (count($confirmedCovering) > 0) {
                $classification = self::COVERAGE_CONFIRMED;
                $matchedRows = $confirmedCovering;
            } elseif (count($anyConfirmedBookable) > 0) {
                $classification = self::COVERAGE_OUTSIDE_HOURS;
                $matchedRows = $anyConfirmedBookable;
            } elseif (count($closedRows) > 0) {
                $classification = self::COVERAGE_CLOSED;
                $matchedRows = $closedRows;
            } elseif (count($unconfirmedRows) > 0) {
                $classification = self::COVERAGE_UNCONFIRMED;
                $matchedRows = $unconfirmedRows;
            } elseif ($roomId !== null && count(array_values(array_filter($allRows, static fn (array $r): bool => (int) $r['countsAsConfirmed'] === 1 && (int) $r['isBookable'] === 1))) > 0) {
                // 🚪 #436 — this room has no cover of any kind, but the
                // venue DOES have a confirmed bookable hire that day (for
                // some OTHER room) — the precise "booked, but not for your
                // room" warning. If the unfiltered set only has
                // unconfirmed/closed/rejected rows for other rooms, plain
                // no-booking below stands (accurate: nothing confirmed
                // anywhere that day).
                $classification = self::COVERAGE_ROOM_NOT_COVERED;
                $matchedRows = array_values(array_filter($allRows, static fn (array $r): bool => (int) $r['countsAsConfirmed'] === 1 && (int) $r['isBookable'] === 1));
            } else {
                $classification = self::COVERAGE_NO_BOOKING;
                $matchedRows = array_values(array_filter($rows, static fn (array $r): bool => (string) $r['statusCategory'] === 'rejected'));
            }

            $perDay[] = ['date' => $date, 'classification' => $classification, 'rows' => $matchedRows];
        }

        $overall = self::COVERAGE_NO_BOOKING;
        foreach (self::WORST_ORDER as $candidate) {
            $present = false;
            foreach ($perDay as $day) {
                if ($day['classification'] === $candidate) {
                    $present = true;
                    break;
                }
            }
            if ($present === true) {
                $overall = $candidate;
                break;
            }
        }

        $severity = self::COVERAGE_SEVERITY[$overall] ?? 'warning';
        $context = [
            'venue' => $venue['venueName'],
            'date' => $firstDate,
            'window' => $start->format('H:i') . "\u{2013}" . $end->format('H:i'),
        ];
        if ($room !== null) {
            // #436 — additive: only venues.coverage.room_not_covered
            // actually references :room; every other message is untouched.
            $context['room'] = (string) $room['roomName'];
        }
        $message = self::coverageMessage($overall, $context);
        if (count($dates) > 1) {
            $message = I18n::t('venues.coverage.multi_day_worst', ['count' => count($dates), 'message' => $message]) . $truncatedNote;
        }

        return [
            'classification' => $overall,
            'severity' => $severity,
            'message' => $message,
            'perDay' => $perDay,
            'dataConflict' => $dataConflict,
            'roomID' => $roomId,
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function coverageMessage(string $classification, array $context): string
    {
        $key = 'venues.coverage.' . str_replace('-', '_', $classification);
        $params = [
            'venue' => (string) ($context['venue'] ?? ''),
            'date' => (string) ($context['date'] ?? ''),
            'room' => (string) ($context['room'] ?? ''),
            'window' => (string) ($context['window'] ?? ''),
            'status' => (string) ($context['status'] ?? ''),
        ];
        return I18n::t($key, $params);
    }

    /* ==========================================================================
     * 🔔 Reminder query helpers — consumed by cron/venue-reminders.php.
     * ======================================================================== */

    /** §9 row 1: bookings that are booked but not yet agreed, due within the lead window. */
    public static function dueUnagreedBookings(int $siteId, int $leadDays): array
    {
        $db = self::db();
        $stmt = $db->prepare(
            'SELECT b.bookingID, b.venueID, b.bookingDate, v.venueName FROM tblVenueBookings b '
            . 'JOIN tblVenueUsageTypes ut ON ut.usageTypeID = b.usageTypeID '
            . 'JOIN tblVenueStatuses st ON st.statusID = b.statusID '
            . 'JOIN tblVenues v ON v.venueID = b.venueID '
            . "WHERE b.siteID = ? AND b.isDeleted = 0 AND ut.isBookable = 1 AND st.countsAsConfirmed = 0 AND st.statusCategory <> 'rejected' "
            . 'AND b.bookingDate BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)'
        );
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('ii', $siteId, $leadDays);
        $stmt->execute();
        $rows = self::fetchAll($stmt);
        $stmt->close();
        return $rows;
    }

    /** §9 row 2: active agreements whose renewal date OR notice-adjusted term end falls within the lead window. Each row tagged with which trigger fired. */
    public static function dueAgreementRenewals(int $siteId, int $leadDays): array
    {
        $db = self::db();
        $stmt = $db->prepare(
            'SELECT agreementID, venueID, title, renewalDate, termEnd, noticePeriodDays FROM tblVenueAgreements '
            . "WHERE siteID = ? AND status = 'active' AND ("
            . '(renewalDate IS NOT NULL AND renewalDate BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)) '
            . 'OR (termEnd IS NOT NULL AND DATE_SUB(termEnd, INTERVAL COALESCE(noticePeriodDays, 0) DAY) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY))'
            . ')'
        );
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('iii', $siteId, $leadDays, $leadDays);
        $stmt->execute();
        $rows = self::fetchAll($stmt);
        $stmt->close();

        foreach ($rows as &$row) {
            $renewalDue = $row['renewalDate'] !== null && (string) $row['renewalDate'] <= date('Y-m-d', strtotime('+' . $leadDays . ' days'));
            $row['trigger'] = $renewalDue === true ? 'renewal' : 'notice';
            $row['dueDate'] = $renewalDue === true ? $row['renewalDate'] : $row['termEnd'];
        }
        unset($row);
        return $rows;
    }

    /** §9 row 3: pending/part-paid invoices due within the lead window — overdue invoices are included by construction. */
    public static function dueInvoices(int $siteId, int $leadDays): array
    {
        $db = self::db();
        $stmt = $db->prepare(
            'SELECT invoiceID, venueID, invoiceRef, dueDate, amountPence FROM tblVenueInvoices '
            . "WHERE siteID = ? AND status IN ('pending', 'part-paid') AND dueDate IS NOT NULL "
            . 'AND dueDate <= DATE_ADD(CURDATE(), INTERVAL ? DAY)'
        );
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('ii', $siteId, $leadDays);
        $stmt->execute();
        $rows = self::fetchAll($stmt);
        $stmt->close();
        return $rows;
    }

    /** Check-first — the common "not sent yet" path never even attempts a duplicate write. uq_venrl_ref is the concurrency backstop. */
    public static function reminderAlreadySent(string $refType, int $refId, string $dueDate): bool
    {
        $db = self::db();
        $stmt = $db->prepare('SELECT 1 FROM tblVenueReminderLog WHERE refType = ? AND refID = ? AND dueDate = ? LIMIT 1');
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('sis', $refType, $refId, $dueDate);
        $stmt->execute();
        $already = $stmt->get_result()->fetch_assoc() !== null;
        $stmt->close();
        return $already;
    }

    /** recipientCount 0 STILL logs — prevents the sweep retrying the same due item forever on a site with no configured recipient. */
    public static function logReminder(int $siteId, string $refType, int $refId, string $dueDate, int $recipientCount): void
    {
        $db = self::db();
        try {
            $stmt = $db->prepare(
                'INSERT INTO tblVenueReminderLog (siteID, refType, refID, dueDate, recipientCount) VALUES (?, ?, ?, ?, ?)'
            );
            if ($stmt === false) {
                return;
            }
            $stmt->bind_param('isisi', $siteId, $refType, $refId, $dueDate, $recipientCount);
            $stmt->execute();
            $stmt->close();
        } catch (\mysqli_sql_exception $e) {
            // 🏁 uq_venrl_ref caught a concurrent run — treat exactly like "already sent".
            error_log('Venues::logReminder() duplicate race: ' . $e->getMessage());
        }
    }

    /**
     * CSV venues.reminder_roles -> roleKeys (union {'venue_manager'} when
     * CSV empty); active site users with a valid email who are admins OR
     * hold one of the target roles. Mirrors
     * AssetRegister::resolveReminderRecipients()'s role/admin SQL shape.
     *
     * @return string[] de-duplicated, FILTER_VALIDATE_EMAIL'd addresses
     */
    public static function resolveReminderRecipients(int $siteId): array
    {
        // 🌐 Site-scoped read — this runs inside the reminders cron's
        //     per-site loop (Site::forceContext), and the ambient Settings
        //     snapshot is NOT refreshed by forceContext, so an ambient
        //     Settings::get() would apply the first site's reminder_roles to
        //     every site. settingForSite($key, $siteId) honours per-site
        //     overrides (falling back to the global NULL-siteID default).
        $csv = (string) (App::settingForSite('venues.reminder_roles', $siteId) ?? '');
        $roleKeys = array_values(array_filter(array_map('trim', explode(',', $csv)), static fn (string $r): bool => $r !== ''));
        if (count($roleKeys) === 0) {
            $roleKeys = ['venue_manager'];
        } elseif (in_array('venue_manager', $roleKeys, true) === false) {
            $roleKeys[] = 'venue_manager';
        }

        $db = self::db();
        $placeholders = implode(',', array_fill(0, count($roleKeys), '?'));
        $sql = 'SELECT DISTINCT u.emailAddress AS email FROM tblUsers u '
            . 'INNER JOIN tblUserSites us ON us.userID = u.userID AND us.siteID = ? AND us.isActive = 1 '
            . 'LEFT JOIN tblUserRoles ur ON ur.userID = u.userID '
            . 'LEFT JOIN tblRoles r ON r.roleID = ur.roleID '
            . 'WHERE u.isActive = 1 AND u.emailAddress IS NOT NULL AND u.emailAddress != "" '
            . "AND (u.isAdmin = 1 OR u.isRootAdmin = 1 OR us.isSiteAdmin = 1 OR us.isSiteRootAdmin = 1 OR r.roleKey IN ({$placeholders}))";
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            return [];
        }
        $types = 'i' . str_repeat('s', count($roleKeys));
        $stmt->bind_param($types, $siteId, ...$roleKeys);
        $stmt->execute();
        $emails = [];
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $emails[] = (string) $row['email'];
        }
        $stmt->close();

        $emails = array_values(array_unique($emails));
        return array_values(array_filter(
            $emails,
            static fn (string $e): bool => filter_var($e, FILTER_VALIDATE_EMAIL) !== false
        ));
    }

    /* ==========================================================================
     * 🔒 Private helpers
     * ======================================================================== */

    private static function db(): \mysqli
    {
        return App::db();
    }

    /** @return array<int, array<string, mixed>> */
    private static function fetchAll(\mysqli_stmt $stmt): array
    {
        $rows = [];
        $result = $stmt->get_result();
        if ($result === false) {
            return [];
        }
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }

    private static function nullableTrim(mixed $value, int $maxLen): ?string
    {
        $trimmed = trim((string) ($value ?? ''));
        if ($trimmed === '') {
            return null;
        }
        return mb_substr($trimmed, 0, $maxLen);
    }

    private static function nullableDate(mixed $value): ?string
    {
        $str = trim((string) ($value ?? ''));
        if ($str === '') {
            return null;
        }
        $parts = explode('-', $str);
        if (count($parts) !== 3 || checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0]) === false) {
            return null;
        }
        return $str;
    }

    /**
     * The §11.1 time-range regex + sentinel set. Sentinel values (blank,
     * '0', 'N/A', '-', em/en dash, '<enter times>', 'TBC', 'TBD' — all
     * case-insensitive/trimmed) yield a NULL pair, not an error.
     *
     * @return ?array{start: string, end: string}
     */
    private static function parseTimeRange(string $raw): ?array
    {
        $trimmed = trim($raw);
        $sentinels = ['', '0', 'n/a', '-', "\u{2013}", "\u{2014}", '<enter times>', 'tbc', 'tbd'];
        if (in_array(strtolower($trimmed), $sentinels, true) === true) {
            return null;
        }
        if (preg_match('/^\s*(\d{1,2})[:.](\d{2})\s*(?:-|\x{2013}|\x{2014}|to)\s*(\d{1,2})[:.](\d{2})\s*$/iu', $trimmed, $m) !== 1) {
            return null;
        }
        $sh = (int) $m[1];
        $sm = (int) $m[2];
        $eh = (int) $m[3];
        $em = (int) $m[4];
        if ($sh > 23 || $sm > 59 || $eh > 23 || $em > 59) {
            return null;
        }
        $start = sprintf('%02d:%02d:00', $sh, $sm);
        $end = sprintf('%02d:%02d:00', $eh, $em);
        if ($end <= $start) {
            return null;
        }
        return ['start' => $start, 'end' => $end];
    }

    /**
     * Excel 1900-system serial -> 'Y-m-d'. The -30 epoch (1899-12-30)
     * absorbs Excel's phantom 1900-02-29 for all serials >= 61. Sanity
     * window 2000-01-01..2100-12-31; outside it => null (implausible date).
     */
    private static function excelSerialToDate(float $serial): ?string
    {
        if ($serial < 1.0 || $serial > 200000.0) {
            return null;
        }
        try {
            $date = (new \DateTimeImmutable('1899-12-30'))->modify('+' . (int) $serial . ' days');
        } catch (\Throwable $e) {
            return null;
        }
        $result = $date->format('Y-m-d');
        if ($result < '2000-01-01' || $result > '2100-12-31') {
            return null;
        }
        return $result;
    }

    /** Text-date parse with a d/m/Y preference, same sanity window as excelSerialToDate(). */
    private static function parseTextDate(string $raw): ?string
    {
        $raw = trim($raw);
        if (preg_match('#^(\d{1,2})[/.](\d{1,2})[/.](\d{4})$#', $raw, $m) === 1) {
            $day = (int) $m[1];
            $month = (int) $m[2];
            $year = (int) $m[3];
            if (checkdate($month, $day, $year) === true) {
                $result = sprintf('%04d-%02d-%02d', $year, $month, $day);
                return ($result >= '2000-01-01' && $result <= '2100-12-31') ? $result : null;
            }
        }
        try {
            $date = new \DateTimeImmutable($raw);
        } catch (\Throwable $e) {
            return null;
        }
        $result = $date->format('Y-m-d');
        return ($result >= '2000-01-01' && $result <= '2100-12-31') ? $result : null;
    }

    /** Guarded DateTimeZone construction — fallback Europe/London on any invalid stored value. */
    private static function venueTimezone(array $venue): \DateTimeZone
    {
        try {
            $tz = (string) ($venue['timezone'] ?? '');
            return new \DateTimeZone($tz !== '' ? $tz : 'Europe/London');
        } catch (\Throwable $e) {
            return new \DateTimeZone('Europe/London');
        }
    }

    /* -- Cross-tenant validation probes (security item 2) -- one prepared SELECT each -- */

    private static function validateVenue(int $venueId, int $siteId, bool $requireActive = false): ?array
    {
        if ($venueId <= 0) {
            return null;
        }
        $venue = self::getVenue($venueId, $siteId);
        if ($venue === null) {
            return null;
        }
        if ($requireActive === true && (int) $venue['isActive'] !== 1) {
            return null;
        }
        return $venue;
    }

    private static function validateStatusForSite(int $statusId, int $siteId, bool $requireActive = false): ?array
    {
        if ($statusId <= 0) {
            return null;
        }
        $db = self::db();
        $stmt = $db->prepare('SELECT * FROM tblVenueStatuses WHERE statusID = ? AND siteID = ? LIMIT 1');
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('ii', $statusId, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row === null) {
            return null;
        }
        if ($requireActive === true && (int) $row['isActive'] !== 1) {
            return null;
        }
        return $row;
    }

    private static function validateUsageTypeForVenue(int $usageTypeId, int $venueId, int $siteId, bool $requireActive = false): ?array
    {
        if ($usageTypeId <= 0) {
            return null;
        }
        $db = self::db();
        $stmt = $db->prepare('SELECT * FROM tblVenueUsageTypes WHERE usageTypeID = ? AND venueID = ? AND siteID = ? LIMIT 1');
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('iii', $usageTypeId, $venueId, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row === null) {
            return null;
        }
        if ($requireActive === true && (int) $row['isActive'] !== 1) {
            return null;
        }
        return $row;
    }

    /**
     * Site+venue-scoped room fetch — the public mirror of getVenue()
     * (Venues.php:383), for callers outside this class (#436 —
     * calendar/manage/save.php's link-persistence guard needs this;
     * validateRoomForVenue() below is private). One-liner delegation so
     * there is a single SQL definition. Deliberately does NOT require
     * isActive=1 — matching saveBooking()'s own room rule, so an event
     * keeps a room link even after that room is later deactivated; the
     * *picker* (calendar/manage/index.php) lists active rooms only.
     */
    public static function getRoom(int $roomId, int $venueId, int $siteId): ?array
    {
        return self::validateRoomForVenue($roomId, $venueId, $siteId);
    }

    private static function validateRoomForVenue(int $roomId, int $venueId, int $siteId): ?array
    {
        if ($roomId <= 0) {
            return null;
        }
        $db = self::db();
        $stmt = $db->prepare('SELECT * FROM tblVenueRooms WHERE roomID = ? AND venueID = ? AND siteID = ? LIMIT 1');
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('iii', $roomId, $venueId, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row !== null ? $row : null;
    }

    private static function validateEventForSite(int $eventId, int $siteId): ?array
    {
        if ($eventId <= 0) {
            return null;
        }
        $db = self::db();
        $stmt = $db->prepare('SELECT * FROM tblEvents WHERE eventID = ? AND siteID = ? AND isDeleted = 0 LIMIT 1');
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('ii', $eventId, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row !== null ? $row : null;
    }

    private static function validateAgreementForVenue(int $agreementId, int $venueId, int $siteId): ?array
    {
        if ($agreementId <= 0) {
            return null;
        }
        $db = self::db();
        $stmt = $db->prepare('SELECT * FROM tblVenueAgreements WHERE agreementID = ? AND venueID = ? AND siteID = ? LIMIT 1');
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('iii', $agreementId, $venueId, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row !== null ? $row : null;
    }

    private static function validateGroupForVenue(int $groupId, int $venueId, int $siteId): ?array
    {
        if ($groupId <= 0) {
            return null;
        }
        $db = self::db();
        $stmt = $db->prepare('SELECT * FROM tblVenueBookingGroups WHERE groupID = ? AND venueID = ? AND siteID = ? LIMIT 1');
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('iii', $groupId, $venueId, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row !== null ? $row : null;
    }

    /** Site-scoped only (no venue known yet) — used by setGroupStatus/softDeleteGroup, which take the group as the primary key. */
    private static function validateGroupForVenueAny(int $groupId, int $siteId): ?array
    {
        if ($groupId <= 0) {
            return null;
        }
        $db = self::db();
        $stmt = $db->prepare('SELECT * FROM tblVenueBookingGroups WHERE groupID = ? AND siteID = ? LIMIT 1');
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('ii', $groupId, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row !== null ? $row : null;
    }

    private static function validateImportBatch(int $batchId, int $siteId): ?array
    {
        if ($batchId <= 0) {
            return null;
        }
        $db = self::db();
        $stmt = $db->prepare('SELECT * FROM tblVenueImportBatches WHERE batchID = ? AND siteID = ? LIMIT 1');
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('ii', $batchId, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row !== null ? $row : null;
    }

    private static function getUsageTypeRaw(int $usageTypeId, int $siteId): ?array
    {
        $db = self::db();
        $stmt = $db->prepare('SELECT * FROM tblVenueUsageTypes WHERE usageTypeID = ? AND siteID = ? LIMIT 1');
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('ii', $usageTypeId, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row !== null ? $row : null;
    }

    /** Case-insensitive exact-name lookup (utf8mb4_general_ci collation does the fold in SQL) — the import wizard's auto-map step. */
    private static function findUsageTypeByName(int $venueId, int $siteId, string $name): ?int
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        $db = self::db();
        $stmt = $db->prepare('SELECT usageTypeID FROM tblVenueUsageTypes WHERE venueID = ? AND siteID = ? AND typeName = ? LIMIT 1');
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('iis', $venueId, $siteId, $name);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row !== null ? (int) $row['usageTypeID'] : null;
    }

    private static function findStatusByName(int $siteId, string $name): ?int
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        $db = self::db();
        $stmt = $db->prepare('SELECT statusID FROM tblVenueStatuses WHERE siteID = ? AND statusName = ? LIMIT 1');
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('is', $siteId, $name);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row !== null ? (int) $row['statusID'] : null;
    }
}
