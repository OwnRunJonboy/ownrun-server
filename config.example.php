<?php

declare(strict_types=1);

return [
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'ownrun',
        'user' => 'ownrun',
        'pass' => 'change-me',
        'charset' => 'utf8mb4',
    ],
    'jwt_secret' => 'change-me-to-a-long-random-string',
    'access_jwt_ttl' => 7 * 24 * 3600,       // 7 days
    'refresh_ttl' => 30 * 24 * 3600,         // 30 days
    'remember_me_ttl' => 365 * 24 * 3600,    // 1 year
    'job_lease_timeout' => 60 * 60,          // 60 minutes
    'runner_online_window' => 60,            // seconds
    'max_request_payload_bytes' => 65536,
    'max_result_payload_bytes' => 524288,    // 512 KiB (agent traces)
    'agent_profiles' => ['workspace', 'web', 'workspace_web'],
    'agent_max_tool_iterations' => 20,
    'agent_default_tool_iterations' => 8,
    'agent_tool_ids' => [
        'now',
        'list_dir',
        'read_file',
        'write_file',
        'http_fetch',
        'web_search',
        'calendar_search',
        'calendar_get',
        'calendar_add',
        'calendar_edit',
        'calendar_delete',
        'journal_search',
        'journal_get',
        'journal_add',
        'journal_edit',
        'journal_delete',
        'task_search',
        'task_get',
        'task_add',
        'task_edit',
        'task_delete',
        'kb_list',
        'kb_search',
        'kb_get',
    ],
    'kb_storage_path' => __DIR__ . '/storage/kb',
    'kb_embed_model' => 'nomic-embed-text',
    'max_kb_upload_bytes' => 32 * 1024 * 1024,
    'max_kb_bases' => 50,
    'max_kb_documents_per_base' => 200,
];
