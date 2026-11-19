<?php

declare(strict_types=1);

return [
    'fallback_link' => 'Si el botón no funciona, copie este enlace en su navegador:',

    'footer' => [
        'settings' => 'Configuración de notificaciones',
        'unsubscribe' => 'Cancelar la suscripción al resumen diario',
        'copyright' => '© :year :company',
        'reason' => [
            'owner' => 'Recibe este mensaje porque es propietario del espacio de trabajo :workspace.',
            'member' => 'Recibe este mensaje porque es miembro de :workspace.',
            'former_member' => 'Recibe este mensaje porque fue miembro de :workspace.',
            'former_owner' => 'Recibe este mensaje porque fue propietario del espacio de trabajo :workspace.',
            'digest' => 'Recibe este mensaje porque activó el resumen diario.',
            'assignee' => 'Recibe este mensaje porque se le asignó una tarea en :workspace.',
            'invitee' => 'Recibe este mensaje porque se invitó a :email a :workspace.',
            'contact' => 'Recibe este mensaje porque alguien envió el formulario de contacto.',
            'account' => 'Recibe este mensaje por una solicitud en su cuenta de :company.',
            'onboarding' => 'Recibe este mensaje porque creó un espacio de trabajo de :company.',
            'billing_contact' => 'Recibe este mensaje porque es el contacto de facturación de :workspace.',
        ],
    ],

    'unsubscribe' => [
        'title' => 'Cancelar suscripción',
        'heading' => '¿Detener el resumen diario?',
        'body' => 'Dejará de recibir el resumen matutino de tareas en :email. Puede volver a activarlo en la configuración de notificaciones.',
        'confirm' => 'Cancelar suscripción',
        'done_heading' => 'Su suscripción se canceló',
        'done_body' => 'El resumen diario está desactivado para :email.',
        'settings' => 'Configuración de notificaciones',
    ],

    'trial_ending' => [
        'subject' => 'Su prueba de Pro termina el :date',
        'preheader' => 'Entonces :workspace se pausa, salvo que elija un plan. Sus registros están seguros.',
        'preheader_grandfathered' => 'Entonces :workspace vuelve a su plan Cloud Gratuito. Sus registros están seguros.',
        'heading' => 'Pro para :workspace termina el :date',
        'ends_at' => 'Su prueba de Pro termina el :datetime (hora de :zone).',
        'pauses' => 'Después, :workspace se pausa hasta que elija un plan.',
        'kept' => 'Si nadie inicia sesión durante los 60 días siguientes, eliminaremos el espacio de trabajo y sus registros.',
        'keeps' => 'Pro conserva todos los modelos de IA, :credits créditos mensuales y límites de uso más altos. Un precio fijo cubre todo el espacio de trabajo.',
        'grandfathered' => 'Si no hace nada, :workspace vuelve a su plan Cloud Gratuito (histórico). Sus datos no se modifican.',
        'cta' => 'Elegir un plan',
        'questions' => '¿Tiene preguntas? Responda a este correo electrónico.',
    ],

    'trial_usage' => [
        'so_far' => 'Hasta ahora su equipo ha :parts.',
        'during' => 'Durante la prueba su equipo ha :parts.',
        'records' => 'agregado :count registro|agregado :count registros',
        'questions' => 'hecho :count pregunta a Rela|hecho :count preguntas a Rela',
        'both' => ':records y :questions',
    ],

    'pro_ended' => [
        'trial_ended' => [
            'subject' => 'Terminó su prueba de Pro para :workspace',
            'heading' => 'Terminó su prueba de Pro para :workspace',
            'preheader' => 'Sus registros están seguros. Elija un plan o amplíe la prueba :days días.',
        ],
        'extension_ended' => [
            'subject' => 'Terminó su prueba ampliada para :workspace',
            'heading' => 'Terminó su prueba ampliada para :workspace',
            'preheader' => 'Sus registros están seguros. Elija un plan para continuar donde lo dejó.',
        ],
        'subscription_ended' => [
            'subject' => 'Terminó su suscripción a Cloud',
            'heading' => 'Terminó el acceso a Cloud de :workspace',
        ],
        'managed_ended' => [
            'subject' => 'Terminó su plan :plan',
            'heading' => 'Terminó :plan para :workspace',
            'preheader' => 'Sus registros están seguros. Contáctenos para reiniciar :plan.',
            'grandfathered' => ':workspace vuelve a su plan Cloud Gratuito (histórico). Sus datos no se modifican.',
            'restore' => 'Contáctenos para reiniciar :plan. Sus registros permanecen como están.',
            'cta' => 'Contáctenos',
            'mailbox_sync_paused' => 'La sincronización de correo electrónico y calendario también está en pausa y se reanuda cuando :plan se reinicie.',
        ],
        'trial_paused' => ':workspace está en pausa. Sus registros están seguros.',
        'kept' => 'Si nadie inicia sesión, eliminaremos :workspace el :date. Elija un plan antes de esa fecha para conservarlo.',
        'extend' => '¿Necesita más tiempo? [Amplíe su prueba :days días](:url) desde la página de facturación. Puede hacerlo una sola vez.',
        'preheader' => 'Sus registros están seguros. Suscríbase para reabrir el espacio de trabajo.',
        'preheader_grandfathered' => 'Su espacio de trabajo volvió a su plan Cloud Gratuito.',
        'paused' => 'El acceso a Cloud de :workspace está ahora en pausa. Sus registros están seguros y no se ha eliminado nada.',
        'mailbox_sync_paused' => 'La sincronización de correo electrónico y calendario también está en pausa y se reanuda cuando se suscriba.',
        'restore' => 'Suscríbase a un plan de Cloud para reabrir la aplicación, la API REST, el MCP server y el asistente de IA exactamente donde los dejó.',
        'grandfathered' => ':workspace vuelve a su plan Cloud Gratuito (histórico). Sus datos no se modifican y puede suscribirse de nuevo en cualquier momento.',
        'cta' => 'Elegir un plan',
        'cta_grandfathered' => 'Elegir un plan',
    ],

    'managed_plan_invoice' => [
        'subject' => 'Su factura del plan :plan de Relaticle',
        'preheader' => 'Su factura anual del plan :plan para :workspace está lista',
        'heading' => 'Su factura del plan :plan está lista',
        'line' => 'Su factura anual del plan :plan para :workspace es de :amount y vence el :date. El plan se activa cuando se paga.',
        'line_without_due' => 'Su factura anual del plan :plan para :workspace es de :amount. El plan se activa cuando se paga.',
        'cta' => 'Ver y pagar la factura',
    ],

    'setup_nudge' => [
        'subject' => 'Su espacio de trabajo le espera',
        'preheader' => 'Un paso pone en marcha :workspace: :step',
        'heading' => ':name, :workspace está listo para sus propios registros',
        'step' => 'Siguiente paso: :step.',
        'cta' => 'Continuar en :assistant',
    ],

    'task_assigned' => [
        'subject' => 'Nueva tarea: :title',
        'preheader' => 'Se le asignó en :workspace',
        'preheader_without_workspace' => 'Se le asignó una tarea',
        'heading' => 'Tiene una nueva tarea',
        'workspace_label' => 'Espacio de trabajo',
        'cta' => 'Ver tarea',
    ],

    'task_digest' => [
        'subject_both' => '[:workspace] :overdue vencidas, :due para hoy, :date',
        'subject_overdue' => '[:workspace] :count tarea vencida, :date|[:workspace] :count tareas vencidas, :date',
        'subject_due' => '[:workspace] :count tarea para hoy, :date|[:workspace] :count tareas para hoy, :date',
        'preheader_more' => ':tasks y :count más',
        'heading' => 'Buenos días, :name',
        'summary' => 'Tiene :tasks en :workspace.',
        'summary_both' => 'Tiene :overdue y :due en :workspace.',
        'open_hint' => 'Seleccione una tarea para abrirla en :workspace.',
        'summary_overdue' => ':count tarea vencida|:count tareas vencidas',
        'summary_due' => ':count tarea para hoy|:count tareas para hoy',
        'overdue' => 'Vencidas',
        'due_today' => 'Para hoy',
        'due_overdue' => ':date (vencida)',
        'due_today_chip' => 'Para hoy',
        'more_records' => '+:count',
        'more_tasks' => 'Ver :count tarea más|Ver :count tareas más',
        'cta' => 'Abrir mis tareas',
    ],

    'workspace_invitation' => [
        'subject' => ':inviter le invitó a :workspace',
        'subject_without_inviter' => 'Se le invitó a :workspace',
        'preheader' => 'Únase a :workspace en Relaticle como :role',
        'heading' => 'Únase a :workspace',
        'line_with_inviter' => ':inviter le invitó al espacio de trabajo :workspace en Relaticle con acceso de :role.',
        'line' => 'Se le invitó al espacio de trabajo :workspace en Relaticle con acceso de :role.',
        'expiry' => 'Esta invitación vence :expiry.',
        'ignore' => '¿No esperaba este mensaje? Ignore este correo electrónico.',
        'cta' => 'Aceptar invitación',
    ],

    'workspace_deletion_scheduled' => [
        'subject' => 'Se programó la eliminación de :workspace',
        'preheader' => 'Se elimina el :date. Cancele en cualquier momento antes',
        'heading' => ':workspace se eliminará el :date',
        'removes' => 'Las personas, empresas, tareas, oportunidades, notas y todos los demás registros de :workspace se eliminan después de esa fecha.',
        'cancel' => 'Puede cancelar desde la configuración del espacio de trabajo en cualquier momento antes de esa fecha.',
        'cta' => 'Cancelar eliminación',
    ],

    'workspace_deletion_reminder' => [
        'subject' => ':workspace se elimina en :days día|:workspace se elimina en :days días',
        'preheader' => 'Último recordatorio antes del :date',
        'heading' => ':days día para que se elimine :workspace|:days días para que se elimine :workspace',
        'final' => 'Este es el último recordatorio. Todo lo de :workspace se elimina después del :date.',
        'cancel' => 'Puede cancelar desde la configuración del espacio de trabajo en cualquier momento antes de esa fecha.',
        'cta' => 'Cancelar eliminación',
    ],

    'workspace_deletion_cancelled' => [
        'subject' => 'Se canceló la eliminación de :workspace',
        'preheader' => 'Sus datos están seguros',
        'heading' => ':workspace se queda',
        'body' => 'Se canceló la eliminación programada de :workspace. No se eliminó nada.',
        'cta' => 'Abrir :workspace',
    ],

    'workspace_member_removed' => [
        'subject' => 'Se le quitó de :workspace',
        'preheader' => 'Ya no tiene acceso a este espacio de trabajo',
        'heading' => 'Se le quitó de :workspace',
        'body' => 'Su acceso a :workspace y a sus registros terminó. Sus otros espacios de trabajo no se ven afectados.',
        'cta' => 'Abrir Relaticle',
    ],

    'account_deletion_scheduled' => [
        'subject' => 'Se programó la eliminación de su cuenta',
        'preheader' => 'Se elimina el :date. Inicie sesión para cancelar',
        'heading' => 'Su cuenta se eliminará el :date',
        'removes' => 'Su perfil y todos los espacios de trabajo de su propiedad se eliminan después de esa fecha.',
        'cancel' => '¿Cambió de opinión? Inicie sesión antes de esa fecha y la eliminación se cancela.',
        'cta' => 'Conservar mi cuenta',
    ],

    'account_deletion_reminder' => [
        'subject' => 'Su cuenta se elimina en :days día|Su cuenta se elimina en :days días',
        'preheader' => 'Último recordatorio antes del :date',
        'heading' => ':days día para que se elimine su cuenta|:days días para que se elimine su cuenta',
        'final' => 'Este es el último recordatorio. Su cuenta y sus datos se eliminan después del :date.',
        'cancel' => 'Inicie sesión antes de esa fecha y la eliminación se cancela.',
        'cta' => 'Conservar mi cuenta',
    ],

    'account_deletion_cancelled' => [
        'subject' => 'Su cuenta se queda',
        'preheader' => 'Eliminación cancelada, datos intactos',
        'heading' => 'Le damos la bienvenida de nuevo, :name',
        'body' => 'Se canceló la eliminación programada de su cuenta. No se eliminó nada.',
        'cta' => 'Abrir Relaticle',
    ],

    'verify_email' => [
        'subject' => 'Verifique su correo electrónico',
        'preheader' => 'Un clic completa su registro',
        'heading' => 'Verifique su dirección de correo electrónico',
        'body' => 'Confirme esta dirección para terminar de configurar su cuenta de Relaticle. Este enlace vence en :count minutos.',
        'ignore' => '¿No se registró? Ignore este correo electrónico.',
        'cta' => 'Verificar correo electrónico',
    ],

    'verify_email_change' => [
        'subject' => 'Confirme su nuevo correo electrónico',
        'preheader' => 'Confirme :email para terminar el cambio',
        'heading' => 'Confirme :email',
        'body' => 'Solicitó usar :email en su cuenta de :company. Confírmelo para terminar el cambio. Este enlace vence en :count minutos.',
        'ignore' => '¿No lo solicitó? Ignore este correo electrónico y se mantendrá su dirección actual.',
        'cta' => 'Confirmar nuevo correo electrónico',
    ],

    'email_code' => [
        'expires' => 'Este código vence en :count minutos.',
        'browser_hint' => 'Ingrese este código en la pestaña del navegador donde empezó.',
        'latest_only' => 'Solo funciona el último código que solicitó.',
        'unsolicited' => '¿No lo solicitó? Puede ignorar este correo electrónico.',
        'purposes' => [
            'signup' => [
                'subject' => 'Su código de registro de Relaticle',
                'preheader' => 'Use este código para terminar de crear su cuenta',
                'heading' => 'Confirme su dirección de correo electrónico',
                'body' => 'Ingrese este código para terminar de crear su cuenta de Relaticle.',
            ],
            'verify_email' => [
                'subject' => 'Su código de verificación de Relaticle',
                'preheader' => 'Use este código para verificar su correo electrónico',
                'heading' => 'Confirme su dirección de correo electrónico',
                'body' => 'Ingrese este código para verificar el correo electrónico de su cuenta de Relaticle.',
            ],
            'sign_in' => [
                'subject' => 'Su código de inicio de sesión de Relaticle',
                'preheader' => 'Use este código para iniciar sesión',
                'heading' => 'Confirme que es usted',
                'body' => 'Ingrese este código para iniciar sesión en su cuenta de Relaticle.',
            ],
            'confirm_identity' => [
                'subject' => 'Su código de confirmación de Relaticle',
                'preheader' => 'Use este código para continuar',
                'heading' => 'Confirme su identidad',
                'body' => 'Ingrese este código para continuar con su cuenta de Relaticle.',
            ],
            'change_email' => [
                'subject' => 'Su código para cambiar el correo electrónico de Relaticle',
                'preheader' => 'Use este código para confirmar su nuevo correo electrónico',
                'heading' => 'Confirme su nuevo correo electrónico',
                'body' => 'Ingrese este código para terminar de cambiar el correo electrónico de su cuenta de Relaticle.',
            ],
            'enable_email_sign_in' => [
                'subject' => 'Su código de inicio de sesión por correo electrónico de Relaticle',
                'preheader' => 'Use este código para activar el inicio de sesión por correo electrónico',
                'heading' => 'Confirme su dirección de correo electrónico',
                'body' => 'Ingrese este código para activar el inicio de sesión por correo electrónico en su cuenta de Relaticle.',
            ],
        ],
    ],

    'email_change_notice' => [
        'subject' => 'Se solicitó un cambio de correo electrónico',
        'preheader' => '¿Fue usted? Bloquéelo si no',
        'heading' => 'Alguien solicitó cambiar su correo electrónico a :email',
        'body' => 'Alguien con sesión iniciada en su cuenta solicitó cambiar su correo electrónico. Cuando se confirme :email, será la dirección de su cuenta.',
        'block' => 'Si no fue usted, bloquee el cambio ahora, cierre las demás sesiones y cambie su contraseña.',
        'cta' => 'Bloquear este cambio',
    ],

    'reset_password' => [
        'subject' => 'Restablezca su contraseña',
        'preheader' => 'Este enlace vence en :count minutos',
        'heading' => 'Restablezca su contraseña',
        'body' => 'Elija una nueva contraseña para su cuenta de Relaticle. Este enlace vence en :count minutos.',
        'ignore' => '¿No solicitó restablecerla? Ignore este correo electrónico y su contraseña se mantendrá.',
        'cta' => 'Restablecer contraseña',
    ],
];
