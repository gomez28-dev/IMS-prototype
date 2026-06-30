<?php

return [
    'exports' => [
        'chunk_size' => 1000,
        'pre_calculate_formulas' => false,
        'strict_null_comparison' => false,
    ],
    'imports' => [
        'read_only' => true,
        'ignore_empty' => false,
        'heading_row' => [
            'formatter' => 'slug',
        ],
    ],
    'extension_detector' => [
        'xlsx'     => 'Xlsx',
        'xlsm'     => 'Xlsx',
        'xltx'     => 'Xlsx',
        'xltm'     => 'Xlsx',
        'xls'      => 'Xls',
        'xlt'      => 'Xls',
        'ods'      => 'Ods',
        'ots'      => 'Ods',
        'slk'      => 'Slk',
        'xml'      => 'Xml',
        'gnumeric' => 'Gnumeric',
        'htm'      => 'Html',
        'html'     => 'Html',
        'csv'      => 'Csv',
        'tsv'      => 'Csv',
        'pdf'      => 'Dompdf',
    ],
    'value_binder' => [
        'default' => Maatwebsite\Excel\DefaultValueBinder::class,
    ],
    'transactions' => [
        'handler' => 'db',
        'db'      => [
            'connection' => null,
        ],
    ],
    'temporary_files' => [
        'local_path'          => storage_path('framework/laravel-excel'),
        'remote_disk'         => null,
        'remote_prefix'       => null,
        'force_resync_local'  => false,
    ],
];
