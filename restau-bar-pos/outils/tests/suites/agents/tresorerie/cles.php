<?php
$r = '/home/user/Eglise/restau-bar-pos';
foreach (['fr', 'en'] as $l) {
    $all = array_merge(require "$r/lang/$l.php", ...array_map(fn ($f) => require $f, glob("$r/lang/$l/*.php")));
    $files = array_merge(glob("$r/views/gestion/tresorerie/*.php"), glob("$r/views/gestion/depenses/*.php"), glob("$r/views/gestion/ventes/*.php"), glob("$r/views/gestion/rapports/*.php"),
        glob("$r/app/Controllers/Gestion/{Tresorerie,Transfert,Depense,CategorieDepense,Vente}*.php", GLOB_BRACE));
    $manque = [];
    foreach ($files as $f) {
        preg_match_all("/t\('([a-z_]+\.[a-z0-9_.]+)'/", file_get_contents($f), $m);
        foreach ($m[1] as $k) { if (!str_ends_with($k, '_') && !isset($all[$k])) $manque[] = basename($f) . ":$k"; }
        // clés construites
    }
    foreach (['caisse', 'mobile_money', 'banque'] as $t) foreach (['type_', 'groupe_vide_', 'ajouter_compte_'] as $pre) if (!isset($all["tresorerie.$pre$t"])) $manque[] = "tresorerie.$pre$t";
    foreach (['transferts.erreur_source', 'transferts.erreur_destination', 'transferts.champ_devise_source', 'transferts.champ_devise_destination'] as $k) if (!isset($all[$k])) $manque[] = $k;
    echo "$l manquantes : ", implode(', ', array_unique($manque)) ?: 'aucune', "\n";
}
