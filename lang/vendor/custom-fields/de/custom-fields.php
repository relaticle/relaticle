<?php

declare(strict_types=1);

return [
    'heading' => [
        'title' => 'Benutzerdefinierte Attribute',
        'description' => 'Erstellen und verwalten Sie benutzerdefinierte Attribute, um zusätzliche Daten zu Ihren Einträgen zu erfassen.',
    ],
    'nav' => [
        'label' => 'Benutzerdefinierte Attribute',
        'group' => 'Benutzerdefinierte Attribute',
    ],
    'section' => [
        'form' => [
            'contains_system_fields_cannot_delete' => 'Abschnitte mit systemdefinierten Attributen können nicht gelöscht werden.',
        ],
        'notifications' => [
            'duplicate_field_code' => 'Dieser Abschnitt enthält bereits ein Attribut mit diesem Code. Benennen Sie eines der beiden um, bevor Sie es hierher verschieben.',
        ],
    ],
    'field' => [
        'form' => [
            'name_helper_text' => 'Die Bezeichnung des Attributs in Tabellen und Formularen.',
            'code_helper_text' => 'Eindeutiger Code, der dieses Attribut in der gesamten Ressource identifiziert.',
            'description_position_options' => [
                'below' => 'Unter dem Attribut',
                'above' => 'Über dem Attribut',
            ],
            'encrypted_help' => 'Wenn aktiviert, werden die Werte dieses Attributs verschlüsselt gespeichert.',
            'visible_in_list_help' => 'Dieses Attribut in Tabellenlisten anzeigen.',
            'list_toggleable_hidden_hint' => 'Wenn aktiviert, ist dieses Attribut in der Listenansicht standardmäßig ausgeblendet, kann aber von Nutzern eingeblendet werden.',
            'visible_in_view_help' => 'Dieses Attribut auf Detailseiten anzeigen.',
            'add_field' => 'Attribut hinzufügen',
            'search_placeholder' => 'Attribute suchen...',
            'type_search_prompt' => 'Attributtypen suchen...',
            'type_no_results' => 'Keine Attributtypen gefunden',
            'system_defined_cannot_delete' => 'Systemdefinierte Attribute können nicht gelöscht werden.',
            'allow_multiple_help' => 'Wenn aktiviert, können Nutzer mehrere Werte für dieses Attribut eingeben.',
        ],
        'actions' => [
            'edit_modal_heading' => 'Attribut bearbeiten',
            'delete_modal_heading' => 'Attribut löschen',
            'delete_modal_description' => 'Möchten Sie dieses Attribut wirklich löschen? Das lässt sich nicht rückgängig machen.',
        ],
    ],
    'empty_states' => [
        'sections' => [
            'description' => 'Erstellen Sie Ihren ersten Abschnitt, um Ihre benutzerdefinierten Attribute zu ordnen und zu gruppieren.',
        ],
        'fields' => [
            'description' => 'Ziehen Sie Attribute hierher oder klicken Sie auf die Schaltfläche unten, um Ihr erstes Attribut hinzuzufügen.',
        ],
        'fields_no_sections' => [
            'heading' => 'Noch keine benutzerdefinierten Attribute',
            'description' => 'Klicken Sie auf die Schaltfläche unten, um Ihr erstes benutzerdefiniertes Attribut hinzuzufügen.',
        ],
        'search_no_results' => [
            'heading' => 'Keine passenden Attribute',
            'description' => 'Keine Attribute entsprechen Ihrer Suche.',
        ],
    ],
    'common' => [
        'active_fields' => 'Aktive Attribute',
        'inactive_fields' => 'Inaktive Attribute',
    ],
    'enums' => [
        'condition_source' => [
            'custom_field' => 'Benutzerdefiniertes Attribut',
        ],
        'date_anchor' => [
            'custom_field' => 'Ein anderes Attribut',
        ],
        'description_position' => [
            'below' => 'Unter dem Attribut',
            'above' => 'Über dem Attribut',
        ],
        'entity_feature' => [
            'labels' => [
                'custom_fields' => 'Benutzerdefinierte Attribute',
            ],
            'descriptions' => [
                'custom_fields' => 'Der Eintragstyp kann benutzerdefinierte Attribute haben',
                'lookup_source' => 'Der Eintragstyp kann als Nachschlagequelle für Auswahlattribute dienen',
                'scoped_management' => 'Die benutzerdefinierten Attribute des Eintragstyps werden pro übergeordnetem Eintrag verwaltet, nicht auf der globalen Verwaltungsseite',
            ],
        ],
    ],
    'date_constraint' => [
        'reference_field' => 'Referenzattribut',
    ],
];
