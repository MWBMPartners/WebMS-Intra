<?php
// Path: _core/OutsideServices.php
/**
 * -----------------------------------------------------------------------------
 * What a member's browser may tell an OUTSIDE COMPANY about them 🛂 (#778)
 * -----------------------------------------------------------------------------
 * Some pictures on a page are not fetched from this portal but from another
 * company's servers. Fetching one tells that company something about a member:
 *
 *   - a GRAVATAR picture is asked for at an address built from a scramble of
 *     the member's e-mail address. It is NOT anonymous: Gravatar's whole
 *     business is matching that scramble to a person, so the company learns who
 *     is looking, from which internet address, on what browser, and when;
 *   - the PHOTO A SIGN-IN PROVIDER supplied (Google's `picture`) is an address
 *     on that provider's servers that is unique to the person;
 *   - a MAP AROUND A MEMBER'S HOME (the member directory) asks OpenStreetMap for
 *     the map squares around where that member lives, together with the
 *     internet address of whoever is looking;
 *   - a MAP OF WHERE A SMALL GROUP MEETS (#809). A small group very often
 *     meets in somebody's home, so the owner decided (D-120) that this map is
 *     the HOST'S HOME on the same terms as the one above. See
 *     `allowsMeetingPlaceMap()` for whose agreement counts.
 *
 * Until #778 nothing let anybody switch any of these off.
 *
 * THE RULE (the owner, 5 November 2026 — D-058; maps added by D-072)
 * -----------------------------------------------------------------------------
 * The answer for a member is the STRICTEST of:
 *   1. the INSTALLATION's setting (what is allowed at all, and the default);
 *   2. EVERY ORGANISATION the member belongs to — not only the one whose page
 *      is open. A member reading organisation A's page is still a member of
 *      organisation B, and B's refusal is about THEM, not about A's content.
 *      Resolving this from the open organisation alone would quietly leak for
 *      exactly the people most likely to object;
 *   3. the member's OWN choice, which may tighten and never loosen.
 * An organisation may tighten the installation and never loosen it; the two
 * administrators may be different parties (D-013's rule for limits, applied to
 * a permission).
 *
 * Each level holds one of three values, in order of strictness:
 *     no   not allowed at all
 *     off  allowed, but OFF until a member switches it on for themselves
 *     on   allowed, and ON until a member switches it off
 * ANYTHING ELSE — empty, misspelt, missing, or a value that could not be read —
 * counts as `no`. A privacy permission that cannot be read must never read as
 * granted; the recoverable direction is the one that shows a silhouette.
 * "Default" follows the strictest level too: an organisation cannot make a
 * service ON by default when the installation ships it OFF.
 *
 * MAPS ARE NEVER ON BY DEFAULT (D-072). For `memberMap` a level may say only
 * `no` or `off`: a member's map appears only once THAT member has switched it
 * on. The pages do not offer `on`, both writers refuse it, and the reader
 * (`decide()`, through `capFor()`) reads a stored `on` as `off`. The pure
 * `effectiveFrom()` below knows nothing of services; `decide()` caps first.
 *
 * THE ONE SERVICE WITH TWO PEOPLE IN IT: MAPS (D-072)
 * -----------------------------------------------------------------------------
 * A map of member X's home, looked at by member Y, involves both of them: X's
 * home is what is sent, Y's internet address is who it is sent with. The
 * owner's words: "a member's map appears only when that member has switched it
 * on and the viewer has not switched it off". So for a person OTHER than the
 * viewer this class asks two DIFFERENT questions:
 *     the person shown — does the ordinary rule above say YES for them?
 *     the person looking — is it allowed for them at all, and have they NOT
 *                          switched it off?
 * The second is deliberately weaker than the first, because that is what the
 * decision says; a viewer who never opened their own page does not stop other
 * members' maps. `allows()` with a subject is the ONE place both are asked.
 *
 * WHAT IS THE ONE PLACE
 * -----------------------------------------------------------------------------
 * This class is the ONLY place the answer is worked out, and the ONLY file
 * under `web/` allowed to hold the Gravatar or Google-photo host names in code
 * (`tools/audit-checks/check_outside_services_gated.py` fails otherwise). An
 * outside address is BUILT here, after the decision, so it cannot exist
 * without one: `gravatarUrlIfAllowed()` and `providerPhotoIfAllowed()` answer
 * null rather than an address when the answer is no. The page rule's
 * `img-src` is widened from `cspImgSources()` alone, as a second defence.
 *
 * WHY `$SETTINGS` IS NEVER READ HERE
 * -----------------------------------------------------------------------------
 * `$SETTINGS` holds the OPEN organisation's values merged over the
 * installation's. It cannot answer point 2 (it knows nothing of the member's
 * other organisations) and it cannot tell "the organisation chose off" from
 * "the installation says off" (#674, #677). So the installation's value comes
 * from `InstallationSettings::layer()` and every organisation's from its OWN
 * row, read in one statement.
 *
 * WHAT THIS CANNOT DO, said plainly
 * -----------------------------------------------------------------------------
 *   - It governs the three services listed in SERVICES and nothing else. Other
 *     outside requests the portal makes (address look-up, what3words, video
 *     players, the libraries fetched from a content-delivery network, the
 *     sign-in robot check) are recorded as follow-ups on #778, not covered.
 *   - It cannot stop a member's browser fetching something the member typed
 *     into a page themselves; it decides only what the PORTAL asks for.
 *   - The writers' checks give an honest refusal; the READER is the enforcement.
 *     A row written by hand in SQL, or brought back by a restore, is still held
 *     to the strictest level when the answer is worked out.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/778
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

final class OutsideServices
{
    // 📋 =========================================================================
    // The services
    // ===========================================================================

    /** Profile pictures from Gravatar. */
    public const GRAVATAR = 'gravatar';

    /** The photo a sign-in provider (Google) supplied, left on its servers. */
    public const PROVIDER_PHOTO = 'providerPhoto';

    /** A map around a member's home, on the member directory (D-072). */
    public const MEMBER_MAP = 'memberMap';

    /** The three values a level may hold, strictest first. */
    public const NO  = 'no';
    public const OFF = 'off';
    public const ON  = 'on';

    /** An organisation's "follow the installation": its own row is removed. */
    public const INHERIT = 'inherit';

    /** A member's "follow my organisations": their own row is removed. */
    public const FOLLOW = 'follow';

    /**
     * A member's "keep my earlier choice": leave this service alone. Offered
     * (and selected) only where a choice is stored but the service is not
     * allowed now, so an untouched Save cannot change a recorded choice.
     */
    public const KEEP = 'keep';

    /**
     * Everything the portal says about each service, in ONE place.
     *
     *   setting     the settings key, at the installation and per organisation
     *   name        what the service is called on the pages
     *   company     who receives the request
     *   sends       one plain sentence of what is sent
     *   imgSources  the page-rule sources a page needs to show it, and NOTHING
     *               wider. Gravatar is one exact host. The sign-in photo is
     *               Google's picture host family, because a provider chooses
     *               which of its machines serves a given photo. The map is
     *               OpenStreetMap's tile host family, for the same reason.
     *   maxLevel    the most permissive value ANY level may hold for it: `on`
     *               for the two pictures, `off` for maps (D-072 rules out a map
     *               that is on before its member has switched it on).
     *
     * ⚠️ ADDING A SERVICE HERE IS NOT ENOUGH. It also needs its setting seeded
     *    in `full_schema.sql` AND a migration, and the place that DRAWS it must
     *    ask this class before it builds the address. The audit check names the
     *    hosts it guards; add the new host there too.
     *
     * @var array<string, array{setting:string, name:string, company:string, sends:string, imgSources:list<string>, maxLevel:string}>
     */
    public const SERVICES = [
        self::GRAVATAR => [
            'setting'    => 'privacy.outside.gravatar',
            'name'       => 'Profile pictures from Gravatar',
            'company'    => 'Gravatar (run by Automattic)',
            'sends'      => 'A scramble of your e-mail address that Gravatar can match to you, your internet '
                          . 'address, your browser and the time, every time a page shows your picture.',
            'imgSources' => ['https://www.gravatar.com'],
            'maxLevel'   => self::ON,
        ],
        self::PROVIDER_PHOTO => [
            'setting'    => 'privacy.outside.providerPhoto',
            'name'       => 'Your photo from the account you sign in with',
            'company'    => 'Google (the photo stays on its servers)',
            'sends'      => 'An address on Google\'s servers that belongs to your photo alone, your internet '
                          . 'address, your browser and the time, every time a page shows your picture.',
            'imgSources' => ['https://*.googleusercontent.com'],
            'maxLevel'   => self::ON,
        ],
        self::MEMBER_MAP => [
            'setting'    => 'privacy.outside.memberMap',
            // 🗺️ #809, D-120: the same setting governs a map of where a small
            //    group meets, because the owner decided that place is to be
            //    treated as the host's home. The words say so, because a choice
            //    made on a page that described only the directory would not be
            //    an informed one.
            'name'       => 'Maps around a member\'s home, and of where a small group meets',
            'company'    => 'OpenStreetMap',
            'sends'      => 'Which map squares to draw, which tells OpenStreetMap roughly where the member lives '
                          . '(to about 110 metres), or exactly where a small group meets, together with the '
                          . 'internet address of whoever is looking. A map of a member\'s home is shown only when '
                          . 'BOTH the member shown and the person looking allow it; a map of a group\'s meeting '
                          . 'place only when the person who entered that place agreed to it and the person '
                          . 'looking allows it.',
            'imgSources' => ['https://*.tile.openstreetmap.org'],
            // 🗺️ NEVER ON BY DEFAULT (D-072): "a member's map appears only when
            //    THAT member has switched it on". So no level may say "on until a
            //    member switches it off" — the pages do not offer it, both writers
            //    refuse it, and the reader reads a stored "on" (hand-written, or
            //    brought back by a restore) as "off". See capFor().
            'maxLevel'   => self::OFF,
        ],
    ];

    /**
     * The words that refusing explains, written once so the general settings
     * editor and every page say the same thing.
     */
    public const LOCKED_MESSAGE = 'Outside services are set on Admin, Settings, Outside services, which holds each '
        . 'organisation to what the installation allows. They cannot be changed, added or deleted here.';

    /** Gravatar's address, used by nothing but gravatarUrlIfAllowed(). */
    private const GRAVATAR_BASE = 'https://www.gravatar.com/avatar/';

    /** The picture-host family a sign-in photo must come from to be drawn at all. */
    private const PROVIDER_PHOTO_HOST_SUFFIX = '.googleusercontent.com';

    // 🧠 =========================================================================
    // Per-request memory
    // ===========================================================================

    /** @var array<string, array<string,mixed>> Facts, keyed "user|site". */
    private static array $facts = [];

    /** Whether this request has already written its one line about a failed read. */
    private static bool $logged = false;

    // 📐 =========================================================================
    // The rule, on FACTS (#647): no connection, no clock, no session
    // ===========================================================================

    /**
     * How permissive one stored value is: 2 for on, 1 for off, 0 for anything
     * else. `no`, empty, null, misspelt and wrong-case text are all 0.
     */
    public static function rank(?string $value): int
    {
        if ($value === null) {
            return 0;
        }
        $value = trim($value);
        if ($value === self::ON) {
            return 2;
        }
        if ($value === self::OFF) {
            return 1;
        }

        return 0;
    }

    /** The word for a rank, for the pages. */
    public static function word(int $rank): string
    {
        if ($rank >= 2) {
            return self::ON;
        }
        if ($rank === 1) {
            return self::OFF;
        }

        return self::NO;
    }

    /**
     * 🧢 One stored level value, held to what the SERVICE allows at all.
     *
     * For maps (`maxLevel` off) a stored `on` reads as `off`: a row written by
     * hand, or brought back by a restore, must never put every member's home on
     * a map before that member chose it (D-072). Values are returned as words,
     * unchanged when within the service's limit, so the rest of the rule sees
     * exactly what was stored.
     */
    public static function capFor(string $service, ?string $value): ?string
    {
        if ($value === null || isset(self::SERVICES[$service]) === false) {
            return $value;
        }
        $max = self::rank(self::SERVICES[$service]['maxLevel']);
        if (self::rank($value) > $max) {
            return self::word($max);
        }

        return $value;
    }

    /**
     * The values a LEVEL (installation or organisation) may be set to for this
     * service, strictest first. The pages offer exactly these and both writers
     * refuse anything else.
     *
     * @return list<string>
     */
    public static function levelValuesFor(string $service): array
    {
        $max = isset(self::SERVICES[$service]) === true ? self::rank(self::SERVICES[$service]['maxLevel']) : 0;
        $out = [];
        foreach ([self::NO, self::OFF, self::ON] as $value) {
            if (self::rank($value) <= $max) {
                $out[] = $value;
            }
        }

        return $out;
    }

    /**
     * The strictest of the installation and every organisation.
     *
     * An organisation with NO ROW of its own (null) follows the installation, so
     * it adds nothing; an organisation WITH a row can only lower the answer,
     * because this is a minimum. That is the whole of "may tighten, never
     * loosen" (#668: the merged value is not used, so no layer can win over a
     * stricter one).
     *
     * @param list<?string> $organisationValues One entry per organisation; null = no row.
     */
    public static function ceilingFrom(?string $installation, array $organisationValues): int
    {
        $ceiling = self::rank($installation);
        foreach ($organisationValues as $value) {
            if ($value === null) {
                continue;
            }
            $ceiling = min($ceiling, self::rank($value));
        }

        return $ceiling;
    }

    /**
     * A member's own choice, cleaned: 'on', 'off', or null for "not chosen".
     * Anything else stored counts as 'off', the stricter of the two.
     */
    public static function choiceFrom(?string $stored): ?string
    {
        if ($stored === null) {
            return null;
        }
        $stored = trim($stored);
        if ($stored === self::ON) {
            return self::ON;
        }

        return self::OFF;
    }

    /**
     * ✅ THE RULE: is the service on for this member?
     *
     *   ceiling `no`               → no, whatever the member chose
     *   member chose off           → no
     *   member chose on            → yes (the ceiling is at least `off`)
     *   member chose nothing       → yes only if the ceiling is `on`
     *
     * @param list<?string> $organisationValues
     */
    public static function effectiveFrom(?string $installation, array $organisationValues, ?string $memberChoice): bool
    {
        $ceiling = self::ceilingFrom($installation, $organisationValues);
        if ($ceiling === 0) {
            return false;
        }
        $choice = self::choiceFrom($memberChoice);
        if ($choice === self::OFF) {
            return false;
        }
        if ($choice === self::ON) {
            return true;
        }

        return $ceiling >= 2;
    }

    /**
     * 👀 The weaker question D-072 asks of the person LOOKING at somebody else's
     * map: is it allowed for them at all, and have they NOT switched it off?
     *
     * @param list<?string> $organisationValues
     */
    public static function doesNotObjectFrom(?string $installation, array $organisationValues, ?string $memberChoice): bool
    {
        if (self::ceilingFrom($installation, $organisationValues) === 0) {
            return false;
        }

        return self::choiceFrom($memberChoice) !== self::OFF;
    }

    // 📖 =========================================================================
    // Reading the facts
    // ===========================================================================

    /**
     * Everything the rule needs about one person, read once per request.
     *
     * The organisations are the one whose page is OPEN plus every organisation
     * the person belongs to (`membershipsOf()`: every membership row still
     * switched on, whether or not the ACCOUNT or the organisation is, and every
     * organisation for a global administrator — so a global administrator's own
     * picture is held to the strictest organisation on the installation, which
     * is the conservative reading). A signed-out visitor (null) has only the
     * open organisation and no choice of their own.
     *
     * ANY READ THAT FAILS makes the whole answer "unreadable", and an unreadable
     * answer is `no` for every service — never "not chosen", which for a service
     * whose ceiling is `on` would mean yes. One line goes to PHP's error log per
     * request, so a broken table is visible without filling the log.
     *
     * @return array{readable:bool, installation:array<string,?string>,
     *               organisations:list<array{siteID:int, name:string, values:array<string,?string>}>,
     *               member:array<string,?string>}
     */
    public static function factsFor(\mysqli $db, ?int $userId, int $openSiteId): array
    {
        $cacheKey = (string) ($userId ?? 0) . '|' . $openSiteId;
        if (isset(self::$facts[$cacheKey]) === true) {
            return self::$facts[$cacheKey];
        }

        $empty = ['readable' => false, 'installation' => [], 'organisations' => [], 'member' => []];
        try {
            $facts = self::readFacts($db, $userId, $openSiteId, false);
        } catch (\Throwable $e) {
            self::logOnce('the outside-services settings could not be read, so every outside service is treated as '
                . 'not allowed for this request: ' . $e->getMessage());
            $facts = $empty;
        }
        self::$facts[$cacheKey] = $facts;

        return $facts;
    }

    /** Forget what this request has read: after a save, and between test cases. */
    public static function forget(): void
    {
        self::$facts  = [];
        self::$logged = false;
    }

    /**
     * The decision for one service and one person's facts, with the reason.
     *
     * @param array<string,mixed> $facts From factsFor().
     *
     * @return array{effective:bool, doesNotObject:bool, ceiling:int, choice:?string,
     *               installation:int, refusedBy:list<string>, readable:bool}
     */
    public static function decide(string $service, array $facts): array
    {
        if (isset(self::SERVICES[$service]) === false || ($facts['readable'] ?? false) !== true) {
            return ['effective' => false, 'doesNotObject' => false, 'ceiling' => 0, 'choice' => null,
                    'installation' => 0, 'refusedBy' => [], 'readable' => ($facts['readable'] ?? false) === true];
        }
        $installation = self::capFor($service, $facts['installation'][$service] ?? null);
        $values = [];
        $refusedBy = [];
        foreach ($facts['organisations'] as $organisation) {
            $value    = self::capFor($service, $organisation['values'][$service] ?? null);
            $values[] = $value;
            if ($value !== null && self::rank($value) === 0) {
                $refusedBy[] = (string) $organisation['name'];
            }
        }
        $choice = $facts['member'][$service] ?? null;

        return [
            'effective'     => self::effectiveFrom($installation, $values, $choice),
            'doesNotObject' => self::doesNotObjectFrom($installation, $values, $choice),
            'ceiling'       => self::ceilingFrom($installation, $values),
            'choice'        => self::choiceFrom($choice),
            'installation'  => self::rank($installation),
            'refusedBy'     => $refusedBy,
            'readable'      => true,
        ];
    }

    /**
     * 🚦 May the portal use this outside service on this request?
     *
     * With no subject, or the viewer as the subject, it is the ordinary rule for
     * the person signed in (or, signed out, for the open organisation alone).
     * With SOMEBODY ELSE as the subject, BOTH must allow it (D-072): the person
     * shown by the ordinary rule, and the person looking by "allowed and not
     * switched off". A subject that is not a positive account number is
     * refused — the portal does not know whose permission it would be using.
     */
    public static function allows(string $service, ?int $subjectUserId = null): bool
    {
        if (isset(self::SERVICES[$service]) === false) {
            return false;
        }
        try {
            $db = App::db();
        } catch (\Throwable $e) {
            self::logOnce('no database connection, so every outside service is treated as not allowed: ' . $e->getMessage());

            return false;
        }
        $viewer   = self::viewerId();
        $openSite = Site::id();
        $viewerDecision = self::decide($service, self::factsFor($db, $viewer, $openSite));

        if ($subjectUserId === null || ($viewer !== null && $subjectUserId === $viewer)) {
            return $viewerDecision['effective'];
        }
        if ($subjectUserId <= 0) {
            return false;
        }
        $subjectDecision = self::decide($service, self::factsFor($db, $subjectUserId, $openSite));

        return $subjectDecision['effective'] === true && $viewerDecision['doesNotObject'] === true;
    }

    // 🏗️ =========================================================================
    // Builders: an outside address exists only AFTER the decision
    // ===========================================================================

    /**
     * The Gravatar address for this person's picture, or null when it may not
     * be used. The person is `$user['userID']`; a row without one is refused,
     * because the portal would not know whose permission it was using.
     *
     * @param array<string,mixed> $user Needs userID and emailAddress.
     */
    public static function gravatarUrlIfAllowed(array $user, int $size): ?string
    {
        $subject = (int) ($user['userID'] ?? 0);
        $email   = trim((string) ($user['emailAddress'] ?? ''));
        if ($subject <= 0 || $email === '') {
            return null;
        }
        if (self::allows(self::GRAVATAR, $subject) === false) {
            return null;
        }
        $size = max(1, min(2048, $size));

        // Gravatar matches the MD5 of the lower-cased, trimmed address. `d=mp`
        // asks for its plain silhouette when the address has no picture there.
        return self::GRAVATAR_BASE . md5(strtolower($email)) . '?s=' . $size . '&d=mp';
    }

    /**
     * The sign-in provider's photo for this person, or null when it may not be
     * used OR is not an `https://` address on Google's picture hosts.
     *
     * The host test is not decoration: the page rule is widened only for that
     * family (SERVICES), so a photo stored from anywhere else would be refused
     * by the browser and draw a broken picture. Refusing it here draws the
     * silhouette instead. (An `http://` address is refused for the same reason
     * and because it would be sent unencrypted.)
     *
     * @param array<string,mixed> $user Needs userID and avatarPath.
     */
    public static function providerPhotoIfAllowed(array $user): ?string
    {
        $subject = (int) ($user['userID'] ?? 0);
        $path    = trim((string) ($user['avatarPath'] ?? ''));
        if ($subject <= 0 || self::isProviderPhotoAddress($path) === false) {
            return null;
        }
        if (self::allows(self::PROVIDER_PHOTO, $subject) === false) {
            return null;
        }

        return $path;
    }

    /** Whether a stored address is a sign-in photo this class knows how to permit. */
    public static function isProviderPhotoAddress(string $path): bool
    {
        if (str_starts_with($path, 'https://') === false) {
            return false;
        }
        $host = parse_url($path, PHP_URL_HOST);
        if (is_string($host) === false || $host === '') {
            return false;
        }
        $host = strtolower($host);

        return str_ends_with($host, self::PROVIDER_PHOTO_HOST_SUFFIX) === true
            && preg_match('/^[a-z0-9.-]+$/', $host) === 1;
    }

    /**
     * 🧱 The extra `img-src` sources the page rule needs for the person signed
     * in, as ' source source' (a leading space) or ''.
     *
     * Only the two picture services: those are drawn in the top bar on every
     * page, always of the viewer themselves. The map is not here — a map page
     * decides with `allows(MEMBER_MAP, $subject)` and asks `mapTileSource()`.
     * Signed out, nothing about a member is drawn, so the answer is ''.
     */
    public static function cspImgSources(): string
    {
        if (self::viewerId() === null) {
            return '';
        }
        $sources = [];
        foreach ([self::GRAVATAR, self::PROVIDER_PHOTO] as $service) {
            if (self::allows($service) === true) {
                foreach (self::SERVICES[$service]['imgSources'] as $source) {
                    $sources[] = $source;
                }
            }
        }

        return $sources === [] ? '' : ' ' . implode(' ', $sources);
    }

    /**
     * The tile source a member-map page widens `img-src` with, AFTER it has
     * asked `allows(MEMBER_MAP, $subject)`. Kept here so the directory pages
     * never hold the host themselves (the audit check counts it).
     */
    public static function mapTileSource(): string
    {
        return implode(' ', self::SERVICES[self::MEMBER_MAP]['imgSources']);
    }

    // 🏠 =========================================================================
    // A small group's meeting place (#809, D-120)
    // ===========================================================================

    /**
     * ✅ THE RULE for a map of where a small group meets, on FACTS (#647).
     *
     * The owner decided (D-120, 11 November 2026) that a meeting place is to be
     * treated as the HOST'S HOME, on the same terms as a member's own home map
     * (D-072, with the strictest-wins rule of D-058 and D-073). Nothing records
     * whose home a meeting place is, so D-120 names the person whose agreement
     * counts: WHOEVER ENTERED THE MEETING PLACE. A map is drawn only when ALL of
     * these hold:
     *
     *   1. somebody is recorded as having entered the place, AND they ticked
     *      "a map of this place may be shown" when they did. That tick is this
     *      place's "switched on"; an old group with nobody recorded has no
     *      agreement and gets no map (the safe reading — nobody agreed);
     *   2. that person is still somebody whose agreement can count: their
     *      account is switched on, and they are still a member of the group's
     *      organisation (or a global administrator). An ERASED account is
     *      switched off and loses its memberships, so an erasure takes the map
     *      away even though the group row still carries the tick;
     *   3. for that person, maps are allowed at all (the installation and EVERY
     *      organisation they belong to) and they have NOT switched maps off for
     *      themselves — the tick cannot loosen their own standing "off";
     *   4. for the person LOOKING, maps are allowed at all and they have not
     *      switched them off (D-072's question of the viewer).
     *
     * Point 3 is `doesNotObject` for the enterer, which is D-072's "effective"
     * with the tick standing in for "switched on": with the tick, the only ways
     * to a no are a level saying `no` or the person's own `off`.
     *
     * @param array<string,mixed> $entererDecision From decide(MEMBER_MAP, …) for the enterer.
     * @param array<string,mixed> $viewerDecision  From decide(MEMBER_MAP, …) for the viewer.
     */
    public static function meetingPlaceMapFrom(bool $agreed, bool $entererStillCounts, array $entererDecision, array $viewerDecision): bool
    {
        if ($agreed === false || $entererStillCounts === false) {
            return false;
        }
        if (($entererDecision['readable'] ?? false) !== true || ($viewerDecision['readable'] ?? false) !== true) {
            return false;
        }

        return ($entererDecision['doesNotObject'] ?? false) === true
            && ($viewerDecision['doesNotObject'] ?? false) === true;
    }

    /**
     * 🚦 May a small group's page draw a map of where the group meets?
     *
     * The ONE place this is decided (#809, D-120); `small-groups/group.php`
     * and `small-groups/meeting.php` ask it and then, only on a yes, widen the
     * page rule with `mapTileSource()`. Neither page names the tile host itself
     * (`tools/audit-checks/check_outside_services_gated.py`).
     *
     * ONE PLACE AT A TIME (D-123). A group may take turns meeting in several
     * hosts' homes; the caller passes THAT place's own row's host and
     * agreement, so one host's agreement never stands for another's home.
     *
     * @param int  $groupSiteId     The organisation the GROUP belongs to.
     * @param ?int $enteredById     `tblSmallGroups.placeEnteredByID`: who entered the place, or null.
     * @param bool $agreed          `tblSmallGroups.placeMapAgreed` = 1: they ticked "a map may be shown".
     *
     * ANY read that fails is a no, as everywhere in this class: a permission
     * that cannot be read must never read as granted.
     */
    public static function allowsMeetingPlaceMap(int $groupSiteId, ?int $enteredById, bool $agreed): bool
    {
        if ($agreed === false || $enteredById === null || $enteredById <= 0 || $groupSiteId <= 0) {
            return false;
        }
        try {
            $db = App::db();
        } catch (\Throwable $e) {
            self::logOnce('no database connection, so every outside service is treated as not allowed: ' . $e->getMessage());

            return false;
        }
        try {
            $stillCounts = self::entererStillCounts($db, $enteredById, $groupSiteId);
        } catch (\Throwable $e) {
            self::logOnce('whether the person who entered a meeting place still counts could not be read, so no '
                . 'meeting-place map is drawn on this request: ' . $e->getMessage());

            return false;
        }
        $openSite = Site::id();
        $viewer   = self::viewerId();
        $entererDecision = self::decide(self::MEMBER_MAP, self::factsFor($db, $enteredById, $openSite));
        $viewerDecision  = self::decide(self::MEMBER_MAP, self::factsFor($db, $viewer, $openSite));

        return self::meetingPlaceMapFrom($agreed, $stillCounts, $entererDecision, $viewerDecision);
    }

    /**
     * Point 2 of the meeting-place rule: is the person who entered the place
     * still somebody whose agreement can count? Their account is switched on,
     * AND they hold a switched-on membership of the group's organisation or are
     * a global administrator (who counts as a member of every organisation, as
     * `membershipsOf()` counts them). Throws on a failed read.
     */
    private static function entererStillCounts(\mysqli $db, int $userId, int $siteId): bool
    {
        $stmt = $db->prepare(
            'SELECT 1 FROM `tblUsers` `u` '
            . 'LEFT JOIN `tblUserSites` `us` ON `us`.`userID` = `u`.`userID` AND `us`.`siteID` = ? AND `us`.`isActive` = 1 '
            . 'WHERE `u`.`userID` = ? AND `u`.`isActive` = 1 AND (`us`.`userSiteID` IS NOT NULL OR `u`.`isRootAdmin` = 1) '
            . 'LIMIT 1'
        );
        $stmt->bind_param('ii', $siteId, $userId);
        $stmt->execute();
        $found = $stmt->get_result()->fetch_assoc() !== null;
        $stmt->close();

        return $found;
    }

    /**
     * Why the person signed in could not give a meeting-place map by ticking
     * the box, in plain words — or '' when nothing in the tiers stands in the
     * way. For the group's edit form, so a leader is never told a tick will do
     * something the rule will then refuse.
     */
    public static function meetingPlaceTickBlockedFor(): string
    {
        $viewer = self::viewerId();
        if ($viewer === null) {
            return 'nobody is signed in.';
        }
        try {
            $db = App::db();
        } catch (\Throwable $e) {
            return 'the portal could not read the settings that decide this, so it is treated as not allowed.';
        }
        $decision = self::decide(self::MEMBER_MAP, self::factsFor($db, $viewer, Site::id()));
        if (($decision['readable'] ?? false) !== true || ($decision['ceiling'] ?? 0) === 0) {
            return self::refusalReason($decision);
        }
        if (($decision['choice'] ?? null) === self::OFF) {
            return 'you have switched maps off for yourself on your own Outside services page.';
        }

        return '';
    }

    // ✍️ =========================================================================
    // Writers
    // ===========================================================================

    /**
     * 🔒 May the GENERAL settings editor (/settings) change, add or delete this
     * key? No, for every `privacy.outside.` key: its own page holds an
     * organisation to what the installation allows and records the change, and
     * the general editor would do neither. Names are compared without regard to
     * capitals, as the database does.
     */
    public static function lockedInGeneralEditor(string $key): bool
    {
        return strncasecmp(trim($key), 'privacy.outside.', 16) === 0;
    }

    /**
     * Save the INSTALLATION's values. Only fields that ARRIVED are in
     * `$posted` (#677); each must be no, off or on, or nothing is saved.
     *
     * Asks `$globalAdmin` itself as well as the handler: the installation and
     * an organisation may be different parties, so the boundary is stated at
     * the writer too (the #687 precedent).
     *
     * Tightening the installation below what an organisation chose is NOT
     * refused: tightening is the safe direction, and the reader already holds
     * every organisation to the stricter value.
     *
     * @param array<string,string> $posted service => value
     *
     * @return array{saved:bool, type:string, message:string, changes:list<string>}
     */
    public static function saveForInstallation(\mysqli $db, array $posted, bool $globalAdmin): array
    {
        if ($globalAdmin === false) {
            return self::refusal('Only a global administrator can change what the whole installation allows. Nothing was saved.');
        }
        $wanted = self::validated($posted, [self::NO, self::OFF, self::ON]);
        if ($wanted === null) {
            return self::refusal('Each choice has to be "not allowed", "off until a member switches it on" or '
                . '"on until a member switches it off". Nothing was saved.');
        }
        if ($wanted === []) {
            return ['saved' => true, 'type' => 'info', 'message' => 'Nothing was sent to save, so nothing changed.', 'changes' => []];
        }
        $beyond = self::beyondServiceLimit($wanted);
        if ($beyond !== '') {
            return self::refusal($beyond . ' Nothing was saved.');
        }

        try {
            $db->begin_transaction();
            $before  = self::storedAt($db, null, true);
            $changes = [];
            foreach ($wanted as $service => $value) {
                $old = $before[$service] ?? null;
                // "Did it CHANGE" is asked of the value as the page SHOWS it —
                // through capFor(), as the card fills its box — so a stored map
                // "on" (hand-written; it reads as "off") is not rewritten by an
                // untouched Save (#778, round 2).
                if (self::capFor($service, $old) === $value) {
                    continue;
                }
                self::upsert($db, null, $service, $value);
                $changes[] = self::SERVICES[$service]['name'] . ': ' . self::shownStored($old) . ' → ' . $value;
            }
            $db->commit();
        } catch (\Throwable $e) {
            self::rollbackQuietly($db);
            error_log('[WebMS-Intra] OutsideServices: the installation\'s choices could not be saved: ' . $e->getMessage());

            return self::refusal('The database refused the change. Nothing was saved.');
        }
        self::forget();

        return [
            'saved'   => true,
            'type'    => 'success',
            'message' => $changes === [] ? 'Saved. Nothing was different.' : 'Saved for the whole installation.',
            'changes' => $changes,
        ];
    }

    /**
     * Save ONE organisation's values. Only fields that ARRIVED are in
     * `$posted`; each must be inherit, no, off or on.
     *
     *   inherit  DELETES the organisation's own row, so it follows the
     *            installation again (#677: a stored value always wins the merge,
     *            so "follow" can only mean "no row");
     *   a value LOOSER than the installation is REFUSED with the reason, never
     *            quietly lowered (#668) — and only when it CHANGED: a value the
     *            organisation already had, which the installation has since
     *            tightened below, is left as it is (#687's lesson) and the
     *            reader holds it down.
     *
     * The installation's rows are read LIVE and LOCKED inside the transaction
     * that writes (#647, #687), so a concurrent tightening of the installation
     * cannot be missed by the check.
     *
     * @param array<string,string> $posted service => value
     *
     * @return array{saved:bool, type:string, message:string, changes:list<string>}
     */
    public static function saveForOrganisation(\mysqli $db, int $siteId, array $posted): array
    {
        if ($siteId <= 0) {
            return self::refusal('No organisation is open. Nothing was saved.');
        }
        $wanted = self::validated($posted, [self::INHERIT, self::NO, self::OFF, self::ON]);
        if ($wanted === null) {
            return self::refusal('Each choice has to be "follow the installation", "not allowed", "off until a member '
                . 'switches it on" or "on until a member switches it off". Nothing was saved.');
        }
        if ($wanted === []) {
            return ['saved' => true, 'type' => 'info', 'message' => 'Nothing was sent to save, so nothing changed.', 'changes' => []];
        }
        $beyond = self::beyondServiceLimit($wanted);
        if ($beyond !== '') {
            return self::refusal($beyond . ' Nothing was saved.');
        }

        try {
            $db->begin_transaction();
            $installation = self::storedAt($db, null, true);
            $own          = self::storedAt($db, $siteId, true);

            $refusals = [];
            foreach ($wanted as $service => $value) {
                if ($value === self::INHERIT) {
                    continue;
                }
                // 🧾 "Did it CHANGE" (#687) is asked of the value as the CARD
                //    SHOWS it: through capFor(), exactly as the page fills its
                //    box. Comparing the RAW row instead made a stored map "on"
                //    (shown as "off") read as changed on an untouched Save, judged
                //    against an installation "no", and refused the WHOLE save —
                //    losing a picture change made on the same form (#778, round 2).
                if (self::capFor($service, $own[$service] ?? null) === $value) {
                    continue;
                }
                $allowed = self::rank(self::capFor($service, $installation[$service] ?? null));
                if (self::rank($value) > $allowed) {
                    $refusals[] = '"' . self::SERVICES[$service]['name'] . '" cannot be set to "' . self::longWord($value)
                        . '": the installation allows no more than "' . self::longWord(self::word($allowed)) . '"';
                }
            }
            if ($refusals !== []) {
                $db->rollback();

                return self::refusal('An organisation may hold itself to less than the installation allows, never to '
                    . 'more. ' . implode('; ', $refusals) . '. Nothing was saved.');
            }

            $changes = [];
            foreach ($wanted as $service => $value) {
                $old = $own[$service] ?? null;
                if ($value === self::INHERIT) {
                    if ($old === null) {
                        continue;
                    }
                    self::deleteOrganisationRow($db, $siteId, $service);
                    $changes[] = self::SERVICES[$service]['name'] . ': ' . self::shownStored($old) . ' → follow the installation';
                    continue;
                }
                if (self::capFor($service, $old) === $value) {
                    continue;
                }
                self::upsert($db, $siteId, $service, $value);
                $changes[] = self::SERVICES[$service]['name'] . ': '
                    . ($old === null ? 'follow the installation' : self::shownStored($old)) . ' → ' . $value;
            }
            $db->commit();
        } catch (\Throwable $e) {
            self::rollbackQuietly($db);
            error_log('[WebMS-Intra] OutsideServices: organisation #' . $siteId . '\'s choices could not be saved: ' . $e->getMessage());

            return self::refusal('The database refused the change. Nothing was saved.');
        }
        self::forget();

        return [
            'saved'   => true,
            'type'    => 'success',
            'message' => $changes === [] ? 'Saved. Nothing was different.' : 'Saved for this organisation.',
            'changes' => $changes,
        ];
    }

    /**
     * Save ONE member's own choices. Only services that ARRIVED are touched,
     * one row each — never a rebuilt list of all of them, which is how #667's
     * page wiped the choices it did not draw.
     *
     *   keep    "keep my earlier choice": the service is left alone;
     *   follow  DELETES the member's row, so the organisations decide;
     *   off     always applied;
     *   on      equal to the STORED choice: not a change, so neither refused nor
     *           written (#687); a real change to "on" is REFUSED where the
     *           member's ceiling is `no` (or cannot be read), naming who refuses.
     *
     * A WITHDRAWAL OF CONSENT IS NEVER LOST (the owner's stronger reading of
     * D-058, round 4 of the independent check). A press is not all-or-nothing
     * any more: every "off" and every "follow" is applied, and only the "on"
     * changes that are above the ceiling are refused — those services are left
     * exactly as they were. The message says both what was saved and what was
     * refused and why. It used to refuse the whole press, so a real "on" made
     * stale by an organisation tightening while the page was open took an "off"
     * beside it down with it.
     *
     * "Is this a change?" is answered from the member's STORED choices, read on
     * their own (`memberChoices()`), so it is still answered when the ceiling
     * cannot be read. With the ceiling unreadable every withdrawal is applied,
     * every real "on" is refused, and the message says the settings could not be
     * read (round 4: reading the stored choice out of the ceiling's facts lost
     * it when that read failed, refused an untouched "on" and the "off" beside
     * it, with an untrue message). The ceiling is read LIVE from the database,
     * not from this request's memory.
     *
     * TWO SAVES AT ONCE ARE SERIALISED (round 5): one transaction, which first
     * locks the member's own `tblUsers` row and only then reads what is stored,
     * with an ORDINARY read whose snapshot is taken after that lock (round 6: a
     * locking read deadlocked two DIFFERENT members saving at once); and an
     * "off" is always written. A lock wait that runs out is rolled back and said
     * plainly ("another change to your account was being saved at the same
     * moment").
     *
     * @param array<string,string> $posted service => value
     *
     * @return array{saved:bool, type:string, message:string, changes:list<string>, refused:list<string>}
     */
    public static function saveMemberChoice(\mysqli $db, int $userId, int $openSiteId, array $posted): array
    {
        if ($userId <= 0) {
            return self::refusal('You are not signed in. Nothing was saved.') + ['refused' => []];
        }
        // "Keep my earlier choice" means: this service was not touched.
        $posted = array_filter($posted, static fn ($value): bool => $value !== self::KEEP);
        $wanted = self::validated($posted, [self::FOLLOW, self::ON, self::OFF]);
        if ($wanted === null) {
            return self::refusal('Each choice has to be "follow my organisations", "on" or "off". Nothing was saved.') + ['refused' => []];
        }
        if ($wanted === []) {
            return ['saved' => true, 'type' => 'info', 'message' => 'Nothing was sent to save, so nothing changed.', 'changes' => [], 'refused' => []];
        }

        // 🔒 ONE TRANSACTION, AND ITS FIRST ACT IS THE LOCK (#778 round 5; the
        //    #728 shape). Two saves at once used to read the stored choices
        //    OUTSIDE any transaction, each skip the "off" it believed was
        //    already stored, and so end with BOTH services on while each tab was
        //    told "saved" — a state neither asked for, and a lost withdrawal.
        //    The lock is on the member's own `tblUsers` row, the PARENT, because
        //    the preference rows being guarded may not exist yet. A lock outside
        //    a transaction is decoration (#697), so the transaction starts first;
        //    the stored choices are then read INSIDE it, after the lock (see
        //    step 1 for why an ordinary read is fresh there). A lock wait that
        //    runs out, or a deadlock, throws; it is caught below and ROLLED BACK
        //    with an honest message (#694 round 3: never leave the transaction
        //    open — `Logger::db()` shares this connection, so a refusal record
        //    written into an open transaction would be lost with it).
        $apply   = [];
        $refused = [];
        $changes = [];
        $stage   = 'lock';
        try {
            $db->begin_transaction();
            $lock = $db->prepare('SELECT `userID` FROM `tblUsers` WHERE `userID` = ? FOR UPDATE');
            $lock->bind_param('i', $userId);
            $lock->execute();
            $lock->get_result();
            $lock->close();

            // 1. What the member has STORED, read AFTER the lock with an ORDINARY
            //    read. It is fresh because of the ORDER, at both usual levels:
            //      REPEATABLE READ (the default): this is the transaction's first
            //        plain read, and InnoDB takes a transaction's snapshot at its
            //        first plain read — after the lock on the member's own row
            //        above (measured on MySQL 8.0/8.4 and MariaDB 11.4/12.3);
            //      READ COMMITTED: every plain read sees what is committed at
            //        that moment, and this one runs after the lock.
            //    Either way it sees every save of this member's that committed
            //    before we got the lock, and none can commit while we hold it.
            //    (Under SERIALIZABLE, which the portal promises nothing about,
            //    plain reads take shared locks; one neighbour deadlock in 60
            //    was measured on MySQL 8.0 there.) Round 5 used a LOCKING read here instead;
            //    that took gap locks reaching the next member's rows, and two
            //    DIFFERENT members saving at once deadlocked 36-40 times in 40
            //    (round 6, measured). Moving this read before the lock would
            //    silently lose that freshness — section T's T07 pins it.
            //    If this fails the writes would fail too, so nothing is attempted.
            $stage  = 'stored';
            $stored = self::memberChoices($db, $userId);

            // 2. The ceiling. A failed read counts as "not allowed": withdrawals
            //    need no ceiling; a real "on" cannot be granted on a failed read.
            $stage = 'ceiling';
            try {
                $facts = self::readFacts($db, $userId, $openSiteId, true);
            } catch (\Throwable $e) {
                error_log('[WebMS-Intra] OutsideServices: member #' . $userId . '\'s ceiling could not be read: ' . $e->getMessage());
                $facts = ['readable' => false, 'installation' => [], 'organisations' => [], 'member' => []];
            }

            // 3. Sort the press: what is applied, and which real "on" is refused.
            foreach ($wanted as $service => $value) {
                $old = $stored[$service] ?? null;
                if ($value === self::ON) {
                    if ($old === self::ON) {
                        continue; // not a change: neither refused nor written
                    }
                    $decision = self::decide($service, $facts);
                    if ($decision['ceiling'] === 0) {
                        $refused[] = '"' . self::SERVICES[$service]['name'] . '" was not switched on, because '
                            . self::refusalReason($decision);
                        continue; // left exactly as it was
                    }
                }
                $apply[$service] = $value;
            }

            // 4. Write what is applied.
            $stage = 'write';
            foreach ($apply as $service => $value) {
                $old = $stored[$service] ?? null;
                if ($value === self::FOLLOW) {
                    // Nothing stored: nothing to delete. The DELETE is skipped,
                    // not merely harmless — on a row that does not exist it
                    // takes a GAP lock reaching the NEXT member's rows, and two
                    // different members saving at once then deadlocked (round 6).
                    if ($old === null) {
                        continue;
                    }
                    $stmt = $db->prepare('DELETE FROM `tblUserOutsideServicePref` WHERE `userID` = ? AND `serviceKey` = ?');
                    $stmt->bind_param('is', $userId, $service);
                    $stmt->execute();
                    $gone = $stmt->affected_rows;
                    $stmt->close();
                    if ($gone > 0) {
                        $changes[] = self::SERVICES[$service]['name'] . ': follow my organisations';
                    }
                    continue;
                }
                // 🧷 A BELT AS WELL AS THE LOCK: an "off" is ALWAYS written, even
                //    when it is already stored, so no earlier read can make a
                //    withdrawal "not a change" and drop it. It is LISTED as a
                //    change only when it was not already stored, so an untouched
                //    "off" is no fresh consent record (H31).
                if ($old === $value && $value !== self::OFF) {
                    continue;
                }
                $stmt = $db->prepare(
                    'INSERT INTO `tblUserOutsideServicePref` (`userID`, `serviceKey`, `choice`) VALUES (?, ?, ?) '
                    . 'ON DUPLICATE KEY UPDATE `choice` = VALUES(`choice`)'
                );
                $stmt->bind_param('iss', $userId, $service, $value);
                $stmt->execute();
                $stmt->close();
                if ($old !== $value) {
                    $changes[] = self::SERVICES[$service]['name'] . ': ' . $value;
                }
            }
            $db->commit();
        } catch (\Throwable $e) {
            self::rollbackQuietly($db);
            error_log('[WebMS-Intra] OutsideServices: member #' . $userId . '\'s choices could not be saved (' . $stage . '): ' . $e->getMessage());
            $refusedLines = $refused === [] ? '' : ' ' . implode(' ', $refused);
            if ((int) $e->getCode() === 1205 || (int) $e->getCode() === 1213) {
                // "Another change to your ACCOUNT": the other writer may be a
                //    profile edit or anything else touching the member's row,
                //    not necessarily another save of these choices (round 6).
                return self::refusal('Another change to your account was being saved at the same moment, and this one '
                    . 'could not wait for it. Nothing was saved; please try again.' . $refusedLines) + ['refused' => $refused];
            }
            if ($stage === 'stored') {
                return self::refusal('Your choices could not be read, so nothing was saved. Please try again.') + ['refused' => []];
            }

            return self::refusal('The database refused the change. Nothing was saved.' . $refusedLines) + ['refused' => $refused];
        }
        self::forget();

        if ($refused === []) {
            return [
                'saved'   => true,
                'type'    => 'success',
                'message' => $changes === [] ? 'Saved. Nothing was different.' : 'Your choices are saved.',
                'changes' => $changes,
                'refused' => [],
            ];
        }

        $savedPart = $changes === []
            ? 'Nothing was saved.'
            : 'Saved: ' . implode('; ', $changes) . '.';

        return [
            'saved'   => $changes !== [],
            'type'    => 'warning',
            'message' => $savedPart . ' ' . implode(' ', $refused),
            'changes' => $changes,
            'refused' => $refused,
        ];
    }

    /**
     * How many LEVELS refuse a service: the installation (one, if it says no)
     * plus each organisation that says no. For "if that changed" / "if those
     * changed" on the member page.
     *
     * @param array<string,mixed> $decision From decide().
     */
    public static function refuserCount(array $decision): int
    {
        if (($decision['readable'] ?? false) !== true) {
            return 1;
        }

        return (($decision['installation'] ?? 0) === 0 ? 1 : 0) + count($decision['refusedBy'] ?? []);
    }

    /**
     * Why a service is not allowed for somebody, in plain words. Names the
     * organisations that refuse; the installation is named as "whoever runs this
     * portal", because the people running it may not be any organisation.
     *
     * @param array<string,mixed> $decision From decide().
     */
    public static function refusalReason(array $decision): string
    {
        if (($decision['readable'] ?? false) !== true) {
            return 'the portal could not read the settings that decide this, so it is treated as not allowed.';
        }
        // Every level that refuses is named — never only the first found, or a
        // member told "whoever runs this portal" would expect a change there to
        // be enough (#778, round 4).
        $parts = [];
        if (($decision['installation'] ?? 0) === 0) {
            $parts[] = 'whoever runs this portal does not allow it for anybody';
        }
        $by = $decision['refusedBy'] ?? [];
        if ($by !== []) {
            $parts[] = (count($by) === 1 ? 'an organisation you belong to, ' : 'organisations you belong to, ')
                . implode(', ', $by) . ', ' . (count($by) === 1 ? 'does' : 'do') . ' not allow it';
        }
        if ($parts === []) {
            return 'it is not allowed.';
        }

        return implode(', and ', $parts) . '.';
    }

    /** The longer words for a value, as the pages show them. */
    public static function longWord(string $value): string
    {
        return match ($value) {
            self::ON      => 'on until a member switches it off',
            self::OFF     => 'off until a member switches it on',
            self::NO      => 'not allowed',
            self::INHERIT => 'follow the installation',
            self::FOLLOW  => 'follow my organisations',
            default       => $value,
        };
    }

    /**
     * What one level stores for every service: the installation (null) or one
     * organisation's OWN rows. Values exactly as stored; a missing row is
     * absent from the answer. For the pages' prefill and the writers.
     *
     * @return array<string,string>
     */
    public static function storedAt(\mysqli $db, ?int $siteId, bool $lock = false): array
    {
        $keys   = self::settingKeys();
        $marks  = implode(', ', array_fill(0, count($keys), '?'));
        $where  = $siteId === null ? '`siteScope` = -1' : '`siteID` = ?';
        $stmt   = $db->prepare(
            'SELECT `settingKey`, `settingValue` FROM `tblSettings` WHERE `settingKey` IN (' . $marks . ') AND ' . $where
            . ($lock === true ? ' FOR UPDATE' : '')
        );
        $types  = str_repeat('s', count($keys));
        $params = $keys;
        if ($siteId !== null) {
            $types   .= 'i';
            $params[] = $siteId;
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $found  = [];
        while ($row = $result->fetch_assoc()) {
            $service = self::serviceForKey((string) $row['settingKey']);
            if ($service !== null) {
                $found[$service] = (string) ($row['settingValue'] ?? '');
            }
        }
        $stmt->close();

        return $found;
    }

    /**
     * The member's own stored choices, cleaned ('on'/'off'). Read ONLY by
     * saveMemberChoice(), which asks it "is this a change?" (the member page
     * reads the choices through factsFor() and readFacts()' own query).
     *
     * An ORDINARY read, never a locking one, and there is deliberately no option
     * to make it one (round 8 removed a `$lock` parameter nothing used): a
     * locking read of "every row for this member" takes a gap lock reaching the
     * next member's rows, which deadlocked two members saving at once (round 6).
     * The writer relies on its parent-row lock and the read ORDER instead.
     *
     * @return array<string,string>
     */
    public static function memberChoices(\mysqli $db, int $userId): array
    {
        $stmt = $db->prepare('SELECT `serviceKey`, `choice` FROM `tblUserOutsideServicePref` WHERE `userID` = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $found  = [];
        while ($row = $result->fetch_assoc()) {
            $service = (string) $row['serviceKey'];
            if (isset(self::SERVICES[$service]) === true) {
                $found[$service] = (string) self::choiceFrom((string) $row['choice']);
            }
        }
        $stmt->close();

        return $found;
    }

    // 🛠️ =========================================================================
    // Internal helpers
    // ===========================================================================

    /**
     * Read the facts. `$live` reads the installation from the DATABASE rather
     * than the request's settings layer (the writers want what is stored now).
     * Throws on any failure; factsFor() turns that into "unreadable".
     *
     * @return array<string,mixed>
     */
    private static function readFacts(\mysqli $db, ?int $userId, int $openSiteId, bool $live): array
    {
        // 1. The installation.
        $installation = [];
        if ($live === true) {
            $installation = self::storedAt($db, null);
        } else {
            $layer = InstallationSettings::layerOrNull();
            if ($layer === null) {
                throw new \RuntimeException('the installation-wide settings were not loaded at start-up');
            }
            foreach (self::SERVICES as $service => $about) {
                $value = self::layerValue($layer, $about['setting']);
                if ($value !== null) {
                    $installation[$service] = $value;
                }
            }
        }

        // 2. The organisations: the open one, and every one the person belongs to.
        $siteIds = [];
        if ($openSiteId > 0) {
            $siteIds[$openSiteId] = true;
        }
        if ($userId !== null && $userId > 0) {
            foreach (self::membershipsOf($db, $userId) as $own) {
                $siteIds[$own] = true;
            }
        }
        $organisations = [];
        if ($siteIds !== []) {
            $ids       = array_map('intval', array_keys($siteIds));
            $keys      = self::settingKeys();
            $siteMarks = implode(', ', array_fill(0, count($ids), '?'));
            $keyMarks  = implode(', ', array_fill(0, count($keys), '?'));
            $stmt = $db->prepare(
                'SELECT `s`.`siteID`, `s`.`siteName`, `st`.`settingKey`, `st`.`settingValue` '
                . 'FROM `tblSites` `s` '
                . 'LEFT JOIN `tblSettings` `st` ON `st`.`siteID` = `s`.`siteID` AND `st`.`settingKey` IN (' . $keyMarks . ') '
                . 'WHERE `s`.`siteID` IN (' . $siteMarks . ')'
            );
            $params = array_merge($keys, $ids);
            $stmt->bind_param(str_repeat('s', count($keys)) . str_repeat('i', count($ids)), ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            $bySite = [];
            while ($row = $result->fetch_assoc()) {
                $id = (int) $row['siteID'];
                if (isset($bySite[$id]) === false) {
                    $bySite[$id] = ['siteID' => $id, 'name' => (string) $row['siteName'], 'values' => []];
                }
                if ($row['settingKey'] !== null) {
                    $service = self::serviceForKey((string) $row['settingKey']);
                    if ($service !== null) {
                        $bySite[$id]['values'][$service] = (string) ($row['settingValue'] ?? '');
                    }
                }
            }
            $stmt->close();
            $organisations = array_values($bySite);
        }

        // 3. The person's own choices.
        $member = [];
        if ($userId !== null && $userId > 0) {
            $stmt = $db->prepare('SELECT `serviceKey`, `choice` FROM `tblUserOutsideServicePref` WHERE `userID` = ?');
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $member[(string) $row['serviceKey']] = (string) $row['choice'];
            }
            $stmt->close();
        }

        return ['readable' => true, 'installation' => $installation, 'organisations' => $organisations, 'member' => $member];
    }

    /**
     * 🏢 Every organisation a person belongs to, FOR THIS RULE.
     *
     * NOT `Site::organisationsFor()`, deliberately. That answers "which
     * organisations may this person use", so it returns nothing for an account
     * that has been switched off (`tblUsers.isActive = 0`) and skips an
     * organisation that is itself switched off. Both are right for choosing an
     * organisation and wrong here: a switched-off account keeps its memberships
     * (`admin/users/save.php` changes `isActive` alone), the directory still
     * shows such a person, and organisation B's "no" is about them whether or not
     * they can sign in today. Using that answer dropped B's "no" for exactly
     * such a person (#778, round 1 of the independent check).
     *
     * What still counts as "no longer a member" is a membership row switched off
     * (`tblUserSites.isActive = 0`): that is how a person is taken out of an
     * organisation. A global administrator counts as a member of every
     * organisation, as `organisationsFor()` counts them; the strictest of all of
     * them is the conservative reading.
     *
     * @return list<int>
     */
    private static function membershipsOf(\mysqli $db, int $userId): array
    {
        $stmt = $db->prepare(
            'SELECT `s`.`siteID` FROM `tblSites` `s` '
            . 'LEFT JOIN `tblUserSites` `us` ON `us`.`siteID` = `s`.`siteID` AND `us`.`userID` = ? AND `us`.`isActive` = 1 '
            . 'LEFT JOIN `tblUsers` `u` ON `u`.`userID` = ? '
            . 'WHERE `us`.`userSiteID` IS NOT NULL OR `u`.`isRootAdmin` = 1'
        );
        $stmt->bind_param('ii', $userId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $ids    = [];
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int) $row['siteID'];
        }
        $stmt->close();

        return $ids;
    }

    /** One value out of the nested settings layer, or null. */
    private static function layerValue(array $layer, string $dotKey): ?string
    {
        $node = $layer;
        foreach (explode('.', $dotKey) as $part) {
            if (is_array($node) === false || array_key_exists($part, $node) === false) {
                return null;
            }
            $node = $node[$part];
        }

        return is_scalar($node) === true ? (string) $node : null;
    }

    /** @return list<string> Every service's settings key. */
    private static function settingKeys(): array
    {
        return array_values(array_map(static fn (array $about): string => $about['setting'], self::SERVICES));
    }

    /** The service a settings key belongs to, ignoring capitals as the database does. */
    private static function serviceForKey(string $key): ?string
    {
        foreach (self::SERVICES as $service => $about) {
            if (strcasecmp($about['setting'], $key) === 0) {
                return $service;
            }
        }

        return null;
    }

    /**
     * Only known services, each with an allowed value; null if anything posted is
     * outside that, so a mistyped value refuses the whole save.
     *
     * @param array<string,mixed> $posted
     * @param list<string>        $allowed
     *
     * @return array<string,string>|null
     */
    private static function validated(array $posted, array $allowed): ?array
    {
        $out = [];
        foreach ($posted as $service => $value) {
            if (isset(self::SERVICES[$service]) === false || is_string($value) === false
                || in_array($value, $allowed, true) === false) {
                return null;
            }
            $out[(string) $service] = $value;
        }

        return $out;
    }

    /**
     * The refusal for a level value above what the SERVICE allows at all (a map
     * "on until a member switches it off", D-072), or '' when there is none.
     * Asked of every posted value, changed or not: the pages never offer such a
     * value, so only a hand-made request can carry one.
     *
     * @param array<string,string> $wanted
     */
    private static function beyondServiceLimit(array $wanted): string
    {
        $refusals = [];
        foreach ($wanted as $service => $value) {
            if (in_array($value, [self::NO, self::OFF, self::ON], true) === false) {
                continue;
            }
            if (in_array($value, self::levelValuesFor($service), true) === false) {
                $refusals[] = '"' . self::SERVICES[$service]['name'] . '" cannot be set to "' . self::longWord($value)
                    . '": a map of a member\'s home is drawn only once that member has switched it on themselves, so it '
                    . 'can be allowed ("off until a member switches it on") or not allowed, never on by default';
            }
        }

        return implode('; ', $refusals) === '' ? '' : implode('; ', $refusals) . '.';
    }

    /** Write one row at one level (null = the installation). */
    private static function upsert(\mysqli $db, ?int $siteId, string $service, string $value): void
    {
        $key  = self::SERVICES[$service]['setting'];
        $stmt = $db->prepare(
            'INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`, `updatedAt`) '
            . 'VALUES (?, ?, ?, ?, 0, NOW()) '
            . 'ON DUPLICATE KEY UPDATE `settingValue` = VALUES(`settingValue`), `updatedAt` = NOW()'
        );
        $default = self::OFF;
        $stmt->bind_param('isss', $siteId, $key, $value, $default);
        $stmt->execute();
        $stmt->close();
    }

    /** Remove an organisation's own row, so it follows the installation again. */
    private static function deleteOrganisationRow(\mysqli $db, int $siteId, string $service): void
    {
        $key  = self::SERVICES[$service]['setting'];
        $stmt = $db->prepare('DELETE FROM `tblSettings` WHERE `siteID` = ? AND `settingKey` = ?');
        $stmt->bind_param('is', $siteId, $key);
        $stmt->execute();
        $stmt->close();
    }

    /** The person signed in, or null. */
    private static function viewerId(): ?int
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }
        $id = (int) ($_SESSION['user_id'] ?? 0);

        return $id > 0 ? $id : null;
    }

    /** How a stored value reads in a list of changes. */
    private static function shownStored(?string $stored): string
    {
        if ($stored === null) {
            return '(nothing stored)';
        }

        return self::word(self::rank($stored));
    }

    /** @return array{saved:bool, type:string, message:string, changes:list<string>} */
    private static function refusal(string $why): array
    {
        return ['saved' => false, 'type' => 'danger', 'message' => $why, 'changes' => []];
    }

    private static function rollbackQuietly(\mysqli $db): void
    {
        try {
            $db->rollback();
        } catch (\Throwable $ignored) {
            // Nothing more can be done; the caller reports the failure.
        }
    }

    private static function logOnce(string $line): void
    {
        if (self::$logged === true) {
            return;
        }
        self::$logged = true;
        error_log('[WebMS-Intra] OutsideServices: ' . $line);
    }
}
