<?php
// Path: tools/audit-checks/fixtures/static_calls/apps/carried06_core_class_no_import.php
// Carried-over case — the real historical Auth::csrfToken() outage. Auth is
// a genuine core class; nothing here imports it, writes its full path, or
// puts this call inside any namespace — PHP would look for a global
// top-level Auth, find nothing, and stop with a fatal error the instant
// this line runs. MUST be reported.
declare(strict_types=1);

Auth::csrfToken();
