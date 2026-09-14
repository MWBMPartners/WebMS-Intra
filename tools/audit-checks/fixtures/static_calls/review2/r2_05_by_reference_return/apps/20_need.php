<?php
// Path: tools/audit-checks/fixtures/static_calls/review2/r2_05_by_reference_return/apps/20_need.php
// Second review, finding 5 — proves a by-reference method is now mapped in
// full, argument counts included. need() requires one argument, so real PHP
// stops here with an ArgumentCountError. The previous version did report
// this line, but for the wrong reason ("no such method").
// MUST be reported: called with no arguments, needs at least 1.
declare(strict_types=1);

use Portal\Core\Site;

Site::need();
