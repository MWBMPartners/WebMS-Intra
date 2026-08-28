<?php
// Path: _core/brand-defaults.php
/**
 * -----------------------------------------------------------------------------
 * WebMS Intra — Product Brand Presets 🏷️
 * -----------------------------------------------------------------------------
 * SINGLE SOURCE OF TRUTH for the product-level brand layer that sits ABOVE the
 * existing tenant-level `branding.*` settings + Site::branding() cascade.
 *
 * Returns an associative array keyed by `portal.industry` value. Each preset
 * declares the brand identity the installer (and runtime fallbacks) use when
 * no per-org override is set.
 *
 * 🗂️ Two layers of branding, recap:
 *   ┌──────────────────────────────────────────────────────────────────┐
 *   │ Layer 1 — product.*  (this file)                                │
 *   │   Set ONCE at install time via the installer's organisation-type │
 *   │   step. Stored in tblSettings.product.{name,tagline,publisher}.  │
 *   │   Reused by bootstrap (X-Powered-By, error log prefix), the      │
 *   │   shared header/footer templates, the PWA manifest, the OpenAPI  │
 *   │   spec, and the installer wizard itself.                        │
 *   ├──────────────────────────────────────────────────────────────────┤
 *   │ Layer 2 — branding.* / Site::branding()  (already shipped)      │
 *   │   Per-tenant overrides. siteName, logoPath, faviconPath,         │
 *   │   primaryColor, copyrightOrg. Always beats layer 1.              │
 *   └──────────────────────────────────────────────────────────────────┘
 *
 * 🔓 Reversible: the admin can change `portal.industry` at any time via
 *    /admin/settings and the surface will re-derive on next render. The
 *    `product.name`/`tagline` rows stored at install time stay as-is unless
 *    explicitly updated — so admins can either accept the preset defaults or
 *    keep their custom values across an industry switch.
 *
 * 📐 Design notes:
 *   - Bootstrap-free file. MUST NOT use the Portal\Core\ namespace, reference
 *     any class, or call any function defined elsewhere. The installer
 *     `require`s it before the autoloader exists.
 *   - Returns a plain PHP array so any caller can `require` and consume.
 *   - The 'generic' preset's values are the SAME strings the codebase has
 *     historically hardcoded ("WebMS Intra" / etc.). Skipping the installer
 *     step or sticking with `portal.industry = ''` yields zero behavioural
 *     change from before this PR.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/296
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

return [
    // 🏢 Generic — the unaltered platform identity. Default when no
    //    industry is picked at install or when the value is unrecognised.
    '' => [
        'name'        => 'WebMS Intra',
        'tagline'     => 'Internal Management System',
        'publisher'   => 'MWBM Partners Ltd (t/a MWservices)',
        'assetFolder' => 'webms-intra',
        'displayLabel' => 'Generic — internal management portal',
    ],
    'generic' => [
        'name'        => 'WebMS Intra',
        'tagline'     => 'Internal Management System',
        'publisher'   => 'MWBM Partners Ltd (t/a MWservices)',
        'assetFolder' => 'webms-intra',
        'displayLabel' => 'Generic — internal management portal',
    ],

    // ⛪ Church / Place of Worship — the flagship vertical preset.
    //    Same module mix as generic; brand strings + assets differ.
    'church' => [
        'name'        => 'ChurchMS',
        'tagline'     => 'Church Management System',
        'publisher'   => 'MWBM Partners Ltd (t/a MWservices)',
        'assetFolder' => 'churchms',
        'displayLabel' => 'Church / Place of Worship',
    ],

    // 🏫 School — starter artwork shipped #306 (mortarboard emblem). Text
    //    wordmark in logo.svg is system-font, not outlined vectors like the
    //    WebMS/ChurchMS kits — a designer pass is recommended before this
    //    preset goes to a real customer (see logo.svg's own <desc> note).
    'school' => [
        'name'        => 'SchoolMS',
        'tagline'     => 'School Management System',
        'publisher'   => 'MWBM Partners Ltd (t/a MWservices)',
        'assetFolder' => 'schoolms',
        'displayLabel' => 'School / Education',
    ],

    // 🤝 Charity / Non-profit — starter artwork shipped #306 (heart emblem).
    //    See the 'school' preset's note above re: designer pass on logo.svg.
    'nonprofit' => [
        'name'        => 'CharityMS',
        'tagline'     => 'Charity Management System',
        'publisher'   => 'MWBM Partners Ltd (t/a MWservices)',
        'assetFolder' => 'charityms',
        'displayLabel' => 'Charity / Non-profit',
    ],

    // 🏘️ Community group — starter artwork shipped #306 (interlocking-rings
    //    emblem). See the 'school' preset's note above re: designer pass.
    'community' => [
        'name'        => 'CommunityMS',
        'tagline'     => 'Community Management System',
        'publisher'   => 'MWBM Partners Ltd (t/a MWservices)',
        'assetFolder' => 'communityms',
        'displayLabel' => 'Community / Membership organisation',
    ],

    // 🏢 Small business — starter artwork shipped #306 (bar-chart emblem).
    //    See the 'school' preset's note above re: designer pass.
    'small-business' => [
        'name'        => 'BusinessMS',
        'tagline'     => 'Business Management System',
        'publisher'   => 'MWBM Partners Ltd (t/a MWservices)',
        'assetFolder' => 'businessms',
        'displayLabel' => 'Small business',
    ],
];
