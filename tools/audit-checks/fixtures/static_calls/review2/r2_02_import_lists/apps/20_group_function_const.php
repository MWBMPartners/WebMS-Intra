<?php
// Path: tools/audit-checks/fixtures/static_calls/review2/r2_02_import_lists/apps/20_group_function_const.php
// Second review, finding 2 — MISSED FAULT shape. Inside a grouped import, the
// word `function` or `const` in front of an item means "this imports a
// function" or "this imports a constant". Neither imports a CLASS, so
// `Site::` and `Auth::` below still look for top-level classes called Site
// and Auth, find none, and real PHP stops with 'Class "Site" not found'.
// The previous version threw those two words away and treated both items
// as class imports, so it passed this file as fine.
// MUST be reported: both calls, as core classes used with no import.
declare(strict_types=1);

use Portal\Core\{function Site, const Auth};

Site::free();
Auth::ok();
