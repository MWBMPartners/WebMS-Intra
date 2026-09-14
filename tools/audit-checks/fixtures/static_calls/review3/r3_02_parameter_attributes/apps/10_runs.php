<?php
// Path: tools/audit-checks/fixtures/static_calls/review3/r3_02_parameter_attributes/apps/10_runs.php
// Third review, finding 2 — every call here runs without error in real PHP.
// The first two pass the argument their method needs; the last two call
// methods whose own parameter is variadic or has a default. Must NOT be
// accused.
declare(strict_types=1);

use Portal\Core\Site;

echo Site::need(1);
echo Site::needClosure(1);
echo Site::spread();
echo Site::optional();
