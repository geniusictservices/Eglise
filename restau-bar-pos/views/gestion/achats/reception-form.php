<?php
/**
 * Achats > Réceptions > Nouvelle : livraison d'une commande ou libre, achat au marché sans facture (photo
 * du ticket), lignes dans les formats d'achat, emballages rendus et consigne payée, paiement immédiat.
 * @var list<array> $lieux
 * @var list<array> $fournisseurs
 * @var array<int, array> $devises
 * @var ?array $devise devise choisie
 * @var array<int, array> $articles
 * @var array<int, array> $emballages
 * @var array<int, array> $comptes
 * @var array<int, array> $devisesTresorerie
 * @var bool $peutPayer
 * @var ?array $commande
 * @var array $valeurs
 * @var list<array> $lignes
 * @var array $saisiesEmballages
 * @var list<array> $paiements
 * @var array<string, string> $erreurs
 * @var string[] $avertissements
 * @var int $max
 * @var int $maxPaiements
 * @var int $octetsMax
 */

use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;

$jeton = e(App\Core\Session::jetonCsrf());
$invalide = static fn (string $champ): string => isset($erreurs[$champ]) ? ' aria-invalid="true" aria-describedby="erreur-' . $champ . '"' : '';
$erreurChamp = static fn (string $champ): string => isset($erreurs[$champ]) ? '<p class="champ__erreur" id="erreur-' . $champ . '">' . e($erreurs[$champ]) . '</p>' : '';
$devisesJs = array_map([A::class, 'deviseJs'], $devisesTresorerie);
?>
<?= View::rendre('gestion/stock/_onglets', []) ?>

<p class="tre-retour"><a class="lien" href="<?= $commande ? '/gestion/achats/commandes/' . (int) $commande['id'] : '/gestion/achats/receptions' ?>">← <?= e($commande ? $commande['numero'] : t('achats.receptions_titre')) ?></a></p>
<h1><?= e(t('achats.nouvelle_reception')) ?><?= $commande ? ' · ' . e($commande['numero']) : '' ?></h1>
<p class="aide"><?= e(t('achats.reception_aide')) ?></p>

<?= View::rendre('gestion/tresorerie/_erreurs', ['erreurs' => array_values($erreurs)]) ?>

