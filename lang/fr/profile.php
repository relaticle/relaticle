<?php

declare(strict_types=1);

return [
    'form' => [
        'name' => [
            'label' => 'Nom',
        ],
        'email' => [
            'label' => 'E-mail',
        ],
        'profile_photo' => [
            'label' => 'Photo',
        ],
        'timezone' => [
            'label' => 'Fuseau horaire',
            'helper_text' => 'Les dates et heures de toute l\'application s\'affichent dans ce fuseau horaire.',
            'placeholder' => 'Sélectionner un fuseau horaire',
        ],
        'locale' => [
            'label' => 'Langue',
            'helper_text' => 'L\'application, les e-mails et les notifications utilisent cette langue.',
            'placeholder' => 'Sélectionner une langue',
        ],
        'current_password' => [
            'label' => 'Mot de passe actuel',
        ],
        'new_password' => [
            'label' => 'Nouveau mot de passe',
        ],
        'confirm_password' => [
            'label' => 'Confirmer le mot de passe',
        ],
        'password' => [
            'label' => 'Mot de passe',
            'throttled' => 'Trop de tentatives. Veuillez réessayer dans :seconds secondes.',
        ],
    ],

    'sections' => [
        'update_profile_information' => [
            'title' => 'Informations du profil',
            'description' => 'Mettez à jour les informations de profil et l\'adresse e-mail de votre compte.',
        ],
        'update_password' => [
            'title' => 'Mettre à jour le mot de passe',
            'description' => 'Utilisez un mot de passe long et aléatoire pour sécuriser votre compte.',
        ],
        'set_password' => [
            'title' => 'Définir un mot de passe',
            'description' => 'Ajoutez un mot de passe à votre compte pour pouvoir aussi vous connecter avec votre e-mail et votre mot de passe.',
        ],
        'browser_sessions' => [
            'title' => 'Sessions du navigateur',
            'description' => 'Gérez et déconnectez vos sessions actives sur d\'autres navigateurs et appareils.',
            'notice' => 'Si nécessaire, vous pouvez vous déconnecter de toutes vos autres sessions de navigateur sur tous vos appareils. Certaines de vos sessions récentes sont listées ci-dessous, mais cette liste peut être incomplète. Si vous pensez que votre compte a été compromis, changez aussi votre mot de passe.',
            'labels' => [
                'current_device' => 'Cet appareil',
                'last_active' => 'Dernière activité',
                'unknown_device' => 'Inconnu',
            ],
        ],
        'delete_account' => [
            'title' => 'Supprimer le compte',
            'description' => 'Supprimez définitivement votre compte après un délai de grâce de 30 jours.',
            'notice' => 'Votre profil et votre compte de connexion seront définitivement supprimés après 30 jours. Les espaces de travail dont vous êtes le seul membre, y compris leurs données CRM, seront aussi supprimés. Les enregistrements des espaces de travail partagés resteront sans votre profil. Connectez-vous avant la date de suppression pour l\'annuler.',
            'confirm_email_label' => 'Saisissez l\'e-mail de votre compte pour confirmer',
            'confirm_email_mismatch' => 'Cela ne correspond pas à l\'e-mail de votre compte.',
        ],
        'mfa' => [
            'title' => 'Authentification à deux facteurs',
            'description' => 'Exigez un code d\'authentification lorsque vous vous connectez sans clé d\'accès.',
            'status_enabled' => 'L\'authentification à deux facteurs est activée.',
            'status_disabled' => 'L\'authentification à deux facteurs est désactivée. Activez-la pour protéger votre compte si votre mot de passe est volé.',
            'enable' => 'Activer',
            'disable' => 'Désactiver',
            'identity_description' => 'Confirmez votre identité avant de configurer votre application d\'authentification.',
            'continue' => 'Continuer',
            'setup_heading' => 'Configurez votre application d\'authentification',
            'verify' => 'Vérifier et activer',
            'recovery_save_heading' => 'Enregistrez vos codes de récupération',
            'recovery_saved' => 'J\'ai enregistré mes codes de récupération',
            'enable_heading' => 'Activer l\'authentification à deux facteurs',
            'enable_description' => 'Scannez le code avec votre application d\'authentification, puis saisissez le code à six chiffres qu\'elle affiche.',
            'disable_heading' => 'Désactiver l\'authentification à deux facteurs',
            'disable_description' => 'Un code ne vous sera plus demandé lorsque vous vous connectez.',
            'scan_hint' => 'Scannez ce code avec votre application d\'authentification.',
            'manual_hint' => 'Impossible de scanner ? Saisissez plutôt cette clé :',
            'code_label' => 'Code à six chiffres',
            'code_invalid' => 'Ce code est incorrect. Vérifiez votre application d\'authentification et réessayez.',
            'confirm' => 'Confirmer',
            'enabled_notification' => 'L\'authentification à deux facteurs est activée.',
            'disabled_notification' => 'L\'authentification à deux facteurs est désactivée.',
            'recovery_title' => 'Codes de récupération',
            'recovery_description' => 'Conservez-les en lieu sûr. Chacun vous connecte une fois si vous perdez votre application d\'authentification.',
            'recovery_show' => 'Afficher les codes de récupération',
            'recovery_regenerate' => 'Générer de nouveaux codes',
            'recovery_regenerated' => 'De nouveaux codes de récupération ont été générés. Les anciens ne fonctionnent plus.',
            'recovery_heading' => 'Vos codes de récupération',
            'copy' => 'Copier',
            'copied' => 'Copié',
            'copy_key' => 'Copier la clé de configuration',
            'copy_codes' => 'Copier tous les codes',
        ],

        'passkeys' => [
            'title' => 'Clés d\'accès',
            'description' => 'Gérez vos clés d\'accès pour vous connecter sans mot de passe.',
            'unsupported' => 'Les clés d\'accès ne sont pas prises en charge par ce navigateur.',
            'empty' => 'Aucune clé d\'accès pour le moment. Ajoutez-en une pour vous connecter sans mot de passe.',
            'added' => 'Ajoutée :time',
            'last_used' => 'Dernière utilisation :time',
            'add_passkey' => 'Ajouter une clé d\'accès',
            'add_description' => 'Enregistrez une nouvelle clé d\'accès pour cet appareil afin de vous connecter sans mot de passe.',
            'name_label' => 'Nom de la clé d\'accès',
            'name_placeholder' => 'p. ex. MacBook Pro, iPhone',
            'default_name' => 'Clé d\'accès',
            'rename' => 'Renommer',
            'save' => 'Enregistrer',
            'use_password' => 'Utiliser plutôt votre mot de passe',
            'method_hint' => 'Vous confirmerez avec Face ID, Touch ID ou votre clé d\'accès.',
            'confirmed' => 'Confirmée',
            'register' => 'Enregistrer la clé d\'accès',
            'registering' => 'Enregistrement...',
            'waiting' => 'En attente de la clé d\'accès…',
            'cancel' => 'Annuler',
            'remove' => 'Supprimer',
            'remove_confirm_title' => 'Supprimer la clé d\'accès',
            'remove_confirm' => 'Vous ne pourrez plus l\'utiliser pour vous connecter.',
        ],
    ],

    'actions' => [
        'save' => 'Enregistrer',
        'remove_photo' => 'Supprimer la photo',
        'delete_account' => 'Supprimer le compte',
        'log_out_other_browsers' => 'Déconnecter les autres sessions du navigateur',
    ],

    'notifications' => [
        'account_deletion_cancelled' => 'Suppression du compte annulée',
        'save' => [
            'success' => 'Enregistré.',
        ],
        'photo_removed' => 'Photo de profil supprimée.',
        'photo_remove_failed' => 'Impossible de supprimer votre photo de profil. Veuillez réessayer.',
        'logged_out_other_sessions' => [
            'success' => 'Toutes les autres sessions du navigateur ont été déconnectées.',
            'remembered_only' => 'Les autres appareils sont déconnectés après :minutes minutes d\'inactivité. Un appareil sur lequel Relaticle est ouvert reste connecté. Changez votre mot de passe pour le déconnecter immédiatement.',
        ],
        'delete_account_blocked' => [
            'title' => 'Suppression du compte bloquée',
        ],
        'passkey_removed' => [
            'success' => 'Clé d\'accès supprimée.',
        ],
        'passkey_renamed' => [
            'success' => 'Clé d\'accès renommée.',
        ],
        'passkey_registration_failed' => [
            'title' => 'Impossible d\'ajouter la clé d\'accès. Veuillez réessayer.',
        ],
        'passkey_confirmation_failed' => [
            'title' => 'La vérification de la clé d\'accès a échoué. Veuillez réessayer.',
        ],
        'identity_confirmation_failed' => [
            'title' => 'La confirmation de l\'identité a échoué. Veuillez réessayer.',
        ],
    ],

    'modals' => [
        'delete_account' => [
            'notice' => 'Votre profil et votre compte de connexion seront supprimés après 30 jours. Les espaces de travail dont vous êtes le seul membre seront aussi supprimés. Les enregistrements des espaces de travail partagés resteront. Connectez-vous avant la date de suppression pour l\'annuler.',
        ],
        'log_out_other_browsers' => [
            'title' => 'Déconnecter les autres sessions du navigateur',
            'description' => 'Vous serez déconnecté sur tous vos autres appareils.',
        ],
    ],

    'security' => 'Sécurité',

    'scheduled_deletion_interstitial' => [
        'heading' => 'La suppression de votre compte est programmée',
        'details' => [
            'account' => 'Votre profil et votre compte de connexion seront définitivement supprimés.',
            'deletion_date' => 'Date de suppression :',
            'workspaces' => ':count espace de travail dont vous êtes le seul membre et ses données CRM seront aussi supprimés.|:count espaces de travail dont vous êtes le seul membre et leurs données CRM seront aussi supprimés.',
            'shared_records' => 'Les enregistrements des espaces de travail partagés resteront sans votre profil.',
        ],
        'help' => 'Vous avez changé d\'avis ? Conservez votre compte pour annuler cette suppression et retrouver l\'accès.',
        'actions' => [
            'cancel_deletion' => [
                'label' => 'Conserver mon compte',
                'modal_heading' => 'Conserver votre compte ?',
                'modal_description' => 'La suppression programmée sera annulée. Vous retrouverez l\'accès à votre compte et à vos espaces de travail.',
                'modal_submit_label' => 'Oui, conserver mon compte',
            ],
            'logout' => [
                'label' => 'Se déconnecter',
            ],
        ],
    ],
];
