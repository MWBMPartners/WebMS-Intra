<?php
// Path: tools/account-guard-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Account Change Guard self-test 🛡️ (#518)
 * -----------------------------------------------------------------------------
 * Standalone, dependency-free (no database, no bootstrap, no network)
 * regression guard for `Portal\Core\AccountGuard` — the ONE place that
 * decides whether a site administrator's attempt to change an account is
 * allowed to reach it. See the class docblock in
 * `web/_core/AccountGuard.php` for the full "why" behind every rule this
 * file checks.
 *
 * This exercises the REAL class, requiring `AccountGuard.php` directly
 * rather than reimplementing its rule. It calls only three of its
 * methods, deliberately:
 *   - decide() — the pure decision table. Takes no database, no session;
 *     every row of the table can be driven from plain PHP arrays.
 *   - message() — the plain-English wording. Also pure.
 *   - memberScopeSql() — but ONLY with an INVALID column name. That
 *     method validates its column argument FIRST, before touching
 *     anything else, and throws straight away — so calling it with a bad
 *     column is still dependency-free. Calling it with a VALID column
 *     would go on to call actorIsGlobal() and isSingleOrganisation(),
 *     which reach into ApiAuth, App and a real database connection —
 *     this script deliberately never does that, so it stays usable with
 *     nothing running at all.
 *
 * What this CANNOT prove: that the real pages call AccountGuard
 * correctly, that facts() reads the right columns, or that logRefusal()
 * writes the right rows — those need a real database, and are proved
 * instead by the #518 build's real-database test plan
 * (`.claude-work/resume/p518--plan.md`, section 10). This file only
 * proves the RULE — decide()'s table — is right, in isolation, every
 * time it is run, without needing any of that set up first.
 *
 * Usage:  php tools/account-guard-selftest.php
 * Exit:   0 on success (all assertions pass), 1 on any failure.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026 MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/518
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

