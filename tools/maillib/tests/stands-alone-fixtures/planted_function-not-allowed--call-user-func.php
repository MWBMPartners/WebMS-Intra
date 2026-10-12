<?php
// Path: tools/maillib/tests/stands-alone-fixtures/planted_function-not-allowed--call-user-func.php
// A PLANTED FILE for stands-alone.php. It is never run and is not part of the library.
// It holds, on purpose, one way of reaching outside the library that the check must catch.

declare(strict_types=1);

namespace MWBM\Mail;
$home = call_user_func('get' . 'env', 'HOME');
