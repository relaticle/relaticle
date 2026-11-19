<?php

declare(strict_types=1);

return [
    'actions' => [
        'delete_record' => 'Datensatz löschen',
    ],

    'user_menu' => [
        'profile' => 'Profil',
        'settings' => 'Einstellungen',
    ],

    'settings_layout' => [
        'back_to_app' => 'Zurück zur App',
    ],

    'impersonation' => [
        'banner' => 'Angemeldet als :name (:email) für den Support.',
        'stop' => 'Stoppen',
    ],

    'navigation_groups' => [
        'tasks' => 'Aufgaben',
    ],

    'sidebar' => [
        'resize' => 'Seitenleistegröße anpassen',
    ],

    'tables' => [
        'resize_column' => 'Spaltenbreite anpassen',
        'resize_column_hint' => 'Ziehen Sie, um die Größe anzupassen. Doppelklick, um anzupassen.',
        'column_menu' => [
            'options' => 'Spaltenoptionen',
            'options_for' => ':column Optionen',
            'edit_label' => 'Spaltenbeschriftung bearbeiten',
            'label' => 'Beschriftung',
            'label_hint' => 'Nur Sie sehen diesen Namen, bis Sie ihn in einem Layout speichern, das Sie teilen. Lassen Sie ihn leer, um den ursprünglichen zu verwenden.',
            'save' => 'Speichern',
            'move_left' => 'Links verschieben',
            'move_right' => 'Rechts verschieben',
            'pin' => 'Anheften',
            'unpin' => 'Abheften',
            'hide' => 'Spalte ausblenden',
        ],
        'layouts' => [
            'label' => 'Layouts',
            'modified' => '(geändert)',
            'mine' => 'Meine Layouts',
            'shared_with_workspace' => 'Mit Arbeitsbereich geteilt',
            'default' => 'Standard',
            'shared_label' => 'Geteilt',
            'name' => 'Name',
            'save' => 'Speichern',
            'save_as' => 'Als neues Layout speichern',
            'rename' => 'Umbenennen',
            'rename_description_other' => 'Es gehört zu :owner, der benachrichtigt wird, dass Sie es umbenannt haben.',
            'save_heading' => 'Über ":name" speichern?',
            'save_description' => 'Es gehört zu :owner, der benachrichtigt wird, dass Sie es geändert haben. Mitglieder, die es verwenden, sehen die neue Version bei ihrem nächsten Besuch.',
            'actions' => 'Aktionen für :name',
            'share' => 'Mit Arbeitsbereich teilen',
            'unshare' => 'Teilen beenden',
            'unshare_heading' => 'Teilen von ":name" beenden?',
            'unshare_description' => 'Es wird wieder zu :owner als privates Layout, und Sie werden es nicht mehr sehen. Mitglieder, die es verwenden, behalten das, was ihre Tabelle zeigt.',
            'share_heading' => ' ":name" mit dem Arbeitsbereich teilen?',
            'share_description' => 'Jeder in :workspace wird dieses Layout sehen und es anwenden können. Administratoren können es auch bearbeiten oder löschen.',
            'share_confirm' => 'Teilen',
            'standard' => 'Standard-Layout',
            'available' => ':count verfügbar',
            'search' => 'Layouts suchen',
            'save_current' => 'Aktuelle Tabelle als Layout speichern',
            'set_default' => 'Als mein Standard festlegen',
            'unset_default' => 'Als meinen Standard entfernen',
            'revert' => 'Zurücksetzen',
            'delete' => 'Layout löschen',
            'delete_heading' => '":name" löschen?',
            'delete_description' => 'Mitglieder, die dieses Layout verwenden, behalten das, was ihre Tabelle zeigt.',
            'delete_description_other' => 'Es gehört zu :owner, der benachrichtigt wird, dass Sie es gelöscht haben. Mitglieder, die es verwenden, behalten das, was ihre Tabelle zeigt.',
            'saved' => '":name" gespeichert',
            'created' => '":name" als neues Layout gespeichert',
            'deleted' => '":name" gelöscht',
            'renamed' => 'In ":name" umbenannt',
            'shared' => '":name" wird mit dem Arbeitsbereich geteilt',
            'unshared' => '":name" wird nicht mehr geteilt',
            'name_required' => 'Geben Sie dem Layout einen Namen.',
            'name_taken' => 'Sie haben bereits ein Layout mit diesem Namen.',
            'name_taken_by_owner' => ':owner hat bereits ein Layout mit diesem Namen.',
            'unavailable' => 'Dieses Layout ist nicht mehr verfügbar.',
        ],
    ],

    'bottom_navigation' => [
        'label' => 'Hauptmenü',
        'more' => 'Mehr',
    ],

    'payload_too_large' => 'Diese Änderung ist zu groß, um sie zu speichern. Kürzen Sie den Inhalt und versuchen Sie es erneut.',
];
