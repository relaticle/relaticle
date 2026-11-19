<?php

declare(strict_types=1);

return [
    'title' => 'Boîtes mail et agenda',
    'navigation_label' => 'Boîtes mail',
    'subheading' => 'Gérez et synchronisez vos boîtes mail et agenda pour rester organisé.',
    'actions' => [
        'connect_gmail' => 'Connecter une boîte mail Google',
        'connect_azure' => 'Connecter une boîte mail Microsoft',
        'manage' => 'Gérer',
        'reconnect' => 'Reconnecter',
        'set_default' => 'Définir par défaut',
        'retry_sync' => 'Relancer la synchronisation',
        'disconnect' => 'Déconnecter la boîte mail',
        'sync_calendar' => [
            'enable_label' => 'Synchroniser l\'agenda',
            'disable_label' => 'Désactiver la synchronisation de l\'agenda',
            'enable_heading' => 'Activer la synchronisation de l\'agenda',
            'disable_heading' => 'Désactiver la synchronisation de l\'agenda',
            'disable_description' => 'Les événements de l\'agenda de cette boîte mail ne seront plus synchronisés.',
            'enable_description' => 'Vous serez redirigé vers :provider pour autoriser l\'accès à l\'agenda.',
            'fallback_provider' => 'le fournisseur',
        ],
        'sync_calendar_now' => 'Synchroniser maintenant',
        'reimport_history' => [
            'label' => 'Réimporter l\'historique',
            'heading' => 'Réimporter l\'historique de la boîte mail ?',
            'description' => 'Les e-mails et événements déjà synchronisés restent dans Relaticle. Nous créerons les personnes et entreprises manquantes selon le paramètre de création d\'enregistrements de l\'espace de travail, puis importerons les messages pas encore stockés. Cela peut prendre du temps sur une grande boîte mail.',
        ],
        'retry_failed_import' => [
            'label' => 'Réessayer',
        ],
    ],
    'settings' => [
        'sync_inbox' => [
            'label' => 'Synchroniser la boîte de réception',
            'helper_text' => 'Importez dans Relaticle les e-mails que vous recevez.',
        ],
        'sync_sent' => [
            'label' => 'Synchroniser les envoyés',
            'helper_text' => 'Importez dans Relaticle les e-mails que vous envoyez depuis cette boîte mail.',
        ],
        'hourly_send_limit' => [
            'label' => 'Limite d\'envoi par heure',
            'placeholder' => 'Par défaut : :default',
            'helper_text' => 'Laissez vide pour utiliser la valeur par défaut de l\'espace de travail.',
        ],
        'daily_send_limit' => [
            'label' => 'Limite d\'envoi par jour',
            'placeholder' => 'Par défaut : :default',
            'helper_text' => 'Laissez vide pour utiliser la valeur par défaut de l\'espace de travail.',
        ],
        'modal_heading' => 'Paramètres de la boîte mail',
        'submit_label' => 'Enregistrer',
    ],
    'notifications' => [
        'connected' => [
            'title' => 'Boîte mail connectée.',
            'body' => 'Les e-mails et réunions apparaîtront au fil de l\'import.',
        ],
        'cancelled' => [
            'title' => 'Rien n\'a été connecté. Vous pouvez connecter une boîte mail à tout moment.',
        ],
        'limit_reached' => [
            'title' => '{1}Limite de boîtes mail atteinte. Cet espace de travail peut connecter 1 boîte mail. Passez à l\'offre supérieure pour en connecter davantage.|[2,*]Limite de boîtes mail atteinte. Cet espace de travail peut connecter :count boîtes mail. Passez à l\'offre supérieure pour en connecter davantage.',
            'trial' => 'Limite de boîtes mail de l\'essai gratuit atteinte. Passez à l\'offre supérieure pour synchroniser plus de boîtes mail.',
            'upgrade' => 'Mettre à niveau',
        ],
        'calendar_sync_queued' => [
            'title' => 'Synchronisation de l\'agenda lancée.',
            'body' => 'Vos réunions seront mises à jour sur cette page à la fin de la synchronisation.',
        ],
        'disconnected' => [
            'title' => 'Boîte mail déconnectée.',
            'body' => 'La boîte mail et ses signatures ont été supprimées.',
        ],
        'default_set' => [
            'title' => 'Boîte mail par défaut mise à jour.',
            'body' => ':email est maintenant votre boîte mail d\'envoi par défaut.',
        ],
        'reimport_queued' => [
            'title' => 'Import de l\'historique en attente.',
            'body' => 'Les personnes et entreprises apparaîtront au fil de l\'import. Vous pouvez continuer à utiliser Relaticle.',
        ],
        'sync_retry_queued' => [
            'title' => 'Nouvelle tentative de synchronisation en attente.',
            'body' => 'Nous récupérons ce qui n\'a pas pu être stocké. Cette page se met à jour à la fin.',
        ],
        'retry_failed_import_queued' => [
            'title' => 'Nouvelle tentative en attente.',
            'body' => 'Nous réessayons les messages qui n\'ont pas pu être importés. Cette page se met à jour à la fin.',
        ],
        'retry_failed_import_unavailable' => [
            'title' => 'Nouvelle tentative impossible',
            'body' => 'Aucun import échoué n\'est disponible pour une nouvelle tentative.',
        ],
    ],
    'default_badge' => 'Par défaut',
    'synced_at' => 'Synchronisé :time',
    'in_sync' => 'Synchronisé',
    'sync_error' => [
        'badge' => 'Problème de synchronisation',
        'heading' => 'Certains éléments n\'ont pas pu être synchronisés',
        'reasons' => [
            'google_mail_disabled' => "Gmail n'est pas activé pour ce compte Google Workspace. Demandez à votre administrateur Google de l'activer, puis reconnectez la boîte mail.",
        ],
    ],
    'importing' => 'Synchronisation',
    'importing_percent' => ':percent %',
    'importing_count' => '{1}:count e-mail|[2,*]:count e-mails',
    'statuses' => [
        'active' => 'Actif',
        'error' => 'Problème de synchronisation',
        'disconnected' => 'Déconnecté',
        'reauth_required' => 'Reconnexion nécessaire',
    ],
    'history_import' => [
        'processed' => ':processed sur :total traités',
        'successful_jobs' => ':count importés avec succès',
    ],
    'history_import_failure' => [
        'max_attempts' => 'Ce message n\'a pas pu être stocké après plusieurs essais. Utilisez Réessayer ci-dessus. Si l\'échec persiste, attendez quelques minutes et réessayez.',
    ],
    'capabilities' => [
        'email' => 'E-mail',
        'calendar' => 'Agenda',
    ],
    'not_connected' => [
        'inbox' => [
            'heading' => 'Envoyez des e-mails dans Relaticle',
            'description' => 'Connectez votre boîte mail pour lire et répondre sans quitter Relaticle. Vous profitez aussi des envois en masse, des modèles et des pièces jointes.',
        ],
        'record' => [
            'heading' => 'Gardez les e-mails sur l\'enregistrement',
            'description' => 'Connectez votre boîte mail pour voir chaque conversation avec cet enregistrement et répondre en un clic.',
        ],
        'meetings' => [
            'heading' => 'Retrouvez vos réunions dans Relaticle',
            'description' => 'Connectez votre boîte mail pour suivre vos réunions à côté de vos enregistrements CRM.',
        ],
    ],
];
