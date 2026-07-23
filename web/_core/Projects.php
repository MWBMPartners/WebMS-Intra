<?php
// Path: _core/Projects.php
/**
 * -----------------------------------------------------------------------------
 * Project fundraising helpers 🎯
 * -----------------------------------------------------------------------------
 * Slug generation, pledge totals, and fulfilment bridge into the Giving app
 * (#266) when a payment lands. When the goal is crossed the project status
 * auto-flips to `funded` and a thank-you email goes out to contributors who
 * supplied an address.
 *
 * Amounts in pence (minor units) — reuses Giving::parseAmount / formatAmount
 * when the Giving app is installed.
 *
 * @package   Portal\Core
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/267
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class Projects
{
    /**
     * Sum of fulfilled pledges for a project (used by the thermometer).
     */
    public static function raisedPence(int $projectId): int
    {
        $db = App::db();
        $stmt = $db->prepare('SELECT COALESCE(SUM(amountPence), 0) FROM tblProjectPledge WHERE projectID = ? AND fulfilledAt IS NOT NULL');
        if ($stmt === false) {
            return 0;
        }
        $stmt->bind_param('i', $projectId);
        $stmt->execute();
        $stmt->bind_result($s);
        $stmt->fetch();
        $stmt->close();
        return (int) $s;
    }

    /**
     * Sum of pledged-but-not-yet-fulfilled, surfaced separately so the
     * thermometer can show "raised vs pledged" if the project owner wants.
     */
    public static function pledgedPence(int $projectId): int
    {
        $db = App::db();
        $stmt = $db->prepare('SELECT COALESCE(SUM(amountPence), 0) FROM tblProjectPledge WHERE projectID = ? AND fulfilledAt IS NULL');
        if ($stmt === false) {
            return 0;
        }
        $stmt->bind_param('i', $projectId);
        $stmt->execute();
        $stmt->bind_result($s);
        $stmt->fetch();
        $stmt->close();
        return (int) $s;
    }

    /**
     * Build a URL-safe slug from a title. Falls back to a random hex suffix
     * if the resulting slug is empty (e.g. for non-Latin titles).
     */
    public static function slugify(string $title): string
    {
        $s = strtolower(trim($title));
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        $s = trim((string) $s, '-');
        if ($s === '') {
            $s = 'project-' . bin2hex(random_bytes(3));
        }
        return substr($s, 0, 180);
    }

    /**
     * Pick a fresh slug for a new project on this site — appends -2, -3,
     * etc. if there's a clash. Stops looking after 100 tries (vanishingly
     * unlikely on any real site).
     */
    public static function uniqueSlug(int $siteId, string $title): string
    {
        $db = App::db();
        $base = self::slugify($title);
        $candidate = $base;
        for ($i = 2; $i <= 100; $i++) {
            $stmt = $db->prepare('SELECT 1 FROM tblProject WHERE siteID = ? AND slug = ? LIMIT 1');
            if ($stmt === false) {
                return $candidate;
            }
            $stmt->bind_param('is', $siteId, $candidate);
            $stmt->execute();
            $taken = $stmt->get_result()->fetch_row() !== null;
            $stmt->close();
            if ($taken === false) {
                return $candidate;
            }
            $candidate = $base . '-' . $i;
        }
        return $base . '-' . bin2hex(random_bytes(3));
    }

    /**
     * Mark a pledge fulfilled. When the Giving app is installed, also
     * insert a matching tblGivingEntry so the donation lands in the
     * site's contributions log. If the project crosses its target this
     * fulfilment, flips status to `funded` and dispatches a thank-you.
     */
    public static function fulfilPledge(int $pledgeId, int $siteId, ?int $treasurerId, ?int $givingCategoryId = null): bool
    {
        $db = App::db();
        $stmt = $db->prepare(
            'SELECT p.pledgeID, p.projectID, p.donorID, p.amountPence, p.fulfilledAt, '
            . '       pr.title, pr.targetAmountPence, pr.status, pr.currency '
            . 'FROM tblProjectPledge p INNER JOIN tblProject pr ON pr.projectID = p.projectID '
            . 'WHERE p.pledgeID = ? AND pr.siteID = ? LIMIT 1'
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('ii', $pledgeId, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row === null || $row['fulfilledAt'] !== null) {
            return false;
        }

        $projectId   = (int) $row['projectID'];
        $donorId     = $row['donorID'] !== null ? (int) $row['donorID'] : null;
        $amount      = (int) $row['amountPence'];
        $currency    = (string) ($row['currency'] ?? 'GBP');

        // Optional Giving entry — only when the app is installed and a category is given.
        $givingEntryId = null;
        if ($givingCategoryId !== null && $givingCategoryId > 0 && $donorId !== null) {
            try {
                $reference = 'project:' . $projectId;
                $notes     = 'Pledge fulfilled — ' . (string) $row['title'];
                $today     = date('Y-m-d');
                $method    = 'card';

                // 🎯 Auto-attribution (#299 follow-up) — project-pledge
                // fulfilment has no explicit campaign selector, so Auto (0)
                // is the only mode here; see Giving::attributeGift() for the
                // full rule. $donorId is already guaranteed non-null by the
                // outer if, but 0/unknown still maps to null defensively.
                $donorForAttr = $donorId > 0 ? $donorId : null;
                $attr         = Giving::attributeGift($siteId, $donorForAttr, $today, 0);
                $campBind     = $attr['campaignID'];
                $pledgeBind   = $attr['pledgeID'];

                $ins = $db->prepare(
                    'INSERT INTO tblGivingEntry (siteID, donorID, categoryID, amountPence, currency, donatedAt, method, reference, notes, recordedByID, campaignID, pledgeID) '
                    . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                if ($ins !== false) {
                    $ins->bind_param(
                        'iiiisssssiii',
                        $siteId, $donorId, $givingCategoryId, $amount, $currency, $today, $method, $reference, $notes, $treasurerId, $campBind, $pledgeBind
                    );
                    if ($ins->execute() === true) {
                        $givingEntryId = (int) $ins->insert_id;
                    }
                    $ins->close();
                }
            } catch (\Throwable $ignored) {
                // Giving app not installed — leave entry id NULL.
            }
        }

        $u = $db->prepare('UPDATE tblProjectPledge SET fulfilledAt = NOW(), givingEntryID = ? WHERE pledgeID = ?');
        if ($u !== false) {
            $u->bind_param('ii', $givingEntryId, $pledgeId);
            $u->execute();
            $u->close();
        }

        // Did this push the project over its target?
        $raised = self::raisedPence($projectId);
        if ($raised >= (int) $row['targetAmountPence'] && (string) $row['status'] === 'active') {
            $f = $db->prepare('UPDATE tblProject SET status = "funded" WHERE projectID = ?');
            if ($f !== false) {
                $f->bind_param('i', $projectId);
                $f->execute();
                $f->close();
            }
            self::dispatchGoalReachedEmails($projectId);
        }

        return true;
    }

    /**
     * Thank-you email to every contributor with a known email address when
     * the project hits its target. Best-effort — swallows failures so the
     * fulfilment commit doesn't get rolled back by a flaky SMTP.
     */
    private static function dispatchGoalReachedEmails(int $projectId): void
    {
        $db = App::db();
        $project = null;
        $stmt = $db->prepare('SELECT title FROM tblProject WHERE projectID = ? LIMIT 1');
        if ($stmt !== false) {
            $stmt->bind_param('i', $projectId);
            $stmt->execute();
            $project = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
        if ($project === null) {
            return;
        }
        $recipients = [];
        $stmt = $db->prepare(
            'SELECT DISTINCT COALESCE(u.emailAddress, p.donorEmail) AS email '
            . 'FROM tblProjectPledge p LEFT JOIN tblUsers u ON u.userID = p.donorID '
            . 'WHERE p.projectID = ? AND COALESCE(u.emailAddress, p.donorEmail) IS NOT NULL'
        );
        if ($stmt !== false) {
            $stmt->bind_param('i', $projectId);
            $stmt->execute();
            $rs = $stmt->get_result();
            while ($r = $rs->fetch_assoc()) {
                if ($r['email'] !== null && $r['email'] !== '') {
                    $recipients[] = (string) $r['email'];
                }
            }
            $stmt->close();
        }
        if ($recipients === []) {
            return;
        }
        $title = (string) $project['title'];
        $body  = '<p>Great news — <strong>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
            . '</strong> has reached its fundraising target. Thank you for your contribution!</p>';
        foreach ($recipients as $to) {
            try {
                Mailer::send($to, 'Goal reached: ' . $title, $body);
            } catch (\Throwable $ignored) {
                // skip individual SMTP failures.
            }
        }
    }
}
