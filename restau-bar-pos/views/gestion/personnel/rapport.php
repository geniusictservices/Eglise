<?php
/**
 * Rapports > Présence : heures, retards, absences, départs anticipés, heures sup par employé ; export CSV ;
 * masse salariale de la période (personnel.paie).
 * @var array $periode
 * @var array $etablissements
 * @var ?array $etab
 * @var list<array{employe: array, totaux: array}> $lignes
 * @var array $totaux
 * @var bool $voirPaie
 * @var array $masse
 */

use App\Controllers\Gestion\PersonnelAffichage as P;
use App\Core\View;
?>
<link rel="stylesheet" href="/assets/css/gestion-tresorerie.css">
<link rel="stylesheet" href="/assets/css/gestion-dettes.css">
<link rel="stylesheet" href="/assets/css/gestion-personnel.css">

<div class="tre-entete">
    <div>
        <h1><?= e(t('pers.rapport_titre')) ?></h1>
        <p class="aide"><?= e(t('pers.rapport_aide')) ?></p>
    </div>
    <div class="tre-entete__actions">
        <a class="bouton bouton--secondaire" href="<?= e(urlAvec(['format' => 'csv'])) ?>"><?= e(t('pers.exporter_csv')) ?></a>
        <button class="bouton bouton--fantome" type="button" onclick="window.print()"><?= e(t('pers.imprimer')) ?></button>
    </div>
</div>

<section class="bloc tre-filtres">
    <?= View::rendre('gestion/tresorerie/_periode', ['periode' => $periode, 'periodes' => ['7j', 'mois', 'mois_precedent', 'annee'], 'action' => '/gestion/rapports/presence', 'garder' => ['etablissement' => $etab['id'] ?? null]]) ?>
    <?= View::rendre('gestion/personnel/_etablissements', ['etablissements' => $etablissements, 'etab' => $etab, 'tous' => true]) ?>
</section>

<?php if ($lignes !== []): ?>
<div class="chiffres per-chiffres">
    <div class="chiffre"><span class="chiffre__valeur"><?= e(P::duree((int) $totaux['minutes_travaillees'])) ?></span><span class="chiffre__libelle"><?= e(t('pers.heures_travaillees_sur', ['prevu' => P::duree((int) $totaux['minutes_planifiees'])])) ?></span></div>
    <div class="chiffre"><span class="chiffre__valeur"><?= (int) $totaux['retards'] ?></span><span class="chiffre__libelle"><?= e(t('pers.retards_n', ['duree' => P::duree((int) $totaux['minutes_retard'])])) ?></span></div>
    <div class="chiffre"><span class="chiffre__valeur"><?= (int) ($totaux['absences'] + $totaux['en_attente']) ?></span><span class="chiffre__libelle"><?= e(t('pers.absences_n', ['j' => (int) $totaux['absences_justifiees']])) ?></span></div>
    <div class="chiffre"><span class="chiffre__valeur"><?= e(P::duree((int) $totaux['minutes_sup'])) ?></span><span class="chiffre__libelle"><?= e(t('pers.col_heures_sup')) ?></span></div>
</div>
<?php endif; ?>

<section class="bloc" aria-labelledby="per-rapport-liste">
    <h2 id="per-rapport-liste"><?= e(t('pers.rapport_presence_titre')) ?></h2>
    <?php if ($lignes === []): ?>
        <p class="aide"><?= e(t('pers.rapport_vide')) ?></p>
    <?php else: ?>
        <div class="defile">
            <table class="tableau per-tableau">
                <thead><tr>
                    <th scope="col"><?= e(t('pers.col_employe')) ?></th>
                    <th scope="col" class="nombre"><?= e(t('pers.col_creneaux')) ?></th>
                    <th scope="col" class="nombre"><?= e(t('pers.col_heures_travaillees')) ?></th>
                    <th scope="col" class="nombre"><?= e(t('pers.col_retards')) ?></th>
                    <th scope="col" class="nombre"><?= e(t('pers.col_absences')) ?></th>
                    <th scope="col" class="nombre"><?= e(t('pers.col_departs_anticipes')) ?></th>
                    <th scope="col" class="nombre"><?= e(t('pers.col_heures_sup')) ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($lignes as $l): $t = $l['totaux']; ?>
                        <tr data-employe="<?= (int) $l['employe']['id'] ?>">
                            <th scope="row"><a class="lien" href="/gestion/personnel/employes/<?= (int) $l['employe']['id'] ?>"><?= e($l['employe']['nom']) ?></a><span class="tre-sous-texte"><?= e(trim((string) $l['employe']['poste'] . ' · ' . $l['employe']['etablissement_nom'], ' ·')) ?></span></th>
                            <td class="nombre"><?= (int) $t['jours_travailles'] ?> / <?= (int) $t['creneaux'] ?></td>
                            <td class="nombre"><?= e(P::duree($t['minutes_travaillees'])) ?><span class="tre-sous-texte">/ <?= e(P::duree($t['minutes_planifiees'])) ?></span></td>
                            <td class="nombre"><?= (int) $t['retards'] ?><?php if ($t['minutes_retard']): ?><span class="tre-sous-texte"><?= e(P::duree($t['minutes_retard'])) ?></span><?php endif; ?></td>
                            <td class="nombre"><?= (int) ($t['absences'] + $t['en_attente']) ?><?php if ($t['absences_justifiees']): ?><span class="tre-sous-texte"><?= e(t('pers.n_justifiees', ['n' => $t['absences_justifiees']])) ?></span><?php endif; ?></td>
                            <td class="nombre"><?= (int) $t['departs_anticipes'] ?></td>
                            <td class="nombre"><?= e(P::duree($t['minutes_sup'])) ?><?php if ($t['minutes_ferie']): ?><span class="tre-sous-texte"><?= e(t('pers.dont_ferie', ['duree' => P::duree($t['minutes_ferie'])])) ?></span><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php if ($voirPaie): ?>
    <?= View::rendre('gestion/personnel/paie/_masse', ['masse' => $masse]) ?>
<?php endif; ?>
