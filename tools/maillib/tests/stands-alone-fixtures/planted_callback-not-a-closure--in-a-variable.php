<?php
// Path: tools/maillib/tests/stands-alone-fixtures/planted_callback-not-a-closure--in-a-variable.php
// A PLANTED FILE for stands-alone.php. It is never run and is not part of the library.
// It holds, on purpose, one way of reaching outside the library that the check must catch.

declare(strict_types=1);

namespace MWBM\Mail;
$function = 'get' . 'env';
$home = array_map($function, ['HOME']);
