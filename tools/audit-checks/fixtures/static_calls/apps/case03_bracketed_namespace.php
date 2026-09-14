<?php
// Path: tools/audit-checks/fixtures/static_calls/apps/case03_bracketed_namespace.php
// Fixture case 3 — the bracketed namespace form. Code inside
// `namespace Portal\Core { ... }` is genuinely IN that namespace for
// exactly the span between its own `{` and matching `}` — a bare
// `Site::ok()` in there needs no import, the same as it would inside an
// ordinary `namespace Portal\Core;` statement. The old lexer's namespace
// check was one regex built only for the semicolon form, so this was always
// reported as a missing import (fault 3). Must NOT be accused of anything.
declare(strict_types=1);

namespace Portal\Core {
    Site::ok(1);
}
