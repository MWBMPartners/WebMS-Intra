<?php
// Path: _install/place_words.php
/**
 * -----------------------------------------------------------------------------
 * Installer — what is said about the country and the time zone 🌍 (#942, D-133)
 * -----------------------------------------------------------------------------
 * The installer's Web addresses page asks for the installation's country and
 * the first organisation's time zone. The finish page then says what WILL
 * happen to each, and the last page says what DID. This file is the ONE place
 * those sentences are made, and the ONE place the database is read for them.
 *
 * WHY IT IS ITS OWN FILE. Each sentence has to be true in every case — a new
 * database and one the person chose to carry on with, something chosen and
 * nothing chosen — and round 2 of #942's independent check found that two of
 * the last page's sentences were pinned by nothing, because every finish the
 * installer's self-test can reach takes one path. A function of plain facts
 * can be called by that test for EVERY case, and the pages print exactly what
 * it returns (`tools/installer-selftest.php`, section N, does both).
 *
 * WHAT WAS WRONG BEFORE IT. The first version printed "UTC" and "no country is
 * known" whatever the database held; measured, a carried-on database's
 * organisation was on America/Chicago.
 *
 * Every function here uses only PHP, `mysqli` and Portal\Core\Countries, which
 * this file loads itself, so the installer (which has no autoloader) and the
 * self-test can both `require` it.
 *
 * @package   Portal\Install
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/942
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'Countries.php';

/**
 * 🌍 A stored country AS THE PORTAL READS IT: two letters in any case become
 * capitals, and anything else is no country at all ('').
 *
 * The same rule as `Countries::readRows()`, which every reader of the setting
 * goes through. The installer's sentences, and its decision whether to write
 * over a stored value, must judge it that way too: round 3 of #942's check
 * found the last page calling a stored "GBR" a country that "was kept" while
 * the portal ignored it and the country actually chosen was dropped, and a
 * stored "gb" reported as a conflict with a chosen United Kingdom.
 */
function installUsableCountry(string $stored): string
{
    $code = strtoupper(trim($stored));

    return preg_match('/^[A-Z]{2}$/', $code) === 1 ? $code : '';
}

/**
 * 🌍 What the database already holds for the two "where" questions: the
 * installation's country (its own row of `org.address.countryCode`) and the
 * first organisation's time zone (`tblSites.timezone` for organisation 1).
 *
 * Read rather than assumed, because a database the person chose to carry on
 * with may hold any country and any zone. `read` is false when the database
 * could not be asked; the sentences then say something true whatever it
 * holds.
 *
 * @param \mysqli|null $db A connection to the installation's database, or null.
 *
 * @return array{country: string, zone: string, read: bool}
 */
function installHeldPlace(?\mysqli $db): array
{
    $held = ['country' => '', 'zone' => '', 'read' => false];
    if ($db === null) {
        return $held;
    }
    try {
        $key  = 'org.address.countryCode';
        $stmt = $db->prepare('SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL ORDER BY `settingID` ASC LIMIT 1');
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $held['country'] = trim((string) ($row['settingValue'] ?? ''));
        $zone            = $db->query('SELECT `timezone` FROM `tblSites` WHERE `siteID` = 1');
        $zoneRow         = $zone !== false ? $zone->fetch_assoc() : null;
        $held['zone']    = trim((string) ($zoneRow['timezone'] ?? ''));
        $held['read']    = true;
    } catch (\Throwable $e) {
        return ['country' => '', 'zone' => '', 'read' => false];
    }

    return $held;
}

/**
 * 📝 The finish page's two lines: what the finish WILL do with the country and
 * the zone, given what the database holds.
 *
 * The rules are the finish's own: a country already held is kept unless this
 * run wrote it itself (installFillSetting()), and the zone is written only on
 * a database built from nothing ("fresh" or "wipe and start again").
 *
 * @param array{country: string, zone: string, read: bool} $held From installHeldPlace().
 * @param string $countryChosen     The country chosen on the Web addresses page, or ''.
 * @param string $zoneChosen        The zone chosen there, or ''.
 * @param string $ownEarlierCountry What this run itself wrote as the country earlier, or ''.
 * @param bool   $writesZone        True on a database built from nothing.
 *
 * @return array{country: string, zone: string}
 */
