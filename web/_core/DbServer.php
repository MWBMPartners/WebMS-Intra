<?php
// Path: _core/DbServer.php
/**
 * -----------------------------------------------------------------------------
 * Database server — what is it, and is it a version we support? 🛢️
 * -----------------------------------------------------------------------------
 * Asks the database which product and version it is, then says in plain English
 * whether that is a version this portal expects to work on.
 *
 * WHY THIS EXISTS
 * ---------------
 * Four places in this codebase already read the database version and print it:
 * the admin dashboard, the health page, every backup file, and now the server
 * information page. Every one of them just showed the raw text and left the
 * reader to work out whether it mattered. Nothing anywhere acted on the answer.
 *
 * That became a real problem in April 2026, when MySQL 8.0 reached the end of
 * its support life and stopped receiving security fixes. Most installs of this
 * portal are on shared hosting, where the customer cannot choose the database
 * version. They still deserve to be TOLD, in words, what they are running and
 * what that means — which is what this class produces.
 *
 * IT ALSO WORKS DURING INSTALLATION
 * ---------------------------------
 * The installation wizard runs before the portal's normal start-up code, so it
 * cannot use most of the classes in this folder. This class is written to the
 * same "bootstrap-free" contract already used by `version.php` and
 * `brand-defaults.php`: it depends on nothing except the database connection
 * handed to it. The wizard can simply `require_once` this file and call it.
 * Do not add a dependency on App, Settings, Site or any PORTAL_* constant here
 * without also fixing the wizard.
 *
 * WHAT "SUPPORTED" MEANS HERE — read this before trusting the answer
 * -----------------------------------------------------------------
 * There are three answers, and they mean different things:
 *
 *   'ok'    The version is inside its own maker's support window AND is a
 *           version this project expects to work on.
 *
 *   'warn'  It should work, but something about it deserves attention — most
 *           often that the maker has stopped issuing security fixes for it.
 *           A warning NEVER blocks anything. On shared hosting the customer
 *           usually cannot change the version, so blocking would only lock
 *           them out of their own portal.
 *
 *   'crit'  Older than this portal's code can actually run on. The database
 *           changes in `_sql/` use features that version does not have, so
 *           installing would fail part-way through with a confusing error.
 *           This is the one case where the installation wizard stops.
 *
 * An honest caveat that belongs on every MariaDB answer: this project's own
 * automated tests only ever run against MySQL. Every database change is
 * written to a convention meant to work on both, and that convention is
 * followed carefully — but "written carefully for MariaDB" is not the same
 * claim as "tested on MariaDB", and this class says so out loud rather than
 * implying a confidence nobody has earned.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/475
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use mysqli;
use Throwable;

/**
 * 🛢️ Reads the database product and version, and judges it against the
 *    versions this project supports.
 */
final class DbServer
{
    // 🚧 The oldest MySQL this portal's own database changes can run on.
    //    Below this the install genuinely fails, so the wizard stops.
    public const MIN_MYSQL = '8.0.0';

    // 🚧 The same line for MariaDB.
    public const MIN_MARIADB = '10.6.0';

    // ✅ The oldest MySQL still inside its maker's support window. MySQL 8.0
    //    reached the end of its extended support in April 2026; 8.4 is a
    //    long-term release supported into 2032. Anything at or above 8.4 —
    //    including the 9.x line — is fine.
    public const SUPPORTED_MYSQL = '8.4.0';

    // ✅ The same line for MariaDB. 11.4 is a long-term release maintained by
    //    the community until May 2029.
    public const SUPPORTED_MARIADB = '11.4.0';

