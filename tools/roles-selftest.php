<?php
// Path: tools/roles-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Roles self-test 🏷️ (#516)
 * -----------------------------------------------------------------------------
 * Standalone, dependency-free (no database, no bootstrap, no network)
 * regression guard for the PURE parts of `Portal\Core\Roles` — the class
 * that turns "does this person hold this role" into a per-organisation
 * question. See `web/_core/Roles.php`'s own docblock for the full "why".
 *
 * This exercises the REAL class, requiring `Roles.php` directly rather
 * than reimplementing its rules. It calls only the methods that need
 * nothing but their own arguments:
 *   - STANDARD — the constant itself, checked for shape.
 *   - normaliseKey() / isValidNewKey() — pure string functions.
 *   - holdsSql() — validates its three identifier arguments FIRST, before
 *     touching anything else, so even the "throws" assertions below need
 *     no database connection.
 *
 * What this CANNOT prove: that `has()`, `grant()`, `revoke()` and the rest
 * of the database-reaching methods actually do the right thing against a
 * real `tblUserRoles`/`tblRoles` — that needs a real database, and is
 * proved instead by the #516 build's real-database proofs (P1-P15). This
 * file only proves the PURE, always-available half never regresses,
 * exactly as `tools/account-guard-selftest.php` does for `AccountGuard`.
 *
 * Usage:  php tools/roles-selftest.php
 * Exit:   0 on success (all assertions pass), 1 on any failure.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026 MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/516
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

if (defined('PORTAL_CORE') === false) {
    define('PORTAL_CORE', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_core');
}

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'Roles.php';

use Portal\Core\Roles;

$failures = 0;

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

echo "=== STANDARD — the fourteen roles every organisation starts with ===\n";
assertTrue('STANDARD has exactly 14 keys', count(Roles::STANDARD) === 14);
foreach (Roles::STANDARD as $key => $role) {
    assertTrue(
        "key '{$key}' matches ^[a-z][a-z0-9_]{1,49}\$",
        preg_match('/^[a-z][a-z0-9_]{1,49}$/', $key) === 1
    );
    assertTrue(
        "key '{$key}' has a non-empty label",
        is_string($role['label'] ?? null) && $role['label'] !== ''
    );
    assertTrue(
        "key '{$key}' has a non-empty description",
        is_string($role['description'] ?? null) && $role['description'] !== ''
    );
}
$expectedKeys = [
    'treasurer', 'approver', 'care_team', 'kids_team', 'prayer_team',
    'asset_manager', 'venue_manager', 'announcement_approver',
    'groups_coordinator', 'stream_moderator', 'staff', 'volunteer',
    'visitor_coordinator', 'event_coordinator',
];
assertTrue(
    'STANDARD keys are exactly the fourteen expected keys, in order',
    array_keys(Roles::STANDARD) === $expectedKeys
);

echo "\n=== normaliseKey() ===\n";
assertTrue("normaliseKey(' Treasurer ') === 'treasurer'", Roles::normaliseKey(' Treasurer ') === 'treasurer');
assertTrue("normaliseKey('TREASURER') === 'treasurer'", Roles::normaliseKey('TREASURER') === 'treasurer');
assertTrue("normaliseKey('treasurer') === 'treasurer' (already normal)", Roles::normaliseKey('treasurer') === 'treasurer');
assertTrue("normaliseKey('') === ''", Roles::normaliseKey('') === '');

echo "\n=== isValidNewKey() ===\n";
assertTrue("isValidNewKey('deacon') accepts", Roles::isValidNewKey('deacon') === true);
assertTrue("isValidNewKey('av_team2') accepts", Roles::isValidNewKey('av_team2') === true);
assertTrue("isValidNewKey('a1') accepts (2 chars, starts with a letter)", Roles::isValidNewKey('a1') === true);
assertTrue("isValidNewKey('Deacon') rejects (not lower-case)", Roles::isValidNewKey('Deacon') === false);
assertTrue("isValidNewKey('1x') rejects (starts with a digit)", Roles::isValidNewKey('1x') === false);
assertTrue("isValidNewKey('x') rejects (only 1 character)", Roles::isValidNewKey('x') === false);
assertTrue("isValidNewKey('@siteAdmin') rejects (a ReportRegistry gate word, not a role key)", Roles::isValidNewKey('@siteAdmin') === false);
assertTrue("isValidNewKey('') rejects (empty)", Roles::isValidNewKey('') === false);
assertTrue(
    'isValidNewKey() rejects a 51-character key (the limit is 50)',
    Roles::isValidNewKey(str_repeat('a', 51)) === false
);
assertTrue(
    'isValidNewKey() accepts a 50-character key (the limit itself)',
    Roles::isValidNewKey(str_repeat('a', 50)) === true
);

echo "\n=== holdsSql() — the exact SQL fragment #514 will call ===\n";
$expectedSql = 'EXISTS (SELECT 1 FROM tblUserRoles ur16 '
    . 'JOIN tblUserSites us16 ON us16.userID = ur16.userID AND us16.siteID = ur16.siteID AND us16.isActive = 1 '
    . 'WHERE ur16.userID = V AND ur16.roleID = am.refID AND ur16.siteID = E.siteID)';
assertTrue(
    "holdsSql('V', 'am.refID', 'E.siteID') matches the plan's exact text",
    Roles::holdsSql('V', 'am.refID', 'E.siteID') === $expectedSql
);
assertTrue(
    "holdsSql('?', '?', '?') works (every argument may be the bind placeholder)",
    Roles::holdsSql('?', '?', '?') === 'EXISTS (SELECT 1 FROM tblUserRoles ur16 '
        . 'JOIN tblUserSites us16 ON us16.userID = ur16.userID AND us16.siteID = ur16.siteID AND us16.isActive = 1 '
        . 'WHERE ur16.userID = ? AND ur16.roleID = ? AND ur16.siteID = ?)'
);
expectThrows("holdsSql('V; DROP TABLE tblUsers', 'x', 'y') throws", static function () {
    Roles::holdsSql('V; DROP TABLE tblUsers', 'x', 'y');
});
expectThrows("holdsSql('1=1', 'x', 'y') throws", static function () {
    Roles::holdsSql('1=1', 'x', 'y');
});
expectThrows("holdsSql('', 'x', 'y') throws (empty is neither '?' nor a plain identifier)", static function () {
    Roles::holdsSql('', 'x', 'y');
});
expectThrows("holdsSql('x', '', 'y') throws (the SECOND argument, not just the first, is checked)", static function () {
    Roles::holdsSql('x', '', 'y');
});
expectThrows("holdsSql('x', 'y', '') throws (the THIRD argument, not just the first two, is checked)", static function () {
    Roles::holdsSql('x', 'y', '');
});

echo "\n" . ($failures === 0 ? "ALL PASS ({$failures} failures)\n" : "{$failures} FAILURE(S)\n");
exit($failures === 0 ? 0 : 1);
