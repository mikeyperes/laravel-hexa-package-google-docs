<?php

return [
    'version' => '1.1.7',
    'enabled' => env('HWS_GOOGLE_DOCS_ENABLED', true),
    'default_format' => env('HWS_GOOGLE_DOCS_DEFAULT_FORMAT', 'txt'),
    'timeout_seconds' => (int) env('HWS_GOOGLE_DOCS_TIMEOUT_SECONDS', 15),
    'user_agent' => env('HWS_GOOGLE_DOCS_USER_AGENT', 'HexaGoogleDocs/1.1 (+https://code.hexawebsystems.com)'),
    'max_preview_chars' => (int) env('HWS_GOOGLE_DOCS_MAX_PREVIEW_CHARS', 1600),
    'auth_mode' => env('HWS_GOOGLE_DOCS_AUTH_MODE', 'public_read'),
    'owner_email' => env('HWS_GOOGLE_DOCS_OWNER_EMAIL', 'contact@michaelperes.com'),
    'default_folder_id' => env('HWS_GOOGLE_DOCS_DEFAULT_FOLDER_ID', ''),
];
