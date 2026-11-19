<?php

declare(strict_types=1);

return [
    'title' => 'Firmas',
    'heading' => 'Firmas de correo',
    'default_badge' => 'Predeterminada',
    'empty' => 'Aún no hay firmas. Seleccione :action para agregar una.',
    'actions' => [
        'create' => 'Nueva firma',
        'edit' => 'Editar',
        'delete' => 'Eliminar',
    ],
    'fields' => [
        'connected_account' => 'Buzón',
        'name' => 'Nombre de la firma',
        'content' => 'Contenido de la firma',
        'is_default' => 'Establecer como predeterminada para este buzón',
    ],
    'notifications' => [
        'created' => 'Firma creada.',
        'updated' => 'Firma actualizada.',
        'deleted' => 'Firma eliminada.',
    ],
];
