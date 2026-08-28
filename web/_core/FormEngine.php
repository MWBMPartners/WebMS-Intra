<?php
// Path: _core/FormEngine.php
/**
 * -----------------------------------------------------------------------------
 * Forms Builder — Engine 🧾
 * -----------------------------------------------------------------------------
 * Single injection-safety boundary for the whole Forms Builder app (#153):
 * the field-type whitelist registry, the whitelist config sanitiser, the
 * escaped-everything renderer, per-type server-side validators, and the
 * immutable answersJson snapshot persistence. Both the internal flow
 * (`_apps/forms/fill.php` → `submit.php`) and the public flow
 * (`_apps/forms/public.php` → `public-submit.php`) call through here, so the
 * injection-safety story lives in exactly ONE file.
 *
 * INJECTION-SAFETY INVARIANTS (read before touching this file):
 *   • Field definitions are DATA. `fieldType` gates behaviour only via
 *     `isset(self::FIELD_TYPES[$type])` + a `match`/`switch` on literal
 *     strings — nothing from a field row is ever concatenated into SQL text
 *     or a PHP callable.
 *   • `configJson` passes through sanitiseConfig() on BOTH read and write —
 *     a whitelist copy, never a raw passthrough — so even a hand-edited row
 *     in the database can't smuggle an unexpected key through to render()
 *     or validate().
 *   • Every SQL statement in this class is a MySQLi prepared statement with
 *     `?` placeholders. The dynamic part of a submission (which fields
 *     exist) never changes SQL shape because every answer set is one JSON
 *     value in one fixed column (`tblFormResponses.answersJson`).
 *   • Every render of admin- or submitter-supplied text goes through
 *     `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` at the echo point.
 *   • Choice-field submissions (`select`/`radio`/`checkboxes`) arrive as
 *     INTEGER INDEXES into the field's own sanitised `options` array —
 *     `(int)`-cast, bounds-checked, and the STORED value is the server-side
 *     option string at that index. A client can never inject an arbitrary
 *     string through a choice field; the field name itself is always
 *     `f_{fieldID}`, a server-controlled integer, never admin- or
 *     attacker-supplied text.
 *
 * PARAMETER-DRIVEN BY DESIGN (#302 reuse seam — see spec §12): nothing in
 * this class reads `Site::id()` or `$_SESSION`. Every method takes the
 * site/form/user context it needs as an explicit parameter, so a future
 * caller (e.g. #302 mission-trips) can create/render/validate/persist a
 * form entirely programmatically, under its own siteID and its own
 * `channel` label, without touching HTTP at all.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/153
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

final class FormEngine
{
    /**
     * 🧾 Field-type registry — THE whitelist. Key = stored fieldType value.
     * 'input' => false marks display-only types (skipped by validation and
     * excluded from answersJson). 'config' lists the ONLY keys
     * sanitiseConfig() will copy through for that type — everything else is
     * dropped, never stored, never rendered.
     */
    public const FIELD_TYPES = [
        'text'       => ['label' => 'Short text',             'input' => true,  'config' => ['maxLength', 'placeholder']],
        'textarea'   => ['label' => 'Long text',               'input' => true,  'config' => ['maxLength', 'placeholder', 'rows']],
        'email'      => ['label' => 'Email address',           'input' => true,  'config' => ['placeholder']],
        'phone'      => ['label' => 'Phone number',            'input' => true,  'config' => ['placeholder']],
        'number'     => ['label' => 'Number',                  'input' => true,  'config' => ['min', 'max']],
        'date'       => ['label' => 'Date',                    'input' => true,  'config' => []],
        'time'       => ['label' => 'Time',                    'input' => true,  'config' => []],
        'select'     => ['label' => 'Dropdown',                'input' => true,  'config' => ['options']],
        'radio'      => ['label' => 'Choose one',              'input' => true,  'config' => ['options']],
        'checkboxes' => ['label' => 'Choose many',             'input' => true,  'config' => ['options']],
        'checkbox'   => ['label' => 'Single tick / consent',   'input' => true,  'config' => []],
        // 📝 Residual default #3 (spec §1.2.3) — display-only section
        // heading, included at near-zero cost; owner may strike it later.
        'heading'    => ['label' => 'Section heading (display only)', 'input' => false, 'config' => []],
    ];

    // 🛡️ Hard caps applied regardless of admin config (defence in depth —
    // these bound what an admin can configure, independent of the per-call
    // config values sanitiseConfig() copies through).
    private const CAP_TEXT_MAXLEN     = 1000;   // 'text' admin maxLength ceiling
    private const CAP_TEXTAREA_MAXLEN = 10000;  // 'textarea' ceiling
    private const CAP_OPTIONS         = 50;     // max options per choice field
    private const CAP_OPTION_LEN      = 200;    // max chars per option string
    private const CAP_PLACEHOLDER_LEN = 200;    // max chars for a placeholder
    private const CAP_ROWS_MAX        = 12;     // textarea rows ceiling
    private const CAP_FIELDS_PER_FORM = 60;

    // =============================================================================
    // 🔍 Lookups
    // =============================================================================

    /**
     * Form row scoped to a site, or null. Cross-site id behaves exactly
     * like missing — no existence oracle for a form belonging to another
     * tenant.
     *
     * @param int $formId
     * @param int $siteId
     * @return array<string,mixed>|null
     */
    public static function getForm(int $formId, int $siteId): ?array
    {
        $db = App::db();
        $stmt = $db->prepare('SELECT * FROM tblForms WHERE formID = ? AND siteID = ? LIMIT 1');
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('ii', $formId, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row === null ? null : $row;
    }

    /**
     * Form row by publicToken — deliberately NOT site-scoped. The `/f/`
     * page has no ambient site context (it's a public, unauthenticated
     * Router special route); the caller gates on the ROW's OWN siteID
     * (service-plans/public.php precedent — see forms/public.php).
     *
     * @param string $token 32 lowercase-hex chars
     * @return array<string,mixed>|null
     */
    public static function getFormByToken(string $token): ?array
    {
        $db = App::db();
        $stmt = $db->prepare('SELECT * FROM tblForms WHERE publicToken = ? LIMIT 1');
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row === null ? null : $row;
    }

    /**
     * Ordered field rows (position, fieldID) for a form, with configJson
     * already decoded AND re-run through sanitiseConfig() — callers never
     * see raw/untrusted JSON, even if a row was hand-edited in the
     * database directly.
     *
     * @param int $formId
     * @return array<int,array<string,mixed>>
     */
    public static function getFields(int $formId): array
    {
        $db = App::db();
        $stmt = $db->prepare(
            'SELECT * FROM tblFormFields WHERE formID = ? ORDER BY position ASC, fieldID ASC'
        );
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('i', $formId);
        $stmt->execute();
        $result = $stmt->get_result();
        $fields = [];
        while ($row = $result->fetch_assoc()) {
            $row['configJson'] = self::resolveConfig($row);
            $fields[] = $row;
        }
        $stmt->close();

        return $fields;
    }

    /**
     * status='published' AND now within [opensAt, closesAt] (NULL bound =
     * open on that side).
     *
     * @param array<string,mixed> $form
     * @return bool
     */
    public static function isOpen(array $form): bool
    {
        if ((string) ($form['status'] ?? '') !== 'published') {
            return false;
        }

        $now = time();

        $opensAt = $form['opensAt'] ?? null;
        if ($opensAt !== null && $opensAt !== '') {
            $opensTs = strtotime((string) $opensAt);
            if ($opensTs !== false && $now < $opensTs) {
                return false;
            }
        }

        $closesAt = $form['closesAt'] ?? null;
        if ($closesAt !== null && $closesAt !== '') {
            $closesTs = strtotime((string) $closesAt);
            if ($closesTs !== false && $now > $closesTs) {
                return false;
            }
        }

        return true;
    }

    // =============================================================================
    // 🛠️ Admin-side helpers
    // =============================================================================

    /**
     * Whitelist copy: for the given field type, copy ONLY the config keys
     * named in FIELD_TYPES[$fieldType]['config']. Unknown keys are DROPPED
     * silently. Every copied value is cast/clamped to a hard CAP_ constant
     * — this is the ONE place admin-entered field configuration is allowed
     * to cross into stored/rendered data, so it never trusts its input.
     *
     * @param string               $fieldType
     * @param array<string,mixed>  $rawConfig
     * @return array<string,mixed> Clean config — json_encode()-ready
     */
    public static function sanitiseConfig(string $fieldType, array $rawConfig): array
    {
        if (isset(self::FIELD_TYPES[$fieldType]) === false) {
            return [];
        }

        $allowedKeys = self::FIELD_TYPES[$fieldType]['config'];
        $clean = [];

        foreach ($allowedKeys as $key) {
            if (array_key_exists($key, $rawConfig) === false) {
                continue;
            }
            $raw = $rawConfig[$key];

            switch ($key) {
                case 'maxLength':
                    $cap = $fieldType === 'textarea' ? self::CAP_TEXTAREA_MAXLEN : self::CAP_TEXT_MAXLEN;
                    $val = (int) $raw;
                    if ($val > 0) {
                        $clean['maxLength'] = min($val, $cap);
                    }
                    break;

                case 'placeholder':
                    $val = trim((string) $raw);
                    if ($val !== '') {
                        $clean['placeholder'] = mb_substr($val, 0, self::CAP_PLACEHOLDER_LEN);
                    }
                    break;

                case 'rows':
                    $val = (int) $raw;
                    if ($val > 0) {
                        $clean['rows'] = max(1, min($val, self::CAP_ROWS_MAX));
                    }
                    break;

                case 'min':
                case 'max':
                    if (is_numeric($raw) === true) {
                        // 🔢 Preserve int vs float shape so JSON round-trips
                        // cleanly and comparisons in validateSubmission()
                        // stay simple numeric comparisons.
                        $num = $raw + 0;
                        $clean[$key] = $num;
                    }
                    break;

                case 'options':
                    $opts = [];
                    if (is_array($raw) === true) {
                        foreach ($raw as $optRaw) {
                            $opt = trim((string) $optRaw);
                            if ($opt === '') {
                                continue;
                            }
                            $opts[] = mb_substr($opt, 0, self::CAP_OPTION_LEN);
                            if (count($opts) >= self::CAP_OPTIONS) {
                                break;
                            }
                        }
                    }
                    if (count($opts) > 0) {
                        // 🔢 Re-indexed 0..n — the array index IS the value
                        // a choice field submits (bounds-checked integer,
                        // never raw option text — see file header).
                        $clean['options'] = array_values($opts);
                    }
                    break;

                default:
                    // 🛡️ Unreachable in practice (FIELD_TYPES only ever
                    // names the keys handled above) — but if a future
                    // registry entry names an unhandled config key, drop
                    // it rather than pass it through unsanitised.
                    break;
            }
        }

        return $clean;
    }

    /**
     * a-z0-9_ slug of the label, mb-cut to 64, uniqued per form with a
     * _2/_3… suffix via a prepared SELECT loop. Never trusts client input
     * — this is the ONLY place a fieldKey is minted.
     *
     * @param int    $formId
     * @param string $label
     * @return string
     */
    public static function generateFieldKey(int $formId, string $label): string
    {
        $base = mb_strtolower(trim($label));
        // 🧹 Anything outside a-z0-9 becomes an underscore, then collapse
        // runs of underscores and trim the ends.
        $base = (string) preg_replace('/[^a-z0-9]+/u', '_', $base);
        $base = trim($base, '_');
        if ($base === '') {
            $base = 'field';
        }
        $base = mb_substr($base, 0, 64);

        $db = App::db();
        $stmt = $db->prepare('SELECT 1 FROM tblFormFields WHERE formID = ? AND fieldKey = ? LIMIT 1');
        if ($stmt === false) {
            return $base;
        }

        $candidate = $base;
        $suffix = 2;
        while (true) {
            $stmt->bind_param('is', $formId, $candidate);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_assoc() !== null;
            if ($exists === false) {
                break;
            }
            $suffixStr = '_' . $suffix;
            $candidate = mb_substr($base, 0, 64 - mb_strlen($suffixStr)) . $suffixStr;
            $suffix++;
            // 🛡️ Defensive ceiling — a form can have at most
            // CAP_FIELDS_PER_FORM fields, so this loop is bounded by far
            // fewer iterations than this in practice.
            if ($suffix > 1000) {
                $candidate = mb_substr($base, 0, 58) . '_' . bin2hex(random_bytes(3));
                break;
            }
        }
        $stmt->close();

        return $candidate;
    }

    /**
     * bin2hex(random_bytes(16)) — 32 lowercase hex chars (house token
     * shape — matches assets/tag.php, service-plans publicToken, etc).
     *
     * @return string
     */
    public static function generatePublicToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    // =============================================================================
    // 🎨 Rendering (fill pages)
    // =============================================================================

    /**
     * Returns the Bootstrap-form HTML for ONE field. EVERY dynamic string
     * (label, helpText, placeholder, option text, old value, error
     * message) passes through htmlspecialchars(ENT_QUOTES, 'UTF-8') at the
     * echo point. The name/id attribute is ALWAYS 'f_' . (int) fieldID —
     * a server-controlled integer, never admin text. Choice options are
     * rendered value="<int index>". Unknown fieldType renders ''.
     *
     * @param array<string,mixed>          $field  A row from getFields()
     * @param array<string,mixed>          $old    fieldKey => raw submitted value (string|string[]) for redisplay after a validation error
     * @param array<string,string>         $errors fieldKey => error message
     * @return string
     */
    public static function renderField(array $field, array $old = [], array $errors = []): string
    {
        $type = (string) ($field['fieldType'] ?? '');
        if (isset(self::FIELD_TYPES[$type]) === false) {
            return '';
        }

        $fieldId   = (int) ($field['fieldID'] ?? 0);
        $fieldKey  = (string) ($field['fieldKey'] ?? '');
        $name      = 'f_' . $fieldId;
        $label     = htmlspecialchars((string) ($field['label'] ?? ''), ENT_QUOTES, 'UTF-8');
        $helpText  = (string) ($field['helpText'] ?? '');
        $required  = (int) ($field['isRequired'] ?? 0) === 1;
        $config    = self::resolveConfig($field);
        $errorMsg  = (string) ($errors[$fieldKey] ?? '');
        $hasError  = $errorMsg !== '';
        $oldValue  = $old[$fieldKey] ?? null;

        $requiredMark = $required === true ? ' <span class="text-danger">*</span>' : '';
        $requiredAttr = $required === true ? ' required' : '';
        $invalidClass = $hasError === true ? ' is-invalid' : '';
        $feedback     = $hasError === true
            ? '<div class="invalid-feedback">' . htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8') . '</div>'
            : '';
        $helpHtml = $helpText !== ''
            ? '<div class="form-text">' . htmlspecialchars($helpText, ENT_QUOTES, 'UTF-8') . '</div>'
            : '';

        // 🔠 Heading — display-only, no input, no name/id at all.
        if ($type === 'heading') {
            $html = '<h5 class="mt-4">' . $label . '</h5>';
            if ($helpText !== '') {
                $html .= '<p class="text-muted small">' . htmlspecialchars($helpText, ENT_QUOTES, 'UTF-8') . '</p>';
            }
            return $html;
        }

        $wrapOpen  = '<div class="mb-3">';
        $wrapClose = '</div>';
        $labelHtml = '<label for="' . $name . '" class="form-label">' . $label . $requiredMark . '</label>';

        switch ($type) {
            case 'text':
            case 'email':
            case 'phone':
                $inputType = $type === 'email' ? 'email' : ($type === 'phone' ? 'tel' : 'text');
                $maxLen = isset($config['maxLength']) ? ' maxlength="' . (int) $config['maxLength'] . '"' : '';
                $placeholder = isset($config['placeholder'])
                    ? ' placeholder="' . htmlspecialchars((string) $config['placeholder'], ENT_QUOTES, 'UTF-8') . '"'
                    : '';
                $valAttr = ' value="' . htmlspecialchars(is_string($oldValue) ? $oldValue : '', ENT_QUOTES, 'UTF-8') . '"';
                return $wrapOpen . $labelHtml
                    . '<input type="' . $inputType . '" class="form-control' . $invalidClass . '" id="' . $name . '" name="' . $name . '"'
                    . $maxLen . $placeholder . $valAttr . $requiredAttr . '>'
                    . $feedback . $helpHtml . $wrapClose;

            case 'textarea':
                $maxLen = isset($config['maxLength']) ? ' maxlength="' . (int) $config['maxLength'] . '"' : '';
                $rows = (int) ($config['rows'] ?? 4);
                $placeholder = isset($config['placeholder'])
                    ? ' placeholder="' . htmlspecialchars((string) $config['placeholder'], ENT_QUOTES, 'UTF-8') . '"'
                    : '';
                $textVal = htmlspecialchars(is_string($oldValue) ? $oldValue : '', ENT_QUOTES, 'UTF-8');
                return $wrapOpen . $labelHtml
                    . '<textarea class="form-control' . $invalidClass . '" id="' . $name . '" name="' . $name . '" rows="' . $rows . '"'
                    . $maxLen . $placeholder . $requiredAttr . '>' . $textVal . '</textarea>'
                    . $feedback . $helpHtml . $wrapClose;

            case 'number':
                $min = isset($config['min']) ? ' min="' . htmlspecialchars((string) $config['min'], ENT_QUOTES, 'UTF-8') . '"' : '';
                $max = isset($config['max']) ? ' max="' . htmlspecialchars((string) $config['max'], ENT_QUOTES, 'UTF-8') . '"' : '';
                $valAttr = ' value="' . htmlspecialchars(is_string($oldValue) ? $oldValue : '', ENT_QUOTES, 'UTF-8') . '"';
                return $wrapOpen . $labelHtml
                    . '<input type="number" class="form-control' . $invalidClass . '" id="' . $name . '" name="' . $name . '"'
                    . $min . $max . $valAttr . $requiredAttr . '>'
                    . $feedback . $helpHtml . $wrapClose;

            case 'date':
            case 'time':
                $inputType = $type;
                $valAttr = ' value="' . htmlspecialchars(is_string($oldValue) ? $oldValue : '', ENT_QUOTES, 'UTF-8') . '"';
                return $wrapOpen . $labelHtml
                    . '<input type="' . $inputType . '" class="form-control' . $invalidClass . '" id="' . $name . '" name="' . $name . '"'
                    . $valAttr . $requiredAttr . '>'
                    . $feedback . $helpHtml . $wrapClose;

            case 'select':
                $options = (array) ($config['options'] ?? []);
                $selectedIdx = is_string($oldValue) && ctype_digit($oldValue) === true ? (int) $oldValue : -1;
                $optHtml = '<option value="">— Please choose —</option>';
                foreach ($options as $idx => $optText) {
                    $selAttr = $idx === $selectedIdx ? ' selected' : '';
                    $optHtml .= '<option value="' . (int) $idx . '"' . $selAttr . '>'
                        . htmlspecialchars((string) $optText, ENT_QUOTES, 'UTF-8') . '</option>';
                }
                return $wrapOpen . $labelHtml
                    . '<select class="form-select' . $invalidClass . '" id="' . $name . '" name="' . $name . '"' . $requiredAttr . '>'
                    . $optHtml . '</select>'
                    . $feedback . $helpHtml . $wrapClose;

            case 'radio':
                $options = (array) ($config['options'] ?? []);
                $selectedIdx = is_string($oldValue) && ctype_digit($oldValue) === true ? (int) $oldValue : -1;
                $html = $wrapOpen . '<div class="form-label">' . $label . $requiredMark . '</div>';
                foreach ($options as $idx => $optText) {
                    $optId = $name . '_' . (int) $idx;
                    $checked = $idx === $selectedIdx ? ' checked' : '';
                    $html .= '<div class="form-check">'
                        . '<input class="form-check-input' . $invalidClass . '" type="radio" name="' . $name . '" id="' . $optId . '" value="' . (int) $idx . '"' . $checked . '>'
                        . '<label class="form-check-label" for="' . $optId . '">' . htmlspecialchars((string) $optText, ENT_QUOTES, 'UTF-8') . '</label>'
                        . '</div>';
                }
                return $html . $feedback . $helpHtml . $wrapClose;

            case 'checkboxes':
                $options = (array) ($config['options'] ?? []);
                $selectedIdxs = [];
                if (is_array($oldValue) === true) {
                    foreach ($oldValue as $v) {
                        if (is_string($v) === true && ctype_digit($v) === true) {
                            $selectedIdxs[(int) $v] = true;
                        }
                    }
                }
                $html = $wrapOpen . '<div class="form-label">' . $label . $requiredMark . '</div>';
                foreach ($options as $idx => $optText) {
                    $optId = $name . '_' . (int) $idx;
                    $checked = isset($selectedIdxs[(int) $idx]) === true ? ' checked' : '';
                    $html .= '<div class="form-check">'
                        . '<input class="form-check-input' . $invalidClass . '" type="checkbox" name="' . $name . '[]" id="' . $optId . '" value="' . (int) $idx . '"' . $checked . '>'
                        . '<label class="form-check-label" for="' . $optId . '">' . htmlspecialchars((string) $optText, ENT_QUOTES, 'UTF-8') . '</label>'
                        . '</div>';
                }
                return $html . $feedback . $helpHtml . $wrapClose;

            case 'checkbox':
                $checked = $oldValue === '1' ? ' checked' : '';
                return $wrapOpen . '<div class="form-check' . $invalidClass . '">'
                    . '<input class="form-check-input' . $invalidClass . '" type="checkbox" name="' . $name . '" id="' . $name . '" value="1"' . $checked . $requiredAttr . '>'
                    . '<label class="form-check-label" for="' . $name . '">' . $label . $requiredMark . '</label>'
                    . '</div>'
                    . $feedback . $helpHtml . $wrapClose;

            default:
                // 🛡️ Unreachable — $type was already verified against
                // FIELD_TYPES above — but keeps the switch total.
                return '';
        }
    }

    // =============================================================================
    // ✅ Validation + persistence
    // =============================================================================

    /**
     * Validates $_POST against the field list. Never throws.
     *
     * @param array<int,array<string,mixed>> $fields Rows from getFields()
     * @param array<string,mixed>            $post   Raw $_POST
     * @return array{values: array<string,mixed>, errors: array<string,string>, old: array<string,mixed>}
     */
    public static function validateSubmission(array $fields, array $post): array
    {
        $values = [];
        $errors = [];
        $old    = [];

        foreach ($fields as $field) {
            $type = (string) ($field['fieldType'] ?? '');
            if (isset(self::FIELD_TYPES[$type]) === false || self::FIELD_TYPES[$type]['input'] === false) {
                // 🛡️ Unknown type or display-only ('heading') — skipped
                // entirely, defends against a hand-edited row.
                continue;
            }

            $fieldKey  = (string) ($field['fieldKey'] ?? '');
            $label     = (string) ($field['label'] ?? $fieldKey);
            $name      = 'f_' . (int) ($field['fieldID'] ?? 0);
            $required  = (int) ($field['isRequired'] ?? 0) === 1;
            $config    = self::resolveConfig($field);

            if ($type === 'checkboxes') {
                $raw = $post[$name] ?? [];
                $raw = is_array($raw) === true ? $raw : [];
                $old[$fieldKey] = $raw;
            } else {
                $raw = trim((string) ($post[$name] ?? ''));
                $old[$fieldKey] = $raw;
            }

            // ── Required check ───────────────────────────────────────────
            if ($type === 'checkboxes') {
                if ($required === true && count($raw) === 0) {
                    $errors[$fieldKey] = $label . ' — please choose at least one option.';
                    continue;
                }
            } elseif ($type === 'checkbox') {
                if ($required === true && $raw !== '1') {
                    $errors[$fieldKey] = $label . ' is required.';
                    continue;
                }
            } else {
                if ($required === true && $raw === '') {
                    $errors[$fieldKey] = $label . ' is required.';
                    continue;
                }
                if ($required === false && $raw === '') {
                    // ✅ Optional and blank — store empty, no further checks.
                    $values[$fieldKey] = '';
                    continue;
                }
            }

            // ── Per-type validation ──────────────────────────────────────
            switch ($type) {
                case 'text':
                    $maxLen = (int) ($config['maxLength'] ?? self::CAP_TEXT_MAXLEN);
                    $values[$fieldKey] = mb_substr($raw, 0, $maxLen);
                    break;

                case 'textarea':
                    $maxLen = (int) ($config['maxLength'] ?? self::CAP_TEXTAREA_MAXLEN);
                    $values[$fieldKey] = mb_substr($raw, 0, $maxLen);
                    break;

                case 'email':
                    if (filter_var($raw, FILTER_VALIDATE_EMAIL) === false || mb_strlen($raw) > 255) {
                        $errors[$fieldKey] = $label . ' must be a valid email address.';
                        break;
                    }
                    $values[$fieldKey] = mb_substr($raw, 0, 255);
                    break;

                case 'phone':
                    if (preg_match('/^[0-9+()\s.\-]{2,40}$/', $raw) !== 1) {
                        $errors[$fieldKey] = $label . ' must be a valid phone number.';
                        break;
                    }
                    $values[$fieldKey] = $raw;
                    break;

                case 'number':
                    if (is_numeric($raw) === false) {
                        $errors[$fieldKey] = $label . ' must be a number.';
                        break;
                    }
                    $num = $raw + 0;
                    if (isset($config['min']) === true && $num < $config['min']) {
                        $errors[$fieldKey] = $label . ' must be at least ' . $config['min'] . '.';
                        break;
                    }
                    if (isset($config['max']) === true && $num > $config['max']) {
                        $errors[$fieldKey] = $label . ' must be at most ' . $config['max'] . '.';
                        break;
                    }
                    $values[$fieldKey] = (string) $num;
                    break;

                case 'date':
                    // 🗓️ House idiom (Portal\Core\Validator::validateDate) —
                    // strict parse + round-trip re-format catches invalid
                    // calendar dates like 2024-02-30, which
                    // createFromFormat() alone silently rolls over.
                    $parsed = \DateTime::createFromFormat('Y-m-d', $raw);
                    if ($parsed === false || $parsed->format('Y-m-d') !== $raw) {
                        $errors[$fieldKey] = $label . ' must be a valid date (YYYY-MM-DD).';
                        break;
                    }
                    $values[$fieldKey] = $raw;
                    break;

                case 'time':
                    if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $raw) !== 1) {
                        $errors[$fieldKey] = $label . ' must be a valid time (HH:MM).';
                        break;
                    }
                    $values[$fieldKey] = $raw;
                    break;

                case 'select':
                case 'radio':
                    $options = (array) ($config['options'] ?? []);
                    if (ctype_digit($raw) === false || isset($options[(int) $raw]) === false) {
                        $errors[$fieldKey] = $label . ' — please choose a valid option.';
                        break;
                    }
                    $values[$fieldKey] = (string) $options[(int) $raw];
                    break;

                case 'checkboxes':
                    $options = (array) ($config['options'] ?? []);
                    $chosen = [];
                    $seen = [];
                    foreach ($raw as $idxRaw) {
                        $idxRaw = (string) $idxRaw;
                        if (ctype_digit($idxRaw) === false) {
                            continue; // 🛡️ ignore a fabricated non-numeric entry
                        }
                        $idx = (int) $idxRaw;
                        if (isset($options[$idx]) === false || isset($seen[$idx]) === true) {
                            continue; // 🛡️ out-of-range or duplicate — ignored, not fatal
                        }
                        $seen[$idx] = true;
                        $chosen[] = (string) $options[$idx];
                    }
                    if ($required === true && count($chosen) === 0) {
                        $errors[$fieldKey] = $label . ' — please choose at least one option.';
                        break;
                    }
                    $values[$fieldKey] = $chosen;
                    break;

                case 'checkbox':
                    $values[$fieldKey] = $raw === '1' ? 'yes' : '';
                    break;

                default:
                    // 🛡️ Unreachable — $type already whitelisted above.
                    break;
            }
        }

        return ['values' => $values, 'errors' => $errors, 'old' => $old];
    }

    /**
     * json_encode of the immutable snapshot:
     * {fieldKey: {label, type, value}} for every INPUT field (unanswered
     * optional fields stored with value ''). Display-only types
     * (heading) are never included. JSON_UNESCAPED_UNICODE.
     *
     * @param array<int,array<string,mixed>> $fields Rows from getFields()
     * @param array<string,mixed>            $values Output of validateSubmission()['values']
     * @return string
     */
    public static function buildAnswersJson(array $fields, array $values): string
    {
        $snapshot = [];
        foreach ($fields as $field) {
            $type = (string) ($field['fieldType'] ?? '');
            if (isset(self::FIELD_TYPES[$type]) === false || self::FIELD_TYPES[$type]['input'] === false) {
                continue;
            }
            $fieldKey = (string) ($field['fieldKey'] ?? '');
            $snapshot[$fieldKey] = [
                'label' => (string) ($field['label'] ?? $fieldKey),
                'type'  => $type,
                'value' => $values[$fieldKey] ?? '',
            ];
        }

        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE);

        return $json === false ? '{}' : $json;
    }

    /**
     * Prepared INSERT into tblFormResponses; returns the new responseID or
     * 0 on failure. Column order + type string is R1 in the build spec's
     * bind_param arity table — keep them in lockstep with the migration's
     * CREATE TABLE column order.
     *
     * Also fires WebhookDispatcher::emit('forms.response.received', …)
     * behind a class_exists() guard (salvation/card-save.php precedent) —
     * WebhookDispatcher resolves its own siteID from ambient Site::id(),
     * same as every other emit() call site in this codebase.
     *
     * @param int         $formId
     * @param int         $siteId
     * @param string      $channel     'internal'|'public'
     * @param int|null    $submitterId NULL for public submissions
     * @param string|null $submitterIp Public channel only
     * @param string      $answersJson From buildAnswersJson()
     * @return int New responseID, or 0 on failure
     */
    public static function saveResponse(
        int $formId,
        int $siteId,
        string $channel,
        ?int $submitterId,
        ?string $submitterIp,
        string $answersJson
    ): int {
        $db = App::db();
        $stmt = $db->prepare(
            'INSERT INTO tblFormResponses (formID, siteID, channel, submitterID, submitterIP, answersJson) '
            . 'VALUES (?, ?, ?, ?, ?, ?)'
        );
        if ($stmt === false) {
            Logger::errorPlatform('MySQL', 'Error', 'FORM_RESPONSE_INSERT_PREP', $db->error, 'formID=' . $formId);
            return 0;
        }

        $stmt->bind_param('iisiss', $formId, $siteId, $channel, $submitterId, $submitterIp, $answersJson);
        $ok = $stmt->execute();
        $newId = (int) $stmt->insert_id;
        $stmt->close();

        if ($ok === false) {
            Logger::errorPlatform('MySQL', 'Error', 'FORM_RESPONSE_INSERT_FAIL', $db->error, 'formID=' . $formId);
            return 0;
        }

        if (class_exists('\\Portal\\Core\\WebhookDispatcher') === true) {
            \Portal\Core\WebhookDispatcher::emit('forms.response.received', [
                'formID'     => $formId,
                'responseID' => $newId,
                'channel'    => $channel,
            ]);
        }

        return $newId;
    }

    /**
     * decode answersJson back to an array. json_decode assoc, depth 8,
     * invalid/empty JSON decodes to [].
     *
     * @param string|null $json
     * @return array<string,mixed>
     */
    public static function decodeAnswers(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true, 8);

        return is_array($decoded) === true ? $decoded : [];
    }

    /**
     * CSV assembly for export.php: header row = Response ID, Submitted,
     * Channel, Submitter, Status + one column per CURRENT field (by
     * position, header = label) + any orphaned fieldKeys found only in
     * historical snapshots (a field since deleted) appended at the end
     * (header = fieldKey). checkboxes arrays are joined '; '.
     * CsvExporter::download() handles quoting/formula-injection guarding —
     * no manual escaping needed here.
     *
     * @param int $formId
     * @param int $siteId
     * @return array{headers: array<int,string>, rows: array<int,array<string,string>>}
     */
    public static function csvRows(int $formId, int $siteId): array
    {
        $form = self::getForm($formId, $siteId);
        if ($form === null) {
            return ['headers' => [], 'rows' => []];
        }

        $fields = self::getFields($formId);
        // 📋 Only INPUT fields carry answers — 'heading' has no column.
        $inputFields = array_values(array_filter(
            $fields,
            static fn (array $f): bool => isset(self::FIELD_TYPES[(string) ($f['fieldType'] ?? '')]) === true
                && self::FIELD_TYPES[(string) $f['fieldType']]['input'] === true
        ));

        $fixedHeaders = ['Response ID', 'Submitted', 'Channel', 'Submitter', 'Status'];
        $fieldHeaders = [];
        $keyToHeader  = [];
        foreach ($inputFields as $f) {
            $fk = (string) $f['fieldKey'];
            $keyToHeader[$fk] = (string) $f['label'];
            $fieldHeaders[] = (string) $f['label'];
        }

        $db = App::db();
        $stmt = $db->prepare(
            'SELECT r.responseID, r.channel, r.submitterID, r.status, r.answersJson, r.createdAt, u.fullName '
            . 'FROM tblFormResponses r LEFT JOIN tblUsers u ON u.userID = r.submitterID '
            . 'WHERE r.formID = ? AND r.siteID = ? ORDER BY r.createdAt DESC LIMIT 5000'
        );
        if ($stmt === false) {
            return ['headers' => array_merge($fixedHeaders, $fieldHeaders), 'rows' => []];
        }
        $stmt->bind_param('ii', $formId, $siteId);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        $orphanKeys = [];
        while ($r = $result->fetch_assoc()) {
            $answers = self::decodeAnswers((string) $r['answersJson']);
            $row = [
                'Response ID' => (string) $r['responseID'],
                'Submitted'   => (string) $r['createdAt'],
                'Channel'     => (string) $r['channel'],
                'Submitter'   => $r['submitterID'] !== null ? (string) ($r['fullName'] ?? ('#' . $r['submitterID'])) : 'Public',
                'Status'      => (string) $r['status'],
            ];
            foreach ($keyToHeader as $fk => $header) {
                $entry = $answers[$fk] ?? null;
                $val = is_array($entry) ? ($entry['value'] ?? '') : '';
                if (is_array($entry) === false) {
                    $val = '';
                }
                $row[$header] = self::formatCsvValue($val);
            }
            // 📦 Orphaned keys — present in this response's snapshot but no
            // longer a current field (deleted after submission). Collected
            // for a trailing column block, header = fieldKey (spec §4).
            foreach ($answers as $fk => $entry) {
                if (isset($keyToHeader[$fk]) === true) {
                    continue;
                }
                $orphanKeys[$fk] = true;
            }
            $rows[] = $row + ['__answers' => $answers];
        }
        $stmt->close();

        $orphanHeaders = array_keys($orphanKeys);
        $headers = array_merge($fixedHeaders, $fieldHeaders, $orphanHeaders);

        $finalRows = [];
        foreach ($rows as $row) {
            $answers = $row['__answers'];
            unset($row['__answers']);
            foreach ($orphanHeaders as $fk) {
                $entry = $answers[$fk] ?? null;
                $val = is_array($entry) ? ($entry['value'] ?? '') : '';
                $row[$fk] = self::formatCsvValue($val);
            }
            $finalRows[] = $row;
        }

        return ['headers' => $headers, 'rows' => $finalRows];
    }

    // =============================================================================
    // 🔒 Private helpers
    // =============================================================================

    /**
     * A field row's configJson may arrive as an array (already decoded by
     * getFields()), a JSON string (a raw row read some other way), or
     * NULL. Always resolves to a sanitised array — re-running
     * sanitiseConfig() even on an already-array input is deliberate
     * defence in depth against a hand-edited/tampered row.
     *
     * @param array<string,mixed> $field
     * @return array<string,mixed>
     */
    private static function resolveConfig(array $field): array
    {
        $type = (string) ($field['fieldType'] ?? '');
        $raw = $field['configJson'] ?? null;

        if (is_string($raw) === true) {
            $decoded = json_decode($raw, true, 8);
            $raw = is_array($decoded) === true ? $decoded : [];
        } elseif (is_array($raw) === false) {
            $raw = [];
        }

        return self::sanitiseConfig($type, $raw);
    }

    /**
     * A stored answer 'value' is either a string (most types) or an array
     * (checkboxes). Format for a single CSV cell.
     *
     * @param mixed $value
     * @return string
     */
    private static function formatCsvValue(mixed $value): string
    {
        if (is_array($value) === true) {
            return implode('; ', array_map(static fn ($v): string => (string) $v, $value));
        }

        return (string) $value;
    }
}
