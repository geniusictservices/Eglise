<?php
/**
 * Événements > Rentabilité : les soirées passées de la période, classées par résultat (recettes − coûts de la
 * soirée − coût des marchandises s'il est connu), avec les totaux.
 * @var array $etablissements
 * @var ?int $etablissement
 * @var array $periode
 * @var list<array> $soirees
 * @var array $totaux
 * @var bool $voirMarges
 * @var ?array $locale
 * @var ?array $reference
 * @var float $taux
 */

use App\Controllers\Gestion\RapportAffichage as R;
use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;

$enLocale = static fn (float $m): string => R::arrondi($m, $locale);
$enRef = static fn (float $m): string => $reference ? A::montant($m, $reference) : '';
$texteperiode = $periode['du'] !== null ? R::periode($periode['du'], $periode['au']) : t('tresorerie.periode_tout');
$maxR = $soirees !== [] ? max(array_map(static fn (array $s) => abs($s['bilan']['resultat']), $soirees)) ?: 1 : 1;
?>
<link rel="stylesheet" href="/assets/css/gestion-tresorerie.css">
<link rel="stylesheet" href="/assets/css/gestion-rapports.css">

<div class="tre-entete rap-entete">
    <div>
        <h1><?= e(t('evenements.rentabilite_titre')) ?></h1>
        <p class="aide"><?= e(t('evenements.rentabilite_aide', ['periode' => $texteperiode])) ?></p>
    </div>
    <?= View::rendre('gestion/rapports/_exporter', ['titre' => t('evenements.rentabilite_titre'), 'periode' => $texteperiode]) ?>
</div>

<section class="bloc tre-filtres rap-filtres" aria-label="<?= e(t('rapports.filtres')) ?>">
    <?= View::rendre('gestion/tresorerie/_periode', ['periode' => $periode, 'periodes' => ['mois', 'mois_precedent', 'annee', 'tout'], 'action' => '/gestion/evenements/rentabilite', 'garder' => ['etablissement' => $etablissement]]) ?>
    <?php if (count($etablissements) > 1): ?>
        <nav class="tre-puces" aria-label="<?= e(t('gestion.etablissement')) ?>">
            <a class="puce" href="<?= e(urlAvec(['etablissement' => null])) ?>"<?= !$etablissement ? ' aria-current="true"' : '' ?>><?= e(t('gventes.tous_etablissements')) ?></a>
            <?php foreach ($etablissements as $etab): ?>
                <a class="puce" href="<?= e(urlAvec(['etablissement' => (int) $etab['id']])) ?>"<?= $etablissement === (int) $etab['id'] ? ' aria-current="true"' : '' ?>><?= e($etab['nom']) ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>
</section>

<?php if ($soirees === []): ?>
    <p class="bloc aide" data-vide="soirees"><?= e(t('evenements.aucune_passee')) ?></p>
<?php else: ?>
    <section class="rap-kpis" aria-label="<?= e(t('rapports.total')) ?>">
        <div class="rap-kpi" data-kpi="soirees"><span class="rap-kpi__libelle"><?= e(t('evenements.soirees')) ?></span><strong class="rap-kpi__valeur"><?= count($soirees) ?></strong><span class="rap-kpi__second"><?= e(t('rapports.n_tickets', ['n' => $totaux['tickets']])) ?></span></div>
        <div class="rap-kpi" data-kpi="recettes"><span class="rap-kpi__libelle"><?= e(t('evenements.recettes')) ?></span><strong class="rap-kpi__valeur montant"><?= e($enLocale($totaux['ca_locale'])) ?></strong><span class="rap-kpi__second montant"><?= e($enRef($totaux['ca_reference'])) ?></span></div>
        <div class="rap-kpi" data-kpi="couts"><span class="rap-kpi__libelle"><?= e(t('evenements.couts')) ?></span><strong class="rap-kpi__valeur montant"><?= e($enRef($totaux['couts'] + $totaux['marchandises'])) ?></strong><span class="rap-kpi__second montant">≈ <?= e($enLocale(($totaux['couts'] + $totaux['marchandises']) * $taux)) ?></span></div>
        <div class="rap-kpi rap-kpi--resultat<?= $totaux['resultat'] < 0 ? ' rap-kpi--negatif' : '' ?>" data-kpi="resultat"><span class="rap-kpi__libelle"><?= e(t('evenements.resultat')) ?></span><strong class="rap-kpi__valeur montant"><?= e($enRef($totaux['resultat'])) ?></strong><span class="rap-kpi__second montant">≈ <?= e($enLocale($totaux['resultat'] * $taux)) ?></span></div>
    </section>

    <section class="bloc" aria-labelledby="evt-classement">
        <h2 id="evt-classement"><?= e(t('evenements.classement')) ?></h2>
        <div class="rap-defile">
            <table class="tableau rap-tableau" data-tableau="rentabilite">
                <thead><tr>
                    <th scope="col" class="nombre">#</th>
                    <th scope="col"><?= e(t('evenements.soiree')) ?></th>
                    <th scope="col" class="nombre"><?= e(t('rapports.col_tickets')) ?></th>
                    <th scope="col" class="nombre"><?= e(t('evenements.recettes')) ?></th>
                    <th scope="col" class="nombre"><?= e(t('evenements.couts')) ?></th>
                    <?php if ($voirMarges): ?><th scope="col" class="nombre"><?= e(t('evenements.marchandises')) ?></th><?php endif; ?>
                    <th scope="col" class="nombre"><?= e(t('evenements.resultat')) ?></th>
                    <th scope="col"><?= e(t('evenements.marge')) ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($soirees as $rang => $ev): $b = $ev['bilan']; ?>
                        <tr data-evenement="<?= (int) $ev['id'] ?>">
                            <td class="nombre rap-rang"><?= $rang + 1 ?></td>
                            <th scope="row"><a href="/gestion/evenements/<?= (int) $ev['id'] ?>"><?= e($ev['titre']) ?></a><span class="rap-sous"><?= e(A::jour($ev['debut'])) ?> · <?= e($ev['etablissement_nom']) ?></span></th>
                            <td class="nombre"><?= (int) $b['totaux']['tickets'] ?></td>
                            <td class="nombre" data-cle="recettes"><span class="montant"><?= e($enLocale($b['totaux']['ca_locale'])) ?></span><span class="rap-sous montant"><?= e($enRef($b['totaux']['ca_reference'])) ?></span></td>
                            <td class="nombre montant" data-cle="couts"><?= e($enRef($b['couts'])) ?></td>
                            <?php if ($voirMarges): ?><td class="nombre montant"><?= e($enRef($b['marchandises'])) ?></td><?php endif; ?>
                            <td class="nombre" data-cle="resultat"><strong class="montant<?= $b['resultat'] < 0 ? ' tre-negatif' : '' ?>"><?= e($enRef($b['resultat'])) ?></strong></td>
                            <td class="rap-part"><span class="rap-jauge" aria-hidden="true"><span style="width: <?= round(max(0, $b['resultat']) / $maxR * 100, 1) ?>%"></span></span><span class="nombre"><?= $b['marge_pct'] !== null ? e(R::pourcent($b['marge_pct'])) : '—' ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>
