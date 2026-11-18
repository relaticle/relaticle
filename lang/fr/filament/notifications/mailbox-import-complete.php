<?php

declare(strict_types=1);

return [
    'title' => 'Import terminé',
    'title_with_issues' => 'Import terminé avec des problèmes',
    'body' => 'Import terminé : :imported.',
    'body_with_issues' => 'Import terminé avec des problèmes : :imported. :failures',
    'imported_emails' => '{0}:count e-mail|{1}:count e-mail|[2,*]:count e-mails',
    'imported_calendar_events' => '{0}:count événement d\'agenda|{1}:count événement d\'agenda|[2,*]:count événements d\'agenda',
    'imported_with_calendar' => ':emails et :events',
    'imported_without_calendar' => ':emails',
    'failed_messages' => "{1}:count message n'a pas pu être importé|[2,*]:count messages n'ont pas pu être importés",
    'failed_calendar_events' => "{1}:count événement d'agenda n'a pas pu être importé|[2,*]:count événements d'agenda n'ont pas pu être importés",
    'calendar_did_not_finish' => 'L\'import de l\'agenda n\'a pas abouti.',
    'email_history_did_not_finish' => "Les anciens e-mails n'ont pas pu être importés.",
    'retry_success' => [
        'title' => 'Nouvelle tentative d\'import terminée',
        'body' => 'Les messages manquants de :email sont importés. :imported sont maintenant dans Relaticle.',
    ],
    'actions' => [
        'retry' => 'Réessayer',
        'open_inbox' => 'Ouvrir la boîte de réception',
    ],
    'retry' => [
        'queued' => [
            'title' => 'Nouvelle tentative en file d\'attente.',
            'body' => 'Nous relançons l\'import de ce qui a échoué. Nous vous préviendrons à la fin.',
        ],
        'unavailable' => [
            'title' => 'Nouvelle tentative indisponible',
            'body' => 'Aucun import en échec ne peut être relancé pour cet import.',
        ],
    ],
    'mail' => [
        'subject' => 'L\'import de votre boîte mail est terminé',
        'subject_with_issues' => 'L\'import de votre boîte mail est terminé avec des problèmes',
        'retry_subject' => 'La nouvelle tentative d\'import de votre boîte mail a réussi',
        'greeting' => 'Bonjour :name,',
        'line' => 'Import terminé depuis :email : :imported. Les nouveaux e-mails continueront de se synchroniser automatiquement.',
        'line_with_issues' => 'Import terminé avec des problèmes depuis :email : :imported. :failures',
        'retry_line' => 'Nous avons importé les messages manquants de :email. :imported sont maintenant dans Relaticle.',
    ],
];
