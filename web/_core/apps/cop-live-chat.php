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
    // 🩹 Contract keys are 'name' / 'settingKey' (see AppRegistry::all()'s
    //    doc-block shape + any sibling file under _core/apps/*.php) — this
    //    file was the only one spelling them 'label' / 'settingsKey'.
    //    AppRegistry::all() merges unknown-shaped metadata against its
    //    defaults array, so the typo'd 'settingsKey' was silently ignored
    //    and 'settingKey' defaulted to "{slug}.enabled" =
    //    'cop-live-chat.enabled' — a setting row that's never seeded.
    //    isEnabled() therefore always read false, permanently disabling
    //    Live Chat regardless of the real 'chat.enabled' setting (#313)
    //    that _apps/livechat/api/*.php actually gates on.
    'name'        => 'Live Chat',
    'description' => 'Moderate viewer chat messages on livestream events',
    'route'       => 'admin/live/chat',
    'icon'        => 'fa-solid fa-comments',
    'category'    => 'cop',
    'settingKey'  => 'chat.enabled',
    // 🩹 Same typo class as above — the contract key is 'industries', not
    //    'visibleForIndustry' (see AppRegistry::visibleForIndustry() +
    //    every sibling _core/apps/*.php file). Was silently ignored and
    //    'industries' defaulted to [], which is harmless here (empty list
    //    = shown for every org industry) but didn't express the original
    //    intent of restricting Live Chat to generic/church orgs. Dropping
    //    the '' entry: visibleForIndustry() already short-circuits to
    //    "show for all apps" whenever the org's own industry is unset/
    //    generic, so ['church'] alone reproduces the intended ['', 'church']
    //    behaviour exactly.
    'industries'  => ['church'],
];
