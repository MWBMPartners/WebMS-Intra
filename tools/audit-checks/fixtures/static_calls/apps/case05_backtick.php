<?php
// Path: tools/audit-checks/fixtures/static_calls/apps/case05_backtick.php
// Fixture case 5 — a backtick shell-execution string containing text that
// LOOKS like a real call. The old lexer's string handling could be fooled
// by backticks; PHP's own tokenizer folds the whole backtick body into one
// opaque string-content token (T_ENCAPSED_AND_WHITESPACE), so this can
// never match the `ClassName::method(` token pattern no matter what text it
// contains. Must NOT be accused of anything.
declare(strict_types=1);

$result = `echo Site::missing()`;
