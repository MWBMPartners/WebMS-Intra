<?php
// Path: _core/SelfServicePassword.php
/**
 * -----------------------------------------------------------------------------
 * Adding a password to your own account 🔑 (#731)
 * -----------------------------------------------------------------------------
 * THE ONE PLACE that decides whether somebody may give their own account a
 * password, and the ONE place that records and reads the proof that they have
 * just shown who they are.
 *
 * WHY IT EXISTS
 * -----------------------------------------------------------------------------
 * A member whose account signs in ONLY through Microsoft, Google or a passkey
 * had no route at all to a password. Measured rather than guessed:
 *
 *   - `Auth::createUser()` (the first-time single-sign-on path) creates rows in
 *     `tblUsers` and `tblUserSites` and NO `tblLocalAccounts` row at all, so
 *     such an account has no sign-in name and no password.
 *   - `_apps/auth/forgot-password/save.php` joins `tblLocalAccounts`, so it
 *     finds nothing for such an account and no reset link is ever sent.
 *   - `_apps/auth/account/change-password.php` changes a password that is
 *     already there; there is none to change.
 *
 * So if the identity provider is unreachable, or the person loses the Microsoft
 * or Google account, or the only device holding the passkey is lost, they
 * cannot get in — and an administrator may not type a password for them
 * either, which is a decision the owner took deliberately (D-018, #586): a
 * second way into an account must never be chosen by somebody who is not its
 * owner.
 *
 * This class is the owner's route: the account's OWNER adds the password, and
 * only after proving, by the method they really have, that they are still the
 * person at the keyboard.
 *
 * WHY FACTS RATHER THAN A CONNECTION
 * -----------------------------------------------------------------------------
 * `whyNotAllowed()` takes an array of facts, exactly as
 * `ExpenseRules::whyNotEditable()` does (#647), for the same reason: the page
 * that draws the form and the handler that stores the password must not be able
 * to disagree. The handler asks TWICE — once cheaply, and once on facts read
 * inside the transaction that writes — and both times it asks here.
 *
 * `factsFor()` is the only thing in this class that touches a database.
 *
 * THE SETTING, AND WHY THE MERGED ARRAY CANNOT ANSWER IT
 * -----------------------------------------------------------------------------
 * `auth.password.selfAdd` says whether this is offered at all. **The STRICTER
 * level wins** (D-026, which quotes D-013): one installation may serve many
 * organisations, and whoever administers the installation may be a DIFFERENT
 * PERSON from the administrators of the organisations on it. `false` is the
 * stricter value here, because allowing it creates a second way into an
 * account. So it is allowed only when the installation AND the organisation
 * both allow it.
 *
 * `$SETTINGS` has ALREADY merged the two by the time any page runs, and the
 * merge lets an organisation's row WIN. That is right for an ordinary setting
 * and wrong for this one, so the installation-wide layer is read on its own
 * through `Portal\Core\InstallationSettings::layer()`.
 *
 * It needs no query, for the same reason `SettingLimit` needs none: where an
 * organisation has its own row the merged value IS that row, and where it has
 * none the merged value IS the installation's. "Both say yes" is therefore
 * exactly "the merged value says yes AND the installation's layer says yes".
 *
 * **A VALUE MISSING FROM THE INSTALLATION'S LAYER COUNTS AS NO.** That is
 * deliberate and it is the direction D-013 names: resolving "not chosen" to the
 * built-in default would make the default cap the installation, so an
 * installation could lower this and never raise it. The cost is stated plainly:
 * between the moment this version's files reach a server and the moment
 * somebody presses "Run migrations", the feature is switched off. That is
 * recoverable; the other direction is not.
 *
 * It ships ALLOWED (`'1'`), because this is a RECOVERY route and #499's "fail
 * closed" was built first and locked members out.
 *
 * WHAT IT CANNOT DO, said plainly because an overstated guarantee is worse than
 * none
 * -----------------------------------------------------------------------------
 *   - **It does not store the password.** `Auth::setPassword()` is the one
 *     place that hashes and writes one (#728), and it keeps its own rules about
 *     sign-in names and about an account that somehow has two sets of sign-in
 *     details. This class decides WHETHER, never HOW.
 *   - **It does not decide who may re-authenticate, or how.** The proof is
 *     recorded by the four paths that really re-authenticate somebody
 *     (`Auth::loginLocal()`, the two single-sign-on callbacks, and the passkey
 *     endpoint). This class holds the recording and the reading so those four
 *     cannot each invent their own window.
 *   - **It cannot tell a borrowed session from its owner.** That is the whole
 *     reason the proof exists: a session can be borrowed, so the window is
 *     short and the proof is spent when it is used. What it CANNOT do is notice
 *     that somebody else is holding the authenticator.
 *   - **The window is counted in SECONDS of real time**, from `time()`, which
 *     is an instant and not a reading on anybody's clock. That is on purpose
 *     (#604: add seconds as seconds, days as days) — five minutes must be five
 *     minutes on the two nights a year the clocks change.
 *   - **It trusts the web server's clock.** A clock moved BACKWARDS would make
 *     a proof look as though it came from the future, and such a proof is
 *     refused rather than accepted; a clock moved FORWARDS simply expires
 *     proofs early. Neither is detectable here.
 *   - **A proof survives the two-factor step.** It is recorded when the first
 *     factor passes, so for an account with two-factor authentication the five
 *     minutes include the time spent typing the code. Nothing can use a proof
 *     while the session is still waiting for that code, because every page is
 *     behind `Auth::requireLogin()` and `recentlyProvedThemselves()` compares
 *     the proof against the SIGNED-IN person, who at that moment is nobody.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/731
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

final class SelfServicePassword
{
    /**
     * ⚙️ The setting that says whether this is offered. Read at BOTH levels;
     *    see the class note above for why the merged array cannot answer it.
     */
    public const SETTING_KEY = 'auth.password.selfAdd';

    /**
     * 🔑 Where the proof lives in the session, and how long it is good for.
     *
     * FIVE MINUTES, and the number is here rather than in a setting on purpose:
     * it is a security boundary, not a preference, and a per-organisation
     * figure would let one organisation widen a window that protects accounts
     * the installation's administrator is also responsible for.
     */
    public const PROOF_KEY             = 'reauth_proof';
    public const PROOF_GOOD_FOR_SECONDS = 300;

    /**
     * 🛂 The methods a proof may name, and NOTHING ELSE is accepted.
     *
     * A closed list rather than any text, because the method is written into
     * the session by four separate files and read back here. An unknown method
     * means a proof this class did not record, and the safe answer to that is
     * "no proof".
     *
     * The words are what a person is shown, so they are ordinary English and
     * not the portal's internal names for the routes.
     */
    public const METHOD_PASSWORD  = 'password';
    public const METHOD_MICROSOFT = 'Microsoft';
    public const METHOD_GOOGLE    = 'Google';
    public const METHOD_PASSKEY   = 'passkey';

    /** @var list<string> */
    public const METHODS = [
        self::METHOD_PASSWORD,
        self::METHOD_MICROSOFT,
        self::METHOD_GOOGLE,
        self::METHOD_PASSKEY,
    ];

    /**
     * 💬 The sentences. Public, because the page that draws the form compares
     *    `whyNotAllowed()`'s answer with MSG_NEEDS_PROOF to decide whether to
     *    offer the "prove it is you" buttons.
     *
     *    That comparison is NOT a second decision: the page never works the
     *    answer out, it only recognises the one this class gave it. Keeping the
     *    reason and the words as one value is what makes that possible, and is
     *    why there is no separate reason code here as `ExpenseRules` has.
     *
     *    NONE OF THESE NAMES ANYBODY ELSE, and none of them says which of the
     *    two levels switched the feature off: the installation's administrator
     *    and the organisation's may be different parties, and a member has no
     *    business being told which one said no (the precedent is
     *    `SettingLimit::refusalWords()`, #687).
     */
    public const MSG_NOT_SIGNED_IN = 'You are not signed in, so nothing was changed.';
    public const MSG_ALREADY_HAS_ONE = 'Your account already has a password, so there is nothing to add. '
        . 'Use "Change Password" on your Account page to change the one you have.';
    public const MSG_NOT_ALLOWED_HERE = 'Adding a password to your own account is not available on this portal. '
        . 'If you need one, ask whoever looks after the portal for your organisation.';
    public const MSG_NO_WAY_TO_PROVE = 'We cannot offer you a way to prove who you are, because this account has '
        . 'no Microsoft or Google sign-in and no passkey. Ask whoever looks after the portal for help.';
    public const MSG_NEEDS_PROOF = 'Before you can add a password, please sign in again the way you normally do. '
        . 'That is how we make sure it is really you adding a second way into your account.';

    /**
     * 🔑 RECORD that somebody has just proved who they are. THE ONE PLACE.
     *
     * Called ONLY from a path that has really just re-authenticated the person:
     * `Auth::loginLocal()` (a password was verified), the Microsoft and Google
     * callbacks (the provider verified them), and
     * `_apps/auth/login/webauthn.php` (a passkey assertion was verified).
     *
     * **NEVER call this because somebody is already signed in.** A session can
     * be borrowed, and that is the entire reason this gate exists: a proof that
     * came from an existing session would prove nothing and would make the gate
     * decorative.
     *
     * The moment is `time()` — seconds since the epoch, which is an instant and
     * carries no time zone. It is deliberately not a formatted date: a reading
     * on a clock would need a zone to compare, and two of the nights in a year
     * have an hour that happens twice or not at all (#604).
     *
     * An unknown method is NOT recorded. Writing one would leave a proof that
     * `recentlyProvedThemselves()` then refuses, which reads exactly like a
     * fault in the sign-in path; refusing to record it keeps the fault where it
     * belongs, in the caller.
     *
     * @param int    $userId The account that has just been authenticated.
     * @param string $method One of self::METHODS.
     *
     * @return void
     */
    public static function recordProof(int $userId, string $method): void
    {
        if ($userId <= 0 || in_array($method, self::METHODS, true) === false) {
            return;
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            // 🛑 No session to record into. Every caller starts one first
            //    (`Auth::ensureSession()`), so this is a backstop rather than a
            //    live branch — said so plainly, because a reader should not
            //    take it for a guard that fires.
            return;
        }

        $_SESSION[self::PROOF_KEY] = [
            'userID' => $userId,
            'method' => $method,
            'atUtc'  => time(),
        ];
    }

    /**
     * 🔑 Has this person proved who they are in the last five minutes?
     *
     * Every one of these has to hold:
     *   - a proof is in the session, with all three of its parts;
     *   - it names THIS person. A proof for somebody else is not a proof for
     *     this account, and the passkey endpoint really can change who the
     *     session belongs to mid-visit, because it signs in whoever's
     *     authenticator answered;
     *   - its method is one this class recorded;
     *   - it is between nought and `PROOF_GOOD_FOR_SECONDS` seconds old. A
     *     NEGATIVE age — a proof from the future — is refused: it means the
     *     clock moved, and "we cannot tell how old this is" must never become
     *     "it is fresh".
     *
     * @param int|null $userId The account to judge. Null means the signed-in
     *                         person, read from the session.
     *
     * @return bool
     */
    public static function recentlyProvedThemselves(?int $userId = null): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        $who = $userId ?? (int) ($_SESSION['user_id'] ?? 0);
        if ($who <= 0) {
            return false;
        }

        $proof = $_SESSION[self::PROOF_KEY] ?? null;
        if (is_array($proof) === false) {
            return false;
        }
        if (isset($proof['userID'], $proof['method'], $proof['atUtc']) === false) {
            return false;
        }
        if (is_int($proof['userID']) === false || is_string($proof['method']) === false || is_int($proof['atUtc']) === false) {
            // 🧵 A session is a file on disk and can be edited or restored.
            //    Anything that is not the shape this class writes is not a
            //    proof.
            return false;
        }
        if ($proof['userID'] !== $who) {
            return false;
        }
        if (in_array($proof['method'], self::METHODS, true) === false) {
            return false;
        }

        $age = time() - $proof['atUtc'];

        return $age >= 0 && $age <= self::PROOF_GOOD_FOR_SECONDS;
    }

    /**
     * 🔑 In ordinary words, how this person proved themselves — for the page to
     * show, so that somebody can see which of their sign-in methods was used.
     *
     * Empty when there is no usable proof. It asks
     * `recentlyProvedThemselves()` rather than reading the session itself, so a
     * stale or borrowed proof can never be described as a current one.
     *
     * @param int|null $userId As `recentlyProvedThemselves()`.
     *
     * @return string
     */
    public static function proofMethod(?int $userId = null): string
    {
        if (self::recentlyProvedThemselves($userId) === false) {
            return '';
        }

        return (string) $_SESSION[self::PROOF_KEY]['method'];
    }

    /**
     * 🔑 SPEND the proof. THE ONE PLACE a proof is cleared.
     *
     * A proof is single-use, so that one re-authentication cannot add two
     * things. It is spent when the password has really been stored — not
     * before: a mistyped password, or one the portal's own policy refuses,
     * would otherwise cost the person a whole sign-in ceremony for a typing
     * mistake, and that is the sort of cost that makes people give up on a
     * recovery route.
     *
     * @return void
     */
    public static function spendProof(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        unset($_SESSION[self::PROOF_KEY]);
    }

    /**
     * ⚙️ Is adding your own password allowed, with the STRICTER LEVEL WINNING?
     *
     * Both arrays are passed in rather than read from globals, so that this
     * rule can be exercised without a request and without a database.
     *
     * @param array<string,mixed> $merged            `$SETTINGS` — already
     *                                               merged, so this stands for
     *                                               the ORGANISATION's answer.
     * @param array<string,mixed> $installationLayer `InstallationSettings::layer()`
     *                                               — the installation-wide
     *                                               rows on their own.
     *
     * @return bool
     */
    public static function allowedFrom(array $merged, array $installationLayer): bool
    {
        return self::saysYes(self::dig($installationLayer, self::SETTING_KEY))
            && self::saysYes(self::dig($merged, self::SETTING_KEY));
    }

    /**
     * ⚙️ The same question, for a page that has no reason to hold the two
     * arrays itself.
     *
     * `InstallationSettings::layer()` and not `layerOrNull()`: an empty layer
     * must read as "not allowed" here. That is the direction its own docblock
     * names — use `layer()` where an empty layer is the SAFE answer, because
     * allowing less is recoverable and allowing more is not.
     *
     * @return bool
     */
    public static function allowed(): bool
    {
        $merged = App::settings();

        return self::allowedFrom(is_array($merged) === true ? $merged : [], InstallationSettings::layer());
    }

    /**
     * 📋 The facts `whyNotAllowed()` judges, read from the database.
     *
     * Separate from the rule for the reason given in the class note: the form
     * page and the handler must ask ONE rule, and the handler asks it twice —
     * the second time on facts read inside the transaction that writes, so that
     * nothing can have changed between the two.
     *
     * @param \mysqli $db     An open connection.
     * @param int     $userId The account.
     *
     * @return array{
     *     userId:int, email:string, hasUsablePassword:bool, hasSignInRow:bool,
     *     providers:list<string>, passkeys:int, allowed:bool, provedThemselves:bool
     * }
     */
    public static function factsFor(\mysqli $db, int $userId): array
    {
        $facts = [
            'userId'            => $userId,
            'email'             => '',
            'hasUsablePassword' => false,
            'hasSignInRow'      => false,
            'providers'         => [],
            'passkeys'          => 0,
            'allowed'           => self::allowed(),
            'provedThemselves'  => self::recentlyProvedThemselves($userId),
        ];
        if ($userId <= 0) {
            return $facts;
        }

        // 🔍 "A usable password" is a sign-in row whose hash is NOT the empty
        //    string. The empty string is this portal's marker for a password
        //    nobody can use — `Auth::loginLocal()` refuses it before
        //    `password_verify()` is ever reached, and `Auth::setUnusablePassword()`
        //    writes it. An account in that state has a row and no way in, so it
        //    needs this page just as much as an account with no row at all, and
        //    counting rows rather than usable hashes would have shut it out.
        //
        //    Both facts are kept, because they answer different questions:
        //    whether there is anything to ADD, and whether `Auth::setPassword()`
        //    will create a row or update one.
        $stmt = $db->prepare(
            'SELECT `U`.`emailAddress` AS `email`, '
            . '(SELECT COUNT(*) FROM `tblLocalAccounts` `LA` '
            . 'WHERE `LA`.`userID` = `U`.`userID` AND `LA`.`passwordHash` <> \'\') AS `usablePasswords`, '
            . '(SELECT COUNT(*) FROM `tblLocalAccounts` `LB` WHERE `LB`.`userID` = `U`.`userID`) AS `signInRows`, '
            . '(SELECT COUNT(*) FROM `tblWebAuthnCredentials` `W` WHERE `W`.`userID` = `U`.`userID`) AS `passkeyCount` '
            . 'FROM `tblUsers` `U` WHERE `U`.`userID` = ? LIMIT 1'
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row !== null) {
            $facts['email']             = (string) $row['email'];
            $facts['hasUsablePassword'] = (int) $row['usablePasswords'] > 0;
            $facts['hasSignInRow']      = (int) $row['signInRows'] > 0;
            $facts['passkeys']          = (int) $row['passkeyCount'];
        }

        $linked = $db->prepare(
            'SELECT DISTINCT `provider` FROM `tblLinkedAccounts` WHERE `userID` = ? ORDER BY `provider`'
        );
        $linked->bind_param('i', $userId);
        $linked->execute();
        $found = $linked->get_result();
        while ($one = $found->fetch_assoc()) {
            $facts['providers'][] = (string) $one['provider'];
        }
        $linked->close();

        return $facts;
    }

    /**
     * 🚦 THE ONE RULE: may this person add a password to their own account?
     *
     * The empty string means yes. Anything else is a sentence to show them.
     *
     * THE ORDER, and why it is this order:
     *   1. **Not signed in.** Nothing else can be judged.
     *   2. **They already have a usable password.** Said before the policy
     *      refusal, because it is the more useful answer and it gives nothing
     *      away — this is always the person's OWN account, so there is no
     *      stranger to protect, which is why the ownership-first rule of
     *      `ExpenseRules::whyNotEditable()` does not apply here.
     *   3. **The portal does not offer it.** Before the proof, so that nobody
     *      is sent through a whole sign-in ceremony and then refused.
     *   4. **There is no method they could prove themselves with.** Also before
     *      the proof, for the same reason: there is nothing to offer them.
     *   5. **No fresh proof.** Last, because it is the only one the person can
     *      do something about on this page.
     *
     * @param array{
     *     userId?:int, hasUsablePassword?:bool, providers?:list<string>,
     *     passkeys?:int, allowed?:bool, provedThemselves?:bool
     * } $facts As `factsFor()` answers.
     *
     * @return string
     */
    public static function whyNotAllowed(array $facts): string
    {
        if ((int) ($facts['userId'] ?? 0) <= 0) {
            return self::MSG_NOT_SIGNED_IN;
        }
        if (($facts['hasUsablePassword'] ?? false) === true) {
            return self::MSG_ALREADY_HAS_ONE;
        }
        if (($facts['allowed'] ?? false) !== true) {
            return self::MSG_NOT_ALLOWED_HERE;
        }
        if (self::methodsToProveWith($facts) === []) {
            return self::MSG_NO_WAY_TO_PROVE;
        }
        if (($facts['provedThemselves'] ?? false) !== true) {
            return self::MSG_NEEDS_PROOF;
        }

        return '';
    }

    /**
     * 🛂 Which ways this person could prove who they are, from the facts.
     *
     * A list of `self::METHOD_*` values, in the order the page offers them. It
     * never includes a password: somebody reading this page has no usable one,
     * which is the whole reason they are here.
     *
     * @param array{providers?:list<string>, passkeys?:int} $facts
     *
     * @return list<string>
     */
    public static function methodsToProveWith(array $facts): array
    {
        $providers = $facts['providers'] ?? [];
        $out       = [];

        if (is_array($providers) === true) {
            if (in_array('ms365', $providers, true) === true) {
                $out[] = self::METHOD_MICROSOFT;
            }
            if (in_array('google', $providers, true) === true) {
                $out[] = self::METHOD_GOOGLE;
            }
        }
        if ((int) ($facts['passkeys'] ?? 0) > 0) {
            $out[] = self::METHOD_PASSKEY;
        }

        return $out;
    }

    /**
     * ✅ Does a stored setting say yes?
     *
     * A setting's value is always text, so '1' is the ordinary case and this
     * portal writes 'true' for some booleans and '1' for others. Only a value
     * that clearly says yes counts; everything else — '', '0', 'false',
     * 'maybe', a missing key, an array where a value was expected — is no,
     * because no is the safe answer for this setting.
     *
     * @param mixed $stored
     *
     * @return bool
     */
    private static function saysYes(mixed $stored): bool
    {
        if (is_bool($stored) === true) {
            return $stored;
        }
        if (is_int($stored) === true) {
            return $stored === 1;
        }
        if (is_string($stored) === false) {
            return false;
        }

        return in_array(strtolower(trim($stored)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * 🔍 One dotted key out of a nested settings array.
     *
     * The same walk `SettingLimit::fromLayer()` does, and deliberately a second
     * copy of six lines rather than a shared helper: `SettingLimit` is integers
     * throughout — every one of its public methods takes and answers an `int` —
     * and bending it to booleans would mean teaching a class about limits that
     * this setting is not one. The thing that must not be copied is the RULE,
     * and the rule (both levels must say yes) lives once, above.
     *
     * @param array<string,mixed> $layer
     * @param string              $dotKey
     *
     * @return mixed
     */
    private static function dig(array $layer, string $dotKey): mixed
    {
        $node = $layer;
        foreach (explode('.', $dotKey) as $part) {
            if (is_array($node) === false || array_key_exists($part, $node) === false) {
                return null;
            }
            $node = $node[$part];
        }

        return $node;
    }
}
