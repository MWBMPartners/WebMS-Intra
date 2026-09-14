<?php
// Path: tools/audit-checks/fixtures/static_calls/review2/r2_02_import_lists/apps/10_comma_import.php
// Second review, finding 2 — FALSE ACCUSATION shape. One `use` line may name
// several classes separated by commas, and PHP imports every one of them.
// The previous version read only the FIRST name (Auth) and skipped to the
// semicolon, so Site looked unimported and was reported as "core class used
// with no import". Real PHP runs this file without error.
// Must NOT be accused of anything.
declare(strict_types=1);

use Portal\Core\Auth, Portal\Core\Site;

Site::free();
Auth::ok();
