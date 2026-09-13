<?php

declare(strict_types=1);

return [
    'create_team' => [
        'label' => 'Crear espacio de trabajo',
        'steps' => [
            'workspace' => 'Espacio de trabajo',
            'attribution' => 'Cómo nos conociste',
            'use_case' => 'Uso',
            'invite' => 'Invitar',
        ],
        'actions' => [
            'continue' => 'Continuar',
            'send_invites' => 'Enviar invitaciones',
            'get_started' => 'Empezar',
            'copy_invite_link' => 'Copiar enlace de invitación',
            'add_more' => 'Añadir más',
            'cancel' => 'Cancelar',
            'skip' => 'Omitir',
            'skip_for_now' => 'Omitir por ahora',
            'back' => 'Atrás',
            'go_to_workspace' => 'Ir al espacio de trabajo',
        ],

        'step_indicator' => 'Paso :current de :total',

        'headings' => [
            'workspace' => 'Crea tu espacio de trabajo',
            'attribution' => '¿Cómo nos conociste?',
            'attribution_description' => 'Indica abajo dónde conociste Crabdev CRM. Este paso es opcional.',
            'use_case' => 'Ayúdanos a personalizar tu espacio de trabajo',
            'use_case_description' => 'Crabdev CRM te permite montar exactamente el CRM que necesitas, por complejo que sea.',
            'use_case_hint' => 'Cuéntanos para qué lo vas a usar y empieza con una plantilla, o parte de cero.',
            'invite' => 'Colabora con tu equipo',
            'invite_description' => 'Cuanto más lo use tu equipo, más partido le sacaréis a Crabdev CRM.',
            'invite_subheading' => 'Invita a tu equipo a colaborar',
        ],
        'form' => [
            'your_name' => [
                'label' => 'Tu nombre',
                'placeholder' => 'Ana García',
            ],
            'workspace_name' => [
                'label' => 'Nombre del espacio de trabajo',
                'placeholder' => 'Crabdev',
            ],
            'workspace_handle' => [
                'label' => 'Identificador del espacio de trabajo',
                'helper_text' => 'Solo se permiten minúsculas, números y guiones.',
            ],
            'use_case_label' => '¿Para qué vas a usar Crabdev CRM?',
            'use_case_context_label' => 'Cuéntanos más sobre tu caso de uso.',
            'use_case_validation_attribute' => 'caso de uso',
            'use_case_context_validation_attribute' => 'detalles del caso de uso',
            'invite_email_placeholder' => 'nombre@empresa.com',
            'invite_role_member' => 'Miembro',
            'invite_role_admin' => 'Administrador',
            'invite_email_label' => 'Correo',
            'invite_role_label' => 'Rol',
        ],
        'notifications' => [
            'workspace_created' => [
                'title' => 'Espacio de trabajo creado',
                'body' => 'Tu espacio de trabajo ":name" ya está listo.',
            ],
            'invite_link_copied' => [
                'title' => 'Enlace de invitación copiado',
                'body' => 'Comparte este enlace con tu equipo. Cualquiera que tenga el enlace puede unirse a este espacio de trabajo.',
            ],
            'complete_previous_steps' => [
                'title' => 'Completa primero los pasos anteriores',
                'body' => 'Rellena los datos del espacio de trabajo y el caso de uso antes de generar un enlace de invitación.',
            ],
            'workspace_limit_reached' => [
                'title' => 'Has alcanzado el límite de espacios de trabajo',
                'body' => 'Ya eres propietario del máximo de espacios de trabajo. Elimina uno o pide que te inviten a uno existente.',
            ],

            'some_invites_failed' => [
                'title' => 'Algunas invitaciones no se han podido enviar',
                'invalid_email' => 'No es una dirección de correo válida.',
                'generic' => 'La validación ha fallado.',
                'send_failed' => 'La invitación se ha guardado, pero el correo no se ha podido entregar. Reenvíala desde los ajustes del espacio de trabajo.',
                'send_skipped' => 'No se ha enviado porque el servicio de correo no está disponible. Invita a esta persona desde los ajustes del espacio de trabajo cuando vuelva a funcionar.',
            ],
        ],
    ],
];
