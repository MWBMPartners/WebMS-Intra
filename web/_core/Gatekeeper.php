<?php
// Path: _core/Gatekeeper.php
/**
 * -----------------------------------------------------------------------------
 * Channel Gatekeeper 🚧
 * -----------------------------------------------------------------------------
 * Restricts access to non-production channels (alpha / beta deploys served from
 * the server's public_html_dev/ or public_html_beta/ directories) based on user
 * roles. By default only Admins (`isAdmin=1`) or Root Admins (`isRootAdmin=1`)
 * are allowed. Additional roles can be configured via tblSettings:
 *     portal.devAccessRoles     = "Admin,Developer"
 * (comma-separated roleKey values from tblRoles)
 *
 * The primary channel is 'dev'. The legacy 'alpha' and 'beta' channels are
 * retained in VALID_CHANNELS for backwards compatibility.
 * -----------------------------------------------------------------------------
 * Usage from the front controller (web/public_html/index.php):
 *     if (Gatekeeper::shouldEnforce(PORTAL_ENV, PORTAL_ENV_SOURCE) === true) {
 *         Gatekeeper::enforce(PORTAL_ENV);
 *     }
 *     \Portal\Core\Router::dispatch($mysqli);
 * shouldEnforce() is the ONE place that decides whether the gate runs at all.
 *
 * The single front controller lives at public_html/index.php in the repo;
 * branch-based deploy maps it to the appropriate server-side directory.
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use RuntimeException;

class Gatekeeper
{
    /**
     * Channels that can bypass individual route auth (they handle it at the gate).
     */
    private const VALID_CHANNELS = ['alpha', 'beta', 'dev'];

    /**
     * Exact addresses that stay reachable without passing through the gate.
     *
     * -------------------------------------------------------------------------
     * WHY EVERY ONE OF THESE IS HERE, AND WHY THE LIST GREW
     * -------------------------------------------------------------------------
     * The gate's whole job is "only let staff in". It can only do that if a
     * member of staff can finish signing in FIRST — otherwise the gate locks
     * out the very people it exists to let through.
     *
     * The original list covered the password form and the Microsoft sign-in
     * round trip. It missed three parts of the sign-in flow that have been
     * added since, and each of the three was a complete lock-out, not a
     * nuisance:
     *
     *   auth/2fa/verify   The second-step code page. Between typing a correct
     *                     password and typing the code, the portal does NOT yet
     *                     consider the person signed in (verify.php sets
     *                     $_SESSION['user_id'] only once the code is right —
     *                     until then it holds '2fa_user_id' instead). Without
     *                     this entry the gate sees "not signed in", sends them
     *                     back to the sign-in form, they sign in again, and
     *                     land here again. An endless loop, and it would have
     *                     hit every administrator who uses two-step sign-in —
     *                     which is the security-conscious ones.
     *
     *   login/webauthn    Signing in with a passkey or fingerprint. Same
     *                     situation: the exchange happens before the portal
     *                     knows who you are.
     *
     *   login/google      The Google sign-in round trip, the exact twin of the
     *   login/google/     Microsoft one that was already listed. It was simply
     *   callback          forgotten when Google sign-in was added.
     *
     *   auth/invite       Accepting an invitation. The person does not have an
     *                     account yet, so they cannot sign in first: this page
     *                     is how they get one. It is ONE address for both
     *                     halves of the job, because the form posts back to the
     *                     same address (/auth/invite?token=...), so there is no
     *                     separate "accept" address to list. The page refuses
     *                     anybody without a valid, unused, unexpired invitation
     *                     token (web/_apps/invites/accept.php). Once the account
     *                     exists it sends the person to "/", which IS gated, so
     *                     an invited ordinary member still meets the gate
     *                     there. An invitation opens the sign-up page, not the
     *                     channel.
     *
     *   offline           The "you are offline" notice. The browser's offline
     *                     helper (web/public_html/sw.js) keeps a copy of it to
     *                     show when there is no network. It holds nothing
     *                     private, so gating it protects nothing and only
     *                     breaks the notice for anybody not signed in.
     *
     * Letting these through gives nothing away. Each one either enforces its
     * own rules — a wrong code, a wrong passkey, a failed Google sign-in or an
     * invalid invitation gets nobody anywhere — or holds nothing private.
     *
     * WHY THE BARE SITE ADDRESS ("/") IS NOT ON THIS LIST ANY MORE
     * The first version listed '' — what "/" becomes once the slashes are
     * trimmed — as open. But Router::handleSpecialRoutes() turns '' into
     * 'dashboard' AFTER this gate has run, so "/" showed the dashboard to
     * anybody, walking straight past the gate that stopped "/dashboard". "/" is
     * now gated exactly as "/dashboard" is.
     *
     * These are ADDRESSES, the thing a visitor types, not file paths. Getting
     * that wrong has bitten this project before: the maintenance page's own
     * allow list contained 'auth/login', which is a file path and not an
     * address anybody can reach, so administrators were locked out during every
     * upgrade. Check any addition against tblRoutes, not against the folders.
     */
    private const OPEN_PATHS = [
        'login',
        'login/ms365',
        'login/ms365/callback',
        'login/google',
        'login/google/callback',
        'login/webauthn',
        'auth/2fa/verify',
        'auth/invite',
        'logout',
        'health',
        'offline',
        'forgot-password',
        'forgot-password/save',
        'reset-password',
        'reset-password/save',
    ];

    /**
     * Path prefixes that bypass the gate (e.g. help centre, public API docs).
     */
    private const OPEN_PREFIXES = [
        'help',
    ];

    /**
     * Enforce gate for given channel (dev|alpha|beta).
     */
    public static function enforce(string $channel): void
    {
        if (in_array($channel, self::VALID_CHANNELS, true) === false) {
            throw new RuntimeException('Invalid channel for gatekeeper.');
        }

        // 🔓 Allow login, auth, and public routes through without restriction
        $path = Router::extractPath();
        if (in_array($path, self::OPEN_PATHS, true) === true) {
            return;
        }
        foreach (self::OPEN_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/') === true) {
                return;
            }
        }

        Auth::ensureSession();
        if (Auth::check() === false) {
            Auth::requireLogin(); // redirects
        }

        global $mysqli;
        $userId = (int) $_SESSION['user_id'];

        // 1. Administrators named on the user record are always allowed.
        //
        //    WHAT WAS WRONG: the two flags were compared with === '1'. A
        //    prepared statement's get_result() hands TINYINT columns back as
        //    PHP whole numbers, so the flag arrived as 1, and 1 === '1' is
        //    false. Nobody passed, root administrators included, so switching
        //    the gate on would have locked every administrator out of the
        //    channel. (Checked against a real MySQL 8.0.36 server: the column
        //    came back as int(1).) Casting to a whole number first gives the
        //    right answer whichever form the database driver uses.
        //
        //    WHICH FLAGS COUNT, AND WHY THE SITE-LEVEL ONES DO NOT
        //    Only isAdmin and isRootAdmin on the person's own tblUsers row. The
        //    in-app help (web/_apps/help/admin.php, "Dev Site Access
        //    (Gatekeeper)") promises exactly that: "By default, only Admin and
        //    Root Admin users are allowed", and the section just before it
        //    names those as the two flags stored on the user record. An
        //    administrator of one organisation (isSiteAdmin / isSiteRootAdmin,
        //    in tblUserSites) is therefore NOT let through by this step. A
        //    pre-release channel is the whole installation's unfinished code,
        //    not one organisation's data, and being in charge of one
        //    organisation does not make somebody staff for the installation.
        //    If such a person does need access, step 2 below grants it by
        //    role, deliberately.
        $row  = null;
        $stmt = $mysqli->prepare('SELECT isRootAdmin, isAdmin FROM tblUsers WHERE userID = ? LIMIT 1');
        if ($stmt !== false) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }

        if (is_array($row) === true
            && ((int) $row['isRootAdmin'] === 1 || (int) $row['isAdmin'] === 1)
        ) {
            return; // permit
        }

        // 2. Additional roles, from the setting portal.{channel}AccessRoles
        //    (for example portal.betaAccessRoles = "Tester,Developer").
        //    Read from the PORTAL-WIDE row only, for the same reason as the
        //    on/off switch (see shouldEnforce()): otherwise an administrator of
        //    one organisation could add a row of their own naming an ordinary
        //    role, and open the pre-release copy to all their members. Reading
        //    the row directly also returns plain text, never a nested list, so
        //    explode() below cannot be handed an array.
        $rolesCsv = self::portalWideSetting('portal.' . $channel . 'AccessRoles') ?? '';
        if ($rolesCsv !== '') {
            $allowedRoles = array_map('trim', explode(',', $rolesCsv));
            if (self::userHasRole($userId, $allowedRoles) === true) {
                return;
            }
        }

        // 3. 🚫 Deny -- log and show the 403 error page
        Logger::activity('GatekeeperDenied', 'User denied to ' . $channel . ' area', $userId);
        Router::renderError(403);
        exit();
    }

    /**
     * Should the gate run on this request at all? The front controller
     * (web/public_html/index.php) asks this, and calls enforce() only on yes.
     *
     * All three must be true:
     *   1. The channel is 'dev', 'beta' or 'alpha' (VALID_CHANNELS). The live
     *      channel is never gated.
     *   2. The channel was decided deliberately: PORTAL_ENV_SOURCE is
     *      'environment' (the PORTAL_ENV environment variable) or 'folder' (a
     *      recognised web folder name) — never 'fallback'.
     *   3. The portal-wide `portal.gatekeeper.enabled` row is not 'false' (in
     *      any letter case, ignoring spaces around it).
     *
     * -------------------------------------------------------------------------
     * WHY A FALLBACK CHANNEL IS NOT GATED
     * -------------------------------------------------------------------------
     * bootstrap.php settles on 'dev' when it recognises neither an environment
     * variable nor a folder name. On a LIVE server whose web folder simply has
     * an unexpected name, that guess is wrong, and gating on it would ask every
     * ordinary member of the live site to be staff. A guess is not a good enough
     * reason to lock people out, so the gate declines — and says so in PHP's
     * error log, one line per request, so that a genuine pre-release copy left
     * ungated by this is noticed rather than silent. The cure for that copy is
     * to set PORTAL_ENV.
     *
     * What this CANNOT do: tell a live server with an odd folder name from a
     * pre-release copy with an odd folder name. From here they look identical,
     * which is exactly why it refuses to guess. Step 2 of the build plan
     * replaces folder-name guessing with an explicit channel file.
     *
     * -------------------------------------------------------------------------
     * WHY ONLY THE PORTAL-WIDE ROW OF THE SWITCH COUNTS
     * -------------------------------------------------------------------------
     * App::settings() lets a row saved for one organisation override the
     * portal-wide row of the same name, and an administrator of one
     * organisation is allowed to add rows for their own organisation. Read that
     * way, such an administrator could add `portal.gatekeeper.enabled = false`
     * and open the pre-release copy to anybody arriving at their
     * organisation's address. Whether a copy of the portal is gated is a
     * decision about the whole installation, so only the portal-wide row —
     * which only a global administrator can change — is read.
     *
     * @param string $channel       PORTAL_ENV.
     * @param string $channelSource PORTAL_ENV_SOURCE: 'environment', 'folder'
     *                              or 'fallback'. Anything else is treated as
     *                              'fallback'.
     *
     * @return bool True when enforce() should run.
     */
    public static function shouldEnforce(string $channel, string $channelSource): bool
    {
        // The same list of channels enforce() accepts, so the two cannot drift.
        // WHAT WAS WRONG: the first version named only 'dev' and 'beta', so
        // PORTAL_ENV = 'alpha' was never gated. That broke the promise in the
        // in-app help (web/_apps/help/admin.php), which says alpha sites are
        // gated and documents portal.alphaAccessRoles for them. bootstrap.php
        // only ever produces 'alpha' from the PORTAL_ENV environment variable,
        // so an alpha channel is always a deliberate choice, never a guess.
        // The comparison is exact: 'prod', or any value not on the list, is
        // never gated.
        if (in_array($channel, self::VALID_CHANNELS, true) === false) {
            return false;
        }

        // Switched off on purpose: nothing to explain in the log.
        // Capital letters and spaces around the word are ignored, so 'False' or
        // ' FALSE ' also switch the gate off. WHAT WAS WRONG: only the exact
        // lower-case 'false' counted, so an administrator who typed 'False'
        // left the gate on and nothing said why. Any other value, including an
        // empty one, a typing mistake or a missing row, leaves the gate on.
        // That is the side that cannot open a pre-release copy by accident.
        $switch = self::portalWideSetting('portal.gatekeeper.enabled');
        if ($switch !== null && strtolower(trim($switch)) === 'false') {
            return false;
        }

        if ($channelSource !== 'environment' && $channelSource !== 'folder') {
            error_log(
                '[WebMS-Intra] Pre-release gate NOT applied: channel "' . $channel
                . '" was only a fallback guess (PORTAL_ENV_SOURCE "' . $channelSource
                . '"), not set by the PORTAL_ENV environment variable or a recognised '
                . 'web folder name. If this is a pre-release copy, set PORTAL_ENV.'
            );
            return false;
        }

        return true;
    }

    /**
     * One setting's PORTAL-WIDE value — the row with no organisation against
     * it — ignoring any row of the same name saved for one organisation. See
     * shouldEnforce() for why the gate reads only this row.
     *
     * Never throws. A setting that cannot be read (a database problem, or a
     * database from before migration 193) comes back as null, which the
     * callers turn into their seeded behaviour: the gate stays on, and no
     * extra roles are allowed.
     *
     * @param string $settingKey The full setting name.
     *
     * @return string|null The value, or null when there is no such row.
     */
    private static function portalWideSetting(string $settingKey): ?string
    {
        try {
            $stmt = App::db()->prepare(
                'SELECT settingValue FROM tblSettings WHERE settingKey = ? AND siteID IS NULL LIMIT 1'
            );
            if ($stmt === false) {
                return null;
            }
            $stmt->bind_param('s', $settingKey);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } catch (\Throwable $problem) {
            return null;
        }

        if (is_array($row) === false || $row['settingValue'] === null) {
            return null;
        }
        return (string) $row['settingValue'];
    }

    /**
     * Check tblUserRoles against allowed roleKey list.
     */
    private static function userHasRole(int $userId, array $roleKeys): bool
    {
        if (count($roleKeys) === 0) {
            return false;
        }

        global $mysqli;

        $placeholders = implode(',', array_fill(0, count($roleKeys), '?'));
        $types        = 'i' . str_repeat('s', count($roleKeys));
        $sql          = 'SELECT 1 FROM tblUserRoles UR '
                      . 'JOIN tblRoles R ON R.roleID = UR.roleID '
                      . 'WHERE UR.userID = ? AND R.roleKey IN (' . $placeholders . ') LIMIT 1';

        $stmt = $mysqli->prepare($sql);
        if ($stmt === false) {
            return false;
        }

        // Build bind params dynamically
        $bindParams = array_merge([$types], [$userId], $roleKeys);
        $ref        = [];
        foreach ($bindParams as $k => $_unused) {
            $ref[$k] = &$bindParams[$k];
        }
        call_user_func_array([$stmt, 'bind_param'], $ref);

        $stmt->execute();
        $stmt->store_result();
        $has = $stmt->num_rows > 0;
        $stmt->close();

        return $has;
    }
}
