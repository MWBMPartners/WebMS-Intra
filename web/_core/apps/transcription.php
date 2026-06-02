<?php
// Path: _core/apps/transcription.php
declare(strict_types=1);
return [
    'slug'        => 'transcription',
    'name'        => 'Transcription',
    'description' => 'Auto-transcribe Recordings via Whisper / AssemblyAI / local whisper.cpp; full-text searchable across all transcripts.',
    'icon'        => 'fa-solid fa-closed-captioning',
    'color'       => '#0891b2',
    'category'    => 'media',
    'industries'  => ['church', 'training', 'events', 'podcasting', 'media'],
    'route'       => 'admin/transcription',
    'settingKey'  => 'transcription.enabled',
    'isCore'      => false,
    'version'     => '1.0.0',
];
