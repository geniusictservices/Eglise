<?php

// Configuration commune à toutes les instances.
// Les valeurs propres à un serveur se mettent dans config/app.local.php (non versionné).
return [
    'debug' => false,

    // Domaine principal : <client>.<domaine_base>
    'domaine_base' => 'restau-bar-pos.local',

    // Instance utilisée quand l'adresse ne contient pas de sous-domaine (localhost, 127.0.0.1…)
    'instance_par_defaut' => 'demo',

    // Sous-domaines réservés, qui ne peuvent pas être attribués à un client
    'sous_domaines_reserves' => ['admin', 'www', 'api', 'mail'],

    'langues' => ['fr', 'en'],
    'langue_defaut' => 'fr',
    'fuseau_horaire' => 'Africa/Lubumbashi',
];
