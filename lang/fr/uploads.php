<?php

declare(strict_types=1);

return [
    'logo' => [
        'description' => 'PNG, JPEG ou WebP, jusqu\'à :max.',
        'upload' => 'Téléverser',
        'replace' => 'Remplacer',
        'remove' => 'Retirer',
        'failed' => 'Le téléversement a échoué. Réessayez.',
    ],
    'errors' => [
        'busy' => 'Le téléversement est en cours de traitement. Réessayez dans un instant.',
        'too_large' => 'Le fichier dépasse :max Mo.',
        'mime_not_allowed' => 'Les fichiers de type :mime ne sont pas acceptés. Types autorisés : :allowed.',
        'extension_missing' => 'Ajoutez une extension au nom du fichier. Autorisées : :allowed.',
        'not_found' => 'Le téléversement est introuvable ou a expiré.',
        'rate_limited' => 'Limite de téléversements atteinte : 60 téléversements par heure et par espace de travail. Réessayez plus tard.',
        'invalid_base64' => 'Impossible de décoder le contenu base64.',
        'no_source' => 'Indiquez exactement l\'un de base64 avec filename, ou upload_id.',
        'invalid_retention' => 'La durée de conservation doit être d\'au moins une heure.',
    ],
];