<form method="post" action="/gestion/achats/receptions/nouvelle" class="tre-formulaire stk-formulaire-large" enctype="multipart/form-data" novalidate
      data-formulaire-lignes data-max="<?= (int) $max ?>" data-texte-base="<?= e(t('stock.soit_js')) ?>"
      data-devises="<?= e((string) json_encode($devisesJs)) ?>" data-texte-total="<?= e(t('achats.total_js')) ?>"
      data-texte-solde="<?= e(t('transferts.solde_js')) ?>" data-texte-decouvert="<?= e(t('transferts.decouvert_js')) ?>" data-max-paiements="<?= (int) $maxPaiements ?>">
    <input type="hidden" name="csrf" value="<?= $jeton ?>">
    <button type="submit" class="visuellement-cache" tabindex="-1" aria-hidden="true"><?= e(t('achats.enregistrer_reception')) ?></button>
    <?php if ($commande): ?><input type="hidden" name="commande_id" value="<?= (int) $commande['id'] ?>"><?php endif; ?>
    <?= View::rendre('gestion/tresorerie/_avertissements', ['avertissements' => $avertissements]) ?>

    <section class="bloc">
        <h2><?= e(t('achats.section_livraison')) ?></h2>
        <div class="tre-paire">
            <div class="champ">
                <label for="fournisseur_id"><?= e(t('achats.fournisseur')) ?></label>
                <select id="fournisseur_id" name="fournisseur_id"<?= $invalide('fournisseur_id') ?><?= $commande ? ' data-verrouille' : '' ?>>
                    <option value=""><?= e(t('achats.choisir_fournisseur')) ?></option>
                    <?php foreach ($fournisseurs as $f): ?>
                        <option value="<?= (int) $f['id'] ?>" data-devise="<?= (int) $f['devise_id'] ?>" data-delai="<?= (int) $f['delai_paiement'] ?>" <?= (int) $valeurs['fournisseur_id'] === (int) $f['id'] ? 'selected' : '' ?>><?= e($f['nom'] . ' · ' . t('achats.type_' . $f['type'])) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $erreurChamp('fournisseur_id') ?><?= $erreurChamp('commande_id') ?>
            </div>
            <div class="champ">
                <label for="lieu_id"><?= e(t('achats.lieu_reception')) ?></label>
                <select id="lieu_id" name="lieu_id"<?= $invalide('lieu_id') ?>>
                    <option value=""><?= e(t('stock.choisir_lieu')) ?></option>
                    <?php foreach ($lieux as $l): ?>
                        <option value="<?= (int) $l['id'] ?>" <?= (int) $valeurs['lieu_id'] === (int) $l['id'] ? 'selected' : '' ?>><?= e($l['nom'] . ' · ' . ($l['etablissement_nom'] ?? t('stock.central')) . ' (' . t('stock.type_lieu_' . $l['type']) . ')') ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $erreurChamp('lieu_id') ?>
            </div>
        </div>
        <div class="tre-paire">
            <div class="champ">
                <label for="date"><?= e(t('achats.date_reception')) ?></label>
                <input type="datetime-local" id="date" name="date" value="<?= e($valeurs['date']) ?>"<?= $invalide('date') ?>>
                <?= $erreurChamp('date') ?>
            </div>
            <div class="champ">
                <label for="devise_id"><?= e(t('achats.devise_facture')) ?></label>
                <select id="devise_id" name="devise_id" data-devise-facture<?= $invalide('devise_id') ?>>
                    <?php foreach ($devises as $d): ?><option value="<?= (int) $d['id'] ?>" data-symbole="<?= e($d['symbole']) ?>" <?= (int) $valeurs['devise_id'] === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['code']) ?></option><?php endforeach; ?>
                </select>
                <?= $erreurChamp('devise_id') ?>
            </div>
        </div>
    </section>

    <section class="bloc">
        <h2><?= e(t('achats.section_facture')) ?></h2>
        <label class="coche"><input type="checkbox" name="sans_facture" value="1" <?= $valeurs['sans_facture'] ? 'checked' : '' ?>> <?= e(t('achats.sans_facture')) ?></label>
        <div class="tre-paire">
            <div class="champ">
                <label for="facture_numero"><?= e(t('achats.facture_numero')) ?></label>
                <input id="facture_numero" name="facture_numero" maxlength="64" value="<?= e($valeurs['facture_numero']) ?>"<?= $invalide('facture_numero') ?>>
                <?= $erreurChamp('facture_numero') ?>
            </div>
            <div class="champ">
                <label for="echeance"><?= e(t('achats.echeance')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label>
                <input type="date" id="echeance" name="echeance" value="<?= e($valeurs['echeance']) ?>"<?= $invalide('echeance') ?>>
                <?= $erreurChamp('echeance') ?>
                <p class="champ__aide"><?= e(t('achats.echeance_aide')) ?></p>
            </div>
        </div>
        <div class="champ">
            <label for="justificatif"><?= e(t('achats.justificatif')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label>
            <input type="file" id="justificatif" name="justificatif" accept="image/jpeg,image/png,image/webp" capture="environment"<?= $invalide('justificatif') ?>>
            <?= $erreurChamp('justificatif') ?>
            <p class="champ__aide"><?= e(t('achats.justificatif_aide', ['taille' => App\Core\Images::tailleLisible($octetsMax)])) ?></p>
        </div>
    </section>

    <section class="bloc" aria-labelledby="lignes-titre">
        <h2 id="lignes-titre"><?= e(t('achats.lignes_reception')) ?></h2>
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

    <?php if ($emballages !== []): ?>
        <section class="bloc" aria-labelledby="emb-titre">
            <h2 id="emb-titre"><?= e(t('achats.section_emballages')) ?></h2>
            <p class="aide"><?= e(t('achats.emballages_aide')) ?></p>
            <div class="tre-defile">
                <table class="tableau stk-table">
                    <thead><tr><th scope="col"><?= e(t('emballages.article')) ?></th><th scope="col"><?= e(t('achats.vides_rendus')) ?></th><th scope="col"><?= e(t('achats.consigne_payee')) ?></th></tr></thead>
                    <tbody>
                        <?php foreach ($emballages as $id => $emb): $s = is_array($saisiesEmballages[$id] ?? null) ? $saisiesEmballages[$id] : []; ?>
                            <tr>
                                <th scope="row"><?= e(champ($emb, 'nom')) ?><span class="tre-sous-texte"><?= isset($devises[(int) $emb['devise_id']]) ? e(t('emballages.consigne_de', ['valeur' => A::montant($emb['valeur_consigne'], $devises[(int) $emb['devise_id']])])) : '' ?></span></th>
                                <td><label class="visuellement-cache" for="emb-<?= (int) $id ?>-rendus"><?= e(t('achats.vides_rendus') . ' · ' . champ($emb, 'nom')) ?></label>
                                    <input class="saisie stk-saisie-courte" id="emb-<?= (int) $id ?>-rendus" name="emballages[<?= (int) $id ?>][rendus]" inputmode="decimal" value="<?= e(is_string($s['rendus'] ?? null) ? $s['rendus'] : '') ?>"<?= $invalide('emballages-' . $id . '-rendus') ?>></td>
                                <td><label class="visuellement-cache" for="emb-<?= (int) $id ?>-payes"><?= e(t('achats.consigne_payee') . ' · ' . champ($emb, 'nom')) ?></label>
                                    <input class="saisie stk-saisie-courte" id="emb-<?= (int) $id ?>-payes" name="emballages[<?= (int) $id ?>][payes]" inputmode="decimal" value="<?= e(is_string($s['payes'] ?? null) ? $s['payes'] : '') ?>"<?= $invalide('emballages-' . $id . '-payes') ?>></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($peutPayer): ?>
        <section class="bloc" id="paiements" aria-labelledby="paiement-titre">
            <h2 id="paiement-titre"><?= e(t('achats.section_paiement')) ?></h2>
            <label class="coche"><input type="checkbox" name="payer" value="1" data-payer <?= $valeurs['payer'] ? 'checked' : '' ?>> <?= e(t('achats.payer_maintenant')) ?></label>
            <?= $erreurChamp('paiements') ?>
            <div data-bloc-paiements>
                <p class="aide"><?= e(t('achats.paiement_aide')) ?></p>
                <div class="tre-paiements" data-paiements>
                    <?php foreach (array_values($paiements) as $i => $ligne): ?>
                        <?= View::rendre('gestion/depenses/_paiement', ['i' => $i, 'ligne' => $ligne, 'comptes' => $comptes, 'devises' => $devisesTresorerie, 'erreurs' => $erreurs]) ?>
                    <?php endforeach; ?>
                </div>
                <template data-modele-paiement>
                    <?= View::rendre('gestion/depenses/_paiement', ['i' => '__i__', 'ligne' => ['compte_id' => null, 'devise_id' => null, 'montant' => ''], 'comptes' => $comptes, 'devises' => $devisesTresorerie, 'erreurs' => []]) ?>
                </template>
                <button class="bouton bouton--secondaire tre-ajouter-paiement" type="submit" name="action" value="ajouter_paiement" formnovalidate data-ajouter-paiement>+ <?= e(t('depenses.ajouter_paiement')) ?></button>
            </div>
        </section>
    <?php endif; ?>

    <section class="bloc">
        <div class="champ">
            <label for="notes"><?= e(t('stock.notes')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label>
            <textarea id="notes" name="notes" rows="2" maxlength="2000"><?= e($valeurs['notes']) ?></textarea>
        </div>
        <div class="tre-actions">
            <button class="bouton" type="submit"><?= e(t('achats.enregistrer_reception')) ?></button>
            <a class="bouton bouton--secondaire" href="/gestion/achats/receptions"><?= e(t('tresorerie.annuler')) ?></a>
        </div>
    </section>
</form>
<script src="/assets/js/gestion-stock.js" defer></script>
