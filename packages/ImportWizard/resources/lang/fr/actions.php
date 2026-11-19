<?php

declare(strict_types=1);

return [
    'start_over' => [
        'label' => 'Recommencer',
        'heading' => 'Lancer un nouvel import ?',
        'description' => 'Voulez-vous vraiment lancer un nouvel import ? Votre progression actuelle sera perdue.',
    ],
    'cancel' => 'Annuler',
    'continue' => [
        'label' => 'Continuer',
        'heading' => 'Évitez de créer des enregistrements en double',
        'submit' => 'Continuer sans association',
        'back' => 'Retour',
        'or' => ' ou ',
        'warning' => "Associez une colonne qui identifie chaque enregistrement de façon unique. Vous évitez ainsi les doublons.\n\nPour :entity, associez une colonne :fields.",
    ],
    'start_import' => [
        'heading' => 'Lancer l\'import',
        'description' => 'Voulez-vous vraiment lancer cet import ?',
        'submit' => 'Lancer l\'import',
    ],
    'download_failed_rows' => 'Télécharger les lignes en échec',
];
