<?php
// Path: web/_core/AddressFingerprint.php
/**
 * -----------------------------------------------------------------------------
 * Turning a network address into something storable 🔒 (#712)
 * -----------------------------------------------------------------------------
 * The ONE place that decides what the portal stores in place of a network
 * address. Never hash an address anywhere else, and never store one.
 *
 * -----------------------------------------------------------------------------
 * WHY A PLAIN SHA-256 IS NOT ENOUGH, MEASURED RATHER THAN ARGUED
 * -----------------------------------------------------------------------------
 * The first version of #712 used `hash('sha256', $ip)` and defended it with the
 * reasoning `Logger::sessionFingerprint()` (#622) gives. An independent check
 * showed that reasoning does not carry across, and the measurement is the whole
 * argument:
 *
 *   - This machine computes about 4.87 MILLION SHA-256 a second in
 *     single-threaded PHP. IPv4 has 2^32 addresses, so THE ENTIRE SPACE takes
 *     about fifteen minutes. The checker recovered a stored value back to
 *     192.0.2.44 in under a second by walking one /8.
 *   - A session identifier has around 128 bits of entropy, so #622's plain hash
 *     really is one-way in practice. An address has 32. Citing that precedent
 *     was comparing two unlike things.
 *   - The reason given for keeping it plain — "a clean-out must be able to make
 *     the same value in SQL" — is TRUE OF #622 AND FALSE HERE. Nothing
 *     recomputes this value in SQL: the live chat's clean-out is purely
 *     time-based (`createdAt < NOW() - INTERVAL ? SECOND`) and every comparison
 *     is made in PHP. So there was no constraint to honour.
 *
 * So the value is salted with the installation's own key, which is not in the
 * database and is therefore not in a database backup either. Guessing an
 * address now needs the key as well as the address.
 *
 * -----------------------------------------------------------------------------
 * THIS IS THE SCHEME THE PORTAL ALREADY USED FOR EXACTLY THIS
 * -----------------------------------------------------------------------------
 * `AssetRegister::saltedHash()` has salted an address this way since #410, for
 * the same purpose — recognising a repeat visitor without keeping their
 * address. #712's first version did not use it, and neither did it mention it.
 *
 * ASSET REGISTER KEEPS ITS OWN COPY ON PURPOSE and must not be pointed at this
 * class: its stored hashes are already on disk in `tblAssetScanLog.ipHash` and
 * `tblAssetAudit.ipHash`, and its own comment promises they are bit-identical
 * to the pre-#410 values. Changing how they are made would silently stop every
 * stored hash matching a newly computed one, so repeat-visitor counting would
 * quietly break with nothing to show for it.
 *
 * -----------------------------------------------------------------------------
 * WHAT THIS IS NOT
 * -----------------------------------------------------------------------------
 * It is a FINGERPRINT, not anonymity, and the difference matters when somebody
 * asks what the portal holds. Whoever has the installation's key AND a list of
 * addresses can test that list against stored values. What it stops is anybody
 * who has only the database — a backup, a copy, a stolen dump — learning which
 * addresses the portal has seen. That is the exposure #712 was about.
 *
 * It is also not a password hash. A slow hash (bcrypt, Argon2) would be wrong
 * here: these values are computed on a request path that already has work to
 * do, there is no attacker-controlled guessing loop against a single value, and
 * the salt is what closes the gap.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/712
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

final class AddressFingerprint
{
    /**
     * 🔒 The stored form of one network address. The empty string in, the empty
     *    string out, so that "no address" stays distinguishable from an address
     *    and can be kept as NULL.
     *
     * 64 characters of hex, always, which is what every column holding one is
     * sized for.
     */
    public static function of(string $address): string
    {
        if ($address === '') {
            return '';
        }

        return hash('sha256', self::salt() . '|' . $address);
    }

    /**
     * 🧂 The installation's own salt.
     *
     * The encryption key is used because it is already per-installation, is
     * already outside the web root, and is already outside the database — which
     * is the property that matters here: a database backup does not carry it.
     *
     * WHEN THE KEY CANNOT BE READ the portal version stands in, exactly as
     * `AssetRegister::saltedHash()` has always done. That is a per-release salt
     * rather than a per-installation one, so it is weaker — but it is still not
     * an unsalted hash, and a rate limit that stopped working because a key file
     * was unreadable would be worse than one using a weaker salt. A fully
     * installed portal never takes this path.
     */
    private static function salt(): string
    {
        $keyPath = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_auth_keys' . DIRECTORY_SEPARATOR . 'enc.key';
        if (is_readable($keyPath) === true) {
            $key = (string) file_get_contents($keyPath);
            if ($key !== '') {
                return $key;
            }
        }

        return defined('PORTAL_VERSION') === true ? (string) PORTAL_VERSION : 'webms-intra';
    }
}
