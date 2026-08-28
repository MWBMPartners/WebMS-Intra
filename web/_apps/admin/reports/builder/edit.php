<?php
// Path: _apps/admin/reports/builder/edit.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Reports Builder: the builder form (new + edit) 📊 (#156)
 * -----------------------------------------------------------------------------
 * Source select -> columns (checkbox list + drag-reorderable chip row via
 * Asset::sortableJs(), the #156 "drag-and-drop" requirement) -> filters
 * (repeatable rows, one AND/OR toggle — Ambiguity A2) -> optional grouping
 * + aggregates -> sort/limit -> Preview (AJAX) -> Save.
 *
 * Registry metadata for the current source (columns -> label/type/locked/
 * enum/aggs) is emitted ONCE as `json_encode()` into a
 * `<script type="application/json">` island; `report-builder.js` is
 * static, self-hosted JS driven entirely by that island. The client JS is
 * CONVENIENCE ONLY — every rule it enforces client-side is re-enforced,
 * from scratch, in `ReportBuilder::compile()` server-side.
 *
 * Gated columns the caller fails are rendered disabled with a lock icon +
 * "(requires role)" label — discoverable, not spoofable: the server
 * independently re-checks every gate regardless of what the disabled
 * control shows.
 *
 * Manage rights (loading someone else's report definition for editing):
 * author OR site admin — mirrors delete.php/save.php's ownership rule.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/156
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\AppRegistry;
use Portal\Core\Asset;
use Portal\Core\Auth;
use Portal\Core\ReportBuilder;
use Portal\Core\ReportRegistry;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

if (App::isAdmin() !== true) {
    $_SESSION['flash_msg']  = t('error.access_denied_inline');
    $_SESSION['flash_type'] = 'danger';
    header('Location: /dashboard');
    exit();
}

if (AppRegistry::isEnabled('reports') === false) {
    $_SESSION['flash_msg']  = 'Reports is disabled for this site.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: /admin/apps');
    exit();
}

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

$reportId = (int) ($_GET['id'] ?? 0);
$sourceKeyGet = trim((string) ($_GET['source'] ?? ''));
// Explicit "change source" action — forces the step-1 picker even though
// a stored/previous source exists (an empty ?source= alone can't signal
// this, since "no source in the URL" is also the everyday "just show me
// the report I'm editing" case).
$pickSource = (string) ($_GET['pickSource'] ?? '') === '1';

$existing     = null;
$definition   = null;
$loadError    = null;
$reportName   = '';
$reportDesc   = '';
$isSharedInit = false;

if ($reportId > 0) {
    $existing = ReportBuilder::get($reportId, $siteId);
    if ($existing === null) {
        $_SESSION['flash_msg']  = 'Report not found.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /admin/reports/builder');
        exit();
    }
    $isOwner = ((int) ($existing['createdByID'] ?? 0)) === $userId;
    if ($isOwner === false && App::isSiteAdmin() === false) {
        $_SESSION['flash_msg']  = 'You can only edit reports you created.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /admin/reports/builder');
        exit();
    }
    $reportName   = (string) $existing['reportName'];
    $reportDesc   = (string) ($existing['description'] ?? '');
    $isSharedInit = (int) $existing['isShared'] === 1;
    try {
        $definition = ReportBuilder::decodeDefinitionJson((string) $existing['definition']);
    } catch (\InvalidArgumentException $e) {
        $loadError = $e->getMessage();
    }
}

// 🔑 Resolve the working source key: explicit ?source= (new report, or an
// explicit "change source" action) wins over whatever the stored
// definition says; ?pickSource=1 forces the step-1 picker even over an
// existing definition; otherwise fall back to the stored definition/row.
if ($pickSource === true) {
    $sourceKey = '';
} elseif ($sourceKeyGet !== '') {
    $sourceKey = $sourceKeyGet;
} elseif ($definition !== null && is_string($definition['source'] ?? null) === true) {
    $sourceKey = (string) $definition['source'];
} elseif ($existing !== null) {
    $sourceKey = (string) $existing['sourceKey'];
} else {
    $sourceKey = '';
}

$availableSources = ReportRegistry::availableSources();

// Changing source resets the form — a $sourceKeyGet different from the
// definition's own source means "start fresh" for this source.
$sourceChanged = $definition !== null && is_string($definition['source'] ?? null) === true
    && $sourceKeyGet !== '' && $sourceKeyGet !== (string) $definition['source'];
