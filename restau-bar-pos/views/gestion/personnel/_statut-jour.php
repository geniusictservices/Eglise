<?php
/**
 * Badge du statut d'une journée d'un employé (bilan de PlanningRepository) : à l'heure, en retard, absent…
 * @var ?array $jour
 */
use App\Controllers\Gestion\PersonnelAffichage as P;

if ($jour === null) {
    return;
}
$badges = [];
switch ($jour['statut']) {
    case 'absent': $badges[] = ['erreur', t('pers.statut_absent')]; break;
    case 'en_attente': $badges[] = ['braise', t('pers.statut_en_attente')]; break;
    case 'justifie': $badges[] = ['glace', t('pers.type_' . ($jour['absence']['type'] ?? 'justification'))]; break;
    case 'sans_solde': $badges[] = ['neutre', t('pers.type_sans_solde')]; break;
    case 'ferie': $badges[] = ['glace', t('pers.statut_ferie')]; break;
    case 'a_venir': $badges[] = ['neutre', t('pers.statut_a_venir')]; break;
    case 'hors_planning': $badges[] = ['glace', t('pers.statut_hors_planning')]; break;
    case 'retard': $badges[] = ['braise', t('pers.statut_retard', ['duree' => P::duree($jour['retard'])])]; break;
    default: $badges[] = ['succes', $jour['en_cours'] ? t('pers.statut_present') : t('pers.statut_a_l_heure')];
}
if ($jour['depart_anticipe'] > 0) {
    $badges[] = ['braise', t('pers.statut_depart_anticipe', ['duree' => P::duree($jour['depart_anticipe'])])];
}
if ($jour['minutes_sup'] > 0) {
    $badges[] = ['lave', t('pers.statut_heures_sup', ['duree' => P::duree($jour['minutes_sup'])])];
}
if ($jour['depart_manquant']) {
    $badges[] = ['erreur', t('pers.statut_depart_manquant')];
}
?>
<span class="per-badges"><?php foreach ($badges as [$classe, $texte]): ?><span class="badge badge--<?= $classe ?>"><?= e($texte) ?></span><?php endforeach; ?></span>
