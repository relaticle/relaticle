<?php

declare(strict_types=1);

return [
    'form' => [
        'team_name' => [
            'label' => 'Nombre del equipo',
        ],
        'team_slug' => [
            'label' => 'Identificador del equipo',
            'helper_text' => 'Solo letras minúsculas, números y guiones. Aparece en la URL de su equipo.',
        ],
        'email' => [
            'label' => 'Correo electrónico',
        ],
    ],

    'sections' => [
        'update_team_name' => [
            'title' => 'Nombre del equipo',
            'description' => 'El nombre del equipo y la información del propietario.',
        ],
        'add_team_member' => [
            'title' => 'Añadir miembro al equipo',
            'description' => 'Añada un nuevo miembro a su equipo para que pueda colaborar con usted.',
            'notice' => 'Por favor, indique la dirección de correo electrónico de la persona que desea añadir a este equipo.',
        ],
        'team_members' => [
            'title' => 'Miembros del equipo',
            'description' => 'Todas las personas que forman parte de este equipo.',
        ],
        'pending_team_invitations' => [
            'title' => 'Invitaciones pendientes',
            'description' => 'Estas personas han sido invitadas a su equipo y se les ha enviado un correo de invitación. Pueden unirse al equipo aceptando la invitación.',
        ],
        'delete_team' => [
            'title' => 'Eliminar equipo',
            'description' => 'Programe este equipo para su eliminación.',
            'notice' => 'Eliminar este equipo lo programará para su eliminación permanente tras un periodo de gracia de 30 días. Puede cancelar la eliminación en cualquier momento antes de ese plazo. Transcurrido el periodo de gracia, todos los recursos y datos serán eliminados permanentemente.',
            'scheduled_notice' => 'Este equipo está programado para su eliminación el :date.',
        ],
    ],

    'actions' => [
        'save' => 'Guardar',
        'add_team_member' => 'Añadir',
        'update_team_role' => 'Gestionar rol',
        'remove_team_member' => 'Eliminar',
        'leave_team' => 'Abandonar',
        'resend_team_invitation' => 'Reenviar',
        'copy_invite_link' => 'Copiar enlace',
        'revoke_team_invitation' => 'Revocar',
        'delete_team' => 'Eliminar equipo',
        'cancel_deletion' => 'Cancelar eliminación',
    ],

    'notifications' => [
        'save' => [
            'success' => 'Guardado.',
        ],
        'team_invitation_sent' => [
            'success' => 'Invitación al equipo enviada.',
        ],
        'team_invitation_revoked' => [
            'success' => 'Invitación al equipo revocada.',
        ],
        'invite_link_copied' => [
            'success' => 'Enlace de invitación copiado al portapapeles.',
        ],
        'team_member_removed' => [
            'success' => 'Ha eliminado a este miembro del equipo.',
        ],
        'leave_team' => [
            'success' => 'Ha abandonado el equipo.',
        ],
        'team_deleted' => [
            'success' => '¡Equipo eliminado!',
        ],
        'permission_denied' => [
            'cannot_update_team_member' => 'No tiene permiso para actualizar a este miembro del equipo.',
            'cannot_leave_team' => 'No puede abandonar un equipo que usted creó.',
            'cannot_remove_team_member' => 'No tiene permiso para eliminar a este miembro del equipo.',
            'cannot_delete_team' => 'No tiene permiso para eliminar este equipo.',
            'cannot_cancel_team_deletion' => 'No tiene permiso para cancelar la eliminación de este equipo.',
        ],
    ],

    'validation' => [
        'email_already_invited' => 'Este usuario ya ha sido invitado al equipo.',
    ],

    'modals' => [
        'leave_team' => [
            'notice' => '¿Está seguro de que desea abandonar este equipo?',
        ],
        'delete_team' => [
            'notice' => 'Esto programará el equipo para su eliminación. Tendrá 30 días para cancelarlo antes de que todos los datos sean eliminados permanentemente.',
        ],
        'cancel_deletion' => [
            'heading' => '¿Cancelar la eliminación del equipo?',
            'notice' => 'El equipo y todos sus datos se conservarán.',
        ],
    ],

    'edit_team' => 'Editar equipo',

    'roles' => [
        'admin' => [
            'description' => 'Los administradores pueden realizar cualquier acción.',
        ],
        'editor' => [
            'description' => 'Los editores pueden leer, crear y actualizar.',
        ],
    ],
];
