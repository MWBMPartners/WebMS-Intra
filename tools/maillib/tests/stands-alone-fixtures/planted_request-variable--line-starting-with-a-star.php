<?php
// Path: tools/maillib/tests/stands-alone-fixtures/planted_request-variable--line-starting-with-a-star.php
// A PLANTED FILE for stands-alone.php. It is never run and is not part of the library.
// It holds, on purpose, one way of reaching outside the library that the check must catch.

declare(strict_types=1);

namespace MWBM\Mail;
$length = 2
* strlen((string) $_SERVER['HTTP_HOST']);
