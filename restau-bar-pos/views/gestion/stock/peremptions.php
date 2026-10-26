<?php
/**
 * Stock > Péremptions : lots périmés (à déclarer en perte) et lots qui périment bientôt, par lieu.
 * @var array<int, array> $lieux
 * @var ?array $lieu
 * @var ?int $jours horizon choisi (null : réglage de chaque établissement)
 * @var list<int> $choixJours
 * @var bool $tous tous les lots datés
 * @var list<array> $perimes
 * @var list<array> $bientot
 * @var array<int, array> $articles
 * @var array<int, float> $cumps
 * @var array<int, bool> $peutPerte par lieu
 * @var bool $voirValeur
 * @var ?array $reference
 */

use App\Controllers\Gestion\StockAffichage as S;
use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;

$jeton = e(App\Core\Session::jetonCsrf());
$tableau = static function (array $lots, bool $perime) use ($articles, $cumps, $peutPerte, $voirValeur, $reference, $jeton, $lieu): string {
    ob_start(); ?>
    <div class="tre-defile">
        <table class="tableau stk-table stk-table--cartes">
            <thead>
                <tr>
                    <th scope="col"><?= e(t('stock.col_article')) ?></th>
                    <th scope="col"><?= e(t('stock.peremption')) ?></th>
                    <th scope="col" class="nombre"><?= e(t('stock.lot_restant')) ?></th>
                    <?php if ($voirValeur): ?><th scope="col" class="nombre"><?= e(t('stock.col_valeur')) ?></th><?php endif; ?>
                    <th scope="col"><span class="visuellement-cache"><?= e(t('stock.col_actions')) ?></span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lots as $l):
                    $article = $articles[(int) $l['produit_id']] ?? ['unite_base' => $l['unite_base'], 'conditionnements' => []];
                    $jours = (int) $l['jours_restants'];
                    $valeur = isset($cumps[(int) $l['produit_id']]) ? (float) $l['quantite_restante'] * $cumps[(int) $l['produit_id']] : null; ?>
                    <tr data-lot="<?= (int) $l['id'] ?>">
                        <td data-libelle="<?= e(t('stock.col_article')) ?>"><strong><?= e(champ($l, 'nom')) ?></strong>
                            <span class="tre-sous-texte"><?= e($l['lieu_nom'] . ' · ' . ($l['etablissement_nom'] ?? t('stock.central'))) ?><?= $l['numero'] ? ' · ' . e(t('stock.lot_numero', ['numero' => $l['numero']])) : '' ?></span></td>
                        <td data-libelle="<?= e(t('stock.peremption')) ?>"><?= e(A::date($l['peremption'])) ?>
                            <span class="badge <?= $perime ? 'badge--erreur' : ($jours <= 1 ? 'badge--braise' : 'badge--neutre') ?>" data-jours="<?= $jours ?>"><?= e($perime
                                ? t(-$jours > 1 ? 'stock.perime_depuis_n' : 'stock.perime_depuis_1', ['n' => -$jours])
                                : ($jours === 0 ? t('stock.perime_aujourdhui') : t($jours > 1 ? 'stock.perime_dans_n' : 'stock.perime_dans_1', ['n' => $jours]))) ?></span></td>
                        <td class="nombre" data-libelle="<?= e(t('stock.lot_restant')) ?>"><?= e(S::quantite($l['quantite_restante'], $article)) ?></td>
                        <?php if ($voirValeur): ?><td class="nombre" data-libelle="<?= e(t('stock.col_valeur')) ?>"><?= $valeur !== null && $reference ? e(A::montant($valeur, $reference)) : '—' ?></td><?php endif; ?>
                        <td class="stk-actions-ligne">
                            <?php if (!empty($peutPerte[(int) $l['lieu_id']])): ?>
                                <form method="post" action="/gestion/stock/peremptions/<?= (int) $l['id'] ?>/perte">
                                    <input type="hidden" name="csrf" value="<?= $jeton ?>">
                                    <?php if ($lieu): ?><input type="hidden" name="retour_lieu" value="<?= (int) $lieu['id'] ?>"><?php endif; ?>
                                    <button class="bouton <?= $perime ? 'bouton--danger' : 'bouton--secondaire' ?>" type="submit"><?= e(t('stock.lot_declarer_perte')) ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php return (string) ob_get_clean();
};
?>
<?= View::rendre('gestion/stock/_onglets', ['flash' => $flash ?? null]) ?>

<div class="tre-entete">
    <div>
        <h1><?= e(t('stock.peremptions_titre')) ?></h1>
        <p class="aide"><?= e(t('stock.peremptions_aide')) ?></p>
    </div>
</div>

<section class="bloc tre-filtres" aria-label="<?= e(t('stock.filtres')) ?>">
    <nav class="tre-puces stk-lieux" aria-label="<?= e(t('stock.choisir_lieu')) ?>">
        <a class="puce" href="<?= e(urlAvec(['lieu' => null])) ?>"<?= $lieu === null ? ' aria-current="true"' : '' ?>><?= e(t('stock.tous_lieux')) ?></a>
        <?php foreach ($lieux as $l): ?>
            <a class="puce" href="<?= e(urlAvec(['lieu' => (int) $l['id']])) ?>"<?= $lieu && (int) $lieu['id'] === (int) $l['id'] ? ' aria-current="true"' : '' ?>><?= e($l['nom']) ?> <span class="stk-puce-etab">· <?= e($l['etablissement_nom'] ?? t('stock.central')) ?></span></a>
        <?php endforeach; ?>
    </nav>
    <nav class="tre-puces" aria-label="<?= e(t('stock.peremption_horizon')) ?>">
        <a class="puce" href="<?= e(urlAvec(['jours' => null, 'tous' => null])) ?>"<?= $jours === null && !$tous ? ' aria-current="true"' : '' ?>><?= e(t('stock.peremption_reglage')) ?></a>
        <?php foreach ($choixJours as $j): ?>
            <a class="puce" href="<?= e(urlAvec(['jours' => $j, 'tous' => null])) ?>"<?= $jours === $j && !$tous ? ' aria-current="true"' : '' ?>><?= e(t($j > 1 ? 'stock.peremption_n_jours' : 'stock.peremption_1_jour', ['n' => $j])) ?></a>
        <?php endforeach; ?>
        <a class="puce" href="<?= e(urlAvec(['jours' => null, 'tous' => '1'])) ?>"<?= $tous ? ' aria-current="true"' : '' ?>><?= e(t('stock.peremption_tous_lots')) ?></a>
    </nav>
</section>

<section class="bloc" aria-labelledby="lots-perimes">
    <h2 id="lots-perimes"><?= e(t('stock.lots_perimes', ['n' => count($perimes)])) ?></h2>
    <?php if ($perimes === []): ?>
        <p class="aide"><?= e(t('stock.aucun_lot_perime')) ?></p>
    <?php else: ?>
        <p class="aide"><?= e(t('stock.lots_perimes_aide')) ?></p>
        <?= $tableau($perimes, true) ?>
    <?php endif; ?>
</section>

<section class="bloc" aria-labelledby="lots-bientot">
    <h2 id="lots-bientot"><?= e(t($tous ? 'stock.lots_dates' : 'stock.lots_bientot', ['n' => count($bientot)])) ?></h2>
    <?php if ($bientot === []): ?>
        <p class="aide"><?= e(t('stock.aucun_lot_bientot')) ?></p>
    <?php else: ?>
        <?= $tableau($bientot, false) ?>
    <?php endif; ?>
</section>
