<?php
// Path: tools/audit-checks/fixtures/static_calls/apps/case01_grouped_import.php
// Fixture case 1 — a grouped import: `use Portal\Core\{Site, Auth};` must
// resolve Site just as well as a single-class `use Portal\Core\Site;`
// would. The old hand-written lexer's import regex only ever matched one
// class per `use` line, so this shape was wrongly reported as a missing
// import (fault 1). Must NOT be accused of anything.
declare(strict_types=1);

use Portal\Core\{Site, Auth};

Site::ok(1);
