<?php

/*
|--------------------------------------------------------------------------
| Capability groups — how the SuperAdmin's switch panel is laid out
|--------------------------------------------------------------------------
|
| Every entry in config/capabilities.php names one of these keys as its
| `group`. The order here is the order of the cards on the panel
| (GET /api/admin/masjids/{id}/capabilities). A separate file rather than a
| key inside capabilities.php, because every reader of that file loops its
| keys as capabilities.
|
*/

return [
    'content' => 'Website & app content',
    'prayer' => 'Prayer & worship',
    'communication' => 'Communication',
    'registration_money' => 'Members, registration & money',
    'school' => 'School',
    'tools' => 'Tools',
];
