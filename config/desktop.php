<?php

return [
    'default_max_devices' => (int) env('DESKTOP_DEFAULT_MAX_DEVICES', 1),
    'license_offline_grace_hours' => (int) env('DESKTOP_LICENSE_GRACE_HOURS', 48),
];
