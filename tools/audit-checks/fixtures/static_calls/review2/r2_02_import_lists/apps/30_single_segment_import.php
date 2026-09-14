<?php
// Path: tools/audit-checks/fixtures/static_calls/review2/r2_02_import_lists/apps/30_single_segment_import.php
// Extra shape found while fixing finding 2 (not in the Codex list). A name in
// a `use` line is always read from the very top of the namespace tree, so
// `use Auth;` means the top-level class \Auth, NOT Portal\Core\Auth — real
// PHP stops here with 'Class "Auth" not found'. The previous version treated
// a one-word import as "look this up among the core classes", so it checked
// the call against Portal\Core\Auth and counted it as verified.
// Must NOT be accused (this check does not report a missing top-level class)
// and must NOT be counted as verified either.
declare(strict_types=1);

namespace App;

use Auth;

Auth::ok();
