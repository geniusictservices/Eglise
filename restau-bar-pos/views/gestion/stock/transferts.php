<?php
/**
 * Stock > Transferts : à recevoir et à expédier d'abord, puis ceux de la période.
 * @var array $periode
 * @var ?string $statut
 * @var list<array> $transferts
 * @var bool $peutCreer
 */

use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;
use App\Repositories\TransfertStockRepository;

$badges = ['brouillon' => 'badge--neutre', 'expedie' => 'badge--braise', 'recu' => 'badge--succes', 'annule' => 'badge--erreur'];
$aRecevoir = array_filter($transferts, static fn (array $t): bool => $t['a_recevoir']);
?>
<?= View::rendre('gestion/stock/_onglets', ['flash' => $flash ?? null]) ?>

<div class="tre-entete">
    <div>
        <h1><?= e(t('stock.transferts_titre')) ?></h1>
        <p class="aide"><?= e(t('stock.transferts_aide')) ?></p>
    </div>
    <?php if ($peutCreer): ?>
        <div class="tre-entete__actions"><a class="bouton" href="/gestion/stock/transferts/nouveau">+ <?= e(t('stock.nouveau_transfert')) ?></a></div>
    <?php endif; ?>
</div>

<?php if ($aRecevoir !== []): ?>
    <p class="stk-alerte stk-alerte--bas stk-alerte--bloc" role="status"><?= e(t(count($aRecevoir) > 1 ? 'stock.n_a_recevoir' : 'stock.un_a_recevoir', ['n' => count($aRecevoir)])) ?></p>
<?php endif; ?>

<section class="bloc tre-filtres" aria-label="<?= e(t('stock.filtres')) ?>">
    <?= View::rendre('gestion/tresorerie/_periode', ['periode' => $periode, 'action' => '/gestion/stock/transferts', 'garder' => ['statut' => $statut]]) ?>
    <nav class="tre-puces" aria-label="<?= e(t('stock.statut')) ?>">
        <a class="puce" href="<?= e(urlAvec(['statut' => null])) ?>"<?= $statut === null ? ' aria-current="true"' : '' ?>><?= e(t('stock.tous_statuts')) ?></a>
        <?php foreach (TransfertStockRepository::STATUTS as $s): ?>
            <a class="puce" href="<?= e(urlAvec(['statut' => $s])) ?>"<?= $statut === $s ? ' aria-current="true"' : '' ?>><?= e(t('stock.statut_transfert_' . $s)) ?></a>
        <?php endforeach; ?>
    </nav>
</section>

<section class="bloc" aria-labelledby="stk-tr-titre">
    <h2 id="stk-tr-titre" class="visuellement-cache"><?= e(t('stock.transferts_titre')) ?></h2>
    <?php if ($transferts === []): ?>
        <p class="aide"><?= e(t('stock.aucun_transfert')) ?></p>
    <?php else: ?>
        <div class="tre-defile">
            <table class="tableau stk-table">
                <thead>
                    <tr>
                        <th scope="col"><?= e(t('stock.col_numero')) ?></th>
                        <th scope="col"><?= e(t('stock.col_trajet')) ?></th>
                        <th scope="col"><?= e(t('stock.statut')) ?></th>
                        <th scope="col"><?= e(t('stock.col_qui')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transferts as $t): ?>
                        <tr data-transfert="<?= (int) $t['id'] ?>">
                            <td><a class="stk-numero" href="/gestion/stock/transferts/<?= (int) $t['id'] ?>"><?= e($t['numero']) ?></a>
                                <span class="tre-sous-texte"><?= e(A::dateHeure($t['cree_le'])) ?></span></td>
                            <td><?= e($t['source_nom']) ?> <span aria-hidden="true">→</span><span class="visuellement-cache"><?= e(t('stock.vers')) ?></span> <?= e($t['destination_nom']) ?>
                                <span class="tre-sous-texte"><?= e(($t['source_etablissement'] ?? t('stock.central')) . ($t['source_etablissement'] !== $t['destination_etablissement'] ? ' → ' . ($t['destination_etablissement'] ?? t('stock.central')) : '')) ?> · <?= e(t((int) $t['nb_lignes'] > 1 ? 'stock.n_lignes' : 'stock.une_ligne', ['n' => (int) $t['nb_lignes']])) ?></span></td>
                            <td><span class="badge <?= $badges[$t['statut']] ?>"><?= e(t('stock.statut_transfert_' . $t['statut'])) ?></span>
                                <?php if ((int) $t['nb_ecarts'] > 0): ?><span class="badge badge--erreur"><?= e(t('stock.ecart')) ?></span><?php endif; ?>
                                <?php if ($t['a_recevoir']): ?><a class="lien" href="/gestion/stock/transferts/<?= (int) $t['id'] ?>#recevoir"><?= e(t('stock.recevoir')) ?></a><?php endif; ?>
                                <?php if ($t['a_expedier']): ?><a class="lien" href="/gestion/stock/transferts/<?= (int) $t['id'] ?>"><?= e(t('stock.expedier')) ?></a><?php endif; ?></td>
                            <td class="tre-sous-texte"><?= e(implode(' · ', array_filter([$t['expediteur'] ? t('stock.par_expedie', ['nom' => $t['expediteur']]) : null, $t['receveur'] ? t('stock.par_recu', ['nom' => $t['receveur']]) : null]))) ?: e($t['createur'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
