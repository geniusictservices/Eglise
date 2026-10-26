<?php
/**
 * Stock > État du stock : un lieu ou tous les lieux visibles, filtres (catégorie, recherche, alertes),
 * quantité lisible (« 9 casiers + 7 bouteilles »), seuils d'alerte et stock visé (avec stock.parametrer),
 * valeur au CUMP (avec rapports.marges).
 * @var array<int, array> $lieux
 * @var ?array $lieu
 * @var array $filtres categorie, q, alerte, tous
 * @var list<array> $categories
 * @var list<array> $lignes article, niveau, etat, par_lieu, valeur
 * @var array $compteurs negatif, bas
 * @var bool $voirValeur
 * @var float $valeurTotale
 * @var ?array $reference
 * @var bool $peutSeuils
 * @var bool $peutAjuster
 * @var bool $modeBloquer
 */

use App\Controllers\Gestion\StockAffichage as S;
use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;

$jeton = e(App\Core\Session::jetonCsrf());
$badge = static fn (string $etat): string => $etat === 'ok' ? '' : '<span class="badge ' . ($etat === 'negatif' ? 'badge--erreur' : 'badge--braise') . '">' . e(t('stock.etat_' . $etat)) . '</span>';
$parEtab = [];
foreach ($lieux as $l) {
    $parEtab[$l['etablissement_nom'] ?? t('stock.central')][] = $l;
}
?>
<?= View::rendre('gestion/stock/_onglets', ['flash' => $flash ?? null]) ?>

<div class="tre-entete">
    <div>
        <h1><?= e(t('stock.titre')) ?></h1>
        <p class="aide"><?= e(t('stock.aide')) ?></p>
    </div>
    <div class="tre-entete__actions">
        <a class="bouton bouton--secondaire" href="/gestion/stock/suggestions"><?= e(t('stock.suggestions_titre')) ?></a>
        <?php if ($peutAjuster): ?>
            <a class="bouton" href="/gestion/stock/ajuster<?= $lieu ? '?lieu=' . (int) $lieu['id'] : '' ?>"><?= e(t('stock.ajuster')) ?></a>
        <?php endif; ?>
    </div>
</div>

<?php if ($compteurs['negatif'] + $compteurs['bas'] > 0): ?>
    <div class="stk-alertes" role="status">
        <?php if ($compteurs['negatif'] > 0): ?>
            <a class="stk-alerte stk-alerte--negatif" href="<?= e(urlAvec(['alerte' => 'negatif'])) ?>"><strong data-compteur="negatif"><?= (int) $compteurs['negatif'] ?></strong> <?= e(t($compteurs['negatif'] > 1 ? 'stock.alerte_negatifs' : 'stock.alerte_negatif')) ?></a>
        <?php endif; ?>
        <?php if ($compteurs['bas'] > 0): ?>
            <a class="stk-alerte stk-alerte--bas" href="<?= e(urlAvec(['alerte' => 'bas'])) ?>"><strong data-compteur="bas"><?= (int) $compteurs['bas'] ?></strong> <?= e(t($compteurs['bas'] > 1 ? 'stock.alerte_bas_n' : 'stock.alerte_bas_1')) ?></a>
        <?php endif; ?>
        <a class="lien" href="/gestion/stock/suggestions"><?= e(t('stock.voir_suggestions')) ?></a>
    </div>
<?php endif; ?>

