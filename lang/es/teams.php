<?php

declare(strict_types=1);

return [
    'form' => [
        'team_name' => [
            'label' => 'Nombre del espacio de trabajo',
        ],
        'team_slug' => [
            'label' => 'Identificador del espacio de trabajo',
            'helper_text' => 'Solo minúsculas, números y guiones.',
        ],
        'emails' => [
            'label' => 'Enviar invitación a',
            'placeholder' => 'ejemplo@correo.com',
            'helper' => 'Separa varias direcciones con una coma, un espacio o un salto de línea.',
        ],
        'invite_as' => [
            'label' => 'Invitar como',
        ],
        'team_logo' => [
            'label' => 'Logo del espacio de trabajo',
        ],
    ],

    'sections' => [
        'update_team_name' => [
            'title' => 'Nombre del espacio de trabajo',
            'description' => 'El nombre del espacio de trabajo y la información del propietario.',
        ],
        'update_team_logo' => [
            'title' => 'Logo del espacio de trabajo',
            'description' => 'Tu logo aparece en el selector de espacios de trabajo, en las invitaciones y en la página para unirse.',
        ],
        'add_team_member' => [
            'title' => 'Invitar personas',
            'description' => 'Envía una invitación por correo o comparte un enlace para que la gente se una por su cuenta.',
        ],
        'team_members' => [
            'title' => 'Miembros',
            'description' => 'Todas las personas con acceso a este espacio de trabajo, incluidas las que aún no han aceptado.',
        ],
        'delete_team' => [
            'title' => 'Eliminar espacio de trabajo',
            'description' => 'Programa la eliminación de este espacio de trabajo.',
            'notice' => 'Al eliminar este espacio de trabajo se programará su eliminación permanente tras un periodo de gracia de 30 días. Puedes cancelarla en cualquier momento antes de esa fecha. Pasado el periodo de gracia, todos los recursos y datos se eliminarán de forma permanente.',
            'scheduled_notice' => 'Este espacio de trabajo está programado para eliminarse el :date.',
        ],
    ],

    'actions' => [
        'save' => 'Guardar',
        'invite_people' => 'Invitar al equipo',
        'send_invitations' => 'Enviar invitaciones',
        'invite_link' => 'Enlace de invitación',
        'close' => 'Cerrar',
        'rotate_invite_link' => 'Generar un enlace nuevo',
        'disable_invite_link' => 'Desactivar el enlace',
        'enable_invite_link' => 'Activar el enlace',
        'update_team_role' => 'Cambiar rol',
        'remove_team_member' => 'Quitar',
        'leave_team' => 'Salir',
        'resend_team_invitation' => 'Reenviar',
        'revoke_team_invitation' => 'Revocar',
        'delete_team' => 'Eliminar espacio de trabajo',
        'cancel_deletion' => 'Cancelar la eliminación',
    ],

    'notifications' => [
        'team_invitation_sent' => [
            'success' => 'Invitación enviada.',
        ],
        'team_invitation_revoked' => [
            'success' => 'Invitación revocada.',
        ],
        'team_member_removed' => [
            'success' => 'Has quitado a este miembro.',
        ],
        'leave_team' => [
            'success' => 'Has salido del espacio de trabajo.',
        ],
        'permission_denied' => [
            'cannot_promote_to_admin' => 'Solo el propietario del espacio de trabajo puede dar o quitar el acceso de administrador.',
            'cannot_remove_team_member' => 'No tienes permiso para quitar a este miembro.',
            'cannot_delete_team' => 'No tienes permiso para eliminar este espacio de trabajo.',
            'cannot_cancel_team_deletion' => 'No tienes permiso para cancelar la eliminación de este espacio de trabajo.',
        ],
        'role_updated' => [
            'success' => 'Rol actualizado.',
        ],
        'invite_link_role_updated' => [
            'success' => 'Quien se una con este enlace tendrá ahora el rol :role.',
        ],
        'invite_link_rotated' => [
            'success' => 'Se ha generado un enlace de invitación nuevo. El anterior ya no funciona.',
        ],
        'invite_link_disabled' => [
            'success' => 'El enlace del espacio de trabajo está desactivado. Invita a la gente por correo.',
        ],
        'invite_link_enabled' => [
            'success' => 'El enlace del espacio de trabajo está activado. Cualquiera que lo abra puede unirse.',
        ],
        'resend_throttled' => 'Espera :seconds segundos antes de reenviar.',
        'some_invites_failed' => [
            'title' => 'Algunas invitaciones no se han podido enviar',
        ],
        'invite_rate_limited' => [
            'title' => 'Demasiadas invitaciones enviadas',
            'body' => 'Espera :seconds segundos antes de enviar más invitaciones.',
        ],
    ],

    'validation' => [
        'email_already_invited' => 'Esta persona ya ha sido invitada al espacio de trabajo.',
        'email_already_member' => 'Esta persona ya pertenece al espacio de trabajo.',
        'only_owner_promotes_admins' => 'Solo el propietario del espacio de trabajo puede dar el rol de administrador.',
        'invite_link_role_cannot_be_admin' => 'El enlace del espacio de trabajo no puede dar el rol de administrador. Invita a los administradores por correo.',
        'no_valid_emails' => 'Introduce al menos una dirección de correo.',
        'too_many_invites' => 'Puedes invitar a :max personas como máximo a la vez.',
        'remove_members_before_deleting' => 'Quita a todos los miembros de estos espacios de trabajo, o elimínalos, antes de eliminar tu cuenta: :teams',
    ],

    'modals' => [
        'leave_team' => [
            'notice' => '¿Seguro que quieres salir de este espacio de trabajo?',
        ],
        'delete_team' => [
            'notice' => 'Se programará la eliminación del espacio de trabajo. Tendrás 30 días para cancelarla antes de que todos los datos se eliminen de forma permanente.',
        ],
        'rotate_invite_link' => [
            'heading' => '¿Generar un enlace de invitación nuevo?',
            'notice' => 'El enlace actual dejará de funcionar de inmediato. Quien lo tenga todavía, en un chat o en un correo, no podrá unirse.',
        ],
        'disable_invite_link' => [
            'heading' => '¿Desactivar el enlace del espacio de trabajo?',
            'notice' => 'Una vez desactivado, nadie podrá unirse con el enlace actual. Al volver a activarlo se genera un enlace distinto, así que el antiguo sigue sin funcionar.',
        ],
        'cancel_deletion' => [
            'heading' => '¿Cancelar la eliminación del espacio de trabajo?',
            'notice' => 'El espacio de trabajo y todos sus datos se conservarán.',
        ],
    ],

    'edit_team' => 'Ajustes del espacio de trabajo',

    'tabs' => [
        'general' => 'General',
        'members' => 'Miembros',
        'custom_fields' => 'Campos personalizados',
        'import_history' => 'Historial de importaciones',
        'activity' => 'Actividad',
        'billing' => 'Facturación',
    ],

    'activity' => [
        'system' => 'Sistema',
        'search_placeholder' => 'Buscar por nombre del registro',
        'record_destroyed' => 'Este registro se ha eliminado de forma permanente.',
        'yes' => 'Sí',
        'no' => 'No',
        'columns' => [
            'created_at' => 'Cuándo',
            'causer' => 'Quién',
            'event' => 'Acción',
            'subject_type' => 'Tipo',
            'record' => 'Registro',
            'changes' => 'Cambios',
        ],
        'filters' => [
            'event' => 'Acción',
            'subject_type' => 'Tipo',
            'causer' => 'Quién',
            'from' => 'Desde',
            'until' => 'Hasta',
        ],
        'events' => [
            'created' => 'Creado',
            'updated' => 'Actualizado',
            'deleted' => 'Eliminado',
            'restored' => 'Restaurado',
        ],
        'types' => [
            'company' => 'Empresa',
            'people' => 'Contacto',
            'opportunity' => 'Oportunidad',
            'task' => 'Tarea',
            'note' => 'Nota',
        ],
        'empty' => [
            'heading' => 'Todavía no hay actividad',
            'description' => 'Aquí aparecerán los cambios que hagan los miembros en los registros.',
        ],
        'changes_modal' => [
            'trigger' => 'Ver todos los cambios',
            'close' => 'Cerrar',
        ],
        'no_results' => [
            'heading' => 'Nada coincide con estos filtros',
            'description' => 'Prueba con otra búsqueda o amplía el rango de fechas.',
            'action' => 'Quitar filtros',
        ],
    ],

    'roles' => [
        'owner' => [
            'label' => 'Propietario',
        ],
        'admin' => [
            'description' => 'Puede crear, editar y eliminar cualquier cosa en este espacio de trabajo.',
        ],
        'editor' => [
            'description' => 'Puede crear y editar registros, pero no eliminarlos.',
        ],
        'viewer' => [
            'description' => 'Puede ver los registros, pero no cambiarlos.',
        ],
    ],

    'table' => [
        'user' => 'Usuario',
        'role' => 'Rol',
        'status' => 'Estado',
        'search_placeholder' => 'Buscar por nombre o correo',
        'invite_pending' => 'Invitación pendiente',
        'invite_expired' => 'Invitación caducada',
        'expires_in' => 'Caduca en :time',
        'expired_ago' => 'Caducó hace :time',
        'expired' => 'Caducada',
        'no_results' => [
            'heading' => 'Nadie coincide con esa búsqueda',
            'description' => 'Prueba con parte de un nombre o con el correo al que invitaste.',
        ],
    ],

    'invitation' => [
        'members' => '{1} 1 persona ya está en este espacio de trabajo|[2,*] :count personas ya están en este espacio de trabajo',
    ],

    'invite_link' => [
        'heading' => 'Enlace de invitación',
        'description' => 'Comparte un enlace en lugar de escribir direcciones. Cualquiera que lo abra se une a este espacio de trabajo.',
        'url' => 'Enlace del espacio de trabajo',
        'copied' => 'Enlace copiado.',
        'default_role' => 'Rol para quien se una con este enlace',
        'default_role_helper' => 'Se guarda en cuanto lo eliges. Los administradores se invitan por correo.',
        'disabled_notice' => 'El enlace del espacio de trabajo está desactivado, así que solo se puede entrar con invitación por correo. Al activarlo se genera un enlace nuevo.',
        'join' => [
            'heading' => 'Únete a :workspace',
            'body' => 'Te unirás con acceso de :role.',
            'joining_as' => 'Te unes como',
            'action' => 'Unirse al espacio de trabajo',
            'decline' => 'Ahora no',
        ],
        'expired' => [
            'heading' => 'El enlace de invitación ha caducado',
            'body' => 'Este enlace de invitación ha caducado. Pide al propietario del espacio de trabajo que comparta uno nuevo.',
            'action' => 'Ir a mi espacio de trabajo',
        ],
    ],

    'pending_for_user' => [
        'heading' => 'Te han invitado a unirte a :team',
        'detail_with_inviter' => ':inviter te ha invitado con acceso de :role.',
        'detail' => 'Te unirás con acceso de :role.',
        'accept' => 'Unirse al espacio de trabajo',
        'decline' => 'Rechazar',
        'declined' => 'Invitación rechazada.',
    ],

    'accept' => [
        'joined' => 'Te has unido al espacio de trabajo :team.',
        'already_member' => 'Ya eres miembro de :team.',
        'no_longer_valid' => 'Esa invitación ya no es válida. Puede que la hayan revocado o que haya caducado.',
        'account_deleting' => 'No puedes aceptar invitaciones mientras tu cuenta está programada para eliminarse.',
        'team_deleting' => 'Este espacio de trabajo está programado para eliminarse y no admite miembros nuevos.',
        'ready' => [
            'heading' => 'Únete a :team',
            'body_with_inviter' => ':inviter te ha invitado a unirte a :team con acceso de :role.',
            'body' => 'Te han invitado a unirte a :team con acceso de :role.',
            'action' => 'Unirse a :team',
            'decline' => 'Ahora no',
        ],
        'wrong_account' => [
            'heading' => 'Esta invitación es para otra cuenta',
            'body' => 'Esta invitación se envió a :invited, pero has iniciado sesión como :current.',
            'switch' => 'Cerrar sesión y cambiar de cuenta',
            'stay' => 'Ir a mi espacio de trabajo',
        ],
        'expired' => [
            'heading' => 'La invitación ya no es válida',
            'body' => 'Esta invitación ha caducado o ya se ha aceptado.',
            'action' => 'Ir a mi espacio de trabajo',
        ],
    ],
];
