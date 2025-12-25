<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Maximum Rows Per Import
    |--------------------------------------------------------------------------
    |
    | The maximum number of rows allowed per import file. Files exceeding
    | this limit will be rejected with an error message.
    |
    */
    'max_rows_per_file' => 10000,

    /*
    |--------------------------------------------------------------------------
    | Preview Settings
    |--------------------------------------------------------------------------
    |
    | Configure how import previews are generated.
    |
    */
    'preview' => [
        // Maximum rows to process for preview generation
        'sample_size' => 1000,

        // Maximum rows to display in the UI
        'display_limit' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Row Counting Settings
    |--------------------------------------------------------------------------
    |
    | Configure how row counts are calculated for CSV files.
    |
    */
    'row_count' => [
        // Files smaller than this (in bytes) use exact counting
        // Larger files use estimation for performance
        'exact_threshold_bytes' => 1_048_576, // 1MB

        // Number of rows to sample for estimation
        'sample_size' => 100,

        // Bytes to read for row size estimation
        'sample_bytes' => 8192,
    ],
];
