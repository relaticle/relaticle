<?php

declare(strict_types=1);

return [
    'form' => [
        'workspace_name' => [
            'label' => 'Nom de l\'espace de travail',
        ],
        'workspace_slug' => [
            'label' => 'Identifiant d\'URL de l\'espace de travail',
            'helper_text' => 'Uniquement des lettres minuscules, des chiffres et des tirets.',
        ],
        'emails' => [
            'label' => 'Adresses e-mail',
            'placeholder' => 'nom@entreprise.com, collegue@entreprise.com',
            'helper' => 'Séparez plusieurs adresses par une virgule, un espace ou un retour à la ligne.',
        ],
        'invite_as' => [
            'label' => 'Inviter en tant que',
        ],
        'role' => [
            'label' => 'Rôle',
        ],
        'workspace_logo' => [
            'label' => 'Logo de l\'espace de travail',
        ],
        'web_search' => [
            'label' => 'Autoriser :assistant à rechercher sur le web',
            'helper_text' => 'Les requêtes de recherche sont envoyées à la recherche web du fournisseur d\'IA.',
            'unavailable' => 'Aucun modèle de cette installation ne peut rechercher sur le web.',
        ],
    ],

    'sections' => [
        'update_workspace_name' => [
            'title' => 'Nom de l\'espace de travail',
            'description' => 'Le nom de l\'espace de travail et les informations sur son propriétaire.',
        ],
        'update_workspace_logo' => [
            'title' => 'Logo de l\'espace de travail',
            'description' => 'Votre logo apparaît dans le sélecteur d\'espace de travail, sur les invitations et sur la page d\'invitation.',
        ],
        'assistant' => [
            'description' => 'Choisissez ce que :assistant, l\'assistant IA intégré, peut faire pour les membres de cet espace de travail.',
        ],
        'invite_people' => [
            'description' => 'Chaque personne reçoit un e-mail avec un lien pour rejoindre :workspace.',
        ],
        'add_workspace_member' => [
            'title' => 'Inviter des membres',
            'description' => 'Invitez des membres par e-mail. Chaque personne invitée reçoit sa propre invitation.',
        ],
        'workspace_members' => [
            'title' => 'Membres',
            'description' => 'Tous les membres ayant accès à cet espace de travail, y compris ceux qui n\'ont pas encore accepté.',
        ],
        'delete_workspace' => [
            'title' => 'Supprimer l\'espace de travail',
            'description' => 'Programmer la suppression de cet espace de travail.',
            'notice' => 'La suppression de cet espace de travail sera programmée pour une suppression définitive après un délai de grâce de 30 jours. Vous pouvez l\'annuler à tout moment avant ce terme. Passé ce délai, toutes les ressources et données seront supprimées définitivement.',
            'scheduled_notice' => 'La suppression de cet espace de travail est programmée le :date.',
        ],
    ],

    'actions' => [
        'save' => 'Enregistrer',
        'invite_people' => 'Inviter des membres de l\'espace de travail',
        'send_invitations' => 'Envoyer les invitations',
        'close' => 'Fermer',
        'update_workspace_role' => 'Changer le rôle',
        'compare_roles' => 'Comparer les rôles',
        'compare_roles_help_link' => 'Lire le détail complet',
        'remove_workspace_member' => 'Retirer',
        'leave_workspace' => 'Quitter',
        'resend_workspace_invitation' => 'Renvoyer',
        'revoke_workspace_invitation' => 'Révoquer',
        'delete_workspace' => 'Supprimer l\'espace de travail',
        'cancel_deletion' => 'Annuler la suppression',
    ],

    'notifications' => [
        'workspace_deletion_cancelled' => [
            'success' => 'Suppression de l\'espace de travail annulée',
        ],
        'workspace_deletion_scheduled' => [
            'success' => 'Suppression de l\'espace de travail programmée le :date',
        ],
        'workspace_invitation_sent' => [
            'success' => 'Invitation envoyée.',
            'success_many' => ':count invitations envoyées.',
        ],
        'workspace_invitation_revoked' => [
            'success' => 'Invitation révoquée.',
        ],
        'workspace_member_removed' => [
            'success' => 'Vous avez retiré ce membre.',
        ],
        'leave_workspace' => [
            'success' => 'Vous avez quitté l\'espace de travail.',
        ],
        'permission_denied' => [
            'cannot_promote_to_admin' => 'Seul le propriétaire de l\'espace de travail peut accorder ou retirer l\'accès administrateur.',
            'cannot_remove_workspace_member' => 'Vous n\'avez pas l\'autorisation de retirer ce membre.',
            'cannot_delete_workspace' => 'Vous n\'avez pas l\'autorisation de supprimer cet espace de travail.',
            'cannot_cancel_workspace_deletion' => 'Vous n\'avez pas l\'autorisation d\'annuler la suppression de cet espace de travail.',
        ],
        'role_updated' => [
            'success' => 'Rôle mis à jour.',
        ],
        'resend_throttled' => 'Veuillez patienter :seconds secondes avant de renvoyer.',
        'some_invites_failed' => [
            'title' => 'Certaines invitations n\'ont pas pu être envoyées',
            'not_sent_limit' => 'non envoyée, la limite d\'invitations est atteinte',
        ],
        'invite_rate_limited' => [
            'title' => 'Trop d\'invitations envoyées',
            'body' => 'Veuillez patienter :time avant d\'envoyer d\'autres invitations.',
        ],
    ],

    'validation' => [
        'slug_too_short' => 'Le champ :attribute doit contenir au moins 3 caractères.',
        'slug_format' => 'Le champ :attribute ne peut contenir que des lettres minuscules, des chiffres et des tirets.',
        'slug_reserved' => 'Le champ :attribute est réservé et ne peut pas être utilisé.',
        'email_already_invited' => 'Cette personne a déjà été invitée dans l\'espace de travail.',
        'email_already_member' => 'Cette personne appartient déjà à l\'espace de travail.',
        'only_owner_promotes_admins' => 'Seul le propriétaire de l\'espace de travail peut accorder le rôle administrateur.',
        'no_valid_emails' => 'Saisissez au moins une adresse e-mail.',
        'too_many_invites' => 'Vous pouvez inviter jusqu\'à :max membres à la fois.',
        'managed_plan_before_deleting' => 'Cet espace de travail est sur une offre facturée par Relaticle. Contactez-nous pour mettre fin à l\'offre avant de supprimer l\'espace de travail.',
        'managed_plan_before_deleting_account' => 'Ces espaces de travail sont sur une offre facturée par Relaticle : :workspaces. Contactez-nous pour mettre fin à l\'offre avant de supprimer votre compte.',
        'remove_members_before_deleting' => 'Retirez tous les membres de ces espaces de travail, ou supprimez-les, avant de supprimer votre compte : :workspaces',
        'workspace_scheduled_for_deletion' => 'La suppression de cet espace de travail est programmée. Annulez-la pour inviter des membres.',
    ],

    'modals' => [
        'update_workspace_role' => [
            'description' => ':name (:email)',
        ],
        'leave_workspace' => [
            'notice' => 'Voulez-vous vraiment quitter cet espace de travail ?',
            'mailbox_notice' => 'Vous perdez l\'accès à cet espace de travail. Toute boîte mail que vous y avez connectée est déconnectée, et ses e-mails sont masqués aux autres membres.',
        ],
        'remove_workspace_member' => [
            'mailbox_notice' => ':name perd l\'accès à cet espace de travail. Toute boîte mail connectée ici est déconnectée, et ses e-mails sont masqués à tous.',
        ],
        'delete_workspace' => [
            'notice' => 'La suppression de l\'espace de travail sera programmée. Vous aurez 30 jours pour l\'annuler avant que toutes les données soient supprimées définitivement.',
        ],
        'cancel_deletion' => [
            'heading' => 'Annuler la suppression de l\'espace de travail ?',
            'notice' => 'L\'espace de travail et toutes ses données seront conservés.',
        ],
    ],

    'edit_workspace' => 'Paramètres de l\'espace de travail',

    'custom_field_types' => [
        'domain' => 'Domaine',
    ],

    'tabs' => [
        'general' => 'Général',
        'members' => 'Membres',
        'custom_fields' => 'Attributs personnalisés',
        'email' => 'E-mail et agenda',
        'import_history' => 'Historique des imports',
        'activity' => 'Activité',
        'billing' => 'Facturation',
    ],

    'activity' => [
        'system' => 'Système',
        'former_member' => 'Ancien membre',
        'search_placeholder' => 'Rechercher par nom d\'enregistrement',
        'record_destroyed' => 'Cet enregistrement a été supprimé définitivement.',
        'yes' => 'Oui',
        'no' => 'Non',
        'outcome' => [
            'created' => 'a créé :subject',
            'deleted' => 'a supprimé :subject',
            'restored' => 'a restauré :subject',
            'other' => 'a apporté une modification à :subject',
        ],
        'subjects' => [
            'company' => 'une entreprise',
            'people' => 'une personne',
            'opportunity' => 'une opportunité',
            'task' => 'une tâche',
            'note' => 'une note',
        ],
        'columns' => [
            'created_at' => 'Quand',
            'causer' => 'Qui',
            'source' => 'Source',
            'event' => 'Action',
            'subject_type' => 'Type',
            'record' => 'Enregistrement',
            'changes' => 'Modifications',
        ],
        'filters' => [
            'event' => 'Action',
            'subject_type' => 'Type',
            'causer' => 'Qui',
            'source' => 'Source',
            'from' => 'Du',
            'until' => 'Au',
        ],
        'events' => [
            'created' => 'Créé',
            'updated' => 'Mis à jour',
            'deleted' => 'Supprimé',
            'restored' => 'Restauré',
            'imported' => 'Importé',
            'import_failed' => 'Échec de l\'import',
        ],
        'types' => [
            'company' => 'Entreprise',
            'people' => 'Personne',
            'opportunity' => 'Opportunité',
            'task' => 'Tâche',
            'note' => 'Note',
            'custom_field' => 'Attribut personnalisé',
            'custom_field_option' => 'Option d\'attribut personnalisé',
            'import' => 'Import',
        ],
        'import_counts' => [
            'created' => 'Créés : :count',
            'updated' => 'Mis à jour : :count',
            'skipped' => 'Ignorés : :count',
            'failed' => 'En échec : :count',
        ],
        'via_import' => 'Via l\'import :file',
        'via_source' => 'Via :source',
        'empty' => [
            'heading' => 'Aucune activité pour le moment',
            'description' => 'Les modifications que vos membres apportent aux enregistrements apparaîtront ici.',
        ],
        'changes_modal' => [
            'trigger' => 'Voir toutes les modifications',
            'trigger_full' => 'Voir la modification complète',
            'close' => 'Fermer',
        ],
        'full_change' => [
            'show' => 'Afficher le texte complet',
            'hide' => 'Masquer le texte complet',
            'before' => 'Avant',
            'after' => 'Après',
        ],
        'no_results' => [
            'heading' => 'Aucun résultat pour ces filtres',
            'description' => 'Essayez un autre terme de recherche, ou élargissez la période.',
            'action' => 'Effacer les filtres',
        ],
    ],

    'roles' => [
        'unknown' => 'Inconnu',
        'owner' => [
            'label' => 'Propriétaire',
        ],
        'admin' => [
            'label' => 'Administrateur',
            'description' => 'Gère les membres, les attributs personnalisés et les paramètres e-mail. Peut supprimer des enregistrements définitivement',
        ],
        'member' => [
            'label' => 'Membre',
            'description' => 'Crée et modifie des enregistrements, et les supprime vers la corbeille',
        ],
        'viewer' => [
            'label' => 'Lecteur',
            'description' => 'Lit tout et ne modifie rien',
        ],
    ],

    'capabilities' => [
        'records' => [
            'view' => ['label' => 'Voir les enregistrements'],
            'create' => ['label' => 'Créer des enregistrements'],
            'update' => ['label' => 'Modifier les enregistrements'],
            'delete' => ['label' => 'Supprimer et restaurer des enregistrements'],
            'force_delete' => ['label' => 'Supprimer des enregistrements définitivement'],
        ],
        'data' => [
            'import' => ['label' => 'Importer des données'],
            'export' => ['label' => 'Exporter des données'],
        ],
        'members' => [
            'manage' => ['label' => 'Inviter, retirer et changer le rôle des membres'],
            'promote_admin' => ['label' => 'Promouvoir quelqu\'un administrateur'],
        ],
        'fields' => [
            'manage' => ['label' => 'Gérer les attributs personnalisés'],
        ],
        'billing' => [
            'manage' => ['label' => 'Gérer la facturation'],
        ],
        'workspace' => [
            'manage' => ['label' => 'Renommer ou supprimer l\'espace de travail'],
        ],
        'email' => [
            'manage' => ['label' => 'Gérer les paramètres e-mail de l\'espace de travail'],
            'agent_send' => ['label' => 'Envoyer des e-mails via un assistant IA'],
        ],
        'activity' => [
            'view' => ['label' => 'Voir le journal d\'activité'],
        ],
    ],

    'role_matrix' => [
        'capability_column' => 'Capacité',
        'granted' => 'Inclus',
        'not_granted' => 'Non inclus',
        'opens_in_new_tab' => '(s\'ouvre dans un nouvel onglet)',
    ],

    'table' => [
        'user' => 'Membre',
        'role' => 'Rôle',
        'status' => 'Statut',
        'search_placeholder' => 'Rechercher un nom ou un e-mail',
        'invite_pending' => 'Invitation en attente',
        'invite_expired' => 'Invitation expirée',
        'expires_in' => 'Expire dans :time',
        'expired_ago' => 'Expirée il y a :time',
        'expired' => 'Expirée',
        'no_results' => [
            'heading' => 'Personne ne correspond à cette recherche',
            'description' => 'Essayez une partie du nom, ou l\'adresse e-mail que vous avez invitée.',
        ],
    ],

    'invitation' => [
        'joining_as' => 'Vous rejoignez en tant que',
        'members' => '{1} 1 membre est déjà dans cet espace de travail|[2,*] :count membres sont déjà dans cet espace de travail',
    ],

    'deletion_banner' => [
        'message' => 'Cet espace de travail sera supprimé définitivement le :date.',
    ],

    'pending_for_user' => [
        'heading' => 'Vous avez été invité à rejoindre :workspace',
        'detail_with_inviter' => ':inviter vous a invité avec l\'accès :role.',
        'detail' => 'Vous rejoindrez avec l\'accès :role.',
        'accept' => 'Rejoindre l\'espace de travail',
        'decline' => 'Refuser',
        'declined' => 'Invitation refusée.',
    ],

    'accept' => [
        'joined' => 'Vous avez rejoint :workspace.',
        'already_member' => 'Vous êtes déjà membre de :workspace.',
        'no_longer_valid' => 'Cette invitation n\'est plus valide. Elle a peut-être été révoquée ou a expiré.',
        'email_unverified' => 'Vérifiez votre adresse e-mail avant de rejoindre un espace de travail.',
        'account_deleting' => 'Vous ne pouvez pas accepter d\'invitations tant que la suppression de votre compte est programmée.',
        'workspace_deleting' => 'La suppression de cet espace de travail est programmée et il n\'accepte pas de nouveaux membres.',
        'deleting' => [
            'heading' => ':workspace n\'accepte pas de nouveaux membres',
            'body' => 'La suppression de cet espace de travail est programmée. Demandez à la personne qui vous a invité d\'annuler la suppression, puis rouvrez l\'invitation.',
            'action' => 'Aller à mon espace de travail',
        ],
        'ready' => [
            'heading' => 'Rejoindre :workspace',
            'body_with_inviter' => ':inviter vous a invité à rejoindre :workspace avec l\'accès :role.',
            'body' => 'Vous avez été invité à rejoindre :workspace avec l\'accès :role.',
            'action' => 'Rejoindre :workspace',
            'decline' => 'Pas maintenant',
        ],
        'wrong_account' => [
            'heading' => 'Cette invitation est destinée à un autre compte',
            'body' => 'Cette invitation a été envoyée à :invited, mais vous êtes connecté en tant que :current.',
            'switch' => 'Se déconnecter et changer de compte',
            'stay' => 'Aller à mon espace de travail',
        ],
        'expired' => [
            'heading' => 'Cette invitation n\'est plus valide',
            'body' => 'Cette invitation a expiré, a déjà été utilisée ou a été remplacée par une plus récente.',
            'action' => 'Aller à mon espace de travail',
        ],
    ],
];
