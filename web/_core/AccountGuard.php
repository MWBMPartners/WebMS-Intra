<?php
// Path: _core/AccountGuard.php
/**
 * -----------------------------------------------------------------------------
 * Account Change Guard 🛡️ (#518)
 * -----------------------------------------------------------------------------
 * WHY THIS CLASS EXISTS
 * -------------------------------------------------------------------------
 * Before this class existed, `App::isAdmin()` was the ONLY gate on every
 * page that changes an account — and `App::isAdmin()` is true for a site
 * administrator of WHICHEVER organisation happens to be open. Reproduced
 * on a real database on 17 September 2026, an administrator of one
 * organisation could, while their own organisation was open:
 *   - set a NEW PASSWORD on a global administrator's account
 *     (`admin/users/save.php`'s update branch had no ownership check at
 *     all);
 *   - give THEMSELVES the portal-wide `isAdmin` flag, which
 *     `App::isAdmin()` then honours for every organisation they ever
 *     open;
 *   - create a BRAND NEW account with the portal-wide `isAdmin` flag
 *     already switched on.
 * All three are a full takeover of the whole installation, not just of
 * one organisation. This class is the one place that decides whether a
 * change to an account is allowed to go ahead, so that decision is made
 * once, correctly, rather than copied by hand into every page that
 * touches `tblUsers`, `tblLocalAccounts`, `tblUserSites`,
 * `tblDbsChecks` and the offboarding/invitation flows that create or
 * change accounts.
 *
 * -------------------------------------------------------------------------
 * WHAT A PAGE MUST DO BEFORE CALLING THIS
 * -------------------------------------------------------------------------
 * This class does NOT decide who may open a page. Every existing page's
 * own `App::isAdmin() === false` (or, for the bearer API, `ApiAuth`'s own
 * scope check) gate stays exactly where it is, and must run FIRST. This
 * class only decides, once someone is already through that door, how far
 * a particular change is allowed to reach. `evaluate()` does carry one
 * backstop check of its own (a session caller who is not an administrator
 * of the organisation that is open gets refused as `not_admin_here`) —
 * that exists only in case a future page forgets its own gate; it is not
 * a licence to skip the page's own check, because a missing gate would
 * still let an ordinary member reach this class's SQL and its logging,
 * which the page's own gate is meant to stop before any of that runs.
 *
 * -------------------------------------------------------------------------
 * WHY AN ENDED MEMBERSHIP STILL COUNTS
 * -------------------------------------------------------------------------
 * Undoing an offboarding (`REACH_REHIRE`) is the one situation where an
 * ENDED `tblUserSites` row (`isActive = 0`) for the organisation that is
 * open still counts as "belongs here". Everywhere else, only an ACTIVE
 * row counts. The reason is simple: offboarding is what SETS
 * `isActive = 0` in the first place, so if an ended row did not count,
 * a site administrator could offboard someone and then never be allowed
 * to undo it — the very organisation that offboarded them would lose the
 * right to bring them back. Undoing an offboarding is also the one
 * change that can only ever put someone BACK where they already were; it
 * cannot reach anywhere new.
 *
 * -------------------------------------------------------------------------
 * THE SINGLE-ORGANISATION EXCEPTION, AND WHY IT READS THIS ONE ROW ITSELF
 * -------------------------------------------------------------------------
 * On an installation that has never turned multi-organisation working on,
 * every account already "belongs" — there is only ever one organisation,
 * so the several-organisations lock (rows 6-9 of the decision table below)
 * is what still applies, but the membership check in row 3 is skipped
 * entirely. `isSingleOrganisation()` decides this by reading the
 * PORTAL-WIDE `multisite.enabled` row directly out of `tblSettings`
 * (`siteID IS NULL`), the same query `Site::preDetect()` runs before
 * settings are even loaded — it deliberately does NOT call
 * `Site::isMultisiteEnabled()`, because that reads the settings snapshot
 * `Site::init()` built for the CURRENT organisation, and an organisation
 * is allowed to override a portal-wide setting with its own row
 * (`bootstrap.php`'s `assign_setting()`). Tested on 17 September 2026: a
 * site administrator posting a new organisation-level
 * `multisite.enabled=false` row through `/orga/settings/save` could not
 * actually create one, because that page already refuses to duplicate an
 * existing portal-wide setting name. So this route is not open today —
 * reading the portal-wide row directly is defence in depth, in exactly
 * the same spirit as `RateLimiter` and `Gatekeeper::portalWideSetting()`
 * already reading settings no single organisation may influence.
 *
 * THIS EXCEPTION STAYS, EVEN THOUGH #533 REMOVED THE MATCHING ONE FROM THE
 * VIEWER-SIDE PAGES (20 September 2026). The check-in page, its save
 * handler and the waitlist promotion used to each carry their own copy of
 * "a single-organisation account with no membership row still belongs
 * here" — added because, before #518, creating an account never wrote a
 * membership row at all. Migration 199 fixes that DATA GAP directly (a real
 * membership row for every such account, backfilled on upgrade), so those
 * three pages have dropped their copies and are now strict: no row, no
 * access, full stop. This class's OWN exception is not the same question.
 * It answers "may THIS ADMINISTRATOR reach this account", not "does this
 * VIEWER belong here" — and it must stay yes for a row-less account, or the
 * very account that migration 199 could not place automatically (because
 * the portal has more than one organisation) would become invisible to
 * everybody except a global administrator, including to the
 * `/admin/users/unplaced` page built to let a global administrator place
 * it. Removing this exception would not tidy anything up; it would hide
 * the accounts the rest of #533 exists to surface.
 *
 * -------------------------------------------------------------------------
 * WHY A MISSING ACCOUNT AND ANOTHER ORGANISATION'S ACCOUNT MUST LOOK — AND
 * RECORD — THE SAME
 * -------------------------------------------------------------------------
 * If a refused change to a REAL account in another organisation looked or
 * behaved even slightly differently from a refused change to an account
 * number that does not exist at all, an administrator could use that
 * difference to find out which account numbers are real in an
 * organisation they have no business looking into — the "does this
 * account exist" test this fix exists to remove. That is why `NOT_FOUND`
 * is used for BOTH cases, why the wording is identical, and why
 * `logRefusal()` writes exactly the same TWO records (one activity-log
 * line, one security record) for both, with no exception carved out for
 * an account number that turns out not to exist. Tested on 17 September
 * 2026: `/admin`'s "Errors (24h)" count, which every site administrator
 * sees for the WHOLE installation with no organisation filter
 * (`admin/index.php`), rose by exactly one after a single refusal — that
 * is only true because the security record is written every time, not
 * only when the target turns out to be real.
 *
 * -------------------------------------------------------------------------
 * WHY A TARGET HOLDING `isAdmin` IS PROTECTED, BUT A PERSON HOLDING IT IS
 * NOT TREATED AS GLOBAL
 * -------------------------------------------------------------------------
 * `tblUsers.isAdmin` is the older, portal-wide flag: `App::isAdmin()`
 * treats anyone holding it as an administrator of EVERY organisation they
 * open. An ACCOUNT holding it is therefore protected here in exactly the
 * same way a genuinely global account (`isRootAdmin`) is — only a global
 * administrator may change it. But a PERSON who holds `isAdmin` is not,
 * by that fact alone, treated as global when they are the one ACTING —
 * `actorIsGlobal()` only ever answers yes for `isRootAdmin`. That is a
 * deliberate, narrower fix: whether an `isAdmin` holder should keep being
 * treated as an administrator of every organisation they open belongs to
 * a separate piece of work (#511, #516), not to this one.
 *
 * -------------------------------------------------------------------------
 * WHAT THIS CLASS CANNOT DO (see also DEV_NOTES.md and the #518 commit)
 * -------------------------------------------------------------------------
 *  - The check and the write it guards are two separate steps. A
 *    membership a global administrator adds in between is not seen by
 *    the check that already ran. Nothing an ordinary (non-global) site
 *    administrator can do creates a membership row in ANOTHER
 *    organisation, so they have no way to open that gap themselves.
 *  - A password or email address a site administrator set while an
 *    account belonged only to their own organisation keeps working if a
 *    global administrator later adds that same account to a second
 *    organisation. When adding someone to a second organisation, a
 *    global administrator may want to ask them to reset their password.
 *  - It does not take back portal-wide `isAdmin` rights that may already
 *    have been given out through this fault before this fix shipped —
 *    that is a one-off clean-up for a global administrator to run by
 *    hand, not something this class can undo automatically.
 *  - It does not fix #511: taking over an account that belongs only to
 *    organisation A can still expose organisation B's members-only pages
 *    once that account is (legitimately) added to B. It also does not
 *    stop one administrator of an organisation taking over a FELLOW
 *    administrator of that SAME organisation — that is #516.
 *  - Account details and DBS records stay shared between organisations
 *    that both include the same person. This class only decides WHO may
 *    change them, not whether the underlying data itself is per
 *    organisation.
 *  - THE EMAIL-CLASH MESSAGE IS NOW BOUNDED, NOT CLOSED (#521, 20 September
 *    2026). `emailAvailability()` still lets a non-global administrator
 *    learn, once per attempt, that an address "can't be used here" — the
 *    same fact as "belongs to an account somewhere on the installation" —
 *    but only up to EMAIL_CLASH_LIMIT (5) times per EMAIL_CLASH_WINDOW (one
 *    hour), and every one of those refusals is recorded where a global
 *    administrator can see it and act. That bounds a systematic sweep of a
 *    customer list to something slow and on the record; it does not stop a
 *    single, deliberate guess. A truly closed design (a blind invitation
 *    flow indistinguishable whether the address is free or taken) is
 *    bigger than this fix and is a follow-up issue, not built here. Editing
 *    your own account (`auth/account/index.php`'s `email_taken` check) is a
 *    MEMBER testing their own address change, not an administrator testing
 *    someone else's, and is deliberately out of this method's scope — see
 *    the #518/#521 follow-ups list.
 *  - The `/admin` dashboard still shows installation-wide totals (errors,
 *    users, activity) to every site administrator, not only their own
 *    organisation's figures. This class only makes sure a refusal moves
 *    those totals by exactly the same amount whether the target account
 *    was missing or belonged to another organisation — it does not scope
 *    the dashboard itself.
 *  - The refusal wording never names the actual reason, but a site
 *    administrator can often work it out anyway, because the user list
 *    already marks Root and Admin accounts with a badge. This is not a
 *    secret the wording reliably keeps.
 *  - Session-mode site detection is still broken for a separate, older
 *    reason (#502) — this class simply reads whatever `Site::id()`
 *    currently returns, which in session mode may not be what the
 *    person switched to.
 *  - For a bearer API-key request, this class trusts the scope check
 *    `ApiAuth` has already run; it does not re-check API scopes itself.
 *  - A global administrator undoing an offboarding still reactivates
 *    EVERY ended membership row the account has, in every organisation —
 *    that is older behaviour and is unchanged here.
 *  - The coverage check that goes with this class
 *    (`tools/audit-checks/check_account_writes_guarded.py`) can only see
 *    an account-table write spelt out as plain SQL text next to the
 *    table name in a `.php` file. It cannot see SQL assembled from
 *    variables, a statement built from joined string pieces, or a write
 *    made through a shared helper in a different file.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026 MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/518
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

final class AccountGuard
{
    // =========================================================================
    // 🏷️ How far a change reaches — the calling page decides which one applies
    // =========================================================================

    /** Showing an account. Also the first check run before any change. */
    public const REACH_VIEW = 'view';

    /** The users API's `isSiteAdmin` flag on THIS organisation's own membership row. */
    public const REACH_THIS_ORG = 'this_org';

    /** Anything stored on the account itself: name, email, phone, password, on/off switch, DBS records. */
    public const REACH_ACCOUNT = 'account';

    /** Undoing an offboarding. */
    public const REACH_REHIRE = 'rehire';

    /** Giving or removing the portal-wide `isAdmin` flag. */
    public const REACH_PORTAL = 'portal';

    // =========================================================================
    // 🏷️ Verdicts
    // =========================================================================

    public const ALLOW       = 'allow';
    public const NOT_FOUND   = 'not_found';
    public const GLOBAL_ONLY = 'global_only';

    /**
     * Plain-English reason word for each reason code, used ONLY inside the
     * security record's detail text (never shown to the person acting —
     * see message() for what they actually see).
     */
    private const REASON_WORDS = [
        'missing'        => 'no account has that number',
        'not_member'     => 'the account is not an active member of the organisation that was open',
        'portal_grant'   => 'asked to give or remove administrator rights across the whole portal',
        'global_admin'   => 'the account belongs to a global administrator',
        'portal_admin'   => 'the account has administrator rights across the whole portal',
        'other_org'      => 'the account also belongs, or used to belong, to another organisation',
        'not_admin_here' => 'the person acting is not an administrator of the organisation that was open',
    ];

    /** @var bool|null Cached per-request answer to isSingleOrganisation(). Null means "not yet worked out". */
    private static ?bool $singleOrgCache = null;

    /**
     * Reset every cached answer. Test-only — production code never calls
     * this; a single HTTP request never needs the answer to change part
     * way through.
     *
     * @return void
     */
    public static function resetCacheForTests(): void
    {
        self::$singleOrgCache = null;
    }

    // =========================================================================
    // 🌐 Who is acting
    // =========================================================================

    /**
     * Is the person (or key) making this request a GLOBAL administrator?
     *
     * A request authenticated with an API key is NEVER treated as global,
     * whoever created that key — checked first, so a bearer request can
     * never borrow the CURRENT session's rights (there usually is no
     * session at all on a bearer request, but checking the source first
     * removes any doubt).
     *
     * @return bool
     */
    public static function actorIsGlobal(): bool
    {
        if (ApiAuth::source() === 'apikey') {
            return false;
        }
        return App::isRootAdmin();
    }

    /**
     * Is this installation running in single-organisation mode?
     *
     * Reads the PORTAL-WIDE `multisite.enabled` row directly — see the
     * class docblock ("THE SINGLE-ORGANISATION EXCEPTION") for why this
     * does NOT call Site::isMultisiteEnabled(). Cached for the rest of
     * the request: the setting cannot change while one HTTP request is
     * being handled.
     *
     * WHAT THIS CANNOT DO: if the prepared statement cannot even be
     * built, or anything else throws, this answers `false` — the
     * STRICTER, multi-organisation reading — rather than risk treating a
     * genuinely multi-organisation installation as single-organisation
     * because of an unrelated database hiccup.
     *
     * @return bool
     */
    public static function isSingleOrganisation(): bool
    {
        if (self::$singleOrgCache !== null) {
            return self::$singleOrgCache;
        }

        try {
            $stmt = App::db()->prepare(
                "SELECT settingValue FROM tblSettings "
                . "WHERE settingKey = 'multisite.enabled' AND siteID IS NULL LIMIT 1"
            );
            if ($stmt === false) {
                self::$singleOrgCache = false;
                return false;
            }
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            // No row, or a value that is not exactly 'true', both mean
            // single-organisation — matches Site::preDetect()'s own
            // reading of this exact row.
            self::$singleOrgCache = ($row === null || $row['settingValue'] !== 'true');
            return self::$singleOrgCache;
        } catch (\Throwable $ignored) {
            self::$singleOrgCache = false;
            return false;
        }
    }

    // =========================================================================
    // ⚖️ The rule itself — no database, no session, testable on its own
    // =========================================================================

    /**
     * The decision table (see the #518 settled plan, section 1.3). Rows are
     * checked in order and the FIRST match wins. This method reaches into
     * nothing outside its own arguments — no database, no session, no other
     * class — so it can be, and is, exercised directly by
     * tools/account-guard-selftest.php with no database at all.
     *
     * @param bool       $actorIsGlobal      Whether the person/key acting is a global administrator.
     * @param array|null $facts              The target account's row from facts(), or null when it does not exist.
     *                                       Expected keys: isAdmin, isRootAdmin, thisOrgActive, otherOrgRows.
     * @param string     $reach              One of the REACH_* constants.
     * @param bool       $singleOrganisation Whether this installation is running single-organisation.
     *
     * @return array{verdict: string, reason: string}
     *
     * @throws \InvalidArgumentException When $reach is not one of the five known values.
     */
    public static function decide(bool $actorIsGlobal, ?array $facts, string $reach, bool $singleOrganisation): array
    {
        self::assertKnownReach($reach);

        // Row 1 — the account plainly does not exist.
        if ($facts === null) {
            return ['verdict' => self::NOT_FOUND, 'reason' => 'missing'];
        }

        // Row 2 — a global administrator may change anything.
        if ($actorIsGlobal === true) {
            return ['verdict' => self::ALLOW, 'reason' => ''];
        }

        // Row 3 — on a multi-organisation installation, an account that is
        // not (in the sense that matters for this reach — see
        // belongsHere()) a member of the organisation that is open must
        // look exactly like a missing account. This is what stops the
        // "does this account exist somewhere else" probe.
        if ($singleOrganisation === false && self::belongsHere($facts, $reach) === false) {
            return ['verdict' => self::NOT_FOUND, 'reason' => 'not_member'];
        }

        // Row 4 — merely viewing/showing an account is always allowed once
        // rows 1-3 have passed.
        if ($reach === self::REACH_VIEW) {
            return ['verdict' => self::ALLOW, 'reason' => ''];
        }

        // Row 5 — giving or removing the portal-wide isAdmin flag is
        // ALWAYS a global-administrator-only action, whoever the target
        // is and however far they belong.
        if ($reach === self::REACH_PORTAL) {
            return ['verdict' => self::GLOBAL_ONLY, 'reason' => 'portal_grant'];
        }

        // Row 6 — the target is itself a global administrator: protected
        // regardless of who is asking or what reach was requested.
        if (self::flagIsOn($facts['isRootAdmin'] ?? null) === true) {
            return ['verdict' => self::GLOBAL_ONLY, 'reason' => 'global_admin'];
        }

        // Row 7 — the target holds the portal-wide isAdmin flag: also
        // protected, even though a PERSON holding that flag is not
        // treated as global when THEY are the one acting (see the class
        // docblock).
        if (self::flagIsOn($facts['isAdmin'] ?? null) === true) {
            return ['verdict' => self::GLOBAL_ONLY, 'reason' => 'portal_admin'];
        }

        // Row 8 — a change limited to THIS organisation's own membership
        // row is allowed even for an account that also belongs elsewhere,
        // because it cannot reach the other organisation.
        if ($reach === self::REACH_THIS_ORG) {
            return ['verdict' => self::ALLOW, 'reason' => ''];
        }

        // Row 9 — the account also belongs (or used to belong) to another
        // organisation, so any OTHER kind of change could reach that
        // organisation too.
        if ((int) ($facts['otherOrgRows'] ?? 0) > 0) {
            return ['verdict' => self::GLOBAL_ONLY, 'reason' => 'other_org'];
        }

        // Row 10 — everything else is allowed.
        return ['verdict' => self::ALLOW, 'reason' => ''];
    }

    /**
     * Does the target "belong" to the organisation that is open, for the
     * purposes of row 3 above? For REACH_REHIRE an ENDED membership row
     * still counts (see the class docblock, "WHY AN ENDED MEMBERSHIP
     * STILL COUNTS"); for every other reach only an ACTIVE row counts.
     *
     * @param array  $facts One row from facts().
     * @param string $reach One of the REACH_* constants.
     *
     * @return bool
     */
    private static function belongsHere(array $facts, string $reach): bool
    {
        $thisOrg = $facts['thisOrgActive'] ?? null;
        if ($reach === self::REACH_REHIRE) {
            // null means "no row at all"; 0 (an ended row) or 1 (active)
            // both mean "there is a row", which is what counts here.
            return $thisOrg !== null;
        }
        return self::flagIsOn($thisOrg);
    }

    /**
     * Throws unless $reach is one of the five known REACH_* values. Kept
     * as its own method because BOTH decide() (called directly by the
     * self-test, with no database) and evaluate() (called by real pages)
     * must refuse an unknown reach in exactly the same way, before either
     * of them does anything else.
     *
     * @param string $reach
     *
     * @return void
     *
     * @throws \InvalidArgumentException
     */
    private static function assertKnownReach(string $reach): void
    {
        $known = [
            self::REACH_VIEW,
            self::REACH_THIS_ORG,
            self::REACH_ACCOUNT,
            self::REACH_REHIRE,
            self::REACH_PORTAL,
        ];
        if (in_array($reach, $known, true) === false) {
            throw new \InvalidArgumentException('AccountGuard: unknown reach "' . $reach . '"');
        }
    }

    // =========================================================================
    // 🔢 Reading a yes/no flag exactly the way the database hands it back
    // =========================================================================

    /**
     * Is a yes/no flag, exactly as it came out of the database, switched
     * on? Accepts only the whole number 1 and the text '1' — this is a
     * deliberate copy of the private App::flagIsOn(), which this class
     * cannot call because it is private to App. See App.php's own
     * extensive comment on that method for the full story: in short, a
     * prepared statement hands a TINYINT column back as a PHP whole
     * number (1, not '1'), so comparing with `=== '1'` alone is wrong,
     * and this codebase has already shipped that exact fault more than
     * once (#497).
     *
     * @param mixed $value
     *
     * @return bool
     */
    private static function flagIsOn(mixed $value): bool
    {
        return ($value === 1 || $value === '1');
    }

    // =========================================================================
    // 🔍 Reading the facts a decision needs, from the database
    // =========================================================================

    /**
     * Fetch the facts decide() needs for each of the given account
     * numbers, scoped to one organisation.
     *
     * Whole numbers above 0 are kept, duplicates are removed, and up to
     * 100 may be asked for in one call — more than that throws, because
     * nothing in the portal legitimately needs more than that in a
     * single page render (the users list page renders 25 at a time).
     *
     * @param array $userIds Account numbers to look up (mixed types tolerated; non-positive/non-numeric are dropped).
     * @param int   $siteId  The organisation to check membership against.
     *
     * @return array<int, array<string, mixed>> Keyed by userID. An id that does not exist in tblUsers is simply absent.
     *
     * @throws \InvalidArgumentException When more than 100 distinct ids remain after cleaning.
     * @throws \RuntimeException         When the prepared statement cannot be built.
     */
    public static function facts(array $userIds, int $siteId): array
    {
        $ids = [];
        foreach ($userIds as $rawId) {
            $id = (int) $rawId;
            if ($id > 0 && in_array($id, $ids, true) === false) {
                $ids[] = $id;
            }
        }

        if (count($ids) === 0) {
            return [];
        }
        if (count($ids) > 100) {
            throw new \InvalidArgumentException(
                'AccountGuard::facts: ' . count($ids) . ' account numbers were asked for in one call; the limit is 100.'
            );
        }

        // One query, one round trip. The two correlated subqueries each
        // return at most one row / one number per outer row: the first
        // because (userID, siteID) is the unique key uq_user_site on
        // tblUserSites, the second because COUNT(*) always returns
        // exactly one row.
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = 'SELECT u.userID, u.isAdmin, u.isRootAdmin, '
            . '(SELECT us.isActive FROM tblUserSites us WHERE us.userID = u.userID AND us.siteID = ? LIMIT 1) AS thisOrgActive, '
            . '(SELECT COUNT(*) FROM tblUserSites o WHERE o.userID = u.userID AND o.siteID <> ?) AS otherOrgRows '
            . 'FROM tblUsers u WHERE u.userID IN (' . $placeholders . ')';

        $db   = App::db();
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('AccountGuard::facts: failed to prepare: ' . $db->error);
        }

        $types  = 'ii' . str_repeat('i', count($ids));
        $params = array_merge([$siteId, $siteId], $ids);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        while (($row = $result->fetch_assoc()) !== null) {
            $rows[(int) $row['userID']] = $row;
        }
        $stmt->close();

        return $rows;
    }

    // =========================================================================
    // 🚪 What real pages call
    // =========================================================================

    /**
     * Shared body of verdict() and check(): works out the verdict for one
     * account and one reach, WITHOUT logging anything. In this order:
     *   1. An unknown reach throws, before any database work at all.
     *   2. A backstop: a SESSION caller who is not even an administrator
     *      of the organisation that is open is refused as NOT_FOUND — in
     *      case a future page forgets its own App::isAdmin() gate. This
     *      is not a substitute for that gate; see the class docblock.
     *   3. An account number of 0 or less is NOT_FOUND, with no query at
     *      all — there is nothing to look up.
     *   4. Otherwise, the real decision, via decide().
     *
     * @param int    $userId The account being changed or viewed.
     * @param string $reach  One of the REACH_* constants.
     *
     * @return array{verdict: string, reason: string}
     */
    private static function evaluate(int $userId, string $reach): array
    {
        self::assertKnownReach($reach);

        if (ApiAuth::source() === 'session' && App::isAdmin() === false) {
            return ['verdict' => self::NOT_FOUND, 'reason' => 'not_admin_here'];
        }

        if ($userId <= 0) {
            return ['verdict' => self::NOT_FOUND, 'reason' => 'missing'];
        }

        $facts = self::facts([$userId], Site::id());

        return self::decide(
            self::actorIsGlobal(),
            $facts[$userId] ?? null,
            $reach,
            self::isSingleOrganisation()
        );
    }

    /**
     * Work out the verdict for drawing a page (e.g. deciding whether to
     * show an Edit button or a "changed by a global administrator" badge)
     * WITHOUT logging a refusal. Use check() instead for an actual
     * attempt to change something.
     *
     * @param int    $userId
     * @param string $reach
     *
     * @return string One of the ALLOW / NOT_FOUND / GLOBAL_ONLY constants.
     */
    public static function verdict(int $userId, string $reach): string
    {
        return self::evaluate($userId, $reach)['verdict'];
    }

    /**
     * Work out the verdict for a REAL attempt to change (or specifically
     * look up before changing) an account, and log a refusal — both the
     * activity-log line and the security record — whenever the answer is
     * not ALLOW. Every page that changes an account calls this, never
     * verdict(), for the actual attempt.
     *
     * @param int    $userId The account being changed.
     * @param string $reach  One of the REACH_* constants.
     * @param string $action Plain-English text describing the attempt, containing ONLY what the person themselves supplied (e.g. "edit account #104"). Never the target's name or email.
     *
     * @return string One of the ALLOW / NOT_FOUND / GLOBAL_ONLY constants.
     */
    public static function check(int $userId, string $reach, string $action): string
    {
        $result = self::evaluate($userId, $reach);
        if ($result['verdict'] !== self::ALLOW) {
            self::logRefusal($action, $result['verdict'], $result['reason'], $userId > 0 ? $userId : null);
        }
        return $result['verdict'];
    }

    // =========================================================================
    // 📝 Recording a refusal
    // =========================================================================

    /**
     * Record a refusal in BOTH places, with no exception carved out for a
     * missing account — see the class docblock, "WHY A MISSING ACCOUNT
     * AND ANOTHER ORGANISATION'S ACCOUNT MUST LOOK — AND RECORD — THE
     * SAME". Never includes the TARGET's name, email address, phone
     * number or any password text in either record — only the account
     * NUMBER the person themselves supplied, which they already knew.
     *
     * Neither Logger method this calls ever throws, so this method never
     * throws either.
     *
     * @param string   $action        Plain-English text of the attempt (see check()).
     * @param string   $verdict       NOT_FOUND or GLOBAL_ONLY (never called for ALLOW).
     * @param string   $reason        One of the REASON_WORDS keys.
     * @param int|null $targetUserId  The account number that was asked for, or null when none was given at all.
     *
     * @return void
     */
    public static function logRefusal(string $action, string $verdict, string $reason, ?int $targetUserId): void
    {
        $actorId = ApiAuth::actorUserId();

        // 1. Activity-log line — visible to this organisation's own
        //    administrators, so it must never say WHY beyond "not found"
        //    or "only a global administrator", and never name the
        //    target's own details.
        $text = ($verdict === self::NOT_FOUND)
            ? ('Refused: ' . $action . '. Not found in this organisation.')
            : ('Refused: ' . $action . '. Only a global administrator can do this.');
        Logger::activity('AccountChangeRefused', $text, $actorId);

        // 2. Security record — organisation left NULL on purpose, so
        //    /admin/errors shows it only to a global administrator
        //    (errors/index.php scopes non-umbrella administrators to
        //    `e.siteID = ?`, which a NULL row never matches). This is
        //    what lets a global administrator see every refusal across
        //    every organisation in one place, without exposing the
        //    refusal to the organisation that triggered it.
        $reasonWords = self::REASON_WORDS[$reason] ?? $reason;
        $source      = ApiAuth::source();
        $cameThrough = ($source === 'apikey')
            ? ('API key #' . (ApiAuth::apiKeyId() ?? 0))
            : 'a signed-in session';

        $detail = 'Action: ' . $action . "\n"
            . 'Verdict: ' . $verdict . "\n"
            . 'Reason: ' . $reasonWords . "\n"
            . 'Target account number: ' . ($targetUserId !== null ? (string) $targetUserId : 'none given') . "\n"
            . 'Organisation open: #' . Site::id() . "\n"
            . 'Came through: ' . $cameThrough;

        Logger::errorPlatformForSite(
            null,
            'Security',
            'Warning',
            'ACCOUNT_CHANGE_REFUSED',
            'Account change refused',
            $detail,
            $actorId
        );
    }

    // =========================================================================
    // 💬 What the person sees
    // =========================================================================

    /**
     * The plain-English message to show for a refusal. NEVER names the
     * actual reason a refusal happened for — see the class docblock. A
     * NOT_FOUND verdict always gets the same wording regardless of
     * reach, because it is meant to be indistinguishable from an account
     * number that was simply wrong.
     *
     * @param string $verdict NOT_FOUND or GLOBAL_ONLY.
     * @param string $reach   One of the REACH_* constants.
     *
     * @return string
     */
    public static function message(string $verdict, string $reach): string
    {
        if ($verdict === self::NOT_FOUND) {
            return 'That account could not be found.';
        }

        if ($verdict === self::GLOBAL_ONLY) {
            if ($reach === self::REACH_PORTAL) {
                return 'Only a global administrator can give or remove administrator rights across the whole portal.';
            }
            return 'Only a global administrator can change this account, because a change made here could reach beyond this organisation.';
        }

        return '';
    }

    // =========================================================================
    // 📋 Scoping a LIST of accounts to this organisation
    // =========================================================================

    /**
     * Build the SQL fragment (plus its bind type and value) that limits a
     * LIST query — user list, offboarding list, DBS list — to accounts
     * that belong to the organisation that is open, for a non-global
     * administrator on a multi-organisation installation.
     *
     * The column name is the ONE piece this method puts straight into
     * SQL text, so it is validated FIRST, before calling anything else
     * (actorIsGlobal(), isSingleOrganisation()) — it must always come
     * from code written by a developer, never from a request, and the
     * check runs before any other class is touched so it can throw with
     * no database connection at all (the self-test relies on exactly
     * this).
     *
     * @param string $userIdColumn A plain `table.column` identifier, e.g. 'u.userID'. Never request-derived.
     * @param bool   $includeEnded Whether an ENDED membership row still counts (offboarding's own list does; everywhere else does not).
     *
     * @return array{0: string, 1: string, 2: array} [$sql, $bindTypes, $bindValues]. $sql is '' (no scoping needed) for a global administrator or a single-organisation installation.
     *
     * @throws \InvalidArgumentException When $userIdColumn is not a plain table.column identifier.
     */
    public static function memberScopeSql(string $userIdColumn, bool $includeEnded = false): array
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*\.[A-Za-z_][A-Za-z0-9_]*$/', $userIdColumn) !== 1) {
            throw new \InvalidArgumentException(
                'AccountGuard::memberScopeSql: "' . $userIdColumn . '" is not a plain table.column identifier.'
            );
        }

        if (self::actorIsGlobal() === true || self::isSingleOrganisation() === true) {
            return ['', '', []];
        }

        $activeOnly = ($includeEnded === true) ? '' : ' AND gm.isActive = 1';
        $sql = 'EXISTS (SELECT 1 FROM tblUserSites gm WHERE gm.userID = ' . $userIdColumn . ' AND gm.siteID = ?' . $activeOnly . ')';

        return [$sql, 'i', [Site::id()]];
    }

    // =========================================================================
    // 📧 #521 — does an email address block "create account" or "change email"?
    // =========================================================================

    /** Free — nothing on the whole installation uses this address. */
    public const EMAIL_FREE = 'free';

    /** In use, but by an account the ACTING administrator may already see — no oracle, nothing recorded. */
    public const EMAIL_IN_USE_HERE = 'in_use_here';

    /** In use by an account OUTSIDE the acting administrator's reach — refused, bounded, recorded. */
    public const EMAIL_NOT_AVAILABLE = 'not_available';

    /** The acting administrator (or key) has collected too many EMAIL_NOT_AVAILABLE refusals recently. */
    public const EMAIL_TOO_MANY = 'too_many';

    /** How many "not available here" refusals one non-global actor may collect in EMAIL_CLASH_WINDOW seconds. */
    public const EMAIL_CLASH_LIMIT = 5;

    /** The window, in seconds, EMAIL_CLASH_LIMIT applies over — one hour. */
    public const EMAIL_CLASH_WINDOW = 3600;

    /**
     * Is an email address available for "create account" or "change email",
     * and — if not — is that because it belongs to an account the acting
     * administrator may already see, or one outside their reach?
     *
     * WHAT WAS WRONG BEFORE (issue #521)
     * -----------------------------------------------------------------------
     * "Another user with that email address already exists" told an
     * administrator, on a MULTI-organisation installation, whether an
     * address belongs to an account ANYWHERE on the installation — including
     * organisations that administrator has no business knowing anything
     * about. Addresses must stay unique across the whole installation (one
     * local-login row per address), so the fix is not simply to remove the
     * message; it is to bound what the message can be used to learn.
     *
     * THE DESIGN, IN ONE SENTENCE: an administrator may learn, once per
     * attempt, that an address "can't be used here" (which is the same fact
     * as "belongs to an account somewhere"), at most EMAIL_CLASH_LIMIT times
     * per EMAIL_CLASH_WINDOW seconds, with every one of those attempts
     * recorded where a GLOBAL administrator can see it and act (typically:
     * add that person to the organisation that needs them).
     *
     * WHAT THIS DELIBERATELY STILL ALLOWS (say this plainly; do not imply
     * the oracle is closed)
     * -----------------------------------------------------------------------
     * An administrator still learns, once per attempt and at most five times
     * an hour, that a specific address belongs to SOMEBODY, somewhere. This
     * BOUNDS the oracle — a systematic sweep of a customer list is slow and
     * conspicuous, and every attempt is on the record — it does NOT CLOSE
     * it. Fully closing it needs a different design entirely (a blind
     * invitation flow that looks identical whether the address is free or
     * taken), which is bigger than this package and is recorded as a
     * follow-up issue rather than built here.
     *
     * ORDER OF OPERATIONS, AND WHY THE LIMIT IS CHECKED FIRST
     * -----------------------------------------------------------------------
     * The rate limit is checked BEFORE the address is even looked up. If the
     * lookup ran first and the limit only applied to the RESULT, the limit
     * itself would become a second oracle: an administrator could tell an
     * address was "interesting" by noticing whether it counted toward their
     * hourly allowance. Checking the limit first means once an actor is over
     * it, EVERY address — used or not — gets the same "too many" wording,
     * so the limit reveals nothing about any particular address.
     *
     * A GLOBAL actor is NEVER limited and NEVER recorded: there is nothing
     * to hide from an administrator who can already see every account on
     * the installation.
     *
     * @param string   $email         The address being checked. Normalised
     *                                (lower-cased, trimmed) before anything
     *                                else, so the same address in different
     *                                letter-casing hits the same bucket and
     *                                the same lookup.
     * @param int|null $excludeUserId On an UPDATE, the account whose own
     *                                current address should not count
     *                                against itself. Null on create.
     * @param string   $action        Plain-English text describing the
     *                                attempt (see check()'s own parameter of
     *                                the same name) — containing ONLY what
     *                                the person themselves supplied, never
     *                                the address itself or the account it
     *                                turned out to belong to.
     *
     * @return string One of EMAIL_FREE / EMAIL_IN_USE_HERE / EMAIL_NOT_AVAILABLE / EMAIL_TOO_MANY.
     */
    public static function emailAvailability(string $email, ?int $excludeUserId, string $action): string
    {
        $normalised = strtolower(trim($email));

        $actorGlobal = self::actorIsGlobal();
        $bucket      = self::emailClashBucket();

        // 🚦 Checked FIRST, before any lookup — see the docblock above for
        //    why: once a non-global actor is over the limit, an UNUSED
        //    address is refused too, so the limit itself cannot be used to
        //    learn anything about any one address.
        if ($actorGlobal === false && RateLimiter::tooMany($bucket, self::EMAIL_CLASH_LIMIT, self::EMAIL_CLASH_WINDOW) === true) {
            return self::EMAIL_TOO_MANY;
        }

        $sql = 'SELECT userID FROM tblUsers WHERE emailAddress = ?';
        $types  = 's';
        $params = [$normalised];
        if ($excludeUserId !== null) {
            $sql     .= ' AND userID != ?';
            $types   .= 'i';
            $params[] = $excludeUserId;
        }
        $sql .= ' LIMIT 1';

        $stmt = App::db()->prepare($sql);
        if ($stmt === false) {
            // 🛡️ Fail the same direction as a genuine database fault
            // elsewhere in this class: refuse the specific claim (nothing is
            // asserted to be free when the check could not actually run),
            // without recording anything for an address that was never
            // successfully looked up at all.
            return self::EMAIL_NOT_AVAILABLE;
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($existing === null) {
            return self::EMAIL_FREE;
        }

        $existingId = (int) $existing['userID'];

        // 👀 Found, but it is an account this actor may already see — no
        //    oracle: they can already find this out on the users list, so
        //    there is nothing to bound or record.
        if (self::verdict($existingId, self::REACH_VIEW) === self::ALLOW) {
            return self::EMAIL_IN_USE_HERE;
        }

        // 🚫 Found, and OUTSIDE this actor's reach — the bounded, recorded
        //    branch. A global actor never reaches here (verdict() above
        //    always allows REACH_VIEW for them), so everything below only
        //    ever runs for a non-global actor.
        RateLimiter::recordHit($bucket, self::EMAIL_CLASH_WINDOW, 'emailclash:');

        $actorId = ApiAuth::actorUserId();

        // 1. Activity-log line — visible to THIS organisation's own
        //    administrators. Carries no address and no account number, the
        //    same discipline logRefusal() already applies.
        Logger::activity(
            'EmailClashRefused',
            'Refused: ' . $action . '. That email address is not available in this organisation.',
            $actorId
        );

        // 2. Security record — organisation left NULL, exactly as
        //    logRefusal() does, so ONLY a global administrator sees it
        //    (errors/index.php scopes a non-umbrella administrator to
        //    `siteID = ?`, which a NULL row never matches). Unlike the
        //    activity line, THIS record carries the address and the
        //    existing account number — a global administrator needs both
        //    to act (typically: add the person to the organisation that
        //    needs them).
        $source      = ApiAuth::source();
        $cameThrough = ($source === 'apikey')
            ? ('API key #' . (ApiAuth::apiKeyId() ?? 0))
            : 'a signed-in session';
        $detail = 'Action: ' . $action . "\n"
            . 'Email address: ' . $normalised . "\n"
            . 'Belongs to account number: ' . $existingId . "\n"
            . 'Organisation open: #' . Site::id() . "\n"
            . 'Came through: ' . $cameThrough;
        Logger::errorPlatformForSite(
            null,
            'Security',
            'Warning',
            'EMAIL_CLASH_OUTSIDE_ORG',
            'Email address refused: belongs to an account outside the open organisation',
            $detail,
            $actorId
        );

        return self::EMAIL_NOT_AVAILABLE;
    }

    /**
     * The plain-English message for one of emailAvailability()'s verdicts.
     *
     * @param string $verdict One of the EMAIL_* constants.
     *
     * @return string Empty string for EMAIL_FREE (never shown).
     */
    public static function emailMessage(string $verdict): string
    {
        if ($verdict === self::EMAIL_IN_USE_HERE) {
            return 'Another account in this organisation already uses that email address.';
        }
        if ($verdict === self::EMAIL_NOT_AVAILABLE) {
            return 'That email address can\'t be used here. If you believe this person should be a '
                . 'member of this organisation, ask a global administrator.';
        }
        if ($verdict === self::EMAIL_TOO_MANY) {
            return 'Too many email addresses that can\'t be used here have been tried from this account '
                . 'in the last hour. Try again later, or ask a global administrator.';
        }

        return '';
    }

    /**
     * How many seconds until the acting administrator's (or key's) email
     * rate limit bucket has room for another EMAIL_NOT_AVAILABLE attempt.
     * Used to populate a Retry-After header on the API's 429 response.
     *
     * @return int Seconds, or 0 if not currently over the limit.
     */
    public static function emailRetryAfter(): int
    {
        return RateLimiter::retryAfter(self::emailClashBucket(), self::EMAIL_CLASH_LIMIT, self::EMAIL_CLASH_WINDOW);
    }

    /**
     * The rate-limit bucket for the CURRENT actor's email-clash allowance.
     *
     * Keyed by API key number for a bearer request (an API key has no
     * `actorUserId()` of its own — that method returns null for a key), or
     * by account number for a session request. Deliberately does NOT fold
     * in the email address itself: the bucket is about how many refusals
     * ONE ACTOR has collected, not about any one address, which is exactly
     * what stops the limit itself becoming a second oracle (see
     * emailAvailability()'s own docblock).
     *
     * @return string
     */
    private static function emailClashBucket(): string
    {
        if (ApiAuth::source() === 'apikey') {
            return 'emailclash:key:' . (int) ApiAuth::apiKeyId();
        }

        return 'emailclash:user:' . (int) ApiAuth::actorUserId();
    }
}
