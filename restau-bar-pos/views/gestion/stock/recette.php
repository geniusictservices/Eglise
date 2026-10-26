<?php
/**
 * Fiche technique d'un produit vendu : rendement, ingrédients (unité de base, part utilisable), coût de
 * revient et marge de chaque format vendu.
 * @var array $produit
 * @var ?array $recette
 * @var array<int, array> $articles ingrédients possibles
 * @var array<int, float> $cumps
 * @var ?array $cout
 * @var list<array> $formats
 * @var list<array> $marges
 * @var bool $voirMarges
 * @var ?array $reference
 * @var bool $peutModifier
 * @var array $valeurs rendement, notes, actif
 * @var list<array> $lignes produit_id, quantite, rendement_pct
 * @var array<string, string> $erreurs
 * @var int $max
 * @var bool $preparation fiche d'une préparation intermédiaire (elle se produit, elle ne se vend pas)
 * @var array<int, int> $preparations identifiants des préparations (ingrédients possibles d'un plat)
 */

use App\Controllers\Gestion\StockAffichage as S;
use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;

$jeton = e(App\Core\Session::jetonCsrf());
$groupes = [];
foreach ($articles as $a) {
    $groupes[$a['categorie']][] = $a;
}
$preparations ??= [];
$preparation ??= false;
$ligneIngredient = static function (string|int $i, array $ligne) use ($groupes, $erreurs, $peutModifier, $preparations): string {
    $p = 'ingredients[' . $i . ']';
    $numero = is_int($i) ? $i + 1 : '__n__';
    ob_start(); ?>
    <fieldset class="stk-ligne stk-ligne--ingredient" data-ligne>
        <legend class="stk-ligne__titre"><?= e(t('stock.ingredient_n', ['n' => $numero])) ?></legend>
        <div class="stk-ligne__champs">
            <div class="champ stk-ligne__format">
                <label for="ing-<?= $i ?>-produit"><?= e(t('stock.ingredient')) ?></label>
                <select id="ing-<?= $i ?>-produit" name="<?= $p ?>[produit_id]" data-ingredient<?= isset($erreurs['ingredients-' . $i . '-produit']) ? ' aria-invalid="true"' : '' ?><?= $peutModifier ? '' : ' disabled' ?>>
                    <option value=""><?= e(t('stock.choisir_ingredient')) ?></option>
                    <?php foreach ($groupes as $categorie => $liste): ?>
                        <optgroup label="<?= e($categorie) ?>">
                            <?php foreach ($liste as $a): ?>
                                <option value="<?= (int) $a['id'] ?>"<?= isset($preparations[(int) $a['id']]) ? ' data-preparation' : '' ?> data-unite="<?= e(S::unite((string) $a['unite_base'], 2)) ?>" <?= (int) ($ligne['produit_id'] ?? 0) === (int) $a['id'] ? 'selected' : '' ?>><?= e(champ($a, 'nom') . ' (' . S::unite((string) $a['unite_base'], 2) . ')') ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="champ stk-ligne__quantite">
                <label for="ing-<?= $i ?>-quantite"><?= e(t('stock.quantite_utilisable')) ?></label>
                <input id="ing-<?= $i ?>-quantite" name="<?= $p ?>[quantite]" inputmode="decimal" value="<?= e($ligne['quantite']) ?>"<?= isset($erreurs['ingredients-' . $i . '-quantite']) ? ' aria-invalid="true"' : '' ?><?= $peutModifier ? '' : ' disabled' ?>>
            </div>
            <div class="champ stk-ligne__prix">
                <label for="ing-<?= $i ?>-rendement"><?= e(t('stock.part_utilisable')) ?></label>
                <input id="ing-<?= $i ?>-rendement" name="<?= $p ?>[rendement_pct]" inputmode="decimal" value="<?= e($ligne['rendement_pct']) ?>"<?= isset($erreurs['ingredients-' . $i . '-rendement']) ? ' aria-invalid="true"' : '' ?><?= $peutModifier ? '' : ' disabled' ?>>
            </div>
        </div>
        <?php if ($peutModifier): ?><button type="button" class="lien stk-ligne__retirer" data-retirer-ligne hidden><?= e(t('stock.retirer_ligne')) ?></button><?php endif; ?>
    </fieldset>
    <?php return (string) ob_get_clean();
};
?>
<?= View::rendre('gestion/stock/_onglets', ['flash' => $flash ?? null]) ?>

<p class="tre-retour"><a class="lien" href="/gestion/stock/recettes<?= $preparation ? '#preparations' : '' ?>">← <?= e(t('stock.recettes_titre')) ?></a></p>
<h1><?= e(t($preparation ? 'stock.prep_fiche_titre' : 'stock.recette_titre', ['produit' => champ($produit, 'nom')])) ?></h1>
<?php if ($preparation): ?>
    <p class="aide"><?= e(t('stock.prep_fiche_aide', ['unite' => S::unite((string) $produit['unite_base'], 2)])) ?></p>
    <?php if ($recette): ?><p><a class="bouton bouton--secondaire" href="/gestion/stock/productions?preparation=<?= (int) $produit['id'] ?>"><?= e(t('stock.prep_produire')) ?></a></p><?php endif; ?>
<?php else: ?>
    <p class="aide"><?= e(t('stock.recette_aide', ['unite' => S::unite((string) $produit['unite_base'])])) ?></p>
<?php endif; ?>

<?= View::rendre('gestion/tresorerie/_erreurs', ['erreurs' => array_values($erreurs)]) ?>

