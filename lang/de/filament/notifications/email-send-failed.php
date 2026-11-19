<?php

declare(strict_types=1);

return [
    'title' => '{1}E-Mail nicht gesendet|[2,*]:count E-Mails nicht gesendet',
    'partial_title' => ':sent gesendet, :failed nicht gesendet',
    'retry' => [
        'body_one' => '„:subject“ wurde nicht gesendet. Prüfen Sie die E-Mail im Postausgang unter „Fehlgeschlagen“ und versuchen Sie es erneut.',
        'body_many' => 'Prüfen Sie die E-Mails im Postausgang unter „Fehlgeschlagen“ und versuchen Sie es erneut.',
        'action' => 'Fehlgeschlagene E-Mails ansehen',
    ],
    'reconnect' => [
        'body_one' => '„:subject“ wurde nicht gesendet, weil das Postfach neu verbunden werden muss. Verbinden Sie es neu und senden Sie die E-Mail dann aus dem Postausgang erneut.',
        'body_many' => 'Das zugehörige Postfach muss neu verbunden werden. Verbinden Sie es neu und senden Sie die E-Mails dann aus dem Postausgang erneut.',
        'action' => 'Postfach neu verbinden',
    ],
    'subscribe' => [
        'body_one' => '„:subject“ wurde nicht gesendet, weil :workspace pausiert ist. Senden Sie die E-Mail aus dem Postausgang erneut, sobald der Arbeitsbereich wieder geöffnet ist.',
        'body_many' => 'Die E-Mails wurden nicht gesendet, weil :workspace pausiert ist. Senden Sie sie aus dem Postausgang erneut, sobald der Arbeitsbereich wieder geöffnet ist.',
        'action' => 'Abrechnung ansehen',
    ],
    'reasons' => [
        'mailbox_needs_reconnect' => 'Das Postfach muss neu verbunden werden, bevor diese E-Mail gesendet werden kann.',
        'workspace_paused' => 'Der Arbeitsbereich war pausiert, als diese E-Mail fällig war. Deshalb wurde sie nicht gesendet.',
        'agent_connection_revoked' => 'Die Assistentenverbindung, die diese E-Mail eingeplant hat, wurde vor der Fälligkeit entfernt. Deshalb wurde sie nicht gesendet.',
        'agent_send_not_allowed' => 'Ihre Rolle in diesem Arbeitsbereich erlaubt es einem Assistenten nicht mehr, E-Mails für Sie zu senden. Deshalb wurde sie nicht gesendet.',
    ],
];
