<?php

declare(strict_types=1);

return [
    'create_workspace' => [
        'label' => 'Crear espacio de trabajo',
        'steps' => [
            'workspace' => 'Espacio de trabajo',
            'attribution' => 'Atribución',
        ],
        'actions' => [
            'continue' => 'Continuar',
            'get_started' => 'Comenzar',
            'cancel' => 'Cancelar',
            'back' => 'Atrás',
        ],

        'headings' => [
            'workspace' => 'Cree su espacio de trabajo',
            'attribution' => '¿Cómo se enteró de nosotros?',
            'attribution_description' => 'Seleccione a continuación dónde conoció Relaticle. Este paso es opcional.',
            'use_case' => 'Ayúdenos a personalizar su espacio de trabajo',
            'use_case_description' => 'Relaticle le permite construir el CRM exacto que necesita, por complejo que sea.',
            'use_case_hint' => 'Cuéntenos su caso de uso y configuraremos una plantilla de pipeline que se ajuste a él.',
        ],
        'form' => [
            'your_name' => [
                'label' => 'Su nombre',
                'placeholder' => 'Jane Doe',
            ],
            'company_logo' => [
                'label' => 'Logotipo de la empresa',
            ],
            'workspace_name' => [
                'label' => 'Nombre de la empresa',
                'placeholder' => 'Ingrese el nombre de su empresa',
            ],
            'workspace_handle' => [
                'label' => 'Identificador del espacio de trabajo',
                'placeholder' => 'my-workspace',
                'helper_text' => 'Solo se permiten letras minúsculas, números y guiones.',
            ],
            'use_case_label' => '¿Para qué usará Relaticle?',
            'use_case_validation_attribute' => 'caso de uso',
            'use_case_context_label' => 'Elija lo que corresponda.',
            'use_case_context_validation_attribute' => 'detalles del caso de uso',
            'other_use_case_label' => '¿Qué va a registrar?',
            'other_use_case_placeholder' => 'Candidatos, donantes, compradores mayoristas',
            'other_use_case_validation_attribute' => 'lo que registra',
            'referral_detail_label' => '¿Qué asistente fue?',
            'referral_prompt_label' => '¿Qué le preguntó?',
            'referral_prompt_placeholder' => 'Un CRM de código abierto para un equipo de ventas pequeño',
            'referral_prompt_validation_attribute' => 'lo que preguntó',
        ],
        'notifications' => [
            'workspace_created' => [
                'title' => 'Espacio de trabajo creado',
                'body' => 'Su espacio de trabajo ":name" está listo.',
            ],
            'workspace_limit_reached' => [
                'title' => 'Límite de espacios de trabajo alcanzado',
                'body' => 'Ya es propietario del número máximo de espacios de trabajo. Elimine uno o pida que lo inviten a un espacio de trabajo existente.',
            ],
        ],
        'preview' => [
            'company_placeholder' => 'Su empresa',
        ],
        'validation' => [
            'context_required' => 'Elija al menos una opción para el caso de uso seleccionado.',
            'context_invalid' => 'Una de las opciones elegidas no pertenece al caso de uso seleccionado.',
            'referral_detail_invalid' => 'El asistente elegido no pertenece a la fuente seleccionada.',
        ],
    ],
    'setup_workspace' => [
        'title' => 'Configure su espacio de trabajo',
        'email' => [
            'heading' => 'Comience con las personas con quienes ya intercambia correos',
            'description' => 'Conecte su buzón y Relaticle construye su CRM mientras termina la configuración.',
            'google' => 'Continuar con Google',
            'microsoft' => 'Continuar con Microsoft',
            'privacy' => 'Solo usted puede leer sus correos electrónicos. Después usted decide qué puede ver su equipo.',
            'benefit_records' => 'Personas y empresas con las que intercambia correos, agregadas por nosotros',
            'benefit_timeline' => 'Correos electrónicos y reuniones en el registro correcto',
            'benefit_send' => 'Envíe desde Relaticle con su propia dirección',
            'skip' => 'Agregaré las personas y empresas yo mismo',
        ],
        'sharing' => [
            'heading' => 'Decida qué puede ver su equipo',
            'syncing' => 'Su buzón se está sincronizando.',
            'description' => 'Solo usted puede leer sus correos electrónicos. Elija cómo se muestran a los demás miembros.',
            'connected' => 'Conectado',
            'footer' => 'Puede cambiar esto en cualquier momento en la configuración.',
        ],
        'invite' => [
            'heading' => 'Invite a su equipo',
            'description' => 'Agregue a las personas con las que trabaja. Recibirán un correo electrónico con un enlace para unirse.',
            'emails_label' => 'Direcciones de correo electrónico',
            'emails_placeholder' => 'maya@acme.com, leo@acme.com',
            'helper' => 'Pegue varias a la vez. Todos reciben el mismo rol.',
            'invalid_emails' => 'Revise estas direcciones: :emails',
            'link_label' => 'O comparta un enlace de invitación',
            'copy_link' => 'Copiar enlace',
            'create_link' => 'Crear enlace',
            'send_and_start' => 'Enviar invitaciones y comenzar',
            'not_sent' => [
                'title' => 'Algunas invitaciones no se enviaron',
                'body' => 'Su espacio de trabajo está listo. Revise Miembros en la configuración e invite a quien falte.',
            ],
        ],
        'preview' => [
            'people' => 'Personas',
            'person' => 'Persona',
            'company' => 'Empresa',
            'from_mailbox' => 'Desde su buzón',
            'found' => '{0} Leyendo su buzón|{1} :count persona encontrada|[2,*] :count personas encontradas',
        ],
    ],
];
