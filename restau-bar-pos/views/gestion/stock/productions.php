<?php
/**
 * Stock > Productions : produire une préparation (sauce, marinade, bouillon) d'après sa fiche, avec l'aperçu
 * des ingrédients qui vont sortir du lieu ; productions de la période.
 * @var array $periode
 * @var list<array> $lieux lieux où la personne peut produire
 * @var list<array> $preparations préparations avec une fiche active
 * @var array<int, list<array{nom: string, q: float, unite1: string, uniteN: string, stock: array<int, float>}>> $besoins par unité produite
 * @var list<array> $productions
 * @var bool $voirValeur
 * @var ?array $reference
 * @var bool $peutFiches
 * @var array $valeurs
 * @var array<string, string> $erreurs
 */

use App\Controllers\Gestion\StockAffichage as S;
use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;

$jeton = e(App\Core\Session::jetonCsrf());
$invalide = static fn (string $champ): string => isset($erreurs[$champ]) ? ' aria-invalid="true" aria-describedby="erreur-' . $champ . '"' : '';
$erreurChamp = static fn (string $champ): string => isset($erreurs[$champ]) ? '<p class="champ__erreur" id="erreur-' . $champ . '">' . e($erreurs[$champ]) . '</p>' : '';
?>
<?= View::rendre('gestion/stock/_onglets', ['flash' => $flash ?? null]) ?>

<div class="tre-entete">
    <div>
        <h1><?= e(t('stock.productions_titre')) ?></h1>
        <p class="aide"><?= e(t('stock.productions_aide')) ?></p>
    </div>
    <?php if ($peutFiches): ?><a class="bouton bouton--secondaire" href="/gestion/stock/recettes#preparations"><?= e(t('stock.prep_gerer')) ?></a><?php endif; ?>
</div>

