<?php
// Path: tools/audit-checks/fixtures/static_calls/apps/case09_interpolation_call.php
// Fixture case 9 — a call written inside a double-quoted string's `{$ ... }`
// complex interpolation. PHP really parses and calls this at runtime — it
// is not string content, whatever it looks like on the page — and really
// throws if the method does not exist. The old lexer never looked inside a
// string at all, so this was invisible to it no matter how real the call
// underneath was. MUST be reported: Site has no missing() method.
declare(strict_types=1);

use Portal\Core\Site;

$values = ['key' => 1];
echo "{$values[Site::missing()]}";
