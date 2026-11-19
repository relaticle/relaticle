<?php

declare(strict_types=1);

return [
    'create_workspace' => [
        'label' => 'Créer un espace de travail',
        'steps' => [
            'workspace' => 'Espace de travail',
            'attribution' => 'Provenance',
        ],
        'actions' => [
            'continue' => 'Continuer',
            'get_started' => 'Commencer',
            'cancel' => 'Annuler',
            'back' => 'Retour',
        ],

        'headings' => [
            'workspace' => 'Créez votre espace de travail',
            'attribution' => 'Comment avez-vous entendu parler de nous ?',
            'attribution_description' => 'Indiquez ci-dessous où vous avez découvert Relaticle. Cette étape est facultative.',
            'use_case' => 'Aidez-nous à personnaliser votre espace de travail',
            'use_case_description' => 'Relaticle vous permet de construire exactement le CRM dont vous avez besoin, aussi complexe soit-il.',
            'use_case_hint' => 'Décrivez votre cas d\'usage et nous préparons un modèle de pipeline adapté.',
        ],
        'form' => [
            'your_name' => [
                'label' => 'Votre nom',
                'placeholder' => 'Jeanne Dupont',
            ],
            'company_logo' => [
                'label' => 'Logo de l\'entreprise',
            ],
            'workspace_name' => [
                'label' => 'Nom de l\'entreprise',
                'placeholder' => 'Saisissez le nom de votre entreprise',
            ],
            'workspace_handle' => [
                'label' => 'Identifiant de l\'espace de travail',
                'placeholder' => 'mon-espace',
                'helper_text' => 'Seuls les lettres minuscules, les chiffres et les tirets sont autorisés.',
            ],
            'use_case_label' => 'Pour quoi utiliserez-vous Relaticle ?',
            'use_case_validation_attribute' => 'cas d\'usage',
            'use_case_context_label' => 'Choisissez ce qui vous correspond.',
            'use_case_context_validation_attribute' => 'détails du cas d\'usage',
            'other_use_case_label' => 'Que suivrez-vous ?',
            'other_use_case_placeholder' => 'Candidats, donateurs, acheteurs en gros',
            'other_use_case_validation_attribute' => 'ce que vous suivez',
            'referral_detail_label' => 'De quel assistant s\'agissait-il ?',
            'referral_prompt_label' => 'Que lui avez-vous demandé ?',
            'referral_prompt_placeholder' => 'Un CRM open source pour une petite équipe commerciale',
            'referral_prompt_validation_attribute' => 'ce que vous avez demandé',
        ],
        'notifications' => [
            'workspace_created' => [
                'title' => 'Espace de travail créé',
                'body' => 'Votre espace de travail « :name » est prêt.',
            ],
            'workspace_limit_reached' => [
                'title' => 'Limite d\'espaces de travail atteinte',
                'body' => 'Vous possédez déjà le nombre maximal d\'espaces de travail. Supprimez-en un, ou demandez à être invité dans un espace existant.',
            ],
        ],
        'preview' => [
            'company_placeholder' => 'Votre entreprise',
        ],
        'validation' => [
            'context_required' => 'Choisissez au moins une option pour le cas d\'usage sélectionné.',
            'context_invalid' => 'Une des options choisies n\'appartient pas au cas d\'usage sélectionné.',
            'referral_detail_invalid' => 'L\'assistant choisi n\'appartient pas à la source sélectionnée.',
        ],
    ],
    'setup_workspace' => [
        'title' => 'Configurez votre espace de travail',
        'email' => [
            'heading' => 'Commencez par les personnes à qui vous écrivez déjà',
            'description' => 'Connectez votre boîte mail et Relaticle construit votre CRM pendant que vous terminez la configuration.',
            'google' => 'Continuer avec Google',
            'microsoft' => 'Continuer avec Microsoft',
            'privacy' => 'Vous seul pouvez lire vos e-mails. Vous choisirez ensuite ce que votre équipe voit.',
            'benefit_records' => 'Les personnes et entreprises à qui vous écrivez, ajoutées pour vous',
            'benefit_timeline' => 'E-mails et réunions sur le bon enregistrement',
            'benefit_send' => 'Envoyez depuis Relaticle avec votre propre adresse',
            'skip' => "J'ajouterai moi-même les personnes et entreprises",
        ],
        'sharing' => [
            'heading' => 'Choisissez ce que votre équipe voit',
            'syncing' => 'Votre boîte mail se synchronise.',
            'description' => 'Vous seul pouvez lire vos e-mails. Choisissez comment ils apparaissent aux autres membres.',
            'connected' => 'Connecté',
            'footer' => 'Vous pouvez modifier ce choix à tout moment dans les paramètres.',
        ],
        'invite' => [
            'heading' => 'Invitez votre équipe',
            'description' => 'Ajoutez les membres avec qui vous travaillez. Ils reçoivent un e-mail avec un lien pour rejoindre.',
            'emails_label' => 'Adresses e-mail',
            'emails_placeholder' => 'maya@acme.com, leo@acme.com',
            'helper' => 'Collez-en plusieurs à la fois. Tous reçoivent le même rôle.',
            'invalid_emails' => 'Vérifiez ces adresses : :emails',
            'send_and_start' => 'Envoyer les invitations et commencer',
            'not_sent' => [
                'title' => 'Certaines invitations n\'ont pas été envoyées',
                'body' => 'Votre espace de travail est prêt. Consultez Membres dans les paramètres et invitez les membres manquants.',
            ],
        ],
        'preview' => [
            'people' => 'Personnes',
            'person' => 'Personne',
            'company' => 'Entreprise',
            'from_mailbox' => 'Depuis votre boîte mail',
            'found' => '{0} Lecture de votre boîte mail|{1} :count personne trouvée|[2,*] :count personnes trouvées',
        ],
    ],
];