<?php if ($lieux !== [] && $preparations !== []): ?>
    <section class="bloc" aria-labelledby="nouvelle-production">
        <h2 id="nouvelle-production"><?= e(t('stock.production_nouvelle')) ?></h2>
        <?= View::rendre('gestion/tresorerie/_erreurs', ['erreurs' => array_values($erreurs)]) ?>
        <form method="post" action="/gestion/stock/productions" class="stk-form-grille" novalidate data-production>
            <input type="hidden" name="csrf" value="<?= $jeton ?>">
            <div class="champ">
                <label for="lieu_id"><?= e(t('stock.lieu')) ?></label>
                <select id="lieu_id" name="lieu_id"<?= $invalide('lieu_id') ?> data-production-lieu>
                    <option value=""><?= e(t('stock.choisir_lieu')) ?></option>
                    <?php foreach ($lieux as $l): ?>
                        <option value="<?= (int) $l['id'] ?>" <?= (int) $valeurs['lieu_id'] === (int) $l['id'] ? 'selected' : '' ?>><?= e($l['nom'] . ' · ' . ($l['etablissement_nom'] ?? t('stock.central'))) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $erreurChamp('lieu_id') ?>
            </div>
            <div class="champ">
                <label for="produit_id"><?= e(t('stock.prep_preparation')) ?></label>
                <select id="produit_id" name="produit_id"<?= $invalide('produit_id') ?> data-production-preparation>
                    <option value=""><?= e(t('stock.prep_choisir')) ?></option>
                    <?php foreach ($preparations as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" data-unite="<?= e(S::unite((string) $p['unite_base'], 2)) ?>" <?= (int) $valeurs['produit_id'] === (int) $p['id'] ? 'selected' : '' ?>><?= e(champ($p, 'nom') . ' (' . S::unite((string) $p['unite_base'], 2) . ')') ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $erreurChamp('produit_id') ?>
            </div>
            <div class="champ">
                <label for="quantite"><?= e(t('stock.prep_quantite')) ?></label>
                <input id="quantite" name="quantite" inputmode="decimal" autocomplete="off" value="<?= e($valeurs['quantite']) ?>"<?= $invalide('quantite') ?> data-production-quantite>
                <?= $erreurChamp('quantite') ?>
            </div>
            <div class="champ">
                <label for="peremption"><?= e(t('stock.peremption')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label>
                <input type="date" id="peremption" name="peremption" value="<?= e($valeurs['peremption']) ?>"<?= $invalide('peremption') ?>>
                <?= $erreurChamp('peremption') ?>
            </div>
            <div class="champ">
                <label for="date"><?= e(t('tresorerie.champ_date')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label>
                <input type="datetime-local" id="date" name="date" value="<?= e($valeurs['date']) ?>"<?= $invalide('date') ?>>
                <?= $erreurChamp('date') ?>
            </div>
            <div class="champ stk-champ-large">
                <label for="notes"><?= e(t('stock.notes')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label>
                <input id="notes" name="notes" maxlength="255" value="<?= e($valeurs['notes']) ?>">
            </div>
            <div class="stk-apercu-production" data-production-apercu hidden aria-live="polite">
                <h3><?= e(t('stock.prep_apercu')) ?></h3>
                <ul class="stk-liste-lignes" data-production-lignes></ul>
            </div>
            <div class="tre-actions"><button class="bouton" type="submit"><?= e(t('stock.prep_enregistrer')) ?></button></div>
            <script type="application/json" data-production-besoins><?= json_encode($besoins, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
            <span hidden data-texte-manque="<?= e(t('stock.prep_manque')) ?>" data-texte-stock="<?= e(t('stock.prep_en_stock')) ?>"></span>
        </form>
    </section>
<?php elseif ($lieux !== []): ?>
    <section class="bloc">
        <p class="aide"><?= e(t('stock.prep_aucune')) ?></p>
        <?php if ($peutFiches): ?><p><a class="bouton" href="/gestion/stock/recettes#preparations"><?= e(t('stock.prep_nouvelle')) ?></a></p><?php endif; ?>
    </section>
<?php endif; ?>

<section class="bloc tre-filtres" aria-label="<?= e(t('stock.filtres')) ?>">
    <?= View::rendre('gestion/tresorerie/_periode', ['periode' => $periode, 'action' => '/gestion/stock/productions', 'garder' => []]) ?>
</section>

<section class="bloc" aria-labelledby="liste-productions">
    <h2 id="liste-productions"><?= e(t(count($productions) > 1 ? 'stock.n_productions' : 'stock.une_production', ['n' => count($productions)])) ?></h2>
    <?php if ($productions === []): ?>
        <p class="aide"><?= e(t('stock.aucune_production')) ?></p>
    <?php else: ?>
        <div class="tre-defile">
            <table class="tableau stk-table stk-table--cartes">
                <thead>
                    <tr>
                        <th scope="col"><?= e(t('stock.col_date')) ?></th>
                        <th scope="col"><?= e(t('stock.prep_preparation')) ?></th>
                        <th scope="col" class="nombre"><?= e(t('stock.col_quantite')) ?></th>
                        <th scope="col"><?= e(t('stock.peremption')) ?></th>
                        <?php if ($voirValeur): ?><th scope="col" class="nombre"><?= e(t('stock.prep_cout')) ?></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($productions as $p): $q = (float) $p['quantite']; ?>
                        <tr data-production="<?= (int) $p['id'] ?>">
                            <td class="tre-journal__date" data-libelle="<?= e(t('stock.col_date')) ?>"><?= e(A::dateHeure($p['date_production'])) ?><span class="tre-sous-texte"><?= e((string) $p['numero']) ?></span></td>
                            <td data-libelle="<?= e(t('stock.prep_preparation')) ?>"><strong><?= e(champ($p, 'nom')) ?></strong><span class="tre-sous-texte"><?= e($p['lieu_nom'] . ' · ' . ($p['etablissement_nom'] ?? t('stock.central')) . ($p['auteur'] ? ' · ' . $p['auteur'] : '')) ?></span>
                                <?php if ($p['notes']): ?><span class="tre-sous-texte"><?= e($p['notes']) ?></span><?php endif; ?></td>
                            <td class="nombre" data-libelle="<?= e(t('stock.col_quantite')) ?>"><?= e(S::nombre($q) . ' ' . S::unite((string) $p['unite_base'], $q)) ?></td>
                            <td data-libelle="<?= e(t('stock.peremption')) ?>"><?= $p['peremption'] ? e(A::jour($p['peremption'])) : '—' ?></td>
                            <?php if ($voirValeur): ?><td class="nombre" data-libelle="<?= e(t('stock.prep_cout')) ?>"><?= $p['cout_total'] !== null && $reference && $q > 0
                                ? e(A::montant((float) $p['cout_total'], $reference)) . '<span class="tre-sous-texte">' . e(t('stock.cout_par', ['cout' => A::montant((float) $p['cout_total'] / $q, $reference), 'unite' => S::unite((string) $p['unite_base'])])) . '</span>'
                                : '—' ?></td><?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<script src="/assets/js/gestion-stock.js" defer></script>
