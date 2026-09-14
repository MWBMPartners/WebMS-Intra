<?php
// Path: tools/audit-checks/fixtures/static_calls/apps/extra04_core_interpolation_method.php
// Extra regression fixture — not one of the 17 documented cases; calls
// Portal\Core\Site::afterInterpolation() (see fixtures/core/Entities.php),
// a method declared right after another method whose body contains a
// `{$ ... }` string interpolation. A version of the CORE MAP builder that
// miscounted that interpolation opener's own brace depth would have already
// closed Site's class body early, silently dropping afterInterpolation()
// from its method table — so this genuine, correct call would be wrongly
// reported as calling a method that does not exist. Must NOT be accused of
// anything.
declare(strict_types=1);

use Portal\Core\Site;

Site::afterInterpolation();