function installPreviewPlaceWords(array $held, string $countryChosen, string $zoneChosen, string $ownEarlierCountry, bool $writesZone): array
{
    $name       = static fn (string $code): string => \Portal\Core\Countries::name($code);
    // Judged as the portal reads it (installUsableCountry()): "gb" IS the
    // United Kingdom, and "GBR" is no country at all, so the finish writes the
    // chosen country over it (installFillSetting() is given the same rule).
    $heldText   = trim((string) ($held['country'] ?? ''));
    $heldCode   = installUsableCountry($heldText);
    $heldZone   = (string) ($held['zone'] ?? '');
    $keepsHeld  = $heldCode !== '' && $heldCode !== $ownEarlierCountry;
    $unusable   = $heldText !== '' && $heldCode === '';

    if (($held['read'] ?? false) !== true) {
        $country = $countryChosen === ''
            ? 'Not chosen. If this database already holds a country, it is kept; if not, no country is known until somebody sets one in Admin, Settings, Organisation.'
            : $name($countryChosen) . ', unless this database already holds a country, which is kept.';
    } elseif ($countryChosen === '' && $unusable === true) {
        $country = 'Not chosen. This database holds "' . $heldText . '" as its country, which is not a country code, so the '
            . 'portal ignores it and no country is in force until somebody sets one in Admin, Settings, Organisation.';
    } elseif ($countryChosen === '') {
        $country = $keepsHeld === true
            ? 'Not chosen here, so the country this database already holds is kept: ' . $name($heldCode) . '.'
            : 'Not chosen. No country is known until somebody sets one in Admin, Settings, Organisation.';
    } elseif ($unusable === true) {
        $country = $name($countryChosen) . '. This database holds "' . $heldText . '" as its country, which is not a country '
            . 'code and is ignored, so ' . $name($countryChosen) . ' replaces it.';
    } elseif ($keepsHeld === true && $heldCode !== $countryChosen) {
        $country = 'You chose ' . $name($countryChosen) . ', but this database already holds a country, '
            . $name($heldCode) . ', and it is kept. Change it later in Admin, Settings, Organisation.';
    } else {
        $country = $name($countryChosen);
    }

    if ($zoneChosen !== '' && $writesZone === true) {
        $zone = $zoneChosen;
    } elseif (($held['read'] ?? false) !== true) {
        $zone = $zoneChosen === ''
            ? 'Not chosen, so your organisation keeps the time zone this database holds (UTC on a new database) until somebody changes it in Admin, Organisations.'
            : 'You chose ' . $zoneChosen . ', but this database is being carried on with, so it keeps the time zone it holds. Change it in Admin, Organisations.';
    } elseif ($zoneChosen === '') {
        $zone = $heldZone !== ''
            ? 'Not chosen, so your organisation stays on ' . $heldZone . ', the time zone this database holds, until somebody changes it in Admin, Organisations.'
            : 'Not chosen, so your organisation keeps the time zone this database holds until somebody changes it in Admin, Organisations.';
    } else {
        $zone = 'You chose ' . $zoneChosen . ', but this database is being carried on with, so it keeps its own time zone, '
            . ($heldZone !== '' ? $heldZone : 'whatever it holds') . '. Change it in Admin, Organisations.';
    }

    return ['country' => $country, 'zone' => $zone];
}

/**
 * 📝 The last page's lines: what the finish DID with the country and the zone.
 *
 * @param array<string, mixed> $report The finish's own record: `country` (what
 *        the setting holds now, as the portal reads it), `countryTyped`,
 *        `countryHeldText` (what it held before, as text), `zoneTyped`, `zoneWritten`,
 *        `zoneKept` (chosen, but this database was carried on with) and
 *        `zoneNow` (organisation 1's zone, read back after the finish).
 *
 * @return array{country: string, countryNote: string, zone: string} Plain text;
 *         `countryNote` is '' when there is nothing to add.
 */
function installDonePlaceWords(array $report): array
{
    $country      = installUsableCountry((string) ($report['country'] ?? ''));
    $countryTyped = (string) ($report['countryTyped'] ?? '');
    // What the setting held BEFORE the finish, as text: only to say so when it
    // was not a country code (the portal ignores such a value).
    $heldText     = trim((string) ($report['countryHeldText'] ?? ''));
    $heldUnusable = $heldText !== '' && installUsableCountry($heldText) === '';
    $zoneTyped    = (string) ($report['zoneTyped'] ?? '');
    $zoneNow      = (string) ($report['zoneNow'] ?? '');

    $countryLine = $country === ''
        ? ($heldUnusable === true
            ? 'None is in force: this database holds "' . $heldText . '" as its country, which is not a country code, so the portal ignores it. Set one in Admin, Settings, Organisation.'
            : 'None is set, so no country is known: a new venue needs one typed in. Set it in Admin, Settings, Organisation.')
        : \Portal\Core\Countries::name($country) . '. Each organisation starts from it and can choose its own in Admin, Settings, Organisation.';
    $countryNote = '';
    if ($country !== '' && $heldUnusable === true) {
        $countryNote = 'This database held "' . $heldText . '" as its country, which is not a country code, so the country you chose replaced it.';
    } elseif ($country !== '' && $countryTyped === '') {
        $countryNote = 'You chose none, so the country this database already had saved was kept.';
    } elseif ($country !== '' && $country !== $countryTyped) {
        $countryNote = 'That is not the country you chose (' . \Portal\Core\Countries::name($countryTyped)
            . '). This database already had a country saved, and it was kept.';
    }

    if (($report['zoneWritten'] ?? false) === true) {
        $zoneLine = $zoneTyped . '. Change it in Admin, Organisations.';
    } elseif (($report['zoneKept'] ?? false) === true) {
        $zoneLine = 'Kept as it was' . ($zoneNow !== '' ? ', ' . $zoneNow : '')
            . ', because you chose to carry on with a database that already had one. The time zone you chose, '
            . $zoneTyped . ', was not saved: check it in Admin, Organisations.';
    } elseif ($zoneNow !== '') {
        $zoneLine = 'Not chosen, so your organisation is on ' . $zoneNow . ', the time zone this database holds. Change it in Admin, Organisations.';
    } else {
        $zoneLine = 'Not chosen, so your organisation keeps the time zone this database holds. Check it in Admin, Organisations.';
    }

    return ['country' => $countryLine, 'countryNote' => $countryNote, 'zone' => $zoneLine];
}
