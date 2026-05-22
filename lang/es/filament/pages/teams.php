<?php

declare(strict_types=1);

return [
    'create_team' => [
        'label' => 'Crear equipo',
        'steps' => [
            'workspace' => 'Espacio de trabajo',
            'attribution' => 'Atribución',
            'use_case' => 'Caso de uso',
            'invite' => 'Invitar',
        ],
        'actions' => [
            'continue' => 'Continuar',
            'send_invites' => 'Enviar invitaciones',
            'get_started' => 'Comenzar',
            'copy_invite_link' => 'Copiar enlace de invitación',
            'add_more' => 'Añadir más',
        ],
        'form' => [
            'company_name' => [
                'label' => 'Nombre de la empresa',
                'placeholder' => 'Acme Corp',
            ],
            'workspace_handle' => [
                'label' => 'Identificador del espacio de trabajo',
                'helper_text' => 'Solo se permiten letras minúsculas, números y guiones.',
            ],
            'use_case_label' => '¿Para qué va a utilizar Relaticle?',
            'use_case_context_label' => 'Por favor, cuéntenos más sobre su caso de uso.',
            'invite_email_placeholder' => 'colega@empresa.com',
            'invite_role_member' => 'Miembro',
            'invite_role_admin' => 'Administrador',
            'invite_table_column_email' => 'Correo electrónico',
            'invite_table_column_role' => 'Rol',
        ],
        'notifications' => [
            'workspace_created' => [
                'title' => 'Espacio de trabajo creado',
                'body' => 'Su espacio de trabajo ":name" está listo.',
            ],
            'invite_link_copied' => [
                'title' => 'Enlace de invitación copiado',
                'body' => 'Comparta este enlace con sus compañeros. Cualquier persona con el enlace puede unirse a este equipo.',
            ],
            'complete_previous_steps' => [
                'title' => 'Complete los pasos anteriores primero',
                'body' => 'Rellene los detalles del espacio de trabajo y el caso de uso antes de generar un enlace de invitación.',
            ],
            'some_invites_failed' => [
                'title' => 'No se pudieron enviar algunas invitaciones',
            ],
        ],
    ],
];
