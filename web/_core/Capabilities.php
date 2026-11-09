<?php
// Path: _core/Capabilities.php
/**
 * -----------------------------------------------------------------------------
 * What opens a door, and which roles open it 🚪 (#727)
 * -----------------------------------------------------------------------------
 * The ONE place a "capability" is declared, the ONE place an organisation's
 * chosen set of roles for one is read or written, and the ONE place the
 * question "may this person do this?" is answered. Nothing else may keep a
 * second copy of any of the three.
 *
 * WHY THIS EXISTS
 * -------------------------------------------------------------------------
 * Every gate in the portal is a line of PHP: `App::hasRole('treasurer')`.
 *
 * ⚠️ DO NOT COUNT THEM WITH `grep`, AND THE EARLIER VERSION OF THIS HEADER
 *    TOLD YOU TO. It recommended
 *        grep -rhoE "App::hasRole\(" web --include='*.php' | wc -l
 *    and called its answer "the real figure". That command counts every
 *    mention in a comment or a docblock as well as every call: it returned
 *    87 on commit `b12ff44` where the tokeniser found 65 calls, and it
 *    returns a smaller number on this working tree than it did before the
 *    gates were converted, because converting one leaves a comment behind
 *    naming the gate it replaced. Count the CALLS, with PHP's own tokeniser
 *    and comments dropped — `tools/role-gate-count.php` does exactly that
 *    and prints the literal / non-literal split as well. A customer on
 *    shared hosting cannot edit PHP,
 * so a role they created could RECORD that somebody holds a responsibility
 * and could never OPEN A DOOR. The owner asked for that on 3 November 2026:
 * "We need to avoid hardcoding gating of features, and should have
 * flexibility to have gating assigned to roles (single or many roles) as
 * needed in a flexible way."
 *
 * A CAPABILITY IS NOT A PAGE, AND IT IS COARSE ON PURPOSE
 * -------------------------------------------------------------------------
 * Gating by address would break the moment a page moved, and several gates
 * are inside a page (a button, a column). Worse, by far the largest group of
 * literal gates named ONE role — the asset register. Measured on commit
 * `b12ff44` with `tools/role-gate-count.php asset_manager`, before this
 * package converted them: 37 calls in 36 files, out of 61 literal calls
 * altogether, so one role accounted for well over half of every gate in the
 * portal. "Make each call site configurable" would therefore have offered a
 * customer dozens of switches with the same answer repeated down most of
 * them. A capability is instead a thing a person recognises ("manage the
 * asset register"), named here, and it may cover many call sites.
 * `assets.manage`'s own `askedAt` list is the proof of that: one capability,
 * and the audit check counts the paths so no number here can go stale.
 *
 * ⚠️ THAT GREP NO LONGER FINDS THE GATES, which is worth knowing before
 *    anybody re-runs it and concludes the work was not done.
 *    `grep -rn "App::hasRole('asset_manager')" web --include='*.php'` returns
 *    19 hits on this working tree and EVERY ONE IS A COMMENT — the line each
 *    converted file keeps, saying which gate the capability replaced. Zero
 *    are calls. Use the tokeniser.
 *
 * WHO GETS IN WITHOUT A ROLE (and do not count the capabilities here — this
 * heading said "THE SIX" and #739 made it twelve)
 * -------------------------------------------------------------------------
 * The owner settled this on 3 November 2026: "Five, plus #724's review. then
 * all remaining features pointable as gated features, all standard roles'
 * gates. with organisational global admin having automatic access to
 * everything, who can then re-assign later."
 *
 * So the five named that day — the asset register, the pastoral care
 * register, children's check-in, the expenses treasury and the livestream
 * chat — all declare `globalAdminFallsOpen => true`, which keeps each
 * converted gate answering EXACTLY what `App::hasRole()` answered before it.
 * `erasure.review` keeps FALSE, which is #724's deliberate exception and the
 * one place a global administrator is refused. The two answers are opposite,
 * which is why no field has a default: see `DECLARED_FIELDS`.
 *
 * ⚠️ #739 CONVERTED THE REST (4 November 2026), so the registry now holds
 *    twelve capabilities rather than six. Three `App::hasRole()` calls are
 *    left under `web/` and every one of them is named, with its reason, in
 *    `tools/audit-checks/check_no_new_role_gates.py` — two take their key
 *    from a row a customer filled in and so cannot be a capability at all,
 *    and the third is a no-op (see the `photos.staff` entry). That check is
 *    a hard gate and fails BOTH ways, so a NEW hard-coded gate cannot be
 *    added and a listed exception cannot quietly stop being true. A gate not
 *    named in an `askedAt` list below is still hard-coded, and
 *    `check_capability_registry.py` fails if a gate is converted without
 *    being written down.
 *
 * ANY-OF, NEVER ALL-OF
 * -------------------------------------------------------------------------
 * A capability holds zero or more role keys and holding ANY ONE of them opens
 * the door (the owner, 3 November 2026). All-of would be a different feature
 * — a two-person rule — and nothing asks for it. If it is ever wanted it is a
 * new flag on the entry, never a change of meaning here, because every
 * existing mapping would change meaning overnight.
 *
 * AN ORGANISATION THAT NEVER OPENS THE PAGE SEES NO CHANGE
 * -------------------------------------------------------------------------
 * With no stored choice, a capability's roles are the `standard` list in its
 * own entry below — the same key the hard-coded gate used. That is the only
 * way this could be shipped safely: #499's "fail closed" was built first and
 * locked members out.
 *
 * THE EMPTY SET IS A DECISION, AND THERE IS NO "CANNOT BE EMPTIED" FLAG
 * -------------------------------------------------------------------------
 * Zero roles is allowed, it means exactly what each entry's `emptyMeans`
 * says, and the page makes somebody confirm it in as many words before it is
 * written. There is deliberately NO flag refusing it, and the reason is a
 * fact about the repair route rather than an opinion: the page that changes a
 * mapping is `web/_apps/admin/roles/`, which is gated on `App::isAdmin()` and
 * on no capability at all, so an empty set is always recoverable by any
 * administrator. `tools/audit-checks/check_capability_registry.py` enforces
 * that fact — it fails if any capability is ever asked from a file under
 * `web/_apps/admin/roles/`, which is the one thing that would make an empty
 * set permanent. If a capability is ever added whose own management page is
 * gated on itself, THAT is when a refusal flag is needed, and it must come
 * with the branch exercised by a test: a flag nothing can reach is worse than
 * no flag, because it reads as a protection.
 *
 * ⚠️ A MAPPING CANNOT FALL OPEN FOR A GLOBAL ADMINISTRATOR UNLESS ITS ENTRY
 *    SAYS SO, and that property is the whole reason this class can be trusted
 *    with #724's gate. `App::hasRole()` answers TRUE for any global
 *    administrator who holds no role row — deliberate since #516 — so the
 *    erasure-review gate asked `Roles::has()` directly instead, and a
 *    registry that resolved everything through `App::hasRole()` would have
 *    silently undone the reason that role exists. `globalAdminFallsOpen` is a
 *    declaration on the capability, not a special case in one file, so the
 *    next one like it is a line in a list.
 *
 * WHAT THIS CLASS CANNOT DO, said plainly
 * -------------------------------------------------------------------------
 *   - It remembers one thing for the rest of ONE request: which roles open a
 *     door here. See `forgetMemo()` for exactly what that covers and what it
 *     deliberately does not — granting somebody a role still takes effect on
 *     the very next call, because a PERSON's answer is never remembered.
 *   - It cannot see the `App::hasRole()` call sites that work their key out
 *     at RUN TIME, and there are far fewer of those than an earlier version
 *     of this list implied. Run `tools/role-gate-count.php` for today's
 *     figure; on commit `b12ff44` it was 4 of 65, and TWO of those four
 *     passed a class constant (`ExpenseRules::EXPORT_ROLE`,
 *     `Giving::ROLE_KEY`) — a fixed key written once rather than a variable
 *     one, which is why #739 could convert both and why neither constant
 *     exists any more. Only two are genuinely worked out:
 *     `ReportRegistry::userPassesGates()`, which walks a report's own list
 *     of gate names, and `web/_apps/tours/api/active.php`, which walks a
 *     guided tour's comma-separated `forRoles` column. Both take the key
 *     from a row a customer filled in, so neither is a page gate and
 *     neither is a capability. (The three examples this list used to name — a workflow's
 *     assignee, a notification audience, a report filter — were not
 *     `App::hasRole()` call sites at all; they are SQL joins against
 *     `tblUserRoles`.)
 *   - It knows nothing about who may CHANGE a mapping. That is the page's
 *     `App::isAdmin()` gate, plus `whyNotChangeable()` for the two parties'
 *     boundary.
 *   - It cannot stop an organisation naming a role nobody holds. The page
 *     warns; refusing would block the ordinary two-step "make the mapping,
 *     then grant the role".
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/727
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use mysqli;

final class Capabilities
{
    /**
     * #724's data-controller review — the first real consumer, and the
     * reason this class exists now rather than later. Named as a constant so
     * no page spells the key by hand.
     */
    public const ERASURE_REVIEW = 'erasure.review';

    /**
     * The five the owner named on 3 November 2026, offered since then. Each
     * is a constant for the same reason as the one above: a page that spells
     * a key by hand can mistype it, and a mistyped key throws from
     * `definition()` only when that page is next opened.
     */
    public const ASSETS_MANAGE      = 'assets.manage';
    public const CARE_REGISTER      = 'care.register';
    public const KIDS_CHECKIN       = 'kids.checkin';
    public const EXPENSES_TREASURY  = 'expenses.treasury';
    public const LIVESTREAM_CONSOLE = 'livestream.console';

    /**
     * The SIX added by #739, which finished the conversion: every remaining
     * gate that named a standard role by a fixed key is now one of these,
     * with one measured exception named below. The owner asked for exactly
     * that on 3 November 2026 — "all remaining features pointable as gated
     * features, all standard roles' gates" — and said in the same breath
     * that this is "the mechanism going forward for all new features", so a
     * new feature DECLARES one of these rather than writing `App::hasRole()`
     * again.
     *
     * ⚠️ SIX IS NOT "ONE PER CALL SITE", and the difference matters because a
     *    page of switches nobody can read is its own fault.
     *
     *    **The arithmetic, corrected — a first version of this comment said
     *    "12 remaining gates naming 6 different role keys", and both numbers
     *    were wrong while the bullets below them summed to thirteen.**
     *    Measured on HEAD with PHP's tokeniser: 16 `App::hasRole()` calls =
     *    12 passing a literal + 2 passing a class constant + 2 working the
     *    key out at run time. So **14 gates passed a FIXED key, naming SEVEN
     *    role keys, and 13 of the 14 became SIX capabilities**:
     *      • SIX were the SAME door (`groups_coordinator`: the five
     *        small-groups pages and `SmallGroups::canManage()`) — one
     *        capability.
     *      • THREE were the expense-approval door — two literal
     *        `App::hasRole('Approver')` gates plus
     *        `ExpenseRules::EXPORT_ROLE`, the same `approver` role asked
     *        through a constant. One capability.
     *      • `treasurer` (through `Giving::ROLE_KEY`), `prayer_team`,
     *        `venue_manager` and `staff` were one gate each — four
     *        capabilities. **That last one, the staff rung in `Photos.php`,
     *        was missing from the first version of this list altogether**,
     *        which is how twelve came to be written where fourteen belonged.
     *      • The fourteenth, `App::hasRole('volunteer')` in `Photos.php`, is
     *        DELIBERATELY NOT CONVERTED. It is a no-op — both branches return
     *        the same rank — so a switch for it would open nothing (#756).
     *        See the `photos.staff` entry, which explains it where somebody
     *        reading the photo tiers will find it.
     *    Count them with `count(Capabilities::CAPABILITIES)` or
     *    `php tools/role-gate-count.php`; do not trust a number in prose —
     *    including these.
     */
    public const EXPENSES_APPROVE = 'expenses.approve';
    public const GIVING_RECORDS   = 'giving.records';
    public const GROUPS_MANAGE    = 'groups.manage';
    public const PRAYER_MODERATE  = 'prayer.moderate';
    public const VENUES_MANAGE    = 'venues.manage';
    public const PHOTOS_STAFF     = 'photos.staff';
    public const PHOTOS_VOLUNTEER = 'photos.volunteer';
    public const PORTAL_CHECK     = 'portal.check';

    /**
     * #493 package P1 (the owner, 7 November 2026 — D-077): who may turn an
     * event's "Show on the public website" switch and dates on or off. Asked
     * in ONE place, `PublicWebsite::mayPublish()`, which every writer that can
     * SET the switch or its dates asks: the Manage Events form and the events
     * API. Every other writer of an event — the CSV import, the series bulk
     * edit, moderation, the public submission form and the outside-calendar
     * importer — never names the three columns, so what it makes is off the
     * website; a series pattern change does name them, because it copies a
     * whole template row, and writes the switch off and both dates empty. ⚠️ Give any of them those columns (#844 asks
     * for one) and it must ask this too. (This said "which every writer of an
     * event asks" until round 5 of #493 P1's check.) Two kinds of administrator pass there BEFORE the capability
     * is asked: an administrator of the organisation (`isAdminOfSite()`), and
     * an active account holding the portal-wide `isAdmin` flag — what Admin →
     * Users sets — that belongs to this organisation (round 1 of #493's check:
     * leaving that one out refused every administrator after the first on a
     * single-organisation installation). So out of the box only administrators
     * publish — D-077's own words — and an organisation can add any role on the
     * Roles page. The role it ships pointing at, `website_editor`, is held by
     * nobody until somebody grants it (migration 258 seeds it and grants
     * nothing).
     */
    public const WEBSITE_PUBLISH  = 'website.publish';

    /**
     * Every field an entry in either list MUST declare, in the order the
     * entries are written.
     *
     * ⚠️ THIS IS WHAT STOPS THE TWO DEFAULTS BEING CONFUSED, and it is the
     *    reason it exists. `globalAdminFallsOpen` is TRUE for every
     *    capability the owner asked for on 3 November 2026 and FALSE for
     *    `erasure.review` (#724). Those are opposite answers to the same
     *    question, so an entry that simply LEFT THE FIELD OUT would be the
     *    dangerous case: whichever way the code happened to read a missing
     *    value, half the registry would be wrong and nothing on any page
     *    would look different.
     *
     *    So there is no default anywhere. `definition()` refuses an
     *    incomplete entry by throwing, with the capability and the missing
     *    field named, and `whyEntryIncomplete()` is public so a test can ask
     *    it of an entry this file could not legally contain. The audit check
     *    `tools/audit-checks/check_capability_registry.py` refuses the same
     *    thing before anything runs (its check A), and it fails both ways —
     *    a missing field and an extra one.
     *
     * @var list<string>
     */
    private const DECLARED_FIELDS = [
        'label',
        'opens',
        'standard',
        'globalAdminFallsOpen',
        'organisationMayRepoint',
        'emptyMeans',
        'askedAt',
    ];

    /**
     * Where an organisation's choice is kept: one row per (organisation,
     * capability) saying a choice HAS been made, and the role keys beside it
     * in the second table.
     *
     * ⚠️ TWO TABLES, AND THE SECOND IS NOT A TIDY-UP. "Chosen, and empty"
     *    must be distinguishable from "never touched", because the first
     *    means "nobody, deliberately" and the second means "the standard
     *    role, as shipped". One table cannot say both without a sentinel row,
     *    and a sentinel holding an empty `roleKey` could not carry the
     *    foreign key to `tblRoles` that makes a deleted role disappear from
     *    every set it was in.
     */
    public const MAP_TABLE      = 'tblCapabilityMap';
    public const MAP_ROLE_TABLE = 'tblCapabilityMapRole';

    /**
     * The installation-wide settings key holding the capability keys an
     * organisation may NOT re-point, comma separated. Read ONLY through
     * `installationLocks()`; never read this key anywhere else.
     */
    public const LOCK_SETTING_KEY = 'capabilities.locked';

    /**
     * What somebody is told when they try to change the lock list in the
     * general settings editor.
     *
     * Worded like the other four locked keys' messages: it says where the
     * setting IS changed, and that nothing happened. A bare "forbidden" sends
     * somebody looking in the database.
     */
    public const LOCKED_MESSAGE = 'That setting records which capabilities an organisation may not re-point, and '
        . 'it is changed on the Roles page (Admin, Roles) and nowhere else. A global administrator opens that page, '
        . 'ticks the capabilities this installation reserves, and the change is recorded. It cannot be added, '
        . 'changed or deleted here. Nothing was changed.';

    /**
     * 🔒 Is this the lock list, which the general settings editor must refuse?
     *
     * WHY. `capabilities.locked` is an ordinary installation-wide
     * `tblSettings` row, so until 3 November 2026 a global administrator could
     * edit it in the general settings editor — which is a page of key/value
     * boxes with no idea what any key means. Doing it there skips all three
     * things `saveInstallationLocks()` does: it drops an unknown or mistyped
     * capability key instead of storing it, it writes an audit record, and it
     * writes an activity record. A row reading `capabilities.locked =
     * assets.manag` would then silently reserve nothing while reading as a
     * reservation.
     *
     * It is NOT a privilege question — the same global administrator is
     * entitled to set it either way, which is why there is no harm on record.
     * It is about the change going through the one writer, so the validation
     * and the two records happen. Every other key with a page of its own has
     * this refusal (`Site`, `MailRoutes`, `Maintenance`, `GivingRetention`),
     * and `Capabilities`' own class comment and migration 243's header both
     * already claimed the key was "written in exactly ONE place" — this is
     * what makes that true rather than aspirational.
     *
     * Asked in FOUR places, as the other four are: changing a row and adding
     * one (`web/_apps/settings/save.php`), deleting one and drawing the row
     * (`web/_apps/settings/index.php`).
     *
     * WHAT IT CANNOT DO: stop somebody changing the row straight in the
     * database, or restoring a backup that holds a different value.
     *
     * Names are compared ignoring capitals, as the database does.
     *
     * @param string $key The settings key being changed, added or deleted.
     *
     * @return bool
     */
    public static function lockedInGeneralEditor(string $key): bool
    {
        return strcasecmp($key, self::LOCK_SETTING_KEY) === 0;
    }

    /**
     * The capabilities a role may be pointed at, and everything true about
     * each one in ONE place.
     *
     * Each entry declares, in this order:
     *   label                  — what it is called on the page.
     *   opens                  — what it opens, in plain words, for somebody
     *                            who does not work on this system.
     *   standard               — the role key(s) it ships with. These are the
     *                            roles the hard-coded gate used, so an
     *                            organisation that never opens the page sees
     *                            no change. Must be keys in
     *                            `Roles::STANDARD`, or no organisation has
     *                            them (the #516 shape).
     *   globalAdminFallsOpen   — whether a global administrator passes
     *                            without holding any of the roles. TRUE
     *                            matches every ordinary gate in the portal
     *                            (`App::hasRole()`) and is what the owner
     *                            asked for on 3 November 2026: "with
     *                            organisational global admin having automatic
     *                            access to everything, who can then
     *                            re-assign later". FALSE is #724's exception
     *                            and is a privacy boundary between two
     *                            parties, not a preference. THERE IS NO
     *                            DEFAULT — see `DECLARED_FIELDS` above for
     *                            why an entry that omits it throws.
     *   organisationMayRepoint — whether an organisation may change the set
     *                            at all. The code's own answer; the
     *                            installation can refuse on top of it
     *                            (`installationLocks()`), never the other way
     *                            round.
     *   emptyMeans             — what zero roles does, in the words the page
     *                            shows before a confirmation. Never a hedge:
     *                            it is the sentence somebody accepts a loss
     *                            of access on.
     *   askedAt               — every file that asks this capability, as a
     *                            path under the repository root. The audit
     *                            check reads each one and fails BOTH ways: a
     *                            capability claiming to be asked where it is
     *                            not, and a capability asked somewhere it
     *                            does not name.
     *
     * @var array<string, array{label:string, opens:string, standard:list<string>, globalAdminFallsOpen:bool, organisationMayRepoint:bool, emptyMeans:string, askedAt:list<string>}>
     */
    public const CAPABILITIES = [
        // 🧑‍⚖️ #724. The person answerable for personal data reads the free
        //    text an erasure rule cannot decide about and says what goes.
        self::ERASURE_REVIEW => [
            'label' => 'Decide the free text an erasure cannot',
            'opens' => 'Reading the lines of free text left over by an erasure request, and '
                . 'deciding for each one whether it is emptied or kept. A request cannot be '
                . 'completed while a line is undecided.',
            'standard' => ['data_controller'],
            // ⚠️ FALSE, AND IT IS THE POINT OF THE ROLE. An installation's
            //    administrator and an organisation's may be different parties
            //    (the standing rule of 29 October 2026). A hosting provider's
            //    administrator must not be able to read a member's own words
            //    to decide whether they must go. Changing this to true
            //    silently undoes #724.
            'globalAdminFallsOpen' => false,
            'organisationMayRepoint' => true,
            'emptyMeans' => 'Nobody will be able to decide these lines, so no erasure request will '
                . 'be able to complete until a role is named here again. Any administrator can '
                . 'change it back on this page.',
            'askedAt' => [
                'web/_apps/admin/erasure/review.php',
                'web/_apps/admin/erasure/review-save.php',
            ],
        ],

        // 📦 THE ASSET REGISTER — the biggest single group of gates in the
        //    portal: 37 calls in 36 files, which is why the owner named it
        //    first. 34 of those files are pages and handlers under
        //    `web/_apps/assets/`; the other two places are NOT in that folder
        //    and were found by reading rather than by trusting the folder
        //    name, which is the whole reason the list below is written out:
        //      • `web/_core/AssetRegister.php` — `canApproveLoan()` and
        //        `canManageMaintenance()` each fold the manager bypass in
        //        themselves (#398). Leaving those two alone would have let a
        //        customer's own role open every asset PAGE and then be
        //        refused the one action on an individual asset.
        //      • `web/_apps/calendar/event.php` — draws the Unassign button
        //        beside an event's assets. `event-assign.php` is the handler
        //        and is converted, so leaving the button behind would have
        //        hidden a control from exactly the people the handler admits
        //        (#675's fault the other way up).
        self::ASSETS_MANAGE => [
            'label' => 'Manage the asset register',
            'opens' => 'The asset register: adding and editing equipment, lending it out, taking '
                . 'stock, printing labels, running the kiosk a steward uses, and seeing an asset '
                . 'marked confidential at all.',
            'standard' => ['asset_manager'],
            'globalAdminFallsOpen' => true,
            'organisationMayRepoint' => true,
            // ⚠️ "ADMINISTRATORS STILL GET IN" IS NOT A HEDGE, IT IS THE FACT.
            //    Every one of these 37 gates reads "an administrator OR this
            //    capability", so an empty set shuts the ROLE out and nothing
            //    else. Saying "only a global administrator" — which an
            //    earlier draft of this entry did — is wrong twice over: an
            //    ordinary administrator of this organisation gets in through
            //    `App::isAdmin()`, and the people a particular asset names as
            //    responsible for it keep the access that is theirs on that
            //    asset.
            'emptyMeans' => 'No role will open the asset register. Administrators of this '
                . 'organisation will still be able to, because every one of these gates lets an '
                . 'administrator in as well, and anybody an individual asset names as responsible '
                . 'for it keeps the access that is theirs on that asset.',
            'askedAt' => [
                'web/_apps/assets/api/detail.php',
                'web/_apps/assets/api/list.php',
                'web/_apps/assets/api/qr.php',
                'web/_apps/assets/categories.php',
                'web/_apps/assets/delete.php',
                'web/_apps/assets/edit.php',
                'web/_apps/assets/event-assign.php',
                'web/_apps/assets/found-reports.php',
                'web/_apps/assets/identifier-verify.php',
                'web/_apps/assets/identifiers-save.php',
                'web/_apps/assets/import.php',
                'web/_apps/assets/index.php',
                'web/_apps/assets/item.php',
                'web/_apps/assets/kiosk-save.php',
                'web/_apps/assets/kiosks.php',
                'web/_apps/assets/kit-save.php',
                'web/_apps/assets/labels-pdf.php',
                'web/_apps/assets/labels.php',
                'web/_apps/assets/license-action.php',
                'web/_apps/assets/license-save.php',
                'web/_apps/assets/loan-save.php',
                'web/_apps/assets/loan.php',
                'web/_apps/assets/loans.php',
                'web/_apps/assets/locations.php',
                'web/_apps/assets/maintenance.php',
                'web/_apps/assets/orgs.php',
                'web/_apps/assets/owners-save.php',
                'web/_apps/assets/resource-download.php',
                'web/_apps/assets/resource-save.php',
                'web/_apps/assets/save.php',
                'web/_apps/assets/stocktake-save.php',
                'web/_apps/assets/stocktake.php',
                'web/_apps/assets/stocktakes.php',
                'web/_apps/assets/value-report.php',
                'web/_apps/calendar/event.php',
                'web/_core/AssetRegister.php',
            ],
        ],

        // 🫂 THE PASTORAL CARE REGISTER — the clearest case for a customer's
        //    own role, because the person who does this work is often called
        //    something else entirely (a safeguarding lead, a pastor, an elder).
        self::CARE_REGISTER => [
            'label' => 'Open the pastoral care register',
            'opens' => 'The confidential pastoral care register: who is being visited, by whom, '
                . 'and the notes of each visit.',
            'standard' => ['care_team'],
            'globalAdminFallsOpen' => true,
            'organisationMayRepoint' => true,
            'emptyMeans' => 'No role will open the pastoral care register. Administrators of this '
                . 'organisation will still be able to, because the gate lets an administrator in '
                . 'as well.',
            'askedAt' => [
                'web/_apps/care/index.php',
                'web/_apps/care/case.php',
                'web/_apps/care/case-save.php',
                'web/_apps/care/visit-save.php',
            ],
        ],

        // 🧒 CHILDREN'S CHECK-IN.
        self::KIDS_CHECKIN => [
            'label' => "Run children's check-in",
            'opens' => 'Checking children in and out, printing labels, and the register of who '
                . 'collected whom.',
            'standard' => ['kids_team'],
            'globalAdminFallsOpen' => true,
            'organisationMayRepoint' => true,
            'emptyMeans' => "No role will run children's check-in. Administrators of this "
                . 'organisation will still be able to, because the gate lets an administrator in '
                . 'as well.',
            'askedAt' => [
                'web/_apps/kids/checkin.php',
                'web/_apps/kids/checkin-do.php',
                'web/_apps/kids/checkout.php',
            ],
        ],

        // 💷 THE TREASURY SIDE OF EXPENSES, AND NOTHING ELSE THE TREASURER
        //    ROLE DOES. The `treasurer` role also opens the giving records,
        //    which since #739 is its OWN capability (`giving.records` below)
        //    and not this one — so a customer who points this capability at a
        //    finance officer has NOT thereby handed them the donor records.
        //    It is also read by three columns of the giving reports
        //    (`ReportRegistry`) and is the audience an expense notification
        //    goes to (`ExpenseMailer`); neither of those is a capability,
        //    because the first takes its gate name from a saved report — a
        //    row a customer filled in — and the second is a list of who is
        //    e-mailed rather than a decision about who may do something.
        //
        //    ⚠️ APPROVING A CLAIM IS A DIFFERENT DOOR AGAIN, and the
        //       distinction is one this repository has already paid for:
        //       `expenses.approve` below is the `approver` ROLE (the whole
        //       organisation) and `tblUserDepts.isApprover` is a flag on ONE
        //       department (#675). Neither is the treasury, and flattening
        //       them together would hand somebody every department's figures.
        self::EXPENSES_TREASURY => [
            'label' => 'Pay approved expense claims',
            'opens' => 'The treasury side of expenses: seeing every approved claim, recording a '
                . 'payment reference and marking a claim reimbursed.',
            'standard' => ['treasurer'],
            'globalAdminFallsOpen' => true,
            'organisationMayRepoint' => true,
            'emptyMeans' => 'No role will reach the treasury side of expenses. Administrators of '
                . 'this organisation will still be able to, because the gate lets an administrator '
                . 'in as well. Approving a claim, and the giving records, are different doors and '
                . 'are not affected.',
            'askedAt' => [
                'web/_apps/expenses/treasury/index.php',
                'web/_apps/expenses/treasury/save.php',
                'web/_apps/expenses/view/index.php',
            ],
        ],

        // 📺 MODERATING THE LIVESTREAM CHAT.
        //
        //    ⚠️ ITS GATES ARE NOT IN `web/_apps/livestream/`, AND THE EARLIER
        //       NOTE IN THIS FILE SAID THEY WERE. Measured:
        //       `web/_apps/livestream/` holds one file, `api/ping.php`, which
        //       has no role gate at all, and `web/_apps/admin/livestream/` is
        //       gated on `App::isAdmin()` plus the "we are live" push's own
        //       coordinator rule (`Auth::isCoordinatorOf()`, #612) — which is
        //       about ONE event's coordinator rather than a standing
        //       responsibility, so it is deliberately NOT this capability. The
        //       two files that really ask for the moderator role are the ones
        //       named below, found with
        //       `grep -rn "stream_moderator" web --include='*.php'`.
        self::LIVESTREAM_CONSOLE => [
            'label' => 'Moderate the livestream chat',
            'opens' => 'The live chat moderation queue: approving, hiding and flagging messages '
                . 'people send during a livestream.',
            'standard' => ['stream_moderator'],
            'globalAdminFallsOpen' => true,
            'organisationMayRepoint' => true,
            'emptyMeans' => 'No role will moderate the livestream chat. Administrators of this '
                . 'organisation will still be able to, because the gate lets an administrator in '
                . 'as well.',
            'askedAt' => [
                'web/_apps/admin/live/chat.php',
                'web/_apps/livechat/api/moderate.php',
            ],
        ],

        // =====================================================================
        // The six added by #739
        // =====================================================================

        // 💷 DECIDING AN EXPENSE CLAIM — and it is NOT the treasury above.
        //    `expenses.treasury` is PAYING a claim somebody else approved;
        //    this is saying yes or no to one. The repository has already paid
        //    for muddling the two (#675), so they stay separate doors even
        //    though one organisation may well point both at one role.
        //
        //    ⚠️ IT DOES NOT COVER THE PER-DEPARTMENT APPROVER FLAG, and that
        //       is the whole reason this entry is not simply "approvals".
        //       `tblUserDepts.isApprover` is a flag on ONE department and is
        //       read separately in `expenses/approve/save.php` AFTER this
        //       gate: a department lead with no role at all still decides
        //       their own department's claims, exactly as before. Pointing
        //       this capability at nobody therefore shuts out the
        //       organisation-wide approver and leaves every department's own
        //       people working.
        //
        //    The claims SPREADSHEET is part of this door on purpose:
        //    `ExpenseRules::mayExportClaims()` asked for the same `approver`
        //    role through `self::EXPORT_ROLE`, so it is the same set of
        //    people and the same list of claims seen another way. Splitting
        //    it would be a new switch that answered the same question.
        self::EXPENSES_APPROVE => [
            'label' => 'Decide expense claims',
            'opens' => 'Approving or rejecting expense claims across the whole organisation, '
                . 'opening any claim to read it, and downloading the claims spreadsheet.',
            'standard' => ['approver'],
            'globalAdminFallsOpen' => true,
            'organisationMayRepoint' => true,
            'emptyMeans' => 'No role will decide claims for the whole organisation. Administrators '
                . 'of this organisation will still be able to, because the gate lets an '
                . 'administrator in as well, and anybody marked as a lead or an approver of a '
                . 'particular department keeps the claims of that department. Paying an approved '
                . 'claim is a different door and is not affected.',
            'askedAt' => [
                'web/_apps/expenses/approve/save.php',
                'web/_apps/expenses/view/index.php',
                'web/_core/ExpenseRules.php',
            ],
        ],

        // 🎁 THE GIVING RECORDS — donors' names, home addresses and every
        //    gift. #727's own note said this was "its own capability when
        //    somebody asks for one", and #739 is somebody asking.
        //
        //    ⚠️ KEEP IT APART FROM `expenses.treasury`. Both ship with the
        //       `treasurer` role, so it is tempting to fold them together —
        //       and folding them would mean that a customer who pointed the
        //       expenses treasury at a finance officer had handed that person
        //       the donor list without being told. The two doors protect
        //       quite different information.
        //
        //    It does NOT cover the three giving columns of the reports, which
        //    `ReportRegistry` gates from a saved report's own list of gate
        //    names — a row a customer filled in, not a gate in PHP, so it
        //    cannot be a capability. Nor the expense notification audience in
        //    `ExpenseMailer`, which is a list of who is e-mailed rather than
        //    a decision about who may do something.
        self::GIVING_RECORDS => [
            'label' => 'Open the giving records',
            'opens' => "Every donor's giving: their name, their home address where one is held, "
                . 'each gift, and recording a gift against somebody. Without it a member sees '
                . 'only their own giving.',
            'standard' => ['treasurer'],
            'globalAdminFallsOpen' => true,
            'organisationMayRepoint' => true,
            'emptyMeans' => 'No role will open the giving records, and every member will see only '
                . 'their own giving. Administrators of this organisation will still be able to, '
                . 'because the gate lets an administrator in as well. Paying an approved expense '
                . 'claim is a different door and is not affected.',
            'askedAt' => [
                'web/_core/Giving.php',
            ],
        ],

        // 👥 SMALL GROUPS — six of the twelve remaining gates, and all six
        //    the same door: the five pages under `web/_apps/small-groups/`
        //    and `SmallGroups::canManage()`, which the group page and its
        //    handler ask about ONE group.
        //
        //    ⚠️ IT IS "EVERY GROUP", NOT "A GROUP". A group's own leaders and
        //       co-leaders keep what is theirs through
        //       `SmallGroups::isLeader()`, which reads the membership rows
        //       and asks no role at all. So an empty set takes the
        //       coordinator's view of every group away and changes nothing
        //       for a leader running their own group — which is also why this
        //       is not two capabilities.
        self::GROUPS_MANAGE => [
            'label' => 'Manage every small group',
            'opens' => 'Every small group at once: the full directory whether or not it is shown '
                . 'to members, creating and editing any group, the attendance reports, and the '
                . 'meeting place of a group whose location is otherwise hidden.',
            'standard' => ['groups_coordinator'],
            'globalAdminFallsOpen' => true,
            'organisationMayRepoint' => true,
            'emptyMeans' => 'No role will manage every small group. Administrators of this '
                . 'organisation will still be able to, because every one of these gates lets an '
                . "administrator in as well, and each group's own leaders keep the running of "
                . 'their own group.',
            'askedAt' => [
                'web/_apps/small-groups/group.php',
                'web/_apps/small-groups/index.php',
                'web/_apps/small-groups/manage.php',
                'web/_apps/small-groups/report.php',
                'web/_apps/small-groups/save.php',
                'web/_core/SmallGroups.php',
            ],
        ],

        // 🙏 MODERATING PRAYER REQUESTS.
        //
        //    ⚠️ IT GATES THE SESSION ROUTE ONLY, and the file says so in as
        //       many words. A request carrying an API key is authorised by
        //       that key's `prayer-requests:write` scope instead, because an
        //       API key holds no roles — so an organisation that empties this
        //       set has NOT closed the key route. That is unchanged from
        //       before #739 and is not something this capability can decide.
        //
        //    The `prayer_team` role is also the pool a request can be
        //    ASSIGNED from (`PrayerChain`), which is a list of people rather
        //    than a gate, so it is deliberately not part of this door.
        self::PRAYER_MODERATE => [
            'label' => 'Moderate prayer requests',
            'opens' => 'Approving, rejecting and hiding the prayer requests people send in.',
            'standard' => ['prayer_team'],
            'globalAdminFallsOpen' => true,
            'organisationMayRepoint' => true,
            'emptyMeans' => 'No role will moderate prayer requests from inside the portal. '
                . 'Administrators of this organisation will still be able to, because the gate '
                . 'lets an administrator in as well. A request sent by another system with its '
                . 'own API key is not affected, because a key holds no roles.',
            'askedAt' => [
                'web/_apps/prayer-requests/api/moderate.php',
            ],
        ],

        // 🏛️ MANAGING VENUE BOOKINGS. One gate serves every manager handler
        //    (`Venues::canManage()`, which that class's own comment calls
        //    "the ONE capability check every manager handler calls"), so one
        //    file covers the whole feature.
        //
        //    ⚠️ IT IS THE MANAGER'S DOOR, NOT THE READER'S. `Venues` has a
        //       separate "may this person see this organisation's bookings at
        //       all?" test that asks no role, so an empty set here leaves the
        //       read-only year schedule, the exports and the data endpoints
        //       exactly as they were.
        self::VENUES_MANAGE => [
            'label' => 'Manage venue bookings',
            'opens' => 'Creating, changing and cancelling venue bookings, and managing the venues, '
                . 'rooms and usage types behind them.',
            'standard' => ['venue_manager'],
            'globalAdminFallsOpen' => true,
            'organisationMayRepoint' => true,
            'emptyMeans' => 'No role will change venue bookings. Administrators of this '
                . 'organisation will still be able to, because the gate lets an administrator in '
                . 'as well, and anybody who may already READ this organisation\'s bookings keeps '
                . 'that, because reading them asks no role.',
            'askedAt' => [
                'web/_core/Venues.php',
            ],
        ],

        // 📷 THE TWO PHOTO TIERS. There are two entries now, and until
        //    4 November 2026 there was one — because the volunteer rung was a
        //    NO-OP and offering a switch for it would have been #671's "a
        //    seeded setting that nothing reads is a lie with a Save button"
        //    wearing a capability's clothes.
        //
        //    ⚠️ WHAT CHANGED IS A PRODUCT DECISION, NOT A TIDY-UP (the owner,
        //       4 November 2026 — D-047; #756). `Photos::userTierRank()` is a
        //       ladder: administrator 3, staff 2, volunteer 1 — and the line
        //       after the volunteer branch used to return 1 for EVERY
        //       signed-in member as well, deliberately, "to keep portals where
        //       role rollout is partial functional". So the two branches
        //       returned the same number and holding the role changed nothing
        //       for anybody, which had been true since `8625b9e`. Worse, the
        //       comment beside it said access was granted "ONLY if you've
        //       explicitly granted them — the role check above gates that",
        //       which was untrue in the REASSURING direction.
        //
        //       Asked which of the two was intended, the owner chose the
        //       comment: **only volunteers reach the volunteer tier.** So the
        //       fall-through returns 0, the rung became real, and a real rung
        //       is a capability (D-017). A signed-in member with no tier now
        //       sees exactly what a stranger sees, because `canView()` lets a
        //       `public` photo through before it asks about rank at all.
        //
        //       **This takes photos away from people who can see them today.**
        //       It is the one change in the capability conversion that does, and
        //       the owner was told so before choosing.
        //
        //    ⚠️ THE ORDER IS LOAD-BEARING IN THE CODE, NOT HERE. The ranking
        //       asks staff FIRST, so somebody holding both lands on the
        //       higher tier. That is in `Photos::userTierRank()`; this list
        //       does not and cannot express it.
        self::PHOTOS_STAFF => [
            'label' => 'See photos shared with staff',
            'opens' => 'Photo albums an organisation has shared with its staff — the higher of the '
                . 'two photo tiers, which also includes everything shared with volunteers.',
            'standard' => ['staff'],
            'globalAdminFallsOpen' => true,
            'organisationMayRepoint' => true,
            'emptyMeans' => 'No role will reach the albums shared with staff. Administrators of '
                . 'this organisation will still be able to, because the ranking puts an '
                . 'administrator at the top tier before it asks about any role. Albums shared with '
                . 'volunteers are reached through the separate "See photos shared with volunteers" '
                . 'setting and are not affected by this one; albums shared with every member are '
                . 'not affected either.',
            'askedAt' => [
                'web/_core/Photos.php',
            ],
        ],

        self::PHOTOS_VOLUNTEER => [
            'label' => 'See photos shared with volunteers',
            'opens' => 'Photo albums an organisation has shared with its volunteers — the lower '
                . 'of the two photo tiers. Albums shared with everybody are not affected: those '
                . 'are visible to anyone, signed in or not.',
            'standard' => ['volunteer'],
            'globalAdminFallsOpen' => true,
            'organisationMayRepoint' => true,
            'emptyMeans' => 'No role will reach the albums shared with volunteers. Administrators '
                . 'of this organisation will still be able to, because the ranking puts an '
                . 'administrator at the top tier before it asks about any role, and so will '
                . 'anybody who reaches the higher staff tier. Albums shared with everybody are '
                . 'not affected.',
            'askedAt' => [
                'web/_core/Photos.php',
            ],
        ],

        // 🔎 "Check this portal" (#477). Read-only: it reports and never
        //    changes anything, which is why it ships to a wider role than the
        //    pages that act on what it finds.
        //
        //    ⚠️ IT IS NOT A SECRET, AND THAT IS DELIBERATE. What it shows —
        //       a menu link going nowhere, a help guide nobody can reach, a
        //       setting stored twice — is the kind of thing a TESTER is most
        //       likely to hit and least able to describe. Hiding it behind the
        //       narrowest possible role would reproduce the problem the page
        //       exists to end: the fault is visible to the people who cannot
        //       report it and invisible to the people who can.
        self::PORTAL_CHECK => [
            'label' => 'Check this portal',
            'opens' => 'The "Check this portal" page: a read-only list of anything inconsistent '
                . 'in how this installation is set up — addresses with no page behind them, '
                . 'settings stored twice, apps switched on that cannot be opened, help guides '
                . 'nobody can reach, and whether the recorded version matches the files.',
            'standard' => ['staff'],
            'globalAdminFallsOpen' => true,
            'organisationMayRepoint' => true,
            'emptyMeans' => 'No role will open the check page. Administrators of this organisation '
                . 'will still be able to, because this gate lets an administrator in as well. '
                . 'Nothing stops working — the page only ever reports.',
            'askedAt' => [
                'web/_apps/admin/check/index.php',
            ],
        ],

        // 🌐 THE PUBLIC WEBSITE (#493, D-077). Putting an item in front of
        //    anybody on the internet is a different act from editing it, which
        //    is why it is its own permission and not "whoever may edit the
        //    event" (D-077 rules that out).
        //
        //    ⚠️ SAY PLAINLY WHAT IT DECIDES TODAY, because a switch that opens
        //       nothing is #671's "a lie with a Save button". Every page that
        //       writes an event is open to administrators only, and an
        //       administrator of the organisation already passes the gate in
        //       `PublicWebsite::mayPublish()` before this is asked — and so does
        //       an account holding the portal-wide `isAdmin` flag that belongs
        //       to the organisation. So in package P1 a Website Editor who is
        //       not an administrator can reach no page that uses it, and every
        //       person who can reach one already passes; the only caller it
        //       refuses is an API key, which is not a person. It is what #493's
        //       noticeboard package (P2) will ask on a page an editor CAN open.
        self::WEBSITE_PUBLISH => [
            'label' => 'Choose what appears on the public website',
            // ⚠️ SAID AS IT IS TODAY (round 1): every page that changes an event is
            //    open to administrators only, and they hold this already, so a role
            //    given it opens nothing yet. A sentence promising more was untrue.
            'opens' => 'Turning an event\'s "Show on the public website" switch on or off, and setting the '
                . 'first and last day it may show there. Today only administrators can open the pages that '
                . 'change an event, and administrators of this organisation may already do this, so giving '
                . 'this to a role changes nothing yet for anybody who is not an administrator.',
            'standard' => ['website_editor'],
            'globalAdminFallsOpen' => true,
            'organisationMayRepoint' => true,
            'emptyMeans' => 'No role will be able to change what appears on the public website. '
                . 'Administrators of this organisation will still be able to, because the gate lets an '
                . 'administrator of the organisation in before it asks about any role. Nothing already on '
                . 'the website is taken off it.',
            'askedAt' => [
                'web/_core/PublicWebsite.php',
            ],
        ],
    ];

    /**
     * Capabilities that are WRITTEN DOWN and deliberately NOT OFFERED,
     * because nothing in the portal asks them yet.
     *
     * 🫙 IT IS EMPTY TODAY, AND THAT IS A STATEMENT RATHER THAN A GAP. It
     *    held five entries until 3 November 2026 — the asset register, the
     *    care register, children's check-in, the expenses treasury and the
     *    livestream chat — because each of their gates was in a folder that
     *    package could not change, and offering a control that changes
     *    nothing anywhere is #671's "a seeded setting that nothing reads is a
     *    lie with a Save button". All five have had their gates converted and
     *    are in `CAPABILITIES` above, so there is nothing left to write down.
     *
     * ⚠️ DO NOT DELETE THIS LIST. It is where the NEXT capability goes while
     *    its gate is still hard-coded, and it is the only honest place to put
     *    one: a capability belongs here the moment somebody decides it should
     *    exist, and moves up the moment its gate asks for it. Keeping the two
     *    lists apart is what stops the roles page ever offering a door that
     *    opens nothing.
     *
     *    Because it is empty, the audit check's "a written-down capability
     *    must name no file" rule has nothing real to judge, so
     *    `tools/audit-checks/check_capability_registry.py` is proved on that
     *    half by PLANTING an entry here with a file named — never by reading
     *    this list and finding it agrees with itself.
     *
     * Same entry shape as `CAPABILITIES` with `askedAt` empty, plus a
     * `whyNotOffered` sentence. The audit check requires `askedAt` to be
     * empty here and non-empty there, so neither list can quietly become the
     * other.
     *
     * @var array<string, array{label:string, opens:string, standard:list<string>, globalAdminFallsOpen:bool, organisationMayRepoint:bool, emptyMeans:string, askedAt:list<string>, whyNotOffered:string}>
     */
    public const NOT_YET_ASKED = [];

    // =========================================================================
    // 🧹 Pure helpers — no database, safe with nothing set up at all
    // =========================================================================

    /**
     * Is this a capability this portal knows and offers?
     *
     * `NOT_YET_ASKED` keys answer FALSE on purpose: they are written down,
     * not offered, so nothing can store a mapping for one.
     *
     * @param string $capability
     *
     * @return bool
     */
    public static function isKnown(string $capability): bool
    {
        return array_key_exists($capability, self::CAPABILITIES);
    }

    /**
     * Every capability key that is offered, in the order declared.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::CAPABILITIES);
    }

    /**
     * 🧾 Why this entry cannot be used — or '' when it declares everything.
     *
     * ⚠️ IT TAKES THE ENTRY RATHER THAN THE KEY ON PURPOSE, so that a test
     *    can ask it about an entry this file COULD NOT LEGALLY CONTAIN. An
     *    incomplete entry fails the hard gate, so it can never be committed
     *    here — and a guard whose fault can never be built is a guard nothing
     *    exercises, which is where a mistake sits unnoticed. Section Q of
     *    `tools/capabilities-selftest.php` hands it one entry per missing
     *    field.
     *
     * @param array<string,mixed> $entry One entry of `CAPABILITIES`.
     *
     * @return string '' when it is complete.
     */
    public static function whyEntryIncomplete(array $entry): string
    {
        foreach (self::DECLARED_FIELDS as $field) {
            if (array_key_exists($field, $entry) === false) {
                return 'it does not declare ' . $field;
            }
        }
        foreach (['globalAdminFallsOpen', 'organisationMayRepoint'] as $flag) {
            if (is_bool($entry[$flag]) === false) {
                return $flag . ' is not true or false';
            }
        }

        return '';
    }

    /**
     * One capability's declaration.
     *
     * @param string $capability
     *
     * @return array{label:string, opens:string, standard:list<string>, globalAdminFallsOpen:bool, organisationMayRepoint:bool, emptyMeans:string, askedAt:list<string>}
     *
     * @throws \InvalidArgumentException When the capability is not one this portal offers.
     * @throws \LogicException           When the entry leaves a field out — see `DECLARED_FIELDS`.
     */
    public static function definition(string $capability): array
    {
        if (self::isKnown($capability) === false) {
            throw new \InvalidArgumentException('Capabilities: no such capability: ' . $capability);
        }

        $entry = self::CAPABILITIES[$capability];

        // ⚠️ NO FIELD HAS A DEFAULT, AND THIS IS WHERE THAT IS ENFORCED.
        //    `globalAdminFallsOpen` is true for five capabilities and false
        //    for `erasure.review`, so a missing value could not be guessed in
        //    a direction that is right for both — and guessing it would be
        //    SILENT. Without this, PHP would warn about an undefined key, hand
        //    `null` to `globalAdminPasses()` and throw a TypeError naming that
        //    method instead of the capability, which is a loud failure in the
        //    wrong place. Throwing here names the capability and the field.
        $incomplete = self::whyEntryIncomplete($entry);
        if ($incomplete !== '') {
            throw new \LogicException(
                'Capabilities: the entry for ' . $capability . ' cannot be used because '
                . $incomplete . '. There is no default for any field: see DECLARED_FIELDS.'
            );
        }

        return $entry;
    }

    /**
     * The role keys a capability SHIPS with — what an organisation that has
     * never opened the page gets.
     *
     * @param string $capability
     *
     * @return list<string>
     */
    public static function standardRoleKeys(string $capability): array
    {
        return self::definition($capability)['standard'];
    }

    /**
     * Does a global administrator pass this capability without holding any of
     * its roles? See the class header: FALSE is a boundary between two
     * parties, not a preference.
     *
     * @param string $capability
     *
     * @return bool
     */
    public static function fallsOpenForGlobalAdmin(string $capability): bool
    {
        return self::definition($capability)['globalAdminFallsOpen'];
    }

    /**
     * 🔑 Does a global administrator get in on that ground alone?
     *
     * The whole rule, in one place, taking both facts as arguments: whether
     * the account is a global administrator, and whether this capability
     * falls open for one. `allows()` calls it UNCONDITIONALLY rather than
     * wrapping it in an `if`, so there is no branch here that nothing
     * reaches.
     *
     * ⚠️ WHY IT IS A NAMED METHOD AND NOT `$a && $b` INLINE, and the reason
     *    CHANGED on 3 November 2026 — the original sentence is kept here
     *    because it is the kind of justification somebody would act on.
     *    It read: "No capability the portal OFFERS today declares
     *    `globalAdminFallsOpen => true` — there is exactly one, #724's, and
     *    it declares false — so written inline the 'true' half would be a
     *    branch no test could reach." That was TRUE when part one of #727
     *    shipped `erasure.review` alone. **Part two made it false and did not
     *    revisit it.** And #739 repeated the mistake one paragraph below,
     *    writing "six offered capabilities and FIVE of them" while there were
     *    twelve and eleven — in the docblock whose own lesson is that a count
     *    written here goes stale. **So this sentence names no number.** There
     *    is more than one of each, so both halves of the rule are reached by
     *    ordinary pages; exactly one capability declares false (#724's), and
     *    the way to know the figures is to ask the registry, as the next
     *    paragraph says. Do not read a stale count as a reason to inline the
     *    rule or to delete the test section that exercises it.
     *
     *    Ask the registry rather than trusting any count written here:
     *        php -r 'require "web/_core/Capabilities.php";
     *            foreach (Portal\Core\Capabilities::CAPABILITIES as $k => $e) {
     *                printf("%-20s %s\n", $k, var_export($e["globalAdminFallsOpen"], true)); }'
     *    `tools/audit-checks/check_capability_registry.py` prints the same
     *    column on every run.
     *
     *    The reasons it stays a named method, both still true: the rule is
     *    written ONCE where two opposite answers meet, so a page cannot keep
     *    a second copy of it; and all four combinations are exercised
     *    directly by `tools/capabilities-selftest.php` rather than only
     *    through whichever capabilities happen to be declared today. It is
     *    also where an all-of rule would have to go if one is ever wanted.
     *
     * @param bool $isGlobalAdmin Whether the account is a global administrator.
     * @param bool $fallsOpen     The capability's own `globalAdminFallsOpen`.
     *
     * @return bool
     */
    public static function globalAdminPasses(bool $isGlobalAdmin, bool $fallsOpen): bool
    {
        return $isGlobalAdmin === true && $fallsOpen === true;
    }

    /**
     * What zero roles does, in the words the page shows before asking
     * somebody to confirm it.
     *
     * @param string $capability
     *
     * @return string
     */
    public static function emptyMeans(string $capability): string
    {
        return self::definition($capability)['emptyMeans'];
    }

    /**
     * The capability keys the INSTALLATION refuses to let an organisation
     * re-point.
     *
     * Takes the installation-wide settings layer rather than fetching one, so
     * this is pure and so a test can hand it any answer.
     *
     * ⚠️ A NULL LAYER MEANS "WE CANNOT TELL", AND THAT LOCKS EVERYTHING. The
     *    two levels may be different parties (the standing rule of 29 October
     *    2026), so when the installation's own answer has not been loaded we
     *    do not let an organisation change access. The direction matters:
     *    refusing a change is recoverable in one click once start-up is
     *    working again; allowing one that the installation forbids is not.
     *    In practice `bootstrap.php` always loads the layer for a web
     *    request, so no page reaches this.
     *
     *    An entry naming a capability this portal does not offer is IGNORED
     *    rather than an error — an installation may lock a capability ahead
     *    of it being built, or keep a lock for one that has been withdrawn,
     *    and neither should make the page refuse to draw.
     *
     * @param array<string,mixed>|null $installationLayer `InstallationSettings::layerOrNull()`.
     *
     * @return list<string> Capability keys, every one of them offered by this portal.
     */
    public static function installationLocks(?array $installationLayer): array
    {
        if ($installationLayer === null) {
            return self::keys();
        }

        // `capabilities.locked` nests as $layer['capabilities']['locked'].
        $parts = explode('.', self::LOCK_SETTING_KEY);
        $value = $installationLayer;
        foreach ($parts as $part) {
            if (is_array($value) === false || array_key_exists($part, $value) === false) {
                return [];
            }
            $value = $value[$part];
        }
        if (is_string($value) === false) {
            return [];
        }

        $locked = [];
        foreach (explode(',', $value) as $candidate) {
            $key = trim($candidate);
            if ($key !== '' && self::isKnown($key) === true && in_array($key, $locked, true) === false) {
                $locked[] = $key;
            }
        }

        return $locked;
    }

    /**
     * Why this organisation may NOT change this capability's roles — or '' if
     * it may.
     *
     * A sentence, because it is shown to somebody. Never a boolean with the
     * words worked out somewhere else.
     *
     * @param string                   $capability
     * @param array<string,mixed>|null $installationLayer `InstallationSettings::layerOrNull()`.
     *
     * @return string '' when it may be changed.
     */
    public static function whyNotChangeable(string $capability, ?array $installationLayer): string
    {
        if (self::isKnown($capability) === false) {
            return 'That is not something this portal can point at a role.';
        }
        if (self::CAPABILITIES[$capability]['organisationMayRepoint'] === false) {
            return 'This one is fixed in the portal itself and cannot be pointed at a different role.';
        }
        if (in_array($capability, self::installationLocks($installationLayer), true) === true) {
            return 'Whoever runs this installation has reserved this one, so it cannot be changed here. '
                . 'Ask them if you need it pointed at one of your own roles.';
        }

        return '';
    }

    /**
     * A plain, developer-written table name — checked before it is ever put
     * into a statement, because a table name cannot be a bound parameter.
     * The two names are constants in this file, so this can only ever fail if
     * somebody edits them to something odd.
     *
     * @param string $name
     *
     * @return bool
     */
    private static function isPlainName(string $name): bool
    {
        return preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $name) === 1;
    }

    // =========================================================================
    // 🗒️ What is remembered for the rest of ONE request, and what is not
    // =========================================================================

    /**
     * The roles in force, per "<organisation>|<capability>", for the rest of
     * this request only.
     *
     * ⚠️ WHAT IS REMEMBERED IS THE MAPPING, NEVER A PERSON'S ANSWER. Granting
     *    somebody a role, or ending their membership, changes what
     *    `allows()` answers on the VERY NEXT CALL, because
     *    `Roles::hasAnyOf()` is still asked every single time. Only the
     *    question "which roles open this door here" is remembered, and the
     *    one thing that changes it is `save()` or `forget()`, which both
     *    clear this.
     *
     *    WHY IT EXISTS: converting the asset register's gates (#727) put
     *    `App::can()` where `App::hasRole()` used to be, and two of those
     *    sites are INSIDE A LOOP — `assets/loans.php` asks per loan and
     *    `assets/maintenance.php` per due item, both through
     *    `AssetRegister::canApproveLoan()`/`canManageMaintenance()`. Without
     *    this, a fifty-row list went from one query per row to five, because
     *    `storageExists()` alone asks twice. Measured as a count of
     *    statements, not timed.
     *
     * ⚠️ THE ORGANISATION IN THAT KEY IS NOW GUARDED, and it was not until
     *    3 November 2026. An independent check dropped it — `$memoKey =
     *    $capability;` — with the hard gate green and this file's self-test
     *    199/199 green, and an organisation that had chosen nothing was then
     *    served another organisation's chosen set. The sentence above had
     *    always promised the scoping; nothing had ever checked it, and the
     *    self-test's two organisations happened only ever to be asked in an
     *    order where the two keys agree. Section R of
     *    `tools/capabilities-selftest.php` now asks them in both orders, and
     *    check Q of `tools/audit-checks/check_capability_registry.py` pins
     *    the key itself so the guard is in front of a PUSH and not only of a
     *    pull request. Nothing in `web/` reaches the fault today — `App::can()`
     *    always passes `Site::id()` — but `_apps/cron/asset-reminders.php`
     *    already loops organisations with `Site::forceContext()`, so a job
     *    that asks a capability would.
     *
     * @var array<string, list<string>>
     */
    private static array $inForceMemo = [];

    /** Whether the two storage tables are there; null until first asked. */
    private static ?bool $storageMemo = null;

    /**
     * Forget what was remembered above.
     *
     * Called by `save()` and `forget()` after a write, and by
     * `admin/roles/save.php` after it DELETES a role — the database's own
     * `ON DELETE CASCADE` takes that role out of every set it opened, and
     * nothing here would otherwise be told. Also by
     * `tools/capabilities-selftest.php`, whose section J hides the storage by
     * renaming both tables mid-run.
     *
     * ⚠️ WHAT THIS CANNOT DO: the memo is NOT keyed by connection, so a
     *    script holding two connections to two different databases would read
     *    the first one's answer on the second unless it calls this in
     *    between. A web request has exactly one connection
     *    (`DbServer::connect()` is the one place one is opened, #593), and
     *    nothing in `web/` opens a second — so no page reaches that. It is
     *    written down because a test harness can.
     */
    public static function forgetMemo(): void
    {
        self::$inForceMemo = [];
        self::$storageMemo = null;
    }

    // =========================================================================
    // 🔍 Reading
    // =========================================================================

    /**
     * Are both storage tables there?
     *
     * ⚠️ ASKED BEFORE ANYTHING IS READ, and a missing table means "no
     *    organisation has chosen anything", never an error. Files reach a
     *    server before anybody presses "Run migrations" (#694), and a gate
     *    that threw in that window would lock every door in the portal
     *    between the upload and the upgrade.
     *
     *    `SHOW TABLES LIKE` and not a comparison against a name typed here:
     *    a server with `lower_case_table_names = 1` reports lower-case names,
     *    and `LIKE` ignores case while `in_array(..., true)` does not (#697,
     *    where exactly that comparison made two safety nets inert).
     *
     * @param mysqli $db An open connection.
     *
     * @return bool
     */
    public static function storageExists(mysqli $db): bool
    {
        // Asked once per request: a table does not appear or disappear while
        // one is being served. `forgetMemo()` is what a test calls when it
        // makes that untrue on purpose.
        if (self::$storageMemo !== null) {
            return self::$storageMemo;
        }

        foreach ([self::MAP_TABLE, self::MAP_ROLE_TABLE] as $table) {
            if (self::isPlainName($table) === false) {
                self::$storageMemo = false;

                return false;
            }
            try {
                $result = $db->query("SHOW TABLES LIKE '" . $table . "'");
                if ($result === false) {
                    self::$storageMemo = false;

                    return false;
                }
                $found = $result->num_rows > 0;
                $result->free();
                if ($found === false) {
                    self::$storageMemo = false;

                    return false;
                }
            } catch (\Throwable $e) {
                self::$storageMemo = false;

                return false;
            }
        }

        self::$storageMemo = true;

        return true;
    }

    /**
     * The role keys this organisation has CHOSEN for this capability, or null
     * when it has chosen nothing.
     *
     * Null and the empty array are different answers and the difference is
     * the whole feature: null means "as shipped", and `[]` means "nobody,
     * deliberately".
     *
     * @param mysqli $db         An open connection.
     * @param int    $siteId     The organisation.
     * @param string $capability
     *
     * @return list<string>|null
     */
    public static function chosenRoleKeys(mysqli $db, int $siteId, string $capability): ?array
    {
        if (self::isKnown($capability) === false) {
            return null;
        }
        if (self::storageExists($db) === false) {
            return null;
        }

        $mapStmt = $db->prepare(
            'SELECT `mapID` FROM `' . self::MAP_TABLE . '` WHERE `siteID` = ? AND `capabilityKey` = ? LIMIT 1'
        );
        if ($mapStmt === false) {
            return null;
        }
        $mapStmt->bind_param('is', $siteId, $capability);
        $mapStmt->execute();
        $mapRow = $mapStmt->get_result()->fetch_assoc();
        $mapStmt->close();
        if ($mapRow === null) {
            return null;
        }
        $mapId = (int) $mapRow['mapID'];

        $keys = [];
        $roleStmt = $db->prepare(
            'SELECT `roleKey` FROM `' . self::MAP_ROLE_TABLE . '` WHERE `mapID` = ? ORDER BY `roleKey`'
        );
        if ($roleStmt === false) {
            return null;
        }
        $roleStmt->bind_param('i', $mapId);
        $roleStmt->execute();
        $result = $roleStmt->get_result();
        while (($row = $result->fetch_assoc()) !== null) {
            $keys[] = strtolower((string) $row['roleKey']);
        }
        $roleStmt->close();

        return $keys;
    }

    /**
     * The role keys that open this capability HERE, right now: the
     * organisation's chosen set where it has one, the shipped set where it
     * has not.
     *
     * @param mysqli $db         An open connection.
     * @param int    $siteId     The organisation.
     * @param string $capability
     *
     * @return list<string>
     */
    public static function roleKeysInForce(mysqli $db, int $siteId, string $capability): array
    {
        // ⚠️ `definition()` FIRST, so an unknown capability still THROWS
        //    rather than being remembered as an empty answer. Without this
        //    the memo would be reached before the only thing that refuses a
        //    mistyped key.
        $shipped = self::standardRoleKeys($capability);

        $memoKey = (string) $siteId . '|' . $capability;
        if (array_key_exists($memoKey, self::$inForceMemo) === true) {
            return self::$inForceMemo[$memoKey];
        }

        $chosen = self::chosenRoleKeys($db, $siteId, $capability);
        $answer = $chosen !== null ? $chosen : $shipped;

        self::$inForceMemo[$memoKey] = $answer;

        return $answer;
    }

    /**
     * 🚪 May this person do this, in this organisation?
     *
     * The ONE answer. A page asks `App::can()`, which supplies the global
     * administrator flag from the signed-in account; this method takes it as
     * an argument so that it can be answered with no session at all.
     *
     * ⚠️ THE GLOBAL ADMINISTRATOR SHORTCUT IS ASKED OF THE CAPABILITY, NEVER
     *    TAKEN FOR GRANTED. `App::hasRole()` returns true for any global
     *    administrator with no role row, and #724's whole point is a gate
     *    that does not. So the flag is only honoured when the capability's
     *    own entry says `globalAdminFallsOpen`.
     *
     * @param mysqli $db            An open connection.
     * @param int    $userId        The account.
     * @param int    $siteId        The organisation.
     * @param string $capability
     * @param bool   $isGlobalAdmin Whether the account is a global administrator (`isRootAdmin`).
     *
     * @return bool
     *
     * @throws \InvalidArgumentException When the capability is not one this portal offers.
     */
    public static function allows(
        mysqli $db,
        int $userId,
        int $siteId,
        string $capability,
        bool $isGlobalAdmin
    ): bool {
        // Throws on an unknown capability rather than answering false: a
        // mistyped key must be a fault at the moment it is written, not a
        // door that is quietly shut for everybody.
        $definition = self::definition($capability);

        if (self::globalAdminPasses($isGlobalAdmin, $definition['globalAdminFallsOpen']) === true) {
            return true;
        }
        if ($userId <= 0) {
            return false;
        }

        $keys = self::roleKeysInForce($db, $siteId, $capability);

        return Roles::hasAnyOf($db, $userId, $siteId, $keys);
    }

    /**
     * How many ACTIVE members of this organisation hold at least one of these
     * role keys. Counts PEOPLE, not holdings — somebody holding two of them
     * counts once.
     *
     * The page uses it to warn that a set nobody holds opens the door for
     * nobody. It is a warning and not a refusal: making the mapping and then
     * granting the role is the ordinary order of work.
     *
     * @param mysqli        $db       An open connection.
     * @param int           $siteId   The organisation.
     * @param list<string>  $roleKeys Role keys; an empty list answers 0.
     *
     * @return int
     */
    public static function holderCount(mysqli $db, int $siteId, array $roleKeys): int
    {
        if (count($roleKeys) === 0) {
            return 0;
        }
        $keys = [];
        foreach ($roleKeys as $key) {
            $keys[] = Roles::normaliseKey((string) $key);
        }

        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $db->prepare(
            'SELECT COUNT(DISTINCT ur.`userID`) AS cnt FROM `tblUserRoles` ur '
            . 'JOIN `tblRoles` r ON r.`roleID` = ur.`roleID` AND r.`siteID` = ur.`siteID` '
            . 'JOIN `tblUserSites` us ON us.`userID` = ur.`userID` AND us.`siteID` = ur.`siteID` '
            . 'AND us.`isActive` = 1 '
            . 'WHERE ur.`siteID` = ? AND r.`roleKey` IN (' . $placeholders . ')'
        );
        if ($stmt === false) {
            return 0;
        }
        $types  = 'i' . str_repeat('s', count($keys));
        $params = array_merge([$siteId], $keys);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * Which capabilities would be left with NO roles at all if this role were
     * deleted from this organisation.
     *
     * Why this exists: the storage's foreign key removes a deleted role from
     * every set it was in, which is right — a set naming a role that no
     * longer exists would be a door nobody can see is shut. But if it was the
     * LAST role in a set, the organisation would silently end up with an
     * empty set it never confirmed, which is the one thing the page makes
     * somebody confirm in as many words. So the roles page refuses the delete
     * and says which capability to point elsewhere first.
     *
     * A capability the organisation has NOT chosen anything for cannot be
     * affected: its roles are the shipped list, and deleting an
     * organisation's role does not change that list. (A standard role cannot
     * be deleted at all, which is what makes that true.)
     *
     * @param mysqli $db      An open connection.
     * @param int    $siteId  The organisation.
     * @param string $roleKey The role about to be deleted.
     *
     * @return list<string> Capability keys, possibly empty.
     */
    public static function capabilitiesLeftEmptyByRemoving(mysqli $db, int $siteId, string $roleKey): array
    {
        $key  = Roles::normaliseKey($roleKey);
        $left = [];
        foreach (self::keys() as $capability) {
            $chosen = self::chosenRoleKeys($db, $siteId, $capability);
            if ($chosen === null || count($chosen) === 0) {
                continue;
            }
            $without = [];
            foreach ($chosen as $existing) {
                if ($existing !== $key) {
                    $without[] = $existing;
                }
            }
            if (count($without) === 0) {
                $left[] = $capability;
            }
        }

        return $left;
    }

    // =========================================================================
    // ✏️ Writing
    // =========================================================================

    /**
     * Record this organisation's set of roles for one capability. The ONE
     * writer; `admin/roles/save.php` is its only caller.
     *
     * Everything that can go wrong is answered as a word rather than thrown,
     * because every one of them is something an administrator can put right
     * on the page they are already looking at:
     *
     *   'ok'            — written.
     *   'unchanged'     — the set was already exactly this.
     *   'unknown'       — not a capability this portal offers.
     *   'not_allowed'   — the code or the installation refuses the change.
     *   'no_storage'    — the database has not had migration 243 yet.
     *   'bad_role'      — a role key that is not one of this organisation's.
     *   'failed'        — the database refused the write.
     *
     * ⚠️ THE WHOLE SET IS REPLACED, inside ONE transaction, and the map row
     *    is written FIRST so that "a choice was made" and "these are the
     *    roles" can never be half true. An organisation choosing an EMPTY set
     *    therefore ends with a map row and no role rows, which is exactly how
     *    "nobody, deliberately" is told apart from "never touched".
     *
     * @param mysqli       $db         An open connection.
     * @param int          $siteId     The organisation.
     * @param string       $capability
     * @param list<string> $roleKeys   The new set; may be empty.
     * @param int|null     $actorId    Who changed it, for the audit trail.
     * @param array<string,mixed>|null $installationLayer `InstallationSettings::layerOrNull()`.
     *
     * @return string One of the words above.
     */
    public static function save(
        mysqli $db,
        int $siteId,
        string $capability,
        array $roleKeys,
        ?int $actorId,
        ?array $installationLayer
    ): string {
        if (self::isKnown($capability) === false) {
            return 'unknown';
        }
        if (self::whyNotChangeable($capability, $installationLayer) !== '') {
            return 'not_allowed';
        }
        if (self::storageExists($db) === false) {
            return 'no_storage';
        }

        // Normalise, drop duplicates, and keep a stable order so an
        // "unchanged" answer is about the SET and not about the order
        // somebody happened to tick the boxes in.
        $wanted = [];
        foreach ($roleKeys as $raw) {
            $key = Roles::normaliseKey((string) $raw);
            if ($key !== '' && in_array($key, $wanted, true) === false) {
                $wanted[] = $key;
            }
        }
        sort($wanted);

        // Every key must be a role THIS organisation has. The storage's
        // foreign key would refuse anything else anyway, but a refusal that
        // says which key was wrong is worth more than an exception, and this
        // also refuses another organisation's role rather than letting the
        // database decide.
        $own = [];
        foreach (Roles::forSite($db, $siteId) as $role) {
            $own[] = strtolower($role['roleKey']);
        }
        foreach ($wanted as $key) {
            if (in_array($key, $own, true) === false) {
                return 'bad_role';
            }
        }

        $before = self::chosenRoleKeys($db, $siteId, $capability);
        if ($before !== null) {
            $sortedBefore = $before;
            sort($sortedBefore);
            if ($sortedBefore === $wanted) {
                return 'unchanged';
            }
        }

        $db->begin_transaction();
        try {
            $mapStmt = $db->prepare(
                'INSERT INTO `' . self::MAP_TABLE . '` (`siteID`, `capabilityKey`, `decidedAtUtc`, `decidedByID`) '
                . 'VALUES (?, ?, UTC_TIMESTAMP(), ?) '
                . 'ON DUPLICATE KEY UPDATE `decidedAtUtc` = UTC_TIMESTAMP(), `decidedByID` = VALUES(`decidedByID`)'
            );
            $mapStmt->bind_param('isi', $siteId, $capability, $actorId);
            $mapStmt->execute();
            $mapStmt->close();

            // The map row's own number, read back rather than taken from
            // `insert_id`: on the second save `ON DUPLICATE KEY UPDATE`
            // leaves `insert_id` holding the number of a row that was never
            // inserted on some versions, and the row already has a number.
            $idStmt = $db->prepare(
                'SELECT `mapID` FROM `' . self::MAP_TABLE . '` WHERE `siteID` = ? AND `capabilityKey` = ? LIMIT 1'
            );
            $idStmt->bind_param('is', $siteId, $capability);
            $idStmt->execute();
            $idRow = $idStmt->get_result()->fetch_assoc();
            $idStmt->close();
            if ($idRow === null) {
                $db->rollback();

                return 'failed';
            }
            $mapId = (int) $idRow['mapID'];

            $wipe = $db->prepare('DELETE FROM `' . self::MAP_ROLE_TABLE . '` WHERE `mapID` = ?');
            $wipe->bind_param('i', $mapId);
            $wipe->execute();
            $wipe->close();

            if (count($wanted) > 0) {
                $add = $db->prepare(
                    'INSERT INTO `' . self::MAP_ROLE_TABLE . '` (`mapID`, `siteID`, `roleKey`) VALUES (?, ?, ?)'
                );
                foreach ($wanted as $key) {
                    $add->bind_param('iis', $mapId, $siteId, $key);
                    $add->execute();
                }
                $add->close();
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();

            return 'failed';
        }

        // 🗒️ The mapping has changed, so what this request remembered about
        //    it is wrong. Without this the roles page's own success message,
        //    which asks `roleKeysInForce()` immediately afterwards, would
        //    report the set as it was BEFORE the save.
        self::forgetMemo();

        // 🧾 Audited: the capability, what it was and what it is now. Role
        //    KEYS and capability keys only — no account names and no member's
        //    details, exactly as `Roles::grant()` records numbers rather than
        //    names.
        Logger::audit(
            self::MAP_TABLE,
            $mapId,
            $before === null ? 'create' : 'update',
            $before === null ? null : ['capabilityKey' => $capability, 'roleKeys' => implode(',', $before)],
            ['capabilityKey' => $capability, 'roleKeys' => implode(',', $wanted)],
            $actorId
        );
        Logger::activity(
            'CapabilityRolesChange',
            'Capability "' . $capability . '" in organisation #' . (string) $siteId
            . ' is now opened by: ' . (count($wanted) > 0 ? implode(', ', $wanted) : 'nobody by role'),
            $actorId
        );

        return 'ok';
    }

    /**
     * Write the capability keys the INSTALLATION reserves to itself. The ONE
     * writer of `capabilities.locked`; `admin/roles/save.php` is its only
     * caller, and only for a global administrator.
     *
     * ⚠️ IT WRITES THE INSTALLATION-WIDE ROW (`siteID` IS NULL) AND READS
     *    BACK FROM THE INSTALLATION-WIDE ROW, never from the merged
     *    `$SETTINGS`. #674's fault is exactly this shape the other way up: a
     *    form that saves installation-wide while showing the OPEN
     *    ORGANISATION's value lets somebody press Save without touching
     *    anything and make their own value everybody's. There is no
     *    per-organisation row for this key and nothing may ever create one —
     *    an organisation deciding what the installation reserves would be the
     *    whole point inverted.
     *
     * Only keys this portal OFFERS are stored, so a lock cannot be written
     * for a capability that does not exist; `installationLocks()` ignores
     * unknown keys on the way out too, which is what lets an installation
     * keep a lock for a capability that has been withdrawn.
     *
     * @param mysqli       $db      An open connection.
     * @param list<string> $keys    Capability keys to reserve; an empty list reserves nothing.
     * @param int|null     $actorId Who changed it, for the audit trail.
     *
     * @return string 'ok' | 'unchanged' | 'failed'
     */
    public static function saveInstallationLocks(mysqli $db, array $keys, ?int $actorId): string
    {
        $wanted = [];
        foreach ($keys as $raw) {
            $key = trim((string) $raw);
            if (self::isKnown($key) === true && in_array($key, $wanted, true) === false) {
                $wanted[] = $key;
            }
        }
        sort($wanted);
        $value = implode(',', $wanted);

        try {
            $read = $db->prepare(
                'SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL LIMIT 1'
            );
            $key  = self::LOCK_SETTING_KEY;
            $read->bind_param('s', $key);
            $read->execute();
            $row = $read->get_result()->fetch_assoc();
            $read->close();
            $before = $row === null ? '' : (string) ($row['settingValue'] ?? '');

            if ($before === $value) {
                return 'unchanged';
            }

            $write = $db->prepare(
                'INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) '
                . "VALUES (NULL, ?, ?, '', 0) "
                . 'ON DUPLICATE KEY UPDATE `settingValue` = VALUES(`settingValue`)'
            );
            $write->bind_param('ss', $key, $value);
            $write->execute();
            $write->close();
        } catch (\Throwable $e) {
            return 'failed';
        }

        Logger::audit(
            'tblSettings',
            0,
            'update',
            ['settingKey' => self::LOCK_SETTING_KEY, 'settingValue' => $before],
            ['settingKey' => self::LOCK_SETTING_KEY, 'settingValue' => $value],
            $actorId
        );
        Logger::activity(
            'CapabilityLocksChange',
            'The installation now reserves these capabilities: '
            . ($value !== '' ? $value : 'none'),
            $actorId
        );

        return 'ok';
    }

    /**
     * Put a capability's roles back to the shipped set by FORGETTING the
     * organisation's choice — so it becomes "as shipped" again rather than "a
     * chosen set that happens to match".
     *
     * The difference is visible on the page and matters: an organisation that
     * has made no choice follows the portal if the shipped set ever changes,
     * and one that has chosen does not.
     *
     * @param mysqli   $db         An open connection.
     * @param int      $siteId     The organisation.
     * @param string   $capability
     * @param int|null $actorId
     * @param array<string,mixed>|null $installationLayer
     *
     * @return string 'ok' | 'unchanged' | 'unknown' | 'not_allowed' | 'no_storage' | 'failed'
     */
    public static function forget(
        mysqli $db,
        int $siteId,
        string $capability,
        ?int $actorId,
        ?array $installationLayer
    ): string {
        if (self::isKnown($capability) === false) {
            return 'unknown';
        }
        if (self::whyNotChangeable($capability, $installationLayer) !== '') {
            return 'not_allowed';
        }
        if (self::storageExists($db) === false) {
            return 'no_storage';
        }

        $before = self::chosenRoleKeys($db, $siteId, $capability);
        if ($before === null) {
            return 'unchanged';
        }

        try {
            // The role rows go with it through `ON DELETE CASCADE`.
            $stmt = $db->prepare(
                'DELETE FROM `' . self::MAP_TABLE . '` WHERE `siteID` = ? AND `capabilityKey` = ?'
            );
            $stmt->bind_param('is', $siteId, $capability);
            $stmt->execute();
            $stmt->close();
        } catch (\Throwable $e) {
            return 'failed';
        }

        // 🗒️ Same reason as in `save()`: the door is back to the shipped
        //    roles now, and this request remembered the chosen set.
        self::forgetMemo();

        Logger::audit(
            self::MAP_TABLE,
            0,
            'delete',
            ['capabilityKey' => $capability, 'roleKeys' => implode(',', $before)],
            null,
            $actorId
        );
        Logger::activity(
            'CapabilityRolesReset',
            'Capability "' . $capability . '" in organisation #' . (string) $siteId
            . ' is back to the roles it ships with',
            $actorId
        );

        return 'ok';
    }
}
