<?php
// Path: web/_core/maillib/autoload.php
/**
 * -----------------------------------------------------------------------------
 * Mail library — class loader 📦
 * -----------------------------------------------------------------------------
 * The one file an application requires to use the mail library. It teaches PHP
 * where to find any class whose name starts with `MWBM\Mail\`: the rest of the
 * name is the path under `src/`. So `MWBM\Mail\Smtp\Connection` is the file
 * `src/Smtp/Connection.php`.
 *
 * WHY A HAND-WRITTEN LOADER. The library must work on shared hosting with no
 * Composer and no command line. The folder is laid out the way Composer
 * expects (one class per file, the path matching the name), so a
 * `composer.json` can replace this file later without moving anything.
 *
 * WHAT THIS CANNOT DO. It loads this library's classes and nothing else. It
 * never reads a setting, a database or anything belonging to the application
 * that uses it: that is what lets the library be lifted out and reused.
 *
 * @package   MWBM\Mail
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.1.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'MWBM\\Mail\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    // Letters, digits, underscores and the namespace separator only. A class
    // name can therefore never walk out of `src/` with ".." or a slash.
    if (preg_match('/^[A-Za-z0-9_\\\\]+$/', $relative) !== 1) {
        return;
    }
    $file = __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR
        . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
    if (is_file($file) === true) {
        require $file;
    }
});