    /**
     * 🔍 Ask the database what it is.
     *
     * Never throws. If the database cannot answer — a permission problem, a
     * connection that has already gone away — the result comes back with an
     * engine of 'Unknown' and a state of 'warn', so a caller can always print
     * something sensible instead of crashing.
     *
     * @param mysqli $db An open database connection.
     *
     * @return array{
     *     engine: string,
     *     family: string,
     *     raw: string,
     *     version: string,
     *     comment: string,
     *     state: string,
     *     headline: string,
     *     detail: string,
     *     untested: bool
     * }
     */
    public static function inspect(mysqli $db): array
    {
        $raw     = '';
        $comment = '';

        // 🛡️ Two separate try blocks on purpose. A server that refuses
        //    @@version_comment (some managed hosts restrict system variables)
        //    should still give us the version number from VERSION().
        try {
            $rs = $db->query('SELECT VERSION() AS v');
            if ($rs !== false) {
                $row = $rs->fetch_assoc();
                $raw = (string) ($row['v'] ?? '');
                $rs->free();
            }
        } catch (Throwable $e) {
            $raw = '';
        }

        try {
            $rs = $db->query('SELECT @@version_comment AS c');
            if ($rs !== false) {
                $row     = $rs->fetch_assoc();
                $comment = (string) ($row['c'] ?? '');
                $rs->free();
            }
        } catch (Throwable $e) {
            $comment = '';
        }

        // 🪫 If VERSION() gave us nothing, fall back to what the connection
        //    itself reported when it was opened. That value is filled in by the
        //    database driver during the handshake, so it survives a server that
        //    will not run queries for this user.
        if ($raw === '') {
            $raw = (string) ($db->server_info ?? '');
        }

        return self::classify($raw, $comment);
    }

    /**
     * 🧮 Work out the product and verdict from the two strings the server gave
     *    us. Split out from inspect() so it can be reasoned about — and tested —
     *    without a database.
     *
     * @param string $raw     Whatever VERSION() returned.
     * @param string $comment Whatever @@version_comment returned; may be empty.
     *
     * @return array<string, mixed> Same shape as inspect().
     */
    public static function classify(string $raw, string $comment = ''): array
    {
        $raw     = trim($raw);
        $comment = trim($comment);

        $version = self::extractVersion($raw);

        // 🏷️ Which product is this? MariaDB and Percona both announce
        //    themselves by name, either in the version string or in the
        //    comment, so those two are easy and certain.
        $haystack = strtolower($raw . ' ' . $comment);

        if (strpos($haystack, 'mariadb') !== false) {
            $engine = 'MariaDB';
            $family = 'mariadb';
        } elseif (strpos($haystack, 'percona') !== false) {
            // Percona Server is a MySQL fork that tracks MySQL's own version
            // numbers, so it is judged by the MySQL rules below.
            $engine = 'Percona Server';
            $family = 'mysql';
        } elseif (strpos($haystack, 'mysql') !== false || $version !== '') {
            // Nothing named itself. Plain MySQL is the only database this
            // portal is built for, and it is also the only one that does not
            // stamp its own name into VERSION(), so a bare version number is
            // very likely MySQL. Requiring a readable version number here
            // matters: without it, any unrecognisable string would be
            // confidently mislabelled "MySQL" on the strength of no evidence
            // at all.
            $engine = 'MySQL';
            $family = 'mysql';
        } else {
            $engine = 'Unknown';
            $family = 'unknown';
        }

        // ❓ We could not read a version number at all. Say so plainly rather
        //    than guessing.
        if ($version === '') {
            return [
                'engine'   => $engine,
                'family'   => $family,
                'raw'      => $raw,
                'version'  => '',
                'comment'  => $comment,
                'state'    => 'warn',
                'headline' => 'Database version could not be read',
                'detail'   => 'The database answered, but it did not report a version number '
                            . 'this portal could understand. Everything will still work; we '
                            . 'simply cannot tell you whether the version is a supported one.',
                'untested' => false,
            ];
        }

        if ($family === 'mariadb') {
            return self::judgeMariaDb($engine, $raw, $version, $comment);
        }

        if ($family === 'mysql') {
            return self::judgeMySql($engine, $raw, $version, $comment);
        }

        return [
            'engine'   => 'Unknown',
            'family'   => 'unknown',
            'raw'      => $raw,
            'version'  => $version,
            'comment'  => $comment,
            'state'    => 'warn',
            'headline' => 'Unrecognised database, version ' . $version,
            'detail'   => 'This portal could not tell whether this is MySQL, MariaDB or '
                        . 'something else. It is probably fine — but nobody has checked '
                        . 'this portal against it, so treat anything unusual with suspicion.',
            'untested' => true,
        ];
    }