<section class="bloc tre-filtres" aria-label="<?= e(t('stock.filtres')) ?>">
    <nav class="tre-puces stk-lieux" aria-label="<?= e(t('stock.choisir_lieu')) ?>">
        <a class="puce" href="<?= e(urlAvec(['lieu' => null, 'tous' => null])) ?>"<?= $lieu === null ? ' aria-current="true"' : '' ?>><?= e(t('stock.tous_lieux')) ?></a>
        <?php foreach ($parEtab as $etab => $liste): ?>
            <?php foreach ($liste as $l): ?>
                <a class="puce" href="<?= e(urlAvec(['lieu' => (int) $l['id']])) ?>"<?= $lieu && (int) $lieu['id'] === (int) $l['id'] ? ' aria-current="true"' : '' ?>
                   title="<?= e($etab) ?>"><?= e($l['nom']) ?><?php if (count($parEtab) > 1): ?> <span class="stk-puce-etab">· <?= e($etab) ?></span><?php endif; ?></a>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </nav>
    <form class="tre-filtres__formulaire" method="get" action="/gestion/stock">
        <?php if ($lieu): ?><input type="hidden" name="lieu" value="<?= (int) $lieu['id'] ?>"><?php endif; ?>
        <div class="champ">
            <label for="filtre-q"><?= e(t('stock.rechercher')) ?></label>
            <input type="search" id="filtre-q" name="q" value="<?= e($filtres['q']) ?>" placeholder="<?= e(t('stock.rechercher_exemple')) ?>">
        </div>
        <div class="champ">
            <label for="filtre-categorie"><?= e(t('stock.categorie')) ?></label>
            <select id="filtre-categorie" name="categorie">
                <option value=""><?= e(t('stock.toutes_categories')) ?></option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= $filtres['categorie'] === (int) $c['id'] ? 'selected' : '' ?>><?= e(champ($c, 'nom')) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="champ">
            <label for="filtre-alerte"><?= e(t('stock.alertes')) ?></label>
            <select id="filtre-alerte" name="alerte">
                <option value=""><?= e(t('stock.tous_niveaux')) ?></option>
                <option value="bas" <?= $filtres['alerte'] === 'bas' ? 'selected' : '' ?>><?= e(t('stock.filtre_bas')) ?></option>
                <option value="negatif" <?= $filtres['alerte'] === 'negatif' ? 'selected' : '' ?>><?= e(t('stock.filtre_negatif')) ?></option>
            </select>
        </div>
        <label class="coche stk-coche-filtre"><input type="checkbox" name="tous" value="1" <?= $filtres['tous'] ? 'checked' : '' ?>> <?= e(t('stock.afficher_tous')) ?></label>
        <div class="tre-filtres__boutons">
            <button class="bouton bouton--secondaire" type="submit"><?= e(t('tresorerie.afficher')) ?></button>
        </div>
    </form>
</section>

<?php if ($lieu && $modeBloquer): ?>
    <p class="aide stk-note"><?= e(t('stock.mode_bloquer_note')) ?></p>
<?php endif; ?>

