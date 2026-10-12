<?php
// Path: tools/maillib/tests/stands-alone-fixtures/planted_class-by-variable--member.php
// A PLANTED FILE for stands-alone.php. It is never run and is not part of the library.
// It holds, on purpose, one way of reaching outside the library that the check must catch.

declare(strict_types=1);

namespace MWBM\Mail;
final class Planted
{
    public function reach(string $name): mixed
    {
        return $this->$name();
    }
}
