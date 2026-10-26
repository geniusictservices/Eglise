<?php
/**
 * Rapports > Export comptable : choix de la période, aperçu de chaque journal (lignes, totaux par devise) et
 * téléchargement en CSV (tableur ou logiciel comptable).
 * @var array $periode
 * @var array<int, array> $mouvements devise_id => entree, sortie, n
 * @var array $ventes n, annulees, reference
 * @var array<int, array> $depenses devise_id => n, montant
 * @var array<int, array> $devises
 * @var ?array $reference
 */

use App\Controllers\Gestion\DetteAffichage as D;
use App\Controllers\Gestion\RapportAffichage as R;
use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;

$texteperiode = $periode['du'] !== null ? R::periode($periode['du'], $periode['au']) : t('tresorerie.periode_tout');
$lien = static fn (string $journal): string => urlAvec(['export' => 'csv', 'journal' => $journal]);
$ordre = array_flip(array_keys($devises));
uksort($mouvements, static fn ($a, $b) => ($ordre[$a] ?? 999) <=> ($ordre[$b] ?? 999));
uksort($depenses, static fn ($a, $b) => ($ordre[$a] ?? 999) <=> ($ordre[$b] ?? 999));
?>
<link rel="stylesheet" href="/assets/css/gestion-tresorerie.css">
<link rel="stylesheet" href="/assets/css/gestion-rapports.css">

<div class="tre-entete rap-entete">
    <div>
        <h1><?= e(t('rcompta.titre')) ?></h1>
        <p class="aide"><?= e(t('rcompta.aide', ['periode' => $texteperiode])) ?></p>
    </div>
    <?= View::rendre('gestion/rapports/_exporter', ['titre' => t('rcompta.titre'), 'periode' => $texteperiode, 'csv' => false]) ?>
</div>

<section class="bloc tre-filtres rap-filtres" aria-label="<?= e(t('rapports.filtres')) ?>">
    <?= View::rendre('gestion/tresorerie/_periode', ['periode' => $periode, 'periodes' => ['mois', 'mois_precedent', 'annee', 'tout'], 'action' => '/gestion/rapports/export-comptable']) ?>
</section>

<div class="rap-grille">
    <section class="bloc" aria-labelledby="rc-tresorerie" data-journal="tresorerie">
        <h2 id="rc-tresorerie"><?= e(t('rcompta.journal_tresorerie')) ?></h2>
        <p class="aide"><?= e(t('rcompta.journal_tresorerie_aide')) ?></p>
        <?php if ($mouvements === []): ?>
            <p class="aide"><?= e(t('rcompta.vide')) ?></p>
        <?php else: ?>
            <div class="rap-defile">
                <table class="tableau rap-tableau">
                    <thead><tr><th scope="col"><?= e(t('rapports.csv_devise')) ?></th><th scope="col" class="nombre"><?= e(t('rcompta.lignes')) ?></th><th scope="col" class="nombre"><?= e(t('rcompta.col_entree')) ?></th><th scope="col" class="nombre"><?= e(t('rcompta.col_sortie')) ?></th></tr></thead>
                    <tbody>
                        <?php foreach ($mouvements as $did => $m): ?>
                            <tr data-devise="<?= (int) $did ?>"><th scope="row"><?= e($devises[$did]['code'] ?? '?') ?></th><td class="nombre"><?= (int) $m['n'] ?></td>
                                <td class="nombre montant"><?= e(D::montant($m['entree'] ?? 0, (int) $did, $devises)) ?></td><td class="nombre montant"><?= e(D::montant($m['sortie'] ?? 0, (int) $did, $devises)) ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <p><a class="bouton bouton--secondaire" href="<?= e($lien('tresorerie')) ?>" download data-export="tresorerie"><?= e(t('rcompta.telecharger')) ?></a></p>
    </section>

    <section class="bloc" aria-labelledby="rc-ventes" data-journal="ventes">
        <h2 id="rc-ventes"><?= e(t('rcompta.journal_ventes')) ?></h2>
        <p class="aide"><?= e(t('rcompta.journal_ventes_aide')) ?></p>
        <dl class="rap-infos">
            <div><dt><?= e(t('rcompta.tickets')) ?></dt><dd><?= (int) $ventes['n'] ?></dd></div>
            <div><dt><?= e(t('rapports.col_annulees')) ?></dt><dd><?= (int) $ventes['annulees'] ?></dd></div>
            <?php if ($reference): ?><div><dt><?= e(t('rcompta.ca_ref', ['code' => $reference['code']])) ?></dt><dd class="montant"><?= e(A::montant((float) $ventes['reference'], $reference)) ?></dd></div><?php endif; ?>
        </dl>
        <p><a class="bouton bouton--secondaire" href="<?= e($lien('ventes')) ?>" download data-export="ventes"><?= e(t('rcompta.telecharger')) ?></a></p>
    </section>

    <section class="bloc" aria-labelledby="rc-depenses" data-journal="depenses">
        <h2 id="rc-depenses"><?= e(t('rcompta.journal_depenses')) ?></h2>
        <p class="aide"><?= e(t('rcompta.journal_depenses_aide')) ?></p>
        <?php if ($depenses === []): ?>
            <p class="aide"><?= e(t('rcompta.vide')) ?></p>
        <?php else: ?>
            <ul class="rap-liste-simple">
                <?php foreach ($depenses as $did => $d): ?>
                    <li><span><?= e($devises[$did]['code'] ?? '?') ?> · <?= e(t('rcompta.n_paiements', ['n' => $d['n']])) ?></span><span class="montant"><?= e(D::montant($d['montant'], (int) $did, $devises)) ?></span></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <p><a class="bouton bouton--secondaire" href="<?= e($lien('depenses')) ?>" download data-export="depenses"><?= e(t('rcompta.telecharger')) ?></a></p>
    </section>
</div>
<p class="aide"><?= e(t('rcompta.format')) ?></p>
