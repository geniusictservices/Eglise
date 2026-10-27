<?php
$r = '/home/user/Eglise/restau-bar-pos';
$fr = require "$r/lang/fr/ventes-gestion.php"; $en = require "$r/lang/en/ventes-gestion.php";
echo "fr only: ", implode(',', array_diff_key($fr, $en) ? array_keys(array_diff_key($fr, $en)) : []), "\nen only: ", implode(',', array_keys(array_diff_key($en, $fr))), "\n";
$allFr = array_merge(require "$r/lang/fr.php", ...array_map(fn($f) => require $f, array_filter(glob("$r/lang/fr/*.php"), fn($f) => basename($f) !== 'ventes-gestion.php')));
$allEn = array_merge(require "$r/lang/en.php", ...array_map(fn($f) => require $f, array_filter(glob("$r/lang/en/*.php"), fn($f) => basename($f) !== 'ventes-gestion.php')));
echo "collisions: ", implode(',', array_keys(array_intersect_key($fr, $allFr))), "\n";
$allFr += $fr; $allEn += $en;
$files = array_merge(glob("$r/views/gestion/ventes/*.php"), glob("$r/views/gestion/rapports/*.php"), ["$r/app/Controllers/Gestion/VenteController.php", "$r/app/Controllers/Gestion/RapportVenteController.php"]);
$manque = [];
foreach ($files as $f) { preg_match_all("/t\('([a-z_]+\.[a-z0-9_.]+)'/", file_get_contents($f), $m); foreach ($m[1] as $k) { if (str_ends_with($k, '_')) continue; if (!isset($allFr[$k])) $manque[] = "fr:$k"; if (!isset($allEn[$k])) $manque[] = "en:$k"; } }
foreach (['especes','mobile_money','banque'] as $t) foreach (['fr'=>$allFr,'en'=>$allEn] as $l=>$a) if (!isset($a["tresorerie.type_$t"])) $manque[] = "$l:type_$t";
echo "missing: ", implode(', ', array_unique($manque)) ?: 'none', "\n";
