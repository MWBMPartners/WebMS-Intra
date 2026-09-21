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
 *   newsletter.provider = internal | mailermatt | mailchimp
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

    /**
     * Dispatch a newsletter via the configured provider. Returns
     * ['sent' => N, 'failed' => N]. Caller is responsible for the
     * status transition (sending → sent) afterwards.
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
            return ['sent' => 0, 'failed' => 0];
        }
        $subject = (string) ($news['subject'] ?? '') !== '' ? (string) $news['subject'] : (string) $news['title'];
        $html    = self::renderHtml($newsletterId, $siteId);

        $rs = $db->prepare(
            'SELECT recipientID, emailAddress, unsubToken FROM tblNewsletterRecipient '
            . 'WHERE newsletterID = ? AND deliveredAt IS NULL AND errorMsg IS NULL '
            . 'LIMIT ?'
        );
        if ($rs === false) {
            return ['sent' => 0, 'failed' => 0];
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
        foreach ($pending as $p) {
            $rid       = (int) $p['recipientID'];
            $email     = (string) $p['emailAddress'];
            $token     = (string) $p['unsubToken'];
            $body      = self::personalise($html, $newsletterId, $rid, $token, $siteId);
            $ok = self::providerSend($provider, $email, $subject, $body);
            if ($ok === true) {
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

        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * Provider dispatch — swap by `newsletter.provider`. Today only
     * `internal` (via Mailer) is wired. The `mailermatt` and `mailchimp`
     * branches throw to make accidental selection visible until their
     * adapters land.
     *
     * @link https://github.com/MWBMPartners/webMailerMatt — pending public API
     */
    private static function providerSend(string $provider, string $to, string $subject, string $html): bool
    {
        switch ($provider) {
            case 'internal':
                return Mailer::send($to, $subject, $html);
            case 'mailermatt':
                // TODO(#269 follow-up): webMailerMatt API adapter — reads
                // newsletter.mailermatt.apiKey + baseUrl and POSTs a send
                // request. Repo: https://github.com/MWBMPartners/webMailerMatt
                // Until that ships, fall back to internal so newsletters
                // still go out rather than silently failing.
                return Mailer::send($to, $subject, $html);
            case 'mailchimp':
                // TODO(#269 follow-up): Mailchimp Transactional adapter.
                return Mailer::send($to, $subject, $html);
            default:
                return Mailer::send($to, $subject, $html);
        }
    }

    /**
     * Inject per-recipient tokens into the rendered HTML — unsubscribe URL,
     * open-tracking pixel (when enabled), click-tracking URL rewrites.
     */
    public static function personalise(string $html, int $newsletterId, int $recipientId, string $token, int $siteId): string
    {
        $settings = App::settings()['newsletter'] ?? [];
        $base = self::baseUrl();
        $unsub = $base . '/unsubscribe?token=' . urlencode($token);

        $html = str_replace('{{UNSUB_URL}}', htmlspecialchars($unsub, ENT_QUOTES, 'UTF-8'), $html);

        if ((string) ($settings['trackClicks'] ?? '0') === '1') {
            $html = preg_replace_callback(
                '/href="(https?:[^"]+)"/i',
                static function (array $m) use ($base, $newsletterId, $recipientId): string {
                    $target = $m[1];
                    if (strpos($target, '/unsubscribe') !== false) {
                        return $m[0];
                    }
                    $wrapped = $base . '/newsletter/track/click?n=' . $newsletterId
                        . '&r=' . $recipientId . '&u=' . urlencode($target);
                    return 'href="' . htmlspecialchars($wrapped, ENT_QUOTES, 'UTF-8') . '"';
                },
                $html
            );
        }

        if ((string) ($settings['trackOpens'] ?? '0') === '1') {
            $pixel = $base . '/newsletter/track/open?n=' . $newsletterId . '&r=' . $recipientId;
            $html .= '<img src="' . htmlspecialchars($pixel, ENT_QUOTES, 'UTF-8') . '" width="1" height="1" alt="" style="border:0;display:block;">';
        }

        $html .= '<p style="font-size:11px;color:#888;margin-top:24px;text-align:center;">'
            . 'You\'re receiving this because you\'re a member. '
            . '<a href="' . htmlspecialchars($unsub, ENT_QUOTES, 'UTF-8') . '" style="color:#888;">Unsubscribe</a>.</p>';

        return $html;
    }

    /**
     * Resolve absolute URL base for outbound links (open / click / unsub).
     */
    public static function baseUrl(): string
    {
        $scheme = (($_SERVER['HTTPS'] ?? '') !== '' && (string) ($_SERVER['HTTPS'] ?? '') !== 'off') ? 'https' : 'http';
        return $scheme . '://' . ((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
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
                $days = max(1, min(60, (int) ($cfg['days'] ?? 14)));
                $items = [];
                $stmt = $db->prepare(
                    'SELECT eventName, startDateTime, locationName FROM tblEvents '
                    . 'WHERE siteID = ? AND status = "published" '
                    . 'AND startDateTime BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL ? DAY) '
                    . 'ORDER BY startDateTime LIMIT 20'
                );
                if ($stmt !== false) {
                    $stmt->bind_param('ii', $siteId, $days);
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
                $html = '<h3 style="font-family:Arial,sans-serif;color:#1b2330;margin:24px 0 8px;">Upcoming events</h3><ul>';
                foreach ($items as $it) {
                    $when = (string) $it['startDateTime'];
                    $html .= '<li><strong>' . $esc((string) $it['eventName']) . '</strong> — '
                        . $esc(date('D j M, H:i', (int) strtotime($when))) . '</li>';
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
                            $url = self::baseUrl() . '/recordings/view?id=' . (int) $r['recordingID'];
                            return '<h3 style="font-family:Arial,sans-serif;color:#1b2330;margin:24px 0 8px;">Latest sermon</h3>'
                                . '<p><strong>' . $esc((string) $r['title']) . '</strong>'
                                . ($r['presenterText'] !== null ? ' — ' . $esc((string) $r['presenterText']) : '')
                                . '<br><a href="' . $esc($url) . '">Listen online</a></p>';
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
