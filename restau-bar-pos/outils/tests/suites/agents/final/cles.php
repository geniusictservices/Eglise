<?php
define('RACINE', '/home/user/Eglise/restau-bar-pos');
function charger($l) { $t = require RACINE."/lang/$l.php"; foreach (glob(RACINE."/lang/$l/*.php") as $f) $t = array_merge($t, require $f); return $t; }
$fr = charger('fr'); $en = charger('en');
echo "fr: ".count($fr)." en: ".count($en)."\n";
echo "Manque en anglais: ".implode(', ', array_diff(array_keys($fr), array_keys($en)))."\n";
echo "Manque en français: ".implode(', ', array_diff(array_keys($en), array_keys($fr)))."\n";
$utilisees = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(RACINE));
foreach ($it as $f) {
  if (!preg_match('#\.(php|js)$#', $f) || str_contains($f, '/lang/') || str_contains($f, '/vendor/')) continue;
  $src = file_get_contents($f);
  preg_match_all("#\\bt\\(\\s*'([a-z0-9_.]+)'#", $src, $m);
  foreach ($m[1] as $c) $utilisees[$c][] = str_replace(RACINE.'/', '', $f);
}
foreach ($utilisees as $c => $fs) {
  if (!isset($fr[$c]) || !isset($en[$c])) echo "ABSENTE ".(!isset($fr[$c])?'fr ':'').(!isset($en[$c])?'en ':'')."$c : ".implode(',', array_unique($fs))."\n";
}
// placeholders différents
foreach ($fr as $c => $v) { if (!isset($en[$c])) continue; preg_match_all('#\{(\w+)\}#', $v, $a); preg_match_all('#\{(\w+)\}#', $en[$c], $b); sort($a[1]); sort($b[1]); if ($a[1] != $b[1]) echo "PARAMS $c : ".implode(',',$a[1])." / ".implode(',',$b[1])."\n"; }
// valeurs identiques fr/en (suspect)
$id = []; foreach ($fr as $c => $v) if (isset($en[$c]) && $en[$c] === $v && strlen($v) > 12) $id[] = $c; echo "Identiques fr/en (>12 car.): ".implode(', ', $id)."\n";
