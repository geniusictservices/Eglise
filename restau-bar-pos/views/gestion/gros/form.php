<?php
/**
 * Ventes > Vente en gros > Nouvelle : dépôt, client revendeur, lignes au prix de gros (par casier), emballages
 * (prêtés ou consigne facturée, vides rapportés), paiements et reste à l'ardoise.
 * @var list<array> $depots dépôts qui vendent en gros (où la personne a ventes.gros)
 * @var ?array $lieu
 * @var list<array> $clients
 * @var ?array $client
 * @var array{pretes: array<int, float>, consignes: array<int, float>} $soldesClient
 * @var array<int, array> $devises
 * @var array<int, array> $articles
 * @var array<int, array<int, string>> $stocks dépôt => produit => quantité lisible
 * @var array<int, array<int, float>> $prixGros conditionnement => devise => prix
 * @var array<int, array> $emballages
 * @var array<int, array> $comptes
 * @var bool $peutCredit
 * @var bool $peutForcer
 * @var array $valeurs
 * @var list<array> $lignes
 * @var array $retours
 * @var list<array> $paiements
 * @var array<string, string> $erreurs
 * @var int $max
 * @var int $maxPaiements
 */

use App\Controllers\Gestion\StockAffichage as S;
use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;

$jeton = e(App\Core\Session::jetonCsrf());
$invalide = static fn (string $champ): string => isset($erreurs[$champ]) ? ' aria-invalid="true" aria-describedby="erreur-' . $champ . '"' : '';
$erreurChamp = static fn (string $champ): string => isset($erreurs[$champ]) ? '<p class="champ__erreur" id="erreur-' . $champ . '">' . e($erreurs[$champ]) . '</p>' : '';
$devise = $devises[(int) $valeurs['devise_id']] ?? null;
$devisesJs = array_map([A::class, 'deviseJs'], $devises);
$revendeurs = array_filter($clients, static fn (array $c): bool => $c['type'] === 'revendeur');
$autres = array_filter($clients, static fn (array $c): bool => $c['type'] !== 'revendeur');
$ligneClient = static fn (array $c): string => '<option value="' . (int) $c['id'] . '"' . ((int) $valeurs['client_id'] === (int) $c['id'] ? ' selected' : '') . '>'
    . e($c['nom'] . ($c['telephone'] ? ' · ' . $c['telephone'] : '') . ($c['bloque'] ? ' · ' . t('gros.client_bloque') : '')) . '</option>';
