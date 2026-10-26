<?php
/**
 * Rapports > Personnel : présence de la période (module Personnel et paie), incluse par la page du classement.
 * Avec rapports.voir : tous les employés de la portée (ou de l'établissement choisi) ; sinon, sa propre présence.
 * Le détail, l'export CSV et la masse salariale sont dans Rapports > Présence (/gestion/rapports/presence).
 * @var array{du: string, au: string} $bornes
 * @var ?int $etablissement
 * @var bool $toutVoir
 * @var ?int $choisi
 * @var int $moi
 */

use App\Controllers\Gestion\PersonnelAffichage as P;
use App\Core\Acces;
use App\Repositories\PersonnelRepository;
use App\Repositories\PlanningRepository;

$personnel = new PersonnelRepository();
if ($toutVoir) {
    $portee = $etablissement ? [(int) $etablissement] : Acces::etablissements((int) $moi, 'rapports.voir');
    $employes = $personnel->liste($portee, ['statut' => 'tous']);
    if ($choisi !== null) {
        $employes = array_values(array_filter($employes, static fn ($e) => (int) $e['utilisateur_id'] === (int) $choisi)) ?: $employes;
    }
} else {
    $moiEmploye = $personnel->parUtilisateur((int) $moi);
    $employes = $moiEmploye ? [$moiEmploye] : [];
}
$bilan = $employes ? (new PlanningRepository())->bilan(array_map(static fn ($e) => (int) $e['id'], $employes), $bornes['du'], $bornes['au'], $etablissement ? (int) $etablissement : null) : [];
$lignes = [];
foreach ($employes as $e) {
    $t = $bilan[(int) $e['id']]['totaux'] ?? null;
    if ($t !== null && ($t['creneaux'] > 0 || $t['jours_travailles'] > 0)) {
        $lignes[] = ['employe' => $e, 'totaux' => $t];
    }
}
usort($lignes, static fn ($a, $b) => $b['totaux']['minutes_travaillees'] <=> $a['totaux']['minutes_travaillees']);
$lien = '/gestion/rapports/presence?' . http_build_query(['du' => $bornes['du'], 'au' => $bornes['au'], 'etablissement' => $etablissement ?: null]);
?>
<link rel="stylesheet" href="/assets/css/gestion-personnel.css">
<section class="bloc per-rapport-presence" id="presence" aria-labelledby="per-presence-titre">
    <h2 id="per-presence-titre"><?= e(t('pers.rapport_presence_titre')) ?></h2>
    <p class="aide"><?= e(t('pers.rapport_presence_aide', ['du' => P::jour($bornes['du']), 'au' => P::jour($bornes['au'])])) ?></p>
    <?php if ($lignes === []): ?>
        <p class="aide"><?= e(t('pers.rapport_vide')) ?></p>
    <?php else: ?>
        <div class="defile">
            <table class="tableau per-tableau">
                <thead>
                    <tr>
                        <th scope="col"><?= e(t('pers.col_employe')) ?></th>
                        <th scope="col" class="nombre"><?= e(t('pers.col_heures_travaillees')) ?></th>
                        <th scope="col" class="nombre"><?= e(t('pers.col_retards')) ?></th>
                        <th scope="col" class="nombre"><?= e(t('pers.col_absences')) ?></th>
                        <th scope="col" class="nombre"><?= e(t('pers.col_heures_sup')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lignes as $l): $t = $l['totaux']; ?>
                        <tr>
                            <th scope="row"><?= e($l['employe']['nom']) ?><span class="tre-sous-texte"><?= e((string) $l['employe']['poste']) ?></span></th>
                            <td class="nombre"><?= e(P::duree($t['minutes_travaillees'])) ?> <span class="tre-sous-texte">/ <?= e(P::duree($t['minutes_planifiees'])) ?></span></td>
                            <td class="nombre"><?= (int) $t['retards'] ?><?php if ($t['minutes_retard'] > 0): ?><span class="tre-sous-texte"><?= e(P::duree($t['minutes_retard'])) ?></span><?php endif; ?></td>
                            <td class="nombre"><?= (int) ($t['absences'] + $t['en_attente']) ?><?php if ($t['absences_justifiees'] > 0): ?><span class="tre-sous-texte"><?= e(t('pers.n_justifiees', ['n' => $t['absences_justifiees']])) ?></span><?php endif; ?></td>
                            <td class="nombre"><?= e(P::duree($t['minutes_sup'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?php if ($toutVoir): ?>
        <p class="det-pied"><a class="lien" href="<?= e($lien) ?>"><?= e(t('pers.voir_rapport_presence')) ?></a></p>
    <?php endif; ?>
</section>
