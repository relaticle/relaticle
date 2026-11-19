<?php

declare(strict_types=1);

return [
    'navigation_label' => 'E-mails',
    'account_filter' => [
        'label' => 'Boîte mail',
    ],
    'tabs' => [
        'drafts' => 'Brouillons',
        'outbox' => 'Boîte d\'envoi',
        'templates' => 'Modèles',
    ],
    'drafts' => [
        'columns' => [
            'subject' => 'Brouillon',
            'last_edited' => 'Dernière modification',
        ],
        'actions' => [
            'open' => 'Continuer la rédaction',
            'delete' => 'Supprimer le brouillon',
            'delete_selected' => 'Supprimer les brouillons',
        ],
        'empty' => [
            'heading' => 'Aucun brouillon',
            'description' => 'Les messages que vous fermez avant l\'envoi sont enregistrés ici.',
        ],
        'notifications' => [
            'deleted' => 'Brouillon supprimé',
            'bulk_deleted' => '{1}1 brouillon supprimé|[2,*]:count brouillons supprimés',
        ],
    ],
    'outbox' => [
        'empty' => [
            'heading' => 'Aucun e-mail dans la boîte d\'envoi',
            'description' => 'Les e-mails en file d\'attente et programmés apparaissent ici jusqu\'à leur envoi.',
        ],
    ],
    'search' => [
        'placeholder' => 'Rechercher des e-mails…',
        'clear' => 'Effacer la recherche',
    ],
    'subject' => [
        'none' => '(sans objet)',
        'hidden' => '(objet masqué)',
    ],
    'pagination' => [
        'previous' => 'Précédent',
        'next' => 'Suivant',
        'range' => ':first–:last sur :total',
    ],
    'list_empty' => [
        'no_results' => 'Aucun résultat pour « :search »',
        'all' => 'Aucun e-mail',
        'sent' => 'Aucun e-mail envoyé',
        'inbox' => 'Aucun e-mail reçu',
    ],
    'list_row' => [
        'via' => 'via :name',
        'via_mailboxes' => '{1}via 1 boîte mail|[2,*]via :count boîtes mail',
        'access_granted_via' => 'Accès accordé via',
        'timestamp_yesterday' => 'Hier, :time',
        'request_access' => 'Demander l\'accès à :name',
        'requested' => 'Demandé',
        'opening' => 'Ouverture…',
    ],
    'pending_access' => [
        'heading' => '{1}1 demande d\'accès en attente|[2,*]:count demandes d\'accès en attente',
        'unknown_user' => 'Membre inconnu',
        'approve' => 'Approuver',
        'deny' => 'Refuser',
    ],
    'compose' => [
        'label' => 'Rédiger',
        'notifications' => [
            'queued' => [
                'title' => 'E-mail en file d\'attente pour envoi',
                'body' => 'Votre e-mail est en cours d\'envoi.',
            ],
        ],
    ],
    'privacy_gate' => [
        'metadata_only' => [
            'heading' => 'Le corps et l\'objet de l\'e-mail sont restreints',
            'description' => 'Vous voyez les participants et la date. Demandez l\'accès pour voir l\'objet et le corps.',
        ],
        'subject_only' => [
            'heading' => 'Le corps de l\'e-mail est restreint',
            'description' => 'Vous voyez l\'objet. Le corps complet de l\'e-mail est masqué. Demandez l\'accès pour en voir plus.',
        ],
        'private' => [
            'heading' => 'Cet e-mail est privé',
            'description' => 'Seul le propriétaire de l\'e-mail peut voir ce contenu.',
        ],
        'request_hint' => 'Sélectionnez :action ci-dessus pour demander plus d\'accès.',
        'request_pending' => 'Votre demande d\'accès est en attente.',
    ],
    'reader' => [
        'heading' => 'Voir l\'e-mail',
        'internal' => 'E-mail interne. Seuls les membres de l\'espace de travail peuvent le voir.',
        'unknown_sender' => '(expéditeur inconnu)',
        'no_body' => '(aucun corps de message)',
        'attachments' => [
            'unnamed' => 'Fichier sans nom',
            'processing' => 'traitement…',
        ],
    ],
    'back_to_list' => 'Retour à la liste',
    'recipients' => [
        'from' => 'De',
        'to' => 'à',
        'to_heading' => 'À',
        'cc' => 'cc',
        'cc_heading' => 'Cc',
        'more' => '{1}et 1 autre|[2,*]et :count autres',
        'details' => 'Expéditeur et destinataires',
    ],
    'row_actions' => [
        'label' => 'Actions sur l\'e-mail',
    ],
    'mark_all_read' => [
        'label' => 'Tout marquer comme lu',
    ],
    'reply_forward' => [
        'modal_headings' => [
            'reply_all' => 'Répondre à tous',
            'forward' => 'Transférer',
            'reply' => 'Répondre',
        ],
        'notifications' => [
            'queued' => [
                'title' => 'E-mail en file d\'attente pour envoi',
            ],
        ],
    ],
    'sharing' => [
        'label' => 'Partage',
        'modal_heading' => 'Paramètres de partage',
        'fields' => [
            'privacy_tier' => [
                'label' => 'Qui peut voir cet e-mail ?',
            ],
            'shares' => [
                'label' => 'Partager avec des membres précis',
                'description' => 'Donnez à certains membres plus d\'accès que le paramètre ci-dessus.',
                'add_action_label' => 'Ajouter un membre',
                'new_item' => 'Nouveau membre',
            ],
            'shared_with' => [
                'label' => 'Membre',
                'placeholder' => 'Choisir un membre…',
            ],
            'tier' => [
                'label' => 'Niveau d\'accès',
            ],
        ],
        'notifications' => [
            'saved' => [
                'title' => 'Paramètres de partage enregistrés.',
            ],
        ],
    ],
    'request_access' => [
        'label' => 'Demander l\'accès',
        'fields' => [
            'tier_requested' => [
                'label' => 'Niveau d\'accès demandé',
            ],
        ],
        'notifications' => [
            'pending' => [
                'title' => 'Vous avez déjà une demande en attente pour cet e-mail.',
            ],
            'sent' => [
                'title' => 'Demande d\'accès envoyée.',
            ],
        ],
    ],
    'compose_form' => [
        'signature' => [
            'label' => 'Signature',
        ],
    ],
];
