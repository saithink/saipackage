<?php
// Process files are evaluated while Config is still loading; read the environment directly.
return filter_var(env('SAIPACKAGE_MANAGEMENT_ENABLED', false), FILTER_VALIDATE_BOOLEAN) ? [
    'management' => [
        'handler' => \plugin\saipackage\app\process\ManagementWorker::class,
        'count' => 1,
        'reloadable' => true,
    ],
] : [];
