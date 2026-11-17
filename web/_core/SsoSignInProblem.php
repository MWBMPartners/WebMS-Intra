<?php
// Path: web/_core/SsoSignInProblem.php
/**
 * -----------------------------------------------------------------------------
 * Why a Microsoft or Google sign-in failed, in words (#1042) 🔑❌
 * -----------------------------------------------------------------------------
 * The ONE place a failed Microsoft or Google sign-in is put into words. The two
 * callbacks in `Auth.php` name a PROBLEM (one of the constants below) and
 * `Auth::oauthError()` asks `words()` for the sentence, once, for the person
 * the browser belongs to.
 *
 * TWO DIFFERENT PEOPLE REACH THE CALLBACKS, AND EACH NEEDS ITS OWN WORDS.
 *
 *  - Somebody NOT signed in, trying to sign in. They read the words on the
 *    sign-in page. Those words are unchanged by #1042 round 4, byte for byte.
 *
 *  - A member who IS signed in, sent there on purpose by the Account page's
 *    "Link Google" / "Link Microsoft 365" buttons or by "Add a password"
 *    (#731). Round 3 showed them the SIGNED-OUT words, and two were untrue
 *    about them (the independent check, round 3): "No account on this portal
 *    matches this sign-in. Ask an administrator to invite you." told somebody
 *    who HAS an account to ask for an invitation — which would make the very
 *    duplicate D-185 exists to prevent — and "This account has been
 *    deactivated" on their own working Account page read as though THEIR
 *    account were switched off, when it is ANOTHER account: the e-mail
 *    address is unique in `tblUsers`, the switched-off account was found by
 *    that address only after no switched-ON account had it, and
 *    `Auth::ensureSession()` ends a switched-off member's session on each
 *    request (whenever it can read the account), so the signed-in member's
 *    own account is switched on.
 *
 * EVERY SIGNED-IN SENTENCE SAYS THREE THINGS: that the sign-in could not be
 * used with THIS member's account, why, and that they are still signed in with
 * nothing on their account changed. That last part is true of every problem
 * here: each is raised before the callback updates, links or grants anything
 * (read in `callbackMS365()` and `callbackGoogle()`, 15 November 2026).
 *
 * WHAT THE SIGNED-IN WORDS MUST NOT REVEAL. A sign-in is only ever for an
 * identity the person controls at Microsoft or Google, signed in or not, so
 * the signed-out words already tell that person whether an account exists for
 * that identity's address ("No account ... matches") and whether it is
 * switched off ("This account has been deactivated"). The signed-in words
 * reveal NO MORE than that: the "no account" sentence does not even say that
 * nobody else has the address — only that it is not this member's — and the
 * "switched off" sentence says what the signed-out sentence says, plus that
 * the account is not the member's own, which they know.
 *
 * LINKING NEVER CREATES OR SWITCHES (D-208, #1073). A signed-in member who
 * links Microsoft or Google is only ever attached to the account they are
 * signed in to. An identity that matches nobody is refused (`NO_ACCOUNT`)
 * whatever `auth.sso.createAccounts` says; one already linked to, or carrying
 * the e-mail address of, a DIFFERENT account is refused (`LINKED_ELSEWHERE`,
 * `EMAIL_ELSEWHERE`). The two new sentences say "a different account" and
 * reveal nothing a person could not learn by signing out: they control that
 * identity, and signed OUT the same identity would sign them in to that other
 * account (by link, or by e-mail, which is the ordinary auto-link). "An
 * administrator can start merging them" is true: an administrator with
 * `accounts.merge` starts a merge and the person confirms (D-190, D-191).
 *
 * WHAT THIS CANNOT DO: it does not decide anything. Whether a sign-in fails,
 * and why, is decided in the callbacks; this only words the answer.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/1042
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

final class SsoSignInProblem
{
    /** Too many FAILED sign-ins from this internet connection lately (`RateLimiter::isBlocked()`). */
    public const TOO_MANY = 'too_many';
    /** The `state` that came back is not the one this browser's session started. */
    public const STATE = 'state';
    /** No authorisation code came back (the person may have cancelled at the provider). */
    public const NO_CODE = 'no_code';
    /** The token request to the provider failed outright. */
    public const TOKEN_REQUEST = 'token_request';
    /** The provider's token answer was not readable or held no identity token. */
    public const TOKEN_RESPONSE = 'token_response';
    /** The provider's signing keys could not be fetched. */
    public const SIGNING_KEYS = 'signing_keys';
    /** The identity token failed its check. */
    public const TOKEN_VERIFY = 'token_verify';
    /** Microsoft's token named no e-mail address. */
    public const NO_EMAIL = 'no_email';
    /** Google's token named no e-mail address or no subject. */
    public const NO_IDENTITY = 'no_identity';
    /** The Google account is not from the one organisation this portal accepts (`hostedDomain`). */
    public const DOMAIN = 'domain';
    /** The sign-in's e-mail address belongs to a switched-off account. */
    public const SWITCHED_OFF = 'switched_off';
    /** Nothing matched, and new accounts by Microsoft or Google sign-in are refused here (D-185). */
    public const NO_ACCOUNT = 'no_account';
    /** While linking: the identity is already linked to a DIFFERENT account (D-208, #1073). */
    public const LINKED_ELSEWHERE = 'linked_elsewhere';
    /** While linking: the identity is not linked, and its e-mail address is a DIFFERENT account's (D-208, #1073). */
    public const EMAIL_ELSEWHERE = 'email_elsewhere';

    /** Said after every signed-in sentence, because it is true of every problem here (see the class docblock). */
    private const SIGNED_IN_TAIL = ' You are still signed in, and nothing on your account has changed.';

    /**
     * Each problem's words: [for somebody NOT signed in, for a member who IS].
     * `{provider}` in the second becomes 'Microsoft' or 'Google'. The first
     * column is what these callbacks have always said (NO_ACCOUNT's is D-185's,
     * kept in `SsoNewAccounts`), unchanged on purpose.
     */
    private const WORDS = [
        self::TOO_MANY => [
            'Too many authentication attempts. Please try again later.',
            'Your {provider} sign-in was not tried, because there have been too many failed sign-in attempts from your internet connection recently. Please try again later.',
        ],
        self::STATE => [
            'Invalid OAuth state. Please try signing in again.',
            'That {provider} sign-in could not be used with your account, because it was not the one this browser started (it may have been used already, or another may have been started since in a different tab or window). Please try again.',
        ],
        self::NO_CODE => [
            'Authorization code missing. Please try signing in again.',
            '{provider} did not confirm that sign-in (it may have been cancelled there), so it could not be used with your account.',
        ],
        self::TOKEN_REQUEST => [
            'Token request failed. Please try signing in again.',
            'The portal could not get {provider} to confirm that sign-in, so it could not be used with your account. Please try again.',
        ],
        self::TOKEN_RESPONSE => [
            'Invalid token response. Please try signing in again.',
            '{provider} answered in a way the portal could not read, so that sign-in could not be used with your account. Please try again.',
        ],
        self::SIGNING_KEYS => [
            'Unable to retrieve signing keys. Please try again later.',
            'The portal could not fetch what it needs from {provider} to check that sign-in, so it could not be used with your account. Please try again later.',
        ],
        self::TOKEN_VERIFY => [
            'Token verification failed. Please try signing in again.',
            'The portal could not confirm that the {provider} sign-in was genuine, so it could not be used with your account. Please try again.',
        ],
        self::NO_EMAIL => [
            'Unable to determine user email from token.',
            '{provider} did not say which e-mail address that sign-in belongs to, so it could not be used with your account.',
        ],
        self::NO_IDENTITY => [
            'Unable to determine user identity from token.',
            '{provider} did not say which account and e-mail address that sign-in belongs to, so it could not be used with your account.',
        ],
        self::DOMAIN => [
            'Your Google account is not from the allowed organisation.',
            'That {provider} account could not be used with your account, because this portal accepts {provider} accounts from one organisation only, and it is not from that one.',
        ],
        self::SWITCHED_OFF => [
            'This account has been deactivated. Please contact your administrator.',
            'That {provider} account could not be used with your account, because it is not linked to your account and its e-mail address is not the one on your account here: that address belongs to a different account on this portal, which is switched off.',
        ],
        self::NO_ACCOUNT => [
            SsoNewAccounts::REFUSAL_WORDS,
            // D-208 (#1073): a member who is linking reads this whatever the
            // "new accounts" setting says, because linking never makes an account.
            'That {provider} account could not be used with your account, because it is not linked to your account and its e-mail address is not the one on your account here, so the portal cannot tell that it is yours.',
        ],
        // The two below are raised ONLY while somebody is signed in (linking),
        // so their signed-out column is never read in practice; it is the
        // fallback's signed-out sentence word for word, so that if it ever were
        // read it would say what a signed-out person has always been told.
        self::LINKED_ELSEWHERE => [
            'Your sign-in could not be completed. Please try signing in again.',
            'That {provider} account could not be used with your account, because it is already linked to a different account on this portal. Linking never moves a sign-in from one account to another or signs you in as the other account. If both accounts are yours, an administrator can start merging them.',
        ],
        self::EMAIL_ELSEWHERE => [
            'Your sign-in could not be completed. Please try signing in again.',
            'That {provider} account could not be used with your account, because it is not linked to your account and its e-mail address belongs to a different account on this portal. Linking never adds a sign-in to another account or signs you in as it. If both accounts are yours, an administrator can start merging them.',
        ],
    ];

    /** For a problem not in the list: never expected, and never left without words. */
    private const FALLBACK = [
        'Your sign-in could not be completed. Please try signing in again.',
        'That {provider} sign-in could not be used with your account.',
    ];

    /**
     * 🗣️ The sentence for one problem, for the person reading it.
     *
     * @param string $problem  One of this class's constants.
     * @param string $provider 'Microsoft' or 'Google' — the callback's own words, never a visitor's.
     * @param bool   $signedIn Whether the browser is signed in to the portal (`Auth::check()`).
     *
     * @return string
     */
    public static function words(string $problem, string $provider, bool $signedIn): string
    {
        $pair = self::WORDS[$problem] ?? self::FALLBACK;
        if ($signedIn === false) {
            return $pair[0];
        }

        return str_replace('{provider}', $provider, $pair[1]) . self::SIGNED_IN_TAIL;
    }

    /**
     * Every problem this class words, in order — for the self-test, which
     * requires every one to be driven and every callback's problem to be here.
     *
     * @return list<string>
     */
    public static function problems(): array
    {
        return array_keys(self::WORDS);
    }
}
