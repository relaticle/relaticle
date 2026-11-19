<?php

declare(strict_types=1);

return [
    'form' => [
        'workspace_name' => [
            'label' => 'Nombre del espacio de trabajo',
        ],
        'workspace_slug' => [
            'label' => 'Slug del espacio de trabajo',
            'helper_text' => 'Solo letras minúsculas, números y guiones.',
        ],
        'emails' => [
            'label' => 'Direcciones de correo electrónico',
            'placeholder' => 'name@company.com, teammate@company.com',
            'helper' => 'Separe varias direcciones con una coma, un espacio o un salto de línea.',
        ],
        'invite_as' => [
            'label' => 'Invitar como',
        ],
        'role' => [
            'label' => 'Rol',
        ],
        'workspace_logo' => [
            'label' => 'Logotipo del espacio de trabajo',
        ],
        'web_search' => [
            'label' => 'Permitir que :assistant busque en la web',
            'helper_text' => 'Las consultas de búsqueda se envían a la búsqueda web del proveedor de IA.',
            'unavailable' => 'Ningún modelo de esta instalación puede buscar en la web.',
        ],
    ],

    'sections' => [
        'update_workspace_name' => [
            'title' => 'Nombre del espacio de trabajo',
            'description' => 'El nombre del espacio de trabajo y la información de su propietario.',
        ],
        'update_workspace_logo' => [
            'title' => 'Logotipo del espacio de trabajo',
            'description' => 'Su logotipo aparece en el selector de espacios de trabajo, en las invitaciones y en la página de invitación.',
        ],
        'assistant' => [
            'description' => 'Elija qué puede hacer :assistant, el asistente de IA integrado, por los miembros de este espacio de trabajo.',
        ],
        'invite_people' => [
            'description' => 'Cada persona recibe un correo electrónico con un enlace para unirse a :workspace.',
        ],
        'add_workspace_member' => [
            'title' => 'Invitar personas',
            'description' => 'Invite a personas por correo electrónico. Cada persona recibe su propia invitación.',
        ],
        'workspace_members' => [
            'title' => 'Miembros',
            'description' => 'Todas las personas con acceso a este espacio de trabajo, incluidas las que aún no han aceptado.',
        ],
        'delete_workspace' => [
            'title' => 'Eliminar espacio de trabajo',
            'description' => 'Programe la eliminación de este espacio de trabajo.',
            'notice' => 'Al eliminar este espacio de trabajo, se programará su eliminación permanente después de un período de gracia de 30 días. Puede cancelar la eliminación en cualquier momento antes de que termine. Pasado el período de gracia, todos los recursos y datos se eliminarán de forma permanente.',
            'scheduled_notice' => 'La eliminación de este espacio de trabajo está programada para el :date.',
        ],
    ],

    'actions' => [
        'save' => 'Guardar',
        'invite_people' => 'Invitar miembros del espacio de trabajo',
        'send_invitations' => 'Enviar invitaciones',
        'close' => 'Cerrar',
        'update_workspace_role' => 'Cambiar rol',
        'compare_roles' => 'Comparar roles',
        'compare_roles_help_link' => 'Leer el detalle completo',
        'remove_workspace_member' => 'Quitar',
        'leave_workspace' => 'Salir',
        'resend_workspace_invitation' => 'Reenviar',
        'revoke_workspace_invitation' => 'Revocar',
        'delete_workspace' => 'Eliminar espacio de trabajo',
        'cancel_deletion' => 'Cancelar eliminación',
    ],

    'notifications' => [
        'workspace_deletion_cancelled' => [
            'success' => 'Eliminación del espacio de trabajo cancelada',
        ],
        'workspace_deletion_scheduled' => [
            'success' => 'Eliminación del espacio de trabajo programada para el :date',
        ],
        'workspace_invitation_sent' => [
            'success' => 'Invitación enviada.',
            'success_many' => ':count invitaciones enviadas.',
        ],
        'workspace_invitation_revoked' => [
            'success' => 'Invitación revocada.',
        ],
        'workspace_member_removed' => [
            'success' => 'Ha quitado a este miembro.',
        ],
        'leave_workspace' => [
            'success' => 'Ha salido del espacio de trabajo.',
        ],
        'permission_denied' => [
            'cannot_promote_to_admin' => 'Solo el propietario del espacio de trabajo puede conceder o revocar el acceso de administrador.',
            'cannot_remove_workspace_member' => 'No tiene permiso para quitar a este miembro.',
            'cannot_delete_workspace' => 'No tiene permiso para eliminar este espacio de trabajo.',
            'cannot_cancel_workspace_deletion' => 'No tiene permiso para cancelar la eliminación de este espacio de trabajo.',
        ],
        'role_updated' => [
            'success' => 'Rol actualizado.',
        ],
        'resend_throttled' => 'Espere :seconds segundos antes de reenviar.',
        'some_invites_failed' => [
            'title' => 'No se pudieron enviar algunas invitaciones',
            'not_sent_limit' => 'no enviada, se alcanzó el límite de invitaciones',
        ],
        'invite_rate_limited' => [
            'title' => 'Se enviaron demasiadas invitaciones',
            'body' => 'Espere :time antes de enviar más invitaciones.',
        ],
    ],

    'validation' => [
        'slug_too_short' => 'El campo :attribute debe tener al menos 3 caracteres.',
        'slug_format' => 'El campo :attribute solo puede contener letras minúsculas, números y guiones.',
        'slug_reserved' => 'El campo :attribute está reservado y no se puede usar.',
        'email_already_invited' => 'Esta persona ya fue invitada al espacio de trabajo.',
        'email_already_member' => 'Esta persona ya pertenece al espacio de trabajo.',
        'only_owner_promotes_admins' => 'Solo el propietario del espacio de trabajo puede conceder el rol de administrador.',
        'no_valid_emails' => 'Ingrese al menos una dirección de correo electrónico.',
        'too_many_invites' => 'Puede invitar hasta :max personas a la vez.',
        'managed_plan_before_deleting' => 'Este espacio de trabajo tiene un plan facturado por Relaticle. Comuníquese con nosotros para finalizar el plan antes de eliminar el espacio de trabajo.',
        'managed_plan_before_deleting_account' => 'Estos espacios de trabajo tienen un plan facturado por Relaticle: :workspaces. Comuníquese con nosotros para finalizar el plan antes de eliminar su cuenta.',
        'remove_members_before_deleting' => 'Quite a todos los miembros de estos espacios de trabajo, o elimine los espacios de trabajo, antes de eliminar su cuenta: :workspaces',
        'workspace_scheduled_for_deletion' => 'La eliminación de este espacio de trabajo está programada. Cancele la eliminación para invitar personas.',
    ],

    'modals' => [
        'update_workspace_role' => [
            'description' => ':name (:email)',
        ],
        'leave_workspace' => [
            'notice' => '¿Está seguro de que desea salir de este espacio de trabajo?',
            'mailbox_notice' => 'Perderá el acceso a este espacio de trabajo. Cualquier buzón que haya conectado aquí se desconectará y su correo electrónico quedará oculto para su equipo.',
        ],
        'remove_workspace_member' => [
            'mailbox_notice' => ':name pierde el acceso a este espacio de trabajo. Cualquier buzón que haya conectado aquí se desconectará y su correo electrónico quedará oculto para todos.',
        ],
        'delete_workspace' => [
            'notice' => 'Se programará la eliminación del espacio de trabajo. Tendrá 30 días para cancelarla antes de que todos los datos se eliminen de forma permanente.',
        ],
        'cancel_deletion' => [
            'heading' => '¿Cancelar la eliminación del espacio de trabajo?',
            'notice' => 'El espacio de trabajo y todos sus datos se conservarán.',
        ],
    ],

    'edit_workspace' => 'Configuración del espacio de trabajo',

    'custom_field_types' => [
        'domain' => 'Dominio',
    ],

    'tabs' => [
        'general' => 'General',
        'members' => 'Miembros',
        'custom_fields' => 'Atributos personalizados',
        'email' => 'Correo electrónico y calendario',
        'import_history' => 'Historial de importaciones',
        'activity' => 'Actividad',
        'billing' => 'Facturación',
    ],

    'activity' => [
        'system' => 'Sistema',
        'former_member' => 'Antiguo miembro',
        'search_placeholder' => 'Buscar por nombre del registro',
        'record_destroyed' => 'Este registro se eliminó de forma permanente.',
        'yes' => 'Sí',
        'no' => 'No',
        'outcome' => [
            'created' => 'creó :subject',
            'deleted' => 'eliminó :subject',
            'restored' => 'restauró :subject',
            'other' => 'realizó un cambio en :subject',
        ],
        'subjects' => [
            'company' => 'una empresa',
            'people' => 'una persona',
            'opportunity' => 'una oportunidad',
            'task' => 'una tarea',
            'note' => 'una nota',
        ],
        'columns' => [
            'created_at' => 'Cuándo',
            'causer' => 'Quién',
            'source' => 'Origen',
            'event' => 'Acción',
            'subject_type' => 'Tipo',
            'record' => 'Registro',
            'changes' => 'Cambios',
        ],
        'filters' => [
            'event' => 'Acción',
            'subject_type' => 'Tipo',
            'causer' => 'Quién',
            'source' => 'Origen',
            'from' => 'Desde',
            'until' => 'Hasta',
        ],
        'events' => [
            'created' => 'Creado',
            'updated' => 'Actualizado',
            'deleted' => 'Eliminado',
            'restored' => 'Restaurado',
            'imported' => 'Importado',
            'import_failed' => 'Falló la importación',
        ],
        'types' => [
            'company' => 'Empresa',
            'people' => 'Persona',
            'opportunity' => 'Oportunidad',
            'task' => 'Tarea',
            'note' => 'Nota',
            'custom_field' => 'Atributo personalizado',
            'custom_field_option' => 'Opción de atributo personalizado',
            'import' => 'Importación',
        ],
        'import_counts' => [
            'created' => 'Creados: :count',
            'updated' => 'Actualizados: :count',
            'skipped' => 'Omitidos: :count',
            'failed' => 'Fallidos: :count',
        ],
        'via_import' => 'Mediante la importación :file',
        'via_source' => 'Mediante :source',
        'empty' => [
            'heading' => 'Aún no hay actividad',
            'description' => 'Los cambios que sus miembros hagan en los registros aparecerán aquí.',
        ],
        'changes_modal' => [
            'trigger' => 'Ver todos los cambios',
            'trigger_full' => 'Ver el cambio completo',
            'close' => 'Cerrar',
        ],
        'full_change' => [
            'show' => 'Mostrar texto completo',
            'hide' => 'Ocultar texto completo',
            'before' => 'Antes',
            'after' => 'Después',
        ],
        'no_results' => [
            'heading' => 'Nada coincide con estos filtros',
            'description' => 'Pruebe con otro término de búsqueda o amplíe el rango de fechas.',
            'action' => 'Borrar filtros',
        ],
    ],

    'roles' => [
        'unknown' => 'Desconocido',
        'owner' => [
            'label' => 'Propietario',
        ],
        'admin' => [
            'label' => 'Administrador',
            'description' => 'Administra miembros, atributos personalizados y ajustes de correo electrónico. Puede eliminar registros de forma permanente',
        ],
        'member' => [
            'label' => 'Miembro',
            'description' => 'Puede crear y editar registros, y enviarlos a la papelera',
        ],
        'viewer' => [
            'label' => 'Lector',
            'description' => 'Puede leerlo todo, pero no modificar nada',
        ],
    ],

    'capabilities' => [
        'records' => [
            'view' => ['label' => 'Ver registros'],
            'create' => ['label' => 'Crear registros'],
            'update' => ['label' => 'Actualizar registros'],
            'delete' => ['label' => 'Eliminar y restaurar registros'],
            'force_delete' => ['label' => 'Eliminar registros de forma permanente'],
        ],
        'data' => [
            'import' => ['label' => 'Importar datos'],
            'export' => ['label' => 'Exportar datos'],
        ],
        'members' => [
            'manage' => ['label' => 'Invitar, quitar y cambiar los roles de los miembros'],
            'promote_admin' => ['label' => 'Ascender a alguien a administrador'],
        ],
        'fields' => [
            'manage' => ['label' => 'Administrar atributos personalizados'],
        ],
        'billing' => [
            'manage' => ['label' => 'Administrar la facturación'],
        ],
        'workspace' => [
            'manage' => ['label' => 'Cambiar el nombre o eliminar el espacio de trabajo'],
        ],
        'email' => [
            'manage' => ['label' => 'Administrar los ajustes de correo electrónico del espacio de trabajo'],
            'agent_send' => ['label' => 'Enviar correo electrónico mediante un asistente de IA'],
        ],
        'activity' => [
            'view' => ['label' => 'Ver el registro de actividad'],
        ],
    ],

    'role_matrix' => [
        'capability_column' => 'Capacidad',
        'granted' => 'Incluida',
        'not_granted' => 'No incluida',
        'opens_in_new_tab' => '(en una pestaña nueva)',
    ],

    'table' => [
        'user' => 'Miembro',
        'role' => 'Rol',
        'status' => 'Estado',
        'search_placeholder' => 'Buscar por nombre o correo electrónico',
        'invite_pending' => 'Invitación pendiente',
        'invite_expired' => 'Invitación vencida',
        'expires_in' => 'Vence en :time',
        'expired_ago' => 'Venció hace :time',
        'expired' => 'Vencida',
        'no_results' => [
            'heading' => 'Nadie coincide con esa búsqueda',
            'description' => 'Pruebe con parte de un nombre o con el correo electrónico que invitó.',
        ],
    ],

    'invitation' => [
        'joining_as' => 'Se une como',
        'members' => '{1} 1 persona ya está en este espacio de trabajo|[2,*] :count personas ya están en este espacio de trabajo',
    ],

    'deletion_banner' => [
        'message' => 'Este espacio de trabajo se eliminará de forma permanente el :date.',
    ],

    'pending_for_user' => [
        'heading' => 'Lo invitaron a unirse a :workspace',
        'detail_with_inviter' => ':inviter lo invitó con acceso de :role.',
        'detail' => 'Se unirá con acceso de :role.',
        'accept' => 'Unirse al espacio de trabajo',
        'decline' => 'Rechazar',
        'declined' => 'Invitación rechazada.',
    ],

    'accept' => [
        'joined' => 'Se unió a :workspace.',
        'already_member' => 'Ya es miembro de :workspace.',
        'no_longer_valid' => 'Esa invitación ya no es válida. Puede que se haya revocado o que haya vencido.',
        'email_unverified' => 'Verifique su dirección de correo electrónico antes de unirse a un espacio de trabajo.',
        'account_deleting' => 'No puede aceptar invitaciones mientras la eliminación de su cuenta esté programada.',
        'workspace_deleting' => 'La eliminación de este espacio de trabajo está programada y no acepta nuevos miembros.',
        'deleting' => [
            'heading' => ':workspace no acepta nuevos miembros',
            'body' => 'La eliminación de este espacio de trabajo está programada. Pida a la persona que lo invitó que cancele la eliminación y luego abra la invitación de nuevo.',
            'action' => 'Ir a mi espacio de trabajo',
        ],
        'ready' => [
            'heading' => 'Unirse a :workspace',
            'body_with_inviter' => ':inviter lo invitó a unirse a :workspace con acceso de :role.',
            'body' => 'Lo invitaron a unirse a :workspace con acceso de :role.',
            'action' => 'Unirse a :workspace',
            'decline' => 'Ahora no',
        ],
        'wrong_account' => [
            'heading' => 'Esta invitación es para una cuenta distinta',
            'body' => 'Esta invitación se envió a :invited, pero usted inició sesión como :current.',
            'switch' => 'Cerrar sesión y cambiar de cuenta',
            'stay' => 'Ir a mi espacio de trabajo',
        ],
        'expired' => [
            'heading' => 'Invitación no válida',
            'body' => 'Esta invitación venció, ya se usó o fue reemplazada por una más reciente.',
            'action' => 'Ir a mi espacio de trabajo',
        ],
    ],
];
