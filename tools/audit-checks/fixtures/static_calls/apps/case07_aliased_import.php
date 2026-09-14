<?php
// Path: tools/audit-checks/fixtures/static_calls/apps/case07_aliased_import.php
// Fixture case 7 — an aliased import. `use Portal\Core\Site as S;` then
// `S::missing();` must be checked against the REAL Site, through the alias.
// The old lexer never tracked aliases at all, so a call written through one
// was silently ignored — whether or not it was real. MUST be reported: Site
// has no missing() method.
declare(strict_types=1);

use Portal\Core\Site as S;

S::missing();
