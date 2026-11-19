<?php

declare(strict_types=1);

return [
    'columns' => [
        'type' => 'Tipo',
        'file' => 'Archivo',
        'total' => 'Total',
        'created' => 'Creados',
        'updated' => 'Actualizados',
        'skipped' => 'Omitidos',
        'failed' => 'Fallidos',
        'user' => 'Miembro',
        'date' => 'Fecha',
    ],
    'actions' => [
        'failed_rows' => 'Filas fallidas',
        'mark_failed' => 'Marcar como fallida',
        'mark_failed_description' => 'Esta importación dejó de responder. Al marcarla como fallida, podrá iniciar una nueva con el mismo archivo.',
    ],
    'title' => 'Historial de importaciones',
];