<section class="bloc" aria-labelledby="stk-liste-titre">
    <div class="stk-titre-liste">
        <h2 id="stk-liste-titre"><?= e($lieu ? $lieu['nom'] . ($lieu['etablissement_nom'] ? ' · ' . $lieu['etablissement_nom'] : ' · ' . t('stock.central')) : t('stock.tous_lieux')) ?></h2>
        <?php if ($voirValeur && $reference): ?>
            <p class="stk-valeur"><?= e(t('stock.valeur_totale')) ?> <strong class="montant" data-valeur-totale><?= e(A::montant($valeurTotale, $reference)) ?></strong></p>
        <?php endif; ?>
    </div>
    <?php if ($lignes === []): ?>
        <p class="aide"><?= e(t($filtres['q'] !== '' || $filtres['categorie'] || $filtres['alerte'] ? 'stock.aucun_filtre' : 'stock.aucun')) ?></p>
    <?php else: ?>
        <?php if ($peutSeuils): ?><form method="post" action="/gestion/stock/seuils" id="form-seuils"><input type="hidden" name="csrf" value="<?= $jeton ?>"><input type="hidden" name="lieu_id" value="<?= (int) $lieu['id'] ?>"><?php endif; ?>
        <div class="tre-defile">
            <table class="tableau stk-table stk-table--cartes">
                <thead>
                    <tr>
                        <th scope="col"><?= e(t('stock.col_article')) ?></th>
                        <th scope="col" class="nombre"><?= e(t('stock.col_stock')) ?></th>
                        <?php if ($lieu === null): ?><th scope="col"><?= e(t('stock.col_par_lieu')) ?></th><?php endif; ?>
                        <?php if ($lieu !== null): ?>
                            <th scope="col" class="nombre"><?= e(t('stock.col_seuil')) ?></th>
                            <th scope="col" class="nombre"><?= e(t('stock.col_cible')) ?></th>
                        <?php endif; ?>
                        <?php if ($voirValeur): ?><th scope="col" class="nombre"><?= e(t('stock.col_valeur')) ?></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lignes as $ligne): $a = $ligne['article']; $n = $ligne['niveau']; $pid = (int) $a['id']; ?>
                        <tr data-produit="<?= $pid ?>" data-etat="<?= e($ligne['etat']) ?>" class="<?= $ligne['etat'] !== 'ok' ? 'stk-ligne--' . e($ligne['etat']) : '' ?>">
                            <td>
                                <span class="stk-article"><?= e(champ($a, 'nom')) ?></span>
                                <span class="tre-sous-texte"><?= e($a['categorie']) ?><?= $a['emballage'] ? ' · ' . e(t('stock.emballage')) : '' ?></span>
                            </td>
                            <td class="nombre" data-libelle="<?= e(t('stock.col_stock')) ?>">
                                <strong class="stk-quantite<?= $n['quantite'] < -0.0005 ? ' tre-negatif' : '' ?>" data-quantite="<?= e((string) $n['quantite']) ?>"><?= e(S::quantite($n['quantite'], $a)) ?></strong>
                                <?= $badge($ligne['etat']) ?>
                                <span class="tre-sous-texte"><?= e(S::base($n['quantite'], $a)) ?></span>
                            </td>
                            <?php if ($lieu === null): ?>
                                <td class="stk-par-lieu" data-libelle="<?= e(t('stock.col_par_lieu')) ?>">
                                    <?php foreach ($ligne['par_lieu'] as $pl): ?>
                                        <span class="stk-lieu-qte<?= $pl['etat'] !== 'ok' ? ' stk-lieu-qte--' . e($pl['etat']) : '' ?>"><?= e($pl['lieu']['nom']) ?> <strong><?= e(S::quantite($pl['quantite'], $a)) ?></strong></span>
                                    <?php endforeach; ?>
                                </td>
                            <?php else: ?>
                                <?php foreach (['seuil' => 'seuil_alerte', 'cible' => 'stock_cible'] as $champ => $cle): ?>
                                    <td class="nombre" data-libelle="<?= e(t('stock.col_' . $champ)) ?>">
                                        <?php if ($peutSeuils): ?>
                                            <label class="visuellement-cache" for="<?= $champ ?>-<?= $pid ?>"><?= e(t('stock.col_' . $champ) . ' · ' . champ($a, 'nom')) ?></label>
                                            <input class="saisie stk-saisie-courte" id="<?= $champ ?>-<?= $pid ?>" name="seuils[<?= $pid ?>][<?= $champ ?>]" inputmode="decimal" value="<?= e(S::saisie($n[$cle])) ?>" placeholder="—">
                                        <?php else: ?>
                                            <?= $n[$cle] !== null ? e(S::base($n[$cle], $a)) : '—' ?>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <?php if ($voirValeur): ?>
                                <td class="nombre" data-libelle="<?= e(t('stock.col_valeur')) ?>"><?= $ligne['valeur'] !== null && $reference ? e(A::montant($ligne['valeur'], $reference)) : '<span class="tre-sous-texte">' . e($a['emballage'] ? '—' : t('stock.sans_cout')) . '</span>' ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($peutSeuils): ?>
            <p class="aide"><?= e(t('stock.seuils_aide')) ?></p>
            <div class="tre-actions"><button class="bouton bouton--secondaire" type="submit"><?= e(t('stock.enregistrer_seuils')) ?></button></div>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</section>
