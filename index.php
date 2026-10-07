<?php

/*
 * Porte d'entrée pour une installation dans un sous-dossier (ex. https://exemple.com/waumini/),
 * avec le fichier .htaccess voisin : elle passe la main à public/index.php en gardant
 * le sous-dossier comme adresse de base. Sur une installation classique (adresse
 * pointée sur public/), ce fichier ne sert pas.
 */
require __DIR__.'/public/index.php';