if (defined('PORTAL_CORE') === false) {
    define('PORTAL_CORE', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_core');
}

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'AccountGuard.php';

use Portal\Core\AccountGuard;

$failures = 0;

/**
 * Compare a decide() outcome against the expected verdict/reason and print
 * PASS/FAIL. Kept as its own helper because almost every assertion below
 * is exactly this shape.
 */
function assertDecision(string $label, array $actual, string $expectedVerdict, string $expectedReason): void
{
    global $failures;
    $ok = ($actual['verdict'] === $expectedVerdict) && ($actual['reason'] === $expectedReason);
    $got = $actual['verdict'] . '/' . $actual['reason'];
    $want = $expectedVerdict . '/' . $expectedReason;
    echo ($ok === true ? 'PASS' : 'FAIL') . ' — ' . $label . ' (got ' . $got . ', want ' . $want . ')' . "\n";
    if ($ok === false) {
        $failures++;
    }
}

function assertTrue(string $label, bool $cond): void
{
    global $failures;
    echo ($cond === true ? 'PASS' : 'FAIL') . ' — ' . $label . "\n";
    if ($cond === false) {
        $failures++;
    }
}

function expectThrows(string $label, callable $fn, string $expectedClass = \InvalidArgumentException::class): void
{
    global $failures;
    try {
        $fn();
        echo 'FAIL — ' . $label . ' (no exception thrown)' . "\n";
        $failures++;
    } catch (\Throwable $e) {
        if (get_class($e) === $expectedClass || $e instanceof $expectedClass) {
            echo 'PASS — ' . $label . ' -> ' . get_class($e) . ': ' . $e->getMessage() . "\n";
        } else {
            echo 'FAIL — ' . $label . ' (wrong exception type: ' . get_class($e) . ': ' . $e->getMessage() . ')' . "\n";
            $failures++;
        }
    }
}

// -----------------------------------------------------------------------------
// 🧪 Reusable facts() shapes — plain arrays, exactly what facts() itself
//    would hand back for a real row (see AccountGuard::facts()'s SELECT).
// -----------------------------------------------------------------------------
$ordinary       = ['isAdmin' => 0, 'isRootAdmin' => 0, 'thisOrgActive' => 1, 'otherOrgRows' => 0];
$globalAdminRow = ['isAdmin' => 1, 'isRootAdmin' => 1, 'thisOrgActive' => 1, 'otherOrgRows' => 0];
$portalAdminRow = ['isAdmin' => 1, 'isRootAdmin' => 0, 'thisOrgActive' => 1, 'otherOrgRows' => 0];
$otherOrgActive = ['isAdmin' => 0, 'isRootAdmin' => 0, 'thisOrgActive' => 1, 'otherOrgRows' => 2];
$endedHere      = ['isAdmin' => 0, 'isRootAdmin' => 0, 'thisOrgActive' => 0, 'otherOrgRows' => 0];
$noRowHere      = ['isAdmin' => 0, 'isRootAdmin' => 0, 'thisOrgActive' => null, 'otherOrgRows' => 0];

echo "=== Row 0 — an unknown reach always throws, first, regardless of anything else ===\n";
expectThrows('unknown reach, global actor, no facts', static function () {
    AccountGuard::decide(true, null, 'bogus', true);
});
expectThrows('unknown reach, ordinary actor, real facts', static function () use ($ordinary) {
    AccountGuard::decide(false, $ordinary, 'bogus', false);
});

echo "\n=== Row 1 — a missing account is NOT_FOUND for EVERY actor and EVERY reach ===\n";
foreach ([true, false] as $actorGlobal) {
    foreach ([AccountGuard::REACH_VIEW, AccountGuard::REACH_ACCOUNT, AccountGuard::REACH_THIS_ORG, AccountGuard::REACH_REHIRE, AccountGuard::REACH_PORTAL] as $reach) {
        assertDecision(
            'missing account, actorGlobal=' . ($actorGlobal ? 'true' : 'false') . ', reach=' . $reach,
            AccountGuard::decide($actorGlobal, null, $reach, false),
            AccountGuard::NOT_FOUND,
            'missing'
        );
    }
}

echo "\n=== Row 2 — a global administrator may change anything that exists ===\n";
assertDecision(
    'global actor, ordinary account, REACH_ACCOUNT',
    AccountGuard::decide(true, $ordinary, AccountGuard::REACH_ACCOUNT, false),
    AccountGuard::ALLOW,
    ''
);
assertDecision(
    'global actor, a global admin target, REACH_PORTAL',
    AccountGuard::decide(true, $globalAdminRow, AccountGuard::REACH_PORTAL, false),
    AccountGuard::ALLOW,
    ''
);
assertDecision(
    'global actor, an account also in another organisation, REACH_ACCOUNT',
    AccountGuard::decide(true, $otherOrgActive, AccountGuard::REACH_ACCOUNT, false),
    AccountGuard::ALLOW,
    ''
);

echo "\n=== Row 3 — multi-organisation: not belonging here is NOT_FOUND, for every reach it can be tested against ===\n";
foreach ([AccountGuard::REACH_VIEW, AccountGuard::REACH_ACCOUNT, AccountGuard::REACH_THIS_ORG, AccountGuard::REACH_PORTAL] as $reach) {
    assertDecision(
        'ordinary actor, no membership row here, multi-org, reach=' . $reach,
        AccountGuard::decide(false, $noRowHere, $reach, false),
        AccountGuard::NOT_FOUND,
        'not_member'
    );
}

echo "\n=== Assertion 2 — row 3 never fires in single-organisation mode ===\n";
assertDecision(
    'single-org, no membership row here, REACH_ACCOUNT, otherOrgRows=0 -> ALLOW',
    AccountGuard::decide(false, $noRowHere, AccountGuard::REACH_ACCOUNT, true),
    AccountGuard::ALLOW,
    ''
);
$noRowButElsewhere = ['isAdmin' => 0, 'isRootAdmin' => 0, 'thisOrgActive' => null, 'otherOrgRows' => 2];
assertDecision(
    'single-org, no membership row here, REACH_ACCOUNT, otherOrgRows=2 -> GLOBAL_ONLY/other_org',
    AccountGuard::decide(false, $noRowButElsewhere, AccountGuard::REACH_ACCOUNT, true),
    AccountGuard::GLOBAL_ONLY,
    'other_org'
);

echo "\n=== Row 4 — merely viewing is allowed once rows 1-3 pass ===\n";
assertDecision(
    'ordinary actor, belongs here, REACH_VIEW, multi-org',
    AccountGuard::decide(false, $ordinary, AccountGuard::REACH_VIEW, false),
    AccountGuard::ALLOW,
    ''
);
assertDecision(
    'ordinary actor, belongs here, REACH_VIEW, single-org',
    AccountGuard::decide(false, $ordinary, AccountGuard::REACH_VIEW, true),
    AccountGuard::ALLOW,
    ''
);

echo "\n=== Row 5 — portal-wide isAdmin is always global-administrator-only ===\n";
assertDecision(
    'ordinary actor, belongs here, REACH_PORTAL, multi-org',
    AccountGuard::decide(false, $ordinary, AccountGuard::REACH_PORTAL, false),
    AccountGuard::GLOBAL_ONLY,
    'portal_grant'
);
assertDecision(
    'ordinary actor, belongs here, REACH_PORTAL, single-org',
    AccountGuard::decide(false, $ordinary, AccountGuard::REACH_PORTAL, true),
    AccountGuard::GLOBAL_ONLY,
    'portal_grant'
);

echo "\n=== Rows 6/7 — protected targets (global admin, portal admin) ===\n";
assertDecision(
    'ordinary actor, target is a global admin, REACH_ACCOUNT',
    AccountGuard::decide(false, $globalAdminRow, AccountGuard::REACH_ACCOUNT, false),
    AccountGuard::GLOBAL_ONLY,
    'global_admin'
);
assertDecision(
    'ordinary actor, target holds portal-wide isAdmin, REACH_ACCOUNT',
    AccountGuard::decide(false, $portalAdminRow, AccountGuard::REACH_ACCOUNT, false),
    AccountGuard::GLOBAL_ONLY,
    'portal_admin'
);

echo "\n=== Assertion 7 — REACH_THIS_ORG still protects global/portal admin targets, but allows an other-org-only account ===\n";
assertDecision(
    'ordinary actor, target is a global admin, REACH_THIS_ORG -> still refused',
    AccountGuard::decide(false, $globalAdminRow, AccountGuard::REACH_THIS_ORG, false),
    AccountGuard::GLOBAL_ONLY,
    'global_admin'
);
assertDecision(
    'ordinary actor, target holds portal-wide isAdmin, REACH_THIS_ORG -> still refused',
    AccountGuard::decide(false, $portalAdminRow, AccountGuard::REACH_THIS_ORG, false),
    AccountGuard::GLOBAL_ONLY,
    'portal_admin'
);
assertDecision(
    'ordinary actor, unprotected account also in another org, REACH_THIS_ORG -> ALLOW',
    AccountGuard::decide(false, $otherOrgActive, AccountGuard::REACH_THIS_ORG, false),
    AccountGuard::ALLOW,
    ''
);

echo "\n=== Row 9 — an account also belonging elsewhere needs a global administrator for anything wider than REACH_THIS_ORG ===\n";
assertDecision(
    'ordinary actor, unprotected account also in another org, REACH_ACCOUNT',
    AccountGuard::decide(false, $otherOrgActive, AccountGuard::REACH_ACCOUNT, false),
    AccountGuard::GLOBAL_ONLY,
    'other_org'
);

echo "\n=== Row 10 — an ordinary account, belonging only here, is allowed ===\n";
assertDecision(
    'ordinary actor, ordinary account, REACH_ACCOUNT',
    AccountGuard::decide(false, $ordinary, AccountGuard::REACH_ACCOUNT, false),
    AccountGuard::ALLOW,
    ''
);

echo "\n=== Assertion 5 — an ended membership here belongs ONLY for REACH_REHIRE ===\n";
assertDecision(
    'ordinary actor, ended membership here, REACH_REHIRE -> ALLOW',
    AccountGuard::decide(false, $endedHere, AccountGuard::REACH_REHIRE, false),
    AccountGuard::ALLOW,
    ''
);
foreach ([AccountGuard::REACH_VIEW, AccountGuard::REACH_ACCOUNT, AccountGuard::REACH_THIS_ORG, AccountGuard::REACH_PORTAL] as $reach) {
    assertDecision(
        'ordinary actor, ended membership here, reach=' . $reach . ' -> NOT_FOUND/not_member',
        AccountGuard::decide(false, $endedHere, $reach, false),
        AccountGuard::NOT_FOUND,
        'not_member'
    );
}

echo "\n=== Assertion 3 — flag values: only the whole number 1 and the text '1' count as \"on\" ===\n";
// isRootAdmin — every other value must NOT protect the target (falls
// through to row 7's isAdmin check, which is also off in each of these
// facts rows, so an "off" isRootAdmin reading should end in ALLOW).
foreach ([1, '1'] as $onValue) {
    $facts = ['isAdmin' => 0, 'isRootAdmin' => $onValue, 'thisOrgActive' => 1, 'otherOrgRows' => 0];
    assertDecision(
        'isRootAdmin=' . var_export($onValue, true) . ' counts as ON',
        AccountGuard::decide(false, $facts, AccountGuard::REACH_ACCOUNT, false),
        AccountGuard::GLOBAL_ONLY,
        'global_admin'
    );
}
// The values true, '01', and '1x' are added to detect a loosened test
// that uses (int) $value === 1 instead of the strict check. Issue #497
// explains why only the exact integer 1 and the exact string '1' count
// as set; any other value must not.
foreach ([0, '0', null, true, '01', '1x'] as $offValue) {
    $facts = ['isAdmin' => 0, 'isRootAdmin' => $offValue, 'thisOrgActive' => 1, 'otherOrgRows' => 0];
    assertDecision(
        'isRootAdmin=' . var_export($offValue, true) . ' counts as OFF',
        AccountGuard::decide(false, $facts, AccountGuard::REACH_ACCOUNT, false),
        AccountGuard::ALLOW,
        ''
    );
}
// isAdmin — same two-value test, with isRootAdmin held off.
foreach ([1, '1'] as $onValue) {
    $facts = ['isAdmin' => $onValue, 'isRootAdmin' => 0, 'thisOrgActive' => 1, 'otherOrgRows' => 0];
    assertDecision(
        'isAdmin=' . var_export($onValue, true) . ' counts as ON',
        AccountGuard::decide(false, $facts, AccountGuard::REACH_ACCOUNT, false),
        AccountGuard::GLOBAL_ONLY,
        'portal_admin'
    );
}
// The values true, '01', and '1x' are added to detect a loosened test
// that uses (int) $value === 1 instead of the strict check. Issue #497
// explains why only the exact integer 1 and the exact string '1' count
// as set; any other value must not.
foreach ([0, '0', null, true, '01', '1x'] as $offValue) {
    $facts = ['isAdmin' => $offValue, 'isRootAdmin' => 0, 'thisOrgActive' => 1, 'otherOrgRows' => 0];
    assertDecision(
        'isAdmin=' . var_export($offValue, true) . ' counts as OFF',
        AccountGuard::decide(false, $facts, AccountGuard::REACH_ACCOUNT, false),
        AccountGuard::ALLOW,
        ''
    );
}
// thisOrgActive — same two-value test, read via row 3's "belongs" check
// (multi-org, REACH_ACCOUNT — anything but 1/'1' must NOT belong).
foreach ([1, '1'] as $onValue) {
    $facts = ['isAdmin' => 0, 'isRootAdmin' => 0, 'thisOrgActive' => $onValue, 'otherOrgRows' => 0];
    assertDecision(
        'thisOrgActive=' . var_export($onValue, true) . ' counts as belonging (ALLOW)',
        AccountGuard::decide(false, $facts, AccountGuard::REACH_ACCOUNT, false),
        AccountGuard::ALLOW,
        ''
    );
}
foreach (['0', 2, true, '01', '1x'] as $notOnValue) {
    // A value of 0 (int) is covered separately by the "ended" tests above
    // (belongs only for REACH_REHIRE); here we cover the TEXT '0' and any
    // other non-1 value some future caller might hand in, both of which
    // must NOT count as an active membership. The values true, '01', and
    // '1x' are added to detect a loosened test that uses (int) $value === 1
    // instead of the strict check; issue #497 is why only exact integer 1
    // and exact string '1' count as set.
    $facts = ['isAdmin' => 0, 'isRootAdmin' => 0, 'thisOrgActive' => $notOnValue, 'otherOrgRows' => 0];
    assertDecision(
        'thisOrgActive=' . var_export($notOnValue, true) . ' does not count as active (NOT_FOUND)',
        AccountGuard::decide(false, $facts, AccountGuard::REACH_ACCOUNT, false),
        AccountGuard::NOT_FOUND,
        'not_member'
    );
}

echo "\n=== Assertion 4 — otherOrgRows as 0, '0', 2, '2' ===\n";
foreach ([0, '0'] as $zeroish) {
    $facts = ['isAdmin' => 0, 'isRootAdmin' => 0, 'thisOrgActive' => 1, 'otherOrgRows' => $zeroish];
    assertDecision(
        'otherOrgRows=' . var_export($zeroish, true) . ' -> ALLOW (no other organisation)',
        AccountGuard::decide(false, $facts, AccountGuard::REACH_ACCOUNT, false),
        AccountGuard::ALLOW,
        ''
    );
}
foreach ([2, '2'] as $someOther) {
    $facts = ['isAdmin' => 0, 'isRootAdmin' => 0, 'thisOrgActive' => 1, 'otherOrgRows' => $someOther];
    assertDecision(
        'otherOrgRows=' . var_export($someOther, true) . ' -> GLOBAL_ONLY/other_org',
        AccountGuard::decide(false, $facts, AccountGuard::REACH_ACCOUNT, false),
        AccountGuard::GLOBAL_ONLY,
        'other_org'
    );
}

echo "\n=== Assertion 8 — the three message() texts, exactly, and none empty ===\n";
$notFoundMsg = AccountGuard::message(AccountGuard::NOT_FOUND, AccountGuard::REACH_ACCOUNT);
assertTrue('NOT_FOUND message is exact', $notFoundMsg === 'That account could not be found.');
$portalMsg = AccountGuard::message(AccountGuard::GLOBAL_ONLY, AccountGuard::REACH_PORTAL);
assertTrue(
    'GLOBAL_ONLY/REACH_PORTAL message is exact',
    $portalMsg === 'Only a global administrator can give or remove administrator rights across the whole portal.'
);
$accountMsg = AccountGuard::message(AccountGuard::GLOBAL_ONLY, AccountGuard::REACH_ACCOUNT);
assertTrue(
    'GLOBAL_ONLY/other reach message is exact',
    $accountMsg === 'Only a global administrator can change this account, because a change made here could reach beyond this organisation.'
);
assertTrue('none of the three messages is empty', $notFoundMsg !== '' && $portalMsg !== '' && $accountMsg !== '');
// A NOT_FOUND verdict must give the SAME wording regardless of reach —
// this is what makes a missing account indistinguishable from a refused
// one to the person trying.
assertTrue(
    'NOT_FOUND wording does not vary by reach',
    AccountGuard::message(AccountGuard::NOT_FOUND, AccountGuard::REACH_PORTAL) === $notFoundMsg
    && AccountGuard::message(AccountGuard::NOT_FOUND, AccountGuard::REACH_REHIRE) === $notFoundMsg
);

echo "\n=== Assertion 9 — memberScopeSql() validates its column name before touching anything else ===\n";
expectThrows('a column name carrying an injection attempt', static function () {
    AccountGuard::memberScopeSql('u.userID; DROP');
});
expectThrows('a bare column with no table qualifier', static function () {
    AccountGuard::memberScopeSql('userID');
});

// -----------------------------------------------------------------------------
// #521 (20 September 2026) — emailMessage() is the fourth pure method this
// file can exercise with no database: a plain string-in, string-out lookup
// against the four EMAIL_* verdicts. emailAvailability() itself needs a real
// connection (it queries tblUsers and calls RateLimiter), so it is proved
// separately, against a real database, in the settled plan's §6.5 proof —
// not here.
// -----------------------------------------------------------------------------
echo "\n=== Assertion 10 — the four emailMessage() texts, exactly, and only one is empty ===\n";
$emailFreeMsg   = AccountGuard::emailMessage(AccountGuard::EMAIL_FREE);
$emailInUseMsg  = AccountGuard::emailMessage(AccountGuard::EMAIL_IN_USE_HERE);
$emailNotAvailMsg = AccountGuard::emailMessage(AccountGuard::EMAIL_NOT_AVAILABLE);
$emailTooManyMsg  = AccountGuard::emailMessage(AccountGuard::EMAIL_TOO_MANY);

assertTrue('EMAIL_FREE message is empty (never shown)', $emailFreeMsg === '');
assertTrue(
    'EMAIL_IN_USE_HERE message is exact',
    $emailInUseMsg === 'Another account in this organisation already uses that email address.'
);
assertTrue(
    'EMAIL_NOT_AVAILABLE message is exact',
    $emailNotAvailMsg === 'That email address can\'t be used here. If you believe this person should be a '
        . 'member of this organisation, ask a global administrator.'
);
assertTrue(
    'EMAIL_TOO_MANY message is exact',
    $emailTooManyMsg === 'Too many email addresses that can\'t be used here have been tried from this account '
        . 'in the last hour. Try again later, or ask a global administrator.'
);
assertTrue(
    'exactly one of the four messages is empty, and it is the free one',
    $emailFreeMsg === '' && $emailInUseMsg !== '' && $emailNotAvailMsg !== '' && $emailTooManyMsg !== ''
);
assertTrue(
    'an unrecognised verdict string returns empty, not a fatal error',
    AccountGuard::emailMessage('nonsense') === ''
);
assertTrue(
    'the limit and window constants are the ones the docblock describes',
    AccountGuard::EMAIL_CLASH_LIMIT === 5 && AccountGuard::EMAIL_CLASH_WINDOW === 3600
);

echo "\n" . ($failures === 0 ? "ALL PASS ({$failures} failures)\n" : "{$failures} FAILURE(S)\n");
exit($failures === 0 ? 0 : 1);
