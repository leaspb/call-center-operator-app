<?php

return [
    'url' => env('AI_API_URL', 'https://api.openai.com/v1'),
    'key' => env('AI_API_KEY', ''),
    'model' => env('AI_MODEL', 'openai/gpt-4o-mini'),
    'timeout' => (int) env('AI_TIMEOUT', 30),
];
