/**
 * Reports Builder — client-side form assembly + preview (#156)
 *
 * Single vanilla JS file, no build step. Reads registry metadata + (when
 * editing) the existing definition from the `#reportBuilderData` JSON
 * island edit.php emits, then:
 *   - keeps the column checkbox list <-> the reorderable chip row in sync
 *     (drag-reorder via SortableJS, loaded by Asset::sortableJs() — the
 *     #156 "drag-and-drop" requirement);
 *   - renders repeatable filter rows (column -> operator, filtered by the
 *     column's type -> a typed value input) and aggregate rows;
 *   - assembles the canonical definition JSON object (the SAME shape
 *     Portal\Core\ReportBuilder::compile() expects) for BOTH the AJAX
 *     Preview call and the real form submit to save.php.
 *
 * SECURITY NOTE: every rule this file enforces (allowed operators per
 * type, gated columns, arities, …) is a CONVENIENCE — the definition this
 * file builds is re-validated from scratch, server-side, against the
 * PHP-code registry on every preview/save/run. Nothing here is trusted.
 *
 * CSRF token rotation: Auth::verifyCsrf() rotates the session token on
 * EVERY successful check (see _core/Auth.php) — preview.php's JSON
 * response carries the freshly rotated token in `csrf`, and syncCsrf()
 * below writes it back into the page's csrf_token inputs so a second
 * Preview click (or the eventual Save submit) still carries a valid token
 * (event-hub-upload.js precedent).
 *
 * @see https://github.com/MWBMPartners/WebMS-Intra/issues/156
 */
