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
 * @var list<array> $preparations préparations intermédiaires (sauce, marinade) avec leur fiche
 * @var array{nom_fr: string, nom_en: string, unite: string} $valeursPreparation
 * @var array<string, string> $erreursPreparation
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

<section class="bloc" aria-labelledby="preparations-titre" id="preparations">
    <h2 id="preparations-titre"><?= e(t('stock.prep_titre', ['n' => count($preparations)])) ?></h2>
    <p class="aide"><?= e(t('stock.prep_aide')) ?></p>
    <?php if ($preparations !== []): ?>
        <ul class="stk-cartes">
            <?php foreach ($preparations as $p): $c = $couts[(int) $p['id']] ?? null; ?>
                <li>
                    <a class="stk-carte" href="/gestion/stock/recettes/<?= (int) $p['id'] ?>" data-preparation="<?= (int) $p['id'] ?>">
                        <span class="stk-carte__nom"><?= App\Core\Icones::svg('recette', 20) ?><strong><?= e(champ($p, 'nom')) ?></strong></span>
                        <span class="tre-sous-texte"><?= $p['recette_id'] !== null && (int) $p['nb_ingredients'] > 0
                            ? e(t((int) $p['nb_ingredients'] > 1 ? 'stock.n_ingredients' : 'stock.un_ingredient', ['n' => (int) $p['nb_ingredients']])) . ' · ' . e(t('stock.prep_rendement', ['n' => S::nombre((float) $p['rendement']), 'unite' => S::unite((string) $p['unite_base'], (float) $p['rendement'])]))
                            : e(t('stock.prep_sans_fiche')) ?></span>
                        <?php if ($voirMarges && $c !== null && $c['cout'] !== null && $reference): ?>
                            <span class="stk-carte__cout"><?= e(t('stock.cout_par', ['cout' => A::montant($c['cout'], $reference), 'unite' => S::unite((string) $p['unite_base'])])) ?></span>
                        <?php endif; ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
        <p><a class="lien" href="/gestion/stock/productions"><?= e(t('stock.prep_vers_productions')) ?></a></p>
    <?php endif; ?>
    <?php if ($peutModifier): ?>
        <?= View::rendre('gestion/tresorerie/_erreurs', ['erreurs' => array_values($erreursPreparation)]) ?>
        <details class="stk-nouvelle-prep"<?= $erreursPreparation !== [] || $preparations === [] ? ' open' : '' ?>>
            <summary class="bouton bouton--secondaire"><?= e(t('stock.prep_nouvelle')) ?></summary>
            <form method="post" action="/gestion/stock/recettes/preparation" class="tre-formulaire stk-form-ligne" novalidate data-form-preparation>
                <input type="hidden" name="csrf" value="<?= e(App\Core\Session::jetonCsrf()) ?>">
                <div class="champ">
                    <label for="prep-nom-fr"><?= e(t('stock.prep_nom_fr')) ?></label>
                    <input id="prep-nom-fr" name="nom_fr" maxlength="128" required value="<?= e($valeursPreparation['nom_fr']) ?>"<?= isset($erreursPreparation['nom_fr']) ? ' aria-invalid="true"' : '' ?>>
                </div>
                <div class="champ">
                    <label for="prep-nom-en"><?= e(t('stock.prep_nom_en')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label>
                    <input id="prep-nom-en" name="nom_en" maxlength="128" value="<?= e($valeursPreparation['nom_en']) ?>">
                </div>
                <div class="champ">
                    <label for="prep-unite"><?= e(t('stock.prep_unite')) ?></label>
                    <select id="prep-unite" name="unite"<?= isset($erreursPreparation['unite']) ? ' aria-invalid="true"' : '' ?>>
                        <?php foreach (S::UNITES as $u): ?>
                            <option value="<?= e($u) ?>" <?= $valeursPreparation['unite'] === $u ? 'selected' : '' ?>><?= e(S::unite($u, 2)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="tre-actions"><button class="bouton" type="submit"><?= e(t('stock.prep_creer')) ?></button></div>
            </form>
        </details>
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
