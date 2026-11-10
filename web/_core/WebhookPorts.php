<?php
// Path: _core/WebhookPorts.php
/**
 * -----------------------------------------------------------------------------
 * Which port numbers a webhook may use 🪝🚪 (#615, the owner's decision D-102)
 * -----------------------------------------------------------------------------
 * A webhook is an automatic message the portal sends to another system's web
 * address. #615 sends every one through `SafeFetch::post()`, which on its own
 * allows only the two standard web ports (443 for https, 80 for http) — the
 * rule the outside-calendar fetcher has always had, because an unusual port is
 * the classic way to reach a service that was never meant to face the
 * internet. The owner decided (D-102, 10 November 2026) that for WEBHOOKS this
 * is an INSTALLATION setting, standard ports by default: the installation's
 * global administrator may allow any port from 1 to 65535 on a PUBLIC address.
 * Private and internal addresses stay refused whatever it says — that is
 * `SafeFetch::check()`'s job and this class has no say in it.
 *
 * THIS IS THE ONE PLACE THAT ANSWERS IT. The save page and the dispatcher both
 * ask `allowsAnyPort()`, so a webhook cannot be saved under one answer and
 * delivered under another.
 *
 * WHY THE INSTALLATION'S ROW ONLY, AND NEVER AN ORGANISATION'S. Whoever runs
 * the installation may be a different party from the organisations on it (a
 * hosting provider and its customers is the ordinary case), and a port rule
 * protects the HOST's network, which is the installation's to decide. So the
 * value is read from `InstallationSettings::layer()` — the installation-wide
 * rows alone — and an organisation's own row of the same name is IGNORED: the
 * merged `$SETTINGS` would have let it win, which is exactly an organisation
 * loosening a boundary that is not its own (D-013). And because the layer is
 * empty when start-up has not loaded it, "we could not tell" reads as
 * standard ports only: the recoverable direction.
 *
 * WHERE IT IS SET. The webhooks admin page, by a global administrator only,
 * and every change is recorded in the activity log — the precedent is the
 * installation's payload period on the same page and `OutsideServices`. The
 * general settings editor refuses the key on every path
 * (`lockedInGeneralEditor()`), because it would neither check who is asking
 * nor record the change. Nothing seeds it: a missing value IS the default.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/615
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use mysqli;

final class WebhookPorts
{
    /** The setting. `1` allows any port on a public address; anything else is standard ports only. */
    public const KEY = 'webhooks.anyPort';

    /** What the general settings editor says when it refuses the key. */
    public const LOCKED_MESSAGE = 'Which port numbers a webhook may use is set by a global administrator on the '
        . 'Webhooks page (Admin, Integrations, Webhooks), where the change is recorded. It cannot be changed here.';

    /**
     * May a webhook use a port other than 443 or 80? Read from the
     * INSTALLATION's row alone; only the exact text `1` says yes.
     */
    public static function allowsAnyPort(): bool
    {
        $layer = InstallationSettings::layer();
        $value = $layer['webhooks']['anyPort'] ?? null;
        return is_scalar($value) === true && trim((string) $value) === '1';
    }

    /**
     * 🔒 May the GENERAL settings editor (/settings) change, add or delete
     * this key? No: see the class header. Names are compared without regard
     * to capitals, as the database does.
     */
    public static function lockedInGeneralEditor(string $key): bool
    {
        return strcasecmp(trim($key), self::KEY) === 0;
    }

    /**
     * Save the installation's choice. Asks `$globalAdmin` itself as well as
     * the handler (the #687 precedent: the boundary is stated at the writer
     * too). Records the change, or the refusal, in the activity log.
     *
     * @return array{saved: bool, message: string}
     */
    public static function saveForInstallation(mysqli $db, bool $allowAnyPort, bool $globalAdmin, ?int $userId): array
    {
        if ($globalAdmin === false) {
            Logger::activity(
                'WebhookPortsRefused',
                'Which port numbers a webhook may use was posted by an administrator who is not a global '
                . 'administrator; nothing was saved',
                $userId
            );
            return ['saved' => false, 'message' => 'Only a global administrator can choose which port numbers '
                . 'webhooks may use, because it protects the whole installation. Nothing was saved.'];
        }
        $before = self::allowsAnyPort();
        $value  = $allowAnyPort === true ? '1' : '0';
        $key    = self::KEY;
        $stmt   = $db->prepare(
            'INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) '
            . 'VALUES (NULL, ?, ?, \'0\', 0) ON DUPLICATE KEY UPDATE `settingValue` = VALUES(`settingValue`)'
        );
        $stmt->bind_param('ss', $key, $value);
        $stmt->execute();
        $stmt->close();
        Logger::activity(
            'WebhookPortsChanged',
            'Webhooks may now use ' . ($allowAnyPort === true
                ? 'any port number on a public address'
                : 'only the standard ports (443 and 80)')
            . '; before, ' . ($before === true ? 'any port number' : 'only the standard ports')
            . ' (installation-wide)',
            $userId
        );
        return ['saved' => true, 'message' => $allowAnyPort === true
            ? 'Webhooks may now use any port number on a public address. Private and internal addresses are still refused.'
            : 'Webhooks may now use only the standard ports (443 for https, 80 for http). A webhook already saved with '
                . 'another port will be refused at its next delivery.'];
    }
}
