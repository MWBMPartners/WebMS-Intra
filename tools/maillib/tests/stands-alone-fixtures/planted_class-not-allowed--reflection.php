<?php
// Path: tools/maillib/tests/stands-alone-fixtures/planted_class-not-allowed--reflection.php
// A PLANTED FILE for stands-alone.php. It is never run and is not part of the library.
// It holds, on purpose, one way of reaching outside the library that the check must catch.

declare(strict_types=1);

namespace MWBM\Mail;
$anything = (new \ReflectionClass(Secret::class))->newInstanceWithoutConstructor();
