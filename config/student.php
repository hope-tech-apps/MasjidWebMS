<?php

return [
    'session' => ['idle_minutes'=>15, 'absolute_minutes'=>120],
    'login' => ['per_username'=>5, 'per_ip'=>50, 'window_seconds'=>900, 'retention_days'=>90],
    'password_min_length' => 10,
];
