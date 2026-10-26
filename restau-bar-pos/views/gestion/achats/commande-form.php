<?php
/**
 * Nouveau bon de commande, ou modification d'un brouillon.
 * @var ?array $commande
 * @var list<array> $lieux
 * @var list<array> $fournisseurs
 * @var array<int, array> $devises
 * @var array<int, array> $articles
 * @var array $valeurs
 * @var list<array> $lignes
 * @var array<string, string> $erreurs
 * @var int $max
 */

use App\Core\View;

$jeton = e(App\Core\Session::jetonCsrf());
$invalide = static fn (string $champ): string => isset($erreurs[$champ]) ? ' aria-invalid="true" aria-describedby="erreur-' . $champ . '"' : '';
$erreurChamp = static fn (string $champ): string => isset($erreurs[$champ]) ? '<p class="champ__erreur" id="erreur-' . $champ . '">' . e($erreurs[$champ]) . '</p>' : '';
$devise = $devises[(int) $valeurs['devise_id']] ?? null;
$action = $commande ? '/gestion/achats/commandes/' . (int) $commande['id'] . '/modifier' : '/gestion/achats/commandes/nouvelle';
?>
<?= View::rendre('gestion/stock/_onglets', []) ?>

<p class="tre-retour"><a class="lien" href="<?= $commande ? '/gestion/achats/commandes/' . (int) $commande['id'] : '/gestion/achats/commandes' ?>">← <?= e($commande ? $commande['numero'] : t('achats.commandes_titre')) ?></a></p>
<h1><?= e($commande ? t('achats.modifier_commande', ['numero' => $commande['numero']]) : t('achats.nouvelle_commande')) ?></h1>

<?= View::rendre('gestion/tresorerie/_erreurs', ['erreurs' => array_values($erreurs)]) ?>

<form method="post" action="<?= e($action) ?>" class="tre-formulaire stk-formulaire-large" novalidate data-formulaire-lignes data-max="<?= (int) $max ?>" data-texte-base="<?= e(t('stock.soit_js')) ?>" data-texte-total="<?= e(t('achats.total_js')) ?>">
    <input type="hidden" name="csrf" value="<?= $jeton ?>">
    <button type="submit" class="visuellement-cache" tabindex="-1" aria-hidden="true"><?= e(t('stock.enregistrer')) ?></button>
    <section class="bloc">
        <div class="tre-paire">
            <div class="champ">
                <label for="fournisseur_id"><?= e(t('achats.fournisseur')) ?></label>
                <select id="fournisseur_id" name="fournisseur_id"<?= $invalide('fournisseur_id') ?>>
                    <option value=""><?= e(t('achats.choisir_fournisseur')) ?></option>
                    <?php foreach ($fournisseurs as $f): ?><option value="<?= (int) $f['id'] ?>" <?= (int) $valeurs['fournisseur_id'] === (int) $f['id'] ? 'selected' : '' ?>><?= e($f['nom']) ?></option><?php endforeach; ?>
                </select>
                <?= $erreurChamp('fournisseur_id') ?>
            </div>
            <div class="champ">
                <label for="lieu_id"><?= e(t('achats.lieu_livraison')) ?></label>
                <select id="lieu_id" name="lieu_id"<?= $invalide('lieu_id') ?>>
                    <option value=""><?= e(t('stock.choisir_lieu')) ?></option>
                    <?php foreach ($lieux as $l): ?><option value="<?= (int) $l['id'] ?>" <?= (int) $valeurs['lieu_id'] === (int) $l['id'] ? 'selected' : '' ?>><?= e($l['nom'] . ' · ' . ($l['etablissement_nom'] ?? t('stock.central'))) ?></option><?php endforeach; ?>
                </select>
                <?= $erreurChamp('lieu_id') ?>
            </div>
        </div>
        <div class="tre-paire">
            <div class="champ">
                <label for="date_commande"><?= e(t('achats.date_commande')) ?></label>
                <input type="date" id="date_commande" name="date_commande" value="<?= e($valeurs['date_commande']) ?>"<?= $invalide('date_commande') ?>>
                <?= $erreurChamp('date_commande') ?>
            </div>
            <div class="champ">
                <label for="date_prevue"><?= e(t('achats.date_prevue')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label>
                <input type="date" id="date_prevue" name="date_prevue" value="<?= e($valeurs['date_prevue']) ?>"<?= $invalide('date_prevue') ?>>
                <?= $erreurChamp('date_prevue') ?>
            </div>
        </div>
        <div class="champ tre-champ-court">
            <label for="devise_id"><?= e(t('achats.devise_facture')) ?></label>
            <select id="devise_id" name="devise_id" data-devise-facture<?= $invalide('devise_id') ?>>
                <?php foreach ($devises as $d): ?><option value="<?= (int) $d['id'] ?>" data-symbole="<?= e($d['symbole']) ?>" <?= (int) $valeurs['devise_id'] === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['code']) ?></option><?php endforeach; ?>
            </select>
        </div>
    </section>
    <section class="bloc" aria-labelledby="lignes-titre">
        <h2 id="lignes-titre"><?= e(t('achats.lignes_commande')) ?></h2>
        <?= $erreurChamp('lignes') ?>
        <div class="stk-lignes" data-lignes>
            <?php foreach (array_values($lignes) as $i => $ligne): ?>
                <?= View::rendre('gestion/stock/_ligne-article', ['i' => $i, 'ligne' => $ligne, 'articles' => $articles, 'erreurs' => $erreurs, 'avecPrix' => true, 'achat' => true, 'symbole' => $devise['symbole'] ?? '']) ?>
            <?php endforeach; ?>
        </div>
        <template data-modele-ligne>
            <?= View::rendre('gestion/stock/_ligne-article', ['i' => '__i__', 'ligne' => ['conditionnement_id' => null, 'quantite' => '', 'prix' => ''], 'articles' => $articles, 'erreurs' => [], 'avecPrix' => true, 'achat' => true, 'symbole' => $devise['symbole'] ?? '']) ?>
        </template>
        <button class="bouton bouton--secondaire stk-ajouter-ligne" type="submit" name="action" value="ajouter_ligne" formnovalidate data-ajouter-ligne>+ <?= e(t('stock.ajouter_ligne')) ?></button>
        <p class="tre-resume" data-total-lignes aria-live="polite" hidden></p>
    </section>
    <section class="bloc">
        <div class="champ">
            <label for="notes"><?= e(t('stock.notes')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label>
            <textarea id="notes" name="notes" rows="2" maxlength="2000"><?= e($valeurs['notes']) ?></textarea>
        </div>
        <div class="tre-actions">
            <button class="bouton" type="submit"><?= e(t('achats.enregistrer_commande')) ?></button>
            <a class="bouton bouton--secondaire" href="/gestion/achats/commandes"><?= e(t('tresorerie.annuler')) ?></a>
        </div>
    </section>
</form>
<script src="/assets/js/gestion-stock.js" defer></script>
