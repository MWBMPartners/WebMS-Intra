<?php
// Path: tools/audit-checks/fixtures/static_calls/review2/r2_06_trait_selection/apps/20_still_reported.php
// Second review, finding 6 — proves the fixes above did not simply stop the
// check looking. In AliasUser, `AliasA::ok insteadof AliasB` means the plain
// name ok() is AliasA's version, which needs one argument, so real PHP stops
// here with an ArgumentCountError.
// MUST be reported: called with no arguments, needs at least 1.
declare(strict_types=1);

use Portal\Core\AliasUser;

AliasUser::ok();
