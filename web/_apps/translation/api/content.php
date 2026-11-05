<?php
// Path: _apps/translation/api/content.php
/**
 * -----------------------------------------------------------------------------
 * Translate one piece of the organisation's published content 🌐
 * -----------------------------------------------------------------------------
 * Address: `api/translation/content`, switch `api.translation.content.enabled`.
 *
 *   POST sourceTable=tblAnnouncements  sourceID=42  targetLanguage=cy
 *        csrf_token=…
 *
 * Answers the portal's usual envelope, so `meta.csrfToken` carries the
 * replacement form token — a page may translate several pieces without
 * reloading, and `Auth::verifyCsrf()` replaces the token on every acceptance
 * (#625). Every refusal reached AFTER that rotation carries it as well.
 *
 * **WITH ONE EXCEPTION THAT MATTERS: a REFUSED form token carries none.**
 * `ApiAuth::requireWrite()` answers that refusal before it records the
 * rotation, so `meta.csrfToken` is absent exactly where a caller would most
 * want it. The script therefore asks the member to reload rather than showing
 * them the portal's own words, and it keeps the token for the WHOLE PAGE —
 * every control and every ordinary form — so this refusal should not arise
 * from a second press. An earlier version of this comment said every refusal
 * carried the replacement, which was untrue in the reassuring direction.
 *
 * WHAT THIS REPLACES, AND WHY IT IS A REWRITE RATHER THAN A MOVE
 * -------------------------------------------------------------
 * `web/_apps/api/translate.php` (deleted in the same change) took the text to
 * be translated FROM THE REQUEST, looked up nothing, and asked nothing about
 * who was reading. It could not be reached — `ApiRouter` wants three segments
 * and that address had two — so nothing was ever exposed; but finishing #485
 * is precisely the act of making it reachable, and #761 records the three
 * faults that would have gone live together. The gate and the one place the
 * words are read are both in `Portal\Core\ContentTranslation`, so this file
 * decides nothing of its own.
 *
 * NOT FOR AN API KEY, on purpose. A bearer key belongs to a system, and
 * translating sends a reading to an outside company on a MEMBER'S OWN
 * permission (D-049). A key has no such permission to give, so a bearer
 * request is refused here rather than falling through to be refused later for
 * the less obvious reason that it has no account to look an opt-in up for.
 *
 * @package   Portal\API
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/485
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/761
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\ApiAuth;
use Portal\Core\ApiResponse;
use Portal\Core\App;
use Portal\Core\ContentTranslation;
use Portal\Core\Site;
use Portal\Core\Translation;

ApiAuth::requireMethod('POST');

// 🔑 Refused before anything is resolved: see the file comment. A key cannot
//    hold a member's own permission, and the message says that rather than
//    leaving somebody to work it out from a later refusal.
if (ApiAuth::isBearer() === true) {
    ApiResponse::error(
        'Translation is for a signed-in member, because translating sends the text '
        . 'to an outside company on that member\'s own permission. An API key cannot '
        . 'give that permission.',
        403
    );
}

// 🔒 A MEMBER's write, not an administrator's — the second argument is what
//    says so. Every other write endpoint here needs an administrator; this is
//    the first that does not, because reading something in your own language
//    is not an administrative act.
$body = ApiAuth::requireWrite('translation:write', false);

$db     = App::db();
$siteId = Site::id();
$userId = (int) (App::user()['userID'] ?? 0);

$sourceTable = (string) ($body['sourceTable'] ?? '');
$sourceId    = (int) ($body['sourceID'] ?? 0);
$targetLang  = (string) ($body['targetLanguage'] ?? '');

// ❓ ONE gate, the same one the page asked before drawing the control. The
//    page and this handler cannot disagree, because neither holds a copy of
//    the rule (#647).
$settings = App::settings()['translation'] ?? [];
$why = ContentTranslation::whyNotOffered([
    'signedIn'       => $userId > 0,
    'enabled'        => (string) ($settings['enabled'] ?? '0') === '1',
    'optedIn'        => ContentTranslation::readerOptedIn($userId),
    'sourceTable'    => $sourceTable,
    'targetLanguage' => $targetLang,
]);
if ($why !== '') {
    ApiResponse::error(ContentTranslation::refusalWords($why), $why === 'sign-in' ? 401 : 403);
}

// 📖 The words come from the DATABASE, through the row's own visibility rule.
//    A row of another organisation, an unpublished one or one that has expired
//    is simply NOT FOUND, so the refusal never says whether it exists (#647's
//    ownership-before-status rule). This is fault 2 of #761: the old handler
//    took the words from the request, so the allow-list of tables constrained
//    the label and not the text.
$text = ContentTranslation::readSourceText($db, $sourceTable, $sourceId, $siteId);
if ($text === null) {
    ApiResponse::error(ContentTranslation::refusalWords('not-found'), 404);
}

$sourceLang = Translation::detect($text);
$out = Translation::translate(
    $sourceTable,
    $sourceId,
    ContentTranslation::translatableFields()[$sourceTable],
    $sourceLang,
    $targetLang,
    $text
);

// 🟰 null means the source is already in the language asked for, so nothing
//    was sent and nothing was charged. It is not a failure, and the answer
//    says which it is rather than quietly returning the same words.
if ($out === null) {
    ApiResponse::success([
        'translation'     => $text,
        'sameLanguage'    => true,
        'sourceLanguage'  => $sourceLang,
        'message'         => ContentTranslation::refusalWords('already-that-language'),
    ]);
}

// ⛔ false covers three different things and the engine does not say which:
//    switched off, the month's limit reached, or the outside company did not
//    answer. The first cannot happen here (the gate above tested it), so the
//    message names the two that can and does not assert either. Saying "the
//    limit is reached" when the service was merely down would be a false
//    statement about the customer's money.
if ($out === false) {
    $cap = Translation::capPence();
    $spent = Translation::monthSpendPence();
    $reason = ($cap > 0 && $spent >= $cap) ? 'cap-reached' : 'provider-failed';
    ApiResponse::error(ContentTranslation::refusalWords($reason), 503);
}

ApiResponse::success([
    'translation'    => $out,
    'sameLanguage'   => false,
    'sourceLanguage' => $sourceLang,
    'targetLanguage' => Translation::normaliseLocale($targetLang),
]);
