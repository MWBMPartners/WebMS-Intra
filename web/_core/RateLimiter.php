<?php
// Path: _core/RateLimiter.php
/**
 * -----------------------------------------------------------------------------
 * Login Rate Limiter 🛡️
 * -----------------------------------------------------------------------------
 * Database-backed rate limiting to prevent brute-force login attempts. Counts
 * recent failed login activity per IP address in tblActivityLogs and blocks
 * requests that exceed the configured threshold.
 *
 * Configuration via tblSettings:
 *   auth.rateLimit.maxAttempts   = 5   (max failures before lockout)
 *   auth.rateLimit.windowMinutes = 15  (time window for counting failures)
 *   portal.trustedProxies        = ''  (the machines, or ranges of machines,
 *                                       allowed to tell us a visitor's real
 *                                       address - see getClientIp())
 *   portal.trustedProxyHeader    = 'x-forwarded-for'  (the ONE header those
 *                                       machines are relied on to fill in:
 *                                       'x-forwarded-for' or 'cf-connecting-ip')
 *   Both portal.* settings are read from the PORTAL-WIDE row only. See
 *   loadProxySettings() for why a row saved for one organisation is ignored.
 *
 * Usage:
 *   if (RateLimiter::isBlocked($ip)) {
 *       exit('Too many attempts. Try again later.');
 *   }
 *
 * @see       https://owasp.org/www-community/controls/Blocking_Brute_Force_Attacks
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.3.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class RateLimiter
{
    /** @var int Default maximum failed attempts (per IP) before lockout */
    private const DEFAULT_MAX_ATTEMPTS = 5;

    /** @var int Default maximum failed attempts (per username, across all IPs) — higher
     *           threshold than per-IP because legit users can fail across devices.
     *           Catches the "single account, rotating IPs" attack pattern. */
    private const DEFAULT_MAX_ATTEMPTS_BY_USERNAME = 10;

    /** @var int Default time window in minutes for counting failures */
    private const DEFAULT_WINDOW_MINUTES = 15;

    /** @var string The default forwarded header: the standard chain of hops. */
    private const HEADER_FORWARDED_FOR = 'x-forwarded-for';

    /** @var string Cloudflare's single-address header. */
    private const HEADER_CLOUDFLARE = 'cf-connecting-ip';

    /**
     * @var string The first 12 bytes of an IPv4 address written in IPv6 form
     *      (::ffff:192.0.2.1): ten zero bytes, then two 0xFF bytes.
     * @see https://www.rfc-editor.org/rfc/rfc4291#section-2.5.5.2
     */
    private const IPV4_MAPPED_PREFIX = "\0\0\0\0\0\0\0\0\0\0\xff\xff";

    /**
     * @var list<array{0: string, 1: int}>|null The entries in
     *      `portal.trustedProxies` that passed checking, worked out once per
     *      request. Each is a packed network address (4 bytes for IPv4, 16 for
     *      IPv6) and the number of leading bits an address must share with it
     *      to count as inside. A single address is simply a range of one: all
     *      32 or 128 bits must match. Null means "not looked up yet"; an empty
     *      list means "looked up, and nothing is trusted", which is the
     *      shipped default and means no request header is believed.
     */
    private static ?array $trustedProxies = null;

    /**
     * @var string|null The one header trusted machines are relied on to fill
     *      in: HEADER_FORWARDED_FOR or HEADER_CLOUDFLARE. Null means "not
     *      looked up yet".
     */
    private static ?string $trustedProxyHeader = null;

    /**
     * Check if a given IP address is currently rate-limited (blocked).
     *
     * @param string|null $ip The IP address to check (null = auto-detect)
     *
     * @return bool True if the IP is blocked due to too many failed attempts
     */
    public static function isBlocked(?string $ip = null): bool
    {
        // 🌐 Auto-detect IP if not provided
        if ($ip === null) {
            $ip = self::getClientIp();
        }

        // 📊 Get configuration from settings
        $maxAttempts   = self::getMaxAttempts();
        $windowMinutes = self::getWindowMinutes();

        // 📝 Count recent failed login attempts for this IP
        $recentFailures = self::countRecentFailures($ip, $windowMinutes);

        return ($recentFailures >= $maxAttempts);
    }

    /**
     * Check if EITHER the IP OR the username (across all IPs) is rate-limited.
     *
     * Use this on login forms — the composite check defends against two
     * different attack patterns simultaneously:
     *
     *   - Same attacker, one IP, many usernames  → caught by the per-IP limit
     *     (isBlocked / DEFAULT_MAX_ATTEMPTS, default 5 / 15min).
     *   - Single targeted account, attacker rotating IPs  → caught by the
     *     per-username limit (DEFAULT_MAX_ATTEMPTS_BY_USERNAME, default 10 /
     *     15min) — this is the case that pure IP-based limiting misses.
     *
     * Also defends multi-user shared NAT scenarios: when ONE user behind a
     * shared NAT fumbles their password, the per-username limit fires on
     * THEIR account only, not on the NAT IP — so the rest of the office
     * stays unblocked.
     *
     * @param string      $username Submitted username / email (case-folded
     *                              internally before matching)
     * @param string|null $ip       Client IP (null = auto-detect)
     *
     * @return bool True if either limit has tripped
     */
    public static function isUserOrIpBlocked(string $username, ?string $ip = null): bool
    {
        if ($ip === null) {
            $ip = self::getClientIp();
        }

        // 🛡️ IP-only check first (cheap, existing behaviour)
        if (self::isBlocked($ip) === true) {
            return true;
        }

        // 🛡️ Username-only check (across all IPs)
        $username = trim(strtolower($username));
        if ($username === '') {
            return false;  // nothing to match; behave like pure IP limiter
        }

        $maxByUsername  = self::getMaxAttemptsByUsername();
        $windowMinutes  = self::getWindowMinutes();
        $userFailures   = self::countRecentFailuresByUsername($username, $windowMinutes);

        return $userFailures >= $maxByUsername;
    }

    /**
     * Get the number of minutes remaining in the lockout period.
     * Returns 0 if the IP is not currently blocked.
     *
     * @param string|null $ip The IP address to check (null = auto-detect)
     *
     * @return int Minutes remaining until the lockout expires
     */
    public static function lockoutRemaining(?string $ip = null): int
    {
        if ($ip === null) {
            $ip = self::getClientIp();
        }

        if (self::isBlocked($ip) === false) {
            return 0;
        }

        // 🔍 Find the timestamp of the oldest failure in the current window
        $windowMinutes = self::getWindowMinutes();
        $db = App::db();

        $stmt = $db->prepare(
            'SELECT MIN(timestamp) AS earliest '
            . 'FROM tblActivityLogs '
            . 'WHERE visitorIP = ? '
            . 'AND activityType = ? '
            . 'AND timestamp >= DATE_SUB(NOW(), INTERVAL ? MINUTE)'
        );

        if ($stmt === false) {
            return $windowMinutes; // Assume full lockout on DB error
        }

        $type = 'LoginFailed';
        $stmt->bind_param('ssi', $ip, $type, $windowMinutes);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        if ($row === null || $row['earliest'] === null) {
            return 0;
        }

        // ⏱️ Calculate minutes remaining from the earliest failure + window
        $earliestTime = strtotime($row['earliest']);
        $expiryTime   = $earliestTime + ($windowMinutes * 60);
        $remaining    = (int) ceil(($expiryTime - time()) / 60);

        return max(0, $remaining);
    }

    /**
     * Count recent failed login attempts for a given IP address.
     *
     * @param string $ip            The IP address to check
     * @param int    $windowMinutes Time window in minutes
     *
     * @return int Number of failed attempts in the window
     */
    private static function countRecentFailures(string $ip, int $windowMinutes): int
    {
        $db = App::db();

        // 📝 Query tblActivityLogs for recent 'LoginFailed' entries from this IP
        // See: https://dev.mysql.com/doc/refman/8.0/en/date-and-time-functions.html
        $stmt = $db->prepare(
            'SELECT COUNT(*) AS failCount '
            . 'FROM tblActivityLogs '
            . 'WHERE visitorIP = ? '
            . 'AND activityType = ? '
            . 'AND timestamp >= DATE_SUB(NOW(), INTERVAL ? MINUTE)'
        );

        if ($stmt === false) {
            // 🛡️ On DB error, fail open (allow the attempt) to avoid locking out
            // everyone if the database has an issue
            return 0;
        }

        $type = 'LoginFailed';
        $stmt->bind_param('ssi', $ip, $type, $windowMinutes);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        return (int) ($row['failCount'] ?? 0);
    }

    /**
     * Count recent LoginFailed entries whose activityDescription mentions
     * the given username — across ALL source IPs.
     *
     * The login handler logs failures as e.g.
     *   "Failed login attempt for: alice@example.com"
     *   "Incorrect password for: alice@example.com"
     * so a LIKE '%username%' match catches both forms.
     *
     * The username is bound via prepared statement parameter so SQL
     * injection is impossible. To prevent the user's literal '%' / '_'
     * from broadening the LIKE pattern, we escape them before binding.
     */
    private static function countRecentFailuresByUsername(string $username, int $windowMinutes): int
    {
        // 🛡️ Escape SQL LIKE metacharacters
        $pattern = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $username) . '%';

        $db = App::db();
        $stmt = $db->prepare(
            'SELECT COUNT(*) AS failCount '
            . 'FROM tblActivityLogs '
            . 'WHERE activityType = ? '
            . 'AND timestamp >= DATE_SUB(NOW(), INTERVAL ? MINUTE) '
            . 'AND LOWER(activityDescription) LIKE ?'
        );
        if ($stmt === false) {
            return 0;  // fail open on DB error
        }
        $type = 'LoginFailed';
        $stmt->bind_param('sis', $type, $windowMinutes, $pattern);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['failCount'] ?? 0);
    }

    /**
     * Get the configured maximum failed attempts BY USERNAME (across all IPs).
     */
    private static function getMaxAttemptsByUsername(): int
    {
        $value = App::settings('auth.rateLimit.maxAttemptsByUsername');
        if ($value !== null && $value !== '') {
            return (int) $value;
        }
        return self::DEFAULT_MAX_ATTEMPTS_BY_USERNAME;
    }

    /**
     * Get the configured maximum failed attempts before lockout.
     *
     * @return int Maximum attempts
     */
    private static function getMaxAttempts(): int
    {
        $value = App::settings('auth.rateLimit.maxAttempts');

        if ($value !== null && $value !== '') {
            return (int) $value;
        }

        return self::DEFAULT_MAX_ATTEMPTS;
    }

    /**
     * Get the configured time window in minutes for counting failures.
     *
     * @return int Window in minutes
     */
    private static function getWindowMinutes(): int
    {
        $value = App::settings('auth.rateLimit.windowMinutes');

        if ($value !== null && $value !== '') {
            return (int) $value;
        }

        return self::DEFAULT_WINDOW_MINUTES;
    }

    /* ---------------------------------------------------------------------- */
    /* Generic sliding-window limiter — tblApiRateLimits (#323 Phase 2)        */
    /* ---------------------------------------------------------------------- */

    /**
     * Generic sliding-window rate-limit check against tblApiRateLimits.
     * Does NOT record a hit itself — call recordHit() separately once the
     * caller decides to let the request through (mirrors the check-then-act
     * pattern used by isBlocked() above). Fails OPEN on any DB error so a
     * database hiccup never locks every API caller out.
     *
     * @param string $bucket        Limiter bucket key, e.g. 'apikey:42'
     * @param int    $maxHits       Maximum hits allowed within the window
     * @param int    $windowSeconds Sliding window size, in seconds
     *
     * @return bool True if the bucket is currently over the limit
     */
    public static function tooMany(string $bucket, int $maxHits, int $windowSeconds): bool
    {
        $db = App::db();

        $stmt = $db->prepare(
            'SELECT COUNT(*) AS hitCount '
            . 'FROM tblApiRateLimits '
            . 'WHERE bucket = ? '
            . 'AND hitAt >= NOW() - INTERVAL ? SECOND'
        );
        if ($stmt === false) {
            // 🛡️ Fail open on DB error — never lock out every API caller
            // because of a transient database issue.
            return false;
        }
        $stmt->bind_param('si', $bucket, $windowSeconds);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $hitCount = (int) ($row['hitCount'] ?? 0);

        return $hitCount >= $maxHits;
    }

    /**
     * Record one hit for the given bucket. Roughly 1-in-64 calls also prunes
     * rows older than 2× the window for this bucket, keeping the table small
     * without needing a separate cron/cleanup job.
     *
     * @param string $bucket        Limiter bucket key, e.g. 'apikey:42'
     * @param int    $windowSeconds Sliding window size, in seconds — used to
     *                              size the opportunistic prune horizon
     */
    public static function recordHit(string $bucket, int $windowSeconds): void
    {
        $db = App::db();

        $stmt = $db->prepare('INSERT INTO tblApiRateLimits (bucket) VALUES (?)');
        if ($stmt === false) {
            // 🛡️ Fail open — a logging failure must never block the request.
            return;
        }
        $stmt->bind_param('s', $bucket);
        $stmt->execute();
        $stmt->close();

        // 🧹 Opportunistic prune — ~1-in-64 requests, capped at 500 rows per
        //    call so a single unlucky request never pays for a huge sweep.
        if (random_int(1, 64) === 1) {
            $pruneSeconds = $windowSeconds * 2;
            $pruneStmt = $db->prepare(
                'DELETE FROM tblApiRateLimits '
                . 'WHERE bucket = ? '
                . 'AND hitAt < NOW() - INTERVAL ? SECOND '
                . 'LIMIT 500'
            );
            if ($pruneStmt !== false) {
                $pruneStmt->bind_param('si', $bucket, $pruneSeconds);
                @$pruneStmt->execute();
                $pruneStmt->close();
            }
        }
    }

    /**
     * Seconds remaining until the oldest in-window hit ages out — used to
     * populate a Retry-After response header. Returns 0 if the bucket is
     * not currently over the limit.
     *
     * @param string $bucket        Limiter bucket key, e.g. 'apikey:42'
     * @param int    $maxHits       Maximum hits allowed within the window
     * @param int    $windowSeconds Sliding window size, in seconds
     *
     * @return int Seconds until the oldest hit falls out of the window
     */
    public static function retryAfter(string $bucket, int $maxHits, int $windowSeconds): int
    {
        if (self::tooMany($bucket, $maxHits, $windowSeconds) === false) {
            return 0;
        }

        $db = App::db();
        $stmt = $db->prepare(
            'SELECT MIN(hitAt) AS earliest '
            . 'FROM tblApiRateLimits '
            . 'WHERE bucket = ? '
            . 'AND hitAt >= NOW() - INTERVAL ? SECOND'
        );
        if ($stmt === false) {
            return $windowSeconds; // Assume the full window on DB error
        }
        $stmt->bind_param('si', $bucket, $windowSeconds);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row === null || $row['earliest'] === null) {
            return 0;
        }

        $earliestTime = strtotime((string) $row['earliest']);
        $expiryTime   = $earliestTime + $windowSeconds;
        $remaining    = $expiryTime - time();

        return max(0, $remaining);
    }

    /**
     * 🌐 Public accessor for the same client-address detection used inside
     * this class by isBlocked() and friends. Exposed so callers building
     * their own generic-bucket keys (tooMany()/recordHit(), e.g.
     * `_apps/auth/2fa/verify.php`'s per-user-plus-address code throttle, #B3)
     * get exactly the same answer the rest of this class would use, instead
     * of each call site working it out again and getting it slightly
     * differently.
     *
     * @return string The visitor's address, as decided by getClientIp() below.
     */
    public static function clientIp(): string
    {
        return self::getClientIp();
    }

    /**
     * Work out which address a request came from.
     *
     * ---------------------------------------------------------------------
     * WHAT WAS WRONG BEFORE, AND WHY IT MATTERED
     * ---------------------------------------------------------------------
     * This used to read two request headers - `CF-Connecting-IP` and
     * `X-Forwarded-For` - and believe whichever it found, always, with no
     * check of who sent them.
     *
     * A request header is just text the caller types. Anybody can send one.
     * So anybody could send a different `CF-Connecting-IP` value on every
     * request and look like a brand new person each time. That made every
     * "too many attempts" limit in the portal decorative: the sign-in
     * lockout, the two-factor code throttle, the public form limits on the
     * prayer-request and lost-property pages, all of it. They all count
     * against whatever this function returns.
     *
     * Nobody had noticed because the counting itself worked perfectly. It
     * was just counting a name the visitor had chosen for themselves.
     *
     * ---------------------------------------------------------------------
     * WHAT IT DOES NOW
     * ---------------------------------------------------------------------
     * A forwarded header is believed ONLY when the machine that actually
     * opened the connection - `REMOTE_ADDR`, which the web server fills in
     * and a visitor cannot touch - falls inside an address or range the
     * administrator has listed in the `portal.trustedProxies` setting.
     *
     * That setting is seeded EMPTY, so out of the box nothing is trusted and
     * the only address believed is the one the web server saw. That is the
     * right answer for this portal's normal hosting, where visitors connect
     * straight to the server with nothing in between.
     *
     * It only needs filling in when something genuinely does sit in front of
     * the portal and pass requests along - a content network such as
     * Cloudflare, or a load balancer. In that arrangement `REMOTE_ADDR` is
     * that machine, not the visitor, so a header is the only way to learn
     * who the visitor was.
     *
     * Even then, only ONE header is read: the one named in
     * `portal.trustedProxyHeader`.
     *
     *   'x-forwarded-for' (the default). Every proxy that handles a request
     *     ADDS the address it received the request from to the RIGHT-HAND
     *     end of this list. So the entries on the right were written by
     *     machines we trust, and the entries on the left were written by
     *     whoever sent the request - possibly the visitor, typing anything
     *     they like. The list is therefore read from the RIGHT: start at the
     *     connecting machine and, while the current hop is trusted, step one
     *     entry to the left. The first hop that is NOT trusted is the
     *     visitor. If every hop is trusted, the leftmost one is used.
     *
     *   'cf-connecting-ip'. Cloudflare replaces this header with the
     *     visitor's address on every request, whatever the visitor sent. An
     *     ordinary proxy or load balancer does not: it passes the header
     *     along untouched, so believing it from one of those would hand the
     *     visitor the pen again. Only the operator knows which header their
     *     own proxy guarantees to overwrite, which is why this is a setting
     *     and not a guess.
     *
     * ---------------------------------------------------------------------
     * WHAT WAS WRONG WITH THE FIRST VERSION OF THIS FIX
     * ---------------------------------------------------------------------
     * The first version, written alongside the setting, had three faults
     * that an independent review found before it shipped:
     *
     *   - It took the LEFTMOST X-Forwarded-For entry. That is exactly the
     *     one the visitor wrote, so a visitor behind a trusted proxy could
     *     still choose a new address on every request.
     *   - It believed CF-Connecting-IP from EVERY trusted machine, including
     *     ordinary proxies that pass it through unchanged.
     *   - It accepted exact addresses only. Cloudflare publishes its machines
     *     as ranges, so a site behind it could not list them. Every visitor
     *     then looked like one of Cloudflare's own addresses, everybody
     *     arriving through the same Cloudflare machine shared one limit, and
     *     one person getting a password wrong a few times could lock
     *     everybody else out.
     *
     * ---------------------------------------------------------------------
     * WHAT THIS CANNOT DO
     * ---------------------------------------------------------------------
     * It cannot see past an X-Forwarded-For entry that is not a plain
     * address - "unknown", say, or an address with a port number on the end,
     * which a few proxies write. When the walk from the right reaches such
     * an entry it STOPS and uses the last address it could check, rather
     * than skipping over it. Skipping would be worse: everything to the left
     * of that entry may have been written by the visitor, so skipping could
     * hand them the answer. The cost is that visitors behind such a proxy
     * share one limit. Blank entries (two commas in a row) are simply passed
     * over, because they are nobody's address.
     *
     * It cannot tell an honest header from one a trusted machine has been
     * tricked into passing on. Once a machine is trusted it is trusted;
     * listing one - and especially listing a range - is a decision, not a
     * formality.
     *
     * The address returned is exactly as the web server or the header wrote
     * it. The two ways of writing an IPv4 address (::ffff:192.0.2.1 and
     * 192.0.2.1) count as the same machine when deciding TRUST, but the
     * answer itself is not rewritten from one form to the other.
     *
     * @see https://developers.cloudflare.com/fundamentals/reference/http-request-headers/
     * @see https://www.rfc-editor.org/rfc/rfc7239 (the Forwarded header family)
     *
     * @return string The visitor's address, or '0.0.0.0' when there is none
     *                (which happens when this runs outside a web request).
     */
    private static function getClientIp(): string
    {
        // 🌐 The address the web server itself saw. This is the only one a
        //    visitor cannot choose, so it is the starting point and also the
        //    fallback for every path below.
        $remoteAddr = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if ($remoteAddr === '') {
            return '0.0.0.0';
        }

        // 🚧 Nothing in front of us that we trust? Then every forwarded header
        //    is just text a stranger typed, and we ignore them all.
        if (self::isTrustedProxy($remoteAddr) === false) {
            return $remoteAddr;
        }

        // ☁️ The operator has said their proxy is Cloudflare, which always
        //    overwrites this header. Read it and nothing else. A missing or
        //    malformed value falls back to the connection itself, NOT to
        //    X-Forwarded-For: the operator told us which header to rely on,
        //    and quietly reading a different one would undo that choice.
        if (self::trustedProxyHeader() === self::HEADER_CLOUDFLARE) {
            $cloudflare = trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
            if (self::normaliseAddress($cloudflare) !== null) {
                return $cloudflare;
            }
            return $remoteAddr;
        }

        // 🔀 X-Forwarded-For, read from the RIGHT (see the note above).
        //    $client starts as the connecting machine. Each time round: if
        //    $client is trusted, the next entry to the left is the machine
        //    $client received the request from, so that becomes $client. The
        //    walk ends at the first machine that is NOT trusted - the visitor.
        //    If every entry is trusted, the walk runs off the left-hand end
        //    and the leftmost address stands.
        //    CF-Connecting-IP is never looked at on this path, whatever it says.
        $client = $remoteAddr;
        $hops   = explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        for ($i = count($hops) - 1; $i >= 0; $i--) {
            if (self::isTrustedProxy($client) === false) {
                break;
            }
            $hop = trim($hops[$i]);
            if ($hop === '') {
                continue; // two commas in a row: nobody's address
            }
            if (self::normaliseAddress($hop) === null) {
                break; // cannot see past this - see "WHAT THIS CANNOT DO"
            }
            $client = $hop;
        }

        return $client;
    }

    /**
     * Is this address one we have been told to believe about the visitor's
     * real address?
     *
     * Addresses are compared in their packed binary form (`inet_pton`) rather
     * than as text. That is not fussiness: the same address can be written
     * several ways - `::1` and `0:0:0:0:0:0:0:1` are the same machine, and so
     * are `::ffff:192.0.2.1` and `192.0.2.1` - and a plain string comparison
     * would say they are different, silently refusing to trust a proxy an
     * administrator had correctly listed.
     *
     * @param string $address An address as the web server or a header gave it.
     *
     * @return bool True only when it falls inside a listed address or range.
     */
    private static function isTrustedProxy(string $address): bool
    {
        $packed = self::normaliseAddress($address);
        if ($packed === null) {
            return false;
        }

        foreach (self::trustedProxies() as [$network, $bits]) {
            if (self::inRange($packed, $network, $bits) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Turn ONE address written as text into packed binary, or null if it is
     * not a single valid address (a range, a port number, "unknown", blank).
     *
     * An IPv4 address written in IPv6 form (::ffff:192.0.2.1) comes back as
     * the plain four-byte IPv4 form. A web server listening on IPv6 can report
     * IPv4 visitors that way. Without this step an administrator who listed
     * 192.0.2.1 would find that machine never trusted when it was reported as
     * ::ffff:192.0.2.1, and the reverse.
     *
     * @param string $text The address as text.
     *
     * @return string|null 4 bytes (IPv4), 16 bytes (IPv6), or null.
     */
    private static function normaliseAddress(string $text): ?string
    {
        $packed = self::packAddress($text);
        if ($packed !== null
            && strlen($packed) === 16
            && str_starts_with($packed, self::IPV4_MAPPED_PREFIX) === true
        ) {
            return substr($packed, 12);
        }
        return $packed;
    }

    /**
     * Packed binary for a valid IPv4 or IPv6 address exactly as written (no
     * IPv4-in-IPv6 conversion), or null. filter_var() is asked first because
     * it rejects anything that is not a whole, plain address; inet_pton()
     * alone would also raise a PHP warning on rubbish.
     *
     * @param string $text The address as text.
     *
     * @return string|null 4 or 16 bytes, or null.
     */
    private static function packAddress(string $text): ?string
    {
        if (filter_var($text, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $packed = @inet_pton($text);
        return $packed === false ? null : $packed;
    }

    /**
     * Check one entry from `portal.trustedProxies` and turn it into a packed
     * network address and a size, or null if it must be ignored.
     *
     * Accepted: a single address (192.0.2.10, 2001:db8::1), or a range in
     * slash notation (173.245.48.0/20, 2400:cb00::/32).
     *
     * Ignored, and so never trusted:
     *   - an entry with a space, tab, new line or comma inside it
     *     ("192.0.2.1 /24"). The address in front of the slash is NOT trusted
     *     on its own either - see loadProxySettings() for why;
     *   - anything before the slash that is not a valid address ("garbage/8");
     *   - a size after the slash that is not a plain whole number, or is larger
     *     than the address allows - over 32 for IPv4, over 128 for IPv6
     *     ("1.2.3.4/33");
     *   - a size of 0 ("0.0.0.0/0", "::/0"). That means "the whole internet",
     *     and trusting the whole internet is exactly the bypass this setting
     *     exists to close. For the same reason an IPv4-in-IPv6 range of /96 or
     *     wider ("::ffff:0.0.0.0/96", which is every IPv4 address) is refused.
     *
     * An address with bits set beyond the size (192.0.2.77/24) is accepted as
     * the range it sits in (192.0.2.0/24), as other tools that read this
     * notation do: only the leading bits are ever compared, so the extra bits
     * change nothing.
     *
     * @param string $entry One trimmed, non-empty entry.
     *
     * @return array{0: string, 1: int}|null Packed network and size in bits.
     */
    private static function parseTrustedEntry(string $entry): ?array
    {
        // Nothing that separates entries may appear INSIDE one. The list reader
        // (loadProxySettings) deliberately keeps a slash and the spaces or
        // commas touching it together, so that "192.0.2.1 /24" arrives here as
        // ONE entry and is refused whole, rather than the address in front of
        // the slash being trusted on its own. filter_var() below happens to
        // refuse a space as well (checked on PHP 8.5), but this line says so
        // outright instead of depending on that.
        if (preg_match('/[\s,]/', $entry) === 1) {
            return null;
        }

        $slash       = strpos($entry, '/');
        $addressText = $slash === false ? $entry : substr($entry, 0, $slash);
        $packed      = self::packAddress($addressText);
        if ($packed === null) {
            return null;
        }

        $maxBits = strlen($packed) * 8;
        $bits    = $maxBits;
        if ($slash !== false) {
            $sizeText = substr($entry, $slash + 1);
            // Digits only: no sign, no spaces, no second slash, nothing after.
            if (preg_match('/^[0-9]{1,3}\z/', $sizeText) !== 1) {
                return null;
            }
            $bits = (int) $sizeText;
            if ($bits < 1 || $bits > $maxBits) {
                return null;
            }
        }

        // IPv4 written in IPv6 form: store it as plain IPv4, so it compares
        // with the addresses normaliseAddress() produces.
        if ($maxBits === 128 && str_starts_with($packed, self::IPV4_MAPPED_PREFIX) === true) {
            if ($bits <= 96) {
                return null;
            }
            return [substr($packed, 12), $bits - 96];
        }

        return [$packed, $bits];
    }

    /**
     * Does a packed address fall inside a packed network of the given size?
     * Both must be the same family: an IPv4 address is never inside an IPv6
     * range, which is why IPv4-in-IPv6 forms are converted before this.
     *
     * @param string $address Packed address (4 or 16 bytes).
     * @param string $network Packed network (4 or 16 bytes).
     * @param int    $bits    How many leading bits must match (at least 1).
     *
     * @return bool True when the leading $bits bits are identical.
     */
    private static function inRange(string $address, string $network, int $bits): bool
    {
        if (strlen($address) !== strlen($network)) {
            return false;
        }

        // Whole bytes first...
        $wholeBytes = intdiv($bits, 8);
        if (substr($address, 0, $wholeBytes) !== substr($network, 0, $wholeBytes)) {
            return false;
        }

        // ...then whatever bits are left over at the front of the next byte.
        $leftoverBits = $bits % 8;
        if ($leftoverBits === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $leftoverBits)) & 0xFF;
        return (ord($address[$wholeBytes]) & $mask) === (ord($network[$wholeBytes]) & $mask);
    }

    /**
     * Read `portal.trustedProxies` and `portal.trustedProxyHeader`, once per
     * request, from the PORTAL-WIDE rows of tblSettings only.
     *
     * ---------------------------------------------------------------------
     * WHY NOT App::settings(), LIKE EVERYTHING ELSE
     * ---------------------------------------------------------------------
     * App::settings() is the merged list for the organisation this request
     * belongs to: a row saved for one organisation overrides the portal-wide
     * row of the same name. An administrator of a single organisation is
     * allowed to add rows for their own organisation. Read that way, such an
     * administrator could add their own `portal.trustedProxies` - say
     * "0.0.0.0/1, 128.0.0.0/1", which between them cover every IPv4 address -
     * and every limit counted on requests arriving at their organisation's
     * address would go back to believing whatever a visitor typed. Sign-in
     * accounts are not confined to one organisation, so that would weaken the
     * lockout for other people too. Which machines sit in front of the server
     * is a fact about the installation, so only the portal-wide row counts,
     * and a per-organisation row of the same name is ignored.
     *
     * ---------------------------------------------------------------------
     * HOW THE LIST IS READ
     * ---------------------------------------------------------------------
     * Entries are separated by commas, and a new line or a space works too,
     * because Cloudflare publishes its ranges one per line and an operator
     * will paste them in as they come. The one exception is a slash: a slash
     * with a space, tab, new line or comma touching it holds the pieces on
     * both sides together as ONE entry, which is then refused, so
     * "192.0.2.1 /24" trusts nothing rather than trusting 192.0.2.1 alone.
     * Each entry is checked by parseTrustedEntry(); anything that fails is
     * dropped, so a typo makes that one entry do nothing - the safe direction
     * - instead of breaking the whole list. It is dropped silently, which is
     * why the migration that seeds this setting tells the operator to check
     * the result.
     *
     * The header setting accepts 'cf-connecting-ip' in any letter case and
     * with stray spaces, because header names are not case-sensitive in HTTP
     * and Cloudflare's own documents write it "CF-Connecting-IP". Any other
     * value means 'x-forwarded-for'.
     *
     * Settings unreadable (very early in a request, a database problem, or a
     * database from before migration 193)? Trust nothing, and use the default
     * header - the same answer as an empty list, and the safe one. This never
     * throws: it sits underneath the sign-in path, and an exception here would
     * take the sign-in page down with it.
     *
     * @return void
     */
    private static function loadProxySettings(): void
    {
        if (self::$trustedProxies !== null && self::$trustedProxyHeader !== null) {
            return;
        }

        $listKey   = 'portal.trustedProxies';
        $headerKey = 'portal.trustedProxyHeader';
        $rawList   = '';
        $rawHeader = '';
        try {
            $stmt = App::db()->prepare(
                'SELECT settingKey, settingValue FROM tblSettings '
                . 'WHERE siteID IS NULL AND settingKey IN (?, ?)'
            );
            if ($stmt !== false) {
                $stmt->bind_param('ss', $listKey, $headerKey);
                $stmt->execute();
                $result = $stmt->get_result();
                while (is_array($row = $result->fetch_assoc()) === true) {
                    // Matched case-insensitively, as the database matched it.
                    if (strcasecmp((string) $row['settingKey'], $listKey) === 0) {
                        $rawList = (string) $row['settingValue'];
                    } elseif (strcasecmp((string) $row['settingKey'], $headerKey) === 0) {
                        $rawHeader = (string) $row['settingValue'];
                    }
                }
                $stmt->close();
            }
        } catch (\Throwable $ignored) {
            $rawList   = '';
            $rawHeader = '';
        }

        // ✂️ Cut the list into entries.
        //
        //    WHAT WAS WRONG: the list used to be split at every comma, space and
        //    new line BEFORE any entry was checked. A range typed with a stray
        //    space, such as "192.0.2.1 /0", fell apart into "192.0.2.1" - a
        //    perfectly good single address, which was then trusted - and "/0",
        //    which was thrown away. So a mistyped range quietly trusted something
        //    the operator never wrote on its own. Checked 13 September 2026 with
        //    PHP 8.5 and MySQL 8.0.36: with that setting, a request from
        //    192.0.2.1 carrying "X-Forwarded-For: 6.6.6.6" was counted as coming
        //    from 6.6.6.6.
        //
        //    NOW a slash holds together whatever sits either side of it, even
        //    across spaces, tabs, new lines or commas. "192.0.2.1 /0" stays ONE
        //    entry, parseTrustedEntry() refuses it because it contains a space,
        //    and neither half is trusted. Everywhere else a comma, a space or a
        //    new line still separates entries, so a published list, one range
        //    per line, still pastes in exactly as it comes.
        //
        //    Reading the pattern: an entry is one or more of either (a) a run of
        //    characters that are neither a separator nor a slash, or (b) a slash
        //    together with any separators touching it. The "++" and "*+" forms
        //    tell PHP never to go back and re-try characters it has already
        //    matched, which keeps a long run of spaces from slowing it down.
        //    If the pattern ever gives up (it returns false), nothing is trusted.
        $list = [];
        if (preg_match_all('~(?:[^\s,/]++|[\s,]*+/[\s,]*+)++~', $rawList, $matches) === false) {
            $matches = [[]];
        }
        foreach ($matches[0] as $entry) {
            $range = self::parseTrustedEntry($entry);
            if ($range !== null) {
                $list[] = $range;
            }
        }

        self::$trustedProxies     = $list;
        self::$trustedProxyHeader = strtolower(trim($rawHeader)) === self::HEADER_CLOUDFLARE
            ? self::HEADER_CLOUDFLARE
            : self::HEADER_FORWARDED_FOR;
    }

    /**
     * The checked entries of `portal.trustedProxies`. Empty means trust nothing.
     *
     * @return list<array{0: string, 1: int}>
     */
    private static function trustedProxies(): array
    {
        self::loadProxySettings();
        return self::$trustedProxies ?? [];
    }

    /**
     * The one header trusted machines are relied on to fill in.
     *
     * @return string HEADER_FORWARDED_FOR or HEADER_CLOUDFLARE.
     */
    private static function trustedProxyHeader(): string
    {
        self::loadProxySettings();
        return self::$trustedProxyHeader ?? self::HEADER_FORWARDED_FOR;
    }
}
