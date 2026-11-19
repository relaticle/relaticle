<?php

declare(strict_types=1);

return [
    'start_over' => [
        'label' => 'Empezar de nuevo',
        'heading' => '¿Iniciar una nueva importación?',
        'description' => '¿Está seguro de que desea iniciar una nueva importación? Se perderá su progreso actual.',
    ],
    'cancel' => 'Cancelar',
    'continue' => [
        'label' => 'Continuar',
        'heading' => 'Evite crear registros duplicados',
        'submit' => 'Continuar sin asignar',
        'back' => 'Volver',
        'or' => ' o ',
        'warning' => "Para evitar crear registros duplicados, asegúrese de asignar una columna que identifique de forma única cada registro.\n\nPara :entity, asigne una columna :fields.",
    ],
    'start_import' => [
        'heading' => 'Iniciar importación',
        'description' => '¿Está seguro de que desea iniciar esta importación?',
        'submit' => 'Iniciar importación',
    ],
    'download_failed_rows' => 'Descargar filas fallidas',
];
