<?php

return [
    'default' => env('CACHE_DRIVER', 'file'),
    'stores' => [
        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],
        'database' => [
            'driver' => 'database',
            'connection' => null,
            'table' => 'cache',
            'lock_connection' => null,
            'lock_table' => null,
        ],
        'file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache/data'),
            'lock_path' => storage_path('framework/cache/data'),
        ],
    ],
    'prefix' => env('CACHE_PREFIX', 'inventory_management_system_cache'),
];
