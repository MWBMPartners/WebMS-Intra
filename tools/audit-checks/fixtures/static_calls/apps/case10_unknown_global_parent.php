<?php
// Path: tools/audit-checks/fixtures/static_calls/apps/case10_unknown_global_parent.php
// Fixture case 10 — Portal\Core\Weird `extends \Base` (a LEADING
// BACKSLASH, single segment). That names the GLOBAL namespace's Base, never
// Portal\Core\Base, even though a class of that short name happens to exist
// in the fixture core directory too. Weird::anything() must be SKIPPED —
// this check cannot see the real, unrelated global \Base's methods, and
// must never check it against Portal\Core\Base's instead. The old lexer
// stripped the leading backslash and, finding nothing left but a single
// segment, silently matched it to the sibling anyway. Must NOT be accused
// of anything.
declare(strict_types=1);

use Portal\Core\Weird;

Weird::somethingThatIsNotOnEitherBase();
