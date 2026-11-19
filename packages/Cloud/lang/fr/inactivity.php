<?php

declare(strict_types=1);

return [
    'notice' => [
        'subject' => 'Nous supprimons désormais les espaces de travail d\'essai en pause après 60 jours',
        'preheader' => ':workspace est en sécurité jusqu\'au :date. Choisissez une offre ou connectez-vous pour le conserver plus longtemps.',
        'heading' => 'Nous supprimons désormais les espaces de travail d\'essai en pause après 60 jours',
        'body' => ':workspace est en pause depuis la fin de son essai. À partir d\'aujourd\'hui, nous supprimons un espace de travail d\'essai en pause après 60 jours sans connexion.',
        'date' => 'Vos 60 jours commencent aujourd\'hui. Si personne ne se connecte et que personne ne choisit d\'offre, nous supprimerons :workspace le :date.',
        'warn' => 'Nous vous enverrons un e-mail :warning jours et :reminder jours avant cette date.',
        'cta' => 'Choisir une offre',
    ],
    'warning' => [
        'subject' => ':workspace sera supprimé le :date',
        'preheader' => 'Personne ne s\'est connecté depuis le :since. Choisissez une offre ou connectez-vous pour le conserver.',
        'heading' => ':workspace sera supprimé le :date',
        'paused' => ':workspace est en pause, et personne ne s\'est connecté depuis le :since.',
        'rule' => 'Nous supprimons un espace de travail d\'essai en pause après 60 jours sans connexion. Le :date, nous supprimerons :workspace et chaque enregistrement qu\'il contient. Cette action est irréversible.',
        'keep' => 'Pour conserver l\'espace de travail, choisissez une offre avant cette date.',
        'cta' => 'Choisir une offre',
        'sign_in' => 'Se connecter le conserve aussi pour 60 jours de plus.',
    ],
    'reminder' => [
        'subject' => ':workspace sera supprimé le :date',
        'preheader' => 'Ceci est le dernier rappel. Choisissez une offre ou connectez-vous pour le conserver.',
        'heading' => ':workspace sera supprimé le :date',
        'body' => 'Nous supprimerons :workspace et chaque enregistrement qu\'il contient le :date. Cette action est irréversible.',
        'keep' => 'Choisissez une offre ou connectez-vous avant cette date pour le conserver.',
    ],
    'deleted' => [
        'subject' => ':workspace a été supprimé',
        'preheader' => 'Il était en pause depuis 60 jours sans aucune connexion.',
        'heading' => ':workspace a été supprimé',
        'body' => ':workspace était en pause depuis 60 jours sans aucune connexion, nous l\'avons donc supprimé avec ses enregistrements.',
        'restart' => 'Vous pouvez vous connecter à tout moment et créer un nouvel espace de travail.',
    ],
];