$ligneVente = static function (string|int $i, array $ligne) use ($articles, $erreurs, $devise): string {
    return View::rendre('gestion/stock/_ligne-article', ['i' => $i, 'ligne' => $ligne, 'articles' => $articles, 'erreurs' => $erreurs, 'avecPrix' => true, 'achat' => false, 'symbole' => $devise['symbole'] ?? '']);
};
$lignePaiement = static function (string|int $i, array $p) use ($comptes, $erreurs, $devise): string {
    $numero = is_int($i) ? $i + 1 : '__n__';
    $e = $erreurs['paiements-' . $i . '-compte'] ?? ($erreurs['paiements-' . $i . '-montant'] ?? null);
    ob_start(); ?>
    <fieldset class="stk-ligne" data-ligne-paiement>
        <legend class="stk-ligne__titre"><?= e(t('depenses.paiement_n', ['n' => $numero])) ?></legend>
        <div class="stk-ligne__champs">
            <div class="champ stk-ligne__format">
                <label for="pg-<?= $i ?>-compte"><?= e(t('depenses.champ_compte')) ?></label>
                <select id="pg-<?= $i ?>-compte" name="paiements[<?= $i ?>][compte_id]"<?= isset($erreurs['paiements-' . $i . '-compte']) ? ' aria-invalid="true"' : '' ?>>
                    <option value=""><?= e(t('tresorerie.choisir_compte')) ?></option>
                    <?php foreach ($comptes as $c): if ($devise !== null && !App\Repositories\GestionTresorerieRepository::tient($c, (int) $devise['id'])) { continue; } ?>
                        <option value="<?= (int) $c['id'] ?>" <?= (int) ($p['compte_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['nom'] . ' · ' . t('tresorerie.type_' . $c['type'])) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="champ stk-ligne__prix">
                <label for="pg-<?= $i ?>-montant"><?= e(t('gros.montant_paye')) ?></label>
                <div class="tre-montant">
                    <input id="pg-<?= $i ?>-montant" name="paiements[<?= $i ?>][montant]" inputmode="decimal" autocomplete="off" value="<?= e($p['montant'] ?? '') ?>" data-montant-paiement<?= isset($erreurs['paiements-' . $i . '-montant']) ? ' aria-invalid="true"' : '' ?>>
                    <span class="tre-montant__devise" data-symbole-prix><?= e($devise['symbole'] ?? '') ?></span>
                </div>
            </div>
        </div>
        <?php if ($e): ?><p class="champ__erreur"><?= e($e) ?></p><?php endif; ?>
        <button type="button" class="lien stk-ligne__retirer" data-retirer-ligne hidden><?= e(t('stock.retirer_ligne')) ?></button>
    </fieldset>
    <?php return (string) ob_get_clean();
};
?>
<?= View::rendre('gestion/stock/_onglets', []) ?>

<p class="tre-retour"><a class="lien" href="/gestion/gros">← <?= e(t('gros.titre')) ?></a></p>
<h1><?= e(t('gros.nouvelle')) ?></h1>
<p class="aide"><?= e(t('gros.nouvelle_aide')) ?></p>

<?php if ($depots === []): ?>
    <p class="alerte" role="status"><?= e(t('gros.aucun_depot')) ?></p>
<?php else: ?>
<?= View::rendre('gestion/tresorerie/_erreurs', ['erreurs' => array_values($erreurs)]) ?>

<form method="post" action="/gestion/gros/nouvelle" class="tre-formulaire stk-formulaire-large" novalidate
      data-formulaire-lignes data-max="<?= (int) $max ?>" data-texte-base="<?= e(t('stock.soit_js')) ?>"
      data-texte-stock="<?= e(t('gros.stock_js')) ?>" data-stocks="<?= e((string) json_encode($stocks)) ?>"
      data-devises="<?= e((string) json_encode($devisesJs)) ?>" data-texte-total="<?= e(t('gros.total_js')) ?>"
      data-vente-gros data-prix-gros="<?= e((string) json_encode($prixGros)) ?>">
    <input type="hidden" name="csrf" value="<?= $jeton ?>">
    <button type="submit" class="visuellement-cache" tabindex="-1" aria-hidden="true"><?= e(t('gros.enregistrer')) ?></button>

    <section class="bloc">
        <h2><?= e(t('gros.section_commande')) ?></h2>
        <div class="tre-paire">
            <div class="champ">
                <label for="lieu_id"><?= e(t('gros.depot')) ?></label>
                <select id="lieu_id" name="lieu_id" data-source data-recharger<?= $invalide('lieu_id') ?>>
                    <option value=""><?= e(t('stock.choisir_lieu')) ?></option>
                    <?php foreach ($depots as $d): ?>
                        <option value="<?= (int) $d['id'] ?>" <?= (int) $valeurs['lieu_id'] === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['nom'] . ' · ' . $d['etablissement_nom']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $erreurChamp('lieu_id') ?>
            </div>
            <div class="champ">
                <label for="client_id"><?= e(t('gros.client')) ?></label>
                <select id="client_id" name="client_id" data-recharger<?= $invalide('client_id') ?>>
                    <option value=""><?= e(t('gros.choisir_client')) ?></option>
                    <?php if ($revendeurs !== []): ?><optgroup label="<?= e(t('gros.revendeurs')) ?>"><?= implode('', array_map($ligneClient, $revendeurs)) ?></optgroup><?php endif; ?>
                    <?php if ($autres !== []): ?><optgroup label="<?= e(t('gros.autres_clients')) ?>"><?= implode('', array_map($ligneClient, $autres)) ?></optgroup><?php endif; ?>
                </select>
                <?= $erreurChamp('client_id') ?>
            </div>
        </div>
        <div class="tre-paire">
            <div class="champ">
                <label for="devise_id"><?= e(t('gros.devise')) ?></label>
                <select id="devise_id" name="devise_id" data-devise-facture data-recharger<?= $invalide('devise_id') ?>>
                    <?php foreach ($devises as $d): ?><option value="<?= (int) $d['id'] ?>" data-symbole="<?= e($d['symbole']) ?>" <?= (int) $valeurs['devise_id'] === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['code']) ?></option><?php endforeach; ?>
                </select>
                <?= $erreurChamp('devise_id') ?>
            </div>
            <div class="champ">
                <label for="date"><?= e(t('tresorerie.champ_date')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label>
                <input type="datetime-local" id="date" name="date" value="<?= e($valeurs['date']) ?>"<?= $invalide('date') ?>>
                <?= $erreurChamp('date') ?>
            </div>
        </div>
    </section>

    <section class="bloc" aria-labelledby="lignes-titre">
        <h2 id="lignes-titre"><?= e(t('gros.lignes')) ?></h2>
        <p class="aide"><?= e(t('gros.lignes_aide')) ?></p>
        <?= $erreurChamp('lignes') ?>
        <div class="stk-lignes" data-lignes>
            <?php foreach (array_values($lignes) as $i => $ligne): ?><?= $ligneVente($i, $ligne) ?><?php endforeach; ?>
        </div>
        <template data-modele-ligne><?= $ligneVente('__i__', ['conditionnement_id' => null, 'quantite' => '', 'prix' => '']) ?></template>
        <button class="bouton bouton--secondaire stk-ajouter-ligne" type="submit" name="action" value="ajouter_ligne" formnovalidate data-ajouter-ligne>+ <?= e(t('stock.ajouter_ligne')) ?></button>
        <p class="tre-resume" data-total-lignes aria-live="polite" hidden></p>
        <p class="champ__aide"><a class="lien" href="/gestion/gros/prix"><?= e(t('gros.voir_prix')) ?></a></p>
    </section>

    <?php if ($emballages !== []): ?>
        <section class="bloc" aria-labelledby="emb-titre">
            <h2 id="emb-titre"><?= e(t('gros.section_emballages')) ?></h2>
            <fieldset class="tre-groupe-champs">
                <legend><?= e(t('gros.mode_emballages')) ?></legend>
                <div class="stk-choix">
                    <?php foreach (['pret', 'facturer'] as $mode): ?>
                        <label class="stk-choix__option">
                            <input type="radio" name="mode_emballages" value="<?= $mode ?>" <?= $valeurs['mode_emballages'] === $mode ? 'checked' : '' ?>>
                            <span><strong><?= e(t('gros.mode_' . $mode)) ?></strong><span class="tre-sous-texte"><?= e(t('gros.mode_' . $mode . '_aide')) ?></span></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
            <p class="aide"><?= e(t('gros.retours_aide')) ?></p>
            <div class="tre-defile">
                <table class="tableau stk-table stk-table--cartes">
                    <thead><tr><th scope="col"><?= e(t('emballages.article')) ?></th><th scope="col" class="nombre"><?= e(t('gros.chez_client')) ?></th><th scope="col"><?= e(t('gros.vides_rapportes')) ?></th></tr></thead>
                    <tbody>
                        <?php foreach ($emballages as $id => $emb):
                            $pretes = $soldesClient['pretes'][$id] ?? 0.0; $consignes = $soldesClient['consignes'][$id] ?? 0.0; ?>
                            <tr data-emballage-gros="<?= (int) $id ?>">
                                <th scope="row" data-libelle="<?= e(t('emballages.article')) ?>"><?= e(champ($emb, 'nom')) ?></th>
                                <td class="nombre" data-libelle="<?= e(t('gros.chez_client')) ?>"><?= $client !== null
                                    ? e(t('gros.solde_client', ['pretes' => S::nombre($pretes), 'consignes' => S::nombre($consignes)]))
                                    : '—' ?></td>
                                <td data-libelle="<?= e(t('gros.vides_rapportes')) ?>"><label class="visuellement-cache" for="retour-<?= (int) $id ?>"><?= e(t('gros.vides_rapportes') . ' · ' . champ($emb, 'nom')) ?></label>
                                    <input class="saisie stk-saisie-courte" id="retour-<?= (int) $id ?>" name="retours[<?= (int) $id ?>]" inputmode="decimal" value="<?= e(is_string($retours[$id] ?? null) ? $retours[$id] : '') ?>"<?= $invalide('retours-' . $id) ?>></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <section class="bloc" aria-labelledby="paiement-titre">
        <h2 id="paiement-titre"><?= e(t('gros.section_paiement')) ?></h2>
        <p class="aide"><?= e(t($peutCredit ? 'gros.paiement_aide' : 'gros.paiement_aide_sans_credit')) ?></p>
        <?= $erreurChamp('paiements') ?>
        <div class="stk-lignes" data-paiements-gros>
            <?php foreach (array_values($paiements) as $i => $p): ?><?= $lignePaiement($i, $p) ?><?php endforeach; ?>
        </div>
        <?php if (count($paiements) < $maxPaiements): ?>
            <button class="bouton bouton--secondaire stk-ajouter-ligne" type="submit" name="action" value="ajouter_paiement" formnovalidate>+ <?= e(t('depenses.ajouter_paiement')) ?></button>
        <?php endif; ?>
        <?php if ($peutCredit): ?>
            <div class="tre-paire">
                <div class="champ">
                    <label for="echeance"><?= e(t('gros.echeance')) ?></label>
                    <input type="date" id="echeance" name="echeance" value="<?= e($valeurs['echeance']) ?>"<?= $invalide('echeance') ?>>
                    <?= $erreurChamp('echeance') ?>
                    <p class="champ__aide"><?= e(t('gros.echeance_aide')) ?></p>
                </div>
            </div>
            <?php if ($peutForcer): ?>
                <label class="coche"><input type="checkbox" name="forcer" value="1" <?= $valeurs['forcer'] ? 'checked' : '' ?>> <?= e(t('gros.forcer')) ?></label>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <section class="bloc">
        <div class="champ">
            <label for="notes"><?= e(t('stock.notes')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label>
            <input id="notes" name="notes" maxlength="255" value="<?= e($valeurs['notes']) ?>">
        </div>
        <div class="tre-actions">
            <button class="bouton" type="submit"><?= e(t('gros.enregistrer')) ?></button>
            <a class="bouton bouton--secondaire" href="/gestion/gros"><?= e(t('tresorerie.annuler')) ?></a>
        </div>
    </section>
</form>
<?php endif; ?>
<script src="/assets/js/gestion-stock.js" defer></script>
