<?php
// Path: tools/maillib/tests/stands-alone-fixtures/planted_settings-variable--after-a-short-comment.php
// A PLANTED FILE for stands-alone.php. It is never run and is not part of the library.
// It holds, on purpose, one way of reaching outside the library that the check must catch.

declare(strict_types=1);

namespace MWBM\Mail;
function planted(): string
{
    /* x */ global $SETTINGS;
    return (string) $SETTINGS['site.name'];
}
