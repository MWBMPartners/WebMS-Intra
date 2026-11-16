<?php
// Path: _apps/admin/exchange-rates/index.php
/**
 * Admin — Exchange rates 💱 (#993; D-173, D-192)
 *
 * What the portal uses to compare spending with outside companies against a
 * spending limit when the two are in different currencies. It shows where the
 * rates come from, whether they are fresh, the rate each limit would use right
 * now, and the rates an administrator has typed as the fallback.
 *
 * WHO SEES WHAT. Any administrator opens the page. The switch, "Fetch now" and
 * the INSTALLATION's typed rates are a global administrator's (the handler,
 * `save.php`, is what enforces that; hiding a control is a courtesy). An
 * organisation's administrator can type and remove rates for their own
 * organisation, and those are used only for that organisation's own limit.
 *
 * THE SWITCH IS FILLED FROM THE INSTALLATION'S OWN ROW (#674), never from the
 * merged settings: Save writes `siteID IS NULL`, so what is drawn must be what
 * would be written back.
 *
 * NO PAGE SCRIPT: everything here is an ordinary form, so there is nothing for
 * the page rule (a nonce on every inline script) to refuse.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/993
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Currency;
use Portal\Core\ExchangeRates;
use Portal\Core\InstallationSettings;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$db      = App::db();
$isRoot  = App::isRootAdmin() === true;
$siteId  = Site::id();
$e       = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

// 🏢 The switch, from the installation's own row (see the header).
$fetchOn = (string) (InstallationSettings::value(ExchangeRates::FETCH_SETTING) ?? '0') === '1';

$status = ExchangeRates::status($db);
$installationCurrency = Currency::ofInstallation();
$organisationCurrency = Currency::ofOrganisation($siteId);

// 🧮 The rate each limit would use now, from each currency an outside company
//    bills in: the price list's own set (`ProviderSpend::billingCurrencies()`,
//    #993), so a company added to the list appears here with no edit.
$levels = [
    ['name' => 'the installation\'s limit', 'currency' => $installationCurrency, 'site' => null],
    ['name' => 'this organisation\'s own limit', 'currency' => $organisationCurrency, 'site' => $siteId],
];
$lines = [];
foreach ($levels as $level) {
    foreach (\Portal\Core\ProviderSpend::billingCurrencies() as $from) {
        if ($level['currency'] === '') {
            $lines[] = ['level' => $level['name'], 'from' => $from, 'to' => '', 'words' => 'No currency is set for this level, so no comparison can be made.', 'source' => ''];
            continue;
        }
        $rate = ExchangeRates::rate($db, $from, $level['currency'], $level['site']);
        if ($rate === null) {
            $lines[] = ['level' => $level['name'], 'from' => $from, 'to' => $level['currency'],
                'words' => 'No rate: spending with a company that bills in ' . $from . ' would be refused while a limit is in force.', 'source' => 'none'];
            continue;
        }
        $lines[] = ['level' => $level['name'], 'from' => $from, 'to' => $level['currency'],
            'words' => (string) ExchangeRates::crossRateText($db, $from, $level['currency'], $rate) . ' (from ' . $rate['asOf'] . ')',
            'source' => $rate['source']];
    }
}
$sourceWords = [
    'same'                 => 'same currency',
    'fetched'              => 'fetched from the European Central Bank',
    'entered-organisation' => 'typed by this organisation',
    'entered-installation' => 'typed for the installation',
    'none'                 => 'none',
    ''                     => '',
];

$installationRates = $isRoot === true ? ExchangeRates::enteredFor($db, null) : null;
$organisationRates = ExchangeRates::enteredFor($db, $siteId);

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
$csrf  = Auth::csrfToken();
$save  = Site::url('admin/exchange-rates/save');
$today = gmdate('Y-m-d');

$pageTitle   = 'Exchange rates';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => Site::url(''), 'Admin' => Site::url('admin'), 'Exchange rates' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';

/** One typed-rate list and its add form, for either level. */
$ratesCard = static function (string $title, string $scope, ?array $rows, bool $mayChange) use ($e, $csrf, $save, $today): void {
    ?>
    <div class="card mb-4" data-rates-level="<?php echo $e($scope); ?>">
        <div class="card-header"><strong><?php echo $e($title); ?></strong></div>
        <div class="card-body">
            <?php if ($rows === null): ?>
                <p class="text-muted" data-rates-unreadable>The typed rates for this level could not be read just now.</p>
            <?php elseif ($rows === []): ?>
                <p class="text-muted">No rates typed.</p>
            <?php else: ?>
                <div class="portal-data-list mb-3">
                    <?php foreach ($rows as $r): ?>
                        <div class="row py-1 border-bottom small align-items-center">
                            <div class="col-md-5">1 <?php echo $e($r['fromCurrency']); ?> = <?php echo $e(rtrim(rtrim($r['rate'], '0'), '.')); ?> <?php echo $e($r['toCurrency']); ?></div>
                            <div class="col-md-4 text-muted">as of <?php echo $e($r['asOf']); ?></div>
                            <div class="col-md-3 text-end">
                                <?php if ($mayChange === true): ?>
                                    <form method="post" action="<?php echo $e($save); ?>" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>">
                                        <input type="hidden" name="action" value="remove_rate">
                                        <input type="hidden" name="scope" value="<?php echo $e($scope); ?>">
                                        <input type="hidden" name="enteredID" value="<?php echo (int) $r['enteredID']; ?>">
                                        <button class="btn btn-sm btn-outline-danger" type="submit">Remove</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($mayChange === true): ?>
                <form method="post" action="<?php echo $e($save); ?>" class="row g-2">
                    <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>">
                    <input type="hidden" name="action" value="save_rate">
                    <input type="hidden" name="scope" value="<?php echo $e($scope); ?>">
                    <div class="col-6 col-md-2">
                        <label class="form-label small" for="from-<?php echo $e($scope); ?>">From</label>
                        <input class="form-control" id="from-<?php echo $e($scope); ?>" name="fromCurrency" maxlength="3" placeholder="USD" required>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small" for="to-<?php echo $e($scope); ?>">To</label>
                        <input class="form-control" id="to-<?php echo $e($scope); ?>" name="toCurrency" maxlength="3" placeholder="GBP" required>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small" for="rate-<?php echo $e($scope); ?>">1 &ldquo;from&rdquo; is this many &ldquo;to&rdquo;</label>
                        <input class="form-control" id="rate-<?php echo $e($scope); ?>" name="rate" inputmode="decimal" placeholder="0.7560" required>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small" for="asof-<?php echo $e($scope); ?>">Rate is from</label>
                        <input class="form-control" id="asof-<?php echo $e($scope); ?>" name="asOf" value="<?php echo $e($today); ?>" placeholder="YYYY-MM-DD" required>
                    </div>
                    <div class="col-12 col-md-2 d-flex align-items-end">
                        <button class="btn btn-primary w-100" type="submit">Save rate</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <?php
};
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo $e($flashType !== '' ? $flashType : 'info'); ?>"><?php echo $e($flashMsg); ?></div>
<?php endif; ?>

