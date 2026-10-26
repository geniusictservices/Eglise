<?php
/**
 * Stock > Ajuster : correction d'un stock (ajouter, retirer, ou nouveau stock compté) ou stock d'ouverture,
 * avec un motif obligatoire.
 * @var list<array> $lieux lieux où la personne peut ajuster
 * @var array<int, array> $articles
 * @var array $niveaux lieu => produit => niveau
 * @var array $valeurs
 * @var array<string, string> $erreurs
 */

use App\Core\View;

$jeton = e(App\Core\Session::jetonCsrf());
$invalide = static fn (string $champ): string => isset($erreurs[$champ]) ? ' aria-invalid="true" aria-describedby="erreur-' . $champ . '"' : '';
$erreurChamp = static fn (string $champ): string => isset($erreurs[$champ]) ? '<p class="champ__erreur" id="erreur-' . $champ . '">' . e($erreurs[$champ]) . '</p>' : '';
// Stock actuel de chaque article dans chaque lieu, pour l'afficher en direct
$stocks = [];
foreach ($niveaux as $lieuId => $parProduit) {
    foreach ($parProduit as $produitId => $n) {
        $stocks[$lieuId][$produitId] = isset($articles[$produitId]) ? App\Controllers\Gestion\StockAffichage::quantite($n['quantite'], $articles[$produitId]) : '';
    }
}
?>
<?= View::rendre('gestion/stock/_onglets', []) ?>

<p class="tre-retour"><a class="lien" href="/gestion/stock">← <?= e(t('stock.titre')) ?></a></p>
<h1><?= e(t('stock.ajuster_titre')) ?></h1>
<p class="aide"><?= e(t('stock.ajuster_aide')) ?></p>

<?= View::rendre('gestion/tresorerie/_erreurs', ['erreurs' => array_values($erreurs)]) ?>

<form method="post" action="/gestion/stock/ajuster" class="tre-formulaire" novalidate id="form-ajuster" data-stocks="<?= e((string) json_encode($stocks)) ?>">
    <input type="hidden" name="csrf" value="<?= $jeton ?>">
    <section class="bloc">
        <div class="tre-paire">
            <div class="champ">
                <label for="lieu_id"><?= e(t('stock.lieu')) ?></label>
                <select id="lieu_id" name="lieu_id" data-lieu<?= $invalide('lieu_id') ?>>
                    <option value=""><?= e(t('stock.choisir_lieu')) ?></option>
                    <?php foreach ($lieux as $l): ?>
                        <option value="<?= (int) $l['id'] ?>" <?= (int) $valeurs['lieu_id'] === (int) $l['id'] ? 'selected' : '' ?>><?= e($l['nom'] . ' · ' . ($l['etablissement_nom'] ?? t('stock.central'))) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $erreurChamp('lieu_id') ?>
            </div>
            <div class="champ">
                <label for="conditionnement_id"><?= e(t('stock.article_format')) ?></label>
                <select id="conditionnement_id" name="conditionnement_id" data-format<?= $invalide('conditionnement_id') ?>>
                    <option value=""><?= e(t('stock.choisir_article')) ?></option>
                    <?= View::rendre('gestion/stock/_options-formats', ['articles' => $articles, 'choisi' => $valeurs['conditionnement_id'], 'achat' => false]) ?>
                </select>
                <?= $erreurChamp('conditionnement_id') ?>
            </div>
        </div>
        <p class="champ__aide stk-stock-actuel" data-stock-actuel aria-live="polite" data-texte="<?= e(t('stock.stock_actuel_js')) ?>"></p>

        <fieldset class="tre-groupe-champs stk-sens">
            <legend><?= e(t('stock.sens')) ?></legend>
            <div class="tre-moyens tre-moyens--compact">
                <?php foreach (['compter', 'ajouter', 'retirer'] as $sens): ?>
                    <label class="tre-moyen"><input type="radio" name="sens" value="<?= $sens ?>" <?= $valeurs['sens'] === $sens ? 'checked' : '' ?>><span><?= e(t('stock.sens_' . $sens)) ?></span></label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <div class="tre-paire">
            <div class="champ">
                <label for="quantite"><?= e(t('stock.quantite_format')) ?></label>
                <input id="quantite" name="quantite" inputmode="decimal" autocomplete="off" value="<?= e($valeurs['quantite']) ?>"<?= $invalide('quantite') ?>>
                <?= $erreurChamp('quantite') ?>
                <p class="champ__aide"><?= e(t('stock.quantite_aide')) ?></p>
            </div>
            <div class="champ">
                <label for="type"><?= e(t('stock.type_ajustement')) ?></label>
                <select id="type" name="type">
                    <option value="ajustement" <?= $valeurs['type'] === 'ajustement' ? 'selected' : '' ?>><?= e(t('stock.type_ajustement')) ?></option>
                    <option value="ouverture" <?= $valeurs['type'] === 'ouverture' ? 'selected' : '' ?>><?= e(t('stock.type_ouverture')) ?></option>
                </select>
            </div>
        </div>
        <div class="champ">
            <label for="motif"><?= e(t('stock.motif')) ?></label>
            <input id="motif" name="motif" maxlength="255" value="<?= e($valeurs['motif']) ?>" placeholder="<?= e(t('stock.motif_exemple')) ?>"<?= $invalide('motif') ?>>
            <?= $erreurChamp('motif') ?>
        </div>
        <div class="champ tre-champ-moyen">
            <label for="date"><?= e(t('tresorerie.champ_date')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label>
            <input type="datetime-local" id="date" name="date" value="<?= e($valeurs['date']) ?>"<?= $invalide('date') ?>>
            <?= $erreurChamp('date') ?>
        </div>
        <div class="tre-actions">
            <button class="bouton" type="submit"><?= e(t('stock.enregistrer_ajustement')) ?></button>
            <a class="bouton bouton--secondaire" href="/gestion/stock"><?= e(t('tresorerie.annuler')) ?></a>
        </div>
    </section>
</form>
<script src="/assets/js/gestion-stock.js" defer></script>
