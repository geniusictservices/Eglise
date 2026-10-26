<?php
/**
 * Stock > Fiches techniques : produits vendus avec ou sans fiche, coût de revient ; tableau des marges
 * par format vendu (avec rapports.marges).
 * @var list<array> $produits
 * @var array<int, array{cout: ?float, complet: bool}> $couts
 * @var list<array> $marges
 * @var bool $voirMarges
 * @var ?array $reference
 * @var bool $peutModifier
 */

use App\Controllers\Gestion\StockAffichage as S;
use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;

$avec = array_filter($produits, static fn (array $p): bool => $p['recette_id'] !== null && (int) $p['nb_ingredients'] > 0);
$sans = array_filter($produits, static fn (array $p): bool => $p['recette_id'] === null || (int) $p['nb_ingredients'] === 0);
?>
<?= View::rendre('gestion/stock/_onglets', ['flash' => $flash ?? null]) ?>

<div class="tre-entete">
    <div>
        <h1><?= e(t('stock.recettes_titre')) ?></h1>
        <p class="aide"><?= e(t('stock.recettes_aide')) ?></p>
    </div>
</div>

<section class="bloc" aria-labelledby="avec-fiche">
    <h2 id="avec-fiche"><?= e(t('stock.avec_fiche', ['n' => count($avec)])) ?></h2>
    <?php if ($avec === []): ?>
        <p class="aide"><?= e(t('stock.aucune_fiche')) ?></p>
    <?php else: ?>
        <ul class="stk-cartes">
            <?php foreach ($avec as $p): $c = $couts[(int) $p['id']] ?? null; ?>
                <li>
                    <a class="stk-carte" href="/gestion/stock/recettes/<?= (int) $p['id'] ?>" data-recette="<?= (int) $p['id'] ?>">
                        <span class="stk-carte__nom"><?= App\Core\Icones::svg('recette', 20) ?><strong><?= e(champ($p, 'nom')) ?></strong></span>
                        <span class="tre-sous-texte"><?= e(champ(['nom_fr' => $p['cat_nom_fr'], 'nom_en' => $p['cat_nom_en']], 'nom')) ?> · <?= e(t((int) $p['nb_ingredients'] > 1 ? 'stock.n_ingredients' : 'stock.un_ingredient', ['n' => (int) $p['nb_ingredients']])) ?>
                            <?php if (!$p['recette_active']): ?> · <?= e(t('stock.fiche_inactive')) ?><?php endif; ?></span>
                        <?php if ($voirMarges && $c !== null && $c['cout'] !== null && $reference): ?>
                            <span class="stk-carte__cout"><?= e(t('stock.cout_par', ['cout' => A::montant($c['cout'], $reference), 'unite' => S::unite((string) $p['unite_base'])])) ?><?= $c['complet'] ? '' : ' · ' . e(t('stock.cout_incomplet')) ?></span>
                        <?php endif; ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<?php if ($sans !== [] && $peutModifier): ?>
    <section class="bloc" aria-labelledby="sans-fiche">
        <h2 id="sans-fiche"><?= e(t('stock.sans_fiche')) ?></h2>
        <p class="aide"><?= e(t('stock.sans_fiche_aide')) ?></p>
        <ul class="stk-puces-liens">
            <?php foreach ($sans as $p): ?>
                <li><a class="puce" href="/gestion/stock/recettes/<?= (int) $p['id'] ?>"><?= e(champ($p, 'nom')) ?></a></li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<?php if ($voirMarges && $reference && $marges !== []): ?>
    <section class="bloc" aria-labelledby="marges-titre">
        <h2 id="marges-titre"><?= e(t('stock.marges_titre')) ?></h2>
        <p class="aide"><?= e(t('stock.marges_aide', ['code' => $reference['code']])) ?></p>
        <div class="tre-defile">
            <table class="tableau stk-table stk-marges">
                <thead>
                    <tr>
                        <th scope="col"><?= e(t('stock.col_article')) ?></th>
                        <th scope="col" class="nombre"><?= e(t('stock.col_cout')) ?></th>
                        <th scope="col" class="nombre"><?= e(t('stock.col_prix')) ?></th>
                        <th scope="col" class="nombre"><?= e(t('stock.col_marge')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($marges as $m): ?>
                        <tr data-marge="<?= (int) $m['conditionnement_id'] ?>">
                            <td><strong><?= e(champ($m, 'nom')) ?></strong> <span class="tre-sous-texte"><?= e(champ(['nom_fr' => $m['format_fr'], 'nom_en' => $m['format_en']], 'nom')) ?><?= $m['recette'] ? ' · ' . e(t('stock.fiche')) : '' ?></span></td>
                            <td class="nombre"><?= $m['cout'] !== null ? e(A::montant($m['cout'], $reference)) . ($m['complet'] ? '' : ' *') : '<span class="tre-sous-texte">' . e(t('stock.sans_cout')) . '</span>' ?></td>
                            <td class="nombre"><?= $m['prix'] !== null ? e(A::montant($m['prix'], $reference)) : '—' ?></td>
                            <td class="nombre"><?php if ($m['marge'] !== null): ?><strong class="<?= $m['marge'] < 0 ? 'tre-negatif' : '' ?>"><?= e(A::montant($m['marge'], $reference)) ?></strong>
                                <span class="tre-sous-texte" data-taux-marge="<?= e((string) round((float) $m['taux'] * 100, 1)) ?>"><?= e(S::nombre(round((float) $m['taux'] * 100, 1))) ?> %</span><?php else: ?>—<?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="aide"><?= e(t('stock.cout_incomplet_note')) ?></p>
    </section>
<?php endif; ?>
