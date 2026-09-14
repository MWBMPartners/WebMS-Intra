<?php
// Path: tools/audit-checks/fixtures/static_calls/review3/r3_01_dynamic_class_expressions/apps/20_with_site_import.php
// Third review, finding 1 — BOTH wrong answers at once. This file imports
// Portal\Core\Site, but `Site` in the calls below is still a class constant or
// a property holding Auth::class, so every call really goes to Auth.
//
// The previous version read `Site` as the imported class and checked the
// WRONG class:
//   - the first two lines run without error (Auth has authOnly()), yet were
//     reported as "no such method", because Site has no authOnly();
//   - the last line really throws "Call to undefined method
//     Portal\Core\Auth::ok()", yet was counted as verified, because Site does
//     have ok().
// None of the three may be accused or counted as verified. All three must be
// counted as "left alone". The throwing line is last so the lines before it
// can be seen to run.
declare(strict_types=1);

use Portal\Core\Site;

final class ImportHolder
{
    public const Site = \Portal\Core\Auth::class;

    public string $Site = \Portal\Core\Auth::class;
}

$holder = new ImportHolder();

echo ImportHolder::Site::authOnly();
echo $holder->Site::authOnly();
echo $holder->Site::ok();
