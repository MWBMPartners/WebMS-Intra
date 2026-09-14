<?php
// Path: tools/audit-checks/fixtures/static_calls/apps/case08_enum.php
// Fixture case 8 — enums are mapped, and DO have static methods. Calling a
// method that genuinely does not exist on an enum must be reported, exactly
// as it would be for a class. The old lexer never mapped enums (or
// interfaces) at all, so a call like this passed clean no matter what was
// called. MUST be reported: Status has no missing() method (it does have
// fromLabel() and the built-in cases() — see the self-test's positive
// checks for those).
declare(strict_types=1);

use Portal\Core\Status;

// These two must NOT be flagged — a hand-written static method, and the
// built-in `cases()` every enum gets whether the source text spells it out
// or not (see sc_add_enum_builtins()).
Status::fromLabel('active');
Status::cases();

Status::missing();