(function () {
    'use strict';

    var root = document.getElementById('reportBuilderRoot');
    if (root === null) {
        return; // Not on the builder edit page.
    }

    var dataEl = document.getElementById('reportBuilderData');
    var CONFIG = dataEl !== null ? JSON.parse(dataEl.textContent || '{}') : {};
    var SOURCE_KEY = CONFIG.sourceKey || '';
    var COLUMNS = (CONFIG.columns) || {}; // key -> {label, type, locked, enum, aggs}

    // Mirrors ReportRegistry's closed operator/type vocabulary — UI
    // convenience only (see file header). Keep in sync by hand; the
    // server is always the authority.
    var OPERATORS = {
        eq:       { label: '=',            arity: 1 },
        neq:      { label: '≠',            arity: 1 },
        lt:       { label: '<',            arity: 1 },
        lte:      { label: '≤',            arity: 1 },
        gt:       { label: '>',            arity: 1 },
        gte:      { label: '≥',            arity: 1 },
        like:     { label: 'contains',     arity: 1 },
        notlike:  { label: 'does not contain', arity: 1 },
        between:  { label: 'between',      arity: 2 },
        'in':     { label: 'is one of',    arity: 'n' },
        isnull:   { label: 'is empty',     arity: 0 },
        notnull:  { label: 'is not empty', arity: 0 }
    };
    var TYPE_OPERATORS = {
        string:   ['eq', 'neq', 'like', 'notlike', 'in', 'isnull', 'notnull'],
        int:      ['eq', 'neq', 'lt', 'lte', 'gt', 'gte', 'between', 'in', 'isnull', 'notnull'],
        decimal:  ['eq', 'neq', 'lt', 'lte', 'gt', 'gte', 'between', 'isnull', 'notnull'],
        date:     ['eq', 'neq', 'lt', 'lte', 'gt', 'gte', 'between', 'isnull', 'notnull'],
        datetime: ['eq', 'neq', 'lt', 'lte', 'gt', 'gte', 'between', 'isnull', 'notnull'],
        'enum':   ['eq', 'neq', 'in'],
        'bool':   ['eq']
    };
    var AGG_LABELS = { count: 'Count', countDistinct: 'Distinct count', sum: 'Sum', avg: 'Average', min: 'Minimum', max: 'Maximum' };

    /* ------------------------------------------------------------------ */
    /* CSRF helpers (event-hub-upload.js precedent)                       */
    /* ------------------------------------------------------------------ */

    function getCsrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta !== null ? (meta.getAttribute('content') || '') : '';
    }
    function syncCsrf(token) {
        if (!token) { return; }
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta !== null) { meta.setAttribute('content', token); }
        var inputs = document.querySelectorAll('input[name="csrf_token"]');
        for (var i = 0; i < inputs.length; i++) { inputs[i].value = token; }
    }

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = String(s);
        return d.innerHTML;
    }

    /* ------------------------------------------------------------------ */
    /* Columns <-> chip row (drag-reorder via SortableJS)                 */
    /* ------------------------------------------------------------------ */

    var chipContainer = document.getElementById('columnChips');
    var checkboxes = root.querySelectorAll('.report-col-checkbox');

    function wireChipRemove(chip, colKey) {
        var btn = chip.querySelector('button');
        if (btn === null) { return; }
        btn.addEventListener('click', function () {
            chip.remove();
            var cb = root.querySelector('.report-col-checkbox[value="' + colKey + '"]');
            if (cb !== null) { cb.checked = false; }
            refreshSortTargets();
        });
    }
    function addChip(colKey) {
        if (chipContainer === null || chipContainer.querySelector('[data-col="' + colKey + '"]') !== null) {
            return;
        }
        var meta = COLUMNS[colKey] || { label: colKey };
        var chip = document.createElement('span');
        chip.className = 'badge text-bg-primary d-inline-flex align-items-center gap-1 p-2 me-1 mb-1 report-chip';
        chip.setAttribute('data-col', colKey);
        chip.setAttribute('draggable', 'true');
        chip.innerHTML = '<i class="fa-solid fa-grip-vertical opacity-50"></i><span>' + esc(meta.label) + '</span>'
            + '<button type="button" class="btn-close btn-close-white btn-sm ms-1" aria-label="Remove"></button>';
        wireChipRemove(chip, colKey);
        chipContainer.appendChild(chip);
    }
    // Wire remove buttons on any SERVER-RENDERED chips (edit mode).
    if (chipContainer !== null) {
        var existingChips = chipContainer.querySelectorAll('.report-chip[data-col]');
        for (var ec = 0; ec < existingChips.length; ec++) {
            wireChipRemove(existingChips[ec], existingChips[ec].getAttribute('data-col'));
        }
    }
    function removeChip(colKey) {
        if (chipContainer === null) { return; }
        var chip = chipContainer.querySelector('[data-col="' + colKey + '"]');
        if (chip !== null) { chip.remove(); }
    }
    for (var ci = 0; ci < checkboxes.length; ci++) {
        checkboxes[ci].addEventListener('change', function (ev) {
            var cb = ev.currentTarget;
            if (cb.checked === true) { addChip(cb.value); } else { removeChip(cb.value); }
            refreshSortTargets();
        });
    }
    function currentColumnOrder() {
        if (chipContainer === null) { return []; }
        var chips = chipContainer.querySelectorAll('[data-col]');
        var out = [];
        for (var i = 0; i < chips.length; i++) { out.push(chips[i].getAttribute('data-col')); }
        return out;
    }

    // SortableJS is loaded via Asset::sortableJs() (jsdelivr, SRI-pinned).
    // If the CDN fails, the chip row simply keeps its insertion order —
    // degraded, not broken.
    if (chipContainer !== null && typeof window.Sortable !== 'undefined') {
        window.Sortable.create(chipContainer, {
            animation: 150,
            onEnd: refreshSortTargets
        });
    }

    /* ------------------------------------------------------------------ */
    /* Group / aggregate toggle                                           */
    /* ------------------------------------------------------------------ */

    var groupToggle = document.getElementById('groupToggle');
    var columnsSection = document.getElementById('columnsSection');
    var groupSection = document.getElementById('groupSection');
    var groupColSelect = document.getElementById('groupCol');
    var groupTransformSelect = document.getElementById('groupTransform');
    var groupTransformWrap = document.getElementById('groupTransformWrap');

    function applyGroupVisibility() {
        var grouped = groupToggle !== null && groupToggle.checked === true;
        if (columnsSection !== null) { columnsSection.style.display = grouped ? 'none' : ''; }
        if (groupSection !== null) { groupSection.style.display = grouped ? '' : 'none'; }
        refreshSortTargets();
    }
    function applyTransformVisibility() {
        if (groupColSelect === null || groupTransformWrap === null) { return; }
        var key = groupColSelect.value;
        var type = (COLUMNS[key] || {}).type;
        var show = key !== '' && (type === 'date' || type === 'datetime');
        groupTransformWrap.style.display = show ? '' : 'none';
        if (show === false && groupTransformSelect !== null) { groupTransformSelect.value = ''; }
    }
    if (groupToggle !== null) { groupToggle.addEventListener('change', applyGroupVisibility); }
    if (groupColSelect !== null) { groupColSelect.addEventListener('change', function () { applyTransformVisibility(); refreshSortTargets(); }); }

    /* ------------------------------------------------------------------ */
    /* Filter rows (repeatable)                                           */
    /* ------------------------------------------------------------------ */

    var filterRowsContainer = document.getElementById('filterRows');
    var filterTemplate = document.getElementById('filterRowTemplate');
    var addFilterBtn = document.getElementById('addFilterRow');
    var MAX_FILTER_ROWS = 15;

    function columnOptionsHtml(selected) {
        var html = '';
        Object.keys(COLUMNS).forEach(function (key) {
            var meta = COLUMNS[key];
            var lockAttr = meta.locked === true ? ' disabled' : '';
            var lockLabel = meta.locked === true ? ' (requires role)' : '';
            var sel = key === selected ? ' selected' : '';
            html += '<option value="' + esc(key) + '"' + lockAttr + sel + '>' + esc(meta.label) + lockLabel + '</option>';
        });
        return html;
    }
    function operatorOptionsHtml(type, selected) {
        var allowed = TYPE_OPERATORS[type] || [];
        var html = '';
        allowed.forEach(function (opKey) {
            var sel = opKey === selected ? ' selected' : '';
            html += '<option value="' + opKey + '"' + sel + '>' + esc(OPERATORS[opKey].label) + '</option>';
        });
        return html;
    }
    function valueInputHtml(colKey, op, vals) {
        var meta = COLUMNS[colKey] || {};
        var arity = (OPERATORS[op] || {}).arity;
        vals = vals || [];
        if (arity === 0) {
            return '<span class="text-muted small">No value needed</span>';
        }
        var inputType = 'text';
        if (meta.type === 'int' || meta.type === 'decimal') { inputType = 'number'; }
        if (meta.type === 'date') { inputType = 'date'; }
        if (meta.type === 'datetime') { inputType = 'text'; } // "YYYY-MM-DD HH:MM[:SS]"
        if (meta.type === 'enum') {
            var opts = (meta['enum'] || []).map(function (v) {
                return '<option value="' + esc(v) + '">' + esc(v) + '</option>';
            }).join('');
            if (op === 'in') {
                return '<select class="form-select form-select-sm filter-val filter-val-multi" multiple size="3">' + opts + '</select>'
                    + '<div class="form-text">Ctrl/Cmd-click to select several</div>';
            }
            return '<select class="form-select form-select-sm filter-val">' + opts + '</select>';
        }
        if (op === 'between') {
            return '<div class="d-flex gap-1">'
                + '<input type="' + inputType + '" class="form-control form-control-sm filter-val" placeholder="From" value="' + esc(vals[0] || '') + '">'
                + '<input type="' + inputType + '" class="form-control form-control-sm filter-val" placeholder="To" value="' + esc(vals[1] || '') + '">'
                + '</div>';
        }
        if (op === 'in') {
            return '<input type="text" class="form-control form-control-sm filter-val filter-val-taglist" placeholder="Comma-separated values" value="' + esc(vals.join(', ')) + '">';
        }
        return '<input type="' + inputType + '" class="form-control form-control-sm filter-val" value="' + esc(vals[0] || '') + '">';
    }

    // 🛡️ IMPORTANT: server-rendered (pre-existing, edit mode) filter rows
    // already carry the CORRECT option lists + typed values from the
    // stored definition — wiring them must NEVER blow those away.
    // `wireFilterRow()` attaches change listeners ONLY (no innerHTML
    // rewrite). A full rebuild (fresh, empty operator/value cell) only
    // ever happens in direct response to the user changing the column or
    // operator on THAT row from then on, or when a brand-new row is added
    // via the template (which starts genuinely empty).
    function rebuildOperatorAndValue(row, resetValue) {
        var colSelect = row.querySelector('.filter-col');
        var opSelect = row.querySelector('.filter-op');
        var valueCell = row.querySelector('.filter-value-cell');
        if (colSelect === null || opSelect === null || valueCell === null) { return; }
        var meta = COLUMNS[colSelect.value] || {};
        var keepOp = resetValue === true ? '' : opSelect.value;
        opSelect.innerHTML = operatorOptionsHtml(meta.type || 'string', keepOp);
        valueCell.innerHTML = valueInputHtml(colSelect.value, opSelect.value, []);
    }

    function wireFilterRow(row) {
        var colSelect = row.querySelector('.filter-col');
        var opSelect = row.querySelector('.filter-op');
        var removeBtn = row.querySelector('.filter-row-remove');
        if (colSelect === null || opSelect === null) { return; }
        colSelect.addEventListener('change', function () { rebuildOperatorAndValue(row, true); });
        opSelect.addEventListener('change', function () { rebuildOperatorAndValue(row, false); });
        if (removeBtn !== null) {
            removeBtn.addEventListener('click', function () { row.remove(); refreshSortTargets(); });
        }
    }

    function addFilterRow() {
        if (filterTemplate === null || filterRowsContainer === null) { return; }
        if (filterRowsContainer.querySelectorAll('.filter-row').length >= MAX_FILTER_ROWS) { return; }
        var clone = filterTemplate.content.firstElementChild.cloneNode(true);
        var colSelect = clone.querySelector('.filter-col');
        colSelect.innerHTML = columnOptionsHtml('');
        filterRowsContainer.appendChild(clone);
        rebuildOperatorAndValue(clone, true);
        wireFilterRow(clone);
    }
    if (addFilterBtn !== null) { addFilterBtn.addEventListener('click', addFilterRow); }

    // Wire any SERVER-RENDERED filter rows (edit mode) without touching
    // their already-correct content.
    if (filterRowsContainer !== null) {
        var existingFilterRows = filterRowsContainer.querySelectorAll('.filter-row');
        for (var fr = 0; fr < existingFilterRows.length; fr++) { wireFilterRow(existingFilterRows[fr]); }
    }

    /* ------------------------------------------------------------------ */
    /* Aggregate rows (repeatable, grouped mode only)                     */
    /* ------------------------------------------------------------------ */

    var aggRowsContainer = document.getElementById('aggregateRows');
    var aggTemplate = document.getElementById('aggregateRowTemplate');
    var addAggBtn = document.getElementById('addAggregateRow');
    var MAX_AGG_ROWS = 6;

    function aggColumnOptionsHtml(selected) {
        var html = '<option value="">(count of matching rows)</option>';
        Object.keys(COLUMNS).forEach(function (key) {
            var meta = COLUMNS[key];
            if (!meta.aggs || meta.aggs.length === 0) { return; }
            var lockAttr = meta.locked === true ? ' disabled' : '';
            var sel = key === selected ? ' selected' : '';
            html += '<option value="' + esc(key) + '"' + lockAttr + sel + '>' + esc(meta.label) + (meta.locked ? ' (requires role)' : '') + '</option>';
        });
        return html;
    }
    function aggFnOptionsHtml(colKey, selected) {
        var aggs = colKey === '' ? ['count'] : ((COLUMNS[colKey] || {}).aggs || []);
        var html = '';
        aggs.forEach(function (fn) {
            var sel = fn === selected ? ' selected' : '';
            html += '<option value="' + fn + '"' + sel + '>' + esc(AGG_LABELS[fn] || fn) + '</option>';
        });
        return html;
    }
    // Same "never wipe server-rendered edit-mode values" discipline as
    // the filter rows above.
    function wireAggregateRow(row) {
        var colSelect = row.querySelector('.agg-col');
        var fnSelect = row.querySelector('.agg-fn');
        var removeBtn = row.querySelector('.agg-row-remove');
        if (colSelect === null || fnSelect === null) { return; }
        colSelect.addEventListener('change', function () {
            fnSelect.innerHTML = aggFnOptionsHtml(colSelect.value, '');
            refreshSortTargets();
        });
        fnSelect.addEventListener('change', refreshSortTargets);
        if (removeBtn !== null) {
            removeBtn.addEventListener('click', function () { row.remove(); refreshSortTargets(); });
        }
    }
    function addAggregateRow() {
        if (aggTemplate === null || aggRowsContainer === null) { return; }
        if (aggRowsContainer.querySelectorAll('.agg-row').length >= MAX_AGG_ROWS) { return; }
        var clone = aggTemplate.content.firstElementChild.cloneNode(true);
        var colSelect = clone.querySelector('.agg-col');
        colSelect.innerHTML = aggColumnOptionsHtml('');
        clone.querySelector('.agg-fn').innerHTML = aggFnOptionsHtml('', '');
        aggRowsContainer.appendChild(clone);
        wireAggregateRow(clone);
        refreshSortTargets();
    }
    if (addAggBtn !== null) { addAggBtn.addEventListener('click', addAggregateRow); }

    // Wire any SERVER-RENDERED aggregate rows (edit mode) without
    // touching their already-correct content.
    if (aggRowsContainer !== null) {
        var existingAggRows = aggRowsContainer.querySelectorAll('.agg-row');
        for (var ar = 0; ar < existingAggRows.length; ar++) { wireAggregateRow(existingAggRows[ar]); }
    }

    /* ------------------------------------------------------------------ */
    /* Sort column options — rebuilt whenever the output-key set changes  */
    /* ------------------------------------------------------------------ */

    var sortColSelect = document.getElementById('sortCol');

    function currentOutputKeys() {
        if (groupToggle !== null && groupToggle.checked === true) {
            var keys = [];
            var groupKey = groupColSelect !== null ? groupColSelect.value : '';
            if (groupKey !== '') { keys.push({ key: groupKey, label: (COLUMNS[groupKey] || {}).label || groupKey }); }
            if (aggRowsContainer !== null) {
                var rows = aggRowsContainer.querySelectorAll('.agg-row');
                for (var i = 0; i < rows.length; i++) {
                    var colVal = rows[i].querySelector('.agg-col').value;
                    var fnVal = rows[i].querySelector('.agg-fn').value;
                    if (fnVal === '') { continue; }
                    var alias = fnVal + '_' + (colVal || 'all');
                    var label = (AGG_LABELS[fnVal] || fnVal) + (colVal ? ' of ' + (COLUMNS[colVal] || {}).label : '');
                    keys.push({ key: alias, label: label });
                }
            }
            return keys;
        }
        return currentColumnOrder().map(function (key) { return { key: key, label: (COLUMNS[key] || {}).label || key }; });
    }
    function refreshSortTargets() {
        if (sortColSelect === null) { return; }
        var current = sortColSelect.value;
        var keys = currentOutputKeys();
        var html = '<option value="">(default order)</option>';
        keys.forEach(function (k) {
            var sel = k.key === current ? ' selected' : '';
            html += '<option value="' + esc(k.key) + '"' + sel + '>' + esc(k.label) + '</option>';
        });
        sortColSelect.innerHTML = html;
    }

    /* ------------------------------------------------------------------ */
    /* Definition assembly — the ONE function both Preview and Save use   */
    /* ------------------------------------------------------------------ */

    function collectFilterRows() {
        var out = [];
        if (filterRowsContainer === null) { return out; }
        var rows = filterRowsContainer.querySelectorAll('.filter-row');
        for (var i = 0; i < rows.length; i++) {
            var colSelect = rows[i].querySelector('.filter-col');
            var opSelect = rows[i].querySelector('.filter-op');
            if (colSelect === null || opSelect === null || colSelect.value === '') { continue; }
            var op = opSelect.value;
            var arity = (OPERATORS[op] || {}).arity;
            var vals = [];
            if (arity === 0) {
                vals = [];
            } else if (arity === 2) {
                var pair = rows[i].querySelectorAll('.filter-val');
                vals = [pair[0] ? pair[0].value : '', pair[1] ? pair[1].value : ''];
            } else if (arity === 'n') {
                var multi = rows[i].querySelector('.filter-val-multi');
                if (multi !== null) {
                    vals = Array.prototype.slice.call(multi.selectedOptions).map(function (o) { return o.value; });
                } else {
                    var taglist = rows[i].querySelector('.filter-val-taglist');
                    var raw = taglist !== null ? taglist.value : '';
                    vals = raw.split(',').map(function (s) { return s.trim(); }).filter(function (s) { return s !== ''; });
                }
            } else {
                var single = rows[i].querySelector('.filter-val');
                vals = [single !== null ? single.value : ''];
            }
            out.push({ col: colSelect.value, op: op, vals: vals });
        }
        return out;
    }

    function collectAggregateRows() {
        var out = [];
        if (aggRowsContainer === null) { return out; }
        var rows = aggRowsContainer.querySelectorAll('.agg-row');
        for (var i = 0; i < rows.length; i++) {
            var colSelect = rows[i].querySelector('.agg-col');
            var fnSelect = rows[i].querySelector('.agg-fn');
            if (fnSelect === null || fnSelect.value === '') { continue; }
            out.push({ col: colSelect.value === '' ? null : colSelect.value, fn: fnSelect.value });
        }
        return out;
    }

    function buildDefinition() {
        var def = { v: 1, source: SOURCE_KEY };
        var grouped = groupToggle !== null && groupToggle.checked === true;

        if (grouped === true) {
            var groupKey = groupColSelect !== null ? groupColSelect.value : '';
            var transformVal = groupTransformSelect !== null ? groupTransformSelect.value : '';
            def.group = { col: groupKey, transform: transformVal === '' ? null : transformVal };
            def.aggregates = collectAggregateRows();
        } else {
            def.columns = currentColumnOrder();
        }

        var filterRows = collectFilterRows();
        if (filterRows.length > 0) {
            var conjunctionEl = document.getElementById('filterConjunction');
            def.filters = { conjunction: conjunctionEl !== null ? conjunctionEl.value : 'AND', rows: filterRows };
        }

        var sortVal = sortColSelect !== null ? sortColSelect.value : '';
        if (sortVal !== '') {
            var dirEl = document.getElementById('sortDir');
            def.sort = { col: sortVal, dir: dirEl !== null ? dirEl.value : 'asc' };
        }

        var limitEl = document.getElementById('reportLimit');
        if (limitEl !== null && limitEl.value !== '') {
            var limitNum = parseInt(limitEl.value, 10);
            if (!isNaN(limitNum) && limitNum > 0) { def.limit = limitNum; }
        }

        return def;
    }

    /* ------------------------------------------------------------------ */
    /* Preview                                                             */
    /* ------------------------------------------------------------------ */

    var previewBtn = document.getElementById('previewReportBtn');
    var previewPanel = document.getElementById('previewPanel');

    function renderPreview(payload) {
        if (previewPanel === null) { return; }
        if (payload.status !== 'ok') {
            previewPanel.innerHTML = '<div class="alert alert-danger mb-0">' + esc(payload.error || 'Preview failed.') + '</div>';
            return;
        }
        var cols = payload.columns || [];
        var rows = payload.rows || [];
        var html = '<div class="portal-data-list mb-0"><div class="portal-data-header">';
        cols.forEach(function (c) { html += '<div class="col">' + esc(c.label) + '</div>'; });
        html += '</div>';
        if (rows.length === 0) {
            html += '<div class="portal-data-row"><div class="col text-muted">No rows match yet.</div></div>';
        } else {
            rows.forEach(function (r) {
                html += '<div class="portal-data-row">';
                cols.forEach(function (c) { html += '<div class="col">' + esc(r[c.key] !== undefined && r[c.key] !== null ? r[c.key] : '') + '</div>'; });
                html += '</div>';
            });
        }
        html += '</div>';
        html += '<p class="small text-muted mt-2 mb-0">Showing first ' + rows.length + ' row(s)' + (payload.hasMore === true ? ' (more available when run) ' : '') + '.</p>';
        previewPanel.innerHTML = html;
    }

    function runPreview() {
        if (previewPanel === null) { return; }
        previewPanel.innerHTML = '<div class="text-muted small"><i class="fa-solid fa-spinner fa-spin me-1"></i>Loading preview…</div>';
        var body = new URLSearchParams();
        body.set('csrf_token', getCsrf());
        body.set('definition', JSON.stringify(buildDefinition()));

        fetch('/admin/reports/builder/preview', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: body.toString()
        }).then(function (resp) { return resp.json(); })
            .then(function (payload) {
                if (payload && payload.csrf) { syncCsrf(payload.csrf); }
                renderPreview(payload || { status: 'error', error: 'No response from server.' });
            })
            .catch(function () {
                previewPanel.innerHTML = '<div class="alert alert-danger mb-0">Preview request failed.</div>';
            });
    }
    if (previewBtn !== null) { previewBtn.addEventListener('click', runPreview); }

    /* ------------------------------------------------------------------ */
    /* Save — assemble the hidden `definition` field just before submit   */
    /* ------------------------------------------------------------------ */

    var saveForm = document.getElementById('reportSaveForm');
    var definitionField = document.getElementById('definitionField');
    if (saveForm !== null && definitionField !== null) {
        saveForm.addEventListener('submit', function () {
            definitionField.value = JSON.stringify(buildDefinition());
        });
    }

    /* ------------------------------------------------------------------ */
    /* Initial render                                                      */
    /* ------------------------------------------------------------------ */

    applyGroupVisibility();
    applyTransformVisibility();
    refreshSortTargets();
})();
