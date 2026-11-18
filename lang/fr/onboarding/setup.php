<?php

declare(strict_types=1);

return [
    'title' => 'Configurez votre espace de travail',
    'opens_in_new_tab' => '(s\'ouvre dans un nouvel onglet)',
    'assistant_prompt' => 'Transférez mes clients actuels dans Relaticle : ajoutez chaque entreprise et les personnes avec qui je travaille, et une opportunité pour chaque vente encore en cours.',
    'buttons' => [
        'add_client' => [
            'label' => 'Ajouter un client',
            'prompt' => 'Je veux ajouter une entreprise avec laquelle je travaille et les personnes qui y sont. Demandez-moi les détails.',
        ],
        'show_synced' => [
            'label' => 'Voir mes entreprises synchronisées',
            'prompt' => 'Montrez-moi les entreprises synchronisées depuis ma boîte mail.',
        ],
        'add_outside_email' => [
            'label' => 'Ajouter quelqu\'un absent de mes e-mails',
            'prompt' => 'Je veux ajouter une personne ou une entreprise qui n\'est pas dans mes e-mails. Demandez-moi les détails.',
        ],
        'upload_file' => [
            'label' => 'Téléverser mon fichier',
        ],
        'connect_assistant' => [
            'label' => 'Connecter Claude ou ChatGPT',
            'prompt' => 'Comment connecter Claude ou ChatGPT à cet espace de travail ?',
        ],
        'pick_first' => [
            'label' => 'Choisir par qui commencer',
            'prompt' => 'Montrez-moi les entreprises qui viennent d\'arriver pour que je choisisse par qui commencer.',
        ],
        'later' => [
            'label' => 'Plus tard',
            'prompt' => 'Je choisirai plus tard par qui commencer.',
        ],
    ],
];
