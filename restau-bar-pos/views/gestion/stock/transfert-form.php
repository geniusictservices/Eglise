<?php
/**
 * Stock > Transferts > Nouveau : source (lieux où la personne peut transférer), destination (tout lieu actif),
 * lignes ; enregistrer en brouillon ou confirmer la sortie tout de suite.
 * @var list<array> $sources
 * @var list<array> $destinations
 * @var array<int, array> $articles
 * @var array $niveaux
 * @var array $valeurs source, destination, notes
 * @var list<array> $lignes
 * @var array<string, string> $erreurs
 * @var string[] $avertissements
 * @var int $max
 */

use App\Controllers\Gestion\StockAffichage as S;
use App\Core\View;

$jeton = e(App\Core\Session::jetonCsrf());
$invalide = static fn (string $champ): string => isset($erreurs[$champ]) ? ' aria-invalid="true" aria-describedby="erreur-' . $champ . '"' : '';
$erreurChamp = static fn (string $champ): string => isset($erreurs[$champ]) ? '<p class="champ__erreur" id="erreur-' . $champ . '">' . e($erreurs[$champ]) . '</p>' : '';
$stocks = [];
foreach ($niveaux as $lieuId => $parProduit) {
    foreach ($parProduit as $produitId => $n) {
        if (isset($articles[$produitId])) {
            $stocks[$lieuId][$produitId] = S::quantite($n['quantite'], $articles[$produitId]);
        }
    }
}
$libelle = static fn (array $l): string => $l['nom'] . ' · ' . ($l['etablissement_nom'] ?? t('stock.central')) . ' (' . t('stock.type_lieu_' . $l['type']) . ')';
?>
<?= View::rendre('gestion/stock/_onglets', []) ?>

<p class="tre-retour"><a class="lien" href="/gestion/stock/transferts">← <?= e(t('stock.transferts_titre')) ?></a></p>
<h1><?= e(t('stock.nouveau_transfert')) ?></h1>
<p class="aide"><?= e(t('stock.transfert_aide')) ?></p>

<?= View::rendre('gestion/tresorerie/_erreurs', ['erreurs' => array_values($erreurs)]) ?>

<form method="post" action="/gestion/stock/transferts/nouveau" class="tre-formulaire" novalidate data-formulaire-lignes data-max="<?= (int) $max ?>"
      data-stocks="<?= e((string) json_encode($stocks)) ?>" data-texte-stock="<?= e(t('stock.stock_source_js')) ?>" data-texte-base="<?= e(t('stock.soit_js')) ?>">
    <input type="hidden" name="csrf" value="<?= $jeton ?>">
    <button type="submit" class="visuellement-cache" tabindex="-1" aria-hidden="true" name="action" value="brouillon"><?= e(t('stock.enregistrer_brouillon')) ?></button>
    <?= View::rendre('gestion/tresorerie/_avertissements', ['avertissements' => $avertissements]) ?>
    <?php if ($avertissements !== []): ?><input type="hidden" name="action" value="expedier"><?php endif; ?>

    <section class="bloc">
        <div class="tre-paire">
            <div class="champ">
                <label for="source"><?= e(t('stock.source')) ?></label>
                <select id="source" name="source" data-source<?= $invalide('source') ?>>
                    <option value=""><?= e(t('stock.choisir_lieu')) ?></option>
                    <?php foreach ($sources as $l): ?>
                        <option value="<?= (int) $l['id'] ?>" <?= (int) $valeurs['source'] === (int) $l['id'] ? 'selected' : '' ?>><?= e($libelle($l)) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $erreurChamp('source') ?>
            </div>
            <div class="champ">
                <label for="destination"><?= e(t('stock.destination')) ?></label>
                <select id="destination" name="destination"<?= $invalide('destination') ?>>
                    <option value=""><?= e(t('stock.choisir_lieu')) ?></option>
                    <?php foreach ($destinations as $l): ?>
                        <option value="<?= (int) $l['id'] ?>" <?= (int) $valeurs['destination'] === (int) $l['id'] ? 'selected' : '' ?>><?= e($libelle($l)) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $erreurChamp('destination') ?>
            </div>
        </div>
    </section>

    <section class="bloc" aria-labelledby="lignes-titre">
        <h2 id="lignes-titre"><?= e(t('stock.lignes_transfert')) ?></h2>
        <?= $erreurChamp('lignes') ?>
        <div class="stk-lignes" data-lignes>
            <?php foreach (array_values($lignes) as $i => $ligne): ?>
                <?= View::rendre('gestion/stock/_ligne-article', ['i' => $i, 'ligne' => $ligne, 'articles' => $articles, 'erreurs' => $erreurs, 'avecPrix' => false]) ?>
            <?php endforeach; ?>
        </div>
        <template data-modele-ligne>
            <?= View::rendre('gestion/stock/_ligne-article', ['i' => '__i__', 'ligne' => ['conditionnement_id' => null, 'quantite' => ''], 'articles' => $articles, 'erreurs' => [], 'avecPrix' => false]) ?>
        </template>
        <button class="bouton bouton--secondaire stk-ajouter-ligne" type="submit" name="action" value="ajouter_ligne" formnovalidate data-ajouter-ligne>+ <?= e(t('stock.ajouter_ligne')) ?></button>
    </section>

    <section class="bloc">
        <div class="champ">
            <label for="notes"><?= e(t('stock.notes')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label>
            <input id="notes" name="notes" maxlength="255" value="<?= e($valeurs['notes']) ?>">
        </div>
        <div class="tre-actions">
            <button class="bouton" type="submit" name="action" value="expedier"><?= e(t('stock.confirmer_sortie')) ?></button>
            <button class="bouton bouton--secondaire" type="submit" name="action" value="brouillon"><?= e(t('stock.enregistrer_brouillon')) ?></button>
        </div>
        <p class="aide"><?= e(t('stock.confirmer_sortie_aide')) ?></p>
    </section>
</form>
<script src="/assets/js/gestion-stock.js" defer></script>
