<?php
return [
    // Run php webman saipackage:setup first, then enable and restart all workers.
    'enabled' => filter_var(env('SAIPACKAGE_MANAGEMENT_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'settings_key' => env('SAIPACKAGE_SETTINGS_KEY', ''),
    'table_prefix' => '',
    'probes' => [],
    'settings_migrations' => [],
    // Return true only once plugin-owned jobs have drained. Required before managed uninstall.
    'drain' => null,
    'health_interval' => 60,
    'updates' => [
        'automatic' => true,
        'interval' => 86400,
        'retry_interval' => 900,
        'allow_prerelease' => false,
        // Trusted sources configured by the host, never by request input.
        'sources' => [],
        // Legacy plugin mapping: 'plugin_id' => 'source_name'.
        'plugins' => [],
    ],
];
