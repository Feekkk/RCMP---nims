<?php

declare(strict_types=1);

return [
    // Set this in your environment for production.
    // Example: APP_KEY="base64:...." or any long random string.
    'app_key' => getenv('APP_KEY') ?: 'dev-insecure-change-me',
    // Optional fixed base URL (e.g. https://nims.example.com). If empty, computed from request.
    'app_url' => getenv('APP_URL') ?: '',
];

