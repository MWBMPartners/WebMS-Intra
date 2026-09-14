<?php
// Path: tools/audit-checks/fixtures/static_calls/review3/r3_01_dynamic_class_expressions/apps/10_no_import.php
// Third review, finding 1 — FALSE ACCUSATION shape. In each call below, `Site`
// is NOT a class name. It is a class constant or a property whose value is
// Auth::class, so PHP calls Auth::authOnly(), which exists. Real PHP runs all
// three lines without error.
//
// The previous version matched only the `Site::authOnly(` part of each line,
// read `Site` as a class name, and — because this file has no namespace and
// no import — reported three "core class used with no import" findings.
// Must NOT be accused. Must not be counted as verified either: the checker
// cannot know from the source text which class a constant or property holds,
// so all three calls must be counted as "left alone".
declare(strict_types=1);

final class NoImportHolder
{
    public const Site = \Portal\Core\Auth::class;

    public string $Site = \Portal\Core\Auth::class;
}

$holder = new NoImportHolder();

echo NoImportHolder::Site::authOnly();
echo $holder->Site::authOnly();
echo $holder?->Site::authOnly();
