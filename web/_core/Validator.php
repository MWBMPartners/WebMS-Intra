<?php
// Path: _core/Validator.php
/**
 * -----------------------------------------------------------------------------
 * Input Validation Framework ✅
 * -----------------------------------------------------------------------------
 * Lightweight, rule-based validator for associative data arrays. Rules are
 * defined as pipe-separated strings mapped to field names, following a
 * Laravel-inspired syntax adapted for the Portal framework.
 *
 * Supported rules:
 *   required        — field must be present and non-empty
 *   string          — value must be a string
 *   int             — value must be numeric (integer)
 *   email           — value must be a valid email address
 *   max:N           — string max length N characters
 *   min:N           — string min length N characters
 *   date            — value must be a valid date (Y-m-d)
 *   in:val1,val2    — value must be one of the listed options
 *   regex:pattern   — value must match the given regex pattern
 *
 * Usage:
 *   $v = new Validator($_POST, [
 *       'fullName' => 'required|string|max:255',
 *       'email'    => 'required|email',
 *       'deptID'   => 'required|int',
 *       'status'   => 'in:active,inactive',
 *   ]);
 *   if ($v->fails()) {
 *       $_SESSION['flash_msg'] = implode(' ', $v->firstErrors());
 *   }
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class Validator
{
    // 📦 -----------------------------------------------------------------------
    // Properties
    // -----------------------------------------------------------------------

    /** @var array<string, mixed> The data array to validate */
    private array $data;

    /** @var array<string, string> Field-to-rules mapping (pipe-separated strings) */
    private array $rules;

    /** @var array<string, array<int, string>> Collected error messages per field */
    private array $errors = [];

    /** @var bool Whether validation has already been executed */
    private bool $validated = false;

    // 🏗️ -----------------------------------------------------------------------
    // Constructor
    // -----------------------------------------------------------------------

    /**
     * Create a new Validator instance.
     *
     * @param array<string, mixed>  $data  Associative array of input data
     * @param array<string, string> $rules Associative array mapping field names
     *                                     to pipe-separated rule strings
     */
    public function __construct(array $data, array $rules)
    {
        $this->data  = $data;
        $this->rules = $rules;
    }

    // ✅ -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Determine if the data passes all validation rules.
     *
     * @return bool True if every field satisfies its rules
     */
    public function passes(): bool
    {
        if ($this->validated === false) {
            $this->runValidation();
        }

        return count($this->errors) === 0;
    }

    /**
     * Determine if the data fails one or more validation rules.
     *
     * @return bool True if at least one field has an error
     */
    public function fails(): bool
    {
        return $this->passes() === false;
    }

    /**
     * Retrieve all validation error messages grouped by field.
     *
     * @return array<string, array<int, string>> Field => [message, ...]
     */
    public function errors(): array
    {
        if ($this->validated === false) {
            $this->runValidation();
        }

        return $this->errors;
    }

    /**
     * Retrieve only the first error message for each field that failed.
     *
     * @return array<string, string> Field => first error message
     */
    public function firstErrors(): array
    {
        $first = [];
        foreach ($this->errors() as $field => $messages) {
            if (count($messages) > 0) {
                $first[$field] = $messages[0];
            }
        }

        return $first;
    }

    // 🔄 -----------------------------------------------------------------------
    // Validation Engine
    // -----------------------------------------------------------------------

    /**
     * Execute all validation rules against the data.
     *
     * Iterates each field's pipe-separated rule string, splitting into
     * individual rules, then dispatching to the appropriate check method.
     *
     * @return void
     */
    private function runValidation(): void
    {
        $this->errors    = [];
        $this->validated = true;

        foreach ($this->rules as $field => $ruleString) {
            $ruleParts = explode('|', $ruleString);

            foreach ($ruleParts as $rule) {
                $this->applyRule($field, trim($rule));
            }
        }
    }

    /**
     * Apply a single rule to a field, adding an error message on failure.
     *
     * Rules with parameters use colon syntax, e.g. "max:255" or "in:a,b,c".
     *
     * @param string $field The field name being validated
     * @param string $rule  The rule identifier (possibly with :param suffix)
     *
     * @return void
     */
    private function applyRule(string $field, string $rule): void
    {
        // 🔍 Parse rule name and optional parameter
        $param    = null;
        $ruleName = $rule;

        // Handle regex separately because the pattern may contain colons
        if (strpos($rule, 'regex:') === 0) {
            $ruleName = 'regex';
            $param    = substr($rule, 6); // everything after "regex:"
        } elseif (strpos($rule, ':') !== false) {
            [$ruleName, $param] = explode(':', $rule, 2);
        }

        $value  = $this->data[$field] ?? null;
        $exists = array_key_exists($field, $this->data);

        // 📋 Human-readable field label (camelCase → spaced words)
        $label = $this->humanize($field);

        // 🚦 Dispatch to rule handler
        switch ($ruleName) {
            case 'required':
                $this->validateRequired($field, $label, $value, $exists);
                break;

            case 'string':
                $this->validateString($field, $label, $value, $exists);
                break;

            case 'int':
                $this->validateInt($field, $label, $value, $exists);
                break;

            case 'email':
                $this->validateEmail($field, $label, $value, $exists);
                break;

            case 'max':
                $this->validateMax($field, $label, $value, $exists, (int) $param);
                break;

            case 'min':
                $this->validateMin($field, $label, $value, $exists, (int) $param);
                break;

            case 'date':
                $this->validateDate($field, $label, $value, $exists);
                break;

            case 'in':
                $this->validateIn($field, $label, $value, $exists, (string) $param);
                break;

            case 'regex':
                $this->validateRegex($field, $label, $value, $exists, (string) $param);
                break;
        }
    }

    // 📏 -----------------------------------------------------------------------
    // Individual Rule Handlers
    // -----------------------------------------------------------------------

    /**
     * Validate that a field is present and non-empty.
     *
     * @param string $field  Field name
     * @param string $label  Human-readable label
     * @param mixed  $value  The field value
     * @param bool   $exists Whether the field exists in the data array
     *
     * @return void
     */
    private function validateRequired(string $field, string $label, mixed $value, bool $exists): void
    {
        if ($exists === false || $value === null || $value === '') {
            $this->addError($field, "The {$label} field is required.");
        }
    }

    /**
     * Validate that a field value is a string.
     *
     * Skips validation if the field is absent (use 'required' to enforce presence).
     *
     * @param string $field  Field name
     * @param string $label  Human-readable label
     * @param mixed  $value  The field value
     * @param bool   $exists Whether the field exists in the data array
     *
     * @return void
     */
    private function validateString(string $field, string $label, mixed $value, bool $exists): void
    {
        if ($exists === false || $value === null || $value === '') {
            return;
        }

        if (is_string($value) === false) {
            $this->addError($field, "The {$label} must be a string.");
        }
    }

    /**
     * Validate that a field value is an integer (or numeric string representing one).
     *
     * @param string $field  Field name
     * @param string $label  Human-readable label
     * @param mixed  $value  The field value
     * @param bool   $exists Whether the field exists in the data array
     *
     * @return void
     */
    private function validateInt(string $field, string $label, mixed $value, bool $exists): void
    {
        if ($exists === false || $value === null || $value === '') {
            return;
        }

        // 🔢 Accept actual ints and numeric strings that represent whole numbers
        if (is_int($value) === true) {
            return;
        }

        if (is_string($value) === true && preg_match('/^-?\d+$/', $value) === 1) {
            return;
        }

        $this->addError($field, "The {$label} must be an integer.");
    }

    /**
     * Validate that a field value is a valid email address.
     *
     * Uses PHP's built-in FILTER_VALIDATE_EMAIL for RFC-compliant checking.
     *
     * @see   https://www.php.net/manual/en/filter.filters.validate.php
     *
     * @param string $field  Field name
     * @param string $label  Human-readable label
     * @param mixed  $value  The field value
     * @param bool   $exists Whether the field exists in the data array
     *
     * @return void
     */
    private function validateEmail(string $field, string $label, mixed $value, bool $exists): void
    {
        if ($exists === false || $value === null || $value === '') {
            return;
        }

        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->addError($field, "The {$label} must be a valid email address.");
        }
    }

    /**
     * Validate that a string field does not exceed a maximum length.
     *
     * @param string $field    Field name
     * @param string $label    Human-readable label
     * @param mixed  $value    The field value
     * @param bool   $exists   Whether the field exists in the data array
     * @param int    $maxLen   Maximum allowed character length
     *
     * @return void
     */
    private function validateMax(string $field, string $label, mixed $value, bool $exists, int $maxLen): void
    {
        if ($exists === false || $value === null || $value === '') {
            return;
        }

        if (is_string($value) === true && mb_strlen($value) > $maxLen) {
            $this->addError($field, "The {$label} must not exceed {$maxLen} characters.");
        }
    }

    /**
     * Validate that a string field meets a minimum length.
     *
     * @param string $field    Field name
     * @param string $label    Human-readable label
     * @param mixed  $value    The field value
     * @param bool   $exists   Whether the field exists in the data array
     * @param int    $minLen   Minimum required character length
     *
     * @return void
     */
    private function validateMin(string $field, string $label, mixed $value, bool $exists, int $minLen): void
    {
        if ($exists === false || $value === null || $value === '') {
            return;
        }

        if (is_string($value) === true && mb_strlen($value) < $minLen) {
            $this->addError($field, "The {$label} must be at least {$minLen} characters.");
        }
    }

    /**
     * Validate that a field value is a valid date in Y-m-d format.
     *
     * Uses DateTime::createFromFormat for strict parsing, then verifies
     * the formatted output matches the input to catch invalid dates
     * like 2024-02-30.
     *
     * @see   https://www.php.net/manual/en/datetime.createfromformat.php
     *
     * @param string $field  Field name
     * @param string $label  Human-readable label
     * @param mixed  $value  The field value
     * @param bool   $exists Whether the field exists in the data array
     *
     * @return void
     */
    private function validateDate(string $field, string $label, mixed $value, bool $exists): void
    {
        if ($exists === false || $value === null || $value === '') {
            return;
        }

        $parsed = \DateTime::createFromFormat('Y-m-d', (string) $value);

        if ($parsed === false || $parsed->format('Y-m-d') !== (string) $value) {
            $this->addError($field, "The {$label} must be a valid date (YYYY-MM-DD).");
        }
    }

    /**
     * Validate that a field value is one of a set of allowed values.
     *
     * The allowed values are provided as a comma-separated string, e.g. "in:active,inactive".
     *
     * @param string $field       Field name
     * @param string $label       Human-readable label
     * @param mixed  $value       The field value
     * @param bool   $exists      Whether the field exists in the data array
     * @param string $optionsCsv  Comma-separated list of allowed values
     *
     * @return void
     */
    private function validateIn(string $field, string $label, mixed $value, bool $exists, string $optionsCsv): void
    {
        if ($exists === false || $value === null || $value === '') {
            return;
        }

        $allowed = explode(',', $optionsCsv);

        if (in_array((string) $value, $allowed, true) === false) {
            $list = implode(', ', $allowed);
            $this->addError($field, "The {$label} must be one of: {$list}.");
        }
    }

    /**
     * Validate that a field value matches a regular expression pattern.
     *
     * The pattern should include delimiters, e.g. "regex:/^[A-Z]+$/i".
     *
     * @param string $field   Field name
     * @param string $label   Human-readable label
     * @param mixed  $value   The field value
     * @param bool   $exists  Whether the field exists in the data array
     * @param string $pattern The regex pattern (with delimiters)
     *
     * @return void
     */
    private function validateRegex(string $field, string $label, mixed $value, bool $exists, string $pattern): void
    {
        if ($exists === false || $value === null || $value === '') {
            return;
        }

        if (preg_match($pattern, (string) $value) !== 1) {
            $this->addError($field, "The {$label} format is invalid.");
        }
    }

    // 🛠️ -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Add an error message for a given field.
     *
     * @param string $field   The field name
     * @param string $message The human-readable error message
     *
     * @return void
     */
    private function addError(string $field, string $message): void
    {
        if (array_key_exists($field, $this->errors) === false) {
            $this->errors[$field] = [];
        }

        $this->errors[$field][] = $message;
    }

    /**
     * Convert a camelCase or snake_case field name into a human-readable label.
     *
     * Examples:
     *   'fullName'  → 'full name'
     *   'deptID'    → 'dept ID'
     *   'user_name' → 'user name'
     *
     * @param string $field The raw field name
     *
     * @return string The human-friendly label
     */
    private function humanize(string $field): string
    {
        // 🔤 Insert space before uppercase letters (camelCase → spaced)
        $spaced = (string) preg_replace('/([a-z])([A-Z])/', '$1 $2', $field);

        // Replace underscores with spaces
        $spaced = str_replace('_', ' ', $spaced);

        // Lowercase the result (preserving acronyms like "ID" naturally)
        return mb_strtolower($spaced);
    }
}
