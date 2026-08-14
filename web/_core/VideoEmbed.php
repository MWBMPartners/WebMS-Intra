<?php
// Path: _core/VideoEmbed.php
/**
 * -----------------------------------------------------------------------------
 * Video Embed Helper — provider detection + safe embed URLs 🎬
 * -----------------------------------------------------------------------------
 * Allowlist-based multi-provider video embed helper (YouTube / Vimeo /
 * Cloudflare Stream) built for the Event Team Hub (#386 Phase 1), but
 * deliberately kept generic so `/live`, noticeboard, or recordings can
 * adopt it later. Modelled on the existing `Livestream::embedUrl()`
 * (platform + ID -> URL), extended with `parse()` (pasted URL/ID -> a
 * provider + ref pair) and Cloudflare Stream signed-URL token minting.
 *
 * Every stored primitive (provider, ref, signed token) is re-validated by
 * character class immediately before it is interpolated into an embed
 * URL — no raw pasted URL is ever placed directly into an iframe src.
 *
 * Cloudflare Stream uses TWO distinct credentials (kept separate on
 * purpose — see the admin page at `admin/integrations/cloudflare-stream`):
 *   - `cfstream.signingKeyID` / `cfstream.signingKeyPem` — used HERE, to
 *     mint short-lived RS256 playback tokens for `requireSignedURLs`
 *     videos. Never leaves the server; only the resulting JWT reaches the
 *     browser (inside an iframe src, itself never logged).
 *   - `cfstream.apiToken` — the Cloudflare *management* API credential
 *     (mint/poll/edit/delete uploads). NOT used by this class — that's
 *     `Portal\Core\CloudflareStream`, a Phase 1.5 follow-up (#386).
 *
 * `_vendor/simplejwt/JWT.php` is verify-only (used for MS365/Google ID
 * tokens) — it has no encode/sign capability, so RS256 signing is done
 * directly here with `openssl_sign()` rather than extending that vendored
 * library (which is scoped as an IdP-token verifier).
 *
 * Public methods:
 *   VideoEmbed::parse($input)                          -> ?array{provider,ref}
 *   VideoEmbed::embedUrl($provider, $ref, $signedToken) -> ?string
 *   VideoEmbed::signedToken($videoUid, $ttlSeconds)     -> ?string
 *   VideoEmbed::frameSrcOrigins($videos)                -> string
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/386
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class VideoEmbed
{
    /** @var string Character class for a YouTube video ID. */
    private const YOUTUBE_ID_RE = '/^[A-Za-z0-9_-]{11}$/';

    /** @var string Character class for a Vimeo numeric video ID. */
    private const VIMEO_ID_RE = '/^\d+$/';

    /** @var string Character class for a Cloudflare Stream UID (32-hex). */
    private const CF_UID_RE = '/^[0-9a-f]{32}$/';

    /** @var string Character class for a Cloudflare customer subdomain code. */
    private const CF_CODE_RE = '/^[a-z0-9-]+$/';

    /* ====================================================================== */
    /* Provider detection                                                     */
    /* ====================================================================== */

    /**
     * Allowlist-detect a video provider + reference from pasted input —
     * either a full URL (several shapes per provider) or a bare ID/UID.
     * Anything that doesn't match a known shape returns null: we NEVER
     * store or embed an arbitrary pasted URL.
     *
     * @param string $input Raw pasted text from the "add video" form
     *
     * @return array{provider:string,ref:string}|null
     */
    public static function parse(string $input): ?array
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }

        // 🎥 YouTube — watch/shorts/live/embed URLs and youtu.be short links,
        //    all funnelling to an 11-character video ID.
        if (preg_match(
            '#youtube(?:-nocookie)?\.com/(?:watch\?(?:.*&)?v=|shorts/|live/|embed/)([A-Za-z0-9_-]{11})'
            . '|youtu\.be/([A-Za-z0-9_-]{11})#i',
            $input,
            $m
        ) === 1) {
            $ref = $m[1] !== '' ? $m[1] : $m[2];
            return ['provider' => 'youtube', 'ref' => $ref];
        }

        // 🎬 Vimeo — vimeo.com/<id> or player.vimeo.com/video/<id>.
        if (preg_match('#(?:player\.)?vimeo\.com/(?:video/)?(\d+)#i', $input, $m) === 1) {
            return ['provider' => 'vimeo', 'ref' => $m[1]];
        }

        // ☁️ Cloudflare Stream — UID embedded in one of the three hostnames
        //    Cloudflare has used for playback/watch URLs over time.
        if (preg_match(
            '#(?:customer-[a-z0-9]+\.cloudflarestream\.com|watch\.cloudflarestream\.com|iframe\.videodelivery\.net)/'
            . '([0-9a-fA-F]{32})#i',
            $input,
            $m
        ) === 1) {
            return ['provider' => 'cloudflare', 'ref' => strtolower($m[1])];
        }

        // 🔢 No recognisable host — try a bare ID/UID, disambiguated by shape.
        //    Cloudflare's fixed 32-hex length is checked first so it can
        //    never be mistaken for anything else.
        if (preg_match(self::CF_UID_RE, strtolower($input)) === 1) {
            return ['provider' => 'cloudflare', 'ref' => strtolower($input)];
        }
        if (preg_match(self::VIMEO_ID_RE, $input) === 1) {
            return ['provider' => 'vimeo', 'ref' => $input];
        }
        if (preg_match(self::YOUTUBE_ID_RE, $input) === 1) {
            return ['provider' => 'youtube', 'ref' => $input];
        }

        return null;
    }

    /* ====================================================================== */
    /* Embed URL builders                                                     */
    /* ====================================================================== */

    /**
     * Build a safe iframe embed URL for the given provider + reference.
     * Returns null for an unrecognised provider, an invalid ref, or (for
     * Cloudflare) a missing/invalid `cfstream.customerCode` setting — the
     * caller renders an "unavailable" tile instead of a broken iframe.
     *
     * @param string      $provider    'youtube' | 'vimeo' | 'cloudflare'
     * @param string      $ref         The stored videoRef (never raw user input)
     * @param string|null $signedToken Pre-minted signed playback token
     *                                 (cloudflare only — see self::signedToken()).
     *                                 Falls back to the bare UID when null,
     *                                 which only works for videos that do
     *                                 NOT require signed URLs on the CF side.
     *
     * @return string|null
     */
    public static function embedUrl(string $provider, string $ref, ?string $signedToken = null): ?string
    {
        return match ($provider) {
            'youtube'    => self::youtubeEmbedUrl($ref),
            'vimeo'      => self::vimeoEmbedUrl($ref),
            'cloudflare' => self::cloudflareEmbedUrl($ref, $signedToken),
            default      => null,
        };
    }

    private static function youtubeEmbedUrl(string $ref): ?string
    {
        if (preg_match(self::YOUTUBE_ID_RE, $ref) !== 1) {
            return null;
        }
        // youtube-nocookie.com — privacy-enhanced mode, no tracking cookie
        // until the visitor actually presses play.
        return 'https://www.youtube-nocookie.com/embed/' . $ref . '?rel=0&modestbranding=1';
    }

    private static function vimeoEmbedUrl(string $ref): ?string
    {
        if (preg_match(self::VIMEO_ID_RE, $ref) !== 1) {
            return null;
        }
        // dnt=1 — Vimeo "Do Not Track" player flag.
        return 'https://player.vimeo.com/video/' . $ref . '?dnt=1';
    }

    private static function cloudflareEmbedUrl(string $ref, ?string $signedToken): ?string
    {
        // 🛡️ Re-validate the ref char-class immediately before use — the
        //    same discipline as Livestream::embedUrl()'s ID sanitiser.
        if (preg_match(self::CF_UID_RE, $ref) !== 1) {
            return null;
        }

        $code = (string) Settings::get('cfstream.customerCode', '');
        if ($code === '' || preg_match(self::CF_CODE_RE, $code) !== 1) {
            // Not configured — caller renders the "unavailable" tile.
            return null;
        }

        $pathSegment = $ref;
        if ($signedToken !== null) {
            // 🛡️ A signed token is a three-part base64url JWT. Re-validate
            //    the shape before interpolation even though we only ever
            //    pass through our own freshly-minted value.
            if (preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $signedToken) !== 1) {
                return null;
            }
            $pathSegment = $signedToken;
        }

        return 'https://customer-' . $code . '.cloudflarestream.com/' . $pathSegment . '/iframe';
    }

    /* ====================================================================== */
    /* Cloudflare Stream signed playback tokens                               */
    /* ====================================================================== */

    /**
     * Mint (or return a cached) RS256 signed-URL playback token for a
     * Cloudflare Stream video. Returns null — never throws — when the
     * signing key isn't configured or signing fails, so the page can fall
     * back to an "unavailable — check Stream settings" tile rather than a
     * broken iframe. The private key and account details never leave the
     * server; only the resulting short-lived JWT reaches the browser.
     *
     * Caching: a per-request static memo plus a `$_SESSION` cache keyed by
     * UID, reused while more than 1/10th of the TTL remains. RSA-2048
     * signing is roughly 1ms so this is belt-and-braces, not load-bearing.
     *
     * @param string   $videoUid   Cloudflare Stream 32-hex video UID
     * @param int|null $ttlSeconds Override for `cfstream.tokenTtlSeconds`
     *
     * @return string|null Signed JWT, or null on missing/invalid key
     */
    public static function signedToken(string $videoUid, ?int $ttlSeconds = null): ?string
    {
        if (preg_match(self::CF_UID_RE, $videoUid) !== 1) {
            return null;
        }

        // 🗄️ Per-request memo — avoids re-signing the same UID twice on
        //    one page render (e.g. thumbnail + player both need it).
        static $memo = [];
        if (isset($memo[$videoUid]) === true) {
            return $memo[$videoUid];
        }

        $ttl = $ttlSeconds ?? (int) Settings::get('cfstream.tokenTtlSeconds', 21600);
        if ($ttl <= 0) {
            $ttl = 21600;
        }

        // 🗄️ Session cache — reused while more than TTL/10 remains, so a
        //    page reload mid-session doesn't re-sign on every request.
        Auth::ensureSession();
        $cached = $_SESSION['cfstream_tokens'][$videoUid] ?? null;
        if (
            is_array($cached) === true
            && isset($cached['t'], $cached['exp']) === true
            && is_string($cached['t']) === true
            && ((int) $cached['exp'] - time()) > (int) ($ttl / 10)
        ) {
            $memo[$videoUid] = $cached['t'];
            return $memo[$videoUid];
        }

        $keyId  = (string) Settings::get('cfstream.signingKeyID', '');
        $keyPem = (string) Settings::get('cfstream.signingKeyPem', '');
        if ($keyId === '' || $keyPem === '') {
            Logger::errorPlatform(
                'VideoEmbed',
                'Warning',
                'CFSTREAM_KEY_MISSING',
                'Cloudflare Stream signing key not configured',
                'uid=' . $videoUid
            );
            return null;
        }

        $exp = time() + $ttl;

        // 🔐 Build + sign a compact RS256 JWT by hand — simplejwt is
        //    verify-only (see class docblock).
        $header = self::base64UrlEncode((string) json_encode([
            'alg' => 'RS256',
            'kid' => $keyId,
        ], JSON_UNESCAPED_SLASHES));
        $payload = self::base64UrlEncode((string) json_encode([
            'sub'          => $videoUid,
            'kid'          => $keyId,
            'exp'          => $exp,
            'downloadable' => false,
        ], JSON_UNESCAPED_SLASHES));
        $signingInput = $header . '.' . $payload;

        $signature = '';
        $signed = openssl_sign($signingInput, $signature, $keyPem, OPENSSL_ALGO_SHA256);
        if ($signed === false) {
            // ⚠️ Never log the PEM, the signing input, or any partial
            //    signature — only the fact that signing failed + the UID.
            Logger::errorPlatform(
                'VideoEmbed',
                'Error',
                'CFSTREAM_SIGN_FAIL',
                'Cloudflare Stream token signing failed',
                'uid=' . $videoUid . ' opensslError=' . (string) openssl_error_string()
            );
            return null;
        }

        $token = $signingInput . '.' . self::base64UrlEncode($signature);

        $_SESSION['cfstream_tokens'][$videoUid] = ['t' => $token, 'exp' => $exp];
        $memo[$videoUid] = $token;

        return $token;
    }

    /**
     * Base64url-encode per RFC 4648 §5 (no padding, URL-safe alphabet) —
     * the JWT-standard encoding for the header/payload/signature segments.
     */
    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /* ====================================================================== */
    /* CSP helper                                                             */
    /* ====================================================================== */

    /**
     * Build the distinct `frame-src` origin list a page needs to embed the
     * given set of videos — for assignment to `$cspFrameExtra` BEFORE
     * `header.php` is required (page-scoped CSP extension, never touches
     * the base policy).
     *
     * @param list<array{provider?:string}> $videos Rows with at least a 'provider' key
     *
     * @return string Space-separated origin list (possibly empty)
     */
    public static function frameSrcOrigins(array $videos): string
    {
        $origins = [];

        foreach ($videos as $video) {
            $provider = (string) ($video['provider'] ?? '');
            switch ($provider) {
                case 'youtube':
                    $origins['https://www.youtube-nocookie.com'] = true;
                    break;
                case 'vimeo':
                    $origins['https://player.vimeo.com'] = true;
                    break;
                case 'cloudflare':
                    $code = (string) Settings::get('cfstream.customerCode', '');
                    if ($code !== '' && preg_match(self::CF_CODE_RE, $code) === 1) {
                        $origins['https://customer-' . $code . '.cloudflarestream.com'] = true;
                    }
                    break;
                default:
                    // 🚫 Unknown provider — no origin added (nothing to embed).
                    break;
            }
        }

        return implode(' ', array_keys($origins));
    }
}
