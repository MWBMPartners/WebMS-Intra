<?php
// Path: tools/audit-checks/fixtures/static_calls/apps/carried01_trait_method.php
// Carried-over case (the old script handled this correctly too) — a plain,
// non-conflicting trait method. Portal\Core\Greeter::greet() is supplied
// entirely by the Greetable trait it uses; it must not be accused of
// calling a method that doesn't exist. Must NOT be accused of anything.
declare(strict_types=1);

use Portal\Core\Greeter;

Greeter::greet();
