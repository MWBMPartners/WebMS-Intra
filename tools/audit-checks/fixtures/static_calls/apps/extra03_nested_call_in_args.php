<?php
// Path: tools/audit-checks/fixtures/static_calls/apps/extra03_nested_call_in_args.php
// Extra regression fixture — not one of the 17 documented cases; covers a
// bug caught and fixed DURING the tokenizer rewrite itself, purely by
// diffing the new checker's own findings against the old script's on the
// real codebase (never against a committed fixture until now — see
// check_static_calls.php's own "Resume scanning right after the OPENING
// '('" comment for the story, and DEV_NOTES for why leaving it undocumented
// beyond a code comment was flagged as a real gap).
//
// A call's own arguments can contain ANOTHER static call — real code in
// this project (`ApiResponse::success(['asset' => ApiResponse::
// filterSensitive($asset, [...])])`, for one). A version of this scanner
// that, on finding a matched call, resumed scanning from PAST the whole
// matched argument span rather than right after the opening '(' would step
// clean over the inner call and never see it at all — silently losing it
// from every count and from every check, not merely from this one file.
// Site has no missingNested() method, so if the inner call were ever
// missed again, this file would wrongly come out clean. MUST be reported.
declare(strict_types=1);

use Portal\Core\Site;

Site::ok(Site::missingNested());
