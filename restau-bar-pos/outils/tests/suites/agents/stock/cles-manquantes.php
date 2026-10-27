<?php
// Clés t('…') des fichiers donnés absentes de lang/fr et lang/en (fusion de tous les fichiers)
$racine = '/home/user/Eglise/restau-bar-pos';
$langs = [];
foreach (['fr', 'en'] as $l) {
    $t = require "$racine/lang/$l.php";
    foreach (glob("$racine/lang/$l/*.php") as $f) { $t = array_merge($t, require $f); }
    $langs[$l] = $t;
}
$cles = [];
foreach (array_slice($argv, 1) as $f) {
    preg_match_all("/\\bt\\(\\s*'([a-z0-9_.]+)'/", file_get_contents($f), $m);
    preg_match_all("/\\bt\\(\\s*\\\$[a-z]+ \\? '([a-z0-9_.]+)' : '([a-z0-9_.]+)'/i", file_get_contents($f), $m2);
    foreach (array_merge($m[1], $m2[1], $m2[2]) as $c) { $cles[$c] = $f; }
}
foreach ($cles as $c => $f) {
    foreach (['fr', 'en'] as $l) {
        if (!isset($langs[$l][$c])) { echo "$l $c (" . basename($f) . ")\n"; }
    }
}
