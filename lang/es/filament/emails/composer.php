<?php

declare(strict_types=1);

return [
    'title' => 'Nuevo correo electrónico',
    'title_mass_send' => 'Nuevo envío masivo',
    'opening' => 'Abriendo el editor…',
    'draft' => 'Borrador',
    'quoted' => [
        'hidden' => 'Usted no tiene acceso al mensaje original.',
    ],
    'fields' => [
        'from' => 'De',
        'to' => 'Para',
        'cc' => 'CC',
        'bcc' => 'CCO',
        'subject' => 'Asunto',
        'message' => 'Mensaje',
        'signature_none' => 'Sin firma',
        'signature_name' => 'Nombre de la firma',
        'signature_content' => 'Firma',
        'signature_default' => 'Usar como mi firma predeterminada',
        'template_none' => 'Aún no hay plantillas',
        'subject_placeholder' => 'Agregue un asunto',
        'body_placeholder' => 'Escriba su mensaje…',
        'company_team' => 'Equipo de la empresa',
        'company_team_people' => '{1}1 persona|[2,*]:count personas',
    ],
    'toolbar' => [
        'paragraph' => 'Párrafo',
        'alignment' => 'Alineación',
        'lists' => 'Listas',
    ],

    'mass_send' => [
        'summary' => '{0}Aún no hay destinatarios|{1}Enviando a 1 destinatario|[2,*]Enviando un correo electrónico por separado a cada uno de los :count destinatarios',
        'toggle' => 'Envío masivo',
        'send_button' => 'Enviar correos electrónicos (:count)',
        'add_recipients' => 'Agregar destinatarios',
        'outbox_hint' => 'El tiempo de entrega dependerá de los elementos en su bandeja de salida.',
        'view_outbox' => 'Ver bandeja de salida',
        'no_recipients' => 'Agregue al menos un destinatario antes de enviar.',
    ],
    'actions' => [
        'send' => 'Enviar correo electrónico',
        'attach' => 'Adjuntar archivos',
        'signature' => 'Firma',
        'template' => 'Usar plantilla',
        'create_signature' => 'Nueva firma',
        'create_template' => 'Guardar como plantilla',
        'variable' => 'Insertar variable',
        'remove_recipient' => 'Quitar',
        'download_attachment' => 'Descargar adjunto',
        'remove_attachment' => 'Quitar adjunto',
        'uploading' => 'Subiendo…',
        'expand' => 'Pantalla completa',
        'shrink' => 'Salir de pantalla completa',
        'minimize' => 'Minimizar',
        'restore' => 'Restaurar',
        'close' => 'Cerrar',
        'discard' => 'Descartar borrador',
        'grant_send' => [
            'label' => 'Conceder permiso',
        ],
    ],
    'grant_send' => [
        'heading' => 'Relaticle aún no puede enviar desde :email.',
        'heading_generic' => 'Relaticle aún no puede enviar desde este buzón.',
        'description' => 'Conceda permiso de envío para solucionarlo.',
    ],
    'notifications' => [
        'mass_queued' => [
            'title' => 'Envío masivo en cola',
            'body' => '{1}Enviando a 1 destinatario.|[2,*]Enviando a :count destinatarios.',
        ],
        'queued' => ['title' => 'Correo electrónico en cola para enviar'],
        'outbox_full' => ['title' => 'No se pudo poner el correo electrónico en cola'],
        'signature_created' => ['title' => 'Firma creada'],
        'template_created' => ['title' => 'Plantilla guardada'],
        'attachment_too_large' => [
            'title' => 'Algunos archivos eran demasiado grandes',
            'body' => 'No adjuntados: :files. Cada archivo debe pesar menos de :max, y todos los adjuntos juntos menos de :total.',
        ],
        'attachment_empty' => [
            'title' => 'Algunos archivos estaban vacíos',
            'body' => 'No adjuntados: :files. Un archivo vacío no tiene nada que enviar.',
        ],
        'attachment_type_not_allowed' => [
            'title' => 'Algunos archivos no se pudieron adjuntar',
            'body' => 'No adjuntados: :files. Adjunte un PDF, un archivo de Office u OpenDocument, un CSV, un texto, una invitación de calendario, una imagen o un zip.',
        ],
        'attachment_too_large_for_provider' => 'Cada archivo debe pesar menos de :max, y todos los adjuntos juntos menos de :total después de la codificación.',
        'attachment_unavailable' => [
            'title' => 'No se pudieron incluir algunos adjuntos',
            'body' => 'No adjuntados: :files. Descárguelos del correo electrónico original y agréguelos aquí si aún los necesita.',
        ],
        'send_attachment_unavailable' => [
            'title' => 'No se pudieron incluir algunos adjuntos',
            'body' => 'El correo electrónico no se envió. No se pudieron descargar estos archivos: :files. Quítelos o intente enviarlo de nuevo.',
        ],
        'draft_account_disconnected' => [
            'title' => 'El buzón original ya no está conectado',
            'body' => 'El buzón desde el que se escribió este borrador ya no está conectado, así que se cambió a su buzón predeterminado. Verifique el remitente antes de enviar.',
        ],
        'draft_saved' => [
            'title' => 'Borrador guardado',
        ],
    ],
    'validation' => [
        'body_required' => 'Escriba un mensaje antes de enviar.',
    ],
];
