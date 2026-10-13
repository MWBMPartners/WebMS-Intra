<?php
// Path: _core/Gatekeeper.php
/**
 * -----------------------------------------------------------------------------
 * Channel Gatekeeper 🚧
 * -----------------------------------------------------------------------------
 * Restricts access to the pre-release channels (alpha, beta and dev copies of
 * the portal, wherever each is hosted) based on user roles. By default only
 * Admins (`isAdmin=1`) or Root Admins (`isRootAdmin=1`) are allowed.
 * Additional roles can be configured via tblSettings:
 *     portal.devAccessRoles     = "Admin,Developer"
 * (comma-separated roleKey values from tblRoles)
 *
 * It stands in front of a pre-release channel however that channel was
 * stated: by the PORTAL_ENV setting, by the `.channel` file, or by the web
 * folder's name. It NEVER stands in front of an installation whose channel
 * nobody stated: that one is assumed to be live (owner's final rule, 12
 * October 2026). See shouldEnforce(), and _core/Channel.php for how the
 * channel is decided.
 * -----------------------------------------------------------------------------
 * Usage from the front controller (web/public_html/index.php):
 *     if (Gatekeeper::shouldEnforce(PORTAL_ENV, PORTAL_ENV_SOURCE) === true) {
 *         Gatekeeper::enforce(PORTAL_ENV);
 *     }
 *     \Portal\Core\Router::dispatch($mysqli);
 * shouldEnforce() is the ONE place that decides whether the gate runs at all.
 *
 * The single front controller is index.php in the web folder, whatever the
 * hosting calls that folder.
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use RuntimeException;

class Gatekeeper
{
    /**
     * The channels this gate stands in front of: the pre-release ones. Read
     * from Channel, which uses the same list to decide where PHP's error text
     * may be shown, so the two cannot drift apart.
     */
    private const VALID_CHANNELS = Channel::PRE_RELEASE;

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
     *   cron/mail-queue   The mail queue's scheduled job (#592). A scheduler
     *                     has no staff sign-in, so the gate sent it to the
     *                     sign-in page (HTTP 302), CARRYING ITS TOKEN into
     *                     that address, and queued mail never went on a
     *                     pre-release copy even with the job added. The page
     *                     checks its own token in constant time, answers 403
     *                     without it, and prints numbers only
     *                     (web/_apps/cron/mail-queue.php), so the gate adds
     *                     nothing in front of it. ONLY THIS JOB is listed.
     *                     Every other scheduled job (cron/health,
     *                     cron/event-reminders, cron/backup-check and the
     *                     rest) is still behind the gate on a pre-release
     *                     copy and cannot be run by a scheduler there. That
     *                     is how they were before this entry, and it was
     *                     deliberately left alone.
     *
     * Letting these through gives nothing away. Each one either enforces its
     * own rules — a wrong code, a wrong passkey, a failed Google sign-in, an
     * invalid invitation or a missing job token gets nobody anywhere — or
     * holds nothing private.
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
        'cron/mail-queue',
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
     * (index.php in the web folder) asks this, and calls enforce() only on yes.
     *
     * Both must be true:
     *   1. The channel is 'dev', 'beta' or 'alpha' (VALID_CHANNELS). The live
     *      channel is never gated.
     *   2. The portal-wide `portal.gatekeeper.enabled` row is not 'false' (in
     *      any letter case, ignoring spaces around it).
     *
     * -------------------------------------------------------------------------
     * HOW THE CHANNEL WAS DECIDED DOES NOT CHANGE THE ANSWER (owner's final
     * rule, 12 October 2026)
     * -------------------------------------------------------------------------
     * A 'beta', 'alpha' or 'dev' channel is gated whether the PORTAL_ENV
     * setting, the `.channel` file or the web folder's name said so. The
     * live channel is never gated, whether it was stated or only assumed.
     * Channel::decide() answers 'prod' when nothing says which channel an
     * installation is, so such an installation is not gated: nobody is
     * locked out for want of a file.
     *
     * WHAT THIS HAS DONE BEFORE, AND WHY EACH WAS REPLACED
     *   - Until 12 October 2026 the channel was guessed from the web folder's
     *     path, a failed guess was 'dev', and the gate stood aside on it.
     *     Every pre-release copy with an unexpected folder name was open,
     *     with PHP's errors on screen.
     *   - The first build of Channel (commit 7b30c99) closed that the other
     *     way: "not set" was a 'dev' copy and this gate ran on it. Uploading
     *     that code to a live portal without a new file refused every member
     *     and everything that arrives without a staff sign-in. The owner
     *     replaced it the same day.
     *
     * WHO GETS THROUGH THE GATE, AND WHO DOES NOT REACH THE UPGRADE PAGE
     * The sign-in pages are on OPEN_PATHS, and anybody with the Admin or
     * Root Admin flag passes enforce(). Passing the gate is not the same as
     * being allowed every page behind it: the database upgrade page
     * (/admin/upgrade) is for a Root Admin only, and the Admin flag alone
     * gets 403 there. (An earlier comment here said either flag reached the
     * upgrade page. It was untrue.)
     *
     * The source is taken for one thing only: when it is 'assumed', one line
     * is written to PHP's error log saying so and saying the cure. It is
     * written here because this is asked once on every request a person or
     * a machine makes (by index.php, and by the API documentation page,
     * which the web server runs by itself), and never by a command-line
     * tool, which cannot know the web folder's name and would otherwise
     * write the line on every run.
     *
     * What this CANNOT do: gate an installation whose `.channel` file wrongly
     * says `live`, one whose pre-release channel was never stated (it is
     * assumed live), or one where the switch below has been set to 'false'.
     * The switch is a global administrator's own decision about the whole
     * installation. Whether error text is shown is decided in bootstrap.php,
     * not here.
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
     * @param string $channelSource PORTAL_ENV_SOURCE: 'environment', 'file',
     *                              'folder' or 'assumed'. It never changes
     *                              the answer; see above.
     *
     * @return bool True when enforce() should run.
     */
    public static function shouldEnforce(string $channel, string $channelSource): bool
    {
        // The same list of channels enforce() accepts, so the two cannot drift.
        // WHAT WAS WRONG: the first version named only 'dev' and 'beta', so
        // PORTAL_ENV = 'alpha' was never gated. That broke the promise in the
        // in-app help (web/_apps/help/admin.php), which says alpha sites are
        // gated and documents portal.alphaAccessRoles for them.
        // The comparison is exact: 'prod', or any value not on the list, is
        // never gated. Channel::decide() only ever produces 'prod' or a value
        // on the list, so "not on the list" means the live channel.
        if (in_array($channel, self::VALID_CHANNELS, true) === false) {
            // 📝 Live only because nothing says otherwise: say so, and say
            //    the cure, where an administrator will look. One line per
            //    request, on purpose: a pre-release copy that has lost its
            //    file is open to the public in this state, and nothing a
            //    visitor sees says so.
            //    The file's place is given as "beside the _core folder",
            //    never as a path, so the line is the same on every host and
            //    gives nothing away.
            if ($channelSource === Channel::SOURCE_ASSUMED) {
                error_log(
                    '[WebMS-Intra] Nothing says which release channel this installation is, so it is being '
                    . 'treated as the LIVE portal: errors hidden, staff sign-in gate off. If it is a pre-release '
                    . 'copy, it is open to the public. To settle it, create a file named ' . Channel::FILE_NAME
                    . ' beside the _core folder holding one word (live, beta, alpha or dev), or set '
                    . Channel::VARIABLE . ' to that word. Where several web folders share one _core folder, do '
                    . 'not create the file: name the folders ' . implode(', ', array_keys(Channel::FOLDER_NAMES))
                    . ', or give each its own ' . Channel::VARIABLE . '.'
                );
            }

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
     * Check the allowed roleKey list against every organisation this user
     * belongs to (#516).
     *
     * WHY "ANYWHERE", NOT "IN THE ORGANISATION OPEN RIGHT NOW": the
     * pre-release channel gate this backs (`portal.devAccessRoles`) is a
     * PORTAL-WIDE setting, not scoped to one organisation — a developer
     * with the right role in ANY one of their organisations should be able
     * to reach a non-production channel, the same as `isAdmin`/
     * `isRootAdmin` already work portal-wide two lines above this call.
     * This is the owner's explicit decision for #516 (plan section 3, Q3),
     * and is why this delegates to `Roles::hasAnywhere()` rather than
     * `Roles::has()`, which would need one particular organisation to be
     * "open" — something a channel gate running before routing even starts
     * cannot assume.
     */
    private static function userHasRole(int $userId, array $roleKeys): bool
    {
        global $mysqli;
        return Roles::hasAnywhere($mysqli, $userId, $roleKeys);
    }
}
