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
        ],
    ],

    'sections' => [
        'update_profile_information' => [
            'title' => 'Información del perfil',
            'description' => 'Actualice la información del perfil y la dirección de correo electrónico de su cuenta.',
        ],
        'update_password' => [
            'title' => 'Actualizar contraseña',
            'description' => 'Asegúrese de que su cuenta utilice una contraseña larga y aleatoria para mayor seguridad.',
        ],
        'set_password' => [
            'title' => 'Establecer contraseña',
            'description' => 'Añada una contraseña a su cuenta para poder iniciar sesión también con su correo electrónico y contraseña.',
        ],
        'browser_sessions' => [
            'title' => 'Sesiones del navegador',
            'description' => 'Gestione y cierre sus sesiones activas en otros navegadores y dispositivos.',
            'notice' => 'Si es necesario, puede cerrar sesión en todos los demás navegadores de todos sus dispositivos. A continuación se muestran algunas de sus sesiones recientes; sin embargo, esta lista puede no ser exhaustiva. Si cree que su cuenta ha sido comprometida, también debe actualizar su contraseña.',
            'labels' => [
                'current_device' => 'Este dispositivo',
                'last_active' => 'Última actividad',
                'unknown_device' => 'Desconocido',
            ],
        ],
        'delete_account' => [
            'title' => 'Eliminar cuenta',
            'description' => 'Programe su cuenta para eliminación.',
            'notice' => 'Eliminar su cuenta la programará para su eliminación permanente tras un periodo de gracia de 30 días. Puede cancelar la eliminación volviendo a iniciar sesión en cualquier momento antes de ese plazo. Transcurrido el periodo de gracia, todos sus datos serán eliminados permanentemente.',
        ],
    ],

    'actions' => [
        'save' => 'Guardar',
        'remove_photo' => 'Eliminar foto',
        'delete_account' => 'Eliminar cuenta',
        'log_out_other_browsers' => 'Cerrar otras sesiones de navegador',
    ],

    'notifications' => [
        'save' => [
            'success' => 'Guardado.',
        ],
        'photo_removed' => 'Foto de perfil eliminada.',
        'photo_remove_failed' => 'No se pudo eliminar la foto de perfil. Por favor, inténtelo de nuevo.',
        'logged_out_other_sessions' => [
            'success' => 'Todas las demás sesiones de navegador han sido cerradas correctamente.',
        ],
        'delete_account_blocked' => [
            'title' => 'Eliminación de cuenta bloqueada',
        ],
    ],

    'modals' => [
        'delete_account' => [
            'notice' => 'Esto programará su cuenta para su eliminación. Tendrá 30 días para cancelarlo volviendo a iniciar sesión. Después de ese plazo, todos los datos serán eliminados permanentemente. Por favor, introduzca su contraseña para confirmar.',
            'notice_no_password' => 'Esto programará su cuenta para su eliminación. Tendrá 30 días para cancelarlo volviendo a iniciar sesión. Después de ese plazo, todos los datos serán eliminados permanentemente.',
        ],
        'log_out_other_browsers' => [
            'title' => 'Cerrar otras sesiones de navegador',
            'description' => 'Introduzca su contraseña para confirmar que desea cerrar sesión en los demás navegadores de todos sus dispositivos.',
            'description_no_password' => '¿Está seguro de que desea cerrar sesión en los demás navegadores de todos sus dispositivos?',
        ],
    ],

    'edit_profile' => 'Editar perfil',

    'scheduled_deletion_interstitial' => [
        'actions' => [
            'cancel_deletion' => [
                'label' => 'Conservar mi cuenta',
                'modal_heading' => '¿Conservar su cuenta?',
                'modal_description' => 'Se cancelará la eliminación programada y todos sus datos se conservarán.',
                'modal_submit_label' => 'Sí, conservar mi cuenta',
            ],
            'logout' => [
                'label' => 'Cerrar sesión',
            ],
        ],
    ],
];
