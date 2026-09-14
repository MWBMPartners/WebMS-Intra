<?php
// Path: tools/audit-checks/fixtures/static_calls/apps/carried05_site_name.php
// Carried-over case — the real historical bug this whole check exists for
// (#494). Site has never had a name() method. MUST be reported.
declare(strict_types=1);

use Portal\Core\Site;

Site::name();
