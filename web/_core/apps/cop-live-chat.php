<?php
// Path: _core/apps/cop-live-chat.php
/**
 * -----------------------------------------------------------------------------
 * AppRegistry entry — COP Online Engagement: live chat (#313 Phase 1)
 * -----------------------------------------------------------------------------
 * Gated by chat.enabled (default 'false'). Surface is admin-side only in
 * Phase 1; the public viewer chat UI lands in Phase 2 alongside the /live
 * embed page refresh.
 *
 * @link https://github.com/MWBMPartners/WebMS-Intra/issues/313
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

return [
    'slug'        => 'cop-live-chat',
    'label'       => 'Live Chat',
    'description' => 'Moderate viewer chat messages on livestream events',
    'route'       => 'admin/live/chat',
    'icon'        => 'fa-solid fa-comments',
    'category'    => 'cop',
    'settingsKey' => 'chat.enabled',
    'visibleForIndustry' => ['', 'church'],
];
