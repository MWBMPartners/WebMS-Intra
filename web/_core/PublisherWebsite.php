<?php
// Path: _core/PublisherWebsite.php
/**
 * -----------------------------------------------------------------------------
 * 🌐 PublisherWebsite — the publisher's "Website" link on the API page (#863)
 * -----------------------------------------------------------------------------
 * The page that documents this portal's programming interface for developers
 * (`/api-docs`, which draws the description served at `/openapi.json`) shows
 * the publisher's name with a "Website" link beside it. The NAME has always
 * come from the installation's `product.publisher` setting (#307, #821). The
 * LINK was a fixed address written into `web/_core/api-spec.json` — the
 * product's own source repository — so an installation whose publisher was a
 * different company showed that company's name linking to somebody else's
 * site: a name and a link that disagreed, on a page a developer reads.
 *
 * THE OWNER'S DECISION (D-160, 12 November 2026): the link follows a setting,
 * a website address given during installation, and is LEFT OUT when none is
 * set. No address is built in. Showing the name with no link at all, and a
 * fixed address, were both ruled out.
 *
 * THIS CLASS IS THE ONE PLACE that decides whether a typed or stored address
 * can be that link. The installer asks `check()` when the address is typed;
 * `web/public_html/openapi.php` asks `usable()` every time it serves the
 * description. It is asked AGAIN on reading because the general settings
 * editor (Admin, Settings) can store any text at all in the row, and a value
 * nobody checked must never reach a developer's page as a link.
 *
 * WHAT IT ACCEPTS: an http:// or https:// address with a host name, with or
 * without a folder, page, "?" or "#" after it — a company's "about" page is a
 * perfectly good website link, unlike the portal's own address, which must
 * stop at the name (that is PortalAddress's rule, not this one). A bare host
 * name is taken to mean https, as PortalAddress does.
 *
 * WHAT IT REFUSES, never repairs: a space, line break or other control
 * character; any other beginning (`javascript:`, `data:`, `ftp:` ...); a user
 * name or password; a host name that is not plain letters, digits, hyphens and
 * dots; anything over 255 characters; and, after the host name, any character
 * an address may not carry unencoded (quotes, angle brackets and the like).
 *
 * The host name itself is judged by `PortalAddress::check()`, so there is ONE
 * rule in the portal for what a host name is. This class adds only the part
 * after the host name. It uses nothing else of the portal, which is what lets
 * the installer (which has no autoloader) load it with one `require_once`.
 *
 * WHAT IT CANNOT DO: it cannot tell whether the address really is the
 * publisher's website, or whether anything answers there. It judges the shape
 * of the address and nothing more.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/863
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

final class PublisherWebsite
{
    /** The setting the address is kept in: installation-wide, seeded empty. */
    public const SETTING = 'product.publisherUrl';

    /** The longest address accepted, the same ceiling PortalAddress uses. */
    public const MAX_LENGTH = 255;

    /**
     * Check an address as somebody typed it, and tidy it.
     *
     * @return array{address: string, problem: string} `address` is '' when
     *         nothing was typed or it was refused; `problem` is '' unless it
     *         was refused, and then says why in plain words.
     */
    public static function check(string $typed): array
    {
        $refuse = static fn (string $why): array => ['address' => '', 'problem' => $why];

        $text = trim($typed);
        if ($text === '') {
            return ['address' => '', 'problem' => ''];
        }
        if (strlen($text) > self::MAX_LENGTH) {
            return $refuse('The website address is too long: ' . self::MAX_LENGTH . ' characters is the most the portal accepts.');
        }
        if (preg_match('/[\x00-\x20\x7F]/', $text) === 1) {
            return $refuse('The website address has a space or a line break in it. Type it on one line with no spaces, for example https://www.example.org');
        }

        // ✂️ Split the address into the part that names the server (scheme,
        //    host name and port) and everything after it. The first part is
        //    PortalAddress's question; the rest is this class's.
        $scheme = '';
        $rest   = $text;
        if (preg_match('#^([A-Za-z][A-Za-z0-9+.\-]*)://(.*)$#s', $text, $found) === 1) {
            $scheme = $found[1] . '://';
            $rest   = $found[2];
        }
        $nameLength = strcspn($rest, '/?#');
        $after      = substr($rest, $nameLength);

        $server = PortalAddress::check($scheme . substr($rest, 0, $nameLength));
        if ($server['problem'] !== '') {
            return $refuse($server['problem']);
        }
        if ($server['address'] === '') {
            return $refuse('That is not a website address. Type it in full, for example https://www.example.org');
        }

        // 🔤 After the host name, only the characters an address may carry as
        //    they are (RFC 3986's unreserved and reserved sets, plus "%" for
        //    one already encoded). A quote or an angle bracket here could only
        //    be a mistake, or an attempt to break out of the link it becomes.
        if (preg_match('#^[A-Za-z0-9\-._~:/?\#\[\]@!$&\'()*+,;=%]*$#', $after) !== 1) {
            return $refuse('The website address holds a character that cannot be part of a web address, such as a quotation mark or an angle bracket. Copy it from the address bar of your browser.');
        }

        return ['address' => $server['address'] . $after, 'problem' => ''];
    }

    /**
     * The address to link to, judged again from what is stored: '' when
     * nothing usable is stored, which means "leave the link out".
     *
     * Takes `mixed` because a stored setting may be absent (null) as well as
     * text; anything that is not text is never a link.
     */
    public static function usable(mixed $stored): string
    {
        if (is_string($stored) === false) {
            return '';
        }
        $checked = self::check($stored);

        return $checked['problem'] === '' ? $checked['address'] : '';
    }
}
