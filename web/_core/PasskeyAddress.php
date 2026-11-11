<?php
// Path: _core/PasskeyAddress.php
/**
 * -----------------------------------------------------------------------------
 * The address a passkey is tied to 🔑🌐 (#903)
 * -----------------------------------------------------------------------------
 * THE ONE PLACE that decides, for a passkey (WebAuthn), two things:
 *
 *   1. the RELYING PARTY ID ("rpId"): the domain name a passkey belongs to.
 *      A browser stores every passkey against one of these, offers it only
 *      on pages at that name (or below it), and the authenticator signs a
 *      fingerprint of it into every answer. The portal must send the same
 *      name when a passkey is made and when it is used, and must check the
 *      fingerprint against it;
 *   2. the ORIGIN the portal expects the browser to report: the page's own
 *      "scheme://name[:port]", which the browser writes into what the
 *      authenticator signs.
 *
 * Both passkey pages (`_apps/auth/login/webauthn.php`, signing in, and
 * `_apps/auth/account/webauthn.php`, adding one) ask relyingParty() and
 * nothing else. Do not work either out on a page.
 *
 * WHAT WAS WRONG (#903, found by #902)
 * ------------------------------------
 * Both pages did this:
 *     $rpID   = App::settings('auth.webauthn.rpID') ?? ($_SERVER['HTTP_HOST'] ?? 'localhost');
 *     $origin = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
 * The install script seeds `auth.webauthn.rpID` as an EMPTY string, and `??`
 * falls back only on null, so on every installation where nobody had typed
 * a value the pages sent `rpId: ""`. MEASURED in a real browser (Microsoft
 * Edge 157, headless, with a virtual authenticator; the script is
 * `tools/passkey-address-browser-selftest.mjs`): an empty rpId is refused
 * with "SecurityError: The relying party ID is not a registrable domain
 * suffix of, nor equal to the current domain", for making a passkey AND for
 * using one. So on a stock installation passkeys could not work at all. And
 * the fallback, had it ever run, kept the PORT from the Host header, which
 * the same browser also refuses.
 *
 * WHICH BROWSER "MEASURED" MEANS IN THIS FILE (#907): every "measured" in
 * this file was measured in ONE browser, Microsoft Edge 157, by that same
 * script. Edge is built on Chromium, as Chrome is, but neither Chrome nor
 * Chromium was run separately, and Firefox and Safari — which implement the
 * same standard with their own code — were NOT measured at all. Where this
 * file says "the browser refuses", read "Edge 157 refuses".
 *
 * THE RULE
 * --------
 * rpId:
 *   1. the INSTALLATION-WIDE `auth.webauthn.rpID` row, when it holds a usable
 *      name (see check()). An administrator who wants passkeys to work across
 *      several names under one domain sets this to that domain;
 *   2. else the HOST NAME of the portal's own address for this organisation,
 *      as `PortalAddress::base()` gives it (`site.url` when set — or the
 *      organisation's own host name in "own address each" mode — else the
 *      address this request arrived at), with no scheme and no port;
 *   3. else nothing, and the pages refuse with a sentence saying why. They
 *      never send an empty rpId again.
 * origin: `PortalAddress::base()` for this organisation, and nothing else.
 *
 * WHY THE PORTAL'S OWN ADDRESS AND NOT SIMPLY THE REQUEST'S HOST NAME
 * -------------------------------------------------------------------
 * - Behind something that hands requests on (a proxy, a load balancer), the
 *   Host header can be an INSIDE name and PHP's "is this https" flag can be
 *   off. A set `site.url` is the address the visitor's browser really shows,
 *   so the rpId and the origin are right there and nowhere else.
 * - It is ONE rule: the same address the portal puts in every link it sends.
 *   `PortalAddress` already decides when the request may be believed.
 *
 * IS A HOST NAME A VISITOR CHOSE SAFE HERE? (rule 2 while `site.url` is not
 * set, when PortalAddress::visitorCanChooseHost() is true.) Yes, and this is
 * the reasoning, not an assumption:
 *   - a real browser sends the Host of the page it is on, and REFUSES an rpId
 *     that is not that page's name or a domain above it (measured in Edge 157: a page at
 *     http://localhost refused "localhost:<port>", "LOCALHOST", "localhost."
 *     and "http://localhost" alike). A wrong name FAILS; it reveals nothing;
 *   - while the setting is empty, the rpId and the expected origin come from
 *     the SAME request. Somebody who sends a made-up Host to replay an answer
 *     makes the portal expect the fingerprint of the made-up name, and a
 *     member's passkey is bound to the real one, so its signature can never
 *     match. Only a passkey made at the made-up name could, and making one
 *     needs that member already signed in there;
 *   - so a made-up Host can only make the made-up person's own sign-in fail.
 *
 * WHAT IT CANNOT DO, said plainly
 * -------------------------------
 * - A portal reached at TWO addresses. Passkeys belong to one rpId. With
 *   `site.url` set, the rpId is that address's name and the origin that
 *   address (in "own address each" mode, the organisation's own name, with
 *   the scheme and port from `site.url`), so a passkey cannot be made or used at a second, unrelated
 *   address (the browser refuses), and at a name BELOW it (say
 *   www.portal.example.org under portal.example.org) the origin check
 *   refuses. With `site.url` NOT set, each address the portal is reached at
 *   is its own rpId, so a passkey made at one is not offered at the other.
 *   Setting `site.url` and having members use that address is the cure.
 * - A portal reached by a NUMBER (an IP address). Measured in Edge 157: the browser
 *   refuses passkeys outright on such a page ("This is an invalid domain"),
 *   whatever rpId is sent. check() refuses a number as the SETTING for that
 *   reason; rule 2 passes the request's own name through untouched, because
 *   the browser then gives its own refusal and nothing the portal sent could
 *   change it.
 * - ANY rpId set by hand while `site.url` is empty — not only a wider one.
 *   A wider name (say "example.org" for portal.example.org) lets every site
 *   under that domain ask a member's authenticator for an answer; but even
 *   the portal's own exact name lets every site BELOW it (x.portal.example.org)
 *   ask. The origin check is what stops such an answer being accepted, and it
 *   can only do that while the origin is FIXED, i.e. while `site.url` is set.
 *   With `site.url` empty the origin follows the request, and an answer
 *   collected by such a site could be replayed with that site's name as the
 *   Host. So set `site.url` before setting the rpId by hand at all. (The
 *   independent check on #903 found the first version of this sentence said
 *   "wider" only, which understated it; the code before #903 behaved the same.)
 * - Whether the address really reaches this portal. That is the hosting
 *   panel's business, as it is for PortalAddress.
 *
 * WHAT HAPPENS TO PASSKEYS THAT ALREADY EXIST
 * -------------------------------------------
 * A passkey is bound to the rpId it was made under; if the rpId changes,
 * that passkey stops being offered and its answers stop matching.
 *   - With the setting EMPTY (the seeded state), the old pages sent "" and
 *     the browser refused, so no passkey can have been made. Nothing is lost.
 *   - With the setting holding a USABLE name, the rpId is the same as before.
 *     The ORIGIN may change: it used to be the request's own address, and is
 *     now `PortalAddress::base()`. Those are the same unless `site.url` is
 *     set to an address other than the one members use, in which case
 *     passkeys stop working at the other address (see "TWO addresses").
 *   - With the setting holding something UNUSABLE (a scheme, a port, a path,
 *     capitals, a closing dot), the old pages sent it as it was and the
 *     browser refused every one of those shapes (measured in Edge 157), so
 *     no passkey can have been made under it either. It is now ignored (or
 *     tidied, for capitals and a closing dot) and passkeys start working.
 *   - With the setting holding a name with a letter outside a-z, such as
 *     "bücher.example" typed with its ü (#907): the old pages sent it AS
 *     TYPED (`git show 7a666338^:web/_apps/auth/login/webauthn.php` reads
 *     `$rpID = App::settings('auth.webauthn.rpID') ?? …`, with no change to
 *     the value), and checked answers against SHA-256 of that same text.
 *     Measured in Edge 157 on a page at that very name (bücher.localhost,
 *     which the browser shows and sends as xn--bcher-kva.localhost): the
 *     name as typed is REFUSED, for making a passkey and for using one, and
 *     only the "xn--" form is accepted. So in Edge no passkey can have been made
 *     under such a setting either (Firefox and Safari were not measured, and a
 *     browser that hashed the typed name could have made one); it is now
 *     ignored and the portal's own name is used. Typed in its "xn--" form it is kept, and works.
 *     (Case A12 of tools/passkey-address-browser-selftest.mjs.)
 *   - FROM NOW ON, with the setting empty, the rpId follows the portal's
 *     address, so CHANGING `site.url` to a different name — or, in "own
 *     address each" mode, an organisation's own address — strands every
 *     passkey made under the old name. The card where the address is changed
 *     (Admin, Settings, Organisation) says so, and says what passkeys follow
 *     instead when this setting is set or organisations have their own names.
 * No customer installation existed when this was written; the effect is
 * stated anyway because the rule is to state it.
 *
 * Tests: part K of tools/two-step-selftest.php (the real pages, a real P-256
 * signature) and tools/passkey-address-browser-selftest.mjs (a real browser).
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/903
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

final class PasskeyAddress
{
    /** The setting an administrator may set the rpId in. One installation-wide row; empty means "not set". */
    public const SETTING = 'auth.webauthn.rpID';

    /** The setting for the name a browser shows beside a passkey. Empty means "use the portal's name". */
    public const NAME_SETTING = 'auth.webauthn.rpName';

    /** Where relyingParty() found its rpId. */
    public const FROM_SETTING = 'setting';         // the administrator's value
    public const FROM_PORTAL  = 'portal-address';  // the host name of PortalAddress::base()
    public const FROM_NOTHING = 'none';            // no rpId: the pages refuse

    /**
     * One part of a host name between dots, as PortalAddress::HOST_LABEL and
     * Site::cleanHostName() have it. Written out again for the same reason
     * PortalAddress gives: so check() needs nothing but PHP.
     */
    private const HOST_LABEL = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

    /** @var array{state: string, value: string}|null The stored row, read once a request. */
    private static ?array $stored = null;

    /* ====================================================================== */
    /* ✅ Part 1. Checking a value somebody typed. Uses nothing but PHP.       */
    /* ====================================================================== */

    /**
     * Check a value typed for the rpId, and tidy it.
     *
     * ACCEPTED: a bare host name, "portal.example.org". Capitals and one
     * closing dot are tidied away, because the browser refuses both (measured
     * in Edge 157, a Chromium browser; Firefox and Safari not measured)
     * — so a stored value carrying either can never have had a passkey made
     * under it, and tidying it cannot strand one.
     *
     * REFUSED, never repaired, each with its reason in words:
     *   - "https://…" or any other scheme, a port (":8443"), a path or "?"
     *     or "#" after the name, a user name ("@"), spaces or line breaks:
     *     an rpId is a NAME, and the browser refuses every one of these;
     *   - a name that is all numbers and dots (an IP address): the browser
     *     refuses passkeys outright on a page reached by a number;
     *   - anything else that is not letters a to z, digits, hyphens and dots.
     *
     * @return array{id: string, problem: string} `id` is '' when nothing was
     *         typed or it was refused; `problem` is '' unless it was refused.
     */
    public static function check(string $typed): array
    {
        $refuse = static fn (string $why): array => ['id' => '', 'problem' => $why];

        $text = trim($typed);
        if ($text === '') {
            return ['id' => '', 'problem' => ''];
        }
        if (preg_match('/[\x00-\x20\x7F]/', $text) === 1) {
            return $refuse('The passkey address has a space or a line break in it. Type the name alone, for example portal.example.org');
        }
        if (str_contains($text, '://') === true) {
            return $refuse('The passkey address must be the name alone, without "https://" or "http://" in front: for example portal.example.org, not https://portal.example.org');
        }
        if (strpbrk($text, '/?#') !== false) {
            return $refuse('The passkey address must be the name alone, with nothing after it: no "/", folder, page or question mark.');
        }
        if (str_contains($text, '@') === true) {
            return $refuse('The passkey address must not hold a user name (anything before an @ sign). Type the name alone, for example portal.example.org');
        }
        if (str_contains($text, ':') === true) {
            return $refuse('The passkey address must be the name alone, with no port number (":8443") after it. A browser refuses a passkey address with a port, even when the portal is reached at one.');
        }

        $id = rtrim(strtolower($text), '.');
        if ($id === '' || strlen($id) > 253
            || preg_match('/^' . self::HOST_LABEL . '(?:\.' . self::HOST_LABEL . ')*$/', $id) !== 1) {
            return $refuse('That is not a name a passkey can be tied to. Use letters a to z, digits, hyphens and dots, for example portal.example.org. A name with other letters has to be typed in its "xn--" form.');
        }
        if (preg_match('/^[0-9.]+$/', $id) === 1) {
            return $refuse('A passkey cannot be tied to a number (an IP address): browsers refuse passkeys on a page reached by a number. Reach the portal by a name, such as portal.example.org, and use that name here.');
        }

        return ['id' => $id, 'problem' => ''];
    }

    /** Is this the rpId setting's key? Compared ignoring capitals, as the settings table compares keys. */
    public static function isTheSetting(string $key): bool
    {
        return strcasecmp($key, self::SETTING) === 0;
    }

    /* ====================================================================== */
    /* 🧭 Part 2. The rpId and origin in use. Needs the database and Site.     */
    /* ====================================================================== */

    /**
     * 🔑 The rpId and expected origin for THIS request. The rule is in the
     * file header.
     *
     * @return array{id: string, origin: string, from: string, problem: string}
     *         `id` and `origin` are '' when there is none to give, and the
     *         page must then refuse: never send an empty rpId. `problem` is
     *         the sentence for the person in that case, and '' otherwise.
     *         A stored setting that is IGNORED (rule 1 refused it) is not a
     *         problem for the person — the passkey still works, from rule 2 —
     *         so it is said only in PHP's error log, once per passkey request
     *         while the value stays stored; the Settings page refuses to save
     *         such a value in the first place.
     */
    public static function relyingParty(): array
    {
        $origin = PortalAddress::base();
        $stored = self::stored();

        if ($stored['state'] === 'unreadable') {
            return [
                'id'      => '',
                'origin'  => '',
                'from'    => self::FROM_NOTHING,
                'problem' => 'Passkeys cannot be used just now: the portal could not read its own settings. Please try again in a moment, or sign in another way.',
            ];
        }
        // No address means no ORIGIN to check an answer against, whatever the
        // setting says: a passkey made then could never be used. So the
        // pages refuse at once rather than let somebody make one that fails.
        $host = preg_match('#^https?://([^:/]+)#', $origin, $found) === 1 ? $found[1] : '';
        if ($host === '') {
            return [
                'id'      => '',
                'origin'  => '',
                'from'    => self::FROM_NOTHING,
                // The commonest cause is no address set and a request naming
                // something that is not a host name. It is also reached when
                // PortalAddress could not read `site.url` (it then gives no
                // address at all, on purpose), and in "own address each"
                // mode for an organisation other than the default that has no
                // address of its own — so the sentence names all three, and
                // both places an address is set.
                'problem' => 'Passkeys cannot be used here yet: the portal does not know this organisation\'s web address, or could not read it just now. An administrator can set it at Admin, Settings, Organisation (or, where each organisation has its own address, at Admin, Organisations). Please sign in another way.',
            ];
        }
        if ($stored['value'] !== '') {
            $checked = self::check($stored['value']);
            if ($checked['problem'] === '') {
                return ['id' => $checked['id'], 'origin' => $origin, 'from' => self::FROM_SETTING, 'problem' => ''];
            }
            // The value itself is not logged: it is whatever somebody typed.
            error_log('[WebMS-Intra] The ' . self::SETTING . ' setting holds something a passkey cannot be tied to, so it is being ignored and the portal\'s own address is used instead. Correct it at Admin, Settings.');
        }

        return ['id' => $host, 'origin' => $origin, 'from' => self::FROM_PORTAL, 'problem' => ''];
    }

    /**
     * 🔎 The name an administrator SET for passkeys, as rule 1 would use it,
     * or '' when none is set, what is set is unusable, or it could not be
     * read. For a page that must say what passkeys follow: the card where the
     * portal's address is changed says that changing the address strands
     * passkeys ONLY when this is '' (and organisations do not each have an
     * address of their own), because otherwise passkeys follow this name or
     * the organisation's, not that box.
     */
    public static function nameSetByAdministrator(): string
    {
        $stored = self::stored();

        return $stored['state'] === 'read' ? self::check($stored['value'])['id'] : '';
    }

    /**
     * 🏷️ The name a browser shows beside a passkey ("rp.name"): the
     * `auth.webauthn.rpName` setting when it holds something, else the
     * portal's name. An EMPTY setting is "not set" — the same `??` fault as
     * the rpId had (#903). Measured in Edge 157: it ACCEPTS an empty name, so
     * this one was only ever cosmetic; it is decided here so the two
     * settings cannot be read two ways.
     */
    public static function displayName(): string
    {
        foreach ([App::settings(self::NAME_SETTING), App::settings('site.name')] as $candidate) {
            if (is_string($candidate) === true && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return 'Portal';
    }

    /**
     * What the database holds for the rpId setting, read once a request.
     *
     * ONLY THE INSTALLATION-WIDE ROW IS READ, as PortalAddress reads
     * `site.url`. An organisation's own row is ignored on purpose: a wider
     * rpId lets more sites ask members' authenticators for answers (see the
     * header), and that is a decision for whoever runs the whole
     * installation, who may be a different party from an organisation's
     * administrator. REJECTED for that reason: `App::settings()`, where an
     * organisation's row replaces the installation-wide one.
     *
     * A read that FAILS is its own answer and is not treated as "not set":
     * falling back to the portal's address would change the rpId under every
     * member who has a passkey tied to a wider name, for as long as the
     * database hiccuped. The pages refuse instead, which is recoverable.
     *
     * @return array{state: string, value: string}
     */
    private static function stored(): array
    {
        if (self::$stored !== null) {
            return self::$stored;
        }
        try {
            $stmt = App::db()->prepare(
                'SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL ORDER BY `settingID` DESC LIMIT 1'
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
            error_log('[WebMS-Intra] PasskeyAddress could not read the ' . self::SETTING . ' setting, so passkeys are refused for this request: ' . $e->getMessage());

            return self::$stored = ['state' => 'unreadable', 'value' => ''];
        }

        return self::$stored = ['state' => 'read', 'value' => trim((string) ($row['settingValue'] ?? ''))];
    }

    /** Forget what was read, so the next call asks the database again. For tests. */
    public static function forget(): void
    {
        self::$stored = null;
    }
}
