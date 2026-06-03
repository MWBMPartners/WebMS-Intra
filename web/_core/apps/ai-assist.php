<?php
// Path: _core/apps/ai-assist.php
declare(strict_types=1);
return [
    'slug'        => 'ai-assist',
    'name'        => 'AI Assist',
    'description' => 'LLM-assisted drafting for announcements, prayer requests, newsletter. Anthropic / OpenAI / local ollama.',
    'icon'        => 'fa-solid fa-wand-magic-sparkles',
    'color'       => '#a855f7',
    'category'    => 'communications',
    'industries'  => ['church', 'nonprofit', 'school', 'community', 'small-business'],
    'route'       => 'admin/ai-assist',
    'settingKey'  => 'ai_assist.enabled',
    'isCore'      => false,
    'version'     => '1.0.0',
];
