<?php
// Path: tools/audit-checks/fixtures/static_calls/review3/r3_02_parameter_attributes/apps/30_need_closure.php
// Third review, finding 2 (the same fault through `=`) — MUST be reported.
// needClosure() has one required parameter; the `= 1` belongs to the closure
// inside its attribute, not to the parameter. Real PHP throws
// ArgumentCountError on this line. The previous version passed it.
declare(strict_types=1);

use Portal\Core\Site;

Site::needClosure();
