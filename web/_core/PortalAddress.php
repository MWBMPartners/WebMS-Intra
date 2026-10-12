<?php
// Path: _core/PortalAddress.php
/**
 * -----------------------------------------------------------------------------
 * The portal's own web address 🌐 (#500)
 * -----------------------------------------------------------------------------
 * THE ONE PLACE that answers "what address do people reach this portal at?",
 * and so the one place that decides what goes into a link the portal sends
 * out: in an e-mail, a text message, or anything else read away from the page
 * that made it.
 *
 * WHY IT EXISTS
 * -------------
 * WebMS-Intra is a product that many customers install, each at their own
 * address. Until this file there was no setting for that address. Each piece
 * of code that needed a whole link took the host name out of the request
 * that happened to be running (`$_SERVER['HTTP_HOST']`). Two things were
 * wrong with that.
 *
 *   1. A visitor chooses that name. Anybody could send "Forgot password" for
 *      somebody else's e-mail address with a made-up host name, and the
 *      portal mailed that person a WORKING reset link pointing at the
 *      made-up host (shown by a check on 12 October 2026). Whoever owned
 *      that host only had to wait for the click.
 *   2. A job that runs on a timer has no organisation's address to borrow.
 *      A reminder for organisation B went out with the address of whatever
 *      request started the job, and with no "/<B's key>/" in front.
 *
 * THE RULE (settled by the owner, Salem874)
 * -----------------------------------------
 * The address used for links is:
 *   1. the `site.url` setting, when it holds a usable address;
 *   2. else the address this request arrived at;
 *   3. else nothing, and the caller must cope with nothing: leave the link
 *      out, or not send, and say so. Never a built-in address.
 *
 * On top of that, where several organisations share the installation:
 *   - "own address each": the link carries THAT organisation's own host name
 *     (tblSites.hostPattern). The setting, or failing it the request, still
 *     gives the "https" part and any port, because every organisation is
 *     served by the same web server.
 *   - "first part of the address": the link carries "/<that organisation's
 *     key>/" after the host name.
 *   - "sign-in session": nothing is added. See WHAT IT CANNOT DO.
 *
 * ONLY THE INSTALLATION-WIDE ROW IS READ. An organisation's own `site.url`
 * row is ignored on purpose. An administrator of one organisation can add
 * rows for their own organisation in the general settings editor; if such a
 * row were believed, they could point password-reset links at a site of
 * their choosing and wait for a global administrator to click one.
 * REJECTED for that reason: `App::settings('site.url')`, where an
 * organisation's row replaces the installation-wide one.
 *
 * THE STORED VALUE IS CHECKED EVERY TIME IT IS READ, not only when it is
 * saved here. The general settings editor can save any text into it. A value
 * that does not pass check() is treated as not set, one line goes to PHP's
 * error log, and the admin dashboard says so.
 *
 * TWO PARTS, AND WHY
 * ------------------
 * check() and clean() use nothing but PHP itself, so the installer, which
 * cannot load the rest of the portal, can load this one file to check what
 * somebody types. Everything below them needs the database and the
 * organisation that Site worked out.
 *
 * WHAT IT CANNOT DO
 * -----------------
 * - While `site.url` is NOT set, a link still carries whatever host name the
 *   request named (rule 2), made-up or not. Setting the address is what
 *   closes that; the admin dashboard asks for it until it is done, and
 *   offers a global administrator one press to do it. The one exception is
 *   "own address each" WHEN THE DEFAULT ORGANISATION HAS AN ADDRESS OF ITS
 *   OWN: every organisation's host name then comes from its stored address.
 *   Where the default organisation has none (the Organisations page allows
 *   that on purpose), a request naming a made-up host opens the default
 *   organisation and its link carries the made-up name, exactly as with one
 *   organisation. This paragraph used to say "always" for that mode, which
 *   was untrue (independent check, 12 October 2026); visitorCanChooseHost()
 *   is the one place that now answers it.
 * - It cannot tell whether the address really reaches this portal. That is
 *   set in the hosting panel.
 * - In "sign-in session" mode an address cannot say which organisation is
 *   meant: the person lands in the organisation they last chose, or for a
 *   signed-out visitor the default one.
 * - In "own address each" mode an organisation with no address stored, other
 *   than the default organisation, gets NOTHING: any address that is not
 *   some organisation's own opens the default organisation, so a link there
 *   would open the wrong one. The default organisation with no address of
 *   its own gets the portal's address; if somebody has saved ANOTHER
 *   organisation's address as the portal's address, that link is wrong, and
 *   nothing here notices.
 * - In "own address each" mode with no `site.url`, the port is the
 *   request's. A made-up port can therefore reach a link even where the
 *   host name cannot (see the first point for when it cannot).
 * - It does not know about a portal installed in a sub-folder. The portal
 *   has to be at the top of its address, which is why check() refuses a
 *   path.
 * - It does not cover addresses that must match what the browser itself
 *   sent: passkeys (`auth.webauthn.rpID`), working out the organisation, the
 *   Twitch player. Those still read the request, correctly.
 *
 * Test: tools/portal-address-selftest.php
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/500
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

final class PortalAddress
{
    /** The setting that holds the address. One installation-wide row; empty means "not set". */
    public const SETTING = 'site.url';

    /** What state() can answer. */
    public const STATE_SET        = 'set';        // a usable address is stored
    public const STATE_NOT_SET    = 'not-set';    // nothing is stored
    public const STATE_UNUSABLE   = 'unusable';   // something is stored that check() refuses; it is ignored
    public const STATE_UNREADABLE = 'unreadable'; // the database could not be asked

    /**
     * One part of a host name between dots: letters a to z, digits and
     * hyphens, not beginning or ending with a hyphen, at most 63 characters.
     * The same rule as Site::cleanHostName(). It is written out again here,
     * and not borrowed, so that the installer can load this file alone; the
     * self-test holds the two to the same answers.
     */
    private const HOST_LABEL = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

    /**
     * The default organisation: the one any address that is nobody's own
     * opens. See Site::preDetect(). Public since the dashboard began to ask
     * whether it is the organisation in hand, so that the number is written
     * in one place.
     */
    public const DEFAULT_SITE_ID = 1;

    /** @var array{state: string, address: string}|null What the database holds, read once a request. */
    private static ?array $stored = null;

    /** @var array<int, array<string,mixed>|null> Organisations looked up for a link, by number. */
    private static array $siteRows = [];

    /* ====================================================================== */
    /* ✅ Part 1. Checking an address somebody typed. Uses nothing but PHP.    */
    /* ====================================================================== */

    /**
     * Check an address as somebody typed it, and tidy it.
     *
     * ACCEPTED: "https://portal.example.org", the same with "http://", the
     * same with a port ("http://localhost:8080"), and a bare host name, which
     * is taken to mean https. Capitals, one closing "/" and a closing dot are
     * tidied quietly, and the usual port for the scheme is dropped.
     *
     * REFUSED, never repaired, each with its reason in words:
     *   - a space, a line break or any other control character inside it
     *     (a line break in an address that ends up in an e-mail's heading
     *     lets the writer add headings of their own);
     *   - any beginning other than http:// or https://;
     *   - a user name or password (anything before an "@");
     *   - a folder, a page, a "?" or a "#" after the name: every link in the
     *     portal begins with "/", so the portal must be at the top of its
     *     address;
     *   - a host name that is not plain letters a to z, digits, hyphens and
     *     dots (a name with other letters must be typed in its "xn--" form,
     *     because links are joined as text and nothing would convert it);
     *   - a port outside 1 to 65535.
     *
     * @return array{address: string, problem: string} `address` is '' when
     *         nothing was typed or it was refused; `problem` is '' unless it
     *         was refused.
     */
    public static function check(string $typed): array
    {
        $refuse = static fn (string $why): array => ['address' => '', 'problem' => $why];

        $text = trim($typed);
        if ($text === '') {
            return ['address' => '', 'problem' => ''];
        }
        if (strlen($text) > 255) {
            return $refuse('The address is too long: 255 characters is the most the portal accepts.');
        }
        if (preg_match('/[\x00-\x20\x7F]/', $text) === 1) {
            return $refuse('The address has a space or a line break in it. Type it on one line with no spaces, for example https://portal.example.org');
        }

        $scheme = 'https';
        $rest   = $text;
        if (preg_match('#^([A-Za-z][A-Za-z0-9+.\-]*)://(.*)$#', $text, $found) === 1) {
            $scheme = strtolower($found[1]);
            $rest   = $found[2];
            if ($scheme !== 'https' && $scheme !== 'http') {
                return $refuse('The address must begin with https:// or http://. Nothing else can be used.');
            }
        }

        // The name (and port) is everything before the first "/", "?" or "#".
        $nameLength = strcspn($rest, '/?#');
        $name       = substr($rest, 0, $nameLength);
        $after      = substr($rest, $nameLength);
        if (str_contains($name, '@') === true) {
            return $refuse('The address must not hold a user name or password (anything before an @ sign).');
        }
        if ($after !== '' && $after !== '/') {
            return $refuse('The address must stop at the name, with no folder, page or question mark after it. The portal has to be at the top of its own address: for example https://portal.example.org and not https://example.org/portal');
        }

        $port = '';
        if (preg_match('/^(.*):(\d{1,5})$/', $name, $found) === 1) {
            $name = $found[1];
            $port = (int) $found[2];
            if ($port < 1 || $port > 65535) {
                return $refuse('The number after the colon is not a port a web server can use (1 to 65535).');
            }
            if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
                $port = '';
            }
        }

        $host = rtrim(strtolower($name), '.');
        if ($host === '' || strlen($host) > 253
            || preg_match('/^' . self::HOST_LABEL . '(?:\.' . self::HOST_LABEL . ')*$/', $host) !== 1) {
            return $refuse('That is not a host name the portal can use. Use letters a to z, digits, hyphens and dots, for example portal.example.org. A name with other letters has to be typed in its "xn--" form.');
        }

        return ['address' => $scheme . '://' . $host . ($port === '' ? '' : ':' . $port), 'problem' => ''];
    }

    /**
     * The same check as one value: '' when nothing was typed, the tidied
     * address when it is usable, and null when it is refused.
     */
    public static function clean(string $typed): ?string
    {
        $checked = self::check($typed);

        return $checked['problem'] === '' ? $checked['address'] : null;
    }

    /* ====================================================================== */
    /* 🧭 Part 2. The address in use. Needs the database and Site.             */
    /* ====================================================================== */

    /**
     * What the database holds, read once a request and checked.
     *
     * A read that FAILS is its own answer (STATE_UNREADABLE) and is not
     * treated as "not set": base() then gives nothing. Falling back to the
     * request's host name because the database hiccuped would let a made-up
     * name into a link on exactly the installations that had set an address
     * to stop that.
     *
     * @return array{state: string, address: string}
     */
    private static function stored(): array
    {
        if (self::$stored !== null) {
            return self::$stored;
        }
        try {
            // The newest row, should an old database still hold several:
            // that is the copy the loaded settings use as well (see
            // migration 187, after which there is exactly one).
            $stmt = App::db()->prepare(
                'SELECT settingValue FROM tblSettings WHERE settingKey = ? AND siteID IS NULL ORDER BY settingID DESC LIMIT 1'
            );
            if ($stmt === false) {
                throw new \RuntimeException('the question could not be prepared');
            }
            $key = self::SETTING;
            $stmt->bind_param('s', $key);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } catch (\Throwable $e) {
            error_log('[WebMS-Intra] PortalAddress could not read the ' . self::SETTING . ' setting, so no link is built from it or from the request: ' . $e->getMessage());

            return self::$stored = ['state' => self::STATE_UNREADABLE, 'address' => ''];
        }

        $checked = self::check((string) ($row['settingValue'] ?? ''));
        if ($checked['problem'] !== '') {
            // The value itself is not logged: it is whatever somebody typed.
            error_log('[WebMS-Intra] The ' . self::SETTING . ' setting holds something that is not a usable web address, so it is being ignored. Correct it at Admin, Settings, Organisation.');

            return self::$stored = ['state' => self::STATE_UNUSABLE, 'address' => ''];
        }

        return self::$stored = [
            'state'   => $checked['address'] === '' ? self::STATE_NOT_SET : self::STATE_SET,
            'address' => $checked['address'],
        ];
    }

    /** One of the STATE_* answers: is an address stored, and can it be used? */
    public static function state(): string
    {
        return self::stored()['state'];
    }

    /**
     * The address an administrator SET, and nothing else: '' when none is
     * set, or the stored one is unusable or could not be read. For code that
     * must never go by the request: the name the portal gives a mail server,
     * and the pages that show what is set.
     */
    public static function saved(): string
    {
        return self::stored()['address'];
    }

    /**
     * The address THIS request arrived at, "https://name" with any port, or
     * '' when there is none (a command line) or the Host header is not a
     * plain host name. The header is put through check(), so one carrying a
     * path, an "@" or a line break gives nothing and is never passed on.
     *
     * WHAT IT CANNOT DO: tell a true Host header from a made-up one. Both
     * are plain host names. That is why a set address is used first. It also
     * goes by PHP's own "is this https" flag, as the code it replaced did:
     * where something in front of the portal ends https and passes the
     * request on as http, this says http. Setting the address cures both.
     */
    public static function fromRequest(): string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '') {
            return '';
        }
        $https  = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        $scheme = ($https !== '' && $https !== 'off') ? 'https' : 'http';

        return self::clean($scheme . '://' . $host) ?? '';
    }

    /**
     * 🏠 The address of one organisation's portal, with nothing after the
     * name: "https://portal.example.org". '' when there is none to give.
     * The rule is in the file header.
     *
     * @param int|null $siteId The organisation the address is FOR. Leave it
     *                         out for the organisation of this request. A job
     *                         that works through several organisations must
     *                         pass each one's number.
     */
    public static function base(?int $siteId = null): string
    {
        $stored = self::stored();
        if ($stored['state'] === self::STATE_UNREADABLE) {
            return '';
        }
        $portal = $stored['address'] !== '' ? $stored['address'] : self::fromRequest();
        if ($portal === '') {
            return '';
        }
        if (Site::isMultisiteEnabled() === false || Site::detectionMode() !== Site::MODE_HOST) {
            return $portal;
        }

        // Own address each: the organisation's own host name, with the
        // portal address's scheme and port.
        $siteId ??= Site::id();
        $row      = self::siteRow($siteId);
        $ownHost  = $row === null ? null : Site::cleanHostName((string) ($row['hostPattern'] ?? ''));
        if ($ownHost !== null && $ownHost !== '') {
            return (string) preg_replace('#^(https?://)[^:/]+#', '${1}' . $ownHost, $portal);
        }

        return $siteId === self::DEFAULT_SITE_ID ? $portal : '';
    }

    /**
     * ⚠️ Can whoever sends a request choose the HOST NAME of a link the
     * portal then mails out? The admin dashboard asks, so that its warning
     * says what is true in the state the installation is really in.
     *
     * YES while no usable address is set, with one exception: "own address
     * each" mode where the DEFAULT organisation has an address of its own.
     * An address that is nobody's own opens the default organisation
     * (Site::preDetect()), so a request naming a made-up host is a request
     * to the default organisation, and base() gives that organisation its
     * stored name. Where the default organisation has NO address of its own
     * base() gives it the request's address, made-up name and all.
     *
     * NO once an address is set, and NO when the setting cannot be read
     * (base() then gives no link at all).
     *
     * WHY IT IS HERE AND NOT ON THE DASHBOARD: the dashboard used to decide
     * this by the mode alone, and told administrators the name could not be
     * chosen in the one own-address state where it can (independent check,
     * 12 October 2026). The answer depends on what base() does, so it sits
     * beside base().
     *
     * WHAT IT CANNOT SAY: anything about http against https, or the port.
     * Until an address is set those follow the request in every mode.
     */
    public static function visitorCanChooseHost(): bool
    {
        $stored = self::stored();
        if ($stored['address'] !== '' || $stored['state'] === self::STATE_UNREADABLE) {
            return false;
        }
        if (Site::isMultisiteEnabled() === false || Site::detectionMode() !== Site::MODE_HOST) {
            return true;
        }
        $default = self::siteRow(self::DEFAULT_SITE_ID);
        $ownHost = $default === null ? null : Site::cleanHostName((string) ($default['hostPattern'] ?? ''));

        return $ownHost === null || $ownHost === '';
    }

    /**
     * 🔗 A whole link to one of the portal's pages, for something read away
     * from the portal: "https://portal.example.org/reset-password?token=…".
     * '' when there is no address to give; the caller must then leave the
     * link out, or not send, and never fall back to a bare "/page".
     *
     * @param string   $route  The page, as Site::url() takes it, with any
     *                         "?name=value" already added and encoded:
     *                         'reset-password?token=abc'. '' is the front page.
     * @param int|null $siteId The organisation the link is FOR. As base().
     */
    public static function absolute(string $route, ?int $siteId = null): string
    {
        $siteId ??= Site::id();
        $base     = self::base($siteId);
        if ($base === '') {
            return '';
        }
        $path = self::pathFor($route, $siteId);

        return $path === null ? '' : $base . $path;
    }

    /**
     * 📋 A link SHOWN ON A PAGE for somebody to copy, print or turn into a
     * QR code: the whole address when there is one, and otherwise the
     * address without a host name, which still opens from the page it is
     * shown on. Never use this for anything that is sent out: there, "no
     * address" must mean "no link" (absolute() and linkHtml()).
     */
    public static function forPage(string $route, ?int $siteId = null): string
    {
        $whole = self::absolute($route, $siteId);

        return $whole !== '' ? $whole : Site::url($route);
    }

    /**
     * ✉️ One line of an e-mail that links to a page:
     * `<p><a href="…">words</a></p>`, or '' when there is no address to
     * give. The caller joins it on, so a link that opens nothing is never
     * written and no caller has to remember to test for "no address".
     *
     * @param string   $label  The link's words, as plain text. Escaped here.
     * @param int|null $siteId The organisation the link is FOR. As base().
     */
    public static function linkHtml(string $route, string $label, ?int $siteId = null): string
    {
        $url = self::absolute($route, $siteId);

        return $url === ''
            ? ''
            : '<p><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a></p>';
    }

    /**
     * The part of a link after the host name. Null when the organisation
     * cannot be found, so no link can say which organisation it is for.
     *
     * Site::url() is the portal's address builder and is used wherever it is
     * right. It is NOT enough alone in "first part of the address" mode: it
     * knows only the key THIS REQUEST arrived with. A job on a timer arrives
     * with none, and Site::forceContext() changes the organisation without
     * changing that key, so Site::url() would give organisation B's reminder
     * no key, or organisation A's. So the key is compared first, and when it
     * is not this organisation's the path starts from Site::addressOf(), the
     * organisation's own front page.
     */
    private static function pathFor(string $route, int $siteId): ?string
    {
        if (Site::isMultisiteEnabled() === false || Site::detectionMode() !== Site::MODE_PATH) {
            return Site::url($route);
        }
        $row = self::siteRow($siteId);
        $key = $row === null ? '' : (string) ($row['siteKey'] ?? '');
        if ($key === '') {
            return null;
        }
        if (Site::pathPrefix() === $key) {
            return Site::url($route);
        }

        return Site::addressOf($row) . ltrim($route, '/');
    }

    /**
     * One ACTIVE organisation's key and own address, or null when there is
     * no such organisation or it could not be read (logged; the caller then
     * gives no link, which is the safe answer).
     *
     * @return array<string,mixed>|null
     */
    private static function siteRow(int $siteId): ?array
    {
        $current = Site::current();
        if ($current !== null && (int) ($current['siteID'] ?? 0) === $siteId) {
            return $current;
        }
        if (array_key_exists($siteId, self::$siteRows) === true) {
            return self::$siteRows[$siteId];
        }
        $row = null;
        try {
            $stmt = App::db()->prepare('SELECT siteID, siteKey, hostPattern FROM tblSites WHERE siteID = ? AND isActive = 1 LIMIT 1');
            if ($stmt !== false) {
                $stmt->bind_param('i', $siteId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
            }
        } catch (\Throwable $e) {
            error_log('[WebMS-Intra] PortalAddress could not read organisation ' . $siteId . ', so no link is built for it: ' . $e->getMessage());
            $row = null;
        }

        return self::$siteRows[$siteId] = ($row === false ? null : $row);
    }

    /**
     * Forget what was read, so the next call asks the database again. For
     * the page that has just saved a new address, and for tests.
     */
    public static function forget(): void
    {
        self::$stored   = null;
        self::$siteRows = [];
    }
}
