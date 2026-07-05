<?php

return [
    'enabled' => (bool) env('AI_ENABLED', false),
    'provider' => env('AI_PROVIDER', 'openai'),

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'admin_api_key' => env('OPENAI_ADMIN_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-5.4-mini'),
        'timeout' => (int) env('OPENAI_TIMEOUT', 60),
        'endpoint' => env('OPENAI_ENDPOINT', 'https://api.openai.com/v1/responses'),
        'costs_endpoint' => env('OPENAI_COSTS_ENDPOINT', 'https://api.openai.com/v1/organization/costs'),
    ],

    'max_input_chars' => (int) env('AI_MAX_INPUT_CHARS', 12000),
    'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 2000),

    'image_search' => [
        'enabled' => (bool) env('IMAGE_SEARCH_ENABLED', false),
        'provider' => env('IMAGE_SEARCH_PROVIDER', 'manual_url'),
        'serpapi_endpoint' => env('SERPAPI_ENDPOINT', 'https://serpapi.com/search'),
        'safe_mode' => (bool) env('IMAGE_SEARCH_SAFE_MODE', true),
        'max_candidates' => (int) env('IMAGE_SEARCH_MAX_CANDIDATES', 5),
        'min_width' => (int) env('IMAGE_SEARCH_MIN_WIDTH', 600),
        'min_height' => (int) env('IMAGE_SEARCH_MIN_HEIGHT', 600),
        'preferred_format' => env('IMAGE_SEARCH_PREFERRED_FORMAT', 'webp'),
        'max_download_size_mb' => (int) env('IMAGE_SEARCH_MAX_DOWNLOAD_SIZE_MB', 5),
        'allow_manual_url_candidates' => (bool) env('IMAGE_SEARCH_ALLOW_MANUAL_URL_CANDIDATES', true),
    ],
];
