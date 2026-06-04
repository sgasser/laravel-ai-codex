<?php

declare(strict_types=1);

return [
    'url' => env('CODEX_URL', 'https://chatgpt.com/backend-api/codex'),

    'model' => env('CODEX_MODEL', 'gpt-5.5'),

    'timeout' => (int) env('CODEX_TIMEOUT', 300),

    'access_token' => env('CODEX_ACCESS_TOKEN'),

    'auth_json_path' => env('CODEX_AUTH_JSON_PATH', '~/.codex/auth.json'),
];