if ($sourceChanged === true) {
    $definition = null;
}

if ($sourceKey !== '' && isset($availableSources[$sourceKey]) === false) {
    $sourceKey = '';
}

$pageTitle   = $reportId > 0 ? 'Edit report' : 'New report';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Reports' => '/admin/reports', 'Builder' => '/admin/reports/builder', $pageTitle => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0"><i class="fa-solid fa-<?php echo $reportId > 0 ? 'pen' : 'plus'; ?> me-2"></i><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
    <a href="/admin/reports/builder" class="btn btn-outline-secondary btn-sm">
        <i class="fa-solid fa-arrow-left me-1"></i>Back to reports
    </a>
</div>

<?php if ($loadError !== null): ?>
    <div class="alert alert-warning">
        <i class="fa-solid fa-triangle-exclamation me-2"></i>
        This report's saved definition is no longer valid (<?php echo htmlspecialchars($loadError, ENT_QUOTES, 'UTF-8'); ?>).
        Choose a source below and rebuild it.
    </div>
<?php endif; ?>

<?php if ($sourceKey === ''): ?>
    <!-- Step 1 — choose a data source -->
    <div class="card">
        <div class="card-header"><h5 class="mb-0">Choose a data source</h5></div>
        <div class="card-body">
            <?php if (count($availableSources) === 0): ?>
                <p class="text-muted mb-0">No report data sources are currently available — their owning apps may be disabled.</p>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($availableSources as $key => $meta): ?>
                        <div class="col-6 col-md-4">
                            <a class="btn btn-outline-primary w-100 py-3" href="/admin/reports/builder/edit?source=<?php echo urlencode($key); ?><?php echo $reportId > 0 ? '&amp;id=' . $reportId : ''; ?>">
                                <?php echo htmlspecialchars((string) $meta['label'], ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <?php
    $srcMeta      = ReportRegistry::source($sourceKey);
    $rawColumns   = (array) ($srcMeta['columns'] ?? []);
    $columnsMeta  = [];
    foreach ($rawColumns as $colKey => $col) {
        $columnsMeta[$colKey] = [
            'label'  => (string) $col['label'],
            'type'   => (string) $col['type'],
            'locked' => ReportRegistry::callerPassesGates((array) ($col['gates'] ?? [])) === false,
            'enum'   => $col['enum'] ?? null,
            'aggs'   => ReportRegistry::columnAggregations($sourceKey, (string) $colKey),
        ];
    }

    $isGroupedInit  = $definition !== null && array_key_exists('group', $definition) === true && $definition['group'] !== null;
    $initialColumns = [];
    if ($isGroupedInit === false && $definition !== null && is_array($definition['columns'] ?? null) === true) {
        foreach ($definition['columns'] as $ck) {
            if (is_string($ck) === true && isset($columnsMeta[$ck]) === true) {
                $initialColumns[] = $ck;
            }
        }
    }
    $initialGroupCol       = $isGroupedInit === true ? (string) ($definition['group']['col'] ?? '') : '';
    $initialGroupTransform = $isGroupedInit === true ? (string) ($definition['group']['transform'] ?? '') : '';
    $initialAggregates     = $isGroupedInit === true && is_array($definition['aggregates'] ?? null) === true ? $definition['aggregates'] : [];
    $initialFilterRows     = ($definition !== null && is_array($definition['filters']['rows'] ?? null) === true) ? $definition['filters']['rows'] : [];
    $initialConjunction    = (string) ($definition['filters']['conjunction'] ?? 'AND');
    $initialSortCol        = (string) ($definition['sort']['col'] ?? '');
    $initialSortDir        = (string) ($definition['sort']['dir'] ?? 'asc');
    $initialLimit           = isset($definition['limit']) === true ? (int) $definition['limit'] : null;

    // ── Local render helpers (this file only — not part of the registry) ──
    $typeOperators = [
        'string'   => ['eq', 'neq', 'like', 'notlike', 'in', 'isnull', 'notnull'],
        'int'      => ['eq', 'neq', 'lt', 'lte', 'gt', 'gte', 'between', 'in', 'isnull', 'notnull'],
        'decimal'  => ['eq', 'neq', 'lt', 'lte', 'gt', 'gte', 'between', 'isnull', 'notnull'],
        'date'     => ['eq', 'neq', 'lt', 'lte', 'gt', 'gte', 'between', 'isnull', 'notnull'],
        'datetime' => ['eq', 'neq', 'lt', 'lte', 'gt', 'gte', 'between', 'isnull', 'notnull'],
        'enum'     => ['eq', 'neq', 'in'],
        'bool'     => ['eq'],
    ];
    $operatorLabels = [
        'eq' => '=', 'neq' => '≠', 'lt' => '<', 'lte' => '≤', 'gt' => '>', 'gte' => '≥',
        'like' => 'contains', 'notlike' => 'does not contain', 'between' => 'between',
        'in' => 'is one of', 'isnull' => 'is empty', 'notnull' => 'is not empty',
    ];
    $operatorArity = ['eq' => 1, 'neq' => 1, 'lt' => 1, 'lte' => 1, 'gt' => 1, 'gte' => 1, 'like' => 1, 'notlike' => 1, 'between' => 2, 'in' => 'n', 'isnull' => 0, 'notnull' => 0];
    $aggLabels = ['count' => 'Count', 'countDistinct' => 'Distinct count', 'sum' => 'Sum', 'avg' => 'Average', 'min' => 'Minimum', 'max' => 'Maximum'];

    $h = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

    $inputTypeFor = static function (string $type): string {
        if ($type === 'int' || $type === 'decimal') {
            return 'number';
        }
        if ($type === 'date') {
            return 'date';
        }
        return 'text';
    };

    $renderColumnOptions = static function (array $columnsMeta, string $selected) use ($h): string {
        $html = '';
        foreach ($columnsMeta as $key => $meta) {
            $lockAttr  = $meta['locked'] === true ? ' disabled' : '';
            $lockLabel = $meta['locked'] === true ? ' (requires role)' : '';
            $sel       = $key === $selected ? ' selected' : '';
            $html .= '<option value="' . $h((string) $key) . '"' . $lockAttr . $sel . '>' . $h($meta['label']) . $lockLabel . '</option>';
        }
        return $html;
    };

    $renderOperatorOptions = static function (string $type, string $selected) use ($typeOperators, $operatorLabels, $h): string {
        $html = '';
        foreach ((array) ($typeOperators[$type] ?? []) as $opKey) {
            $sel = $opKey === $selected ? ' selected' : '';
            $html .= '<option value="' . $opKey . '"' . $sel . '>' . $h($operatorLabels[$opKey]) . '</option>';
        }
        return $html;
    };

    $renderValueCell = static function (array $columnsMeta, string $colKey, string $op, array $vals) use ($operatorArity, $inputTypeFor, $h): string {
        $meta  = $columnsMeta[$colKey] ?? ['type' => 'string', 'enum' => null];
        $arity = $operatorArity[$op] ?? 1;
        if ($arity === 0) {
            return '<span class="text-muted small">No value needed</span>';
        }
        $inputType = $inputTypeFor((string) $meta['type']);
        if (($meta['type'] ?? '') === 'enum') {
            $opts = '';
            foreach ((array) ($meta['enum'] ?? []) as $v) {
                $v = (string) $v;
                $sel = $arity === 'n' ? (in_array($v, $vals, true) === true ? ' selected' : '') : ((isset($vals[0]) === true && (string) $vals[0] === $v) ? ' selected' : '');
                $opts .= '<option value="' . $h($v) . '"' . $sel . '>' . $h($v) . '</option>';
            }
            if ($arity === 'n') {
                return '<select class="form-select form-select-sm filter-val filter-val-multi" multiple size="3">' . $opts . '</select><div class="form-text">Ctrl/Cmd-click to select several</div>';
            }
            return '<select class="form-select form-select-sm filter-val">' . $opts . '</select>';
        }
        if ($op === 'between') {
            return '<div class="d-flex gap-1">'
                 . '<input type="' . $inputType . '" class="form-control form-control-sm filter-val" placeholder="From" value="' . $h((string) ($vals[0] ?? '')) . '">'
                 . '<input type="' . $inputType . '" class="form-control form-control-sm filter-val" placeholder="To" value="' . $h((string) ($vals[1] ?? '')) . '">'
                 . '</div>';
        }
        if ($op === 'in') {
            $joined = implode(', ', array_map('strval', $vals));
            return '<input type="text" class="form-control form-control-sm filter-val filter-val-taglist" placeholder="Comma-separated values" value="' . $h($joined) . '">';
        }
        return '<input type="' . $inputType . '" class="form-control form-control-sm filter-val" value="' . $h((string) ($vals[0] ?? '')) . '">';
    };

    $renderFilterRow = static function (array $columnsMeta, ?array $row, bool $isTemplate) use ($renderColumnOptions, $renderOperatorOptions, $renderValueCell): string {
        $colKey = (string) ($row['col'] ?? '');
        $op     = (string) ($row['op'] ?? '');
        $vals   = (array) ($row['vals'] ?? []);
        $type   = $columnsMeta[$colKey]['type'] ?? 'string';
        $html  = '<div class="filter-row d-flex flex-wrap gap-2 align-items-start border rounded p-2 mb-2">';
        $html .= '<select class="form-select form-select-sm filter-col" style="max-width:220px;">' . ($isTemplate === true ? '' : $renderColumnOptions($columnsMeta, $colKey)) . '</select>';
        $html .= '<select class="form-select form-select-sm filter-op" style="max-width:180px;">' . ($isTemplate === true ? '' : $renderOperatorOptions($type, $op)) . '</select>';
        $html .= '<div class="filter-value-cell flex-grow-1" style="min-width:180px;">' . ($isTemplate === true ? '' : $renderValueCell($columnsMeta, $colKey, $op, $vals)) . '</div>';
        $html .= '<button type="button" class="btn btn-sm btn-outline-danger filter-row-remove" title="Remove filter"><i class="fa-solid fa-xmark"></i></button>';
        $html .= '</div>';
        return $html;
    };

    $renderAggColumnOptions = static function (array $columnsMeta, string $selected) use ($h): string {
        $html = '<option value="">(count of matching rows)</option>';
        foreach ($columnsMeta as $key => $meta) {
            if (count((array) $meta['aggs']) === 0) {
                continue;
            }
            $lockAttr = $meta['locked'] === true ? ' disabled' : '';
            $sel      = $key === $selected ? ' selected' : '';
            $html .= '<option value="' . $h((string) $key) . '"' . $lockAttr . $sel . '>' . $h($meta['label']) . ($meta['locked'] === true ? ' (requires role)' : '') . '</option>';
        }
        return $html;
    };
    $renderAggFnOptions = static function (array $columnsMeta, string $colKey, string $selected) use ($aggLabels, $h): string {
        $aggs = $colKey === '' ? ['count'] : (array) ($columnsMeta[$colKey]['aggs'] ?? []);
        $html = '';
        foreach ($aggs as $fn) {
            $sel = $fn === $selected ? ' selected' : '';
            $html .= '<option value="' . $h($fn) . '"' . $sel . '>' . $h($aggLabels[$fn] ?? $fn) . '</option>';
        }
        return $html;
    };
    $renderAggRow = static function (array $columnsMeta, ?array $agg, bool $isTemplate) use ($renderAggColumnOptions, $renderAggFnOptions): string {
        $colKey = (string) ($agg['col'] ?? '');
        $fn     = (string) ($agg['fn'] ?? '');
        $html  = '<div class="agg-row d-flex flex-wrap gap-2 align-items-center border rounded p-2 mb-2">';
        $html .= '<select class="form-select form-select-sm agg-col" style="max-width:240px;">' . ($isTemplate === true ? '' : $renderAggColumnOptions($columnsMeta, $colKey)) . '</select>';
        $html .= '<select class="form-select form-select-sm agg-fn" style="max-width:180px;">' . ($isTemplate === true ? '' : $renderAggFnOptions($columnsMeta, $colKey, $fn)) . '</select>';
        $html .= '<button type="button" class="btn btn-sm btn-outline-danger agg-row-remove" title="Remove aggregate"><i class="fa-solid fa-xmark"></i></button>';
        $html .= '</div>';
        return $html;
    };

    // Sort-column candidates for the CURRENT initial state.
    $sortCandidates = [];
    if ($isGroupedInit === true) {
        if ($initialGroupCol !== '') {
            $sortCandidates[$initialGroupCol] = $columnsMeta[$initialGroupCol]['label'] ?? $initialGroupCol;
        }
        foreach ($initialAggregates as $agg) {
            $fn = (string) ($agg['fn'] ?? '');
            if ($fn === '') {
                continue;
            }
            $colKey = $agg['col'] ?? null;
            $alias  = $fn . '_' . ($colKey ?? 'all');
            $label  = ($aggLabels[$fn] ?? $fn) . ($colKey !== null ? ' of ' . ($columnsMeta[$colKey]['label'] ?? $colKey) : '');
            $sortCandidates[$alias] = $label;
        }
    } else {
        foreach ($initialColumns as $ck) {
            $sortCandidates[$ck] = $columnsMeta[$ck]['label'] ?? $ck;
        }
    }
    ?>

    <div id="reportBuilderRoot">
        <script type="application/json" id="reportBuilderData"><?php
            echo json_encode(['sourceKey' => $sourceKey, 'columns' => $columnsMeta], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
        ?></script>

        <div class="card mb-3">
            <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <span class="text-muted small">Data source</span>
                    <div class="h5 mb-0"><?php echo $h((string) ($srcMeta['label'] ?? $sourceKey)); ?></div>
                </div>
                <a href="/admin/reports/builder/edit?<?php echo $reportId > 0 ? 'id=' . $reportId . '&amp;' : ''; ?>pickSource=1" class="btn btn-sm btn-outline-secondary">
                    Change source (resets this form)
                </a>
            </div>
        </div>

        <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" id="groupToggle" <?php echo $isGroupedInit === true ? 'checked' : ''; ?>>
            <label class="form-check-label" for="groupToggle">Group &amp; aggregate results</label>
        </div>

        <!-- 📋 Columns (non-grouped mode) -->
        <div id="columnsSection" class="card mb-3" <?php echo $isGroupedInit === true ? 'style="display:none;"' : ''; ?>>
            <div class="card-header"><h6 class="mb-0">Columns</h6></div>
            <div class="card-body">
                <div class="row row-cols-2 row-cols-md-3 g-2 mb-3">
                    <?php foreach ($columnsMeta as $colKey => $meta): ?>
                        <div class="col">
                            <div class="form-check">
                                <input class="form-check-input report-col-checkbox" type="checkbox" value="<?php echo $h($colKey); ?>"
                                       id="col_<?php echo $h($colKey); ?>"
                                       <?php echo in_array($colKey, $initialColumns, true) === true ? 'checked' : ''; ?>
                                       <?php echo $meta['locked'] === true ? 'disabled' : ''; ?>>
                                <label class="form-check-label" for="col_<?php echo $h($colKey); ?>">
                                    <?php echo $h($meta['label']); ?>
                                    <?php if ($meta['locked'] === true): ?>
                                        <i class="fa-solid fa-lock text-muted small" title="Requires an additional role"></i>
                                    <?php endif; ?>
                                </label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <label class="form-label small text-muted">Selected columns (drag to reorder)</label>
                <div id="columnChips" class="border rounded p-2" style="min-height:3rem;">
                    <?php foreach ($initialColumns as $colKey): ?>
                        <span class="badge text-bg-primary d-inline-flex align-items-center gap-1 p-2 me-1 mb-1 report-chip" data-col="<?php echo $h($colKey); ?>" draggable="true">
                            <i class="fa-solid fa-grip-vertical opacity-50"></i><span><?php echo $h($columnsMeta[$colKey]['label'] ?? $colKey); ?></span>
                            <button type="button" class="btn-close btn-close-white btn-sm ms-1" aria-label="Remove"></button>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- 📊 Grouping + aggregates -->
        <div id="groupSection" class="card mb-3" <?php echo $isGroupedInit === false ? 'style="display:none;"' : ''; ?>>
            <div class="card-header"><h6 class="mb-0">Group by</h6></div>
            <div class="card-body">
                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small">Group column</label>
                        <select id="groupCol" class="form-select">
                            <option value="">(choose a column)</option>
                            <?php echo $renderColumnOptions($columnsMeta, $initialGroupCol); ?>
                        </select>
                    </div>
                    <div class="col-md-6" id="groupTransformWrap" <?php echo ($isGroupedInit === true && in_array($columnsMeta[$initialGroupCol]['type'] ?? '', ['date', 'datetime'], true) === true) ? '' : 'style="display:none;"'; ?>>
                        <label class="form-label small">Bucket by</label>
                        <select id="groupTransform" class="form-select">
                            <option value="">(exact value)</option>
                            <option value="day" <?php echo $initialGroupTransform === 'day' ? 'selected' : ''; ?>>Day</option>
                            <option value="month" <?php echo $initialGroupTransform === 'month' ? 'selected' : ''; ?>>Month</option>
                            <option value="year" <?php echo $initialGroupTransform === 'year' ? 'selected' : ''; ?>>Year</option>
                        </select>
                    </div>
                </div>

                <label class="form-label small text-muted">Aggregates</label>
                <div id="aggregateRows">
                    <?php foreach ($initialAggregates as $agg): ?>
                        <?php echo $renderAggRow($columnsMeta, is_array($agg) ? $agg : null, false); ?>
                    <?php endforeach; ?>
                </div>
                <button type="button" id="addAggregateRow" class="btn btn-sm btn-outline-primary">
                    <i class="fa-solid fa-plus me-1"></i>Add aggregate
                </button>
                <template id="aggregateRowTemplate"><?php echo $renderAggRow($columnsMeta, null, true); ?></template>
            </div>
        </div>

        <!-- 🔍 Filters -->
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0">Filters</h6>
                <div class="d-flex align-items-center gap-2">
                    <span class="small text-muted">Join rows with</span>
                    <select id="filterConjunction" class="form-select form-select-sm" style="width:auto;">
                        <option value="AND" <?php echo $initialConjunction === 'AND' ? 'selected' : ''; ?>>AND</option>
                        <option value="OR" <?php echo $initialConjunction === 'OR' ? 'selected' : ''; ?>>OR</option>
                    </select>
                </div>
            </div>
            <div class="card-body">
                <div id="filterRows">
                    <?php foreach ($initialFilterRows as $row): ?>
                        <?php echo $renderFilterRow($columnsMeta, is_array($row) ? $row : null, false); ?>
                    <?php endforeach; ?>
                </div>
                <button type="button" id="addFilterRow" class="btn btn-sm btn-outline-primary">
                    <i class="fa-solid fa-plus me-1"></i>Add filter
                </button>
                <template id="filterRowTemplate"><?php echo $renderFilterRow($columnsMeta, null, true); ?></template>
            </div>
        </div>

        <!-- ↕️ Sort & limit -->
        <div class="card mb-3">
            <div class="card-header"><h6 class="mb-0">Sort &amp; row limit</h6></div>
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-md-4">
                        <label class="form-label small">Sort by</label>
                        <select id="sortCol" class="form-select">
                            <option value="">(default order)</option>
                            <?php foreach ($sortCandidates as $key => $label): ?>
                                <option value="<?php echo $h((string) $key); ?>" <?php echo $initialSortCol === $key ? 'selected' : ''; ?>><?php echo $h($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Direction</label>
                        <select id="sortDir" class="form-select">
                            <option value="asc" <?php echo $initialSortDir === 'asc' ? 'selected' : ''; ?>>Ascending</option>
                            <option value="desc" <?php echo $initialSortDir === 'desc' ? 'selected' : ''; ?>>Descending</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Row cap (optional)</label>
                        <input type="number" id="reportLimit" class="form-control" min="1" max="100000"
                               value="<?php echo $initialLimit !== null ? (int) $initialLimit : ''; ?>" placeholder="No extra cap">
                    </div>
                </div>
            </div>
        </div>

        <!-- 🔎 Preview -->
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0">Preview</h6>
                <button type="button" id="previewReportBtn" class="btn btn-sm btn-primary">
                    <i class="fa-solid fa-eye me-1"></i>Preview
                </button>
            </div>
            <div class="card-body">
                <div id="previewPanel" class="text-muted small">Click Preview to run this report against the first 25 matching rows.</div>
            </div>
        </div>

        <!-- 💾 Save -->
        <div class="card mb-4">
            <div class="card-header"><h6 class="mb-0">Save report</h6></div>
            <div class="card-body">
                <form method="post" action="/admin/reports/builder/save" id="reportSaveForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $h(Auth::csrfToken()); ?>">
                    <input type="hidden" name="reportID" value="<?php echo $reportId; ?>">
                    <input type="hidden" name="definition" id="definitionField" value="">
                    <div class="row g-3 mb-3">
                        <div class="col-md-5">
                            <label class="form-label" for="reportName">Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="reportName" name="reportName" required maxlength="150" value="<?php echo $h($reportName); ?>">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label" for="reportDesc">Description</label>
                            <input type="text" class="form-control" id="reportDesc" name="description" maxlength="500" value="<?php echo $h($reportDesc); ?>">
                        </div>
                        <div class="col-md-2">
                            <div class="form-check form-switch mt-4">
                                <input class="form-check-input" type="checkbox" id="isShared" name="isShared" value="1" <?php echo $isSharedInit === true ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="isShared">Shared</label>
                            </div>
                            <div class="form-text">Visible to this site's admins</div>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-floppy-disk me-1"></i>Save report
                    </button>
                </form>
            </div>
        </div>
    </div>

    <?php echo Asset::sortableJs(); ?>
    <script src="/assets/js/report-builder.js" defer></script>
<?php endif; ?>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
