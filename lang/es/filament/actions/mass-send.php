<?php

declare(strict_types=1);

return [
    'label' => 'Enviar correo',

    'notifications' => [
        'no_recipients' => [
            'title' => 'No hay destinatarios válidos',
            'body' => 'Ninguna de las personas seleccionadas tiene una dirección de correo electrónico.',
        ],
        'skipped' => [
            'title' => 'Se omitieron algunos destinatarios',
            'body' => '{1}1 registro seleccionado no tiene dirección de correo electrónico y no se agregó.|[2,*]:count registros seleccionados no tienen dirección de correo electrónico y no se agregaron.',
        ],
    ],
];
