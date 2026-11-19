<?php

declare(strict_types=1);

return [
    'finished' => [
        'title' => [
            'completed' => 'Importación completada: :entity',
            'completed_with_failures' => 'Importación completada con filas fallidas: :entity',
            'failed' => 'Falló la importación: :entity',
        ],
        'counts' => [
            'created' => 'Creados: :count',
            'updated' => 'Actualizados: :count',
            'skipped' => 'Omitidos: :count',
            'failed' => 'Fallidos: :count',
        ],
        'actions' => [
            'view' => 'Ver importaciones',
            'download' => 'Descargar filas fallidas',
        ],
    ],
];
