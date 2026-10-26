<?php

return [
    // Types d'établissement
    'type.restaurant_bar' => 'Restaurant-bar',
    'type.lounge' => 'Lounge bar',
    'type.nightclub' => 'Nightclub',

    // Jours
    'jour.1' => 'Lun', 'jour.2' => 'Mar', 'jour.3' => 'Mer', 'jour.4' => 'Jeu',
    'jour.5' => 'Ven', 'jour.6' => 'Sam', 'jour.7' => 'Dim',
    'jour_long.1' => 'lundi', 'jour_long.2' => 'mardi', 'jour_long.3' => 'mercredi', 'jour_long.4' => 'jeudi',
    'jour_long.5' => 'vendredi', 'jour_long.6' => 'samedi', 'jour_long.7' => 'dimanche',
    'jours.tous' => 'Tous les jours',

    // Ouverture
    'etat.ouvert' => '{service} ouvert · jusqu\'à {heure}',
    'etat.ouvert_court' => 'Ouvert en ce moment',
    'etat.ferme' => 'Fermé',
    'etat.ferme_ouvre_aujourdhui' => 'Fermé · ouvre à {heure}',
    'etat.ferme_ouvre_jour' => 'Fermé · ouvre {jour} à {heure}',

    // Badges
    'badge.nouveau' => 'Nouveau',
    'badge.populaire' => 'Populaire',
    'badge.epice' => 'Épicé',
    'badge.epuise' => 'Épuisé',

    // Vitrine
    'vitrine.nos_etablissements' => 'Nos établissements',
    'vitrine.voir_menu' => 'Voir le menu',
    'vitrine.tous_les_etablissements' => '← Tous nos établissements',
    'vitrine.devise' => 'Devise des prix',
    'vitrine.categories' => 'Catégories du menu',
    'vitrine.menu_vide' => 'Le menu sera bientôt disponible.',
    'vitrine.rechercher' => 'Rechercher dans le menu',
    'vitrine.rechercher_exemple' => 'Bière, cocktail, poisson…',
    'vitrine.aucun_resultat' => 'Aucun produit ne correspond à votre recherche.',
    'vitrine.allergenes' => 'Allergènes : {liste}',
    'vitrine.note_taux' => 'Prix en {devise}. Certains prix sont convertis au taux du jour de l\'établissement.',
    'vitrine.horaires' => 'Horaires',
    'vitrine.nous_trouver' => 'Nous trouver',
    'vitrine.voir_carte' => 'Voir sur la carte',

    // Erreurs
    'erreur.introuvable_titre' => 'Page introuvable',
    'erreur.introuvable_texte' => 'Cette page n\'existe pas ou n\'est plus disponible.',
    'erreur.retour_accueil' => 'Retour à l\'accueil',

    // Gestion
    'gestion.nom' => 'Gestion',
    'gestion.connexion' => 'Connexion',
    'gestion.email' => 'Adresse e-mail',
    'gestion.mot_de_passe' => 'Mot de passe',
    'gestion.se_connecter' => 'Se connecter',
    'gestion.deconnexion' => 'Se déconnecter',
    'gestion.identifiants_invalides' => 'E-mail ou mot de passe incorrect.',
    'gestion.trop_essais' => 'Trop d\'essais. Réessayez dans 5 minutes.',
    'gestion.tableau' => 'Tableau de bord',
    'gestion.qr' => 'Codes QR',
    'gestion.voir_vitrine' => 'Voir la vitrine',
    'gestion.bonjour' => 'Bonjour {nom}',
    'gestion.etablissements' => 'Établissements',
    'gestion.produits' => 'Produits',
    'gestion.scans_7j' => 'Scans de QR sur 7 jours',
    'gestion.nom_colonne' => 'Nom',
    'gestion.type' => 'Type',
    'gestion.page_publique' => 'Page publique',
    'gestion.devises' => 'Devises actives',
    'gestion.devise' => 'Devise',
    'gestion.taux' => 'Taux en vigueur',
    'gestion.reference' => 'Référence',
    'gestion.taux_manquant' => 'Taux manquant',
    'gestion.devises_aide' => 'L\'écran de gestion des devises et des taux arrive avec la phase 3 (caisse).',
    'gestion.qr_aide' => 'Chaque code QR ouvre le menu de l\'établissement. Un code lié à une zone affiche les prix de cette zone.',
    'gestion.qr_image' => 'Code QR : {libelle}',
    'gestion.scans' => 'Scans : {total} au total · {semaine} sur 7 jours',
    'gestion.imprimer' => 'Imprimer',
    'gestion.telecharger_svg' => 'Télécharger (SVG)',
    'gestion.qr_nouveau' => 'Nouveau code QR',
    'gestion.etablissement' => 'Établissement',
    'gestion.zone' => 'Zone',
    'gestion.zone_aucune' => 'Aucune (prix normaux)',
    'gestion.libelle' => 'Libellé',
    'gestion.libelle_exemple' => 'Table 12, comptoir, entrée…',
    'gestion.creer' => 'Créer le code QR',
    'gestion.qr_cree' => 'Code QR créé.',
    'gestion.qr_invalide' => 'Choisissez un établissement et donnez un libellé au code QR.',
];
