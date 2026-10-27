<?php
$racine = '/home/user/Eglise/restau-bar-pos';
foreach (['fr', 'en'] as $l) {
    $t = require "$racine/lang/$l.php";
    foreach (glob("$racine/lang/$l/*.php") as $f) $t = array_merge($t, require $f);
    $T[$l] = $t;
}
$cles = file('/home/user/Eglise/restau-bar-pos/outils/tests/suites/agents/stock/cles.txt', FILE_IGNORE_NEW_LINES);
$perms = ['stock.voir','stock.transferer','stock.inventorier','stock.valider','stock.ajuster','stock.recettes','stock.parametrer','achats.voir','achats.fournisseurs','achats.commander','achats.recevoir','achats.payer'];
foreach ($cles as $c) {
    if (str_ends_with($c, '_') || in_array($c, $perms, true)) continue;
    foreach (['fr','en'] as $l) if (!isset($T[$l][$c])) echo "$l manque $c\n";
}
// dynamiques
$dyn = ['achats.paiement_'=>['non_payee','partielle','payee'],'achats.statut_'=>['brouillon','envoyee','partielle','recue','annulee'],'achats.type_'=>['brasserie','distributeur','grossiste','marche','autre'],
 'emballages.type_'=>['recu','rendu','consigne_payee','pret','retour','ajustement'],'stock.etat_'=>['negatif','bas'],'stock.inventaire_'=>['complet','service','partiel'],
 'stock.mode_'=>['vendre','bloquer','vendre_aide','bloquer_aide'],'stock.motif_perte_'=>['casse','offert','personnel','perime','renvoye','autre'],
 'stock.mvt_'=>['ouverture','reception','vente','recette','annulation_vente','transfert_sortie','transfert_entree','transfert_retour','inventaire','perte','ajustement','vide_recupere','vide_rendu'],
 'stock.sens_'=>['compter','ajouter','retirer'],'stock.statut_inventaire_'=>['en_cours','compte','valide','annule'],'stock.statut_transfert_'=>['brouillon','expedie','recu','annule'],'stock.type_lieu_'=>['depot','comptoir']];
foreach ($dyn as $p => $s) foreach ($s as $x) foreach (['fr','en'] as $l) if (!isset($T[$l][$p.$x])) echo "$l manque $p$x\n";
foreach ($perms as $p) foreach (['fr','en'] as $l) if (!isset($T[$l]['perm.'.$p])) echo "$l manque perm.$p\n";
// clés fr/en de mes fichiers identiques
foreach (['stock','achats'] as $f) {
  $a = array_keys(require "$racine/lang/fr/$f.php"); $b = is_file("$racine/lang/en/$f.php") ? array_keys(require "$racine/lang/en/$f.php") : [];
  foreach (array_diff($a,$b) as $k) echo "en/$f manque $k\n"; foreach (array_diff($b,$a) as $k) echo "fr/$f manque $k\n";
}
