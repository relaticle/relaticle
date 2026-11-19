<?php

declare(strict_types=1);

return [
    'title' => 'Nouvel e-mail',
    'title_mass_send' => 'Nouvel e-mail en masse',
    'opening' => 'Ouverture de l\'éditeur…',
    'draft' => 'Brouillon',
    'quoted' => [
        'hidden' => 'Le message d\'origine n\'est pas partagé avec vous.',
    ],
    'fields' => [
        'from' => 'De',
        'to' => 'À',
        'cc' => 'Cc',
        'bcc' => 'Cci',
        'subject' => 'Objet',
        'message' => 'Message',
        'signature_none' => 'Aucune signature',
        'signature_name' => 'Nom de la signature',
        'signature_content' => 'Signature',
        'signature_default' => 'Utiliser comme signature par défaut',
        'template_none' => 'Aucun modèle pour le moment',
        'subject_placeholder' => 'Ajoutez un objet',
        'body_placeholder' => 'Écrivez votre message…',
        'company_team' => 'Équipe de l\'entreprise',
        'company_team_people' => '{1}1 personne|[2,*]:count personnes',
    ],
    'toolbar' => [
        'paragraph' => 'Paragraphe',
        'alignment' => 'Alignement',
        'lists' => 'Listes',
    ],

    'mass_send' => [
        'summary' => '{0}Aucun destinataire pour le moment|{1}Envoi à 1 destinataire|[2,*]Envoi d\'un e-mail distinct à chacun des :count destinataires',
        'toggle' => 'Envoi en masse',
        'send_button' => 'Envoyer les e-mails (:count)',
        'add_recipients' => 'Ajouter des destinataires',
        'outbox_hint' => 'Le délai de livraison dépend des éléments de votre boîte d\'envoi.',
        'view_outbox' => 'Voir la boîte d\'envoi',
        'no_recipients' => 'Ajoutez au moins un destinataire avant d\'envoyer.',
    ],
    'actions' => [
        'send' => 'Envoyer l\'e-mail',
        'attach' => 'Joindre des fichiers',
        'signature' => 'Signature',
        'template' => 'Utiliser un modèle',
        'create_signature' => 'Nouvelle signature',
        'create_template' => 'Enregistrer comme modèle',
        'variable' => 'Insérer une variable',
        'remove_recipient' => 'Retirer',
        'download_attachment' => 'Télécharger la pièce jointe',
        'remove_attachment' => 'Retirer la pièce jointe',
        'uploading' => 'Téléversement…',
        'expand' => 'Plein écran',
        'shrink' => 'Quitter le plein écran',
        'minimize' => 'Réduire',
        'restore' => 'Restaurer',
        'close' => 'Fermer',
        'discard' => 'Supprimer le brouillon',
        'grant_send' => [
            'label' => 'Accorder l\'autorisation',
        ],
    ],
    'grant_send' => [
        'heading' => 'Relaticle ne peut pas encore envoyer depuis :email.',
        'heading_generic' => 'Relaticle ne peut pas encore envoyer depuis cette boîte mail.',
        'description' => 'Accordez l\'autorisation d\'envoi pour corriger cela.',
    ],
    'notifications' => [
        'mass_queued' => [
            'title' => 'E-mail en masse en file d\'attente',
            'body' => '{1}Envoi à 1 destinataire.|[2,*]Envoi à :count destinataires.',
        ],
        'queued' => ['title' => 'E-mail en file d\'attente pour envoi'],
        'outbox_full' => ['title' => 'Impossible de mettre l\'e-mail en file d\'attente'],
        'signature_created' => ['title' => 'Signature créée'],
        'template_created' => ['title' => 'Modèle enregistré'],
        'attachment_too_large' => [
            'title' => 'Certains fichiers étaient trop volumineux',
            'body' => 'Non joints : :files. Chaque fichier doit faire moins de :max, et l\'ensemble des pièces jointes moins de :total.',
        ],
        'attachment_empty' => [
            'title' => 'Certains fichiers étaient vides',
            'body' => 'Non joints : :files. Un fichier vide n\'a rien à envoyer.',
        ],
        'attachment_type_not_allowed' => [
            'title' => 'Certains fichiers n\'ont pas pu être joints',
            'body' => 'Non joints : :files. Joignez un PDF, un fichier Office ou OpenDocument, un CSV, un texte, une invitation d\'agenda, une image ou un zip.',
        ],
        'attachment_too_large_for_provider' => 'Chaque fichier doit faire moins de :max, et l\'ensemble des pièces jointes moins de :total après encodage.',
        'attachment_unavailable' => [
            'title' => 'Certaines pièces jointes n\'ont pas pu être incluses',
            'body' => 'Non joints : :files. Téléchargez-les depuis l\'e-mail d\'origine et ajoutez-les ici si vous en avez encore besoin.',
        ],
        'send_attachment_unavailable' => [
            'title' => 'Impossible d\'inclure certaines pièces jointes',
            'body' => 'L\'e-mail n\'a pas été envoyé. Ces fichiers n\'ont pas pu être téléchargés : :files. Retirez-les ou réessayez l\'envoi.',
        ],
        'draft_account_disconnected' => [
            'title' => 'La boîte mail d\'origine n\'est plus connectée',
            'body' => 'La boîte mail depuis laquelle ce brouillon a été écrit n\'est plus connectée. Il a donc été basculé sur votre boîte mail par défaut. Vérifiez l\'expéditeur avant d\'envoyer.',
        ],
        'draft_saved' => [
            'title' => 'Brouillon enregistré',
        ],
    ],
    'validation' => [
        'body_required' => 'Écrivez un message avant d\'envoyer.',
    ],
];
