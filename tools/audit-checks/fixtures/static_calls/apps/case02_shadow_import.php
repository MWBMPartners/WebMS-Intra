<?php
// Path: tools/audit-checks/fixtures/static_calls/apps/case02_shadow_import.php
// Fixture case 2 — a DIFFERENT class with the same short name. `Vendor\Site`
// is not `Portal\Core\Site`. The old lexer's import handling only ever
// looked at the tail segment, so it treated this import as if it made
// Site:: resolvable against the CORE Site anyway (fault 2). This check
// cannot know what the real Vendor\Site can do, so it must stay silent —
// not "missing import" (this file plainly does import something called
// Site), and not "no such method" either.
declare(strict_types=1);

use Vendor\Site;

Site::totallyMadeUpMethodThatMightBeRealOnTheVendorOne();
