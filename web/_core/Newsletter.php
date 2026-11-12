<?php
// Path: _core/Newsletter.php
/**
 * -----------------------------------------------------------------------------
 * Newsletter composer + sender 📰
 * -----------------------------------------------------------------------------
 * Self-contained provider abstraction: today the `internal` provider routes
 * through Portal\Core\Mailer; tomorrow a `mailermatt` provider drops in by
 * implementing the same dispatch interface (Newsletter::dispatch* contract).
 *
 *   newsletter.provider = internal   <-- THE ONLY ONE THIS RELEASE IMPLEMENTS
 *
 * ⚠ `mailermatt` and `mailchimp` are NAMES WITH NO ADAPTER BEHIND THEM. The
 *   switch in providerSend() accepts them and sends through the portal's own
 *   mailer instead, which is the safe choice -- a newsletter still goes out
 *   rather than silently failing -- but it is NOT what the setting says is
 *   happening. There is no admin page offering them; reaching that state
 *   needs somebody to type the value into the general Settings editor, and
 *   the newsletter page now says so when they have.
 *
 *   This line used to read "internal | mailermatt | mailchimp" as though all
 *   three worked. It is written out here because a setting that quietly means
 *   something other than what it says is worse than one that is missing.
 *
 * Renders blocks into themed HTML, resolves segment recipients, mints
 * per-recipient unsubscribe tokens, and rate-limits sends per-hour so we
 * don't trip shared-host SMTP caps.
 *
 * @package   Portal\Core
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/269
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use mysqli;

class Newsletter
{
    /**
     * 📰 The hidden field a form draws beside the newsletter choice to say
     * "this form showed the member that choice, for THIS organisation" (#875).
     * Its value is the organisation's number.
     */
    public const CHOICE_SHOWN_FIELD = 'newsletterShown';

    /** The newsletter choice's own tick box. Its value, when ticked, is exactly '1'. */
    public const CHOICE_FIELD = 'newsletterOptedIn';

    /**
     * 📰 The hidden "this form drew the newsletter choice" marker (#875).
     * Printed beside the tick box and ONLY there, the way
     * NotifyPrefs::shownMarker() is printed beside each notification switch
     * (#667). It carries the number of the organisation whose newsletter the
     * box was about, because a subscription belongs to one organisation and
     * the tick box says nothing about which.
     */
    public static function choiceShownMarker(int $siteId): string
    {
        return '<input type="hidden" name="' . self::CHOICE_SHOWN_FIELD . '" value="' . $siteId . '">';
    }

    /**
     * 📰 What a posted form asks for the member's newsletter choice in
     * organisation `$siteId` (#875): true (stay subscribed, or subscribe),
     * false (unsubscribe), or NULL - "change nothing" - when the form never
     * showed the choice FOR THAT ORGANISATION.
     *
     * WHY THE MARKER AND NOT THE TICK BOX. An unticked box is not sent at
     * all, so "the box did not arrive" is the same post as "the box was never
     * drawn". The preferences page draws it only while the newsletter is
     * switched on, so a page opened while it was OFF and saved after it was
     * switched ON wrote "opted out" for a member who was never shown a
     * choice. Ask whether the field ARRIVED, never what it contains (#677).
     *
     * WHY THE ORGANISATION (round 2 of #875's check). The save writes the
     * subscription of the organisation the request is in when it is POSTED,
     * which need not be the one the page was drawn for: with the organisation
     * chosen per session, a member can switch organisation in another tab. A
     * box drawn for one organisation must never set another's choice, so a
     * marker naming any other organisation answers null. Through the real
     * switch page the open form's token is also spent by the switch, so that
     * post is refused before it gets here (measured, section D of
     * tools/notify-prefs-selftest.php); this is the second line, and it does
     * not depend on how the form token is handled.
     *
     * EXACTLY '1' is a tick, nothing looser, as NotifyPrefs::changesFromPost()
     * decided for the switches.
     *
     * @param array<string,mixed> $post   Normally `$_POST`.
     * @param int                 $siteId The organisation the save would write for.
     */
    public static function choiceFromPost(array $post, int $siteId): ?bool
    {
        if (($post[self::CHOICE_SHOWN_FIELD] ?? null) !== (string) $siteId) {
            return null;
        }

        return ($post[self::CHOICE_FIELD] ?? null) === '1';
    }

    /**
     * Render an HTML body from a newsletter's block list. Pulls dynamic
     * content (announcements / events / prayers / sermon) at render time
     * so previews stay accurate up until send.
     */
    public static function renderHtml(int $newsletterId, int $siteId): string
    {
        $db = App::db();
        $blocks = [];
        $stmt = $db->prepare('SELECT blockType, payload FROM tblNewsletterContent WHERE newsletterID = ? ORDER BY position, contentID');
        if ($stmt !== false) {
            $stmt->bind_param('i', $newsletterId);
            $stmt->execute();
            $rs = $stmt->get_result();
            while ($r = $rs->fetch_assoc()) {
                $blocks[] = $r;
            }
            $stmt->close();
        }

        $out = '';
        foreach ($blocks as $b) {
            $type = (string) $b['blockType'];
            $cfg  = json_decode((string) ($b['payload'] ?? '{}'), true);
            if (is_array($cfg) === false) {
                $cfg = [];
            }
            $out .= self::renderBlock($type, $cfg, $siteId);
        }
        return $out;
    }

    /**
     * Resolve the recipient list (userID + emailAddress) for a given segment.
     * `ruleJson` shape today: {"all": true} or {"roles": ["volunteer", …]}.
     * Always excludes opted-out users (tblNewsletterSubscription.optedIn = 0).
     */
    public static function resolveSegment(int $siteId, ?int $segmentId): array
    {
        $db = App::db();
        $rule = ['all' => true];
        if ($segmentId !== null && $segmentId > 0) {
            $stmt = $db->prepare('SELECT ruleJson FROM tblNewsletterSegment WHERE segmentID = ? AND siteID = ? LIMIT 1');
            if ($stmt !== false) {
                $stmt->bind_param('ii', $segmentId, $siteId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row !== null) {
                    $decoded = json_decode((string) ($row['ruleJson'] ?? ''), true);
                    if (is_array($decoded) === true) {
                        $rule = $decoded;
                    }
                }
            }
        }

        $sql = 'SELECT DISTINCT u.userID, u.emailAddress, u.fullName '
            . 'FROM tblUsers u INNER JOIN tblUserSites us ON us.userID = u.userID '
            . 'LEFT JOIN tblNewsletterSubscription s ON s.userID = u.userID AND s.siteID = us.siteID ';
        $types  = 'i';
        $params = [$siteId];
        $where  = 'us.siteID = ? AND us.isActive = 1 AND u.isActive = 1 '
            . 'AND u.emailAddress IS NOT NULL AND u.emailAddress != \'\' '
            . 'AND (s.optedIn IS NULL OR s.optedIn = 1)';

        if (isset($rule['roles']) === true && is_array($rule['roles']) === true && count($rule['roles']) > 0) {
            $placeholders = implode(',', array_fill(0, count($rule['roles']), '?'));
            // 🏷️ #516: tblUserSites (aliased `us` earlier in this method) is
            //    already joined on `us.siteID = ?`, so tblUserRoles is tied
            //    to it here and tblRoles to tblUserRoles, otherwise a role
            //    held in a DIFFERENT organisation would count towards this
            //    segment.
            $sql .= 'INNER JOIN tblUserRoles ur ON ur.userID = u.userID AND ur.siteID = us.siteID '
                .  'INNER JOIN tblRoles r ON r.roleID = ur.roleID AND r.siteID = ur.siteID ';
            $where .= ' AND r.roleKey IN (' . $placeholders . ')';
            foreach ($rule['roles'] as $rk) {
                $types .= 's';
                $params[] = (string) $rk;
            }
        }

        $sql .= 'WHERE ' . $where . ' ORDER BY u.userID';
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rs = $stmt->get_result();
        $recipients = [];
        while ($r = $rs->fetch_assoc()) {
            $recipients[] = $r;
        }
        $stmt->close();
        return $recipients;
    }

    /**
     * Materialise per-recipient rows in tblNewsletterRecipient with a fresh
     * unsubscribe token per row. Idempotent — skips users already added.
     */
    public static function lockInRecipients(int $newsletterId, array $recipients): int
    {
        $db = App::db();
        $count = 0;
        $stmt = $db->prepare(
            'INSERT IGNORE INTO tblNewsletterRecipient (newsletterID, userID, emailAddress, unsubToken) '
            . 'VALUES (?, ?, ?, ?)'
        );
        if ($stmt === false) {
            return 0;
        }
        foreach ($recipients as $r) {
            $uid = (int) $r['userID'];
            $em  = (string) $r['emailAddress'];
            $tok = bin2hex(random_bytes(20));
            $stmt->bind_param('iiss', $newsletterId, $uid, $em, $tok);
            if ($stmt->execute() === true && $stmt->affected_rows > 0) {
                $count++;
            }
        }
        $stmt->close();
        return $count;
    }

    /** Why a newsletter cannot be sent: the portal has no address to write its links with. See cannotSendBecause(). */
    public const NOT_SENT_NO_ADDRESS = 'no-address';

    /**
     * ⏳ What a recipient's `errorMsg` holds while their copy is WAITING in
     * the mail queue (#592): kept, not yet sent. It is in `errorMsg`, the
     * recipient row's one free-text column, because that also keeps the row
     * out of the next pass (which takes rows with no `deliveredAt` and no
     * `errorMsg`), so a waiting copy is never sent a second time.
     * mailSettled() replaces it when the copy goes or fails.
     */
    public const WAITING = 'waiting in the mail queue';

    /**
     * 🔗 Why a newsletter for this organisation cannot be sent right now, or
     * '' when it can (#500).
     *
     * A newsletter is not sent without the portal's address: every copy must
     * carry a working "unsubscribe" link, and that link is a whole address.
     * The one reason today is NOT_SENT_NO_ADDRESS.
     *
     * WHY IT IS A QUESTION OF ITS OWN. dispatch() used to find this out
     * alone and answer "sent 0, failed 0", exactly what it answers for an
     * empty list. The page that called it had already marked the newsletter
     * as "sending", so the sender was shown "Sending in progress" and a
     * "Continue sending" button that could never finish, with no reason
     * (independent check, 12 October 2026). The page now asks this BEFORE
     * it locks anybody in or marks anything, and dispatch() gives the same
     * answer to any caller that did not ask.
     *
     * WHAT IT CANNOT SAY: that the mail will be accepted. It answers only
     * whether the links can be written.
     */
    public static function cannotSendBecause(int $siteId): string
    {
        return PortalAddress::absolute('unsubscribe', $siteId) === '' ? self::NOT_SENT_NO_ADDRESS : '';
    }

    /**
     * Dispatch a newsletter via the configured provider. Returns
     * ['sent' => N, 'failed' => N, 'waiting' => N, 'notSent' => why].
     * `notSent` is '' when a pass was made (even one that found nobody
     * waiting), and one of the NOT_SENT_* reasons when nothing was tried at
     * all; a caller must tell the sender that reason and not report it as
     * "sent 0". Caller is responsible for the status transition (sending →
     * sent) afterwards.
     *
     * `sent` counts copies a mail service ACCEPTED in this pass. `waiting`
     * counts copies that were only KEPT in the mail queue (#592): each is
     * marked WAITING, not delivered, and mailSettled() marks it delivered
     * (and counts it) when it goes, or failed when it never will. WHAT WAS
     * WRONG: a kept copy was marked "delivered" at once and counted as sent,
     * and nothing corrected that when it later failed.
     *
     * @return array{sent: int, failed: int, waiting: int, notSent: string}
     */
    public static function dispatch(int $newsletterId, int $siteId): array
    {
        $db = App::db();
        $settings = App::settings()['newsletter'] ?? [];
        $provider = (string) ($settings['provider'] ?? 'internal');
        $cap      = (int) ($settings['batchPerHour'] ?? 100);

        $news = null;
        $stmt = $db->prepare('SELECT title, subject FROM tblNewsletter WHERE newsletterID = ? AND siteID = ? LIMIT 1');
        if ($stmt !== false) {
            $stmt->bind_param('ii', $newsletterId, $siteId);
            $stmt->execute();
            $news = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
        if ($news === null) {
            return ['sent' => 0, 'failed' => 0, 'waiting' => 0, 'notSent' => ''];
        }
        // 🔗 Not sent without the portal's address (#500): see
        //    cannotSendBecause(). Nothing is sent and no recipient is
        //    marked, so the same newsletter can be sent once the address is
        //    set. The reason goes back to the caller AND into Admin, Errors.
        $notSent = self::cannotSendBecause($siteId);
        if ($notSent !== '') {
            Logger::errorPlatform('Newsletter', 'Error', 'NO_PORTAL_ADDRESS', 'A newsletter was not sent, because the portal does not know its own web address, so its unsubscribe link could not be written. Set the address at Admin, Settings, Organisation.', 'newsletter #' . $newsletterId);

            return ['sent' => 0, 'failed' => 0, 'waiting' => 0, 'notSent' => $notSent];
        }
        $subject = (string) ($news['subject'] ?? '') !== '' ? (string) $news['subject'] : (string) $news['title'];
        $html    = self::renderHtml($newsletterId, $siteId);

        $rs = $db->prepare(
            'SELECT recipientID, emailAddress, unsubToken FROM tblNewsletterRecipient '
            . 'WHERE newsletterID = ? AND deliveredAt IS NULL AND errorMsg IS NULL '
            . 'LIMIT ?'
        );
        if ($rs === false) {
            return ['sent' => 0, 'failed' => 0, 'waiting' => 0, 'notSent' => ''];
        }
        $rs->bind_param('ii', $newsletterId, $cap);
        $rs->execute();
        $pending = [];
        $result = $rs->get_result();
        while ($r = $result->fetch_assoc()) {
            $pending[] = $r;
        }
        $rs->close();

        $sent = 0;
        $failed = 0;
        $waiting = 0;
        foreach ($pending as $p) {
            $rid       = (int) $p['recipientID'];
            $email     = (string) $p['emailAddress'];
            $token     = (string) $p['unsubToken'];
            $body      = self::personalise($html, $newsletterId, $rid, $token, $siteId);
            $ok = self::providerSend($provider, $email, $subject, $body, $rid);
            if ($ok === true && Mailer::lastIsWaiting() === true) {
                // ⏳ Only KEPT: say "waiting", never "delivered". See WAITING.
                $mark = self::WAITING;
                $u = $db->prepare('UPDATE tblNewsletterRecipient SET errorMsg = ? WHERE recipientID = ?');
                if ($u !== false) {
                    $u->bind_param('si', $mark, $rid);
                    $u->execute();
                    $u->close();
                }
                $waiting++;
            } elseif ($ok === true) {
                $u = $db->prepare('UPDATE tblNewsletterRecipient SET deliveredAt = NOW() WHERE recipientID = ?');
                if ($u !== false) {
                    $u->bind_param('i', $rid);
                    $u->execute();
                    $u->close();
                }
                $sent++;
            } else {
                $errMsg = 'send-failed';
                $u = $db->prepare('UPDATE tblNewsletterRecipient SET errorMsg = ? WHERE recipientID = ?');
                if ($u !== false) {
                    $u->bind_param('si', $errMsg, $rid);
                    $u->execute();
                    $u->close();
                }
                $failed++;
            }
        }

        $bump = $db->prepare('UPDATE tblNewsletter SET sentCount = sentCount + ? WHERE newsletterID = ?');
        if ($bump !== false) {
            $bump->bind_param('ii', $sent, $newsletterId);
            $bump->execute();
            $bump->close();
        }

        return ['sent' => $sent, 'failed' => $failed, 'waiting' => $waiting, 'notSent' => ''];
    }

    /**
     * 📣 The mail queue's word on a copy that was WAITING: it went, or it
     * never will (#592). MailQueue::tellOrigin() calls this; nothing else
     * should.
     *
     * Only a row that still says WAITING is changed, so being told twice, or
     * about a row somebody has since dealt with by hand, changes nothing.
     * A copy that went is counted in the newsletter's `sentCount` here,
     * because the pass that kept it did not count it.
     *
     * @param int    $recipientId tblNewsletterRecipient.recipientID.
     * @param bool   $went        True: a mail service accepted it. False: it failed or expired.
     * @param string $to          Not used: the row holds its own address.
     */
    public static function mailSettled(\mysqli $db, int $recipientId, bool $went, string $to): void
    {
        $waiting = self::WAITING;
        if ($went === false) {
            $failed = 'send-failed';
            $stmt   = $db->prepare('UPDATE tblNewsletterRecipient SET errorMsg = ? WHERE recipientID = ? AND deliveredAt IS NULL AND errorMsg = ?');
            $stmt->bind_param('sis', $failed, $recipientId, $waiting);
            $stmt->execute();
            $stmt->close();

            return;
        }
        $stmt = $db->prepare('UPDATE tblNewsletterRecipient SET deliveredAt = NOW(), errorMsg = NULL WHERE recipientID = ? AND deliveredAt IS NULL AND errorMsg = ?');
        $stmt->bind_param('is', $recipientId, $waiting);
        $stmt->execute();
        $marked = $stmt->affected_rows === 1;
        $stmt->close();
        if ($marked === true) {
            $bump = $db->prepare('UPDATE tblNewsletter SET sentCount = sentCount + 1 WHERE newsletterID = (SELECT newsletterID FROM tblNewsletterRecipient WHERE recipientID = ?)');
            $bump->bind_param('i', $recipientId);
            $bump->execute();
            $bump->close();
        }
    }

    /**
     * Provider dispatch — swap by `newsletter.provider`. Today only
     * `internal` (via Mailer) is wired.
     *
     * ⚠ THIS USED TO SAY the `mailermatt` and `mailchimp` branches "throw to
     * make accidental selection visible". **They do not and never did** — read
     * the switch below: every arm, those two and the `default:`, calls
     * Mailer::send(). Falling back is the deliberate choice, because a
     * newsletter somebody has written and sent should go out rather than
     * disappear into an exception. What was missing was anybody being TOLD,
     * and that is now the Newsletter page's job: it warns when
     * `newsletter.provider` names a service this release does not implement.
     *
     * Corrected 29 October 2026 after an independent check found the file
     * carrying both versions at once — the header said "falls back", this said
     * "throws", eight lines above code that does neither of the two things one
     * of them described.
     *
     * @link https://github.com/MWBMPartners/webMailerMatt — pending public API
     */
    private static function providerSend(string $provider, string $to, string $subject, string $html, int $recipientId): bool
    {
        // 📮 A newsletter is BULK mail (#592, part 3): it goes after
        //    everything else, never uses the part of the hourly limit kept
        //    back for urgent mail, and once this request has used its own
        //    budget the rest is kept in the mail queue and sent by the job.
        //    So `true` here means "sent, or kept to be sent": the caller
        //    asks Mailer::lastIsWaiting() which. `origin` names the
        //    recipient's row, so that the queue can tell it what became of
        //    a kept copy (mailSettled()).
        $bulk = ['priority' => 'bulk', 'origin' => 'newsletter:' . $recipientId];
        switch ($provider) {
            case 'internal':
                return Mailer::send($to, $subject, $html, [], $bulk);
            case 'mailermatt':
                // TODO(#269 follow-up): webMailerMatt API adapter — reads
                // newsletter.mailermatt.apiKey + baseUrl and POSTs a send
                // request. Repo: https://github.com/MWBMPartners/webMailerMatt
                // Until that ships, fall back to internal so newsletters
                // still go out rather than silently failing.
                return Mailer::send($to, $subject, $html, [], $bulk);
            case 'mailchimp':
                // TODO(#269 follow-up): Mailchimp Transactional adapter.
                return Mailer::send($to, $subject, $html, [], $bulk);
            default:
                return Mailer::send($to, $subject, $html, [], $bulk);
        }
    }

    /**
     * Inject per-recipient tokens into the rendered HTML — unsubscribe URL,
     * open-tracking pixel (when enabled), click-tracking URL rewrites.
     *
     * Each of the three is a whole address from PortalAddress, for the
     * newsletter's own organisation (#500). They used to be built on a
     * private baseUrl() that took the host name from the request, so a
     * newsletter sent in "first part of the address" mode lost its
     * organisation's prefix. dispatch() refuses to send at all when there is
     * no address, so the links here are never empty on that path; called any
     * other way with no address, this returns the HTML untouched.
     */
    public static function personalise(string $html, int $newsletterId, int $recipientId, string $token, int $siteId): string
    {
        $settings = App::settings()['newsletter'] ?? [];
        $unsub = PortalAddress::absolute('unsubscribe?token=' . urlencode($token), $siteId);
        if ($unsub === '') {
            return $html;
        }

        $html = str_replace('{{UNSUB_URL}}', htmlspecialchars($unsub, ENT_QUOTES, 'UTF-8'), $html);

        if ((string) ($settings['trackClicks'] ?? '0') === '1') {
            // 🔗 Each counted link carries a TOKEN for an address recorded
            //    with this newsletter, never the address itself (#620). See
            //    linkToken(). A link that cannot be recorded is left exactly
            //    as written: the person still reaches it, it is only not
            //    counted.
            //
            //    WHAT WAS WRONG. The counted link used to carry the address
            //    (`&u=https://…`) and the counter forwarded to whatever
            //    address a request named, so anybody could write a link that
            //    wore the portal's address and landed on a site of their
            //    choosing. It also carried the address as it is written in
            //    the HTML, with `&` as `&amp;`, so a link whose own address
            //    had two query parts reached the wrong page; the address is
            //    now decoded before it is recorded.
            $html = preg_replace_callback(
                '/href="(https?:[^"]+)"/i',
                static function (array $m) use ($siteId, $newsletterId, $recipientId): string {
                    if (strpos($m[1], '/unsubscribe') !== false) {
                        return $m[0];
                    }
                    $token = self::linkToken($newsletterId, html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    if ($token === '') {
                        return $m[0];
                    }
                    $wrapped = PortalAddress::absolute('newsletter/track/click?n=' . $newsletterId
                        . '&r=' . $recipientId . '&l=' . $token, $siteId);
                    if ($wrapped === '') {
                        return $m[0];
                    }
                    return 'href="' . htmlspecialchars($wrapped, ENT_QUOTES, 'UTF-8') . '"';
                },
                $html
            );
        }

        if ((string) ($settings['trackOpens'] ?? '0') === '1') {
            $pixel = PortalAddress::absolute('newsletter/track/open?n=' . $newsletterId . '&r=' . $recipientId, $siteId);
            $html .= '<img src="' . htmlspecialchars($pixel, ENT_QUOTES, 'UTF-8') . '" width="1" height="1" alt="" style="border:0;display:block;">';
        }

        $html .= '<p style="font-size:11px;color:#888;margin-top:24px;text-align:center;">'
            . 'You\'re receiving this because you\'re a member. '
            . '<a href="' . htmlspecialchars($unsub, ENT_QUOTES, 'UTF-8') . '" style="color:#888;">Unsubscribe</a>.</p>';

        return $html;
    }

    /** 🔗 The longest address a counted link may stand for (the column's width). */
    public const LINK_MAX_LENGTH = 2048;

    /**
     * Tokens already worked out in this request, keyed "newsletterID|address",
     * so a pass that personalises a hundred copies asks the database once per
     * distinct link, not once per link per copy.
     *
     * @var array<string, string>
     */
    private static array $linkTokens = [];

    /**
     * 🔗 Can the click counter forward to this address? The ONE test, asked
     * when a link is recorded AND again whenever one is read back, so a row
     * written some other way (by hand, or by a restore) is still judged.
     *
     * An http or https address with a host, no longer than LINK_MAX_LENGTH,
     * that PHP's own URL test accepts, holding no space or control character
     * (a tab or line break inside an address is how a browser is made to read
     * one address as another, #536). Anything else is never forwarded to; a
     * link that fails it in a newsletter is simply left uncounted.
     */
    public static function isForwardable(string $address): bool
    {
        if ($address === '' || strlen($address) > self::LINK_MAX_LENGTH) {
            return false;
        }
        if (preg_match('/[\x00-\x20\x7F]/', $address) === 1 || preg_match('#^https?://#i', $address) !== 1) {
            return false;
        }
        if (filter_var($address, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return (string) parse_url($address, PHP_URL_HOST) !== '';
    }

    /**
     * 🔗 The token a counted link carries for one address in one newsletter,
     * recording the address the first time (#620). The ONE writer of
     * `tblNewsletterLink`.
     *
     * WHY A STORED LIST AND NOT A SIGNATURE. A signed address needs a key, a
     * new secret to keep; losing or replacing it would break every link in
     * every newsletter ever sent. A stored list needs no key, the link then
     * says nothing about where it goes, and the list is the record of what
     * the newsletter linked to. The token is random so one newsletter's links
     * cannot be listed by counting.
     *
     * Answers '' when the address is not one isForwardable() accepts, or when
     * it cannot be recorded (the table is missing because the upgrade has not
     * been run yet — files reach a server before anybody presses "Run
     * migrations" — or the database refused). The caller then leaves the link
     * as written. That direction is deliberate: a newsletter that has to go
     * out with some links uncounted is better than one that does not go out.
     * The fault is said once in PHP's error log, by its kind and number only:
     * a database's own message can quote the value it was given.
     */
    public static function linkToken(int $newsletterId, string $address): string
    {
        $key = $newsletterId . '|' . $address;
        if (isset(self::$linkTokens[$key]) === true) {
            return self::$linkTokens[$key];
        }
        if ($newsletterId <= 0 || self::isForwardable($address) === false) {
            return '';
        }
        $hash  = hash('sha256', $address);
        $token = bin2hex(random_bytes(16));
        try {
            $db = App::db();
            // ON DUPLICATE KEY, never INSERT IGNORE: IGNORE also turns "too
            // long" into a silent cut, and a cut address is a different one.
            $ins = $db->prepare(
                'INSERT INTO `tblNewsletterLink` (`newsletterID`, `linkToken`, `targetUrl`, `targetHash`) VALUES (?, ?, ?, ?) '
                . 'ON DUPLICATE KEY UPDATE `linkID` = `linkID`'
            );
            $ins->bind_param('isss', $newsletterId, $token, $address, $hash);
            $ins->execute();
            $ins->close();
            $sel = $db->prepare('SELECT `linkToken`, `targetUrl` FROM `tblNewsletterLink` WHERE `newsletterID` = ? AND `targetHash` = ? LIMIT 1');
            $sel->bind_param('is', $newsletterId, $hash);
            $sel->execute();
            $row = $sel->get_result()->fetch_assoc();
            $sel->close();
        } catch (\Throwable $e) {
            error_log('[WebMS-Intra] Newsletter ' . $newsletterId . ': a link could not be recorded for click counting, so it was sent uncounted (' . $e::class . ' ' . $e->getCode() . ').');

            return '';
        }
        // The stored address must be THIS address: two addresses sharing a
        // hash must never send a person to the other one.
        if (is_array($row) === false || (string) $row['targetUrl'] !== $address) {
            return '';
        }
        self::$linkTokens[$key] = (string) $row['linkToken'];

        return self::$linkTokens[$key];
    }

    /**
     * 🔗 Where a counted link goes: the address recorded for this token IN
     * THIS NEWSLETTER, or null. The ONE reader of `tblNewsletterLink`, and
     * the only place the click counter may take an address from (#620).
     *
     * Null for anything but 32 lower-case hex characters (a list sent as
     * `l[]=x` included), for a token recorded with a DIFFERENT newsletter,
     * and for a stored address isForwardable() now refuses. It does not catch
     * a database fault: a fault is a fault, and the portal's error page and
     * error log are the right answer to it, not "this link is no longer
     * valid".
     */
    public static function linkTarget(int $newsletterId, mixed $token): ?string
    {
        if ($newsletterId <= 0 || is_string($token) === false || preg_match('/^[0-9a-f]{32}$/', $token) !== 1) {
            return null;
        }
        $stmt = App::db()->prepare('SELECT `targetUrl` FROM `tblNewsletterLink` WHERE `linkToken` = ? AND `newsletterID` = ? LIMIT 1');
        $stmt->bind_param('si', $token, $newsletterId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $address = is_array($row) === true ? (string) $row['targetUrl'] : '';

        return self::isForwardable($address) === true ? $address : null;
    }

    /**
     * True when tblDemoDataRegister exists in the current database (#498).
     *
     * WHY THIS CHECK EXISTS AT ALL: the register table was added by
     * migration 194, well after this class was written. Code deployed after
     * that migration file exists can still run against a database that has
     * not been upgraded yet — files reach the server before an administrator
     * presses "Run migrations" — so this cannot assume the table is there.
     *
     * WHY SHOW TABLES LIKE, AND NOT A try/catch: `SHOW TABLES LIKE` answers
     * "does a table by this name exist" without ever failing because the
     * table is missing — it simply returns no rows in that case. That is
     * what lets this stay safe on an un-upgraded database while a genuinely
     * broken connection (or any other real database fault) still throws
     * an ordinary exception straight out of this method, exactly as any
     * other query in this file would. A blanket try/catch here would have
     * hidden that real fault behind the same "no demo data" answer as the
     * harmless, expected case — which is precisely the kind of silent
     * failure this codebase's standing rules say to avoid.
     */
    private static function demoRegisterExists(mysqli $db): bool
    {
        $result = $db->query("SHOW TABLES LIKE 'tblDemoDataRegister'");
        if ($result === false) {
            return false;
        }
        $exists = $result->num_rows > 0;
        $result->free();
        return $exists;
    }

    /**
     * Render a single content block to HTML. Dynamic blocks pull live
     * data from the relevant table at render time.
     */
    private static function renderBlock(string $type, array $cfg, int $siteId): string
    {
        $db = App::db();
        $esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        switch ($type) {
            case 'heading':
                $text = $esc((string) ($cfg['text'] ?? ''));
                return '<h2 style="font-family:Arial,sans-serif;color:#1b2330;margin:24px 0 8px;">' . $text . '</h2>';

            case 'text':
                $text = (string) ($cfg['text'] ?? '');
                return '<div style="font-family:Arial,sans-serif;font-size:15px;line-height:1.5;color:#1b2330;margin:8px 0;">'
                    . Markdown::render($text, ['allow_links' => true])
                    . '</div>';

            case 'image':
                $url = $esc((string) ($cfg['url'] ?? ''));
                $alt = $esc((string) ($cfg['alt'] ?? ''));
                if ($url === '') {
                    return '';
                }
                return '<p style="text-align:center;margin:16px 0;"><img src="' . $url . '" alt="' . $alt . '" style="max-width:100%;height:auto;"></p>';

            case 'divider':
                return '<hr style="border:none;border-top:1px solid #e5e7eb;margin:24px 0;">';

            case 'cta':
                $label = $esc((string) ($cfg['label'] ?? 'Read more'));
                $url   = $esc((string) ($cfg['url'] ?? '#'));
                return '<p style="text-align:center;margin:24px 0;">'
                    . '<a href="' . $url . '" style="background:#5e6ad2;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;display:inline-block;">' . $label . '</a>'
                    . '</p>';

            case 'announcements':
                $count = max(1, min(10, (int) ($cfg['count'] ?? 3)));
                $items = [];

                // 🛡️ Never let a demo announcement reach a real newsletter (#498).
                //
                //    Admin -> Maintenance -> Demo data creates a few made-up,
                //    clearly-titled "[DEMO]" announcements for training, and
                //    records the row number of each one in the new
                //    tblDemoDataRegister table. Before this check, a newsletter
                //    sent while demo data was loaded would have picked one of
                //    those up exactly like a real announcement — this query had
                //    no way to tell the difference.
                //
                //    The register table is new (migration 194), so code that
                //    reads it can run against a portal whose files have been
                //    uploaded but whose database has not been upgraded yet —
                //    that is an ordinary, expected state, not a fault, and must
                //    not show an error on this page. self::demoRegisterExists()
                //    answers "does the table exist" with SHOW TABLES LIKE, which
                //    never fails just because the named table is missing (it
                //    only returns zero rows) — so a genuinely broken database
                //    (for example a dropped connection) still surfaces as a real
                //    exception below instead of being read as "no demo data".
                //
                //    The register row must also belong to THIS organisation.
                //    Matching on the row number alone would also leave out a
                //    real announcement in another organisation that happens to
                //    reuse a number once used by demo data (a restore or an
                //    import can do that).
                //
                //    What this cannot do: a demo announcement that somebody has
                //    since edited into a real notice stays on the register -
                //    Wipe deliberately leaves changed rows in place and says so
                //    - and so stays out of newsletters until an administrator
                //    removes its register entry. Leaving a real notice out is
                //    the safe direction to be wrong in; sending a [DEMO] one to
                //    every member is not.
                //
                //    🛡️ Codex catch-up D2 (20 September 2026): WHAT WAS WRONG —
                //    migration 194's register can hold entries with a NULL
                //    siteID: it carried some rows forward from an earlier
                //    development draft that ran before this page recorded
                //    which organisation a demo row belonged to. "siteID = ?"
                //    is never true for a NULL siteID in SQL (NULL is never
                //    equal to anything, including itself), so a "[DEMO]" row
                //    from that earlier draft was NOT excluded, and a
                //    newsletter would have sent it out. THE FIX: also exclude
                //    a register entry with NO recorded organisation at all,
                //    from EVERY organisation's newsletter — the same safe
                //    direction the surrounding comment already chose (a real
                //    notice left out is safer than a demo one sent out). WHAT
                //    THIS CANNOT DO: an entry with no recorded organisation is
                //    never removed by Wipe (see demo-data.php's own header),
                //    so if a REAL announcement later reuses that same row
                //    number, it would also be excluded until the stale
                //    register entry is removed by hand — the identical
                //    trade-off the matched-organisation rule above already
                //    accepts for a reused row number.
                $demoFilterSql = '';
                if (self::demoRegisterExists($db) === true) {
                    $demoFilterSql = ' AND announcementID NOT IN '
                        . '(SELECT rowID FROM tblDemoDataRegister WHERE tableName = ? AND (siteID = ? OR siteID IS NULL))';
                }

                $stmt = $db->prepare(
                    'SELECT title, body FROM tblAnnouncements '
                    . 'WHERE siteID = ? AND isPublished = 1'
                    . $demoFilterSql
                    . ' ORDER BY createdAt DESC LIMIT ?'
                );
                if ($stmt !== false) {
                    if ($demoFilterSql !== '') {
                        // Same table name every time — the register is shared
                        // by several tables (users, announcements, memberships),
                        // and this block only ever wants the announcement rows.
                        $demoTableName = 'tblAnnouncements';
                        // Order follows the placeholders: the announcement's
                        // organisation, then the register's table name and
                        // organisation, then the count.
                        $stmt->bind_param('isii', $siteId, $demoTableName, $siteId, $count);
                    } else {
                        $stmt->bind_param('ii', $siteId, $count);
                    }
                    $stmt->execute();
                    $rs = $stmt->get_result();
                    while ($r = $rs->fetch_assoc()) {
                        $items[] = $r;
                    }
                    $stmt->close();
                }
                if ($items === []) {
                    return '';
                }
                $html = '<h3 style="font-family:Arial,sans-serif;color:#1b2330;margin:24px 0 8px;">Announcements</h3>';
                foreach ($items as $it) {
                    $html .= '<div style="margin:12px 0;padding:12px;border-left:3px solid #5e6ad2;background:#f8fafc;">'
                        . '<strong>' . $esc((string) $it['title']) . '</strong><br>'
                        . '<span style="color:#374151;">' . $esc(mb_substr((string) $it['body'], 0, 240)) . '</span></div>';
                }
                return $html;

            case 'events':
                // 👁️ #514 part P2 (and acceptance 9 of #514). WHAT WAS WRONG
                //    BEFORE: this block listed every published event of the
                //    organisation in the window — deleted ones included (there
                //    was no `isDeleted` test), and, once #514 imports events
                //    from outside calendars, every imported event whatever its
                //    level. A newsletter is emailed OUT of the portal to a whole
                //    list at once, so nothing narrower than "every member may
                //    see it" belongs in it.
                //    NOW: `isDeleted = 0`, plus the one shared rule,
                //    EventVisibility::where(), in "bulkMembers" mode: the
                //    organisation's OWN events as before (the recipients are its
                //    active members), and an imported event only at the public
                //    or members level — never selected groups or hidden. This
                //    mode binds no values. The fragment goes after the literal
                //    conditions (tools/audit-checks/check_sql_columns.py reads
                //    only those; tools/event-visibility-selftest.php checks the
                //    fragment's own column names).
                //    `locationName` used to be selected here although it was
                //    never printed; it is no longer selected.
                //    ⚠️ Each item is ONLY the name and the date. A location, a
                //    description or any other detail must NEVER be added here
                //    without the canSeeFull column
                //    (EventVisibility::fullDetailSelect()): an imported event
                //    can be listed while its details are limited.
                $days = max(1, min(60, (int) ($cfg['days'] ?? 14)));
                $items = [];
                $visibility = EventVisibility::where('e', EventVisibility::MODE_BULK_MEMBERS, 0, date('Y-m-d'));
                // 🕰️ "Upcoming" on EACH EVENT'S OWN CLOCK (#804). This was
                //    `BETWEEN NOW() AND DATE_ADD(NOW(), …)` -- the DATABASE
                //    SERVER's clock -- so an e-mail that cannot be corrected
                //    once sent could list as "upcoming" an event that had begun
                //    hours earlier, or leave out one about to start. Exact
                //    inside SQL, so `LIMIT 20` is filled from the right rows;
                //    the expression appears twice, so its values are bound twice.
                $nowOnClock = Events::nowOnItsOwnClock($db, 'e', $siteId);
                // ⏱️ SOONEST BY THE REAL MOMENT (#811). `ORDER BY startDateTime`
                //    compared readings from different clocks, so an Auckland
                //    20:00 was listed after a New York 10:00 on the same date
                //    although it happens first — and with `LIMIT 20` the sooner
                //    one could be the one left out of an e-mail that cannot be
                //    corrected once sent. `Events::soonestByRealStart()` is the
                //    ONE place that orders a "next N" list; the statement stays
                //    here, with an optional upper bound and limit.
                $read = static function (?string $upTo, ?int $max) use ($db, $siteId, $nowOnClock, $days, $visibility): array {
                    // The rule from EventVisibility::where() above (bulk-members
                    // mode) is appended to this statement as `$visibility['sql']`.
                    $stmt = $db->prepare(
                        'SELECT e.eventID, e.eventName, e.startDateTime, e.timezone FROM tblEvents e '
                        . 'WHERE e.siteID = ? AND e.isDeleted = 0 '
                        . 'AND `e`.`startDateTime` BETWEEN ' . $nowOnClock['sql'] . ' AND DATE_ADD(' . $nowOnClock['sql'] . ', INTERVAL ? DAY) '
                        . ($upTo === null ? '' : 'AND `e`.`startDateTime` <= CAST(? AS DATETIME) ')
                        . 'AND e.status = "published"'
                        . $visibility['sql'] . ' '
                        . 'ORDER BY e.startDateTime, e.eventID' . ($max === null ? '' : ' LIMIT ?')
                    );
                    if ($stmt === false) {
                        return [];
                    }
                    $stmt->bind_param(
                        'i' . $nowOnClock['types'] . $nowOnClock['types'] . 'i' . ($upTo === null ? '' : 's') . $visibility['types'] . ($max === null ? '' : 'i'),
                        ...array_merge([$siteId], $nowOnClock['params'], $nowOnClock['params'], [$days], $upTo === null ? [] : [$upTo],
                            $visibility['params'], $max === null ? [] : [$max])
                    );
                    $stmt->execute();
                    $got = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $stmt->close();

                    return $got;
                };
                $items = Events::soonestByRealStart($db, $siteId, 20, $read);
                if ($items === []) {
                    return '';
                }
                $html = '<h3 style="font-family:Arial,sans-serif;color:#1b2330;margin:24px 0 8px;">Upcoming events</h3><ul>';
                foreach ($items as $it) {
                    // #777 part two: a reading on the event's own clock, put into
                    // words as written. `strtotime()` of it moved a start inside the
                    // hour the clocks skip an hour on, in an e-mail that cannot be
                    // corrected once sent.
                    $when = (string) $it['startDateTime'];
                    $html .= '<li><strong>' . $esc((string) $it['eventName']) . '</strong> — '
                        . $esc(I18n::formatClockDateTime($when, 'D j M, H:i')) . '</li>';
                }
                return $html . '</ul>';

            case 'prayers':
                $items = [];
                try {
                    $stmt = $db->prepare('SELECT subject AS title, body FROM tblPrayerRequests WHERE siteID = ? AND status = "active" AND visibility = "congregation" ORDER BY createdAt DESC LIMIT 5');
                    if ($stmt !== false) {
                        $stmt->bind_param('i', $siteId);
                        $stmt->execute();
                        $rs = $stmt->get_result();
                        while ($r = $rs->fetch_assoc()) {
                            $items[] = $r;
                        }
                        $stmt->close();
                    }
                } catch (\Throwable $ignored) {
                    // Prayer Requests app not installed — silently skip.
                }
                if ($items === []) {
                    return '';
                }
                $html = '<h3 style="font-family:Arial,sans-serif;color:#1b2330;margin:24px 0 8px;">Prayer requests</h3>';
                foreach ($items as $it) {
                    $html .= '<p><strong>' . $esc((string) $it['title']) . '</strong><br>'
                        . $esc(mb_substr((string) $it['body'], 0, 180)) . '</p>';
                }
                return $html;

            case 'sermon':
                try {
                    $stmt = $db->prepare('SELECT recordingID, title, presenterText FROM tblRecording WHERE siteID = ? AND isPublished = 1 AND kind = "sermon" ORDER BY recordedAt DESC LIMIT 1');
                    if ($stmt !== false) {
                        $stmt->bind_param('i', $siteId);
                        $stmt->execute();
                        $r = $stmt->get_result()->fetch_assoc();
                        $stmt->close();
                        if ($r !== null) {
                            // A whole link for this organisation (#500); left out when there is no address to give.
                            $url = PortalAddress::absolute('recordings/view?id=' . (int) $r['recordingID'], $siteId);
                            return '<h3 style="font-family:Arial,sans-serif;color:#1b2330;margin:24px 0 8px;">Latest sermon</h3>'
                                . '<p><strong>' . $esc((string) $r['title']) . '</strong>'
                                . ($r['presenterText'] !== null ? ' — ' . $esc((string) $r['presenterText']) : '')
                                . ($url === '' ? '' : '<br><a href="' . $esc($url) . '">Listen online</a>') . '</p>';
                        }
                    }
                } catch (\Throwable $ignored) {
                    // Recordings app not installed — silently skip.
                }
                return '';
        }
        return '';
    }
}