    /**
     * 🍃 Judge a MariaDB version.
     *
     * @param string $engine  Display name of the product.
     * @param string $raw     The full version string.
     * @param string $version The three-part version number.
     * @param string $comment The server's own description of itself.
     *
     * @return array<string, mixed>
     */
    private static function judgeMariaDb(string $engine, string $raw, string $version, string $comment): array
    {
        // 📌 The same sentence goes on every MariaDB answer, whatever the
        //    verdict. It is the honest limit of what this project can claim.
        $caveat = ' Please note: this portal\'s own automated tests only ever run against '
                . 'MySQL. Its database changes are written to a convention meant to work on '
                . 'both MySQL and MariaDB, and that convention is followed carefully — but no '
                . 'test here proves it. That is not the same as saying it is broken; it means '
                . 'nobody has checked.';

        if (version_compare($version, self::MIN_MARIADB, '<') === true) {
            return [
                'engine'   => $engine,
                'family'   => 'mariadb',
                'raw'      => $raw,
                'version'  => $version,
                'comment'  => $comment,
                'state'    => 'crit',
                'headline' => 'MariaDB ' . $version . ' is too old for this portal',
                'detail'   => 'This portal needs MariaDB ' . self::MIN_MARIADB . ' or newer. '
                            . 'Its database changes use features that MariaDB ' . $version
                            . ' does not have, so installing would fail part-way through. '
                            . 'Ask your hosting provider to move you to a newer database '
                            . 'server before going any further.' . $caveat,
                'untested' => true,
            ];
        }

        if (version_compare($version, self::SUPPORTED_MARIADB, '<') === true) {
            return [
                'engine'   => $engine,
                'family'   => 'mariadb',
                'raw'      => $raw,
                'version'  => $version,
                'comment'  => $comment,
                'state'    => 'warn',
                'headline' => 'MariaDB ' . $version . ' — older than we would like',
                'detail'   => 'This portal expects to work on MariaDB ' . $version . ', and '
                            . 'there is nothing here that needs a newer one. But MariaDB '
                            . self::SUPPORTED_MARIADB . ' is a long-term release maintained '
                            . 'until May 2029, so it is the version worth asking your hosting '
                            . 'provider for when you next have the chance.' . $caveat,
                'untested' => true,
            ];
        }

        return [
            'engine'   => $engine,
            'family'   => 'mariadb',
            'raw'      => $raw,
            'version'  => $version,
            'comment'  => $comment,
            'state'    => 'ok',
            'headline' => 'MariaDB ' . $version . ' — a supported version',
            'detail'   => 'This is a current MariaDB release and it is still receiving '
                        . 'security fixes from its makers.' . $caveat,
            'untested' => true,
        ];
    }

