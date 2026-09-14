<?php
// Path: tools/audit-checks/fixtures/static_calls/apps/carried04_nested_string_in_interpolation.php
// Carried-over case — a STRING LITERAL nested inside a `{$ ... }`
// interpolation, whose own content merely looks like a call. This is the
// twin of fixture case 9 (case09_interpolation_call.php): that one MUST be
// reported because it is a real call; this one must NOT be, because
// "Site::x()" here is a string VALUE — an array key — never executed.
// PHP's own tokenizer keeps a whole nested string as one opaque token
// regardless of what its text says, so the two can never be confused with
// each other. Must NOT be accused of anything.
declare(strict_types=1);

use Portal\Core\Site;

$values = ["Site::x()" => 1];
echo "{$values["Site::x()"]}";
