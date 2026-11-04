<?php
// Path: tools/capabilities-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Who opens which door, on a real database 🚪🧪 (#727)
 * -----------------------------------------------------------------------------
 * `Portal\Core\Capabilities` is the one place a gated capability is declared,
 * the one place an organisation's chosen roles for it are read or written, and
 * the one place "may this person do this?" is answered. This runs the REAL
 * class against a throwaway database built from `web/_sql/full_schema.sql`.
 *
 * WHAT IT PROVES
 * --------------
 *   A  The registry as declared: twelve capabilities are OFFERED (six until
 *      #739 converted the last of the hard-coded gates), each ships with the
 *      role its hard-coded gate used, every one but #724's falls open for a
 *      global administrator, and `NOT_YET_ASKED` is empty — so the roles page
 *      is offering no door that opens nothing.
 *   B  AN ORGANISATION THAT NEVER OPENS THE PAGE SEES NO CHANGE. With nothing
 *      chosen, the roles in force are the shipped ones (#499: "fail closed"
 *      was built first and locked members out).
 *   C  #724'S GATE, WHICH IS THE ACCEPTANCE TEST FOR THE WHOLE FEATURE. A
 *      global administrator who has NOT been granted the Data Controller role
 *      is REFUSED; the same person with the role is allowed. And the rule
 *      that decides it is exercised over all four combinations.
 *   D  ANY-OF. An organisation can add a role OF ITS OWN beside the standard
 *      one, and either opens the door.
 *   E  RE-POINTING REALLY RE-POINTS. A set naming only the organisation's own
 *      role shuts the standard role out.
 *   F  THE EMPTY SET. Nobody at all — not the standard role, not a global
 *      administrator — and "chosen and empty" is a DIFFERENT answer from
 *      "never chosen", which is what `forget()` goes back to.
 *   G  THE INSTALLATION'S REFUSAL. A locked capability cannot be changed, the
 *      refusal is a sentence somebody can read, nothing is stored, and a layer
 *      that could not be read locks everything rather than nothing.
 *   H  ONE ORGANISATION'S CHOICE IS ITS OWN, and a role from another
 *      organisation is refused by name rather than by an exception.
 *   I  DELETING A ROLE. The storage's own link takes it out of every set it
 *      opened, and the roles page is told when it was the LAST one.
 *   J  NO STORAGE YET. A database that has not had migration 243 falls back to
 *      the shipped roles — it does NOT throw and does NOT answer "nobody"
 *      (#694: files reach a server before anybody presses "Run migrations").
 *   K  WHAT IS WRITTEN DOWN. A change is audited and leaves an activity line,
 *      and neither names a person.
 *   L  THE GATES ARE WHERE THEY MUST BE, whole conditions, comments stripped
 *      by the tokeniser — so `&& false` on either of them fails this test.
 *   N  THE FIVE CONVERTED ON 3 NOVEMBER 2026 (#727), each asserted by name:
 *      the role it ships with, `globalAdminFallsOpen => true`, and that its
 *      "what an empty set costs" sentence does not make the claim an earlier
 *      draft made and that was false ("only a global administrator").
 *   O  AN ORGANISATION THAT NEVER OPENS THE PAGE SEES NO DIFFERENCE, measured
 *      rather than argued: for each of the five, what the capability answers
 *      is compared with the OLD gate rebuilt from `Roles::has()`, over five
 *      people who each hold exactly one thing. And #724's is shown to be the
 *      one where the two deliberately DISAGREE.
 *   P  RE-POINTING ONE OF THE FIVE, including the path a global administrator
 *      takes — which until these five existed was reached by nothing at all.
 *   Q  AN ENTRY THAT LEAVES A FIELD OUT is refused by name, asked of an entry
 *      this repository could not legally contain.
 *   R  WHAT IS REMEMBERED FOR ONE REQUEST, and what is deliberately not: the
 *      mapping is, a person's answer never is, and both writers clear it.
 *
 * WHAT IT CANNOT PROVE, said plainly
 * ----------------------------------
 *   - THE PAGES OVER HTTP. Nothing here starts a web server, so no 403, no
 *     redirect and no rendered form has been requested. The roles page's own
 *     behaviour — the confirmation box for an empty set, the warning when
 *     nobody holds any of the roles, the card only a global administrator
 *     sees — is read as TEXT here and over HTTP nowhere. That is the gap to
 *     close next.
 *   - THE FORTY-NINE CONVERTED GATES THEMSELVES. Section O proves the RULE
 *     behind them is unchanged, and the hard gate
 *     `tools/audit-checks/check_capability_registry.py` proves each named
 *     file really asks the capability and that no old-style call for one of
 *     these roles is left anywhere under `web/`. What NOTHING here does is
 *     open one of those 49 pages and read its answer: that needs a web
 *     server, and `tools/access-pages-selftest.php` is where such a test
 *     would belong.
 *   - TWO ADMINISTRATORS SAVING AT ONCE. `save()` writes inside one
 *     transaction and replaces the whole set, so the last one wins; nothing
 *     here runs two at a time.
 *   - HOW MUCH SLOWER A GATE IS. `App::can()` asks more of the database than
 *     `App::hasRole()` did, which is why the class remembers a mapping for
 *     the rest of a request (section R). The statements were counted; nothing
 *     was timed, and nothing here measures a page's response.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_capabilities SELFTEST_DB_PORT=<port> \
 *             SELFTEST_DB_PASS=<pw> php tools/capabilities-selftest.php
 *
 *         The database must already exist with full_schema.sql loaded, and
 *         its name must start with selftest_.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/727
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$dbName = (string) getenv('SELFTEST_DB_NAME');
if (str_starts_with($dbName, 'selftest_') === false) {
    echo "REFUSED — set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}').\n";
    exit(2);
}

$coreDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR;
if (defined('PORTAL_CORE') === false) {
    define('PORTAL_CORE', rtrim($coreDir, DIRECTORY_SEPARATOR));
}

// Classes one by one, never through bootstrap.php, so this test never reads
// the portal's real connection file or its encryption key.
require_once $coreDir . 'App.php';
require_once $coreDir . 'Site.php';
require_once $coreDir . 'Logger.php';
require_once $coreDir . 'Roles.php';
require_once $coreDir . 'InstallationSettings.php';
require_once $coreDir . 'Capabilities.php';

use Portal\Core\Capabilities;
use Portal\Core\Roles;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
// `$mysqli` is a global on purpose: `App::db()` falls back to it, and so does
// `Logger`.
$mysqli = new mysqli(
    getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
    getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
    getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
    $dbName,
    getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306
);
$mysqli->set_charset('utf8mb4');
\Portal\Core\Site::init($mysqli, ['site' => ['timezoneKeptAt' => '']], 1);

$GLOBALS['cap_pass'] = 0;
$GLOBALS['cap_fail'] = 0;

/**
 * ⚠️ PUT THE TWO TABLES BACK WHATEVER HAPPENS.
 *
 * Section J hides the storage by RENAMING both tables, to prove that a
 * database which has not had migration 243 falls back to the shipped roles
 * rather than throwing. If anything between the two renames stops the run —
 * which is exactly what a planted fault does — the tables stay hidden and
 * EVERY LATER RUN of this test fails for a reason that has nothing to do with
 * the code. That really happened on 3 November 2026: a plant that removed the
 * storage guard left the database renamed, and the next four plants all went
 * red in section D, so each looked caught while being caught for the wrong
 * reason. A plant that goes red for the wrong reason is as useless as one that
 * will not go red at all, and the passing run cannot tell you which.
 *
 * Same remedy as `brs_tidy()` in the backup test (#697). It renames back only
 * if the hidden name is really there, so an ordinary finish does nothing.
 */
register_shutdown_function(static function () use ($mysqli): void {
    foreach ([
        'zzcap_map_hidden'  => Capabilities::MAP_TABLE,
        'zzcap_role_hidden' => Capabilities::MAP_ROLE_TABLE,
    ] as $hidden => $realName) {
        try {
            $result = $mysqli->query("SHOW TABLES LIKE '" . $hidden . "'");
            if ($result !== false && $result->num_rows > 0) {
                $result->free();
                $mysqli->query('RENAME TABLE `' . $hidden . '` TO `' . $realName . '`');
                echo "  (tidied up: {$hidden} was renamed back to {$realName})\n";
            } elseif ($result !== false) {
                $result->free();
            }
        } catch (\Throwable $e) {
            echo "  (could not tidy up {$hidden} — rename it back to {$realName} by hand)\n";
        }
    }
});

function cap_section(string $name): void
{
    echo "\n── {$name}\n";
}

function cap_check(string $what, bool $ok, string $detail = ''): void
{
    if ($ok === true) {
        $GLOBALS['cap_pass']++;
        echo "  ✓ {$what}\n";

        return;
    }
    $GLOBALS['cap_fail']++;
    echo "  ✗ {$what}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

/** One value from one query, or null. */
function cap_one(string $sql, string $types = '', array $values = []): mixed
{
    global $mysqli;
    $stmt = $mysqli->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$values);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();

    return $row === null ? null : $row[0];
}

/**
 * A PHP file's CODE only — every comment removed by the tokeniser, never by a
 * regular expression. A guard's text can sit in a comment, and a check that
 * matched the comment would pass on deleted code.
 */
function cap_codeOnly(string $path): string
{
    $out = '';
    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        if (is_array($token) === true
            && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) === true
        ) {
            continue;
        }
        $out .= is_array($token) === true ? $token[1] : $token;
    }

    return $out;
}

/**
 * 🧱 The one `if (...) { ... }` block whose condition is exactly $condition.
 *
 * Answers the block INCLUDING its condition and its own closing brace, or ''
 * when the condition is not there or its braces never balance.
 *
 * WHY THIS EXISTS, AND WHY THE TWO EASIER WAYS BOTH FAIL (#742)
 * -------------------------------------------------------------
 * These checks exist to prove that a refusal really REFUSES — that the block
 * ends in `exit()` rather than falling through to the write. Two earlier ways
 * of asking that were both wrong:
 *
 *  1. `'/<condition>\{.{0,900}?exit\(\);\s*\}/s'` — the idiom this file used
 *     until 4 November 2026. **`.` crosses newlines with the `s` modifier**,
 *     and `web/_apps/admin/roles/save.php` contains TWENTY-TWO `exit(` calls,
 *     every refusal on the page looking alike. So with the `exit();` DELETED
 *     from the block being checked, the search ran past that block's closing
 *     brace and found the NEXT refusal's `exit();` a few lines down, and the
 *     check stayed green over a gate that no longer refused. Planted and
 *     measured. #668's section D "passed on FOUR disabled gates" is the same
 *     shape, and this is the third time this repository has paid for it.
 *
 *  2. Cutting at `"\n" . str_repeat(' ', $indent) . '}'` — better, and still
 *     wrong in principle: it trusts the indentation of the file being read,
 *     and it is the caller who has to know the right number. Both blocks in
 *     `roles/save.php` contain braces of their own (one has a `foreach`), so
 *     the margin was one level of indentation.
 *
 * So this walks the braces, and **steps over string literals** — #647 records
 * a brace walk that did not, where one `{` inside a log message swallowed the
 * rest of the function and the check passed on a removed gate. Comments need
 * no handling because every caller passes code from `cap_codeOnly()`, which
 * drops them with PHP's own tokeniser.
 *
 * HEREDOCS, AND A GUARANTEE THAT WAS FALSE IN THE DANGEROUS DIRECTION
 * ------------------------------------------------------------------
 * The first version of this docblock said a heredoc "answers '' in that case,
 * which FAILS the check rather than passing it, which is the safe direction".
 * **That was wrong, and wrong the dangerous way round** — found by the
 * independent check, which measured it rather than reading it.
 *
 * A `{` inside a heredoc pushes the walk one level deeper, so the block does
 * not end at its own closing brace: it ends at the ENCLOSING one, swallowing
 * whatever lies between — including the NEXT refusal's `exit();`. It answers
 * '' only when nothing encloses the block, and **all five blocks this is used
 * on are enclosed** (depth 1 in `roles/save.php` and `settings/save.php`,
 * depth 2 in `settings/index.php`). Measured: a heredoc holding a `{` added
 * inside the `$roleKeys` refusal **with its `exit();` deleted** left the whole
 * self-test GREEN at 225 of 225, over a gate that falls through to the save.
 *
 * So the walk now REFUSES when it sees a heredoc opener between the condition
 * and the brace it matched, which makes the stated safe direction true instead
 * of merely claimed. No handler read here uses one today (`<<<` appears in
 * none of the three files, checked), so this costs nothing and is there for
 * the day one does.
 *
 * ONE MORE THING IT CANNOT DO, measured by the same check: the walk steps over
 * string literals, but the `str_contains($block, 'exit();')` its callers do
 * afterwards does NOT. A block whose own flash message contained the text
 * `exit();` would satisfy the caller with the real call gone. Contrived rather
 * than realistic — no message here contains it — and said out loud rather than
 * left to be discovered.
 */
function cap_blockFrom(string $code, string $condition): string
{
    $at = strpos($code, $condition);
    if ($at === false) {
        return '';
    }

    $open = strpos($code, '{', $at + strlen($condition) - 1);
    if ($open === false) {
        return '';
    }

    $depth  = 0;
    $quote  = '';
    $length = strlen($code);

    for ($i = $open; $i < $length; $i++) {
        $ch = $code[$i];

        // 🧵 Inside a string literal nothing counts but the end of it. A
        //    backslash escapes the next character, including the closing
        //    quote — `'it\'s'` must not end at the apostrophe.
        if ($quote !== '') {
            if ($ch === '\\') {
                $i++;
                continue;
            }
            if ($ch === $quote) {
                $quote = '';
            }
            continue;
        }

        if ($ch === "'" || $ch === '"') {
            $quote = $ch;
            continue;
        }

        if ($ch === '{') {
            $depth++;
            continue;
        }

        if ($ch === '}') {
            $depth--;
            if ($depth === 0) {
                $block = substr($code, $at, $i - $at + 1);

                // 🚫 A heredoc makes this answer untrustworthy, so refuse it.
                //    See the docblock: a `{` inside one pushes the walk a level
                //    deeper and the block runs on to the ENCLOSING brace,
                //    swallowing the next refusal's `exit();`. Refusing here is
                //    what makes "it fails rather than passing" true.
                if (str_contains($block, '<<<') === true) {
                    return '';
                }

                return $block;
            }
        }
    }

    return '';
}

// =============================================================================
// 🌍 The made-up world
// =============================================================================
// Two organisations, because "one organisation's choice is its own" cannot be
// tested with one. Seven people, each holding exactly ONE thing, because an
// account holding several grounds at once cannot tell them apart (the #616
// lesson: three OR clauses could each be deleted with every check green).
//
//   DC   holds data_controller in organisation A, nothing else
//   PO   holds organisation A's OWN role privacy_officer, nothing else
//   ROOT a global administrator with NO role row at all          <- #724's person
//   ROOTDC a global administrator who HAS been granted data_controller
//   LAPSED holds data_controller in A but the membership is switched OFF
//   OTHER holds data_controller in organisation B only
//   NOBODY a member of A holding no role at all
$mysqli->query('SET FOREIGN_KEY_CHECKS = 0');

// ⚠️ BOTH ORGANISATIONS ARE MADE FRESH, never an existing one reused. A first
//    version read organisation 1 and then said "nothing is chosen to begin
//    with" — true once, and false on the second run of the same database,
//    where it also counted the first run's seven people. A fixture that only
//    holds on a clean database is a test that passes for a reason nothing
//    states.
$mysqli->query("INSERT INTO `tblSites` (`siteName`, `siteKey`) VALUES ('Capability A', 'cap-a-"
    . bin2hex(random_bytes(4)) . "')");
$siteA = (int) $mysqli->insert_id;
$mysqli->query("INSERT INTO `tblSites` (`siteName`, `siteKey`) VALUES ('Capability B', 'cap-b-"
    . bin2hex(random_bytes(4)) . "')");
$siteB = (int) $mysqli->insert_id;

Roles::seedStandardSet($mysqli, $siteA);
Roles::seedStandardSet($mysqli, $siteB);

// Organisation A's own role — the thing the whole issue is about.
$ownRoleKey = 'privacy_officer';
$stmt = $mysqli->prepare(
    "INSERT INTO `tblRoles` (`siteID`, `roleKey`, `roleName`, `description`, `isStandard`) "
    . "VALUES (?, ?, 'Privacy Officer', 'Our own role', 0)"
);
$stmt->bind_param('is', $siteA, $ownRoleKey);
$stmt->execute();
$ownRoleId = (int) $stmt->insert_id;
$stmt->close();

$capNewUser = static function (string $name, bool $isRootAdmin) use ($mysqli): int {
    $stmt = $mysqli->prepare(
        'INSERT INTO `tblUsers` (`fullName`, `emailAddress`, `isActive`, `isRootAdmin`) VALUES (?, ?, 1, ?)'
    );
    $email = strtolower(str_replace(' ', '-', $name)) . '-' . bin2hex(random_bytes(4)) . '@example.invalid';
    $root  = $isRootAdmin === true ? 1 : 0;
    $stmt->bind_param('ssi', $name, $email, $root);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();

    return $id;
};
$capMember = static function (int $userId, int $siteId, bool $active) use ($mysqli): void {
    $stmt = $mysqli->prepare(
        'INSERT INTO `tblUserSites` (`userID`, `siteID`, `isActive`) VALUES (?, ?, ?)'
    );
    $on = $active === true ? 1 : 0;
    $stmt->bind_param('iii', $userId, $siteId, $on);
    $stmt->execute();
    $stmt->close();
};
$capRoleId = static function (int $siteId, string $key) use ($mysqli): int {
    $stmt = $mysqli->prepare('SELECT `roleID` FROM `tblRoles` WHERE `siteID` = ? AND `roleKey` = ? LIMIT 1');
    $stmt->bind_param('is', $siteId, $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();

    return $row === null ? 0 : (int) $row[0];
};
$capGrant = static function (int $userId, int $roleId, int $siteId) use ($mysqli): void {
    $stmt = $mysqli->prepare('INSERT INTO `tblUserRoles` (`userID`, `roleID`, `siteID`) VALUES (?, ?, ?)');
    $stmt->bind_param('iii', $userId, $roleId, $siteId);
    $stmt->execute();
    $stmt->close();
};

$dcRoleA = $capRoleId($siteA, 'data_controller');
$dcRoleB = $capRoleId($siteB, 'data_controller');

$dcId     = $capNewUser('Cap DC', false);
$poId     = $capNewUser('Cap PO', false);
$rootId   = $capNewUser('Cap Root', true);
$rootDcId = $capNewUser('Cap Root DC', true);
$lapsedId = $capNewUser('Cap Lapsed', false);
$otherId  = $capNewUser('Cap Other', false);
$nobodyId = $capNewUser('Cap Nobody', false);

$capMember($dcId, $siteA, true);
$capMember($poId, $siteA, true);
$capMember($rootId, $siteA, true);
$capMember($rootDcId, $siteA, true);
$capMember($lapsedId, $siteA, false);
$capMember($otherId, $siteB, true);
$capMember($nobodyId, $siteA, true);

$capGrant($dcId, $dcRoleA, $siteA);
$capGrant($poId, $ownRoleId, $siteA);
$capGrant($rootDcId, $dcRoleA, $siteA);
$capGrant($lapsedId, $dcRoleA, $siteA);
$capGrant($otherId, $dcRoleB, $siteB);

// 🧑‍🤝‍🧑 ONE PERSON PER SHIPPED ROLE, for the five capabilities converted on
//    3 November 2026 (#727). Each of these five holds EXACTLY ONE role and
//    nothing else — no administrator flag, no second role — because an
//    account holding several grounds at once cannot tell them apart. That is
//    #616's lesson, where three OR clauses could each be deleted on their own
//    with every check still green.
//
//    Two more stand for the ways a role is NOT enough, and they hold the
//    asset-register role because that is the capability section O re-points:
//    one whose membership of this organisation is switched OFF, and one who
//    holds it in organisation B only.
// 🧑‍🤝‍🧑 ONE PERSON PER CAPABILITY, each holding ONLY the role that capability
//    ships pointing at. That is what lets section N ask, per capability and
//    per person, whether `App::can()` answers exactly what `App::hasRole()`
//    answered — the whole basis of "#739 changed nobody's access".
//
//    **#739's six were missing from here, so that equivalence was never asked
//    about them** — and the list was named `$capHolders` while eleven
//    capabilities existed. A list named after a number is the shape that goes
//    stale, so this one is named for its job.
$capHolders = [];
foreach ([
    Capabilities::ASSETS_MANAGE      => 'Cap Assets',
    Capabilities::CARE_REGISTER      => 'Cap Care',
    Capabilities::KIDS_CHECKIN       => 'Cap Kids',
    Capabilities::EXPENSES_TREASURY  => 'Cap Treasury',
    Capabilities::LIVESTREAM_CONSOLE => 'Cap Stream',
    // #739's six. Each person holds that one role and nothing else, so a
    // capability answering through the WRONG role cannot be mistaken for a
    // person who happened to hold both (#616's lesson: one account holding
    // several grounds cannot tell them apart).
    Capabilities::EXPENSES_APPROVE   => 'Cap Approver',
    Capabilities::GIVING_RECORDS     => 'Cap Giving',
    Capabilities::GROUPS_MANAGE      => 'Cap Groups',
    Capabilities::PRAYER_MODERATE    => 'Cap Prayer',
    Capabilities::VENUES_MANAGE      => 'Cap Venues',
    Capabilities::PHOTOS_STAFF       => 'Cap Staff',
    // #756: the volunteer photo rung became a real gate and so a capability
    // (D-047). Added HERE in the same change that added it to the registry,
    // because `check_capability_registry.py` only requires the key to EXIST in
    // `Roles::STANDARD` and section A asserts only that a capability ships with
    // A role, never WHICH — so a capability missing from these two lists ships
    // pointing at anything at all with every check green. That is #739's worst
    // finding, and it nearly handed the donor list to the wrong role.
    Capabilities::PHOTOS_VOLUNTEER   => 'Cap Volunteer',
] as $capabilityKey => $personName) {
    $shippedRole = Capabilities::standardRoleKeys($capabilityKey)[0];
    $personId    = $capNewUser($personName, false);
    $capMember($personId, $siteA, true);
    $capGrant($personId, $capRoleId($siteA, $shippedRole), $siteA);
    $capHolders[$capabilityKey] = $personId;
}
$assetLapsedId = $capNewUser('Cap Assets Lapsed', false);
$capMember($assetLapsedId, $siteA, false);
$capGrant($assetLapsedId, $capRoleId($siteA, 'asset_manager'), $siteA);
$assetOtherId = $capNewUser('Cap Assets Elsewhere', false);
$capMember($assetOtherId, $siteB, true);
$capGrant($assetOtherId, $capRoleId($siteB, 'asset_manager'), $siteB);

$mysqli->query('SET FOREIGN_KEY_CHECKS = 1');

$CAP = Capabilities::ERASURE_REVIEW;

// Every capability EXCEPT #724's, in the order they are declared.
//
// ⚠️ WRITE THE WHOLE LIST OUT, NEVER A COUNT, and keep it in declared order:
//    a count passes when one capability is swapped for another, and
//    `in_array` passes when one is quietly dropped (#697's remedy). Section A
//    below compares `Capabilities::keys()` with `$CAP` followed by this list,
//    which is the only place the expected set is written down.
//
// 🧾 It was called `$FIVE` until #739 and NOTHING USED IT — a list named
//    after a number, declared and then never read, while section A wrote the
//    same five keys out again by hand. #739 made it eleven and gave it the one
//    job, so there is one list rather than two.
$OTHERS = [
    Capabilities::ASSETS_MANAGE,
    Capabilities::CARE_REGISTER,
    Capabilities::KIDS_CHECKIN,
    Capabilities::EXPENSES_TREASURY,
    Capabilities::LIVESTREAM_CONSOLE,
    // The six #739 added, in declared order.
    Capabilities::EXPENSES_APPROVE,
    Capabilities::GIVING_RECORDS,
    Capabilities::GROUPS_MANAGE,
    Capabilities::PRAYER_MODERATE,
    Capabilities::VENUES_MANAGE,
    Capabilities::PHOTOS_STAFF,
    // #756 / D-047: the volunteer photo rung is a real gate now, so it is a
    // capability like the rest.
    Capabilities::PHOTOS_VOLUNTEER,
];

// =============================================================================
cap_section('A — the registry as declared');
// =============================================================================
// ⚠️ THE WHOLE LIST, IN ORDER, AND NOT A COUNT. A count passes when one
//    capability is swapped for another, and `in_array` passes when one is
//    quietly dropped — #697's remedy, where iterating a list meant deleting a
//    case stopped testing it while the label still said eight.
// ⚠️ AND THE LABEL IS NOT A NUMBER EITHER. It read "twelve capabilities are
//    offered" and went red the day a thirteenth was added (#756) — which is the
//    check working, but the NAME was the same shape #739 recorded as the one
//    that goes stale: a list named after a number. Two things in this file had
//    already been renamed for exactly that reason (`$fiveShouldShipWith` and
//    `$fiveHolders`), and this label was missed. It is named for its job now, so
//    adding a capability means editing ONE list rather than a list and a word.
cap_check(
    'every offered capability is declared, in order, and nothing has been added, '
        . 'dropped or swapped: #724\'s, the five the owner named on 3 November 2026, '
        . 'the six #739 converted, and #756\'s',
    Capabilities::keys() === array_merge([$CAP], $OTHERS),
    implode(',', Capabilities::keys())
);
// ⚠️ EVERY ONE OF THE OTHER ELEVEN FALLS OPEN FOR A GLOBAL ADMINISTRATOR, AND
//    #724'S DOES NOT. Both halves are asserted, because the whole reason
//    `globalAdminFallsOpen` has no default is that the registry holds opposite
//    answers to one question: a check that only tested the `false` one would
//    pass on a registry where every other entry had quietly flipped.
$allOthersFallOpen = true;
foreach ($OTHERS as $otherCap) {
    if (Capabilities::fallsOpenForGlobalAdmin($otherCap) === false) {
        $allOthersFallOpen = false;
    }
}
cap_check(
    'every capability but #724\'s falls open for a global administrator, as the owner asked',
    $allOthersFallOpen === true
);
// And each one ships with at least one role, which is what makes an
// installation that never opens the Roles page behave as it did before.
$allShipARole = true;
foreach ($OTHERS as $otherCap) {
    if (count(Capabilities::standardRoleKeys($otherCap)) === 0) {
        $allShipARole = false;
    }
}
cap_check('every one of them ships with a role, so nothing is closed on upgrade', $allShipARole === true);
cap_check(
    'it ships with the role its hard-coded gate used',
    Capabilities::standardRoleKeys($CAP) === ['data_controller'],
    implode(',', Capabilities::standardRoleKeys($CAP))
);
cap_check(
    'it does NOT fall open for a global administrator',
    Capabilities::fallsOpenForGlobalAdmin($CAP) === false
);
cap_check('its empty-set sentence is real words', strlen(Capabilities::emptyMeans($CAP)) > 40);
// 🫙 `NOT_YET_ASKED` IS EMPTY, AND THAT IS ASSERTED RATHER THAN ASSUMED. It
//    held the five until their gates were converted. Anything written there
//    is a capability nothing asks, so nothing may store one — and the whole
//    point of keeping the two lists apart is that the roles page can never
//    offer a door that opens nothing (#671).
cap_check(
    'nothing is written down but unoffered any more',
    Capabilities::NOT_YET_ASKED === [],
    implode(',', array_keys(Capabilities::NOT_YET_ASKED))
);
$allUnknown = true;
foreach (array_keys(Capabilities::NOT_YET_ASKED) as $key) {
    if (Capabilities::isKnown($key) === true) {
        $allUnknown = false;
    }
}
cap_check('and were there one, it would not be "known", so it could not be stored', $allUnknown === true);
cap_check(
    'a save for a capability this portal does not offer is refused by name',
    Capabilities::save($mysqli, $siteA, 'no.such.capability', ['data_controller'], $dcId, []) === 'unknown'
);
$threw = false;
try {
    Capabilities::definition('no.such.thing');
} catch (\InvalidArgumentException $e) {
    $threw = true;
}
cap_check('an unknown capability THROWS rather than answering "no"', $threw === true);

// =============================================================================
cap_section('B — an organisation that has chosen nothing sees no change');
// =============================================================================
cap_check('nothing is chosen to begin with', Capabilities::chosenRoleKeys($mysqli, $siteA, $CAP) === null);
cap_check(
    'so the roles in force are the shipped ones',
    Capabilities::roleKeysInForce($mysqli, $siteA, $CAP) === ['data_controller']
);
cap_check(
    'the Data Controller is allowed',
    Capabilities::allows($mysqli, $dcId, $siteA, $CAP, false) === true
);
cap_check(
    'somebody with no role is not',
    Capabilities::allows($mysqli, $nobodyId, $siteA, $CAP, false) === false
);
// The membership, not just the role: a person whose membership has been
// switched off is not a member any more, and `Roles::hasAnyOf()` joins on it.
cap_check(
    'a person whose membership is switched off is not',
    Capabilities::allows($mysqli, $lapsedId, $siteA, $CAP, false) === false
);
cap_check(
    'and neither is organisation B\'s Data Controller, here',
    Capabilities::allows($mysqli, $otherId, $siteA, $CAP, false) === false
);

// =============================================================================
cap_section('C — #724\'s gate: a global administrator without the role is REFUSED');
// =============================================================================
// ⚠️ THIS IS THE ACCEPTANCE TEST FOR THE WHOLE FEATURE. `App::hasRole()`
//    answers TRUE for any global administrator with no role row (#516), so a
//    registry that resolved everything through it would have silently undone
//    the reason the Data Controller role exists. The flag is passed in as a
//    FACT and the capability's own declaration decides whether to honour it.
cap_check(
    'a global administrator who has NOT been granted the role is refused',
    Capabilities::allows($mysqli, $rootId, $siteA, $CAP, true) === false
);
cap_check(
    'the same person is still refused when they hold no role at all by any other route',
    Roles::hasAnyOf($mysqli, $rootId, $siteA, ['data_controller']) === false
);
cap_check(
    'a global administrator who HAS been granted it is allowed',
    Capabilities::allows($mysqli, $rootDcId, $siteA, $CAP, true) === true
);
// All four combinations of the rule that decides it, which is why it is a
// named method: no offered capability declares `globalAdminFallsOpen => true`,
// so inside an `if` the "true" half would be reached by nothing.
cap_check('rule: global admin + falls open  -> in',    Capabilities::globalAdminPasses(true, true) === true);
cap_check('rule: global admin + does not    -> not in', Capabilities::globalAdminPasses(true, false) === false);
cap_check('rule: not global admin + open    -> not in', Capabilities::globalAdminPasses(false, true) === false);
cap_check('rule: not global admin + closed  -> not in', Capabilities::globalAdminPasses(false, false) === false);

// =============================================================================
cap_section('D — any-of: the organisation adds a role of its own beside the standard one');
// =============================================================================
cap_check(
    'the save is accepted',
    Capabilities::save($mysqli, $siteA, $CAP, ['data_controller', $ownRoleKey], $dcId, []) === 'ok'
);
$chosen = Capabilities::chosenRoleKeys($mysqli, $siteA, $CAP);
cap_check('both roles are stored', $chosen === ['data_controller', 'privacy_officer'], implode(',', (array) $chosen));
cap_check(
    'the standard role still opens it',
    Capabilities::allows($mysqli, $dcId, $siteA, $CAP, false) === true
);
cap_check(
    'and so does the organisation\'s OWN role — which is the whole issue',
    Capabilities::allows($mysqli, $poId, $siteA, $CAP, false) === true
);
cap_check(
    'somebody with neither is still refused',
    Capabilities::allows($mysqli, $nobodyId, $siteA, $CAP, false) === false
);
cap_check(
    'saving the same set again changes nothing and says so',
    Capabilities::save($mysqli, $siteA, $CAP, [$ownRoleKey, 'data_controller'], $dcId, []) === 'unchanged'
);
// THREE people, not two, and the figure is worth stating: the Data
// Controller, the global administrator who was granted that role, and the
// holder of the organisation's own role. The person whose MEMBERSHIP is
// switched off holds the role and is NOT counted, and neither is organisation
// B's. (An earlier version of this label said "two people" while asserting
// three — a label that does not match its own assertion is the kind of
// untruth this repository treats as a defect.)
cap_check(
    'three PEOPLE hold one of them — counted as people, not as holdings, and '
    . 'a switched-off membership does not count',
    Capabilities::holderCount($mysqli, $siteA, ['data_controller', $ownRoleKey]) === 3,
    (string) Capabilities::holderCount($mysqli, $siteA, ['data_controller', $ownRoleKey])
);

// =============================================================================
cap_section('E — re-pointing really re-points');
// =============================================================================
cap_check(
    'the organisation names only its own role',
    Capabilities::save($mysqli, $siteA, $CAP, [$ownRoleKey], $dcId, []) === 'ok'
);
cap_check(
    'its own role opens it',
    Capabilities::allows($mysqli, $poId, $siteA, $CAP, false) === true
);
cap_check(
    'and the STANDARD role no longer does',
    Capabilities::allows($mysqli, $dcId, $siteA, $CAP, false) === false
);
cap_check(
    'a global administrator holding the standard role is shut out too',
    Capabilities::allows($mysqli, $rootDcId, $siteA, $CAP, true) === false
);

// =============================================================================
cap_section('F — the empty set is a decision, and it is not the same as "never chosen"');
// =============================================================================
cap_check('an empty set is accepted', Capabilities::save($mysqli, $siteA, $CAP, [], $dcId, []) === 'ok');
cap_check(
    'it is CHOSEN and empty, which is not null',
    Capabilities::chosenRoleKeys($mysqli, $siteA, $CAP) === []
);
cap_check(
    'so the roles in force are none at all — NOT the shipped ones',
    Capabilities::roleKeysInForce($mysqli, $siteA, $CAP) === []
);
cap_check('nobody holding the standard role gets in', Capabilities::allows($mysqli, $dcId, $siteA, $CAP, false) === false);
cap_check('nobody holding the own role gets in', Capabilities::allows($mysqli, $poId, $siteA, $CAP, false) === false);
cap_check(
    'and not a global administrator either, because this one does not fall open',
    Capabilities::allows($mysqli, $rootId, $siteA, $CAP, true) === false
);
cap_check('nobody holds any of nothing', Capabilities::holderCount($mysqli, $siteA, []) === 0);
// And the map row really is there — "chosen and empty" is a row with no
// children, which is the whole reason there are two tables.
cap_check(
    'the choice is a row of its own with no roles beside it',
    (int) cap_one(
        'SELECT COUNT(*) FROM `' . Capabilities::MAP_TABLE . '` WHERE `siteID` = ? AND `capabilityKey` = ?',
        'is',
        [$siteA, $CAP]
    ) === 1
    && (int) cap_one(
        'SELECT COUNT(*) FROM `' . Capabilities::MAP_ROLE_TABLE . '` WHERE `siteID` = ?',
        'i',
        [$siteA]
    ) === 0
);
cap_check('forgetting the choice is accepted', Capabilities::forget($mysqli, $siteA, $CAP, $dcId, []) === 'ok');
cap_check(
    'and it is back to "never chosen", not to "chosen and the same"',
    Capabilities::chosenRoleKeys($mysqli, $siteA, $CAP) === null
);
cap_check(
    'so the shipped role opens it again',
    Capabilities::allows($mysqli, $dcId, $siteA, $CAP, false) === true
);
cap_check(
    'forgetting twice says nothing changed',
    Capabilities::forget($mysqli, $siteA, $CAP, $dcId, []) === 'unchanged'
);

// =============================================================================
cap_section('G — the installation can refuse, and "we cannot tell" refuses too');
// =============================================================================
cap_check(
    'with nothing locked, the organisation may change it',
    Capabilities::whyNotChangeable($CAP, []) === ''
);
$lockedLayer = ['capabilities' => ['locked' => $CAP]];
cap_check(
    'with it locked, the refusal is a sentence somebody can read',
    strlen(Capabilities::whyNotChangeable($CAP, $lockedLayer)) > 40
);
cap_check(
    'and the save is refused',
    Capabilities::save($mysqli, $siteA, $CAP, [$ownRoleKey], $dcId, $lockedLayer) === 'not_allowed'
);
cap_check(
    'with NOTHING stored by that save',
    Capabilities::chosenRoleKeys($mysqli, $siteA, $CAP) === null
);
cap_check(
    'forgetting is refused too',
    Capabilities::forget($mysqli, $siteA, $CAP, $dcId, $lockedLayer) === 'not_allowed'
);
// ⚠️ A NULL LAYER IS "WE CANNOT TELL", and that locks EVERYTHING. The
//    direction matters: refusing a change is recoverable in one click, and
//    allowing one the installation forbids is not.
cap_check(
    'a layer that could not be read locks every capability',
    Capabilities::installationLocks(null) === Capabilities::keys()
);
cap_check(
    'and the refusal is given rather than the change allowed',
    Capabilities::whyNotChangeable($CAP, null) !== ''
);
cap_check(
    'an unknown key in the locked list is ignored rather than breaking the page',
    Capabilities::installationLocks(['capabilities' => ['locked' => 'no.such.thing,' . $CAP]]) === [$CAP]
);
cap_check(
    'a locked list with spaces and duplicates still means one capability',
    Capabilities::installationLocks(['capabilities' => ['locked' => ' ' . $CAP . ' , ' . $CAP]]) === [$CAP]
);
cap_check('an empty locked value locks nothing', Capabilities::installationLocks(['capabilities' => ['locked' => '']]) === []);
cap_check('a missing key locks nothing', Capabilities::installationLocks(['other' => ['thing' => 'x']]) === []);

// The installation's own writer, and the row it writes.
cap_check(
    'the installation can store what it reserves',
    Capabilities::saveInstallationLocks($mysqli, [$CAP, 'no.such.thing'], $rootId) === 'ok'
);
cap_check(
    'the unknown key is not stored',
    (string) cap_one(
        "SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL",
        's',
        [Capabilities::LOCK_SETTING_KEY]
    ) === $CAP
);
cap_check(
    'and it writes NO per-organisation row — an organisation deciding what the installation reserves '
    . 'would be the point inverted',
    (int) cap_one(
        "SELECT COUNT(*) FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NOT NULL",
        's',
        [Capabilities::LOCK_SETTING_KEY]
    ) === 0
);
cap_check(
    'storing the same thing again says nothing changed',
    Capabilities::saveInstallationLocks($mysqli, [$CAP], $rootId) === 'unchanged'
);
cap_check(
    'and it can be cleared again',
    Capabilities::saveInstallationLocks($mysqli, [], $rootId) === 'ok'
);

// =============================================================================
cap_section('H — one organisation\'s choice is its own');
// =============================================================================
cap_check(
    'organisation B chooses its own Data Controller only',
    Capabilities::save($mysqli, $siteB, $CAP, ['data_controller'], $otherId, []) === 'ok'
);
cap_check('B has a choice', Capabilities::chosenRoleKeys($mysqli, $siteB, $CAP) === ['data_controller']);
cap_check('A still has none', Capabilities::chosenRoleKeys($mysqli, $siteA, $CAP) === null);
cap_check(
    'B\'s Data Controller is allowed in B',
    Capabilities::allows($mysqli, $otherId, $siteB, $CAP, false) === true
);
cap_check(
    'A\'s Data Controller is not allowed in B',
    Capabilities::allows($mysqli, $dcId, $siteB, $CAP, false) === false
);
// A role key that exists, but in the OTHER organisation. Refused by name, not
// by an exception out of a foreign key.
cap_check(
    'A cannot name a role that is only B\'s',
    Capabilities::save($mysqli, $siteB, $CAP, [$ownRoleKey], $otherId, []) === 'bad_role'
);
cap_check(
    'and nothing of B\'s was changed by that refusal',
    Capabilities::chosenRoleKeys($mysqli, $siteB, $CAP) === ['data_controller']
);
cap_check(
    'a role key that exists nowhere is refused the same way',
    Capabilities::save($mysqli, $siteA, $CAP, ['no_such_role'], $dcId, []) === 'bad_role'
);

// =============================================================================
cap_section('I — deleting a role');
// =============================================================================
Capabilities::save($mysqli, $siteA, $CAP, ['data_controller', $ownRoleKey], $dcId, []);
cap_check(
    'with two roles on it, deleting one leaves the capability reachable, so nothing warns',
    Capabilities::capabilitiesLeftEmptyByRemoving($mysqli, $siteA, $ownRoleKey) === []
);
// The storage's own link: the role goes, and it goes out of the set with it.
$stmt = $mysqli->prepare('DELETE FROM `tblRoles` WHERE `roleID` = ? AND `siteID` = ?');
$stmt->bind_param('ii', $ownRoleId, $siteA);
$stmt->execute();
$stmt->close();
cap_check(
    'the deleted role is out of the set, left by the database rather than by a sweep',
    Capabilities::chosenRoleKeys($mysqli, $siteA, $CAP) === ['data_controller']
);
cap_check(
    'and it no longer opens the door',
    Capabilities::allows($mysqli, $poId, $siteA, $CAP, false) === false
);
cap_check(
    'the standard role, which is now the LAST one, is reported as such',
    Capabilities::capabilitiesLeftEmptyByRemoving($mysqli, $siteA, 'data_controller') === [$CAP]
);
cap_check(
    'a role on no capability at all is reported as emptying nothing',
    Capabilities::capabilitiesLeftEmptyByRemoving($mysqli, $siteA, 'treasurer') === []
);
// And where the organisation has chosen NOTHING, no role deletion can empty a
// set — the shipped list is not an organisation's to change.
Capabilities::forget($mysqli, $siteA, $CAP, $dcId, []);
cap_check(
    'with nothing chosen, no deletion can empty a set',
    Capabilities::capabilitiesLeftEmptyByRemoving($mysqli, $siteA, 'data_controller') === []
);

// =============================================================================
cap_section('J — a database that has not had migration 243');
// =============================================================================
// ⚠️ FILES REACH A SERVER BEFORE ANYBODY PRESSES "RUN MIGRATIONS" (#694). In
//    that window every door in the portal is asked this question, so the
//    answer must be "the shipped roles" — never an exception, and never
//    "nobody".
Capabilities::save($mysqli, $siteA, $CAP, ['data_controller'], $dcId, []);
$mysqli->query('RENAME TABLE `' . Capabilities::MAP_ROLE_TABLE . '` TO `zzcap_role_hidden`');
$mysqli->query('RENAME TABLE `' . Capabilities::MAP_TABLE . '` TO `zzcap_map_hidden`');
// 🗒️ The class remembers "are the tables there" and "which roles open this"
//    for the rest of a request, because a table does not appear or disappear
//    while a real one is being served (see `forgetMemo()`). This test makes
//    that untrue on purpose, so it has to say so — exactly as the roles page
//    does after it deletes a role.
Capabilities::forgetMemo();
cap_check('the storage reads as absent', Capabilities::storageExists($mysqli) === false);
cap_check(
    'a chosen set cannot be read, which is reported as "nothing chosen"',
    Capabilities::chosenRoleKeys($mysqli, $siteA, $CAP) === null
);
cap_check(
    'so the shipped roles are in force — not nobody',
    Capabilities::roleKeysInForce($mysqli, $siteA, $CAP) === ['data_controller']
);
cap_check(
    'and the door still opens for the right person',
    Capabilities::allows($mysqli, $dcId, $siteA, $CAP, false) === true
);
cap_check(
    'a save says plainly that there is nowhere to put it',
    Capabilities::save($mysqli, $siteA, $CAP, ['data_controller'], $dcId, []) === 'no_storage'
);
cap_check(
    'and so does forgetting one',
    Capabilities::forget($mysqli, $siteA, $CAP, $dcId, []) === 'no_storage'
);
$mysqli->query('RENAME TABLE `zzcap_map_hidden` TO `' . Capabilities::MAP_TABLE . '`');
$mysqli->query('RENAME TABLE `zzcap_role_hidden` TO `' . Capabilities::MAP_ROLE_TABLE . '`');
Capabilities::forgetMemo();
cap_check('the storage is back', Capabilities::storageExists($mysqli) === true);
cap_check(
    'and the choice that was made before it was hidden is still there',
    Capabilities::chosenRoleKeys($mysqli, $siteA, $CAP) === ['data_controller']
);

// =============================================================================
cap_section('K — what a change leaves written down');
// =============================================================================
$beforeActivity = (int) cap_one(
    "SELECT COUNT(*) FROM `tblActivityLogs` WHERE `activityType` = 'CapabilityRolesChange'"
);
Capabilities::save($mysqli, $siteA, $CAP, ['data_controller', 'treasurer'], $dcId, []);
$afterActivity = (int) cap_one(
    "SELECT COUNT(*) FROM `tblActivityLogs` WHERE `activityType` = 'CapabilityRolesChange'"
);
cap_check('a change leaves an activity line', $afterActivity === $beforeActivity + 1);
$line = (string) cap_one(
    "SELECT `activityDescription` FROM `tblActivityLogs` WHERE `activityType` = 'CapabilityRolesChange' "
    . 'ORDER BY `logID` DESC LIMIT 1'
);
cap_check('which names the capability', str_contains($line, $CAP) === true, $line);
cap_check('and the roles', str_contains($line, 'treasurer') === true, $line);
// Account NUMBERS only, never names — the #518 convention `Roles::grant()`
// already follows. A capability change is read by administrators who know
// their own members by number.
$dcName  = (string) cap_one('SELECT `fullName` FROM `tblUsers` WHERE `userID` = ?', 'i', [$dcId]);
$dcEmail = (string) cap_one('SELECT `emailAddress` FROM `tblUsers` WHERE `userID` = ?', 'i', [$dcId]);
cap_check('and NOT the name of whoever changed it', str_contains($line, $dcName) === false);
cap_check('and NOT their e-mail address', str_contains($line, $dcEmail) === false);
$auditCount = (int) cap_one(
    "SELECT COUNT(*) FROM `tblAuditTrail` WHERE `tableName` = ?",
    's',
    [Capabilities::MAP_TABLE]
);
cap_check('and an audit row against the storage table', $auditCount > 0, (string) $auditCount);
$auditNew = (string) cap_one(
    "SELECT IFNULL(`newValue`, '') FROM `tblAuditTrail` WHERE `tableName` = ? ORDER BY `auditID` DESC LIMIT 1",
    's',
    [Capabilities::MAP_TABLE]
);
cap_check('whose "after" says which roles', str_contains($auditNew, 'treasurer') === true, $auditNew);
cap_check('and holds no e-mail address', str_contains($auditNew, $dcEmail) === false);

// =============================================================================
cap_section('L — the gates are where they must be');
// =============================================================================
// Whole conditions, comments stripped by the tokeniser: a check matching the
// TEXT of a guard passes when `&& false` is added to it, and one matching a
// comment passes when the code is deleted.
$appsDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_apps' . DIRECTORY_SEPARATOR
    . 'admin' . DIRECTORY_SEPARATOR . 'erasure' . DIRECTORY_SEPARATOR;
foreach (['review.php', 'review-save.php'] as $pageFile) {
    $pageCode = cap_codeOnly($appsDir . $pageFile);
    cap_check(
        $pageFile . ' asks the registry, whole condition',
        str_contains($pageCode, 'if (App::can(Capabilities::ERASURE_REVIEW) === false) {') === true
    );
    cap_check(
        $pageFile . ' refuses rather than merely testing',
        str_contains(
            $pageCode,
            "if (App::can(Capabilities::ERASURE_REVIEW) === false) {\n    Router::renderError(403);\n    return;\n}"
        ) === true
    );
    cap_check(
        $pageFile . ' does NOT use App::hasRole(), which would let a role-less global administrator in',
        str_contains($pageCode, 'App::hasRole(') === false
    );
}
// `App::can()` must hand the flag over rather than answering for itself. One
// `return true;` there would undo every `globalAdminFallsOpen => false` at
// once, with nothing on any page looking different.
$appCode = cap_codeOnly(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_core'
    . DIRECTORY_SEPARATOR . 'App.php');
$canAt = strpos($appCode, 'public static function can(string $capability): bool');
cap_check('App::can() is still there', $canAt !== false);
if ($canAt !== false) {
    $canBody = substr($appCode, $canAt, 700);
    cap_check(
        'and it passes the global administrator flag to Capabilities::allows()',
        str_contains($canBody, "self::flagIsOn(\$user, 'isRootAdmin')") === true
        && str_contains($canBody, 'Capabilities::allows(') === true
    );
    cap_check(
        'and takes no shortcut of its own',
        preg_match('/return\s+true\s*;/', $canBody) !== 1
    );
}
// The ONE place a capability's roles come from. A second reader would be free
// to disagree with this one, which is the fault #668 and #677 both are.
$capCode = cap_codeOnly(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_core'
    . DIRECTORY_SEPARATOR . 'Capabilities.php');
cap_check(
    'Capabilities::allows() asks roleKeysInForce() and Roles::hasAnyOf(), and nothing else decides',
    str_contains($capCode, 'self::roleKeysInForce($db, $siteId, $capability)') === true
    && str_contains($capCode, 'Roles::hasAnyOf($db, $userId, $siteId, $keys)') === true
);

// -----------------------------------------------------------------------------
// 🪧 `Roles::hasAnyOf()` IS NEW SQL, SO EVERY IDENTIFIER IN IT IS BACKTICKED
// -----------------------------------------------------------------------------
// The owner's standing rule for every project: backtick every identifier in new
// or changed SQL, table and column names alike. Not because any name here is a
// reserved word — none is — but because the reserved set GROWS between versions
// and differs between engines, a bare identifier that is a reserved word does
// not PARSE, and `php -l` cannot see inside a string. That is exactly how the
// statement saving a transcript shipped unable to run: `fullText` IS `FULLTEXT`
// (#276), and it threw after an outside provider had been called and charged.
//
// `hasAnyOf()` was written for this package and shipped with no backtick on any
// identifier, copied from `has()` beside it — which predates the rule and is
// deliberately left alone. `Capabilities::holderCount()`, written in the same
// package, did backtick. The statement is pinned WHOLE rather than counted,
// because a count of backticks passes when one is moved to the wrong name.
$rolesCode = cap_codeOnly(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_core'
    . DIRECTORY_SEPARATOR . 'Roles.php');
cap_check(
    'Roles::hasAnyOf() backticks every identifier in its statement, whole statement pinned',
    str_contains(
        $rolesCode,
        "'SELECT 1 FROM `tblUserRoles` `ur` '\n            . 'JOIN `tblRoles` `r` ON `r`.`roleID` = `ur`.`roleID` "
        . "AND `r`.`siteID` = `ur`.`siteID` '\n            . 'JOIN `tblUserSites` `us` ON `us`.`userID` = `ur`.`userID` '"
        . "\n            . 'AND `us`.`siteID` = `ur`.`siteID` AND `us`.`isActive` = 1 '"
        . "\n            . 'WHERE `ur`.`userID` = ? AND `ur`.`siteID` = ? '"
        . "\n            . 'AND `r`.`roleKey` IN (' . \$placeholders . ') LIMIT 1'"
    ) === true
);
// 🧪 AND IT STILL ANSWERS THE SAME THING, which is the half a text pin cannot
//    see. The rewrite is the reason every converted gate answers what
//    `App::hasRole()` answered before it, so the equivalence is checked against
//    a real database rather than by comparing two strings: the holder of a
//    shipped role is in, somebody with no role is out, and a membership that is
//    switched off is out (the `us`.`isActive` term).
cap_check(
    'and it still answers the same thing — holder in, nobody out, lapsed membership out',
    Roles::hasAnyOf($mysqli, $dcId, $siteA, ['data_controller']) === true
    && Roles::hasAnyOf($mysqli, $nobodyId, $siteA, ['data_controller']) === false
    && Roles::hasAnyOf($mysqli, $lapsedId, $siteA, ['data_controller']) === false
    && Roles::hasAnyOf($mysqli, $dcId, $siteB, ['data_controller']) === false
);

// =============================================================================
cap_section('M — the roles page\'s own gates, pinned as text');
// =============================================================================
// ⚠️ THESE ARE TEXT PINS AND NOT BEHAVIOUR. Nothing here starts a web server,
//    so the confirmation box, the global administrator's card and the refused
//    delete have never been pressed. They are pinned because they are the only
//    guard over three real harms — an empty set written by a mis-click, an
//    organisation deciding what the installation reserves, and a door emptied
//    as a side effect of deleting a role nobody holds — and because removing
//    any of them was caught by NOTHING until this section existed. A text pin
//    is the cheap half of the remedy, not the whole of it.
$rolesDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_apps' . DIRECTORY_SEPARATOR
    . 'admin' . DIRECTORY_SEPARATOR . 'roles' . DIRECTORY_SEPARATOR;
$saveCode  = cap_codeOnly($rolesDir . 'save.php');
$indexCode = file_get_contents($rolesDir . 'index.php');
// 🔎 The gate pins below read the CODE ONLY, with comments dropped by PHP's
//    tokeniser, so a commented-out gate cannot satisfy them. `$indexCode`
//    above stays as the raw file because the pins further down are on MARKUP
//    (the confirmation box, the form boundaries) rather than on code.
$indexCodeOnly = cap_codeOnly($rolesDir . 'index.php');

// -----------------------------------------------------------------------------
// ⚠️ THE ADMINISTRATOR GATE ITSELF, WHICH THIS SECTION PINNED BY NOTHING UNTIL
//    3 NOVEMBER 2026 — AND IT IS THE ONE THAT MATTERS MOST.
//
//    An independent check changed `if (App::isAdmin() === false)` to
//    `if (false)` in each of these two files, one at a time, and got: `php -l`
//    clean, all 53 audit scripts green, and this self-test 199/199 BOTH TIMES.
//    This section was already *titled* "the roles page's own gates" and pinned
//    five lesser conditions in `save.php` — the empty-set refusal, the exact
//    `confirm_empty` comparison, the `is_array` refusal, the installation's
//    `isRootAdmin` block and the delete refusal — and not this one.
//
//    `save.php` is the single writer of every capability mapping. With that
//    one word changed, ANY SIGNED-IN MEMBER can post `action=capability` and
//    re-point all six doors at a role they hold — the pastoral care register,
//    the asset register, children's check-in, the expenses treasury, the
//    livestream chat and #724's erasure review — and can rename and delete
//    roles. The gate predates #727 (it is #516's), so the hole was
//    pre-existing; what #727 did was make that one line the thing standing
//    between a member and six access decisions, and then argue FROM it in
//    `Capabilities`' class header ("an empty set is always recoverable
//    because the page that changes a mapping is gated on `App::isAdmin()`"),
//    in `CHANGELOG.md` and in the standing rule in `.claude/CLAUDE.md`.
//
//    THE PIN IS THE WHOLE BLOCK AS ONE LITERAL, deliberately, and not a
//    regular expression. One literal does both jobs at once: the WHOLE
//    condition, so `if (false)` and `&& false` both fail it (#668's section-D
//    lesson and #674's), and the refusal INSIDE the block, so deleting the
//    `Router::renderError(403); return;` fails it too — matching a condition
//    on its own passed with the `exit` removed once before. A regular
//    expression that allowed "anything, then a return" would pass on a block
//    that returned after doing the work.
$administratorGate = "if (App::isAdmin() === false) {\n    Router::renderError(403);\n    return;\n}";
foreach (['index.php' => $indexCodeOnly, 'save.php' => $saveCode] as $gateFile => $gateCode) {
    cap_check(
        $gateFile . ' refuses anybody who is not an administrator — whole condition AND the '
        . 'refusal inside the block, as one literal',
        str_contains($gateCode, $administratorGate) === true
    );

    // 🧱 AND IT MUST COME FIRST. A gate that is present but sits below the
    //    work is no gate at all, and a literal pin cannot see where a block
    //    is. Both files have it as the first executable statement after their
    //    `use` lines; this asserts nothing that reads the request, nothing
    //    that resolves the organisation and nothing that touches the registry
    //    appears above it. Measured positions on 3 November 2026: the gate at
    //    220 in `index.php` and 265 in `save.php`, with the earliest of these
    //    at 307 and 346 respectively.
    $gateAt = strpos($gateCode, $administratorGate);
    $nothingAbove = true;
    foreach (['$_SERVER', '$_POST', '$_GET', 'Site::id()', 'Capabilities::'] as $mustComeLater) {
        $firstAt = strpos($gateCode, $mustComeLater);
        if ($firstAt !== false && $gateAt !== false && $firstAt < $gateAt) {
            $nothingAbove = false;
        }
    }
    cap_check(
        'and ' . $gateFile . ' has it ABOVE everything that reads the request, resolves the '
        . 'organisation or asks the registry',
        $gateAt !== false && $nothingAbove === true
    );
}

// The WHOLE condition, and the block it leads to: matching only part of it
// passes when `&& false` is added, and matching the condition alone passes
// when the `exit()` is deleted (#668's section-D lesson).
cap_check(
    'save.php refuses an empty set that nobody confirmed, whole condition',
    str_contains($saveCode, 'if (count($roleKeys) === 0 && $confirmedEmpty === false) {') === true
);
$emptySetBlock = cap_blockFrom($saveCode, 'if (count($roleKeys) === 0 && $confirmedEmpty === false) {');
cap_check(
    'and that refusal really stops, rather than falling through to the save',
    $emptySetBlock !== '' && str_contains($emptySetBlock, 'exit();') === true,
    $emptySetBlock === '' ? 'the block could not be cut' : 'block is ' . strlen($emptySetBlock) . ' chars'
);
cap_check(
    'the confirmation is read as an exact value, so an unticked box cannot satisfy it',
    str_contains($saveCode, "\$confirmedEmpty = (\$_POST['confirm_empty'] ?? '') === '1';") === true
);
// A string where a list is expected would make `foreach` throw: a request
// chooses the TYPE of what it sends as well as the value (27 October 2026).
cap_check(
    'a posted roleKeys that is not a list is refused rather than walked',
    str_contains($saveCode, 'if (is_array($posted) === false) {') === true
);
// What the INSTALLATION reserves is not an organisation's business.
cap_check(
    'the installation\'s own action is for a global administrator only, and refuses',
    str_contains(
        $saveCode,
        "if (App::isRootAdmin() === false) {\n        Router::renderError(403);\n        return;\n    }"
    ) === true
);
// The delete guard. Without it, deleting a role nobody holds empties a
// capability's set with nobody confirming it.
cap_check(
    'save.php asks whether deleting a role would empty a capability',
    str_contains($saveCode, 'Capabilities::capabilitiesLeftEmptyByRemoving(') === true
);
$wouldEmptyBlock = cap_blockFrom($saveCode, 'if (count($wouldEmpty) > 0) {');
cap_check(
    'and refuses the delete when it would, rather than noting it',
    $wouldEmptyBlock !== '' && str_contains($wouldEmptyBlock, 'exit();') === true,
    $wouldEmptyBlock === '' ? 'the block could not be cut' : 'block is ' . strlen($wouldEmptyBlock) . ' chars'
);
// ⚠️ AND IT REFUSES WHEN IT CANNOT ASK AT ALL. The first version left the key
//    null on a failed `prepare()` and wrapped the whole question in
//    `if ($roleKeyRow !== null)`, with the DELETE outside that `if` — so a
//    transient database failure skipped the guard and deleted the role anyway,
//    emptying a capability's set with nobody confirming it. "We could not tell"
//    must never become "nobody has, go ahead" (#647's rule).
// 🧱 This was the SIXTH refusal pin in this file and the last one still
//    cutting its block by indentation — which made "cap_blockFrom() is the one
//    way a block is cut in this file" untrue while four documents said it. An
//    independent check found it. It was sound (planted: deleting its own
//    `exit();` turned the test red), so nothing was broken; what was wrong was
//    the claim. One way now, not two.
$couldNotRead = cap_blockFrom($saveCode, 'if ($roleKeyRow === null) {');
cap_check(
    'a role key it could not READ refuses the delete, whole condition',
    $couldNotRead !== '',
    $couldNotRead === '' ? 'there is no `if ($roleKeyRow === null)` branch at all' : ''
);
cap_check(
    '...and that branch really stops, and records that nothing was deleted',
    $couldNotRead !== ''
    && str_contains($couldNotRead, 'exit();') === true
    && str_contains($couldNotRead, "'RoleDeleteRefused'") === true,
    $couldNotRead === '' ? 'no branch to look in' : 'block is ' . strlen($couldNotRead) . ' chars'
);
cap_check(
    '...and the capability question is NOT wrapped in "if we did read it", which is how it was skipped',
    str_contains($saveCode, 'if ($roleKeyRow !== null) {') === false
);
// The page itself: the box, and the capability's OWN sentence beside it. A
// general warning would not say what THIS door costs.
cap_check(
    'index.php draws the confirmation box',
    str_contains($indexCode, 'name="confirm_empty"') === true
);
cap_check(
    'with the capability\'s own words about what an empty set does',
    str_contains($indexCode, 'Capabilities::emptyMeans($capKey)') === true
);
// ⚠️ NO `disabled` CONTROL (#667/#677): a disabled control is not submitted,
//    so a form drawn for somebody who may not change it would post an EMPTY
//    set and read as a deliberate "nobody".
// ⚠️ THE FIRST VERSION OF THIS CHECK COULD NEVER FAIL. It read
//    `no 'disabled' anywhere || the role-key box is disabled`, and the page
//    HAS had that box disabled since #516 — so the right-hand side was always
//    true and the clause passed whatever else was added. That is the shape
//    this repository keeps paying for: a clause that cannot fail makes a check
//    LOOK stronger than it is. It counts instead, and names the one that is
//    allowed, so a second disabled control fails it.
// It asks of the FORMS THEMSELVES, not of the whole file: the page has had a
// disabled role-key box since #516, and counting mentions would also count the
// comment that explains why there is no other.
$capFormFrom = strpos($indexCode, 'name="action" value="capability"');
$capFormTo   = strpos($indexCode, 'Save who opens this');
$lockFormFrom = strpos($indexCode, 'name="action" value="capability_locks"');
$lockFormTo   = strpos($indexCode, 'Save what this installation reserves');
cap_check(
    'neither capability form contains a disabled control — one would not be submitted, '
    . 'so it would post an EMPTY set and read as a deliberate "nobody" (#667/#677)',
    $capFormFrom !== false && $capFormTo !== false && $capFormTo > $capFormFrom
    && $lockFormFrom !== false && $lockFormTo !== false && $lockFormTo > $lockFormFrom
    && str_contains(substr($indexCode, $capFormFrom, $capFormTo - $capFormFrom), 'disabled') === false
    && str_contains(substr($indexCode, $lockFormFrom, $lockFormTo - $lockFormFrom), 'disabled') === false
);
cap_check(
    'the page reads the installation\'s own layer, never the merged settings (#674)',
    str_contains($indexCode, 'InstallationSettings::layerOrNull()') === true
    && str_contains($indexCode, "App::settings()['capabilities']") === false
);

// -----------------------------------------------------------------------------
// 🔒 `capabilities.locked` IS REFUSED IN THE GENERAL SETTINGS EDITOR (#727)
// -----------------------------------------------------------------------------
// It is an ordinary installation-wide `tblSettings` row, so until 3 November
// 2026 a global administrator could edit it in `/settings` — a page of
// key/value boxes with no idea what any key means. Doing it there skips all
// three things `saveInstallationLocks()` does: it validates every capability
// key, writes an audit record and writes an activity record. A row typed there
// as `assets.manag` would reserve NOTHING while reading as a reservation,
// because `installationLocks()` ignores a key it does not recognise.
//
// Not a privilege question — the same global administrator may set it either
// way — but `Capabilities`' own class comment and migration 243's header BOTH
// already said the key was "written in exactly ONE place", and every other key
// with a page of its own has this refusal (`Site`, `MailRoutes`,
// `Maintenance`, `GivingRetention`). This is what makes the claim true.
//
// FOUR call sites, like the other four keys: changing a row and adding one in
// `settings/save.php`, deleting one and drawing the row in `settings/index.php`.
cap_check(
    'Capabilities::lockedInGeneralEditor() exists and compares ignoring capitals, as the database does',
    str_contains($capCode, 'public static function lockedInGeneralEditor(string $key): bool') === true
    && str_contains($capCode, 'return strcasecmp($key, self::LOCK_SETTING_KEY) === 0;') === true
);
cap_check(
    'and there is a message saying where the setting IS changed, rather than a bare refusal',
    str_contains($capCode, 'public const LOCKED_MESSAGE') === true
    && str_contains(Capabilities::LOCKED_MESSAGE, 'Roles page') === true
    && str_contains(Capabilities::LOCKED_MESSAGE, 'Nothing was changed.') === true
);
cap_check(
    'it answers yes for the lock key whatever the capitals, and no for anything else',
    Capabilities::lockedInGeneralEditor(Capabilities::LOCK_SETTING_KEY) === true
    && Capabilities::lockedInGeneralEditor('CAPABILITIES.LOCKED') === true
    && Capabilities::lockedInGeneralEditor('capabilities.lockedx') === false
    && Capabilities::lockedInGeneralEditor('') === false
);
// ⚠️ THE WHOLE CONDITION AND THE `exit()`, in each of the three handler
//    branches. Matching the call alone passes when `&& false` is added, and
//    matching the condition alone passes when the `exit()` is deleted (#668's
//    section-D lesson, which this repository has paid for repeatedly).
//
//    ⚠️ AND THE OBVIOUS WAY TO WRITE THE SECOND HALF DOES NOT WORK — PLANTED
//       AND MEASURED. The first version of these checks used the idiom this
//       file used elsewhere, `\{.{0,900}?exit\(\);\s*\}` with the `s`
//       modifier. With the `exit();` DELETED from one of these blocks the
//       check STAYED GREEN, because `.` crosses newlines and the search simply
//       ran past this block's closing brace and found the NEXT refusal's
//       `exit();` a few lines below. Every refusal on these pages looks alike —
//       `roles/save.php` holds TWENTY-TWO `exit(` calls — so there is always
//       another one within 900 characters.
//
//       So the block is CUT FIRST and the `exit()` looked for inside what was
//       cut. **That cut is now `cap_blockFrom()`, a brace walk that steps over
//       string literals** (#647: a walk that did not had one `{` in a log
//       message swallow the rest of a function, and the check passed on a
//       removed gate). It replaced an earlier cut at "the closing brace at N
//       spaces", which worked but trusted the read file's indentation and made
//       the caller supply N — and the two blocks in `roles/save.php` that
//       #742 was raised about both contain braces of their own, so the margin
//       was one level of indentation. One way of cutting a block in this file
//       now, not two.
$settingsDir  = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_apps'
    . DIRECTORY_SEPARATOR . 'settings' . DIRECTORY_SEPARATOR;
$settingsSave  = cap_codeOnly($settingsDir . 'save.php');
$settingsIndex = cap_codeOnly($settingsDir . 'index.php');
// 🧱 These three used `$capBlockFrom`, which cut at the closing brace at a
//    stated indentation. It worked, and it is gone: it trusted the read file's
//    indentation and made the CALLER supply the right number, so there were two
//    ways of cutting a block in this one file and only one of them was sound.
//    `cap_blockFrom()` walks the braces and steps over string literals — see
//    its own docblock for the two earlier ways and why each failed (#742).
foreach ([
    'changing a row' => [
        'if (\\Portal\\Core\\Capabilities::lockedInGeneralEditor($storedKey) === true) {',
        $settingsSave,
    ],
    'adding a row' => [
        'if (\\Portal\\Core\\Capabilities::lockedInGeneralEditor($settingKey) === true) {',
        $settingsSave,
    ],
    'deleting a row' => [
        'if ($lockedKey !== null && \\Portal\\Core\\Capabilities::lockedInGeneralEditor('
        . '(string) $lockedKey[\'settingKey\']) === true) {',
        $settingsIndex,
    ],
] as $what => [$condition, $where]) {
    $block = cap_blockFrom($where, $condition);
    cap_check(
        'the general settings editor refuses the lock key when ' . $what . ', whole condition',
        $block !== '',
        $block === '' ? 'the condition was not found exactly as written' : ''
    );
    cap_check(
        '...and that refusal really STOPS, rather than falling through to the write (' . $what . ')',
        $block !== '' && str_contains($block, 'exit();') === true,
        $block === '' ? 'no block to look in' : 'block is ' . strlen($block) . ' chars'
    );
}
// And the row itself must SAY where it is changed, or an administrator sees an
// Edit button that is refused when pressed. The delete button is withheld for
// the same reason.
cap_check(
    'settings/index.php tells the reader the lock key is ticked at Admin, Roles',
    str_contains($settingsIndex, '$capabilityLocksLocked = \\Portal\\Core\\Capabilities::lockedInGeneralEditor(') === true
    && str_contains($settingsIndex, 'Ticked at Admin, Roles') === true
    && str_contains($settingsIndex, 'Ticked by a global administrator, at Admin, Roles') === true
);
cap_check(
    'and withholds the Delete button for it, rather than offering one that is refused',
    str_contains($settingsIndex, '$capabilityLocksLocked === false') === true
);

// =============================================================================
cap_section('N — every offered capability, as declared (#727, #739)');
// =============================================================================
// ⚠️ THE TWO ANSWERS TO `globalAdminFallsOpen` ARE OPPOSITE, AND THAT IS WHY
//    THIS SECTION EXISTS. The owner's decision of 3 November 2026 was that an
//    organisation's global administrator has automatic access to everything
//    and can re-assign later — so the five declare TRUE. #724's erasure
//    review declares FALSE, deliberately. A single entry copied from the
//    wrong neighbour would be silent, so every one is asserted by name rather
//    than by a loop over whatever happens to be there.
//
// 🚨 AND THE SIX FROM #739 WERE NOT IN THIS LIST, WHICH IS HOW "NOBODY'S
//    ACCESS CHANGED" CAME TO BE PINNED BY NOTHING. Measured by an independent
//    check and reproduced: changing ONE word in the registry —
//    `giving.records` shipping with `['approver']` instead of
//    `['treasurer']` — left **all 60 scripts in `tools/audit-checks/` green**,
//    because `check_capability_registry.py` only requires the key to EXIST in
//    `Roles::STANDARD`, and section A of this file asserts that every
//    capability ships with **a** role, never **which**.
//
//    The harm is not hypothetical. On upgrade that one word would hand the
//    donor list — names and home addresses — to whoever decides expense
//    claims. `photos.staff => ['volunteer']` would open the staff photo tier
//    to every signed-in member, because the volunteer rung is a no-op (#756).
//
//    This list is the ONLY place that says which role each capability ships
//    pointing at. **Add a capability here in the same change that adds it to
//    the registry.** The name says "every offered capability" rather than a
//    number, because a list named after a number is the shape that goes stale
//    — it was `$shouldShipWith` while eleven existed.
$shouldShipWith = [
    Capabilities::ASSETS_MANAGE      => 'asset_manager',
    Capabilities::CARE_REGISTER      => 'care_team',
    Capabilities::KIDS_CHECKIN       => 'kids_team',
    Capabilities::EXPENSES_TREASURY  => 'treasurer',
    Capabilities::LIVESTREAM_CONSOLE => 'stream_moderator',
    // #739's six, each pointing at the role its hard-coded gate really used.
    Capabilities::EXPENSES_APPROVE   => 'approver',
    Capabilities::GIVING_RECORDS     => 'treasurer',
    Capabilities::GROUPS_MANAGE      => 'groups_coordinator',
    Capabilities::PRAYER_MODERATE    => 'prayer_team',
    Capabilities::VENUES_MANAGE      => 'venue_manager',
    Capabilities::PHOTOS_STAFF       => 'staff',
    // #756 / D-047 — see the note beside `$capHolders`.
    Capabilities::PHOTOS_VOLUNTEER   => 'volunteer',
];
foreach ($shouldShipWith as $capabilityKey => $shippedRole) {
    cap_check(
        $capabilityKey . ' ships with the role its hard-coded gate used (' . $shippedRole . ')',
        Capabilities::standardRoleKeys($capabilityKey) === [$shippedRole],
        implode(',', Capabilities::standardRoleKeys($capabilityKey))
    );
    cap_check(
        $capabilityKey . ' falls open for a global administrator (the owner, 3 November 2026)',
        Capabilities::fallsOpenForGlobalAdmin($capabilityKey) === true
    );
    cap_check(
        $capabilityKey . ' says what an empty set costs, in real words',
        strlen(Capabilities::emptyMeans($capabilityKey)) > 40
    );
    // ⚠️ THE WORDING FAULT THIS CATCHES IS ONE I MADE. The first draft of all
    //    five said "Only a global administrator will be able to…", which is
    //    wrong twice: every one of these gates also lets an ORDINARY
    //    administrator of the organisation in through `App::isAdmin()`, so an
    //    empty set shuts out the role and nothing else. Nothing can judge how
    //    a sentence reads, so what is pinned is that it does not make the
    //    claim that was false.
    cap_check(
        $capabilityKey . ' does NOT claim only a global administrator would be left',
        str_contains(Capabilities::emptyMeans($capabilityKey), 'Only a global administrator') === false
        && str_contains(Capabilities::emptyMeans($capabilityKey), 'only a global administrator') === false,
        Capabilities::emptyMeans($capabilityKey)
    );
    cap_check(
        $capabilityKey . ' says administrators are unaffected, which is the fact',
        str_contains(Capabilities::emptyMeans($capabilityKey), 'dministrator') === true
    );
}
cap_check(
    'and #724\'s is still the one that does NOT fall open',
    Capabilities::fallsOpenForGlobalAdmin($CAP) === false
);

// 📷 THE PHOTO LADDER'S FALL-THROUGH (#756, D-047) — and this is here because
//    NOTHING ELSE WOULD NOTICE IT GOING BACK.
//
//    `Photos::userTierRank()` is the only converted gate whose BEHAVIOUR
//    changed. Section O proves `photos.volunteer` answers exactly what
//    `App::hasRole('volunteer')` answered, which is true and is NOT the same as
//    "nothing changed for anybody": before #756 the line AFTER that gate
//    returned 1 as well, for every signed-in member, so the gate decided
//    nothing. Reading section O as proof that this package changed nothing
//    would be the "a check that pins a reassurance" shape.
//
//    ⚠️ NOTHING DRIVES THE LADDER ITSELF. No test anywhere called
//       `Photos::userTierRank()` before this one — measured with
//       `grep -rln userTierRank tools/`, which answered only
//       `check_no_new_role_gates.py`, and that reads it as TEXT. That is how a
//       no-op rung survived from `8625b9e` to 4 November 2026 with a comment
//       beside it claiming the opposite. It cannot be driven cheaply from here
//       either: the method asks `Auth::check()`, `App::isAdmin()` and
//       `App::can()`, all of which read the session.
//
//    So what is pinned is the fall-through's OWN VALUE, with comments stripped
//    so the explanation above it cannot satisfy the check. A regression to
//    `return 1;` is the exact fault #756 fixed, and it would otherwise be
//    invisible to all 297 checks in this file.
$photosCode = cap_codeOnly(dirname(__DIR__) . '/web/_core/Photos.php');
cap_check(
    '#756 the photo ladder still asks the capability for the volunteer rung',
    str_contains($photosCode, 'App::can(Capabilities::PHOTOS_VOLUNTEER) === true') === true
);
// 📏 WHITESPACE-TOLERANT ON PURPOSE. `cap_codeOnly()` strips a comment's TEXT
//    but leaves the indentation that preceded it, so the stripped ladder holds
//    a run of blank-looking lines between the rung and the fall-through — a
//    literal `str_contains` was tried first and failed on exactly that, which is
//    a check crying wolf over its own formatting. What matters is the ORDER of
//    the two returns, not the spacing between them.
cap_check(
    '#756 ... and a signed-in member with no tier gets 0, not 1 — the no-op this fixed',
    preg_match(
        '/PHOTOS_VOLUNTEER\)\s*===\s*true\s*\)\s*\{\s*return\s+1;\s*\}\s*return\s+0;/',
        $photosCode
    ) === 1,
    'the volunteer rung is not immediately followed by "return 0;" — if it returns 1 again, '
        . 'the rung decides nothing and #756 is undone'
);
cap_check(
    '#756 ... and the old hard-coded role gate has not come back',
    str_contains($photosCode, "App::hasRole('volunteer')") === false
);

// =============================================================================
cap_section('O — AN ORGANISATION THAT NEVER OPENS THE PAGE SEES NO DIFFERENCE');
// =============================================================================
// ⚠️ THIS IS THE ACCEPTANCE TEST FOR THE CONVERSION, and it is a MEASUREMENT
//    rather than an argument. Each converted gate used to read
//    `App::hasRole('<role>')`, which is exactly
//        $isRootAdmin === true  ||  Roles::has($db, $uid, $siteId, '<role>')
//    (see App::hasRole(): a global administrator passes with no role row at
//    all, by design since #516). That expression is rebuilt here, from
//    `Roles::has()` — the real method the old gate used — and compared with
//    what the capability answers, for every one of the five, over five people
//    who each hold exactly one thing.
//
//    Planted, `globalAdminFallsOpen => false` on any of the five turns the
//    global administrator's row red; a different `standard` role turns the
//    holder's row red. Both were planted and both went red.
$oldHasRole = static function (int $userId, int $siteId, string $roleKey, bool $isRootAdmin) use ($mysqli): bool {
    if ($isRootAdmin === true) {
        return true;
    }

    return Roles::has($mysqli, $userId, $siteId, $roleKey);
};
foreach ($shouldShipWith as $capabilityKey => $shippedRole) {
    $people = [
        'the holder of the role it ships with' => [$capHolders[$capabilityKey], false],
        'somebody with no role at all'         => [$nobodyId, false],
        'somebody whose membership has ended'  => [$assetLapsedId, false],
        'a holder in the OTHER organisation'   => [$assetOtherId, false],
        'a global administrator with no role'  => [$rootId, true],
    ];
    foreach ($people as $who => $pair) {
        [$personId, $isRoot] = $pair;
        $now    = Capabilities::allows($mysqli, $personId, $siteA, $capabilityKey, $isRoot);
        $before = $oldHasRole($personId, $siteA, $shippedRole, $isRoot);
        cap_check(
            $capabilityKey . ': ' . $who . ' is answered exactly as App::hasRole() answered',
            $now === $before,
            'capability says ' . var_export($now, true) . ', the old gate said ' . var_export($before, true)
        );
    }
}
// ⚠️ AND #724'S IS THE ONE WHERE THE TWO DELIBERATELY DISAGREE. Without this
//    the section above would read as "the registry always matches the old
//    gate", which is the opposite of why the registry was built. If this ever
//    passes by agreeing, somebody has set `globalAdminFallsOpen => true` on
//    the erasure review and #724 is undone.
cap_check(
    '#724: a global administrator with no role is REFUSED, where App::hasRole() let them in',
    Capabilities::allows($mysqli, $rootId, $siteA, $CAP, true) === false
    && $oldHasRole($rootId, $siteA, 'data_controller', true) === true
);

// =============================================================================
cap_section('P — re-pointing one of the five, and the global-administrator path');
// =============================================================================
// ⚠️ THIS PATH WAS REACHED BY NOTHING UNTIL NOW, and the previous build's own
//    report said so: no OFFERED capability declared
//    `globalAdminFallsOpen => true`, so the branch through `allows()` for a
//    global administrator could not be taken by any real capability. All five
//    of these declare it, so the branch is exercised here with a real
//    capability, a real database and a real empty set.
// ⚠️ A ROLE AND A PERSON OF THEIR OWN, MADE HERE AND NOT IN THE WORLD ABOVE.
//    Section I DELETES `privacy_officer` to prove the storage's own link takes
//    a deleted role out of every set, so reusing it here failed with
//    'bad_role' — and the three checks that failed were the three that
//    mattered. A fixture one earlier section destroys is not a fixture.
$ownRole2Key = 'finance_officer';
$stmt = $mysqli->prepare(
    "INSERT INTO `tblRoles` (`siteID`, `roleKey`, `roleName`, `description`, `isStandard`) "
    . "VALUES (?, ?, 'Finance Officer', 'Our own role, made for section P', 0)"
);
$stmt->bind_param('is', $siteA, $ownRole2Key);
$stmt->execute();
$ownRole2Id = (int) $stmt->insert_id;
$stmt->close();
$foId = $capNewUser('Cap FO', false);
$capMember($foId, $siteA, true);
$capGrant($foId, $ownRole2Id, $siteA);

$assetHolderId = $capHolders[Capabilities::ASSETS_MANAGE];
cap_check(
    'before anything is chosen, the asset-register role holder is in',
    Capabilities::allows($mysqli, $assetHolderId, $siteA, Capabilities::ASSETS_MANAGE, false) === true
);
cap_check(
    'and the organisation\'s own Finance Officer is not',
    Capabilities::allows($mysqli, $foId, $siteA, Capabilities::ASSETS_MANAGE, false) === false
);
// The outcome is kept in a variable and not asked twice: calling `save()`
// again to print its answer would really save again, and the second answer
// would be 'unchanged' — a detail line that contradicts the check beside it.
$repointOutcome = Capabilities::save($mysqli, $siteA, Capabilities::ASSETS_MANAGE, [$ownRole2Key], $dcId, []);
cap_check(
    'pointing it at the organisation\'s OWN role is accepted',
    $repointOutcome === 'ok',
    $repointOutcome
);
cap_check(
    'the Finance Officer is now in',
    Capabilities::allows($mysqli, $foId, $siteA, Capabilities::ASSETS_MANAGE, false) === true
);
cap_check(
    'and the asset_manager role no longer opens it — a re-point REPLACES the set',
    Capabilities::allows($mysqli, $assetHolderId, $siteA, Capabilities::ASSETS_MANAGE, false) === false
);
cap_check(
    'a global administrator holding NEITHER role is still in, because this one falls open',
    Capabilities::allows($mysqli, $rootId, $siteA, Capabilities::ASSETS_MANAGE, true) === true
);
// The empty set, on a capability that falls open: nobody by role, and a global
// administrator still in. That is the sentence the page shows, proved.
cap_check(
    'the empty set is accepted',
    Capabilities::save($mysqli, $siteA, Capabilities::ASSETS_MANAGE, [], $dcId, []) === 'ok'
);
cap_check(
    'with nobody chosen, no role opens it',
    Capabilities::allows($mysqli, $poId, $siteA, Capabilities::ASSETS_MANAGE, false) === false
    && Capabilities::allows($mysqli, $assetHolderId, $siteA, Capabilities::ASSETS_MANAGE, false) === false
);
cap_check(
    'and a global administrator is STILL in, which is what this door\'s own sentence says',
    Capabilities::allows($mysqli, $rootId, $siteA, Capabilities::ASSETS_MANAGE, true) === true
);
cap_check(
    'but an empty set on #724\'s door really does mean nobody, global administrator included',
    Capabilities::save($mysqli, $siteA, $CAP, [], $dcId, []) === 'ok'
    && Capabilities::allows($mysqli, $rootId, $siteA, $CAP, true) === false
    && Capabilities::allows($mysqli, $dcId, $siteA, $CAP, false) === false
);
cap_check(
    'forgetting the choice puts the asset register back to the role it ships with',
    Capabilities::forget($mysqli, $siteA, Capabilities::ASSETS_MANAGE, $dcId, []) === 'ok'
    && Capabilities::chosenRoleKeys($mysqli, $siteA, Capabilities::ASSETS_MANAGE) === null
    && Capabilities::allows($mysqli, $assetHolderId, $siteA, Capabilities::ASSETS_MANAGE, false) === true
);
Capabilities::forget($mysqli, $siteA, $CAP, $dcId, []);
cap_check(
    'and #724\'s door is back too, so the rest of this run starts from "nothing chosen"',
    Capabilities::chosenRoleKeys($mysqli, $siteA, $CAP) === null
);
// One organisation's re-point is its own, for one of the five as well as for
// #724 (section H proves it for #724).
cap_check(
    'organisation B is untouched by organisation A\'s re-point',
    Capabilities::save($mysqli, $siteA, Capabilities::ASSETS_MANAGE, [$ownRole2Key], $dcId, []) === 'ok'
    && Capabilities::roleKeysInForce($mysqli, $siteB, Capabilities::ASSETS_MANAGE) === ['asset_manager']
    && Capabilities::allows($mysqli, $assetOtherId, $siteB, Capabilities::ASSETS_MANAGE, false) === true
);
Capabilities::forget($mysqli, $siteA, Capabilities::ASSETS_MANAGE, $dcId, []);

// =============================================================================
cap_section('Q — an entry that leaves a field out cannot be guessed at');
// =============================================================================
// ⚠️ ASKED OF AN ENTRY THIS FILE COULD NOT LEGALLY CONTAIN. An incomplete
//    entry fails the hard gate, so it can never be committed — and a guard
//    whose fault cannot be built is a guard nothing exercises. That is why
//    `whyEntryIncomplete()` takes the ENTRY rather than the key.
$completeEntry = [
    'label' => 'x',
    'opens' => 'x',
    'standard' => ['asset_manager'],
    'globalAdminFallsOpen' => true,
    'organisationMayRepoint' => true,
    'emptyMeans' => 'x',
    'askedAt' => ['web/_apps/assets/save.php'],
];
cap_check('a complete entry is accepted', Capabilities::whyEntryIncomplete($completeEntry) === '');
foreach (array_keys($completeEntry) as $field) {
    $short = $completeEntry;
    unset($short[$field]);
    cap_check(
        'an entry missing ' . $field . ' is refused, naming the field',
        Capabilities::whyEntryIncomplete($short) === 'it does not declare ' . $field,
        Capabilities::whyEntryIncomplete($short)
    );
}
// A field present but not a true/false is the other half: `'true'` as TEXT is
// truthy in PHP and would read as "falls open" for ever.
foreach (['globalAdminFallsOpen', 'organisationMayRepoint'] as $flag) {
    $wrong        = $completeEntry;
    $wrong[$flag] = 'true';
    cap_check(
        $flag . ' written as text rather than true/false is refused',
        Capabilities::whyEntryIncomplete($wrong) === $flag . ' is not true or false',
        Capabilities::whyEntryIncomplete($wrong)
    );
}
// And every entry the portal really ships is complete, which is what makes
// `definition()` safe to call from a gate.
$allComplete = true;
foreach (Capabilities::CAPABILITIES as $entry) {
    if (Capabilities::whyEntryIncomplete($entry) !== '') {
        $allComplete = false;
    }
}
cap_check('every entry this portal offers is complete', $allComplete === true);

// =============================================================================
cap_section('R — what is remembered for one request, and what is NOT');
// =============================================================================
// ⚠️ A CACHE IS A NEW WAY TO BE WRONG, so each half is proved separately.
//    What is remembered is the MAPPING ("which roles open this door here").
//    What is never remembered is a PERSON's answer, so granting somebody a
//    role takes effect on the very next call.
Capabilities::forgetMemo();
cap_check(
    'granting a role takes effect at once — a person\'s answer is never remembered',
    Capabilities::allows($mysqli, $nobodyId, $siteA, Capabilities::CARE_REGISTER, false) === false
);
$careRoleId = $capRoleId($siteA, 'care_team');
$capGrant($nobodyId, $careRoleId, $siteA);
cap_check(
    '...and the very next call says yes, with nothing told to forget anything',
    Capabilities::allows($mysqli, $nobodyId, $siteA, Capabilities::CARE_REGISTER, false) === true
);
$mysqli->query('DELETE FROM `tblUserRoles` WHERE `userID` = ' . (int) $nobodyId
    . ' AND `roleID` = ' . (int) $careRoleId);
cap_check(
    '...and taking it away does too',
    Capabilities::allows($mysqli, $nobodyId, $siteA, Capabilities::CARE_REGISTER, false) === false
);
// `save()` must clear what it makes untrue. Without that call the roles page's
// own success message, which asks `roleKeysInForce()` straight afterwards,
// would report the set as it was BEFORE the save.
cap_check(
    'the shipped set is what is read first',
    Capabilities::roleKeysInForce($mysqli, $siteA, Capabilities::KIDS_CHECKIN) === ['kids_team']
);
cap_check(
    'a save is seen immediately afterwards, in the same request',
    Capabilities::save($mysqli, $siteA, Capabilities::KIDS_CHECKIN, [$ownRole2Key], $dcId, []) === 'ok'
    && Capabilities::roleKeysInForce($mysqli, $siteA, Capabilities::KIDS_CHECKIN) === [$ownRole2Key],
    implode(',', Capabilities::roleKeysInForce($mysqli, $siteA, Capabilities::KIDS_CHECKIN))
);
cap_check(
    'and so is forgetting one',
    Capabilities::forget($mysqli, $siteA, Capabilities::KIDS_CHECKIN, $dcId, []) === 'ok'
    && Capabilities::roleKeysInForce($mysqli, $siteA, Capabilities::KIDS_CHECKIN) === ['kids_team'],
    implode(',', Capabilities::roleKeysInForce($mysqli, $siteA, Capabilities::KIDS_CHECKIN))
);
// The documented limit, stated as a test rather than as a promise: a mapping
// changed BEHIND the class's back — by SQL here, by a deleted role on the
// roles page — is not seen until somebody says so. That is why
// `admin/roles/save.php` calls `forgetMemo()` after it deletes a role.
Capabilities::save($mysqli, $siteA, Capabilities::CARE_REGISTER, [$ownRole2Key], $dcId, []);
// ⚠️ THE READ BELOW IS LOAD-BEARING AND THE FIRST VERSION OF THIS CHECK LEFT
//    IT OUT, so the check failed: `save()` had just cleared the memo, nothing
//    had put anything back into it, and the next read went to the database and
//    correctly saw the deleted row. There is nothing to be stale until
//    something has been remembered.
cap_check(
    'the saved set is what is remembered',
    Capabilities::roleKeysInForce($mysqli, $siteA, Capabilities::CARE_REGISTER) === [$ownRole2Key]
);
$mysqli->query('DELETE FROM `' . Capabilities::MAP_TABLE . '` WHERE `siteID` = ' . (int) $siteA
    . " AND `capabilityKey` = 'care.register'");
cap_check(
    'a mapping changed by raw SQL is NOT seen for the rest of the request (the stated limit)',
    Capabilities::roleKeysInForce($mysqli, $siteA, Capabilities::CARE_REGISTER) === [$ownRole2Key],
    implode(',', Capabilities::roleKeysInForce($mysqli, $siteA, Capabilities::CARE_REGISTER))
);
Capabilities::forgetMemo();
cap_check(
    '...and forgetMemo() is what makes it visible, which is the roles page\'s remedy',
    Capabilities::roleKeysInForce($mysqli, $siteA, Capabilities::CARE_REGISTER) === ['care_team']
);
// An unknown capability must still THROW rather than being remembered as an
// empty answer: the memo is reached only after `standardRoleKeys()`.
$memoThrew = false;
try {
    Capabilities::roleKeysInForce($mysqli, $siteA, 'no.such.capability');
} catch (\InvalidArgumentException $e) {
    $memoThrew = true;
}
cap_check('a mistyped capability still throws, memo or no memo', $memoThrew === true);
// The roles page must say so after deleting a role, because the database's own
// link removes that role from every set and nothing tells the class.
$rolesSaveCode = cap_codeOnly(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_apps'
    . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'roles' . DIRECTORY_SEPARATOR . 'save.php');
cap_check(
    'admin/roles/save.php forgets what it remembered after it DELETES a role',
    preg_match(
        '/DELETE FROM tblRoles WHERE roleID = \? AND siteID = \? AND isStandard = 0.{0,400}?'
        . 'Capabilities::forgetMemo\(\);/s',
        $rolesSaveCode
    ) === 1
);
// And both writers must do it themselves, which is what the three behaviour
// checks above measure. Pinned as text as well, because a reader of the class
// should not have to run a database to see that it is deliberate.
cap_check(
    'Capabilities::save() and forget() each clear it themselves',
    substr_count($capCode, 'self::forgetMemo();') === 2,
    (string) substr_count($capCode, 'self::forgetMemo();')
);

// -----------------------------------------------------------------------------
// ⚠️ WHAT THE MEMO IS KEYED BY — WHICH NOTHING PINNED UNTIL 3 NOVEMBER 2026,
//    AND THE REASON IS WORTH READING BEFORE ADDING A CHECK ANYWHERE ELSE.
//
//    `roleKeysInForce()` remembers its answer under
//    `(string) $siteId . '|' . $capability`. An independent check changed that
//    to `$capability` alone — dropping the organisation — and this self-test
//    stayed 199/199 GREEN while organisation B, which had chosen nothing, was
//    served organisation A's chosen set.
//
//    This section already HAD two organisations and already asked about both.
//    It could not see the fault because every pair of questions it asked
//    happened to be in an order where the scoped and unscoped keys give THE
//    SAME ANSWER. That is the repository's named shape one step along: a
//    fixture that cannot tell two things apart is not a fixture. The remedy
//    is not another organisation — it is asking in an order where the two
//    keys MUST disagree.
//
//    BOTH DIRECTIONS ARE CHECKED, and the second is the dangerous one:
//      1. A chosen set bleeding OUTWARDS. Ask A (which chose a role of its
//         own) and then B (which chose nothing): B must get the SHIPPED set.
//         Unscoped, B gets A's private role, which no member of B can hold —
//         so B's door shuts for everybody but its administrators, silently.
//      2. A shipped set bleeding INWARDS. Ask B first and then A: A must get
//         its own choice. Unscoped, A's deliberate restriction is REPLACED by
//         the shipped set, so a role the organisation deliberately shut out
//         opens the door.
//
//    Nothing in production reaches this today — `App::can()` always passes
//    `Site::id()`, and both callers that pass an organisation explicitly pass
//    the same one. But `_apps/cron/asset-reminders.php` already loops every
//    organisation with `Site::forceContext()`, inside the very app holding 36
//    of the converted gates, so the first scheduled job to ask a capability
//    makes it live. A guard put in before that is cheap; found afterwards it
//    is a wrong answer about who may open a register.
// ⚠️ `$ownRole2Key` AND NOT `$ownRoleKey`, for the reason section P already
//    records: section I DELETES `privacy_officer` to prove the storage's own
//    link drops a deleted role, so a save naming it answers 'bad_role'. The
//    first version of this block used it and these checks went red — which is
//    the file's own warning earning its keep. What matters for the fault
//    being caught is only that the role belongs to ONE organisation, so the
//    other cannot legitimately be told to use it.
Capabilities::forgetMemo();
cap_check(
    'memo, direction 1 — organisation A\'s own choice is stored and read back',
    Capabilities::save($mysqli, $siteA, Capabilities::CARE_REGISTER, [$ownRole2Key], $dcId, []) === 'ok'
    && Capabilities::roleKeysInForce($mysqli, $siteA, Capabilities::CARE_REGISTER) === [$ownRole2Key],
    implode(',', Capabilities::roleKeysInForce($mysqli, $siteA, Capabilities::CARE_REGISTER))
);
// 🔑 THE CHECK THE WHOLE BLOCK EXISTS FOR. Asked straight after A, with A's
//    answer sitting in the memo, and B has chosen nothing at all.
cap_check(
    'memo is keyed by ORGANISATION — B, which chose nothing, still gets the SHIPPED set '
    . 'immediately after A\'s own choice was remembered',
    Capabilities::roleKeysInForce($mysqli, $siteB, Capabilities::CARE_REGISTER) === ['care_team'],
    implode(',', Capabilities::roleKeysInForce($mysqli, $siteB, Capabilities::CARE_REGISTER))
);
// Direction 2, with the memo cleared so the ORDER is the other way round.
Capabilities::forgetMemo();
cap_check(
    'memo, direction 2 — B asked FIRST gets the shipped set',
    Capabilities::roleKeysInForce($mysqli, $siteB, Capabilities::CARE_REGISTER) === ['care_team'],
    implode(',', Capabilities::roleKeysInForce($mysqli, $siteB, Capabilities::CARE_REGISTER))
);
cap_check(
    '...and A\'s deliberate restriction is NOT replaced by what B was told',
    Capabilities::roleKeysInForce($mysqli, $siteA, Capabilities::CARE_REGISTER) === [$ownRole2Key],
    implode(',', Capabilities::roleKeysInForce($mysqli, $siteA, Capabilities::CARE_REGISTER))
);
// And the same for the EMPTY set, which is the state the independent check
// reproduced: an organisation that has deliberately chosen nobody must not
// close the same door for an organisation that has chosen nothing at all.
Capabilities::forgetMemo();
cap_check(
    'memo, the empty set — A chooses nobody',
    Capabilities::save($mysqli, $siteA, Capabilities::CARE_REGISTER, [], $dcId, []) === 'ok'
    && Capabilities::roleKeysInForce($mysqli, $siteA, Capabilities::CARE_REGISTER) === [],
    implode(',', Capabilities::roleKeysInForce($mysqli, $siteA, Capabilities::CARE_REGISTER))
);
cap_check(
    '...and B\'s door is untouched by it, asked immediately afterwards',
    Capabilities::roleKeysInForce($mysqli, $siteB, Capabilities::CARE_REGISTER) === ['care_team'],
    implode(',', Capabilities::roleKeysInForce($mysqli, $siteB, Capabilities::CARE_REGISTER))
);
// 🧹 Put the world back, so nothing after this section inherits A's choice.
Capabilities::forget($mysqli, $siteA, Capabilities::CARE_REGISTER, $dcId, []);
Capabilities::forgetMemo();

// =============================================================================
echo "\n";
echo 'Passed: ' . (string) $GLOBALS['cap_pass'] . '   Failed: ' . (string) $GLOBALS['cap_fail'] . "\n";
exit($GLOBALS['cap_fail'] === 0 ? 0 : 1);
