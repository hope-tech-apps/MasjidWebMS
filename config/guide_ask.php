<?php

return [
    'enabled' => (bool) env('GUIDE_ASK_ENABLED', false),
    'model' => env('GUIDE_ASK_MODEL', 'claude-haiku-4-5-20251001'),
    'max_tokens' => (int) env('GUIDE_ASK_MAX_TOKENS', 512),
    'timeout' => (float) env('GUIDE_ASK_TIMEOUT', 20),
    'person_per_minute' => (int) env('GUIDE_ASK_PERSON_PER_MINUTE', 3),
    'organisation_per_day' => (int) env('GUIDE_ASK_ORGANISATION_PER_DAY', 100),
    'platform_per_month' => (int) env('GUIDE_ASK_PLATFORM_PER_MONTH', 10000),
    'min_chars' => (int) env('GUIDE_ASK_MIN_CHARS', 3),
    'max_chars' => (int) env('GUIDE_ASK_MAX_CHARS', 500),
    'retention_days' => (int) env('GUIDE_ASK_RETENTION_DAYS', 180),
    'readers' => [
        'office' => 'an office administrator about the Manara admin screens and, where the school guide is included, the screens for classes, students, teachers and enrolment',
        'teacher' => 'a teacher about their own Manara teacher screens',
        'lunch' => 'a kitchen volunteer about the Manara Jummah Lunch board, the only Manara screen they have',
    ],
    'contacts' => [
        'office' => env('GUIDE_ASK_OFFICE_CONTACT', 'your Manara support contact'),
        'teacher' => 'your school office',
        'lunch' => 'the office that gave you access',
    ],
];
