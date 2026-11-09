<?php

declare(strict_types=1);

return [
    'failed' => 'Ces identifiants ne correspondent pas à nos enregistrements.',
    'password' => 'Le mot de passe fourni est incorrect.',
    'throttle' => 'Tentatives de connexion trop nombreuses. Veuillez essayer de nouveau dans :seconds secondes.',
    'turnstile' => [
        'required' => 'Veuillez compléter la vérification de sécurité.',
        'failed' => 'La vérification a échoué. Veuillez réessayer.',
        'unavailable' => 'La vérification est temporairement indisponible. Veuillez réessayer dans un instant.',
        'checking' => 'Vérification de votre navigateur…',
        'blocked' => 'Nous n\'avons pas pu vérifier votre navigateur. Vérifiez votre connexion et vos bloqueurs de contenu, puis réessayez.',
        'blocked_with_providers' => 'Nous n\'avons pas pu vérifier votre navigateur. Réessayez, ou continuez avec :providers ci-dessus.',
        'or' => 'ou',
        'retry' => 'Réessayer',
    ],
];
