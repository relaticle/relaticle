<?php

declare(strict_types=1);

return [
    'finished' => [
        'title' => [
            'completed' => 'Importación de :entity completada',
            'completed_with_failures' => 'Importación de :entity completada con filas fallidas',
            'failed' => 'Falló la importación de :entity',
        ],
        'counts' => [
            'created' => ':count creados',
            'updated' => ':count actualizados',
            'skipped' => ':count omitidos',
            'failed' => ':count fallidos',
        ],
        'actions' => [
            'view' => 'Ver importaciones',
            'download' => 'Descargar filas fallidas',
        ],
    ],
];
