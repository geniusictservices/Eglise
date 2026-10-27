<?php
define('RACINE', '/home/user/Eglise/restau-bar-pos');
foreach (['fr', 'en'] as $l) {
    $t = require RACINE . "/lang/$l.php";
    foreach (glob(RACINE . "/lang/$l/*.php") as $f) { $t = array_merge($t, require $f); }
    $fichiers = array_merge(glob(RACINE . '/views/gestion/rapports/*.php'), glob(RACINE . '/views/gestion/evenements/*.php'), [RACINE . '/views/gestion/tableau.php'],
        array_map(fn($c) => RACINE . "/app/Controllers/Gestion/$c.php", ['TableauController','RapportServeursController','EvenementController','ExportComptableController','ExportCsv','RapportAffichage']),
        [RACINE . '/app/Repositories/EvenementRepository.php', RACINE . '/app/Repositories/RapportRepository.php']);
    foreach ($fichiers as $f) {
        preg_match_all("/t\('([a-z_]+\.[a-z_0-9.]+)'/", file_get_contents($f), $m);
        foreach (array_unique($m[1]) as $k) if (!isset($t[$k]) && !str_ends_with($k, '_')) echo "$l MANQUE $k dans " . basename($f) . "\n";
    }
}
