<?php

declare(strict_types=1);

return [
    'max_rows' => 10_000,
    'max_file_size' => 10 * 1024 * 1024, // 10MB
    'storage_path' => storage_path('app/imports'),
    'chunk_size' => 500,
];