<h1 class="mb-3"><i class="fa-solid fa-coins me-2"></i>Exchange rates</h1>

<div class="alert alert-info" role="alert">
    <strong>What these are for.</strong> Each outside company (an AI service, a translation
    service, a text-message service, a transcription service) bills in its own currency, and
    the portal keeps what it spends with each in that currency. A rate is used only to compare
    that spending with a spending limit written in your own currency. Once a day the portal
    fetches the European Central Bank&rsquo;s published rates, which are free and need no
    account. If a rate is missing or older than <?php echo (int) ExchangeRates::FRESH_DAYS; ?> days, a rate typed
    below is used instead. <strong>With neither, spending with that company is refused</strong>,
    because the limit cannot be checked.
</div>

<div class="card mb-4" data-rates-section="fetch">
    <div class="card-header"><strong>The daily fetch</strong></div>
    <div class="card-body">
        <?php if ($status === null): ?>
            <div class="alert alert-danger mb-0" role="alert" data-rates-state="unreadable">
                <strong>The exchange-rate records could not be read.</strong> The usual cause is that the
                portal&rsquo;s files have been updated and the database upgrade has not been run yet &mdash; ask
                a global administrator to open Admin &rarr; Upgrade.
            </div>
        <?php else: ?>
            <div class="portal-data-list">
                <div class="row py-1 border-bottom small"><div class="col-md-4 text-muted">Fetching is</div><div class="col-md-8" data-rates-switch="<?php echo $fetchOn === true ? 'on' : 'off'; ?>"><?php echo $fetchOn === true ? 'on' : 'off'; ?></div></div>
                <div class="row py-1 border-bottom small"><div class="col-md-4 text-muted">Last fetched successfully</div><div class="col-md-8"><?php echo $status['lastSuccessUtc'] === null ? 'never' : $e($status['lastSuccessUtc']) . ' UTC'; ?></div></div>
                <div class="row py-1 border-bottom small"><div class="col-md-4 text-muted">Last tried</div><div class="col-md-8"><?php echo $status['lastAttemptUtc'] === null ? 'never' : $e($status['lastAttemptUtc']) . ' UTC'; ?></div></div>
                <?php if ($status['problem'] !== ''): ?>
                    <div class="row py-1 border-bottom small" data-rates-problem="<?php echo $e($status['problem']); ?>"><div class="col-md-4 text-muted">The last try failed because</div><div class="col-md-8"><?php echo $e(ExchangeRates::problemWords($status['problem'])); ?></div></div>
                <?php endif; ?>
                <?php if ($status['futureRows'] > 0): ?>
                    <div class="row py-1 border-bottom small" data-rates-future="<?php echo (int) $status['futureRows']; ?>"><div class="col-md-4 text-muted">Rates dated in the future</div><div class="col-md-8"><?php echo (int) $status['futureRows']; ?> rate(s) carry a date more than a day ahead, so they are not used. Only a later fetch that includes the same currency (while fetching is on) replaces one; a currency the bank does not publish stays until it is removed from the database.</div></div>
                <?php endif; ?>
                <?php if ($status['olderRows'] > 0): ?>
                    <div class="row py-1 border-bottom small" data-rates-older="<?php echo (int) $status['olderRows']; ?>"><div class="col-md-4 text-muted">Older rates kept</div><div class="col-md-8"><?php echo (int) $status['olderRows']; ?> rate(s) are from an earlier list (a currency the bank no longer publishes, perhaps). They are kept, and used only while they are fresh.</div></div>
                <?php endif; ?>
                <div class="row py-1 small"><div class="col-md-4 text-muted">Rates held</div><div class="col-md-8" data-rates-fresh="<?php echo $status['fresh'] === true ? 'fresh' : 'stale'; ?>">
                    <?php if ($status['rateDate'] === null): ?>
                        none yet
                    <?php else: ?>
                        <?php echo (int) $status['count']; ?> currencies, the bank&rsquo;s list of <?php echo $e($status['rateDate']); ?>
                        &mdash; <?php echo $status['fresh'] === true ? 'fresh' : '<strong>too old to use</strong>'; ?>
                    <?php endif; ?>
                </div></div>
            </div>
        <?php endif; ?>
        <?php if ($isRoot === true): ?>
            <hr>
            <form method="post" action="<?php echo $e($save); ?>" class="d-inline-block me-3">
                <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>">
                <input type="hidden" name="action" value="fetch_switch">
                <input type="hidden" name="fetchEnabledShown" value="1">
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" role="switch" id="fetchEnabled" name="fetchEnabled" value="1"<?php echo $fetchOn === true ? ' checked' : ''; ?>>
                    <label class="form-check-label" for="fetchEnabled">Fetch the European Central Bank&rsquo;s rates once a day (for every organisation on this installation)</label>
                </div>
                <button class="btn btn-primary btn-sm" type="submit">Save</button>
            </form>
            <form method="post" action="<?php echo $e($save); ?>" class="d-inline-block">
                <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>">
                <input type="hidden" name="action" value="fetch_now">
                <button class="btn btn-outline-secondary btn-sm" type="submit">Fetch now</button>
            </form>
            <p class="small text-muted mt-2 mb-0">The fetch is a step of the mail queue&rsquo;s scheduled job
                (<code>/cron/mail-queue</code>), so an installation that already runs that job gets it with no
                extra set-up. Fetching sends the bank nothing about anybody: only the request for its published list.</p>
        <?php else: ?>
            <p class="small text-muted mt-3 mb-0">Only a global administrator can switch the fetch on or off.</p>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-4" data-rates-section="in-force">
    <div class="card-header"><strong>The rate each limit would use now</strong></div>
    <div class="card-body">
        <div class="portal-data-list">
            <?php foreach ($lines as $l): ?>
                <div class="row py-1 border-bottom small" data-rates-source="<?php echo $e($l['source']); ?>">
                    <div class="col-md-4 text-muted">From <?php echo $e($l['from']); ?> for <?php echo $e($l['level']); ?></div>
                    <div class="col-md-5"><?php echo $e($l['words']); ?></div>
                    <div class="col-md-3 text-muted"><?php echo $e($sourceWords[$l['source']] ?? ''); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <p class="small text-muted mt-2 mb-0">The installation&rsquo;s limit never uses a rate typed by one
            organisation: the two may be run by different people, and an organisation must not be able to
            loosen the installation&rsquo;s limit.</p>
    </div>
</div>

<?php $ratesCard('Rates typed for this organisation', 'organisation', $organisationRates, true); ?>
<?php if ($isRoot === true): ?>
    <?php $ratesCard('Rates typed for the whole installation', 'installation', $installationRates, true); ?>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
