<?php

declare(strict_types=1);

return [
    'navigation_label' => 'Reuniones',
    'view' => [
        'heading' => 'Reunión',
    ],
    'time' => [
        'all_day' => 'Todo el día',
    ],
    'sections' => [
        'participants' => [
            'heading' => 'Participantes',
            'empty' => 'Sin participantes',
        ],
        'linked_records' => [
            'heading' => 'Registros vinculados',
            'empty' => [
                'heading' => 'No hay registros vinculados',
                'description' => 'Vincule personas, empresas u oportunidades a esta reunión.',
            ],
        ],
        'description' => [
            'heading' => 'Descripción',
        ],
    ],
    'attendees' => [
        'host' => 'Anfitrión',
        'guest' => 'Invitado',
        'show_more' => 'Mostrar más',
        'show_less' => 'Mostrar menos',
    ],
    'actions' => [
        'link_records' => [
            'label' => 'Vincular registros',
        ],
        'unlink_record' => [
            'label' => 'Desvincular :name',
            'heading' => '¿Desvincular :name de esta reunión?',
            'submit' => 'Desvincular',
            'description' => 'La reunión permanece en el calendario. Ya no aparece en este registro.',
        ],
        'join' => [
            'label' => 'Unirse',
        ],
        'rsvp' => [
            'label' => 'Confirmar asistencia',
            'accepted' => [
                'label' => 'Aceptar',
            ],
            'tentative' => [
                'label' => 'Tal vez',
            ],
            'declined' => [
                'label' => 'Rechazar',
                'heading' => '¿Rechazar esta reunión?',
                'description' => 'Su calendario quedará marcado como rechazado. La reunión permanece en el calendario para los demás invitados.',
            ],
        ],
    ],
    'linked_record_types' => [
        'people' => 'Persona',
        'companies' => 'Empresa',
        'opportunities' => 'Oportunidad',
    ],
    'fields' => [
        'record_type' => [
            'label' => 'Tipo',
        ],
        'record' => [
            'label' => 'Registro',
        ],
        'organizer' => [
            'label' => 'Organizador',
        ],
        'email_address' => [
            'label' => 'Correo electrónico',
        ],
        'html_link' => [
            'label' => 'Abrir en el calendario',
        ],
    ],

    'columns' => [
        'starts_at' => [
            'label' => 'Hora',
        ],
        'organizer_name' => [
            'label' => 'Organizador',
        ],
        'attendees_count' => [
            'label' => 'Asistentes',
        ],
        'people_count' => [
            'label' => 'Personas',
        ],
        'companies_count' => [
            'label' => 'Empresas',
        ],
        'opportunities_count' => [
            'label' => 'Oportunidades',
        ],
        'response_status' => [
            'label' => 'Mi respuesta',
        ],
    ],

    'filters' => [
        'response_status' => [
            'label' => 'Mi respuesta',
        ],
    ],

    'notifications' => [
        'rsvp' => [
            'accepted' => [
                'title' => 'Invitación aceptada. Su calendario está actualizado.',
            ],
            'tentative' => [
                'title' => 'Marcada como tal vez. Su calendario está actualizado.',
            ],
            'declined' => [
                'title' => 'Invitación rechazada. Su calendario está actualizado.',
            ],
            'failed' => [
                'title' => 'No se pudo actualizar su respuesta.',
                'body' => 'Vuelva a conectar el buzón e inténtelo de nuevo.',
            ],
            'not_synced' => [
                'title' => 'No se pudo actualizar su respuesta.',
                'body' => 'Esta reunión aún no aparece en su calendario. Inténtelo de nuevo en un momento.',
            ],
        ],
    ],

    'empty_state' => [
        'heading' => 'Aún no hay reuniones',
        'description' => 'Las reuniones de su calendario sincronizado aparecen aquí.',
    ],
];