<div class="stk-deux-colonnes">
    <form method="post" action="/gestion/stock/recettes/<?= (int) $produit['id'] ?>" class="tre-formulaire" novalidate data-formulaire-lignes data-max="<?= (int) $max ?>" data-nom-lignes="ingredients">
        <input type="hidden" name="csrf" value="<?= $jeton ?>">
        <button type="submit" class="visuellement-cache" tabindex="-1" aria-hidden="true"><?= e(t('stock.enregistrer')) ?></button>
        <section class="bloc">
            <div class="champ tre-champ-moyen">
                <label for="rendement"><?= e(t('stock.rendement_fiche', ['unite' => S::unite((string) $produit['unite_base'], 2)])) ?></label>
                <input id="rendement" name="rendement" inputmode="decimal" value="<?= e($valeurs['rendement']) ?>"<?= isset($erreurs['rendement']) ? ' aria-invalid="true"' : '' ?><?= $peutModifier ? '' : ' disabled' ?>>
                <p class="champ__aide"><?= e(t('stock.rendement_aide')) ?></p>
            </div>
        </section>
        <section class="bloc" aria-labelledby="ingredients-titre">
            <h2 id="ingredients-titre"><?= e(t('stock.ingredients')) ?></h2>
            <p class="aide"><?= e(t($preparation ? 'stock.prep_ingredients_aide' : 'stock.ingredients_aide')) ?></p>
            <div class="stk-lignes" data-lignes>
                <?php foreach (array_values($lignes) as $i => $ligne): ?><?= $ligneIngredient($i, $ligne) ?><?php endforeach; ?>
            </div>
            <?php if ($peutModifier): ?>
                <template data-modele-ligne><?= $ligneIngredient('__i__', ['produit_id' => null, 'quantite' => '', 'rendement_pct' => '100']) ?></template>
                <button class="bouton bouton--secondaire stk-ajouter-ligne" type="submit" name="action" value="ajouter_ligne" formnovalidate data-ajouter-ligne>+ <?= e(t('stock.ajouter_ingredient')) ?></button>
            <?php endif; ?>
        </section>
        <section class="bloc">
            <div class="champ">
                <label for="notes"><?= e(t('stock.preparation')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label>
                <textarea id="notes" name="notes" rows="3" maxlength="2000"<?= $peutModifier ? '' : ' disabled' ?>><?= e($valeurs['notes']) ?></textarea>
            </div>
            <label class="coche"><input type="checkbox" name="actif" value="1" <?= $valeurs['actif'] ? 'checked' : '' ?><?= $peutModifier ? '' : ' disabled' ?>> <?= e(t('stock.fiche_active')) ?></label>
            <p class="aide"><?= e(t($preparation ? 'stock.prep_active_aide' : 'stock.fiche_active_aide')) ?></p>
            <?php if ($peutModifier): ?>
                <div class="tre-actions"><button class="bouton" type="submit"><?= e(t('stock.enregistrer_fiche')) ?></button></div>
            <?php endif; ?>
        </section>
    </form>

    <div>
        <?php if ($voirMarges && $recette && $reference): ?>
            <section class="bloc" aria-labelledby="cout-titre">
                <h2 id="cout-titre"><?= e(t('stock.cout_revient')) ?></h2>
                <ul class="stk-liste-lignes">
                    <?php foreach ($recette['ingredients'] as $ing):
                        $conso = (float) $ing['quantite'] / max(0.001, (float) $recette['rendement']) / max(0.01, (float) $ing['rendement_pct'] / 100);
                        $c = $cumps[(int) $ing['produit_id']] ?? null; ?>
                        <li><span><?= e(champ($ing, 'nom')) ?> <span class="tre-sous-texte"><?= e(S::nombre(round($conso, 3)) . ' ' . S::unite((string) $ing['unite_base'], $conso)) ?></span></span>
                            <span class="montant"><?= $c !== null ? e(A::montant($conso * $c, $reference)) : e(t('stock.sans_cout')) ?></span></li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($cout && $cout['cout'] !== null): ?>
                    <p class="stk-total-cout"><?= e(t('stock.cout_par', ['cout' => A::montant($cout['cout'], $reference), 'unite' => S::unite((string) $produit['unite_base'])])) ?></p>
                    <?php if (!$cout['complet']): ?><p class="aide"><?= e(t('stock.cout_incomplet')) ?></p><?php endif; ?>
                <?php endif; ?>
                <?php foreach ($marges as $m): ?>
                    <p class="stk-marge" data-marge="<?= (int) $m['conditionnement_id'] ?>"><strong><?= e(champ(['nom_fr' => $m['format_fr'], 'nom_en' => $m['format_en']], 'nom')) ?></strong> ·
                        <?= e(t('stock.marge_ligne', ['prix' => $m['prix'] !== null ? A::montant($m['prix'], $reference) : '—', 'marge' => $m['marge'] !== null ? A::montant($m['marge'], $reference) : '—',
                            'taux' => $m['taux'] !== null ? S::nombre(round($m['taux'] * 100, 1)) : '—'])) ?></p>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>
        <?php if ($recette && $peutModifier): ?>
            <section class="bloc">
                <details class="tre-supprimer">
                    <summary class="lien tre-lien-danger"><?= e(t('stock.supprimer_fiche')) ?></summary>
                    <p><?= e(t('stock.supprimer_fiche_texte')) ?></p>
                    <form method="post" action="/gestion/stock/recettes/<?= (int) $produit['id'] ?>/supprimer"><input type="hidden" name="csrf" value="<?= $jeton ?>"><button class="bouton bouton--danger" type="submit"><?= e(t('stock.supprimer_fiche_oui')) ?></button></form>
                </details>
            </section>
        <?php endif; ?>
    </div>
</div>
<script src="/assets/js/gestion-stock.js" defer></script>
