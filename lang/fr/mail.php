<?php

declare(strict_types=1);

return [
    'fallback_link' => 'Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :',

    'footer' => [
        'settings' => 'Paramètres de notification',
        'unsubscribe' => 'Se désabonner du résumé quotidien',
        'copyright' => '© :year :company',
        'reason' => [
            'owner' => 'Vous recevez cet e-mail car vous êtes propriétaire de l\'espace de travail :workspace.',
            'member' => 'Vous recevez cet e-mail car vous êtes membre de :workspace.',
            'former_member' => 'Vous recevez cet e-mail car vous étiez membre de :workspace.',
            'former_owner' => 'Vous recevez cet e-mail car vous étiez propriétaire de l\'espace de travail :workspace.',
            'digest' => 'Vous recevez cet e-mail car vous avez activé le résumé quotidien.',
            'assignee' => 'Vous recevez cet e-mail car une tâche de :workspace vous a été assignée.',
            'invitee' => 'Vous recevez cet e-mail car :email a été invité à rejoindre :workspace.',
            'contact' => 'Vous recevez cet e-mail car quelqu\'un a envoyé le formulaire de contact.',
            'account' => 'Vous recevez cet e-mail suite à une demande concernant votre compte :company.',
            'onboarding' => 'Vous recevez cet e-mail car vous avez créé un espace de travail :company.',
            'billing_contact' => 'Vous recevez cet e-mail car vous êtes le contact de facturation de :workspace.',
        ],
    ],

    'unsubscribe' => [
        'title' => 'Se désabonner',
        'heading' => 'Arrêter le résumé quotidien ?',
        'body' => 'Vous ne recevrez plus le résumé matinal des tâches à l\'adresse :email. Vous pouvez le réactiver dans les paramètres de notification.',
        'confirm' => 'Se désabonner',
        'done_heading' => 'Vous êtes désabonné',
        'done_body' => 'Le résumé quotidien est désactivé pour :email.',
        'settings' => 'Paramètres de notification',
    ],

    'trial_ending' => [
        'subject' => 'Votre essai Pro se termine le :date',
        'preheader' => ':workspace est alors mis en pause, sauf si vous choisissez une offre. Vos enregistrements restent en sécurité.',
        'preheader_grandfathered' => ':workspace repasse alors à son offre Cloud Gratuit (historique). Vos enregistrements restent en sécurité.',
        'heading' => 'Pro pour :workspace se termine le :date',
        'ends_at' => 'Votre essai Pro se termine le :datetime (heure : :zone).',
        'pauses' => 'Ensuite, :workspace est mis en pause jusqu\'à ce que vous choisissiez une offre.',
        'kept' => 'Si personne ne se connecte pendant 60 jours après cette date, nous supprimons l\'espace de travail et ses enregistrements.',
        'keeps' => 'Pro conserve tous les modèles d\'IA, :credits crédits mensuels et des limites de débit plus élevées. Un tarif unique couvre tout l\'espace de travail.',
        'grandfathered' => 'Si vous ne faites rien, :workspace repasse à son offre Cloud Gratuit (historique). Vos données restent intactes.',
        'cta' => 'Choisir une offre',
        'questions' => 'Une question ? Répondez à cet e-mail.',
    ],

    'trial_usage' => [
        'so_far' => 'Jusqu\'à présent, votre équipe a :parts.',
        'during' => 'Pendant l\'essai, votre équipe a :parts.',
        'records' => 'ajouté :count enregistrement|ajouté :count enregistrements',
        'questions' => 'posé :count question à Rela|posé :count questions à Rela',
        'both' => ':records et :questions',
    ],

    'pro_ended' => [
        'trial_ended' => [
            'subject' => 'Votre essai Pro pour :workspace est terminé',
            'heading' => 'Votre essai Pro pour :workspace est terminé',
            'preheader' => 'Vos enregistrements sont en sécurité. Choisissez une offre ou prolongez l\'essai de :days jours.',
        ],
        'extension_ended' => [
            'subject' => 'Votre essai prolongé pour :workspace est terminé',
            'heading' => 'Votre essai prolongé pour :workspace est terminé',
            'preheader' => 'Vos enregistrements sont en sécurité. Choisissez une offre pour reprendre là où vous vous étiez arrêté.',
        ],
        'subscription_ended' => [
            'subject' => 'Votre abonnement Cloud est terminé',
            'heading' => 'L\'accès Cloud pour :workspace est terminé',
        ],
        'managed_ended' => [
            'subject' => 'Votre offre :plan est terminée',
            'heading' => ':plan pour :workspace est terminé',
            'preheader' => 'Vos enregistrements sont en sécurité. Contactez-nous pour relancer :plan.',
            'grandfathered' => ':workspace est de retour sur son offre Cloud Gratuit (historique). Vos données restent intactes.',
            'restore' => 'Contactez-nous pour relancer :plan. Vos enregistrements restent tels quels.',
            'cta' => 'Contactez-nous',
            'mailbox_sync_paused' => 'La synchronisation des e-mails et de l\'agenda est aussi suspendue. Elle reprend quand :plan redémarre.',
        ],
        'trial_paused' => ':workspace est en pause. Vos enregistrements sont en sécurité.',
        'kept' => 'Si personne ne se connecte, nous supprimons :workspace le :date. Choisissez une offre avant cette date pour le conserver.',
        'extend' => 'Besoin de plus de temps ? [Prolongez votre essai de :days jours](:url) depuis la page de facturation. Vous pouvez le faire une seule fois.',
        'preheader' => 'Vos enregistrements sont en sécurité. Abonnez-vous pour rouvrir l\'espace de travail.',
        'preheader_grandfathered' => 'Votre espace de travail est de retour sur son offre Cloud Gratuit (historique).',
        'paused' => 'L\'accès Cloud à :workspace est maintenant suspendu. Vos enregistrements sont en sécurité et rien n\'a été supprimé.',
        'mailbox_sync_paused' => 'La synchronisation des e-mails et de l\'agenda est aussi suspendue. Elle reprend dès que vous vous abonnez.',
        'restore' => 'Abonnez-vous à une offre Cloud pour rouvrir l\'application, l\'API REST, le MCP server et l\'assistant IA exactement là où vous les aviez laissés.',
        'grandfathered' => ':workspace est de retour sur son offre Cloud Gratuit (historique). Vos données restent intactes et vous pouvez vous réabonner à tout moment.',
        'cta' => 'Choisir une offre',
        'cta_grandfathered' => 'Choisir une offre',
    ],

    'managed_plan_invoice' => [
        'subject' => 'Votre facture :plan de Relaticle',
        'preheader' => 'Votre facture annuelle :plan pour :workspace est prête',
        'heading' => 'Votre facture :plan est prête',
        'line' => 'Votre facture annuelle :plan pour :workspace s\'élève à :amount, à régler avant le :date. L\'offre s\'active dès son paiement.',
        'line_without_due' => 'Votre facture annuelle :plan pour :workspace s\'élève à :amount. L\'offre s\'active dès son paiement.',
        'cta' => 'Consulter et payer la facture',
    ],

    'setup_nudge' => [
        'subject' => 'Votre espace de travail vous attend',
        'preheader' => 'Une étape suffit pour que :workspace fonctionne : :step',
        'heading' => ':name, :workspace est prêt pour vos propres enregistrements',
        'step' => 'Prochaine étape : :step.',
        'cta' => 'Continuer dans :assistant',
    ],

    'task_assigned' => [
        'subject' => 'Nouvelle tâche : :title',
        'preheader' => 'Une tâche vous a été assignée dans :workspace',
        'preheader_without_workspace' => 'Une tâche vous a été assignée',
        'heading' => 'Vous avez une nouvelle tâche',
        'workspace_label' => 'Espace de travail',
        'cta' => 'Voir la tâche',
    ],

    'task_digest' => [
        'subject_both' => '[:workspace] :overdue en retard, :due à faire aujourd\'hui, :date',
        'subject_overdue' => '[:workspace] :count tâche en retard, :date|[:workspace] :count tâches en retard, :date',
        'subject_due' => '[:workspace] :count tâche à faire aujourd\'hui, :date|[:workspace] :count tâches à faire aujourd\'hui, :date',
        'preheader_more' => ':tasks et :count autres',
        'heading' => 'Bonjour :name',
        'summary' => 'Vous avez :tasks dans :workspace.',
        'summary_both' => 'Vous avez :overdue et :due dans :workspace.',
        'open_hint' => 'Sélectionnez une tâche pour l\'ouvrir dans :workspace.',
        'summary_overdue' => ':count tâche en retard|:count tâches en retard',
        'summary_due' => ':count tâche à faire aujourd\'hui|:count tâches à faire aujourd\'hui',
        'overdue' => 'En retard',
        'due_today' => 'À faire aujourd\'hui',
        'due_overdue' => ':date (en retard)',
        'due_today_chip' => 'À faire aujourd\'hui',
        'more_records' => '+:count',
        'more_tasks' => 'Voir :count tâche de plus|Voir :count tâches de plus',
        'cta' => 'Ouvrir mes tâches',
    ],

    'workspace_invitation' => [
        'subject' => ':inviter vous a invité à rejoindre :workspace',
        'subject_without_inviter' => 'Vous avez été invité à rejoindre :workspace',
        'preheader' => 'Rejoignez :workspace sur Relaticle en tant que :role',
        'heading' => 'Rejoindre :workspace',
        'line_with_inviter' => ':inviter vous a invité à rejoindre l\'espace de travail :workspace sur Relaticle avec l\'accès :role.',
        'line' => 'Vous avez été invité à rejoindre l\'espace de travail :workspace sur Relaticle avec l\'accès :role.',
        'expiry' => 'Cette invitation expire :expiry.',
        'ignore' => 'Vous n\'attendiez pas cette invitation ? Ignorez cet e-mail.',
        'cta' => 'Accepter l\'invitation',
    ],

    'workspace_deletion_scheduled' => [
        'subject' => 'La suppression de :workspace est programmée',
        'preheader' => 'Suppression le :date. Annulez à tout moment avant cette date',
        'heading' => ':workspace sera supprimé le :date',
        'removes' => 'Les personnes, entreprises, tâches, opportunités, notes et tous les autres enregistrements de :workspace sont supprimés après cette date.',
        'cancel' => 'Vous pouvez annuler depuis les paramètres de l\'espace de travail à tout moment avant cette date.',
        'cta' => 'Annuler la suppression',
    ],

    'workspace_deletion_reminder' => [
        'subject' => ':workspace sera supprimé dans :days jour|:workspace sera supprimé dans :days jours',
        'preheader' => 'Dernier rappel avant le :date',
        'heading' => ':days jour avant la suppression de :workspace|:days jours avant la suppression de :workspace',
        'final' => 'Ceci est le dernier rappel. Tout le contenu de :workspace est supprimé après le :date.',
        'cancel' => 'Vous pouvez annuler depuis les paramètres de l\'espace de travail à tout moment avant cette date.',
        'cta' => 'Annuler la suppression',
    ],

    'workspace_deletion_cancelled' => [
        'subject' => 'Suppression de :workspace annulée',
        'preheader' => 'Vos données sont en sécurité',
        'heading' => ':workspace est conservé',
        'body' => 'La suppression programmée de :workspace a été annulée. Rien n\'a été supprimé.',
        'cta' => 'Ouvrir :workspace',
    ],

    'workspace_member_removed' => [
        'subject' => 'Vous avez été retiré de :workspace',
        'preheader' => 'Vous n\'avez plus accès à cet espace de travail',
        'heading' => 'Vous avez été retiré de :workspace',
        'body' => 'Votre accès à :workspace et à ses enregistrements a pris fin. Vos autres espaces de travail ne sont pas affectés.',
        'cta' => 'Ouvrir Relaticle',
    ],

    'account_deletion_scheduled' => [
        'subject' => 'La suppression de votre compte est programmée',
        'preheader' => 'Suppression le :date. Connectez-vous pour annuler',
        'heading' => 'Votre compte sera supprimé le :date',
        'removes' => 'Votre profil et chaque espace de travail dont vous êtes propriétaire sont supprimés après cette date.',
        'cancel' => 'Vous avez changé d\'avis ? Connectez-vous avant cette date et la suppression est annulée.',
        'cta' => 'Conserver mon compte',
    ],

    'account_deletion_reminder' => [
        'subject' => 'Votre compte sera supprimé dans :days jour|Votre compte sera supprimé dans :days jours',
        'preheader' => 'Dernier rappel avant le :date',
        'heading' => ':days jour avant la suppression de votre compte|:days jours avant la suppression de votre compte',
        'final' => 'Ceci est le dernier rappel. Votre compte et ses données sont supprimés après le :date.',
        'cancel' => 'Connectez-vous avant cette date et la suppression est annulée.',
        'cta' => 'Conserver mon compte',
    ],

    'account_deletion_cancelled' => [
        'subject' => 'Votre compte est conservé',
        'preheader' => 'Suppression annulée, données intactes',
        'heading' => 'Bon retour, :name',
        'body' => 'La suppression programmée de votre compte a été annulée. Rien n\'a été supprimé.',
        'cta' => 'Ouvrir Relaticle',
    ],

    'verify_email' => [
        'subject' => 'Vérifiez votre e-mail',
        'preheader' => 'Un clic suffit pour terminer l\'inscription',
        'heading' => 'Vérifiez votre adresse e-mail',
        'body' => 'Confirmez cette adresse pour terminer la configuration de votre compte Relaticle. Ce lien expire dans :count minutes.',
        'ignore' => 'Vous ne vous êtes pas inscrit ? Ignorez cet e-mail.',
        'cta' => 'Vérifier l\'e-mail',
    ],

    'verify_email_change' => [
        'subject' => 'Confirmez votre nouvel e-mail',
        'preheader' => 'Confirmez :email pour terminer le changement',
        'heading' => 'Confirmez :email',
        'body' => 'Vous avez demandé à utiliser :email pour votre compte :company. Confirmez cette adresse pour terminer le changement. Ce lien expire dans :count minutes.',
        'ignore' => 'Vous n\'avez rien demandé ? Ignorez cet e-mail et votre adresse actuelle est conservée.',
        'cta' => 'Confirmer le nouvel e-mail',
    ],

    'email_code' => [
        'expires' => 'Ce code expire dans :count minutes.',
        'browser_hint' => 'Saisissez ce code dans l\'onglet du navigateur où vous avez commencé.',
        'latest_only' => 'Seul le dernier code demandé fonctionne.',
        'unsolicited' => 'Vous n\'avez pas fait cette demande ? Vous pouvez ignorer cet e-mail.',
        'purposes' => [
            'signup' => [
                'subject' => 'Votre code d\'inscription Relaticle',
                'preheader' => 'Utilisez ce code pour terminer la création de votre compte',
                'heading' => 'Confirmez votre adresse e-mail',
                'body' => 'Saisissez ce code pour terminer la création de votre compte Relaticle.',
            ],
            'verify_email' => [
                'subject' => 'Votre code de vérification Relaticle',
                'preheader' => 'Utilisez ce code pour vérifier votre e-mail',
                'heading' => 'Confirmez votre adresse e-mail',
                'body' => 'Saisissez ce code pour vérifier l\'e-mail de votre compte Relaticle.',
            ],
            'sign_in' => [
                'subject' => 'Votre code de connexion Relaticle',
                'preheader' => 'Utilisez ce code pour vous connecter',
                'heading' => 'Confirmez votre identité',
                'body' => 'Saisissez ce code pour vous connecter à votre compte Relaticle.',
            ],
            'confirm_identity' => [
                'subject' => 'Votre code de confirmation Relaticle',
                'preheader' => 'Utilisez ce code pour continuer',
                'heading' => 'Confirmez votre identité',
                'body' => 'Saisissez ce code pour continuer avec votre compte Relaticle.',
            ],
            'change_email' => [
                'subject' => 'Votre code de changement d\'e-mail Relaticle',
                'preheader' => 'Utilisez ce code pour confirmer votre nouvel e-mail',
                'heading' => 'Confirmez votre nouvel e-mail',
                'body' => 'Saisissez ce code pour terminer le changement d\'e-mail de votre compte Relaticle.',
            ],
            'enable_email_sign_in' => [
                'subject' => 'Votre code de connexion par e-mail Relaticle',
                'preheader' => 'Utilisez ce code pour activer la connexion par e-mail',
                'heading' => 'Confirmez votre adresse e-mail',
                'body' => 'Saisissez ce code pour activer la connexion par e-mail sur votre compte Relaticle.',
            ],
        ],
    ],

    'email_change_notice' => [
        'subject' => 'Changement d\'e-mail demandé',
        'preheader' => 'C\'était vous ? Sinon, bloquez-le',
        'heading' => 'Quelqu\'un a demandé à changer votre e-mail en :email',
        'body' => 'Une personne connectée à votre compte a demandé à modifier son e-mail. Une fois :email confirmé, cette adresse devient celle de votre compte.',
        'block' => 'Si ce n\'était pas vous, bloquez le changement maintenant, puis déconnectez les autres sessions et changez votre mot de passe.',
        'cta' => 'Bloquer ce changement',
    ],

    'reset_password' => [
        'subject' => 'Réinitialisez votre mot de passe',
        'preheader' => 'Ce lien expire dans :count minutes',
        'heading' => 'Réinitialisez votre mot de passe',
        'body' => 'Choisissez un nouveau mot de passe pour votre compte Relaticle. Ce lien expire dans :count minutes.',
        'ignore' => 'Vous n\'avez pas demandé de réinitialisation ? Ignorez cet e-mail et votre mot de passe reste inchangé.',
        'cta' => 'Réinitialiser le mot de passe',
    ],
];
