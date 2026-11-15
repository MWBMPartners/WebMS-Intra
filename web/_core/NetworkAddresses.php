<?php
// Path: web/_core/NetworkAddresses.php
/**
 * -----------------------------------------------------------------------------
 * Where the portal keeps network addresses, and for how long 🌐 (#1019)
 * -----------------------------------------------------------------------------
 * The owner's decision D-178 (12 November 2026): the portal keeps network
 * (IP) addresses for audit, security and support, and handles them as
 * personal data — every place that keeps one has a set keeping period, an
 * erasure reaches the ones on a person's own records, and the privacy notice
 * names them and says why they are kept.
 *
 * This class is the ONE place a page asks "where are addresses kept, and for
 * how long". It reads the `addressKept` sentences out of the personal-data
 * catalogue (`web/_core/personal-data-catalogue.php`), which is where each
 * table's handling is decided, so the privacy pages cannot drift from the
 * list an erasure and a data copy are answered from. It keeps NO words of its
 * own about any one table.
 *
 * WHAT GUARDS IT. `tools/audit-checks/check_network_address_periods.py`
 * fails when a column shaped like an address has no sentence, when a
 * sentence names a column the schema does not have, and when a sentence and
 * the pending list disagree about whether a keeping period is set.
 *
 * WHAT IT CANNOT DO: judge whether a sentence is TRUE. That is checked by a
 * person against the code that clears each column, the rule #706 set for a
 * catalogue `period`.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/1019
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

final class NetworkAddresses
{
    /**
     * 📋 Every place a network address is kept, in the catalogue's order.
     *
     * An unreadable catalogue answers an EMPTY list, and the caller says so:
     * a privacy page that cannot list the places must not look as though
     * there are none (see templates/network-addresses.php).
     *
     * @return list<array{table: string, column: string, words: string}>
     */
    public static function places(): array
    {
        $file = __DIR__ . DIRECTORY_SEPARATOR . 'personal-data-catalogue.php';
        if (is_readable($file) === false) {
            return [];
        }
        $catalogue = require $file;
        if (is_array($catalogue) === false) {
            return [];
        }

        $places = [];
        foreach ($catalogue as $table => $entry) {
            if (is_array($entry) === false || isset($entry['addressKept']) === false
                || is_array($entry['addressKept']) === false
            ) {
                continue;
            }
            foreach ($entry['addressKept'] as $column => $words) {
                $words = trim((string) $words);
                if ($words === '') {
                    continue;
                }
                $places[] = ['table' => (string) $table, 'column' => (string) $column, 'words' => $words];
            }
        }

        return $places;
    }
}
