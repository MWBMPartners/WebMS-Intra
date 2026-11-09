<?php
// Path: _core/PublicWebsite.php
/**
 * -----------------------------------------------------------------------------
 * Who may put an event on the PUBLIC WEBSITE, and how a change is judged 🌐
 * -----------------------------------------------------------------------------
 * #493, package P1. The organisation's public website — the public door
 * (`Portal\Core\PublicDoor`: its events feed and, since P4b, the script a
 * website pastes in, which replaced the old countdown widget, D-084), the
 * staff door's `sitemap.xml` and the search-engine markup on an event's page
 * (D-089) — shows an event only when
 * `Portal\Core\EventVisibility` says so in its "website" mode. That class is
 * still the ONE place that decides who may SEE an event. This class decides
 * the three things around it that it must not:
 *
 *   1. WHO MAY CHANGE the switch and the dates (`mayPublish()`, D-077);
 *   2. whether a posted change is ALLOWED and well formed, judged on facts so
 *      the form, its handler and the events API cannot disagree
 *      (`judgeEventFields()`, the #647 shape) — and, since #493 P2a, the same
 *      judgement for a noticeboard poster (`judgePosterFields()`), with the
 *      ONE rule for which posters are on the website (`posterWhere()`);
 *   3. how an event COPIED IN from an outside calendar reaches the website —
 *      the installation's rule capping the organisation's (`copiedRuleFor()`,
 *      `saveRuleForInstallation()`, `saveRuleForOrganisation()`; D-080, D-081).
 *
 * THE DECISIONS IT FOLLOWS (docs/decisions/)
 * -----------------------------------------------------------------------------
 *   D-057  nothing is public until somebody ticks it, one item at a time.
 *   D-077  a new permission, `website.publish`, re-pointable on the Roles
 *          page; out of the box only the organisation's ADMINISTRATORS hold it.
 *   D-078  a switch AND optional "from"/"until" dates; on the website only
 *          while the switch is on and today is inside the dates.
 *   D-080  copied-in events by "one box per calendar" or "one tick per event",
 *          chosen at both levels; D-081: the installation's choice CAPS the
 *          organisation's ("one tick per event" is the stricter).
 *
 * WHAT IT CANNOT DO, said plainly
 * -----------------------------------------------------------------------------
 *   - It cannot pull back a copy already taken: the public door's answers are
 *     cached for a minute by anything in between, the sitemap for an hour, and a
 *     search engine keeps what it took.
 *   - FOR EVENTS the permission decides almost nothing, and that is a
 *     measured fact, not a design choice: every page that writes an event
 *     (`calendar/manage/*`, the events API in session mode) is itself open to
 *     administrators only, and an administrator of the organisation already
 *     holds the permission; the one thing it decides there is an API key (a
 *     key is not a person and is refused, as #485 refuses one). FOR POSTERS
 *     it decides who may change them: Noticeboard, Public website (#493 P2a)
 *     is open to anybody signed in, so there it lets a Website Editor in and
 *     refuses a member who does not hold it.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/493
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

final class PublicWebsite
{
    /**
     * The settings key for the copied-in rule. It begins `public.`, which the
     * general settings editor refuses to anybody but a global administrator
     * (`4eba0ee`); and since round 7 of #493 P1's check the editor refuses
     * this key to EVERYBODY, on changing, adding and deleting alike
     * (`lockedInGeneralEditor()`), because its own card re-works every outside
     * calendar when it is saved and the editor would not. Whatever any row
     * says, `copiedRuleFor()` holds every organisation to the stricter of the
     * installation's and its own.
     */
    public const RULE_KEY = 'public.events.copiedRule';

    /**
     * #493 P3: the three settings Admin → Public website keeps (D-082, D-094,
     * D-095). Declared HERE, beside `RULE_KEY`, because this class's
     * `lockedInGeneralEditor()` refuses them in the general settings editor
     * and the settings-locks self-test finds a lock's keys by reading the
     * lock method's own `self::` constants. `PublicWebsiteSetup` is the ONE
     * place that reads and writes them.
     */
    public const WEBSITE_ADDRESS_KEY = 'public.website.address';
    public const FRAME_ORIGINS_KEY   = 'public.frame.origins';
    public const FRAME_PERMITTED_KEY = 'public.frame.permitted';

    /**
     * D-087's two settings for the public door on a TEST copy (#493 P4a):
     * the installation's limit ('testers' or 'either', installation row
     * only) and the organisation's own choice ('testers' or 'anybody', its
     * own row only). Locked out of the general settings editor HERE, beside
     * the others, for the same reason: round 1 of P4a's check found that a
     * global administrator could otherwise add or change them on Admin,
     * Settings and open a test copy's door to anybody with no card, no check
     * and no record of the decision. `PublicWebsiteSetup` reads them; the
     * card that writes them is package P4d.
     */
    public const DOOR_TEST_PERMITTED_KEY = 'public.door.testCopies.permitted';
    public const DOOR_TEST_OPEN_KEY      = 'public.door.testCopies.open';

    /** An organisation's "follow the installation": its own row is deleted. */
    public const INHERIT = 'inherit';

    /** The fields of an event this class judges, in the order they are logged. */
    public const EVENT_FIELDS = ['showOnWebsite', 'websiteFrom', 'websiteUntil'];

    /** What a brand-new event starts with: OFF, no dates (D-057). */
    public const NEW_EVENT_VALUES = ['showOnWebsite' => 0, 'websiteFrom' => null, 'websiteUntil' => null];

    // =========================================================================
    // 1️⃣ Who may change it (D-077)
    // =========================================================================

    /**
     * May this person turn an event's or a noticeboard poster's website switch
     * or dates on or off, in this organisation?
     *
     *   - an ADMINISTRATOR OF THIS ORGANISATION may, always — D-077's "out of
     *     the box only that organisation's administrators hold it", and
     *     D-017's "an organisation's global administrator has automatic access
     *     to everything". That is EITHER `EventVisibility::isAdminOfSite()` (a
     *     global administrator, or an active site administrator of THIS
     *     organisation, with an active account) OR the portal-wide `isAdmin`
     *     flag on an active account that BELONGS to this organisation
     *     (`Site::isOwnOrganisation()`, an active membership row).
     *
     *     ⚠️ THE SECOND GROUND IS NOT OPTIONAL, and leaving it out was round
     *        1's first finding. Admin → Users makes an administrator by
     *        setting that portal-wide flag, so on a single-organisation
     *        installation EVERY administrator after the first holds only it
     *        (#616's reasoning). Without it they could open and save Manage
     *        Events but never tick the box — a false refusal. It counts only
     *        where the person really belongs, as #616's morning summary does;
     *   - anybody else only through the permission `website.publish`, which
     *     ships pointing at the Website Editor role and which an organisation
     *     can re-point at any role on its Roles page.
     *
     * Read LIVE from the database, so a role taken away takes effect on the
     * very next request.
     *
     * @param \mysqli $db     The connection.
     * @param int     $userId The account, or 0 for nobody (an API key).
     * @param int     $siteId The organisation the event or poster belongs to.
     */
    public static function mayPublish(\mysqli $db, int $userId, int $siteId): bool
    {
        if ($userId <= 0 || $siteId <= 0) {
            return false;
        }
        if (EventVisibility::isAdminOfSite($db, $userId, $siteId) === true) {
            return true;
        }
        if (self::holdsPortalWideAdminFlag($db, $userId) === true && Site::isOwnOrganisation($userId, $siteId, $db) === true) {
            return true;
        }

        // `false` for the global-administrator flag: a global administrator was
        // answered just above, from the database rather than from a session.
        return Capabilities::allows($db, $userId, $siteId, Capabilities::WEBSITE_PUBLISH, false);
    }

    // =========================================================================
    // 2️⃣ Judging a change to one event
    // =========================================================================

    /**
     * Judge what somebody sent for an event's three website fields.
     *
     * ONLY FIELDS THAT ARRIVED ARE IN `$arrived` (#677): a field that was not
     * sent is "leave it as it is", never "empty". The form sends a hidden
     * marker beside its controls so the handler can tell an unticked box (sent
     * as nothing) from a form that drew no box at all; a person who may not
     * publish is drawn no controls, so their ordinary Save sends none of these
     * fields and changes nothing.
     *
     * ASK WHETHER IT CHANGED (#687): a value equal to what is stored is not a
     * change, so it is neither refused nor written. Only a real change by
     * somebody who may not publish is refused — never quietly dropped or
     * clamped (#668), because a person who believes they took an event off
     * the website when they did not is the worst outcome here.
     *
     * @param array<string,mixed>                                                      $arrived    field => raw value
     * @param array{showOnWebsite:int, websiteFrom:?string, websiteUntil:?string}     $stored     current values (NEW_EVENT_VALUES for a new event)
     * @param bool                                                                     $mayPublish from mayPublish()
     *
     * @return array{ok:bool, kind:string, reason:string, values:array{showOnWebsite:int, websiteFrom:?string, websiteUntil:?string}, changed:bool, changes:list<string>, attempted?:list<string>}
     *         `attempted` is present only on a 'forbidden' refusal: the changes
     *         that were asked for and refused, for the record of the refusal.
     *         kind: '' when ok, 'invalid' for a value that is not one, 'forbidden' for a change the person may not make.
     */
    public static function judgeEventFields(array $arrived, array $stored, bool $mayPublish): array
    {
        return self::judgeFields($arrived, $stored, $mayPublish, 'an event');
    }

    /**
     * The same judgement for a noticeboard poster's three website fields
     * (#493 P2a). ONE rule for both: only the noun in a refusal differs, so a
     * poster can never be judged by a looser copy (#768's lesson — copies of
     * one rule drift apart). See judgeEventFields() for what each part means.
     *
     * @param array<string,mixed>                                                  $arrived
     * @param array{showOnWebsite:int, websiteFrom:?string, websiteUntil:?string} $stored
     *
     * @return array{ok:bool, kind:string, reason:string, values:array{showOnWebsite:int, websiteFrom:?string, websiteUntil:?string}, changed:bool, changes:list<string>, attempted?:list<string>}
     */
    public static function judgePosterFields(array $arrived, array $stored, bool $mayPublish): array
    {
        return self::judgeFields($arrived, $stored, $mayPublish, 'a poster');
    }

    /**
     * The one judgement behind judgeEventFields() and judgePosterFields().
     * `$noun` ('an event' / 'a poster') appears only in the refusal's words.
     *
     * @param array<string,mixed> $arrived
     * @param array<string,mixed> $stored
     *
     * @return array<string,mixed>
     */
    private static function judgeFields(array $arrived, array $stored, bool $mayPublish, string $noun): array
    {
        $was = [
            'showOnWebsite' => ((int) ($stored['showOnWebsite'] ?? 0)) === 1 ? 1 : 0,
            'websiteFrom'   => self::storedDate($stored['websiteFrom'] ?? null),
            'websiteUntil'  => self::storedDate($stored['websiteUntil'] ?? null),
        ];
        $wanted = $was;

        foreach ($arrived as $field => $raw) {
            if ($field === 'showOnWebsite') {
                $flag = self::flagFrom($raw);
                if ($flag === null) {
                    return self::refused('invalid', $was, 'showOnWebsite must be true or false. Nothing was saved.');
                }
                $wanted['showOnWebsite'] = $flag;
            } elseif ($field === 'websiteFrom' || $field === 'websiteUntil') {
                $date = self::dateFrom($raw);
                if ($date === false) {
                    return self::refused('invalid', $was, ($field === 'websiteFrom' ? '"Show on the website from"' : '"Show on the website until"')
                        . ' must be a real date written YYYY-MM-DD (from the year 1000), or left empty for no limit. Nothing was saved.');
                }
                $wanted[$field] = $date;
            }
            // Any other key is not this class's business and is ignored here.
        }

        // 📅 The two dates the right way round. Equal is allowed: one day.
        //    Compared as TEXT, which sorts like the calendar for Y-m-d.
        if ($wanted['websiteFrom'] !== null && $wanted['websiteUntil'] !== null
            && $wanted['websiteUntil'] < $wanted['websiteFrom']) {
            return self::refused('invalid', $was, 'The last day on the public website cannot be before the first. Nothing was saved.');
        }

        $changes = self::changeWords($was, $wanted);
        if ($changes === []) {
            return ['ok' => true, 'kind' => '', 'reason' => '', 'values' => $was, 'changed' => false, 'changes' => []];
        }
        if ($mayPublish === false) {
            return self::refused(
                'forbidden',
                $was,
                'Only an administrator of this organisation, or somebody given "Choose what appears on the public website" '
                . 'on the Roles page, can change whether ' . $noun . ' is on the public website or for which days. Nothing was saved.'
            ) + ['attempted' => $changes];
        }

        return ['ok' => true, 'kind' => '', 'reason' => '', 'values' => $wanted, 'changed' => true, 'changes' => $changes];
    }

    /**
     * Record an allowed change in the activity log — the switch turned on, off,
     * or the dates moved — naming the event and the person (`Logger::activity()`
     * fills `userID`, so an erasure still matches one column, #626). Every
     * act that puts content in front of the world, or takes it away, leaves a
     * trail (the #493 plan, 3.3). Written AFTER the change is stored.
     *
     * @param list<string> $changes from judgeEventFields()
     */
    public static function recordChange(int $eventId, string $eventName, array $changes, ?int $userId, string $where): void
    {
        if ($changes === []) {
            return;
        }
        $type = 'EventWebsiteDatesChanged';
        foreach ($changes as $line) {
            if (str_starts_with($line, 'switched ON') === true) {
                $type = 'EventShownOnWebsite';
            } elseif (str_starts_with($line, 'switched OFF') === true) {
                $type = 'EventHiddenFromWebsite';
            }
        }
        Logger::activity(
            $type,
            'Event #' . $eventId . ' "' . mb_substr($eventName, 0, 120) . '" on the public website: '
            . implode('; ', $changes) . ' (' . $where . ').',
            $userId
        );
    }

    /**
     * Record a REFUSED change. A request to put something in front of the
     * world, or take it away, by somebody who may not, crosses a line between
     * parties and must leave a trace (#668's rule for refusals).
     *
     * @param array<string,mixed> $judged from judgeEventFields(), not ok
     */
    public static function recordRefusal(?int $eventId, string $eventName, array $judged, ?int $userId, string $where): void
    {
        $what = $judged['attempted'] ?? [];
        Logger::activity(
            'EventWebsiteChangeRefused',
            ($eventId !== null ? 'Event #' . $eventId . ' "' : 'A new event "') . mb_substr($eventName, 0, 120) . '": a change to the public website was refused ('
            . $where . '). ' . ((is_array($what) === true && $what !== []) ? 'Asked for: ' . implode('; ', $what) . '. ' : '')
            . (string) ($judged['reason'] ?? ''),
            $userId
        );
    }

    /**
     * Record an allowed change to a POSTER's website fields (#493 P2a), as
     * recordChange() does for an event.
     *
     * @param list<string> $changes from judgePosterFields()
     */
    public static function recordPosterChange(int $posterId, string $title, array $changes, ?int $userId, string $where): void
    {
        if ($changes === []) {
            return;
        }
        $type = 'PosterWebsiteDatesChanged';
        foreach ($changes as $line) {
            if (str_starts_with($line, 'switched ON') === true) {
                $type = 'PosterShownOnWebsite';
            } elseif (str_starts_with($line, 'switched OFF') === true) {
                $type = 'PosterHiddenFromWebsite';
            }
        }
        Logger::activity(
            $type,
            'Poster #' . $posterId . ' "' . mb_substr($title, 0, 120) . '" on the public website: '
            . implode('; ', $changes) . ' (' . $where . ').',
            $userId
        );
    }

    /**
     * Record a REFUSED change to a poster's website fields (#493 P2a).
     *
     * @param array<string,mixed> $judged from judgePosterFields(), not ok
     */
    public static function recordPosterRefusal(int $posterId, string $title, array $judged, ?int $userId, string $where): void
    {
        $what = $judged['attempted'] ?? [];
        Logger::activity(
            'PosterWebsiteChangeRefused',
            'Poster #' . $posterId . ' "' . mb_substr($title, 0, 120) . '": a change to the public website was refused ('
            . $where . '). ' . ((is_array($what) === true && $what !== []) ? 'Asked for: ' . implode('; ', $what) . '. ' : '')
            . (string) ($judged['reason'] ?? ''),
            $userId
        );
    }

    /**
     * THE ONE RULE for which noticeboard posters are on the organisation's
     * public website (#493 P2a; D-057, D-078). A fragment beginning " AND ",
     * for a query over `tblNoticeboardPosters` under `$alias`: the switch on,
     * not deleted, and `$today` inside the optional dates (both inclusive).
     * `$today` is the ORGANISATION's calendar date (`date('Y-m-d')`, because
     * start-up sets PHP's zone to the organisation's) — the same calendar the
     * dates are kept on. The organisation itself is NOT in this rule: every
     * caller must add its own `siteID = ?` (the page and the test both do).
     * The public door (P4) and the foyer slideshow when it shows public posters
     * only (P2b) decide WHICH POSTERS by this rule and nothing else. ⚠️ It does
     * not govern a poster's uploaded image or video: `/noticeboard/media`
     * serves those to anybody holding the file's token (#363, so a QR scan
     * works signed out), ticked or not.
     *
     * @return array{sql:string, types:string, params:list<string>}
     */
    public static function posterWhere(string $alias, string $today): array
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,15}$/', $alias) !== 1) {
            throw new \InvalidArgumentException('PublicWebsite::posterWhere(): the alias must be a short lower-case name.');
        }
        if (self::dateFrom($today) === false || self::dateFrom($today) === null) {
            throw new \InvalidArgumentException('PublicWebsite::posterWhere(): today must be a real date written YYYY-MM-DD.');
        }
        $a = $alias;
        return [
            'sql'    => " AND {$a}.isDeleted = 0 AND {$a}.showOnWebsite = 1"
                . " AND ({$a}.websiteFrom IS NULL OR {$a}.websiteFrom <= ?)"
                . " AND ({$a}.websiteUntil IS NULL OR {$a}.websiteUntil >= ?)",
            'types'  => 'ss',
            'params' => [$today, $today],
        ];
    }

    // =========================================================================
    // 3️⃣ How a copied-in event reaches the website (D-080, D-081)
    // =========================================================================

    /**
     * Is this setting changed only on its own card (Admin, Outside calendars)
     * and refused by the general settings editor (#493 P1, round 7 of its
     * check)? Saving the rule there re-works every outside calendar at once;
     * the general editor would store it with no re-work, so the old answers
     * could stand for about a day. Compared ignoring capitals, as every other
     * lock here does.
     */
    public static function lockedInGeneralEditor(string $key): bool
    {
        // #493 P3: the three settings of Admin → Public website as well. That
        // page checks every value (a framing list cannot hold a wildcard, the
        // installation's switch can forbid framing, a main website must be a
        // real address) and records every change; the general editor would
        // store anything, for anybody, at either level — including an
        // INSTALLATION row of `public.frame.origins`, which nothing reads, and
        // an organisation row of `public.frame.permitted`, which nothing reads
        // either, so either would read as a setting while doing nothing.
        // The list is `LOCKED_KEYS`, which `lockedPageFor()` reads too, so the
        // two can never disagree about WHICH keys are locked (round 2 of P3's
        // check). It is a `self::` constant on purpose: the settings-locks
        // self-test finds a lock's keys by reading this body's constants.
        foreach (self::LOCKED_KEYS as $locked) {
            if (strcasecmp($key, $locked) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every key this class locks out of the general settings editor — the ONE
     * list `lockedInGeneralEditor()` and `lockedPageFor()` both read.
     *
     * @var list<string>
     */
    public const LOCKED_KEYS = [self::RULE_KEY, self::WEBSITE_ADDRESS_KEY, self::FRAME_ORIGINS_KEY, self::FRAME_PERMITTED_KEY,
        self::DOOR_TEST_PERMITTED_KEY, self::DOOR_TEST_OPEN_KEY];

    /**
     * 🧭 WHICH PAGE a locked key is changed on — the ONE place that decides
     * it, for the general settings editor's link, its refusal message and the
     * three refusals it records (delete, change, add). null when this class
     * does not lock the key.
     *
     * ⚠️ Until round 1 of #493 P3's check every one of those places named
     *    "Admin, Outside calendars" for every key this class locks, which was
     *    true while there was ONE key and untrue the moment P3 added three —
     *    and migration 262 seeds `public.frame.permitted` on every
     *    installation, so every Settings page showed the wrong page. A page
     *    named in four copies of an `if` is how that happened, so the answer
     *    lives here and the four places ask.
     *
     * `who` says, in words for the Settings page, who can change it there —
     * because Admin, Public website is NOT every administrator's page (D-085):
     * the main website and the framing list are for a global administrator or
     * somebody given "Set up the public website", and the installation's
     * framing switch for the installation's global administrator alone
     * (`installationOnly`).
     *
     * @return array{route:string, name:string, who:string, installationOnly:bool}|null
     */
    public static function lockedPageFor(string $key): ?array
    {
        if (self::lockedInGeneralEditor($key) === false) {
            return null;
        }
        if (strcasecmp($key, self::RULE_KEY) === 0) {
            return ['route' => 'admin/calendar/feeds', 'name' => 'Admin, Outside calendars',
                'who' => 'an administrator of this organisation', 'installationOnly' => false];
        }
        if (strcasecmp($key, self::FRAME_PERMITTED_KEY) === 0 || strcasecmp($key, self::DOOR_TEST_PERMITTED_KEY) === 0) {
            return ['route' => 'admin/public-website', 'name' => 'Admin, Public website',
                'who' => 'the installation\'s global administrator', 'installationOnly' => true];
        }
        if (strcasecmp($key, self::DOOR_TEST_OPEN_KEY) === 0) {
            // D-087 names the organisation's GLOBAL administrator, not
            // somebody given "Set up the public website". The card itself is
            // P4d; until it exists nothing changes this setting anywhere.
            return ['route' => 'admin/public-website', 'name' => 'Admin, Public website',
                'who' => 'a global administrator of this organisation', 'installationOnly' => false];
        }

        return ['route' => 'admin/public-website', 'name' => 'Admin, Public website',
            'who' => 'a global administrator, or somebody given "Set up the public website"', 'installationOnly' => false];
    }

    /**
     * What the general settings editor says when it refuses one of these
     * keys, naming the page THAT key is changed on (`lockedPageFor()`).
     */
    public static function lockedMessageFor(string $key): string
    {
        $page = self::lockedPageFor($key);
        if ($page !== null && $page['route'] === 'admin/public-website') {
            return 'This setting is changed only at Admin, Public website, which checks every value and records every '
                . 'change. Nothing was changed.';
        }

        return self::LOCKED_MESSAGE;
    }

    /** What the general settings editor says when it refuses the copied-in rule. */
    public const LOCKED_MESSAGE = 'How copied-in events reach the public website is changed only at Admin, Outside calendars, '
        . '"Copied-in events on your public website", which re-works every outside calendar at once. Nothing was changed.';

    /**
     * The rule in force for one organisation: the STRICTER of the
     * installation's and the organisation's own (D-081). 'event' is stricter
     * than 'calendar'. An organisation with no row of its own follows the
     * installation; a value nobody can read counts as 'event'; a database
     * that cannot be asked answers 'event'. Never throws: this is read on
     * public pages, and a fault there must hide copied-in events, not break
     * the page or show more.
     */
    public static function copiedRuleFor(\mysqli $db, int $siteId): string
    {
        try {
            $installation = self::storedRule($db, null, false);
            $own          = $siteId > 0 ? self::storedRule($db, $siteId, false) : null;
        } catch (\Throwable $e) {
            error_log('[WebMS-Intra] PublicWebsite: the copied-in events rule could not be read, so the stricter one is used: ' . $e->getMessage());

            return EventVisibility::COPIED_BY_EVENT;
        }

        return self::copiedRuleFrom($installation, $own);
    }

    /**
     * The pure half of copiedRuleFor(), for the page and the self-test.
     *
     * @param string|null $installation The installation's stored value, or null.
     * @param string|null $own          The organisation's own stored value, or null (no row).
     */
    public static function copiedRuleFrom(?string $installation, ?string $own): string
    {
        if (self::isLooser($installation) === false) {
            return EventVisibility::COPIED_BY_EVENT;
        }
        // The installation allows the looser rule; the organisation may still
        // hold itself to the stricter one. No row means "follow".
        if ($own === null) {
            return EventVisibility::COPIED_BY_CALENDAR;
        }

        return self::isLooser($own) === true ? EventVisibility::COPIED_BY_CALENDAR : EventVisibility::COPIED_BY_EVENT;
    }

    /**
     * Save the INSTALLATION's rule. A global administrator only, checked here
     * as well as in the handler, because the installation and an organisation
     * may be different parties (D-013; the #687 precedent). Tightening below
     * what an organisation chose is NOT refused: tightening is the safe
     * direction and the reader already holds every organisation to it.
     *
     * @return array{saved:bool, type:string, message:string, change:string}
     */
    public static function saveRuleForInstallation(\mysqli $db, string $posted, bool $globalAdmin): array
    {
        if ($globalAdmin === false) {
            return self::ruleRefusal('Only a global administrator can choose the rule for the whole installation. Nothing was saved.');
        }
        if (in_array($posted, [EventVisibility::COPIED_BY_CALENDAR, EventVisibility::COPIED_BY_EVENT], true) === false) {
            return self::ruleRefusal('The rule has to be "one box per subscribed calendar" or "one tick per event". Nothing was saved.');
        }
        try {
            $db->begin_transaction();
            $old = self::storedRule($db, null, true);
            if ($old === $posted) {
                $db->commit();

                return ['saved' => true, 'type' => 'info', 'message' => 'Saved. Nothing was different.', 'change' => ''];
            }
            self::upsertRule($db, null, $posted);
            $db->commit();
        } catch (\Throwable $e) {
            self::rollbackQuietly($db);
            error_log('[WebMS-Intra] PublicWebsite: the installation\'s copied-in rule could not be saved: ' . $e->getMessage());

            return self::ruleRefusal('The database refused the change. Nothing was saved.');
        }

        return [
            'saved'   => true,
            'type'    => 'success',
            'message' => 'Saved for the whole installation.',
            'change'  => self::ruleWords($old) . ' → ' . self::ruleWords($posted),
        ];
    }

    /**
     * Save ONE organisation's rule: 'inherit' (delete its row), or a rule no
     * looser than the installation's. A looser one is REFUSED with the reason,
     * never quietly lowered (#668) — and only when it CHANGED (#687): an
     * organisation whose 'calendar' predates the installation tightening is
     * left alone by an untouched Save, and the reader holds it down anyway.
     *
     * The installation's row is read LIVE and LOCKED inside the transaction
     * that writes (#647, #687), so a tightening at the same moment cannot be
     * missed. `$organisationGlobalAdmin` is the D-080 "organisation's global
     * administrator" (`App::isSiteRootAdmin()`, which counts a global
     * administrator too), asked here as well as in the handler.
     *
     * @return array{saved:bool, type:string, message:string, change:string}
     */
    public static function saveRuleForOrganisation(\mysqli $db, int $siteId, string $posted, bool $organisationGlobalAdmin): array
    {
        if ($organisationGlobalAdmin === false) {
            return self::ruleRefusal('Only this organisation\'s global administrator can choose its rule. Nothing was saved.');
        }
        if ($siteId <= 0) {
            return self::ruleRefusal('No organisation is open. Nothing was saved.');
        }
        if (in_array($posted, [self::INHERIT, EventVisibility::COPIED_BY_CALENDAR, EventVisibility::COPIED_BY_EVENT], true) === false) {
            return self::ruleRefusal('The rule has to be "follow the installation", "one box per subscribed calendar" or '
                . '"one tick per event". Nothing was saved.');
        }
        try {
            $db->begin_transaction();
            $installation = self::storedRule($db, null, true);
            $own          = self::storedRule($db, $siteId, true);
            $wasWords     = $own === null ? 'follow the installation' : self::ruleWords($own);

            if ($posted === self::INHERIT) {
                if ($own === null) {
                    $db->commit();

                    return ['saved' => true, 'type' => 'info', 'message' => 'Saved. Nothing was different.', 'change' => ''];
                }
                $del = $db->prepare('DELETE FROM `tblSettings` WHERE `siteID` = ? AND `settingKey` = ?');
                $key = self::RULE_KEY;
                $del->bind_param('is', $siteId, $key);
                $del->execute();
                $del->close();
                $db->commit();

                return ['saved' => true, 'type' => 'success', 'message' => 'Saved for this organisation.', 'change' => $wasWords . ' → follow the installation'];
            }

            if ($own === $posted) {
                $db->commit();

                return ['saved' => true, 'type' => 'info', 'message' => 'Saved. Nothing was different.', 'change' => ''];
            }
            if ($posted === EventVisibility::COPIED_BY_CALENDAR && self::isLooser($installation) === false) {
                $db->rollback();

                return self::ruleRefusal('An organisation may hold itself to the same rule as the installation or a stricter '
                    . 'one, never a looser one. The installation allows only "one tick per event", so "one box per '
                    . 'subscribed calendar" cannot be chosen here. Nothing was saved.');
            }
            self::upsertRule($db, $siteId, $posted);
            $db->commit();
        } catch (\Throwable $e) {
            self::rollbackQuietly($db);
            error_log('[WebMS-Intra] PublicWebsite: organisation #' . $siteId . '\'s copied-in rule could not be saved: ' . $e->getMessage());

            return self::ruleRefusal('The database refused the change. Nothing was saved.');
        }

        return ['saved' => true, 'type' => 'success', 'message' => 'Saved for this organisation.', 'change' => $wasWords . ' → ' . self::ruleWords($posted)];
    }

    /**
     * One level's stored value, exactly as stored, or null when it has no row.
     * `$siteId` null is the installation's row (`siteScope = -1`).
     */
    public static function storedRule(\mysqli $db, ?int $siteId, bool $lock): ?string
    {
        $where = $siteId === null ? '`siteScope` = -1' : '`siteID` = ?';
        $stmt  = $db->prepare(
            'SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = ? AND ' . $where . ' LIMIT 1'
            . ($lock === true ? ' FOR UPDATE' : '')
        );
        $key = self::RULE_KEY;
        if ($siteId === null) {
            $stmt->bind_param('s', $key);
        } else {
            $stmt->bind_param('si', $key, $siteId);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row === null ? null : (string) ($row['settingValue'] ?? '');
    }

    /** Plain words for one stored rule, for a page and the activity log. */
    public static function ruleWords(?string $stored): string
    {
        if ($stored === null) {
            return '(nothing stored)';
        }

        return self::isLooser($stored) === true ? 'one box per subscribed calendar' : 'one tick per event';
    }

    // =========================================================================
    // 🧹 Small helpers
    // =========================================================================

    /**
     * Does this ACTIVE account hold the old portal-wide `isAdmin` flag? Read
     * from the database, so a flag taken away counts for nothing on the next
     * request. Which organisation it applies to is asked separately, of
     * `Site::isOwnOrganisation()`, never assumed.
     */
    private static function holdsPortalWideAdminFlag(\mysqli $db, int $userId): bool
    {
        $stmt = $db->prepare('SELECT 1 FROM `tblUsers` WHERE `userID` = ? AND `isActive` = 1 AND `isAdmin` = 1');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $found = $stmt->get_result()->fetch_row();
        $stmt->close();

        return $found !== null;
    }

    /** Is a stored value exactly the looser rule? Anything else is the stricter. */
    private static function isLooser(?string $stored): bool
    {
        return $stored === EventVisibility::COPIED_BY_CALENDAR;
    }

    /** One switch value, or null when it is not one. */
    private static function flagFrom(mixed $raw): ?int
    {
        if ($raw === true || $raw === 1 || $raw === '1' || $raw === 'true') {
            return 1;
        }
        if ($raw === false || $raw === 0 || $raw === '0' || $raw === 'false') {
            return 0;
        }

        return null;
    }

    /**
     * One date: null for empty, the date for a real Y-m-d from the year 1000
     * (the portal's own floor: MySQL accepts years 1-999 silently, round 22 of
     * #777), or false when it is neither. Never read through strtotime(): a
     * calendar date needs no moment (#777).
     */
    private static function dateFrom(mixed $raw): string|false|null
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_string($raw) === false) {
            return false;
        }
        $text = trim($raw);
        if ($text === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $m) !== 1
            || (int) $m[1] < 1000 || checkdate((int) $m[2], (int) $m[3], (int) $m[1]) === false) {
            return false;
        }

        return $text;
    }

    /** A stored DATE as Y-m-d, or null. */
    private static function storedDate(mixed $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        return substr((string) $stored, 0, 10);
    }

    /**
     * What changed, in words for the activity log.
     *
     * @param array{showOnWebsite:int, websiteFrom:?string, websiteUntil:?string} $was
     * @param array{showOnWebsite:int, websiteFrom:?string, websiteUntil:?string} $now
     *
     * @return list<string>
     */
    private static function changeWords(array $was, array $now): array
    {
        $out = [];
        if ($was['showOnWebsite'] !== $now['showOnWebsite']) {
            $out[] = $now['showOnWebsite'] === 1 ? 'switched ON' : 'switched OFF';
        }
        foreach (['websiteFrom' => 'first day', 'websiteUntil' => 'last day'] as $field => $words) {
            if ($was[$field] !== $now[$field]) {
                $out[] = $words . ' ' . ($was[$field] ?? 'none') . ' → ' . ($now[$field] ?? 'none');
            }
        }

        return $out;
    }

    /**
     * @param array{showOnWebsite:int, websiteFrom:?string, websiteUntil:?string} $was
     *
     * @return array{ok:bool, kind:string, reason:string, values:array, changed:bool, changes:list<string>}
     */
    private static function refused(string $kind, array $was, string $reason): array
    {
        return ['ok' => false, 'kind' => $kind, 'reason' => $reason, 'values' => $was, 'changed' => false, 'changes' => []];
    }

    /** @return array{saved:bool, type:string, message:string, change:string} */
    private static function ruleRefusal(string $why): array
    {
        return ['saved' => false, 'type' => 'danger', 'message' => $why, 'change' => ''];
    }

    /** Write one level's row (null = the installation). */
    private static function upsertRule(\mysqli $db, ?int $siteId, string $value): void
    {
        $stmt = $db->prepare(
            'INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`, `updatedAt`) '
            . 'VALUES (?, ?, ?, ?, 0, NOW()) '
            . 'ON DUPLICATE KEY UPDATE `settingValue` = VALUES(`settingValue`), `updatedAt` = NOW()'
        );
        $key     = self::RULE_KEY;
        $default = EventVisibility::COPIED_BY_EVENT;
        $stmt->bind_param('isss', $siteId, $key, $value, $default);
        $stmt->execute();
        $stmt->close();
    }

    private static function rollbackQuietly(\mysqli $db): void
    {
        try {
            $db->rollback();
        } catch (\Throwable $ignored) {
            // Nothing more can be done; the caller reports the failure.
        }
    }
}
