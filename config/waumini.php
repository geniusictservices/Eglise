<?php

/*
|--------------------------------------------------------------------------
| Waumini
|--------------------------------------------------------------------------
|
| Paramètres propres à la plateforme : devise de base, langues, catalogue
| des permissions et rôles modèles proposés à chaque nouvelle communauté.
|
*/

return [

    // Le dollar est la devise de base de tout le système.
    'base_currency' => 'USD',

    // Indicatif utilisé pour normaliser un numéro saisi sans préfixe (0812345678).
    'default_country_code' => '243',

    // Fuseau horaire par défaut d'une nouvelle communauté (Goma, Lubumbashi).
    'default_timezone' => 'Africa/Lubumbashi',

    'trial_days' => 30,

    // Notifications sur le téléphone (Web Push) : clés créées par « php artisan waumini:vapid ».
    'push' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT', 'mailto:contact@waumini.com'),
    ],

    // Démo publique : chaque visiteur a sa copie, effacée après ce nombre de jours.
    'demo' => [
        'days' => 3,
        'max_active' => 300,
    ],
    'grace_days' => 30,

    // Coordonnées de Genius ICT affichées sur le site public et dans l'application.
    'contact' => [
        'company' => 'Genius ICT',
        'city' => 'Goma, Nord-Kivu, RDC',
        'email' => env('WAUMINI_CONTACT_EMAIL', 'geniusictservices@gmail.com'),
        'phone' => env('WAUMINI_CONTACT_PHONE'), // numéro WhatsApp au format +243…, à renseigner
        'website' => 'waumini.com',
        'payment' => null, // numéros où les communautés paient leur abonnement (M-Pesa, Airtel Money…)
    ],

    // Adresse publique de Waumini (site, application, QR codes).
    'domain' => env('WAUMINI_DOMAIN', 'waumini.com'),

    /*
    |--------------------------------------------------------------------------
    | Offres
    |--------------------------------------------------------------------------
    |
    | Valeurs de départ : les offres et les tarifs se règlent ensuite dans
    | l'espace Genius ICT, avec leur historique. Un nouveau tarif s'applique
    | aux nouvelles souscriptions et, pour les abonnés, au renouvellement.
    |
    */

    'size_tiers' => [
        'small' => 'Jusqu’à 300 membres',
        'medium' => 'De 301 à 1 500 membres',
        'large' => 'Plus de 1 500 membres',
    ],

    'packs' => [
        'msingi' => [
            'name' => 'Msingi',
            'meaning' => 'la base',
            'for' => 'La petite église qui veut d’abord un registre fiable.',
            'modules' => ['Registre des membres et des ménages', 'Tableau de bord', 'Utilisateurs et rôles', 'Exports'],
            'prices' => ['small' => 10, 'medium' => 15, 'large' => 20],
        ],
        'kawaida' => [
            'name' => 'Kawaida',
            'meaning' => 'le standard',
            'for' => 'L’église locale structurée : le pack de référence.',
            'modules' => ['Tout Msingi', 'Finances et promesses', 'Plan d’action et budget', 'Groupes, activités et présences'],
            'prices' => ['small' => 25, 'medium' => 35, 'large' => 50],
            'featured' => true,
        ],
        'kamili' => [
            'name' => 'Kamili',
            'meaning' => 'le complet',
            'for' => 'La grande paroisse ou l’église exigeante.',
            'modules' => ['Tout Kawaida', 'Paie', 'Communication', 'Suivi pastoral', 'Documents et registres', 'Site vitrine'],
            'prices' => ['small' => 40, 'medium' => 55, 'large' => 75],
        ],
        'umoja' => [
            'name' => 'Umoja',
            'meaning' => 'l’unité',
            'for' => 'Communautés, diocèses, églises à annexes.',
            'modules' => ['Tout Kamili, pour toutes les paroisses', 'Consolidation et quotes-parts', 'Accompagnement au déploiement'],
            'prices' => null, // sur devis
        ],
    ],

    'annual_discount_months' => 2, // payer 10 mois pour 12

    /*
    | Espace Genius ICT : les rôles de l'équipe et ce que chacun peut faire.
    */
    'platform_permissions' => [
        'admin.communities' => 'Voir les communautés inscrites et les demandes de démonstration',
        'admin.subscriptions' => 'Enregistrer les paiements et les abonnements, prolonger un essai',
        'admin.pricing' => 'Fixer les offres et les tarifs',
        'admin.settings' => 'Modifier les coordonnées et les réglages de la plateforme',
        'admin.legal' => 'Modifier les conditions d’utilisation et la politique de confidentialité',
        'admin.staff' => 'Gérer l’équipe Genius ICT',
        'admin.support' => 'Répondre aux tickets et ouvrir une communauté avec son accord',
    ],

    'platform_roles' => [
        'direction' => ['name' => 'Direction', 'permissions' => ['*']],
        'commercial' => ['name' => 'Commercial et facturation', 'permissions' => ['admin.communities', 'admin.subscriptions', 'admin.pricing']],
        'support' => ['name' => 'Support', 'permissions' => ['admin.communities', 'admin.support']],
        'contenu' => ['name' => 'Contenus et juridique', 'permissions' => ['admin.legal', 'admin.settings']],
    ],

    // Langues de l'interface : code => nom dans la langue elle-même.
    'locales' => [
        'fr' => 'Français',
        'sw' => 'Kiswahili',
        'ln' => 'Lingála',
        'kg' => 'Kikongo',
        'lua' => 'Tshiluba',
    ],

    // Devises proposées à l'ajout. Le taux est saisi par chaque communauté.
    'currencies' => [
        'USD' => ['name' => 'Dollar américain', 'symbol' => '$', 'decimals' => 2],
        'CDF' => ['name' => 'Franc congolais', 'symbol' => 'FC', 'decimals' => 0],
        'EUR' => ['name' => 'Euro', 'symbol' => '€', 'decimals' => 2],
        'RWF' => ['name' => 'Franc rwandais', 'symbol' => 'FRw', 'decimals' => 0],
        'UGX' => ['name' => 'Shilling ougandais', 'symbol' => 'USh', 'decimals' => 0],
        'BIF' => ['name' => 'Franc burundais', 'symbol' => 'FBu', 'decimals' => 0],
        'KES' => ['name' => 'Shilling kényan', 'symbol' => 'KSh', 'decimals' => 2],
        'TZS' => ['name' => 'Shilling tanzanien', 'symbol' => 'TSh', 'decimals' => 0],
        'XAF' => ['name' => 'Franc CFA (BEAC)', 'symbol' => 'FCFA', 'decimals' => 0],
        'ZAR' => ['name' => 'Rand sud-africain', 'symbol' => 'R', 'decimals' => 2],
        'GBP' => ['name' => 'Livre sterling', 'symbol' => '£', 'decimals' => 2],
        'CAD' => ['name' => 'Dollar canadien', 'symbol' => 'CA$', 'decimals' => 2],
    ],

    /*
    | Registre des membres : valeurs proposées à une nouvelle communauté.
    | Le siège (ou l'église indépendante) les adapte ; ses paroisses suivent.
    */
    'registry' => [
        'number_format' => '{SIGLE}-{ANNEE}-{NUMERO}',
        'number_padding' => 4,
        'yearly_reset' => false,

        // Champs facultatifs de la fiche, que la communauté peut masquer.
        'optional_fields' => [
            'birth_place' => 'Lieu de naissance',
            'phone2' => 'Second téléphone',
            'email' => 'E-mail',
            'profession' => 'Profession',
            'marital_status' => 'État civil',
            'education_level' => 'Niveau d’études',
            'origin_church' => 'Église d’origine',
            'emergency_contact' => 'Personne à prévenir',
            'preferred_language' => 'Langue préférée',
            'joined_on' => 'Date d’adhésion',
        ],

        'statuses' => [
            ['name' => 'Membre', 'color' => 'leaf', 'counts_as_member' => true, 'is_default' => true],
            ['name' => 'Sympathisant', 'color' => 'ochre', 'counts_as_member' => false],
            ['name' => 'Catéchumène', 'color' => 'ink', 'counts_as_member' => false],
            ['name' => 'Enfant', 'color' => 'ochre', 'counts_as_member' => true],
            ['name' => 'Inactif', 'color' => 'sand', 'counts_as_member' => false],
            ['name' => 'Transféré', 'color' => 'sand', 'counts_as_member' => false],
            ['name' => 'Décédé', 'color' => 'terra', 'counts_as_member' => false],
        ],

        'functions' => ['Pasteur', 'Évangéliste', 'Ancien', 'Diacre', 'Diaconesse', 'Choriste', 'Intercesseur', 'Moniteur d’école du dimanche', 'Protocole'],

        'colors' => ['ink' => 'Couleur principale', 'ochre' => 'Ocre', 'terra' => 'Terre cuite', 'leaf' => 'Vert', 'sand' => 'Gris'],
    ],

    /*
    | Finances : catégories proposées à chaque niveau (paroisse, région, siège),
    | que la communauté adapte. Une recette collective (la boîte) n'a pas de nom
    | de donateur ; une recette personnelle est rattachée au membre.
    */
    'finance' => [
        'income_categories' => [
            ['Offrande du culte', 'collective'],
            ['Offrande spéciale', 'collective'],
            ['Dîme', 'personal'],
            ['Offrande d’action de grâce', 'personal'],
            ['Don', 'personal'],
            ['Promesses et projets', 'personal'],
            ['Retour sur avance', 'collective'],
            ['Contribution d’un département', 'group'],
            ['Autres recettes', 'collective'],
        ],
        'expense_categories' => [
            'Loyer et charges', 'Électricité et eau', 'Transport et déplacements', 'Entretien et réparations',
            'Fournitures et matériel', 'Communication et téléphone', 'Évangélisation et missions', 'Œuvres sociales et entraide',
            'Accueil et réceptions', 'Quote-part au niveau supérieur', 'Rémunérations et motivations', 'Autres dépenses',
        ],
        // Circuit des dépenses : nombre de signatures (1 à 3), délai pour justifier une avance.
        'expenses' => ['approvals_required' => 2, 'advance_days' => 14, 'block_unjustified_advances' => false],

        // Message de relance d'une promesse, envoyé à la main sur WhatsApp ; chaque communauté peut l'adapter.
        'pledge_reminder' => 'Bonjour :name, que la paix du Seigneur soit avec vous. Merci pour votre promesse de :promised pour « :campaign ». À ce jour, nous avons reçu :received ; il reste :remaining. Que Dieu vous bénisse ! — :church',

        // Billets et pièces pour compter la collecte du culte.
        'denominations' => [
            'USD' => [100, 50, 20, 10, 5, 2, 1],
            'CDF' => [20000, 10000, 5000, 1000, 500, 200, 100, 50],
        ],
    ],

    // Départements proposés à la création (chacun reste libre de les nommer).
    'department_suggestions' => [
        'ministry' => ['Chorale', 'Jeunesse', 'Mamans', 'Papas', 'École du dimanche', 'Évangélisation', 'Intercession', 'Protocole et accueil', 'Diaconie et social', 'Médias et sonorisation'],
        'administrative' => ['Finances', 'Secrétariat', 'Logistique et entretien', 'Construction'],
    ],

    // Niveaux proposés pour la hiérarchie ; chaque communauté peut les renommer.
    'level_suggestions' => ['Siège', 'Région', 'Secteur', 'District', 'Paroisse', 'Église locale', 'Annexe'],

    /*
    |--------------------------------------------------------------------------
    | Catalogue des permissions
    |--------------------------------------------------------------------------
    |
    | Regroupées par module. Un rôle est une liste de ces clés ; l'administrateur
    | de la communauté compose ses propres rôles en les cochant.
    |
    */

    'permissions' => [
        'organization' => [
            'label' => 'Communauté',
            'items' => [
                'organization.view' => 'Voir le tableau de bord et les informations de la communauté',
                'organization.settings' => 'Modifier les paramètres, le logo et les libellés',
                'organization.hierarchy' => 'Gérer la hiérarchie (régions, secteurs, paroisses) et les rattachements',
                'users.view' => 'Voir les utilisateurs',
                'users.manage' => 'Créer les utilisateurs et leur attribuer des rôles',
                'roles.manage' => 'Créer et modifier les rôles',
                'currencies.manage' => 'Gérer les devises et saisir le taux du jour',
                'audit.view' => 'Consulter le journal d’audit',
                'support.grant' => 'Autoriser ou révoquer l’accès du support Genius ICT',
            ],
        ],
        'members' => [
            'label' => 'Membres et départements',
            'items' => [
                'members.view' => 'Voir le registre des membres',
                'members.manage' => 'Ajouter et modifier les membres et les ménages',
                'members.export' => 'Exporter le registre',
                'members.import' => 'Importer des données depuis Excel',
                'members.sensitive' => 'Voir les informations sensibles des membres',
                'members.settings' => 'Régler le registre : numéro, statuts, champs, fonctions',
                'departments.manage' => 'Créer et gérer les départements',
            ],
        ],
        'finance' => [
            'label' => 'Finances',
            'items' => [
                'finance.view' => 'Voir les caisses et les opérations',
                'finance.settings' => 'Gérer les caisses et les catégories',
                'finance.income' => 'Saisir les recettes et la collecte du culte',
                'finance.contributions.view' => 'Voir les contributions nominatives (dîmes)',
                'finance.pledges' => 'Gérer les promesses',
                'finance.payments.validate' => 'Valider les paiements déclarés (mobile money)',
                'finance.expenses.request' => 'Demander une dépense',
                'finance.expenses.approve' => 'Approuver les dépenses',
                'finance.disburse' => 'Décaisser et enregistrer les justificatifs',
                'finance.exchange' => 'Enregistrer les opérations de change',
                'finance.close' => 'Clôturer une période',
                'finance.reopen' => 'Rouvrir une période clôturée',
                'finance.reports' => 'Produire les rapports financiers',
            ],
        ],
        'planning' => [
            'label' => 'Plan d’action et budget',
            'items' => [
                'planning.view' => 'Voir le plan d’action et le budget',
                'planning.manage' => 'Gérer la vision, les objectifs et les actions',
                'budget.propose' => 'Proposer les besoins d’un département',
                'budget.arbitrate' => 'Arbitrer le budget et le présenter',
                'budget.approve' => 'Approuver le budget et ses révisions',
                'budget.authorize' => 'Autoriser une dépense hors budget',
                'meetings.manage' => 'Enregistrer les réunions et procès-verbaux',
            ],
        ],
        'payroll' => [
            'label' => 'Paie',
            'items' => [
                'payroll.view' => 'Voir la paie et les rémunérations',
                'payroll.manage' => 'Gérer les bénéficiaires, les éléments et les paies',
                'payroll.approve' => 'Approuver les paies et les avances sur salaire',
            ],
        ],
        'community' => [
            'label' => 'Groupes, activités et communication',
            'items' => [
                'groups.manage' => 'Gérer les groupes, réunions et présences',
                'activities.manage' => 'Gérer le calendrier et les activités',
                'attendance.record' => 'Enregistrer les présences',
                'communication.send' => 'Envoyer des notifications',
            ],
        ],
        'documents' => [
            'label' => 'Documents et registres',
            'items' => [
                'documents.issue' => 'Délivrer les attestations et lettres',
                'documents.templates' => 'Gérer les modèles de documents',
                'registers.manage' => 'Tenir les registres officiels et les anciens registres',
            ],
        ],
        'pastoral' => [
            'label' => 'Suivi pastoral',
            'items' => [
                'pastoral.view' => 'Voir et gérer le suivi pastoral',
                'pastoral.confidential' => 'Écrire et lire ses notes confidentielles',
                'member.space' => 'Accéder à son espace membre : sa fiche, ses reçus, ses demandes',
            ],
        ],
        'network' => [
            'label' => 'Consolidation et site web',
            'items' => [
                'consolidation.view' => 'Voir les rapports consolidés des niveaux inférieurs',
                'transfers.manage' => 'Gérer les transferts de membres',
                'website.manage' => 'Gérer le site vitrine',
                'sermons.manage' => 'Publier les prédications sur le site',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rôles modèles
    |--------------------------------------------------------------------------
    |
    | Copiés dans chaque nouvelle communauté, où l'administrateur peut ensuite
    | les modifier ou en créer d'autres. '*' signifie toutes les permissions.
    |
    */

    'role_templates' => [
        'membre' => [
            'name' => 'Membre',
            'description' => 'Son espace membre seulement : sa fiche, sa carte, ses contributions et reçus, ses promesses, le programme, les annonces, ses demandes.',
            'permissions' => ['member.space'],
        ],
        'administrateur' => [
            'name' => 'Administrateur',
            'description' => 'Accès complet à la communauté. Attribué à la personne qui a créé le compte.',
            'permissions' => ['*'],
            'locked' => true,
        ],
        'pasteur' => [
            'name' => 'Pasteur',
            'description' => 'Vue d’ensemble, suivi pastoral et notes confidentielles, validation des dépenses.',
            'permissions' => [
                'member.space',
                'organization.view', 'users.view', 'audit.view',
                'members.view', 'members.manage', 'members.sensitive',
                'finance.view', 'finance.contributions.view', 'finance.expenses.approve', 'finance.reports',
                'planning.view', 'planning.manage', 'budget.approve', 'budget.authorize', 'meetings.manage',
                'payroll.view', 'payroll.approve',
                'activities.manage', 'communication.send',
                'documents.issue',
                'pastoral.view', 'pastoral.confidential',
                'consolidation.view', 'sermons.manage',
            ],
        ],
        'secretaire' => [
            'name' => 'Secrétaire',
            'description' => 'Registre des membres, départements, activités, documents et communication. Ne voit pas les dîmes nominatives.',
            'permissions' => [
                'member.space',
                'organization.view', 'users.view',
                'members.view', 'members.manage', 'members.export', 'members.import', 'members.sensitive', 'members.settings', 'departments.manage',
                'planning.view', 'meetings.manage',
                'groups.manage', 'activities.manage', 'attendance.record', 'communication.send',
                'documents.issue', 'documents.templates', 'registers.manage',
                'transfers.manage', 'website.manage', 'sermons.manage',
            ],
        ],
        'tresorier' => [
            'name' => 'Trésorier',
            'description' => 'Caisses, recettes, promesses, dépenses, change, clôtures et rapports.',
            'permissions' => [
                'member.space',
                'organization.view', 'currencies.manage',
                'members.view',
                'finance.view', 'finance.settings', 'finance.income', 'finance.contributions.view', 'finance.pledges',
                'finance.payments.validate', 'finance.disburse', 'finance.exchange', 'finance.close', 'finance.reports',
                'planning.view', 'budget.arbitrate',
                'payroll.view', 'payroll.manage',
            ],
        ],
        'responsable_departement' => [
            'name' => 'Responsable de département',
            'description' => 'Son département uniquement : membres, réunions, présences, besoins budgétaires, demandes de dépense.',
            'permissions' => [
                'member.space',
                'organization.view',
                'members.view',
                'finance.expenses.request',
                'planning.view', 'budget.propose',
                'groups.manage', 'attendance.record', 'communication.send',
            ],
        ],
        'conseil' => [
            'name' => 'Conseil / comité',
            'description' => 'Lecture seule des rapports et indicateurs, pour contrôler sans modifier.',
            'permissions' => [
                'member.space',
                'organization.view', 'audit.view',
                'finance.view', 'finance.reports',
                'planning.view',
                'consolidation.view',
            ],
        ],
        'responsable_niveau' => [
            'name' => 'Responsable de niveau',
            'description' => 'Secteur, région ou siège : consolidation, comparaisons, transferts. Lecture des paroisses.',
            'permissions' => [
                'member.space',
                'organization.view', 'users.view',
                'members.view',
                'finance.view', 'finance.reports',
                'planning.view', 'planning.manage', 'meetings.manage',
                'consolidation.view', 'transfers.manage',
            ],
        ],
    ],
];
