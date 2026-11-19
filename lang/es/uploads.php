<?php

declare(strict_types=1);

return [
    'logo' => [
        'description' => 'PNG, JPEG o WebP, hasta :max.',
        'upload' => 'Subir',
        'replace' => 'Reemplazar',
        'remove' => 'Quitar',
        'failed' => 'La carga falló. Inténtelo de nuevo.',
    ],
    'errors' => [
        'busy' => 'La carga se está procesando. Inténtelo de nuevo en unos instantes.',
        'too_large' => 'El archivo supera los :max MB.',
        'mime_not_allowed' => 'No se aceptan archivos de tipo :mime. Permitidos: :allowed.',
        'extension_missing' => 'Agregue una extensión al nombre del archivo. Permitidas: :allowed.',
        'unreachable' => 'No se pudo obtener la URL.',
        'url_not_allowed' => 'Solo se pueden obtener URL https públicas en el puerto 443.',
        'not_found' => 'No se encontró la carga o ya venció.',
        'rate_limited' => 'Se alcanzó el límite de cargas: 60 por hora por espacio de trabajo. Inténtelo de nuevo más tarde.',
        'invalid_base64' => 'No se pudo decodificar el contenido base64.',
        'no_source' => 'Indique exactamente uno de estos: source_url, base64 con filename o upload_id.',
        'invalid_retention' => 'El período de retención debe ser de al menos una hora.',
    ],
];
