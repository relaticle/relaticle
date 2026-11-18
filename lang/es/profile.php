<?php

declare(strict_types=1);

return [
    'form' => [
        'name' => [
            'label' => 'Nombre',
        ],
        'email' => [
            'label' => 'Correo electrónico',
        ],
        'profile_photo' => [
            'label' => 'Foto',
        ],
        'timezone' => [
            'label' => 'Zona horaria',
            'helper_text' => 'Las fechas y horas de toda la aplicación se muestran en esta zona horaria.',
            'placeholder' => 'Seleccione una zona horaria',
        ],
        'locale' => [
            'label' => 'Idioma',
            'helper_text' => 'La aplicación, los correos electrónicos y las notificaciones usan este idioma.',
            'placeholder' => 'Seleccione un idioma',
        ],
        'current_password' => [
            'label' => 'Contraseña actual',
        ],
        'new_password' => [
            'label' => 'Contraseña nueva',
        ],
        'confirm_password' => [
            'label' => 'Confirmar contraseña',
        ],
        'password' => [
            'label' => 'Contraseña',
            'throttled' => 'Demasiados intentos. Inténtelo de nuevo en :seconds segundos.',
        ],
    ],

    'sections' => [
        'update_profile_information' => [
            'title' => 'Información del perfil',
            'description' => 'Actualice la información del perfil de su cuenta y su dirección de correo electrónico.',
        ],
        'update_password' => [
            'title' => 'Actualizar contraseña',
            'description' => 'Asegúrese de que su cuenta use una contraseña larga y aleatoria para mantenerse segura.',
        ],
        'set_password' => [
            'title' => 'Establecer contraseña',
            'description' => 'Agregue una contraseña a su cuenta para poder iniciar sesión también con su correo electrónico y contraseña.',
        ],
        'browser_sessions' => [
            'title' => 'Sesiones del navegador',
            'description' => 'Administre y cierre sus sesiones activas en otros navegadores y dispositivos.',
            'notice' => 'Si es necesario, puede cerrar todas sus demás sesiones del navegador en todos sus dispositivos. A continuación se enumeran algunas de sus sesiones recientes, aunque la lista puede no estar completa. Si cree que su cuenta ha sido vulnerada, también debería actualizar su contraseña.',
            'labels' => [
                'current_device' => 'Este dispositivo',
                'last_active' => 'Última actividad',
                'unknown_device' => 'Desconocido',
            ],
        ],
        'delete_account' => [
            'title' => 'Eliminar cuenta',
            'description' => 'Elimine su cuenta de forma permanente después de un período de gracia de 30 días.',
            'notice' => 'Su perfil y su cuenta de inicio de sesión se eliminarán de forma permanente después de 30 días. Los espacios de trabajo a los que solo pertenece usted, incluidos sus datos de CRM, también se eliminarán. Los registros de los espacios de trabajo compartidos permanecerán sin su perfil. Inicie sesión antes de la fecha de eliminación para cancelarla.',
            'confirm_email_label' => 'Escriba el correo electrónico de su cuenta para confirmar',
            'confirm_email_mismatch' => 'No coincide con el correo electrónico de su cuenta.',
        ],
        'mfa' => [
            'title' => 'Autenticación de dos factores',
            'description' => 'Exija un código de autenticación al iniciar sesión sin una llave de acceso.',
            'status_enabled' => 'La autenticación de dos factores está activada.',
            'status_disabled' => 'La autenticación de dos factores está desactivada. Actívela para proteger su cuenta si alguna vez le roban la contraseña.',
            'enable' => 'Activar',
            'disable' => 'Desactivar',
            'identity_description' => 'Confirme su identidad antes de configurar su aplicación de autenticación.',
            'continue' => 'Continuar',
            'setup_heading' => 'Configure su aplicación de autenticación',
            'verify' => 'Verificar y activar',
            'recovery_save_heading' => 'Guarde sus códigos de recuperación',
            'recovery_saved' => 'Ya guardé mis códigos de recuperación',
            'enable_heading' => 'Active la autenticación de dos factores',
            'enable_description' => 'Escanee el código con su aplicación de autenticación y luego ingrese el código de seis dígitos que muestra.',
            'disable_heading' => 'Desactive la autenticación de dos factores',
            'disable_description' => 'Ya no se le pedirá un código al iniciar sesión.',
            'scan_hint' => 'Escanee esto con su aplicación de autenticación.',
            'manual_hint' => '¿No puede escanear? Ingrese esta clave:',
            'code_label' => 'Código de seis dígitos',
            'code_invalid' => 'Ese código no es correcto. Revise su aplicación de autenticación e inténtelo de nuevo.',
            'confirm' => 'Confirmar',
            'enabled_notification' => 'La autenticación de dos factores está activada.',
            'disabled_notification' => 'La autenticación de dos factores está desactivada.',
            'recovery_title' => 'Códigos de recuperación',
            'recovery_description' => 'Guárdelos en un lugar seguro. Cada uno le permite iniciar sesión una vez si pierde su aplicación de autenticación.',
            'recovery_show' => 'Mostrar códigos de recuperación',
            'recovery_regenerate' => 'Generar códigos nuevos',
            'recovery_regenerated' => 'Se generaron códigos de recuperación nuevos. Los anteriores ya no funcionan.',
            'recovery_heading' => 'Sus códigos de recuperación',
            'copy' => 'Copiar',
            'copied' => 'Copiado',
            'copy_key' => 'Copiar clave de configuración',
            'copy_codes' => 'Copiar todos los códigos',
        ],

        'passkeys' => [
            'title' => 'Llaves de acceso',
            'description' => 'Administre sus llaves de acceso para iniciar sesión sin contraseña.',
            'unsupported' => 'Este navegador no admite llaves de acceso.',
            'empty' => 'Aún no hay llaves de acceso. Agregue una para iniciar sesión sin contraseña.',
            'added' => 'Agregada :time',
            'last_used' => 'Último uso :time',
            'add_passkey' => 'Agregar llave de acceso',
            'add_description' => 'Registre una llave de acceso nueva en este dispositivo para iniciar sesión sin contraseña.',
            'name_label' => 'Nombre de la llave de acceso',
            'name_placeholder' => 'p. ej., MacBook Pro, iPhone',
            'default_name' => 'Llave de acceso',
            'rename' => 'Renombrar',
            'save' => 'Guardar',
            'use_password' => 'Usar su contraseña en su lugar',
            'method_hint' => 'Confirmará con Face ID, Touch ID o su llave de acceso.',
            'confirmed' => 'Confirmado',
            'register' => 'Registrar llave de acceso',
            'registering' => 'Registrando...',
            'waiting' => 'Esperando la llave de acceso…',
            'cancel' => 'Cancelar',
            'remove' => 'Quitar',
            'remove_confirm_title' => 'Quitar llave de acceso',
            'remove_confirm' => 'Ya no podrá usarla para iniciar sesión.',
        ],
    ],

    'actions' => [
        'save' => 'Guardar',
        'remove_photo' => 'Quitar foto',
        'delete_account' => 'Eliminar cuenta',
        'log_out_other_browsers' => 'Cerrar las demás sesiones del navegador',
    ],

    'notifications' => [
        'account_deletion_cancelled' => 'Eliminación de la cuenta cancelada',
        'save' => [
            'success' => 'Guardado.',
        ],
        'photo_removed' => 'Foto de perfil quitada.',
        'photo_remove_failed' => 'No se pudo quitar su foto de perfil. Inténtelo de nuevo.',
        'logged_out_other_sessions' => [
            'success' => 'Se cerraron correctamente todas las demás sesiones del navegador.',
            'remembered_only' => 'Los otros dispositivos cierran sesión cuando permanecen inactivos durante :minutes minutos. Un dispositivo con Relaticle abierto mantiene la sesión iniciada. Cambie su contraseña para cerrarle la sesión de inmediato.',
        ],
        'delete_account_blocked' => [
            'title' => 'Eliminación de la cuenta bloqueada',
        ],
        'passkey_removed' => [
            'success' => 'Llave de acceso quitada.',
        ],
        'passkey_renamed' => [
            'success' => 'Llave de acceso renombrada.',
        ],
        'passkey_registration_failed' => [
            'title' => 'No se pudo agregar la llave de acceso. Inténtelo de nuevo.',
        ],
        'passkey_confirmation_failed' => [
            'title' => 'Falló la verificación de la llave de acceso. Inténtelo de nuevo.',
        ],
        'identity_confirmation_failed' => [
            'title' => 'Falló la confirmación de identidad. Inténtelo de nuevo.',
        ],
    ],

    'modals' => [
        'delete_account' => [
            'notice' => 'Su perfil y su cuenta de inicio de sesión se eliminarán después de 30 días. Los espacios de trabajo a los que solo pertenece usted también se eliminarán. Los registros de los espacios de trabajo compartidos permanecerán. Inicie sesión antes de la fecha de eliminación para cancelarla.',
        ],
        'log_out_other_browsers' => [
            'title' => 'Cerrar las demás sesiones del navegador',
            'description' => 'Se cerrará su sesión en todos sus demás dispositivos.',
        ],
    ],

    'security' => 'Seguridad',

    'scheduled_deletion_interstitial' => [
        'heading' => 'La eliminación de su cuenta está programada',
        'details' => [
            'account' => 'Su perfil y su cuenta de inicio de sesión se eliminarán de forma permanente.',
            'deletion_date' => 'Fecha de eliminación:',
            'workspaces' => 'También se eliminarán :count espacio de trabajo de su propiedad exclusiva y sus datos de CRM.|También se eliminarán :count espacios de trabajo de su propiedad exclusiva y sus datos de CRM.',
            'shared_records' => 'Los registros de los espacios de trabajo compartidos permanecerán sin su perfil.',
        ],
        'help' => '¿Cambió de opinión? Conserve su cuenta para cancelar esta eliminación y recuperar el acceso.',
        'actions' => [
            'cancel_deletion' => [
                'label' => 'Conservar mi cuenta',
                'modal_heading' => '¿Conservar su cuenta?',
                'modal_description' => 'Se cancelará la eliminación programada. Recuperará el acceso a su cuenta y a sus espacios de trabajo.',
                'modal_submit_label' => 'Sí, conservar mi cuenta',
            ],
            'logout' => [
                'label' => 'Cerrar sesión',
            ],
        ],
    ],
];
