<?php

declare(strict_types=1);

return [
    'actions' => [
        'compose' => [
            'label' => 'Rédiger',
            'tooltip' => 'Raccourci clavier : C',
        ],
        'compose_email' => [
            'label' => 'Rédiger un e-mail',
        ],
        'undo' => [
            'label' => 'Annuler',
        ],
        'cancel_send' => [
            'label' => 'Annuler l\'envoi',
        ],
    ],
    'notifications' => [
        'queued' => [
            'title' => 'E-mail en file d\'attente',
            'body' => 'Votre e-mail est en cours d\'envoi.',
        ],
        'cancelled' => [
            'title' => 'Envoi annulé',
        ],
        'held' => [
            'title' => ':via a mis un e-mail en file d\'attente',
            'rescheduled_title' => ':via a reprogrammé un e-mail',
            'body' => '« :subject » part à :recipients dans :minutes. Espace de travail : :workspace. Annulez-le si vous ne l\'avez pas demandé.',
            'scheduled_body' => '« :subject » part à :recipients le :date. Espace de travail : :workspace. Annulez-le si vous ne l\'avez pas demandé.',
            'sent_body' => '« :subject » a été envoyé à :recipients. Espace de travail : :workspace.',
            'cancelled_body' => '« :subject » à :recipients a été annulé et n\'a pas été envoyé. Espace de travail : :workspace.',
            'failed_body' => '« :subject » à :recipients n\'a pas pu être envoyé. Espace de travail : :workspace.',
            'sending_body' => '« :subject » est en cours d\'envoi à :recipients. Espace de travail : :workspace.',
            'waiting_body' => '« :subject » à :recipients attend d\'être envoyé. Espace de travail : :workspace. Annulez-le si vous ne l\'avez pas demandé.',
            'minutes' => ':count minute|:count minutes',
        ],
        'too_late' => [
            'title' => 'Trop tard, l\'e-mail a déjà été envoyé',
        ],
        'not_found' => [
            'title' => 'Cet e-mail est introuvable',
        ],
    ],
    'fields' => [
        'template' => [
            'label' => 'Modèle',
            'placeholder' => 'Appliquer un modèle…',
        ],
        'body' => [
            'label' => 'Corps',
        ],
        'scheduled_for' => [
            'label' => 'Envoyer le',
            'helper_text' => 'Laissez vide pour envoyer avec 5 secondes pour annuler.',
        ],
        'signature' => [
            'label' => 'Signature',
            'placeholder' => 'Aucune signature',
        ],
    ],
    'sections' => [
        'settings' => [
            'description' => 'Options de confidentialité, de programmation et de signature pour cet e-mail.',
        ],
    ],
];
