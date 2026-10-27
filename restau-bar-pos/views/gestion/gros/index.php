<?php
/**
 * Ventes > Vente en gros : ventes en gros de la période, emballages et dettes des clients revendeurs.
 * @var array $periode
 * @var list<array> $depots dépôts qui vendent en gros
 * @var list<array> $ventes
 * @var ?array $client filtre
 * @var array<int, array> $soldes client => nom, pretes, consignes, dettes
 * @var array<int, array> $emballages
 * @var array<int, array> $devises
 * @var bool $voirVentes
 * @var bool $peutParametrer
 * @var bool $voirPrix
 */

use App\Controllers\Gestion\StockAffichage as S;
use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;

$montant = static fn ($m, $deviseId): string => isset($devises[(int) $deviseId]) ? A::montant((float) $m, $devises[(int) $deviseId]) : (string) $m;
$venteNouvelle = isset($_GET['vente']) ? (int) $_GET['vente'] : 0;
$listeEmballages = static function (array $parEmballage) use ($emballages): string {
    $morceaux = [];
    foreach ($parEmballage as $id => $q) {
        $morceaux[] = S::nombre($q) . ' × ' . (isset($emballages[$id]) ? champ($emballages[$id], 'nom') : '#' . $id);
    }
    return implode(' · ', $morceaux);
};
?>
<?= View::rendre('gestion/stock/_onglets', ['flash' => $flash ?? null]) ?>

<div class="tre-entete">
    <div>
        <h1><?= e(t('gros.titre')) ?></h1>
        <p class="aide"><?= e(t('gros.aide')) ?></p>
    </div>
    <div class="tre-entete__actions">
        <?php if ($voirPrix): ?><a class="bouton bouton--secondaire" href="/gestion/gros/prix"><?= e(t('gros.prix_titre')) ?></a><?php endif; ?>
        <?php if ($depots !== []): ?><a class="bouton" href="/gestion/gros/nouvelle"><?= e(t('gros.nouvelle')) ?></a><?php endif; ?>
    </div>
</div>

<?php if ($depots === []): ?>
    <p class="alerte" role="status"><?= e(t('gros.aucun_depot')) ?><?php if ($peutParametrer): ?> <a class="lien" href="/gestion/stock/lieux"><?= e(t('gros.activer_depot')) ?></a><?php endif; ?></p>
<?php else: ?>
    <p class="aide"><?= e(t('gros.depots_actifs', ['liste' => implode(', ', array_map(static fn (array $d): string => $d['nom'] . ' (' . $d['etablissement_nom'] . ')', $depots))])) ?></p>
<?php endif; ?>

<?php if ($soldes !== []): ?>
    <section class="bloc" aria-labelledby="soldes-titre">
        <h2 id="soldes-titre"><?= e(t('gros.soldes_titre')) ?></h2>
        <p class="aide"><?= e(t('gros.soldes_aide')) ?></p>
        <div class="tre-defile">
            <table class="tableau stk-table stk-table--cartes">
                <thead><tr><th scope="col"><?= e(t('gros.client')) ?></th><th scope="col"><?= e(t('gros.pretes')) ?></th><th scope="col"><?= e(t('gros.consignes')) ?></th><th scope="col" class="nombre"><?= e(t('gros.dette')) ?></th></tr></thead>
                <tbody>
                    <?php foreach ($soldes as $id => $s): ?>
                        <tr data-solde-client="<?= (int) $id ?>">
                            <td data-libelle="<?= e(t('gros.client')) ?>"><a class="lien" href="<?= e(urlAvec(['client' => (int) $id])) ?>"><strong><?= e($s['nom']) ?></strong></a></td>
                            <td data-libelle="<?= e(t('gros.pretes')) ?>"><?= $s['pretes'] !== [] ? e($listeEmballages($s['pretes'])) : '—' ?></td>
                            <td data-libelle="<?= e(t('gros.consignes')) ?>"><?= $s['consignes'] !== [] ? e($listeEmballages($s['consignes'])) : '—' ?></td>
                            <td class="nombre" data-libelle="<?= e(t('gros.dette')) ?>"><?= $s['dettes'] !== [] ? e(implode(' · ', array_map(static fn ($m, $d) => $montant($m, $d), $s['dettes'], array_keys($s['dettes'])))) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<section class="bloc tre-filtres" aria-label="<?= e(t('stock.filtres')) ?>">
    <?= View::rendre('gestion/tresorerie/_periode', ['periode' => $periode, 'action' => '/gestion/gros', 'garder' => ['client' => $client ? (int) $client['id'] : null]]) ?>
    <?php if ($client): ?>
        <p><?= e(t('gros.filtre_client', ['client' => $client['nom']])) ?> · <a class="lien" href="<?= e(urlAvec(['client' => null])) ?>"><?= e(t('gros.tous_clients')) ?></a></p>
    <?php endif; ?>
</section>

<section class="bloc" aria-labelledby="ventes-gros-titre">
    <h2 id="ventes-gros-titre"><?= e(t(count($ventes) > 1 ? 'gros.n_ventes' : 'gros.une_vente', ['n' => count($ventes)])) ?></h2>
    <?php if ($ventes === []): ?>
        <p class="aide"><?= e(t('gros.aucune_vente')) ?></p>
    <?php else: ?>
        <div class="tre-defile">
            <table class="tableau stk-table stk-table--cartes">
                <thead>
                    <tr>
                        <th scope="col"><?= e(t('stock.col_date')) ?></th>
                        <th scope="col"><?= e(t('gros.client')) ?></th>
                        <th scope="col" class="nombre"><?= e(t('gros.total')) ?></th>
                        <th scope="col" class="nombre"><?= e(t('gros.paye')) ?></th>
                        <th scope="col" class="nombre"><?= e(t('gros.ardoise')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ventes as $v): ?>
                        <tr data-vente-gros="<?= (int) $v['id'] ?>"<?= $venteNouvelle === (int) $v['id'] ? ' aria-current="true"' : '' ?>>
                            <td data-libelle="<?= e(t('stock.col_date')) ?>"><?= e(A::dateHeure($v['cree_le'])) ?>
                                <span class="tre-sous-texte"><?php if ($voirVentes): ?><a class="lien" href="/gestion/ventes/<?= (int) $v['id'] ?>"><?= e($v['numero']) ?></a><?php else: ?><?= e($v['numero']) ?><?php endif; ?>
                                    · <?= e($v['lieu_nom']) ?><?= $v['statut'] === 'annulee' ? ' · ' . e(t('gros.annulee')) : '' ?></span></td>
                            <td data-libelle="<?= e(t('gros.client')) ?>"><strong><?= e((string) $v['client_nom']) ?></strong>
                                <span class="tre-sous-texte"><?= e(t($v['consigne_facturee'] ? 'gros.mode_facturer' : 'gros.mode_pret')) ?><?= $v['vendeur'] ? ' · ' . e($v['vendeur']) : '' ?></span></td>
                            <td class="nombre" data-libelle="<?= e(t('gros.total')) ?>"><?= e($montant($v['total'], $v['devise_id'])) ?></td>
                            <td class="nombre" data-libelle="<?= e(t('gros.paye')) ?>"><?= e($montant($v['paye'], $v['devise_id'])) ?></td>
                            <td class="nombre" data-libelle="<?= e(t('gros.ardoise')) ?>"><?= (float) $v['credit'] > 0 ? '<strong>' . e($montant($v['credit'], $v['devise_id'])) . '</strong>' : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
