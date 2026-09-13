<?php

declare(strict_types=1);

return [
    'form' => [
        'name' => [
            'label' => 'Nombre',
        ],
        'email' => [
            'label' => 'Correo',
        ],
        'profile_photo' => [
            'label' => 'Foto',
        ],
        'timezone' => [
            'label' => 'Zona horaria',
            'helper_text' => 'Las fechas y horas de la app se muestran en esta zona horaria.',
            'placeholder' => 'Selecciona una zona horaria',
        ],
        'current_password' => [
            'label' => 'Contraseña actual',
        ],
        'new_password' => [
            'label' => 'Nueva contraseña',
        ],
        'confirm_password' => [
            'label' => 'Confirmar contraseña',
        ],
        'password' => [
            'label' => 'Contraseña',
            'throttled' => 'Demasiados intentos. Inténtalo de nuevo en :seconds segundos.',
        ],
    ],

    'sections' => [
        'update_profile_information' => [
            'title' => 'Información del perfil',
            'description' => 'Actualiza la información de tu perfil y tu correo electrónico.',
        ],
        'update_password' => [
            'title' => 'Cambiar contraseña',
            'description' => 'Usa una contraseña larga y aleatoria para mantener tu cuenta segura.',
        ],
        'set_password' => [
            'title' => 'Establecer contraseña',
            'description' => 'Añade una contraseña a tu cuenta para poder entrar también con tu correo y contraseña.',
        ],
        'browser_sessions' => [
            'title' => 'Sesiones del navegador',
            'description' => 'Gestiona y cierra tus sesiones activas en otros navegadores y dispositivos.',
            'notice' => 'Si lo necesitas, puedes cerrar todas tus sesiones en otros navegadores y dispositivos. Abajo aparecen algunas de tus sesiones recientes, aunque la lista puede no estar completa. Si crees que tu cuenta está comprometida, cambia también la contraseña.',
            'labels' => [
                'current_device' => 'Este dispositivo',
                'last_active' => 'Última actividad',
                'unknown_device' => 'Desconocido',
            ],
        ],
        'delete_account' => [
            'title' => 'Eliminar cuenta',
            'description' => 'Elimina tu cuenta de forma permanente tras un periodo de gracia de 30 días.',
            'notice' => 'Tu perfil y tu cuenta se eliminarán de forma permanente pasados 30 días. También se eliminarán los espacios de trabajo en los que solo estés tú, con sus datos del CRM. Los registros de los espacios de trabajo compartidos se conservarán sin tu perfil. Inicia sesión antes de la fecha de eliminación para cancelarla.',
            'confirm_email_label' => 'Escribe el correo de tu cuenta para confirmar',
            'confirm_email_mismatch' => 'No coincide con el correo de tu cuenta.',
        ],
        'mfa' => [
            'title' => 'Verificación en dos pasos',
            'description' => 'Pide un código de autenticación al iniciar sesión sin llave de acceso.',
            'status_enabled' => 'La verificación en dos pasos está activada.',
            'status_disabled' => 'La verificación en dos pasos está desactivada. Actívala para proteger tu cuenta si alguna vez te roban la contraseña.',
            'enable' => 'Activar',
            'disable' => 'Desactivar',
            'identity_description' => 'Confirma tu identidad antes de configurar tu app de autenticación.',
            'continue' => 'Continuar',
            'setup_heading' => 'Configura tu app de autenticación',
            'verify' => 'Verificar y activar',
            'recovery_save_heading' => 'Guarda tus códigos de recuperación',
            'recovery_saved' => 'He guardado mis códigos de recuperación',
            'enable_heading' => 'Activar la verificación en dos pasos',
            'enable_description' => 'Escanea el código con tu app de autenticación e introduce el código de seis dígitos que muestra.',
            'disable_heading' => 'Desactivar la verificación en dos pasos',
            'disable_description' => 'Ya no se te pedirá un código al iniciar sesión.',
            'scan_hint' => 'Escanéalo con tu app de autenticación.',
            'manual_hint' => '¿No puedes escanearlo? Introduce esta clave:',
            'code_label' => 'Código de seis dígitos',
            'code_invalid' => 'Ese código no es correcto. Revisa tu app de autenticación e inténtalo de nuevo.',
            'confirm' => 'Confirmar',
            'enabled_notification' => 'La verificación en dos pasos está activada.',
            'disabled_notification' => 'La verificación en dos pasos está desactivada.',
            'recovery_title' => 'Códigos de recuperación',
            'recovery_description' => 'Guárdalos en un lugar seguro. Cada uno te permite entrar una vez si pierdes tu app de autenticación.',
            'recovery_show' => 'Mostrar códigos de recuperación',
            'recovery_regenerate' => 'Generar códigos nuevos',
            'recovery_regenerated' => 'Se han generado códigos de recuperación nuevos. Los anteriores ya no sirven.',
            'recovery_heading' => 'Tus códigos de recuperación',
            'copy' => 'Copiar',
            'copied' => 'Copiado',
            'copy_key' => 'Copiar clave de configuración',
            'copy_codes' => 'Copiar todos los códigos',
        ],

        'passkeys' => [
            'title' => 'Llaves de acceso',
            'description' => 'Gestiona tus llaves de acceso para entrar sin contraseña.',
            'unsupported' => 'Este navegador no admite llaves de acceso.',
            'empty' => 'Todavía no tienes llaves de acceso. Añade una para entrar sin contraseña.',
            'added' => 'Añadida :time',
            'last_used' => 'Usada por última vez :time',
            'add_passkey' => 'Añadir llave de acceso',
            'add_description' => 'Registra una llave de acceso nueva en este dispositivo para entrar sin contraseña.',
            'name_label' => 'Nombre de la llave de acceso',
            'name_placeholder' => 'p. ej., MacBook Pro, iPhone',
            'default_name' => 'Llave de acceso',
            'rename' => 'Renombrar',
            'save' => 'Guardar',
            'use_password' => 'Usar tu contraseña',
            'method_hint' => 'Lo confirmarás con Face ID, Touch ID o tu llave de acceso.',
            'confirmed' => 'Confirmado',
            'register' => 'Registrar llave de acceso',
            'registering' => 'Registrando...',
            'waiting' => 'Esperando a la llave de acceso…',
            'cancel' => 'Cancelar',
            'remove' => 'Quitar',
            'remove_confirm_title' => 'Quitar llave de acceso',
            'remove_confirm' => 'Ya no podrás usarla para iniciar sesión.',
        ],
    ],

    'actions' => [
        'save' => 'Guardar',
        'remove_photo' => 'Quitar foto',
        'delete_account' => 'Eliminar cuenta',
        'log_out_other_browsers' => 'Cerrar las sesiones en otros navegadores',
    ],

    'notifications' => [
        'save' => [
            'success' => 'Guardado.',
        ],
        'photo_removed' => 'Foto de perfil eliminada.',
        'photo_remove_failed' => 'No se ha podido quitar tu foto de perfil. Inténtalo de nuevo.',
        'logged_out_other_sessions' => [
            'success' => 'Se han cerrado todas las demás sesiones del navegador.',
        ],
        'delete_account_blocked' => [
            'title' => 'No se puede eliminar la cuenta',
        ],
        'passkey_removed' => [
            'success' => 'Llave de acceso quitada.',
        ],
        'passkey_renamed' => [
            'success' => 'Llave de acceso renombrada.',
        ],
        'passkey_registration_failed' => [
            'title' => 'No se ha podido añadir la llave de acceso. Inténtalo de nuevo.',
        ],
        'passkey_confirmation_failed' => [
            'title' => 'La verificación con la llave de acceso ha fallado. Inténtalo de nuevo.',
        ],
        'identity_confirmation_failed' => [
            'title' => 'La confirmación de identidad ha fallado. Inténtalo de nuevo.',
        ],
    ],

    'modals' => [
        'delete_account' => [
            'notice' => 'Tu perfil y tu cuenta se eliminarán pasados 30 días. También se eliminarán los espacios de trabajo en los que solo estés tú. Los registros de los espacios de trabajo compartidos se conservarán. Inicia sesión antes de la fecha de eliminación para cancelarla.',
        ],
        'log_out_other_browsers' => [
            'title' => 'Cerrar las sesiones en otros navegadores',
            'description' => 'Se cerrará tu sesión en todos tus demás dispositivos.',
        ],
    ],

    'security' => 'Seguridad',

    'edit_profile' => 'Editar perfil',

    'scheduled_deletion_interstitial' => [
        'heading' => 'Tu cuenta está programada para eliminarse',
        'details' => [
            'account' => 'Tu perfil y tu cuenta se eliminarán de forma permanente.',
            'deletion_date' => 'Fecha de eliminación:',
            'workspaces' => 'También se eliminará :count espacio de trabajo del que eres el único propietario, con sus datos del CRM.|También se eliminarán :count espacios de trabajo de los que eres el único propietario, con sus datos del CRM.',
            'shared_records' => 'Los registros de los espacios de trabajo compartidos se conservarán sin tu perfil.',
        ],
        'help' => '¿Has cambiado de opinión? Conserva tu cuenta para cancelar la eliminación y recuperar el acceso.',
        'actions' => [
            'cancel_deletion' => [
                'label' => 'Conservar mi cuenta',
                'modal_heading' => '¿Conservar tu cuenta?',
                'modal_description' => 'Se cancelará la eliminación programada. Recuperarás el acceso a tu cuenta y a tus espacios de trabajo.',
                'modal_submit_label' => 'Sí, conservar mi cuenta',
            ],
            'logout' => [
                'label' => 'Cerrar sesión',
            ],
        ],
    ],
];
