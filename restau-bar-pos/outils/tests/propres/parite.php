<?php
// Parité des clés FR/EN : lang/fr.php ↔ lang/en.php, et chaque lang/fr/<module>.php ↔ lang/en/<module>.php ; clés en double entre fichiers
$R = '/home/user/Eglise/restau-bar-pos/lang';
$ko = 0;
$comparer = function (string $nom, array $fr, array $en) use (&$ko) {
    foreach (array_diff_key($fr, $en) as $k => $_) { echo "$nom : manque en anglais : $k\n"; $ko++; }
    foreach (array_diff_key($en, $fr) as $k => $_) { echo "$nom : manque en français : $k\n"; $ko++; }
    foreach ($fr as $k => $v) {
        if (isset($en[$k]) && is_string($v) && is_string($en[$k])) {
            preg_match_all('/\{[a-z_]+\}/', $v, $a); preg_match_all('/\{[a-z_]+\}/', $en[$k], $b);
            $a = array_unique($a[0]); $b = array_unique($b[0]); sort($a); sort($b);
            if ($a !== $b) { echo "$nom : paramètres différents pour $k : " . implode(',', $a) . ' / ' . implode(',', $b) . "\n"; $ko++; }
            if (trim($v) === '' || trim($en[$k]) === '') { echo "$nom : texte vide pour $k\n"; $ko++; }
        }
    }
};
$comparer('fr.php/en.php', require "$R/fr.php", require "$R/en.php");
$fichiersFr = array_map('basename', glob("$R/fr/*.php")); $fichiersEn = array_map('basename', glob("$R/en/*.php"));
foreach (array_diff($fichiersFr, $fichiersEn) as $f) { echo "fichier sans anglais : $f\n"; $ko++; }
foreach (array_diff($fichiersEn, $fichiersFr) as $f) { echo "fichier sans français : $f\n"; $ko++; }
$vues = [];
foreach (array_intersect($fichiersFr, $fichiersEn) as $f) {
    $fr = require "$R/fr/$f"; $en = require "$R/en/$f";
    $comparer($f, $fr, $en);
    foreach (array_keys($fr) as $k) { $vues[$k][] = $f; }
}
$communs = require "$R/fr.php";
foreach ($vues as $k => $fs) {
    if (count($fs) > 1) { echo "clé définie dans plusieurs modules ($k) : " . implode(', ', $fs) . "\n"; }
    if (isset($communs[$k])) { echo "clé de module qui écrase une clé commune ($k) : " . implode(', ', $fs) . "\n"; }
}
echo count($fichiersFr) . " fichiers de module ; " . ($ko === 0 ? "parité FR/EN complète" : "$ko écarts") . "\n";
