<?php
// Path: _core/ApiDescriptionAccess.php
/**
 * -----------------------------------------------------------------------------
 * Portal Core — ApiDescriptionAccess 🔐
 * -----------------------------------------------------------------------------
 * The ONE rule for who may read the description of the portal's API (D-215,
 * #1131). Two addresses ask it:
 *
 *   /openapi.json  — the machine-readable description of every API address and
 *                    field. Answered to a signed-in administrator, or to a
 *                    request carrying a valid API key (any scope) in the header
 *                    `Authorization: Bearer wbms_…`. A key that is sent but is
 *                    not valid gets 401 with the API's own words. Everybody
 *                    else gets the portal's ordinary 404 page, the same answer
 *                    as an address that does not exist.
 *   /api-docs      — the browsable page. Signed-in administrators ONLY. A
 *                    signed-out visitor is sent to sign in and brought back; a
 *                    signed-in person who is not an administrator gets the 404
 *                    page. A key sent to the page is ignored: a browser that
 *                    opens a page cannot send one, so a key holder reads the
 *                    description from /openapi.json instead.
 *
 * WHY IT TAKES FACTS. verdict() takes five plain yes/no facts and answers a
 * word, with no request, session or database, so the self-test can walk every
 * combination without a server and the page, the description and the test
 * cannot disagree (the same shape as #647 and #668). enforce() is the only
 * part that reads the request.
 *
 * WHAT "ADMINISTRATOR" MEANS. App::isAdmin(), the gate of the API keys pages:
 * the people who can make and hand out keys are the people who may read what a
 * key reaches. It includes an organisation's own administrator. It is not a
 * capability on purpose: D-215 names the audience, and a capability would let
 * an organisation point the page at a non-administrator role, which D-215 rules
 * out. Auth::check() is already false for a person still waiting for their
 * second sign-in step and for a switched-off account, so neither is re-tested.
 *
 * STATED LIMITS (what this cannot do):
 *   • Any valid key reads the description, whatever its scope, and a key
 *     belonging to a switched-off organisation still does. The API itself
 *     refuses such a key, but the description is the same for every
 *     organisation and reveals no organisation's data, so checking it here
 *     would be code with no protection.
 *   • There is no rate limit on the key path. The API's limit is per key so it
 *     would not slow guessing anyway; a key is 128 random bits, so guessing is
 *     not a realistic route; and the answer is a fixed document.
 *   • Reading it stamps the key's `lastUsedAt` (ApiKey::findByPlaintext() does
 *     that for every lookup), which tells the administrator the key was used.
 *   • On a pre-release copy the staff gate answers first, so a key holder
 *     cannot read the description there, exactly as for every /api/ address.
 *   • No logging: a refused read is not an event worth recording, and a key
 *     path that logged would put a stranger's guesses in the activity log.
 *
 * Call enforce() after start-up, the session, the maintenance gate and the
 * staff gate, and before anything is printed.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/1131
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

final class ApiDescriptionAccess
{
    /** Show it. */
    public const ALLOW = 'allow';

    /** The page only: send a signed-out visitor to sign in, and back. */
    public const SIGN_IN = 'sign-in';

    /** The portal's ordinary 404 page. */
    public const NOT_FOUND = 'not-found';

    /** The description only: 401, in the API's own words. */
    public const BAD_KEY = 'bad-key';

    /**
     * The rule, from facts.
     *
     * @param bool $forThePage     true for /api-docs, false for /openapi.json
     * @param bool $keySent        an `Authorization: Bearer wbms_…` header arrived
     * @param bool $keyValid       … and it names a usable key
     * @param bool $signedIn       a person is signed in (Auth::check())
     * @param bool $administrator  … and App::isAdmin() says yes
     * @return string one of the four constants above
     */
    public static function verdict(
        bool $forThePage,
        bool $keySent,
        bool $keyValid,
        bool $signedIn,
        bool $administrator
    ): string {
        // 🔑 The description: a key that was SENT decides alone, as on every
        //    API address (the two ways in never mix). The page ignores keys.
        if ($forThePage === false && $keySent === true) {
            return $keyValid === true ? self::ALLOW : self::BAD_KEY;
        }
        if ($signedIn === true && $administrator === true) {
            return self::ALLOW;
        }
        if ($forThePage === true && $signedIn === false) {
            return self::SIGN_IN;
        }
        return self::NOT_FOUND;
    }

    /**
     * Ask the request and answer it when the answer is no. Returns only on
     * ALLOW; every other verdict ends the request here.
     */
    public static function enforce(bool $forThePage): void
    {
        $keySent  = $forThePage === false && ApiAuth::isBearer() === true;
        $keyValid = $keySent === true && ApiAuth::bearerKeyRow() !== null;
        // Ask about the session only when no key was sent: a program sending a
        // key needs no session and the answer would change nothing.
        $signedIn      = $keySent === false && Auth::check() === true;
        $administrator = $signedIn === true && App::isAdmin() === true;

        $verdict = self::verdict($forThePage, $keySent, $keyValid, $signedIn, $administrator);
        if ($verdict === self::ALLOW) {
            return;
        }
        if ($verdict === self::SIGN_IN) {
            Auth::requireLogin();
            exit(); // requireLogin() exits; this is the backstop.
        }
        if ($verdict === self::BAD_KEY) {
            ApiResponse::error('Invalid or expired API key', 401);
        }
        // Router::renderError() RETURNS, so the exit is load-bearing.
        Router::renderError(404);
        exit();
    }
}
