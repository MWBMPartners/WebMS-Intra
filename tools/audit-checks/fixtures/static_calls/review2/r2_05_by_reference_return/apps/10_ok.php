<?php
// Path: tools/audit-checks/fixtures/static_calls/review2/r2_05_by_reference_return/apps/10_ok.php
// Second review, finding 5 — FALSE ACCUSATION shape. Site::ok() is real and
// real PHP runs this file without error; the previous version reported it as
// "no such method". Must NOT be accused.
declare(strict_types=1);

use Portal\Core\Site;

Site::ok();
