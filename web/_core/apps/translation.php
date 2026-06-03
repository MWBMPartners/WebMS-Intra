<?php
// Path: _core/apps/translation.php
declare(strict_types=1);
return [
    'slug'        => 'translation',
    'name'        => 'Translation',
    'description' => 'Auto-translate user-generated content (prayer requests, announcements, …) via Anthropic / OpenAI / Google / DeepL / LibreTranslate. Cached after first translate.',
    'icon'        => 'fa-solid fa-language',
    'color'       => '#9333ea',
    'category'    => 'communications',
    'industries'  => ['church', 'community', 'school', 'nonprofit'],
    'route'       => 'admin/translation',
    'settingKey'  => 'translation.enabled',
    'isCore'      => false,
    'version'     => '1.0.0',
];
