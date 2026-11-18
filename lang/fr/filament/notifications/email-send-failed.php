<?php

declare(strict_types=1);

return [
    'title' => '{1}E-mail non envoyé|[2,*]:count e-mails non envoyés',
    'partial_title' => 'Envoyés : :sent. Non envoyés : :failed',
    'retry' => [
        'body_one' => '« :subject » n\'a pas été envoyé. Consultez-le dans la boîte d\'envoi sous Échoués et réessayez.',
        'body_many' => 'Consultez-les dans la boîte d\'envoi sous Échoués et réessayez.',
        'action' => 'Voir les e-mails en échec',
    ],
    'reconnect' => [
        'body_one' => '« :subject » n\'a pas été envoyé, car sa boîte mail doit être reconnectée. Reconnectez-la, puis relancez l\'envoi depuis la boîte d\'envoi.',
        'body_many' => 'Leur boîte mail doit être reconnectée. Reconnectez-la, puis relancez leur envoi depuis la boîte d\'envoi.',
        'action' => 'Reconnecter la boîte mail',
    ],
    'subscribe' => [
        'body_one' => '« :subject » n\'a pas été envoyé, car :workspace est suspendu. Une fois l\'espace de travail rouvert, relancez l\'envoi depuis la boîte d\'envoi.',
        'body_many' => 'Ils n\'ont pas été envoyés, car :workspace est suspendu. Une fois l\'espace de travail rouvert, relancez leur envoi depuis la boîte d\'envoi.',
        'action' => 'Voir la facturation',
    ],
    'reasons' => [
        'mailbox_needs_reconnect' => 'La boîte mail doit être reconnectée avant l\'envoi de cet e-mail.',
        'workspace_paused' => 'L\'espace de travail était suspendu à l\'heure d\'envoi prévue, donc l\'e-mail n\'a pas été envoyé.',
        'agent_connection_revoked' => 'La connexion de l\'assistant qui a mis cet e-mail en file a été supprimée avant l\'heure d\'envoi, donc l\'e-mail n\'a pas été envoyé.',
        'agent_send_not_allowed' => 'Votre rôle dans cet espace de travail ne permet plus à un assistant d\'envoyer des e-mails pour vous, donc l\'e-mail n\'a pas été envoyé.',
    ],
];
