<?php

return [
    // Conservative shared ceiling for this new mail stream, not a provider allowance. Queue releases expire at school midnight.
    'absence_emails_per_minute' => (int) env('ATTENDANCE_ABSENCE_EMAILS_PER_MINUTE', 30),
];
