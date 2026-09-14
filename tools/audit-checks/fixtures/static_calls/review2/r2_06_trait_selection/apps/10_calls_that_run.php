<?php
// Path: tools/audit-checks/fixtures/static_calls/review2/r2_06_trait_selection/apps/10_calls_that_run.php
// Second review, finding 6 — FALSE ACCUSATION shapes (a) to (d). Real PHP runs
// every line of this file without error; the previous version reported each
// one as "called with no arguments, but needs at least 1".
// Must NOT be accused.
declare(strict_types=1);

use Portal\Core\{AbstractChild, AliasUser, NestedConsumer, OwnWins};

OwnWins::ok();          // 6(a)
AliasUser::other();     // 6(b)
NestedConsumer::ok();   // 6(c)
AbstractChild::ok();    // 6(d)
