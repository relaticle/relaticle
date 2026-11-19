<?php

declare(strict_types=1);

return [
    'label' => 'Envoyer un e-mail',

    'notifications' => [
        'no_recipients' => [
            'title' => 'Aucun destinataire valide',
            'body' => "Aucune des personnes sélectionnées n'a d'adresse e-mail.",
        ],
        'skipped' => [
            'title' => 'Certains destinataires ont été ignorés',
            'body' => "{1}1 enregistrement sélectionné n'a pas d'adresse e-mail et n'a pas été ajouté.|[2,*]:count enregistrements sélectionnés n'ont pas d'adresse e-mail et n'ont pas été ajoutés.",
        ],
    ],
];
