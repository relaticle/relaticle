<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| CRM del equipo
|--------------------------------------------------------------------------
|
| Configuración propia del fork (no viene de upstream). La aplica a un
| workspace `php artisan crm:configurar-pipeline {team}`, que se puede
| ejecutar tantas veces como haga falta.
|
*/

return [

    'currency' => 'EUR',

    'opportunity' => [

        // Etapa => color, en el orden de las columnas del tablero.
        'stages' => [
            'Oportunidad' => '#a5b4fc',
            'Contactado' => '#0d9488',
            'En trámite' => '#f59e0b',
            'Contactar más tarde' => '#7c3aed',
            'Cerrada ganada' => '#059669',
            'Cerrada fallida' => '#6b7280',
        ],

        // Etapas de upstream que pasan a ser nuestras: las oportunidades que ya
        // estén en ellas se conservan. El resto se borran si nadie las usa.
        'stage_renames' => [
            'Prospecting' => 'Oportunidad',
            'Qualification' => 'Contactado',
            'Proposal/Price Quote' => 'En trámite',
            'Closed Won' => 'Cerrada ganada',
            'Closed Lost' => 'Cerrada fallida',
        ],

        // Nombres en español de los campos que ya crea Relaticle.
        'field_names' => [
            'amount' => 'Valor',
            'close_date' => 'Fecha de cierre estimada',
            'stage' => 'Etapa',
        ],

        'sources' => ['Web', 'Referido', 'Frío', 'RRSS'],

        // Nombres del equipo para el campo «Responsable».
        'owners' => ['Guille', 'Jaime'],

    ],

    'company' => [

        'field_names' => [
            'domains' => 'Web',
        ],

        'sectors' => [
            'Hostelería y restauración',
            'Comercio y retail',
            'Construcción e inmobiliaria',
            'Industria',
            'Salud y bienestar',
            'Educación y formación',
            'Servicios profesionales',
            'Tecnología',
            'Transporte y logística',
            'Turismo',
            'Otro',
        ],

    ],

];
