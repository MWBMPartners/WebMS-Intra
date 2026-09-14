<?php
// Path: tools/audit-checks/fixtures/static_calls/review3/r3_01_dynamic_class_expressions/apps/30_still_judged.php
// Third review, finding 1 — the control. Here `Site` really IS a class name
// (nothing before it but the start of the statement), so the fix for the
// constant and property shapes must not stop these calls being judged.
// Site::ok() is real and must NOT be accused. Site::notThere() really throws
// "Call to undefined static method" and MUST be reported.
declare(strict_types=1);

use Portal\Core\Site;

echo Site::ok();
Site::notThere();
