<?php

declare(strict_types=1);

return [
    'fallback_link' => 'Si el botón no funciona, copia este enlace en tu navegador:',

    'footer' => [
        'settings' => 'Ajustes de notificaciones',
        'unsubscribe' => 'Darse de baja del resumen diario',
        'copyright' => '© :year :company',
        'reason' => [
            'owner' => 'Has recibido este correo porque eres el propietario del espacio de trabajo :team.',
            'member' => 'Has recibido este correo porque eres miembro de :team.',
            'former_member' => 'Has recibido este correo porque eras miembro de :team.',
            'digest' => 'Has recibido este correo porque activaste el resumen diario.',
            'assignee' => 'Has recibido este correo porque te han asignado una tarea en :team.',
            'invitee' => 'Has recibido este correo porque han invitado a :email a :team.',
            'contact' => 'Has recibido este correo porque alguien ha enviado el formulario de contacto.',
            'account' => 'Has recibido este correo por una solicitud en tu cuenta de :company.',
            'onboarding' => 'Has recibido este correo porque creaste un espacio de trabajo en :company.',
        ],
    ],

    'unsubscribe' => [
        'title' => 'Darse de baja',
        'heading' => '¿Dejar de recibir el resumen diario?',
        'body' => 'Dejarás de recibir el resumen de tareas de cada mañana en :email. Puedes volver a activarlo en los ajustes de notificaciones.',
        'confirm' => 'Darse de baja',
        'done_heading' => 'Te has dado de baja',
        'done_body' => 'El resumen diario está desactivado para :email.',
        'settings' => 'Ajustes de notificaciones',
    ],

    'trial_ending' => [
        'subject' => 'Tu prueba de Pro termina en 3 días',
        'preheader' => 'Mantén todos los modelos de IA y 2.000 créditos por un precio fijo',
        'heading' => 'Quedan 3 días de Pro para :team',
        'ends_on' => 'Tu prueba de Pro de 14 días termina el :date.',
        'keeps' => 'Con Pro mantienes todos los modelos de IA, 2.000 créditos al mes y límites de uso más altos.',
        'flat_price' => 'No se paga por usuario. Un único precio cubre todo el espacio de trabajo.',
        'grandfathered' => 'Si no haces nada, :team vuelve a su plan Cloud Free heredado. Tus datos no se tocan.',
        'paused' => 'Si no haces nada, el acceso en la nube se pausa cuando termine la prueba. Tus datos se conservan y puedes suscribirte cuando quieras para seguir donde lo dejaste.',
        'cta' => 'Mantener Pro',
    ],

    'setup_nudge' => [
        'subject' => 'Tu espacio de trabajo te espera',
        'preheader' => 'Un paso pone en marcha :team: :step',
        'heading' => ':name, :team sigue vacío',
        'step' => 'Siguiente paso: :step.',
        'cta' => 'Continuar en :assistant',
    ],

    'task_assigned' => [
        'subject' => 'Nueva tarea: :title',
        'preheader' => 'Te la han asignado en :team',
        'preheader_without_team' => 'Te han asignado una tarea',
        'heading' => 'Tienes una tarea nueva',
        'team_label' => 'Espacio de trabajo',
        'cta' => 'Ver tarea',
    ],

    'task_digest' => [
        'subject' => 'Tus tareas del :date',
        'preheader' => ':overdue vencidas, :due vencen hoy',
        'heading' => 'Tareas de hoy, :name',
        'overdue' => 'Vencidas',
        'due_today' => 'Vencen hoy',
        'due' => 'Vence el :date',
        'cta' => 'Ver todas mis tareas',
    ],

    'team_invitation' => [
        'subject' => ':inviter te ha invitado a :team',
        'subject_without_inviter' => 'Te han invitado a :team',
        'preheader' => 'Únete a :team en Crabdev CRM como :role',
        'heading' => 'Únete a :team',
        'line_with_inviter' => ':inviter te ha invitado al espacio de trabajo :team en Crabdev CRM con acceso de :role.',
        'line' => 'Te han invitado al espacio de trabajo :team en Crabdev CRM con acceso de :role.',
        'expiry' => 'Esta invitación caduca :expiry.',
        'ignore' => '¿No esperabas esta invitación? Ignora este correo.',
        'cta' => 'Aceptar invitación',
    ],

    'team_deletion_scheduled' => [
        'subject' => ':team está programado para eliminarse',
        'preheader' => 'Se elimina el :date. Puedes cancelarlo hasta entonces',
        'heading' => ':team se eliminará el :date',
        'removes' => 'Los contactos, empresas, tareas, oportunidades, notas y el resto de registros de :team se eliminarán después de esa fecha.',
        'cancel' => 'Puedes cancelarlo desde los ajustes del espacio de trabajo en cualquier momento antes de esa fecha.',
        'cta' => 'Cancelar la eliminación',
    ],

    'team_deletion_reminder' => [
        'subject' => ':team se elimina en :days día|:team se elimina en :days días',
        'preheader' => 'Último aviso antes del :date',
        'heading' => 'Queda :days día para que se elimine :team|Quedan :days días para que se elimine :team',
        'final' => 'Este es el último aviso. Todo lo que hay en :team se eliminará después del :date.',
        'cancel' => 'Puedes cancelarlo desde los ajustes del espacio de trabajo en cualquier momento antes de esa fecha.',
        'cta' => 'Cancelar la eliminación',
    ],

    'team_deletion_cancelled' => [
        'subject' => 'Eliminación de :team cancelada',
        'preheader' => 'Tus datos están a salvo',
        'heading' => ':team se queda',
        'body' => 'Se ha cancelado la eliminación programada de :team. No se ha eliminado nada.',
        'cta' => 'Abrir :team',
    ],

    'team_member_removed' => [
        'subject' => 'Te han quitado de :team',
        'preheader' => 'Ya no tienes acceso a este espacio de trabajo',
        'heading' => 'Te han quitado de :team',
        'body' => 'Tu acceso a :team y a sus registros ha terminado. Tus otros espacios de trabajo no se ven afectados.',
        'cta' => 'Abrir Crabdev CRM',
    ],

    'account_deletion_scheduled' => [
        'subject' => 'Tu cuenta está programada para eliminarse',
        'preheader' => 'Se elimina el :date. Inicia sesión para cancelarlo',
        'heading' => 'Tu cuenta se eliminará el :date',
        'removes' => 'Tu perfil y todos los espacios de trabajo de los que eres propietario se eliminarán después de esa fecha.',
        'cancel' => '¿Has cambiado de opinión? Inicia sesión antes de esa fecha y la eliminación se cancelará.',
        'cta' => 'Conservar mi cuenta',
    ],

    'account_deletion_reminder' => [
        'subject' => 'Tu cuenta se elimina en :days día|Tu cuenta se elimina en :days días',
        'preheader' => 'Último aviso antes del :date',
        'heading' => 'Queda :days día para que se elimine tu cuenta|Quedan :days días para que se elimine tu cuenta',
        'final' => 'Este es el último aviso. Tu cuenta y sus datos se eliminarán después del :date.',
        'cancel' => 'Inicia sesión antes de esa fecha y la eliminación se cancelará.',
        'cta' => 'Conservar mi cuenta',
    ],

    'account_deletion_cancelled' => [
        'subject' => 'Tu cuenta se queda',
        'preheader' => 'Eliminación cancelada, datos intactos',
        'heading' => 'Bienvenido de nuevo, :name',
        'body' => 'Se ha cancelado la eliminación programada de tu cuenta. No se ha eliminado nada.',
        'cta' => 'Abrir Crabdev CRM',
    ],

    'verify_email' => [
        'subject' => 'Verifica tu correo',
        'preheader' => 'Un clic y terminas de registrarte',
        'heading' => 'Verifica tu dirección de correo',
        'body' => 'Confirma esta dirección para terminar de configurar tu cuenta de Crabdev CRM.',
        'ignore' => '¿No te has registrado? Ignora este correo.',
        'cta' => 'Verificar correo',
    ],

    'verify_email_change' => [
        'subject' => 'Confirma tu nuevo correo',
        'preheader' => 'Confirma :email para terminar el cambio',
        'heading' => 'Confirma :email',
        'body' => 'Has pedido usar :email en tu cuenta de :company. Confírmalo para terminar el cambio. Este enlace caduca en :count minutos.',
        'ignore' => '¿No lo has pedido tú? Ignora este correo y tu dirección actual se mantendrá.',
        'cta' => 'Confirmar nuevo correo',
    ],

    'email_code' => [
        'expires' => 'Este código caduca en :count minutos.',
        'browser_hint' => 'Introduce este código en la pestaña del navegador donde empezaste.',
        'latest_only' => 'Solo funciona el último código que hayas pedido.',
        'unsolicited' => '¿No lo has pedido tú? Puedes ignorar este correo.',
        'purposes' => [
            'signup' => [
                'subject' => 'Tu código de registro en Crabdev CRM',
                'preheader' => 'Usa este código para terminar de crear tu cuenta',
                'heading' => 'Confirma tu dirección de correo',
                'body' => 'Introduce este código para terminar de crear tu cuenta de Crabdev CRM.',
            ],
            'verify_email' => [
                'subject' => 'Tu código de verificación de Crabdev CRM',
                'preheader' => 'Usa este código para verificar tu correo',
                'heading' => 'Confirma tu dirección de correo',
                'body' => 'Introduce este código para verificar el correo de tu cuenta de Crabdev CRM.',
            ],
            'sign_in' => [
                'subject' => 'Tu código de acceso a Crabdev CRM',
                'preheader' => 'Usa este código para iniciar sesión',
                'heading' => 'Confirma que eres tú',
                'body' => 'Introduce este código para iniciar sesión en tu cuenta de Crabdev CRM.',
            ],
            'confirm_identity' => [
                'subject' => 'Tu código de confirmación de Crabdev CRM',
                'preheader' => 'Usa este código para continuar',
                'heading' => 'Confirma tu identidad',
                'body' => 'Introduce este código para continuar con tu cuenta de Crabdev CRM.',
            ],
            'change_email' => [
                'subject' => 'Tu código para cambiar el correo en Crabdev CRM',
                'preheader' => 'Usa este código para confirmar tu nuevo correo',
                'heading' => 'Confirma tu nuevo correo',
                'body' => 'Introduce este código para terminar de cambiar el correo de tu cuenta de Crabdev CRM.',
            ],
            'enable_email_sign_in' => [
                'subject' => 'Tu código para activar el acceso por correo en Crabdev CRM',
                'preheader' => 'Usa este código para activar el acceso por correo',
                'heading' => 'Confirma tu dirección de correo',
                'body' => 'Introduce este código para activar el acceso por correo en tu cuenta de Crabdev CRM.',
            ],
        ],
    ],

    'email_change_notice' => [
        'subject' => 'Solicitud de cambio de correo',
        'preheader' => '¿Has sido tú? Si no, bloquéalo',
        'heading' => 'Alguien ha pedido cambiar tu correo a :email',
        'body' => 'Alguien con la sesión iniciada en tu cuenta ha pedido cambiar su correo. Cuando se confirme :email, pasará a ser la dirección de tu cuenta.',
        'block' => 'Si no has sido tú, bloquea el cambio ahora, cierra las demás sesiones y cambia tu contraseña.',
        'cta' => 'Bloquear este cambio',
    ],

    'reset_password' => [
        'subject' => 'Restablece tu contraseña',
        'preheader' => 'Este enlace caduca en :count minutos',
        'heading' => 'Restablece tu contraseña',
        'body' => 'Elige una contraseña nueva para tu cuenta de Crabdev CRM. Este enlace caduca en :count minutos.',
        'ignore' => '¿No has pedido restablecerla? Ignora este correo y tu contraseña se mantendrá.',
        'cta' => 'Restablecer contraseña',
    ],

    'contact_submission' => [
        'subject' => 'Nuevo contacto: :name',
        'preheader' => ':company, :email',
        'preheader_without_company' => ':email',
        'heading' => 'Nuevo mensaje del formulario de contacto',
        'name' => 'Nombre',
        'email' => 'Correo',
        'company' => 'Empresa',
        'cta' => 'Responder a :name',
    ],
];
