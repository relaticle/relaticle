<?php

declare(strict_types=1);

return [
    'activation' => [
        'heading' => 'Primeros pasos',
        'progress' => ':completed/:total pasos completados',
        'dismiss' => 'Descartar',
        'collapse' => 'Contraer lista de verificación',
        'more_actions' => 'Más acciones',
        'encouragement' => '¡Vamos!',
        'invite_members' => 'Invitar a su equipo',
        'sample_data' => 'Este espacio de trabajo viene con registros de ejemplo para que pueda explorar. Todo lo que agregue se mostrará junto a ellos.',
        'remove_sample_data' => 'Quitar datos de ejemplo',
        'remove_sample_data_confirm' => '¿Eliminar todos los registros de ejemplo? Sus propios registros se conservan.',
        'steps' => [
            'first_record' => [
                'label' => 'Agregue a su primera persona',
                'description' => 'Ponga una persona real en el CRM y el resto vendrá solo',
            ],
            'sync_email' => [
                'label' => 'Sincronice su buzón',
                'description' => 'Conecte su buzón para traer conversaciones y reuniones al CRM',
                'syncing' => 'Sincronizando correo electrónico…',
                'syncing_percent' => 'Sincronizando correo electrónico (:percent%)',
            ],
            'import' => [
                'label' => 'Importe sus registros existentes',
                'description' => 'Traiga un CSV de su hoja de cálculo o de su CRM anterior',
            ],
            'invite' => [
                'label' => 'Invite a un miembro del equipo',
                'description' => 'Los pipelines compartidos rinden más que los privados',
            ],
            'ask_rela' => [
                'label' => 'Pregunte a Rela por su pipeline',
                'label_empty' => 'Pregunte a Rela cómo empezar',
                'description' => 'Su asistente puede leer, redactar y actualizar registros por usted',
                'prompt' => '¿Qué hay en mi pipeline ahora mismo?',
                'prompt_empty' => '¿Cómo puede ayudarme a configurar este espacio de trabajo?',
            ],
        ],
    ],
    'meetings' => [
        'heading' => 'Reuniones',
        'empty' => [
            'title' => 'Sin reuniones',
            'description' => 'Elija otra fecha para planificar con anticipación o revisar reuniones pasadas.',
            'next_with_meetings' => 'Siguiente día con reuniones',
        ],
        'disconnected' => [
            'title' => 'Convierta reuniones en oportunidades',
            'description' => 'Sincronice su calendario para obtener el contexto de cada reunión al instante',
        ],
        'date' => [
            'today' => 'Hoy,',
            'tomorrow' => 'Mañana,',
            'yesterday' => 'Ayer,',
            'other' => ':weekday,',
        ],
        'previous_day' => 'Día anterior',
        'next_day' => 'Día siguiente',
        'pick_date' => 'Cambiar fecha, actualmente :date',
        'more_actions' => 'Más acciones de reunión',
        'go_to_today' => 'Ir a hoy',
        'calendar_settings' => 'Configuración del calendario',
        'open' => 'Abrir reunión',
        'open_named' => 'Abrir :title',
        'expand_participants' => 'Mostrar participantes de :title',
        'collapse_participants' => 'Ocultar participantes de :title',
        'all_day' => 'Todo el día',
        'more_participants' => '+:count',
        'happening_now' => 'Ahora',
        'time_range' => ':start a :end',
        'load_more' => 'Cargar más',
        'syncing' => [
            'title' => 'Sincronizando',
            'title_with_percent' => 'Sincronizando (:percent%)',
            'description_initial' => 'Estamos procesando sus correos electrónicos y eventos del calendario…',
            'description_update' => 'Buscando correos electrónicos nuevos y cambios en el calendario…',
            'emails_processed' => '{1}:count correo electrónico sincronizado|[2,*]:count correos electrónicos sincronizados',
            'meetings_processed' => '{1}:count reunión sincronizada|[2,*]:count reuniones sincronizadas',
            'emails_updated' => '{1}:count correo electrónico nuevo|[2,*]:count correos electrónicos nuevos',
            'meetings_updated' => '{1}:count reunión actualizada|[2,*]:count reuniones actualizadas',
        ],
    ],
    'tasks' => [
        'heading' => 'Tareas',
        'view_all' => 'Ver todas',
        'create_action_label' => 'Nueva tarea',
        'complete' => 'Marcar como completada',
        'empty' => [
            'title' => 'Mantenga el trabajo al día',
            'description' => 'Cree tareas para usted o su equipo para dar seguimiento a los próximos pasos',
        ],
    ],
];
