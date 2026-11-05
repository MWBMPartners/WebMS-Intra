<?php
// Path: _core/ContentTranslation.php
/**
 * -----------------------------------------------------------------------------
 * Which piece of content may be translated, and for whom 🌐
 * -----------------------------------------------------------------------------
 * `Portal\Core\Translation` is the ENGINE: it calls an outside company, pays
 * for the call and caches the answer. This class is the GATE in front of it,
 * and it is the ONE place that decides:
 *
 *   • which pieces of content may be translated at all (`SOURCES`);
 *   • whether the person asking may read the piece they named;
 *   • whether they have given their own permission for their reading to be
 *     sent to an outside company.
 *
 * Do not write a second answer to any of those three questions in a page or a
 * handler. `whyNotOffered()` takes FACTS rather than a connection, so the page
 * drawing the "Translate" control and the handler accepting the press ask the
 * SAME rule and cannot disagree (#647's lesson, where the payment page and its
 * own test each held a copy of one rule).
 *
 * WHY THIS CLASS EXISTS AT ALL — read this before widening anything in it
 * -----------------------------------------------------------------------
 * The handler that used to sit at `web/_apps/api/translate.php` took the TEXT
 * TO BE TRANSLATED FROM THE REQUEST and looked up nothing. It could not be
 * reached, because `ApiRouter` wants `api/{app}/{action}` and that address has
 * two segments, so no harm ever came of it — but finishing #485 is exactly the
 * act of making it reachable, and three faults would have become live together
 * (#761):
 *
 *   1. `Translation::lookupCached()` is keyed on the source row alone and asks
 *      nothing about who is reading. The old handler called it with whatever
 *      row number was posted, so anybody signed in anywhere could read back
 *      the translated words of any row of any organisation.
 *   2. The text came from `$_POST['text']`, so the allow-list of tables
 *      constrained the LABEL and not the words. A member could have spent the
 *      customer's money translating anything at all, and the answer was then
 *      cached against a real row's number — so the next reader of
 *      announcement 42 would have been shown text that was never in
 *      announcement 42.
 *   3. Nothing checked that the caller could see the row.
 *
 * So the text is read FROM THE DATABASE here, by a statement that carries the
 * row's own visibility rule, and the row number alone decides nothing.
 *
 * WHAT IS DELIBERATELY NOT IN `SOURCES`, and this is the part to leave alone
 * ------------------------------------------------------------------------
 * **A PRAYER REQUEST IS NOT TRANSLATABLE.** D-049 is the owner's decision to
 * finish this feature, and the option they chose said in terms that *"the
 * 'Translate' control must not appear where it would send a third party's
 * words"*. A prayer request is the clearest case there is: `tblPrayerRequests`
 * is the one table this portal already treats as *a person's own words about
 * themselves* (D-016, #715, migration 240 — an erasure empties `subject`,
 * `body` and `partnerNote` for that reason), and such a request is routinely
 * about somebody who is not even the submitter: *"please pray for my mother's
 * operation"*. The mother can never consent, and the READER'S opt-in cannot
 * stand in for the WRITER'S — a congregation-visible request is read by every
 * member, so one member pressing a button would send another member's words,
 * and a third party's, to Anthropic or Google.
 *
 * That narrows the feature from the five tables the old handler listed to the
 * three below, and it is said plainly on #485 so the owner can overrule it.
 * It is NOT a tidy-up and must not be reversed as one.
 *
 * `tblNewsletter` is absent for a duller reason: no page a member reads offers
 * its text, and a surface nothing reads is #671's "a seeded setting that
 * nothing reads is a lie with a Save button". Add it here when a page shows a
 * newsletter in the portal, not before.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/485
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/761
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use mysqli;

class ContentTranslation
{
    /**
     * 🗂️ Two answers remembered for the life of ONE request.
     *
     * The control is drawn once per piece of content, and a project page with
     * twenty updates draws twenty-one of them — each asking the same two
     * questions about the same person. That was 42 extra queries on one page
     * view, measured by an independent check.
     *
     * Both answers are constants within a request: a member's own language and
     * their own permission cannot change half way through drawing a page. They
     * are keyed by account number all the same, because a scheduled job or a
     * test can ask about more than one person in one run.
     *
     * **Deliberately NOT a cache that outlives the request.** A member who
     * switches their permission off must see the controls gone on the very
     * next page, and `apcu` is not on this hosting anyway.
     *
     * @var array<int, bool>
     */
    private static array $optedIn = [];

    /** @var array<int, string> */
    private static array $language = [];

    /**
     * 📋 THE ONE ALLOW-LIST: what may be translated, and how its words are
     *    found.
     *
     * Each entry is a table this portal will translate ONE field of, with the
     * statement that reads that field. Three things about the shape, each of
     * which is load-bearing:
     *
     *   • **The key is matched EXACTLY against the request** before anything
     *     is built, which is what makes it safe for the table and column names
     *     to sit inside the SQL text. The same reasoning as
     *     `DbBackup::CASCADE_RESCUE`: a name that came from a constant is not
     *     a name that came from a visitor.
     *   • **Every statement carries the row's own visibility rule**, copied
     *     from the page that displays it, and takes exactly two values — the
     *     row's number and the organisation's. A row the reader may not see is
     *     therefore NOT FOUND rather than refused, so a refusal never reveals
     *     whether it exists (the ownership-before-status rule of #647).
     *   • **One field per table.** Every press of the control spends the
     *     customer's money with an outside company, so a second field would be
     *     a second charge. A page that wants a title translated too is a
     *     decision about money, not a convenience.
     *
     * The clock: these statements ask MySQL for `NOW()`, while
     * `announcements/view.php` binds PHP's `date()`. On this hosting that is
     * one machine and one zone, so they agree — but said plainly rather than
     * assumed: at the exact minute a post is published or expires the two can
     * differ by the length of the request, and the only consequence is a
     * translation refused for a post the page still shows. Nothing is sent and
     * nothing is charged.
     */
    private const SOURCES = [
        // An announcement: the organisation telling its members something, and
        // only once it is published, undeleted and inside its own dates. The
        // clause is `announcements/view.php`'s own, so the control cannot
        // appear on a page whose row this would refuse.
        'tblAnnouncements' => [
            'field' => 'body',
            'sql'   => 'SELECT `a`.`body` FROM `tblAnnouncements` `a` '
                     . 'WHERE `a`.`announcementID` = ? AND `a`.`siteID` = ? '
                     . 'AND `a`.`isPublished` = 1 AND `a`.`isDeleted` = 0 '
                     . 'AND (`a`.`publishAt` IS NULL OR `a`.`publishAt` <= NOW()) '
                     . 'AND (`a`.`expiresAt` IS NULL OR `a`.`expiresAt` > NOW()) LIMIT 1',
        ],
        // A project's description: the organisation describing something it is
        // raising money for. `isPublic = 1` is `projects/view.php`'s own test,
        // and it is what makes this the organisation's PUBLISHED words.
        'tblProject' => [
            'field' => 'description',
            'sql'   => 'SELECT `p`.`description` FROM `tblProject` `p` '
                     . 'WHERE `p`.`projectID` = ? AND `p`.`siteID` = ? AND `p`.`isPublic` = 1 LIMIT 1',
        ],
        // An update posted against a project. The organisation it belongs to is
        // on the PARENT row, never on this one, so the join is how the
        // organisation is checked — #709's lesson about reaching a row through
        // its parent, in its mildest form.
        'tblProjectUpdate' => [
            'field' => 'content',
            'sql'   => 'SELECT `u`.`content` FROM `tblProjectUpdate` `u` '
                     . 'INNER JOIN `tblProject` `p` ON `p`.`projectID` = `u`.`projectID` '
                     . 'WHERE `u`.`updateID` = ? AND `p`.`siteID` = ? AND `p`.`isPublic` = 1 LIMIT 1',
        ],
    ];

    /**
     * 🧾 The tables this portal will translate, with the one field of each.
     *
     * Public so that a page, a test and the audit check all read the SAME list
     * rather than keeping a copy of it.
     *
     * @return array<string, string> table name => field name
     */
    public static function translatableFields(): array
    {
        $out = [];
        foreach (self::SOURCES as $table => $entry) {
            $out[$table] = (string) $entry['field'];
        }
        return $out;
    }

    /**
     * ❓ Why this reader may not be offered a translation of this piece — or
     *    an empty string when they may.
     *
     * TAKES FACTS, NOT A CONNECTION, so the page that draws the control and
     * the handler that accepts the press ask one rule (#647). The page passes
     * what it already knows; the handler passes what it read for itself.
     *
     * The order of the tests is deliberate and is about money and privacy
     * rather than tidiness:
     *
     *   1. **Signed in.** `projects/view.php` is a PUBLIC page, so without
     *      this a passer-by could spend the customer's money. The old handler
     *      had `Auth::requireLogin()` and that much was right.
     *   2. **The feature is on.** `translation.enabled` ships `'0'`.
     *   3. **This reader has opted in.** D-049: *"the member's own opt-in is
     *      what permits it, so nothing may be sent for somebody who has not
     *      opted in"*. It gates the explicit press as well as any automatic
     *      translation, because a send is a send.
     *   4. **The piece is on the allow-list.**
     *   5. **There is somewhere to translate to.** Asking for the language the
     *      text is already in costs nothing and means nothing.
     *
     * NOTE WHAT IS NOT HERE: whether the monthly spend cap has been reached.
     * That is asked by `Translation::translate()` at the moment the money
     * would be spent, because a cap tested only here would be #668's
     * "a save-time cap is not a cap" — a reader whose page was drawn before
     * the cap was reached would still spend past it. The control may therefore
     * be offered and the press then answered "the month's limit is reached",
     * which is the honest order.
     *
     * @param array<string, mixed> $facts signedIn, optedIn, enabled, sourceTable, targetLanguage, readerLanguage
     *
     * @return string A reason in plain words, or '' when it may be offered.
     */
    public static function whyNotOffered(array $facts): string
    {
        if ((bool) ($facts['signedIn'] ?? false) === false) {
            return 'sign-in';
        }
        if ((bool) ($facts['enabled'] ?? false) === false) {
            return 'switched-off';
        }
        if ((bool) ($facts['optedIn'] ?? false) === false) {
            return 'not-opted-in';
        }
        $table = (string) ($facts['sourceTable'] ?? '');
        if (array_key_exists($table, self::SOURCES) === false) {
            return 'not-translatable';
        }
        // ⚠️ ASK OF THE RAW VALUE, NOT THE NORMALISED ONE. The first version
        //    normalised first and then tested for an empty string — and
        //    `Translation::normaliseLocale('')` answers `'en'`, so the branch
        //    could NEVER fire. A press with no language got as far as the
        //    database, was read as English, and came back "already in that
        //    language": a clause that cannot fail, found by a test asserting
        //    the refusal and getting 200 instead.
        if (trim((string) ($facts['targetLanguage'] ?? '')) === '') {
            return 'no-language';
        }
        // 🚫 A LANGUAGE THE PORTAL DOES NOT KNOW IS REFUSED BEFORE ANY MONEY
        //    IS SPENT. Without this, a made-up code would be passed to the
        //    outside company, charged for, and the rubbish that came back
        //    cached against a real row for ever. Thirteen languages are
        //    recognised; `I18n` is the one list of them, so there is no second
        //    copy here.
        if (I18n::isSupported(Translation::normaliseLocale((string) $facts['targetLanguage'])) === false) {
            return 'language-not-offered';
        }
        return '';
    }

    /**
     * 🗣️ The words for a refusal, for a page or a handler to show.
     *
     * One place, so the control's tooltip and the handler's answer say the same
     * thing. None of them names the person who wrote the content, and none
     * says whether a row exists.
     */
    public static function refusalWords(string $reason): string
    {
        switch ($reason) {
            case 'sign-in':
                return 'Translation is for signed-in members.';
            case 'switched-off':
                return 'Translation is switched off for this organisation.';
            case 'not-opted-in':
                return 'Switch translation on for your own account first, under '
                     . 'Account, Translation. Translating sends the text to an '
                     . 'outside company, so we ask you first.';
            case 'not-translatable':
                return 'This kind of content cannot be translated.';
            case 'no-language':
                return 'No language was asked for.';
            case 'language-not-offered':
                return 'This portal does not offer that language.';
            case 'not-found':
                return 'That item could not be found.';
            case 'already-that-language':
                return 'This is already in that language.';
            case 'cap-reached':
                return 'This organisation has reached its translation limit for '
                     . 'this month. Ask an administrator.';
            case 'provider-failed':
                // ⚠️ DO NOT SAY "NOTHING HAS BEEN CHARGED". It was said here, in
                //    api-spec.json and on the help page, and the portal CANNOT
                //    know it: a provider call that returns HTTP 200 with an
                //    unexpected shape, or times out after the provider has
                //    accepted the request, answers null here and has still been
                //    billed. The script already said this correctly in its own
                //    comment, so the package contradicted itself — and the
                //    sentence a member reads was the reassuring one, about
                //    somebody else's money.
                //
                //    What IS true and is worth saying: nothing was added to the
                //    portal's own record of the month's spend.
                return 'The translation service did not answer, so nothing was '
                     . 'translated. We cannot tell whether the attempt was charged '
                     . 'for; it has not been added to this month\'s recorded spend.';
            default:
                return 'This cannot be translated.';
        }
    }

    /**
     * 📖 The ONE place the text to be translated is read.
     *
     * Reads the named field of the named row THROUGH that row's own visibility
     * rule, so the answer is null both when the row is not there and when this
     * organisation may not see it. The caller cannot tell those apart, which is
     * the point.
     *
     * @param mysqli $db     The connection.
     * @param string $table  A key of SOURCES; anything else answers null.
     * @param int    $id     The row's own number.
     * @param int    $siteId The organisation being served.
     *
     * @return string|null The words, or null when there are none to be had.
     */
    public static function readSourceText(mysqli $db, string $table, int $id, int $siteId): ?string
    {
        if (array_key_exists($table, self::SOURCES) === false || $id <= 0 || $siteId <= 0) {
            return null;
        }
        $stmt = $db->prepare((string) self::SOURCES[$table]['sql']);
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('ii', $id, $siteId);
        $stmt->execute();
        $stmt->bind_result($text);
        $hit = $stmt->fetch() === true;
        $stmt->close();
        if ($hit === false) {
            return null;
        }
        $words = (string) $text;
        return trim($words) === '' ? null : $words;
    }

    /**
     * 🗣️ The language to translate FOR this reader — the one place that
     *    decides it.
     *
     * **IT READS THE MEMBER'S OWN STORED CHOICE, `tblUsers.locale`, AND NOT
     * `I18n::locale()`, AND THAT IS STILL DELIBERATE — but the reason has
     * changed, and the old reason is worth keeping because it was true for
     * eight months.** `I18n::locale()` is the language the INTERFACE is being
     * drawn in, and until #763 was fixed on 5 November 2026 it did not follow
     * a member's saved choice at all: `I18n::init()` runs inside
     * `bootstrap.php`, where **no session is active**, because the session
     * starts in `index.php` after bootstrap has been required. So
     * `resolveLocale()` could never reach its first two sources — the
     * member's row and the language switcher — and the block in bootstrap
     * that would have read `tblUsers.locale` was behind the same test and
     * could not run either.
     *
     * **#763 IS FIXED NOW**, by `I18n::reconsiderNowSessionExists()`, called
     * from `index.php` straight after `Auth::ensureSession()`. So
     * `I18n::locale()` DOES follow a member's saved choice today, and the
     * sentence above is history rather than a live limitation. The owner's
     * decision that came with it is D-060: an untouched `'en'` counts as "not
     * chosen", because nothing could ever write that column and every row held
     * the schema default.
     *
     * Reading the member's own column remains the right source for this job,
     * which is why nothing here changed: the translation is made FOR one
     * person, so the language they chose is the question, whatever the
     * interface happens to be doing.
     *
     * ⚠️ A DRAFT OF THIS DOCBLOCK SAID THE TWO CAN DIFFER BECAUSE "an
     *    organisation may have turned a language off for the interface", AND
     *    NO SUCH CONTROL EXISTS. `isSupported()` is `array_key_exists()`
     *    against a hard-coded list of thirteen, and `enabledLocales()` is
     *    "which `web/_lang/*.php` files are readable" — a fact about what was
     *    deployed, not a setting anybody can change. Nothing anywhere reads an
     *    `i18n.enabledLocales` setting (`grep -rn enabledLocales web tools`
     *    answers the definition, its one caller, and a test fixture). A
     *    sentence that justifies a design with a mechanism that is not there is
     *    the reassuring direction, which is the worse one.
     *
     * They CAN still differ, for a real reason: this column is read straight
     * from the database, while `I18n::locale()` is the answer
     * `reconsiderNowSessionExists()` reached for THIS request — so a member
     * whose stored language has no translation file gets the interface in the
     * fallback language and a translation in the language they asked for.
     *
     * `I18n::locale()` is still the fallback, for an account with no stored
     * choice.
     */
    public static function readerLanguage(mysqli $db, int $userId): string
    {
        if (array_key_exists($userId, self::$language) === true) {
            return self::$language[$userId];
        }
        self::$language[$userId] = self::readLanguage($db, $userId);

        return self::$language[$userId];
    }

    /** The reading itself, asked once per account per request. */
    private static function readLanguage(mysqli $db, int $userId): string
    {
        if ($userId > 0) {
            $stmt = $db->prepare('SELECT `locale` FROM `tblUsers` WHERE `userID` = ? LIMIT 1');
            if ($stmt !== false) {
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $stmt->bind_result($stored);
                $found = $stmt->fetch() === true;
                $stmt->close();
                if ($found === true && trim((string) $stored) !== '') {
                    return Translation::normaliseLocale((string) $stored);
                }
            }
        }
        return Translation::normaliseLocale(I18n::locale());
    }

    /**
     * 🙋 Has this reader given their own permission?
     *
     * Asks `Translation::userAutoTranslate()` rather than reading the column
     * here. That method existed and was called by nothing at all until #485 —
     * the member's opt-in was a tick-box that changed nothing anywhere.
     *
     * **DO NOT SAY THE PREFERENCE HAS "ONE READER", which this docblock did.**
     * `_apps/account/translation.php` reads `tblUserTranslationPref` directly
     * as well, to draw the tick-box ticked. That is two readers, and it is
     * fine — one asks "may this person be offered a translation", the other
     * "how should this box be drawn" — but the sentence was untrue, and the
     * repository's own rule is that an overstated guarantee is worse than
     * none. What IS true: nothing but this method decides the ANSWER for a
     * translation.
     */
    public static function readerOptedIn(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        if (array_key_exists($userId, self::$optedIn) === false) {
            self::$optedIn[$userId] = Translation::userAutoTranslate($userId);
        }

        return self::$optedIn[$userId];
    }

    /**
     * 🧹 Forget both remembered answers.
     *
     * For a test or a long-running script that changes a person's preference
     * and then asks again in the same process. Nothing in a web request needs
     * it: a request draws one page for one person.
     */
    public static function forgetReader(): void
    {
        self::$optedIn  = [];
        self::$language = [];
    }
}
