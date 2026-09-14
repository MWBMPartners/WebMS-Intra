<?php
// Path: tools/audit-checks/fixtures/static_calls/apps/carried03_fqcn_other_vendor.php
// Carried-over case — a fully-qualified call to a DIFFERENT Site.
// `\Vendor\Site::missing()`'s full path is `\Vendor\Site`, not
// `\Portal\Core\Site` — the leading backslash makes it unambiguous, and
// unambiguously not ours, even inside a file that happens to import the
// REAL Site for something else entirely. Must NOT be accused of anything.
declare(strict_types=1);

use Portal\Core\Site;

\Vendor\Site::missing();
