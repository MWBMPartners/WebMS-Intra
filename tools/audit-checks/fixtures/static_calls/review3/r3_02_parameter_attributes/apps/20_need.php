<?php
// Path: tools/audit-checks/fixtures/static_calls/review3/r3_02_parameter_attributes/apps/20_need.php
// Third review, finding 2 — MUST be reported. need() has one required
// parameter; the `...` in its attribute does not make it variadic. Real PHP
// throws ArgumentCountError on this line. The previous version passed it.
declare(strict_types=1);

use Portal\Core\Site;

Site::need();