    /**
     * 🐬 Judge a MySQL (or Percona Server) version.
     *
     * @param string $engine  Display name of the product.
     * @param string $raw     The full version string.
     * @param string $version The three-part version number.
     * @param string $comment The server's own description of itself.
     *
     * @return array<string, mixed>
     */
    private static function judgeMySql(string $engine, string $raw, string $version, string $comment): array
    {
        if (version_compare($version, self::MIN_MYSQL, '<') === true) {
            return [
                'engine'   => $engine,
                'family'   => 'mysql',
                'raw'      => $raw,
                'version'  => $version,
                'comment'  => $comment,
                'state'    => 'crit',
                'headline' => $engine . ' ' . $version . ' is too old for this portal',
                'detail'   => 'This portal needs MySQL ' . self::MIN_MYSQL . ' or newer. Its '
                            . 'database changes use features that version ' . $version . ' does '
                            . 'not have, so installing would fail part-way through and leave '
                            . 'you with a half-built database. Ask your hosting provider to '
                            . 'move you to a newer database server before going any further.',
                'untested' => false,
            ];
        }

        // ⚠️ The 8.0 line. This is the important one: it is far and away the
        //    most common version on shared hosting, and it stopped receiving
        //    security fixes in April 2026.
        if (version_compare($version, '8.1.0', '<') === true) {
            return [
                'engine'   => $engine,
                'family'   => 'mysql',
                'raw'      => $raw,
                'version'  => $version,
                'comment'  => $comment,
                'state'    => 'warn',
                'headline' => $engine . ' ' . $version . ' — works, but no longer getting security fixes',
                'detail'   => 'The whole MySQL 8.0 line reached the end of its support life in '
                            . 'April 2026. It still runs this portal perfectly well — this is '
                            . 'the version everything here is tested against — but Oracle no '
                            . 'longer issues security fixes for it, so any flaw found from now '
                            . 'on stays unfixed. On shared hosting you usually cannot change '
                            . 'this yourself. It is worth asking your hosting provider when '
                            . 'they plan to move to MySQL ' . self::SUPPORTED_MYSQL . ', which '
                            . 'is a long-term release supported into 2032.',
                'untested' => false,
            ];
        }

        // ⚠️ 8.1, 8.2 and 8.3 were short-lived "innovation" releases. Each was
        //    superseded within about three months and none is supported now.
        if (version_compare($version, self::SUPPORTED_MYSQL, '<') === true) {
            return [
                'engine'   => $engine,
                'family'   => 'mysql',
                'raw'      => $raw,
                'version'  => $version,
                'comment'  => $comment,
                'state'    => 'warn',
                'headline' => $engine . ' ' . $version . ' — a short-lived release, now unsupported',
                'detail'   => 'MySQL 8.1, 8.2 and 8.3 were each replaced within about three '
                            . 'months and none of them receives fixes any more. This portal '
                            . 'should run on it without trouble, but MySQL ' . self::SUPPORTED_MYSQL
                            . ' is the long-term release to move to — it is supported into 2032.',
                'untested' => false,
            ];
        }

        return [
            'engine'   => $engine,
            'family'   => 'mysql',
            'raw'      => $raw,
            'version'  => $version,
            'comment'  => $comment,
            'state'    => 'ok',
            'headline' => $engine . ' ' . $version . ' — a supported version',
            'detail'   => 'This is a current MySQL release and it is still receiving security '
                        . 'fixes. Note that this portal\'s automated tests run against the '
                        . 'MySQL 8.0 line, so this version is newer than the one everything is '
                        . 'checked against. That is the right direction to be wrong in, but it '
                        . 'is worth knowing.',
            'untested' => false,
        ];
    }

    /**
     * 🔢 Pull a plain three-part version number out of whatever the server said.
     *
     * MariaDB sometimes puts a fake `5.5.5-` on the front of its version string.
     * It does that so that very old MySQL client programs, which refuse to talk
     * to anything whose version starts with "10.", will still connect. The real
     * version is the part after that prefix, so it has to be removed first or
     * every MariaDB 10.x and 11.x install would be misread as ancient MySQL
     * 5.5 and wrongly refused.
     *
     * See: https://mariadb.com/kb/en/mariadb-vs-mysql-compatibility/
     *
     * @param string $raw Whatever VERSION() returned.
     *
     * @return string A version like '8.0.36', or an empty string if none found.
     */
    private static function extractVersion(string $raw): string
    {
        if (strpos($raw, '5.5.5-') === 0) {
            $raw = substr($raw, 6);
        }

        if (preg_match('/(\d+)\.(\d+)\.(\d+)/', $raw, $m) === 1) {
            return $m[1] . '.' . $m[2] . '.' . $m[3];
        }

        // Some builds report only two parts, e.g. "11.4". Treat that as x.y.0.
        if (preg_match('/(\d+)\.(\d+)/', $raw, $m) === 1) {
            return $m[1] . '.' . $m[2] . '.0';
        }

        return '';
    }
}
