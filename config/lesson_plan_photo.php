<?php

return [
    // The pilot capability and private allowlist still apply when this is true.
    'enabled' => (bool) env('LESSON_PLAN_PHOTO_ENABLED', true),
    'model' => env('LESSON_PLAN_PHOTO_MODEL'),
    // Four notebook pages, bounded independently of provider limits.
    'max_image_kb' => 3072,
    'max_dimension' => 2000,
    'reads_per_hour' => 20,
    'max_tokens' => 8192,
    'timeout' => 60,
];
