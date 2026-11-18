<?php

declare(strict_types=1);

return [
    'title' => 'Richten Sie Ihren Arbeitsbereich ein',
    'opens_in_new_tab' => '(öffnet in einem neuen Tab)',
    'assistant_prompt' => 'Übertrage meine aktuellen Kunden nach Relaticle: Lege jedes Unternehmen und die Personen an, mit denen ich arbeite, und für jedes noch offene Vorhaben eine Verkaufschance.',
    'buttons' => [
        'add_client' => [
            'label' => 'Kunden hinzufügen',
            'prompt' => 'Ich möchte ein Unternehmen hinzufügen, mit dem ich arbeite, und die Personen dort. Frag mich nach den Details.',
        ],
        'show_synced' => [
            'label' => 'Synchronisierte Unternehmen anzeigen',
            'prompt' => 'Zeig mir die Unternehmen, die aus meinem Postfach synchronisiert wurden.',
        ],
        'add_outside_email' => [
            'label' => 'Jemanden hinzufügen, der nicht in meinen E-Mails ist',
            'prompt' => 'Ich möchte eine Person oder ein Unternehmen hinzufügen, die nicht in meinen E-Mails vorkommen. Frag mich nach den Details.',
        ],
        'upload_file' => [
            'label' => 'Meine Datei hochladen',
        ],
        'connect_assistant' => [
            'label' => 'Claude oder ChatGPT verbinden',
            'prompt' => 'Wie verbinde ich Claude oder ChatGPT mit diesem Arbeitsbereich?',
        ],
        'pick_first' => [
            'label' => 'Auswählen, wen ich zuerst bearbeite',
            'prompt' => 'Zeig mir die Unternehmen, die gerade eingetroffen sind, damit ich auswählen kann, wen ich zuerst bearbeite.',
        ],
        'later' => [
            'label' => 'Später',
            'prompt' => 'Ich wähle später aus, wen ich zuerst bearbeite.',
        ],
    ],
];
