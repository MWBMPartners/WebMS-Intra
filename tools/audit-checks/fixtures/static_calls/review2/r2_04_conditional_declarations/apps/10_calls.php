<?php
// Path: tools/audit-checks/fixtures/static_calls/review2/r2_04_conditional_declarations/apps/10_calls.php
// Second review, finding 4 — FALSE ACCUSATION shape. Both calls run without
// error in real PHP, because the `if (true)` branch declares the version that
// has free(). The check cannot know which branch runs, so it must neither
// accuse these calls nor count them as verified: both must be counted as
// "left alone".
declare(strict_types=1);

use Portal\Core\{AltSite, Site};

Site::free();
AltSite::free();
