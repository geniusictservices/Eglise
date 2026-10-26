<?php
/**
 * Fiche d'un inventaire. En cours : feuille de comptage « à l'aveugle » (le stock attendu n'est pas montré
 * à qui compte), dans les formats du produit. Compté : écarts détaillés (début + entrées − ventes − pertes
 * ± autres = attendu ; compté ; écart et sa valeur), justification, validation. Validé : lecture seule.
 * @var array $inventaire
 * @var array<int, array> $articles
 * @var array<int, array> $autres articles absents de l'inventaire (pour en ajouter un)
 * @var bool $peutCompter
 * @var bool $peutValider
 * @var bool $seul la personne connectée a compté
 * @var bool $separee validation par une autre personne demandée
 * @var bool $voirValeur
 * @var ?array $reference
 * @var array<int, float> $cumps
 * @var array<string, string> $erreurs
 * @var array $saisies
 * @var array<int, string> $justifications
 */

use App\Controllers\Gestion\InventaireController;
use App\Controllers\Gestion\StockAffichage as S;
use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;

$jeton = e(App\Core\Session::jetonCsrf());
$csrf = '<input type="hidden" name="csrf" value="' . $jeton . '">';
$i = $inventaire;
$badges = ['en_cours' => 'badge--braise', 'compte' => 'badge--glace', 'valide' => 'badge--succes', 'annule' => 'badge--neutre'];
$article = static fn (int $id): array => $articles[$id] ?? ['nom_fr' => '?', 'nom_en' => '?', 'unite_base' => 'piece', 'conditionnements' => []];
$enCours = $i['statut'] === 'en_cours';
$ouvert = in_array($i['statut'], ['en_cours', 'compte'], true);
$totalEcart = 0.0;
?>
<?= View::rendre('gestion/stock/_onglets', ['flash' => $flash ?? null]) ?>

<p class="tre-retour"><a class="lien" href="/gestion/stock/inventaires">← <?= e(t('stock.inventaires_titre')) ?></a></p>
<div class="tre-entete">
    <div>
        <h1 class="stk-titre-badge"><?= e($i['numero']) ?> <span class="badge <?= $badges[$i['statut']] ?>" data-statut="<?= e($i['statut']) ?>"><?= e(t('stock.statut_inventaire_' . $i['statut'])) ?></span></h1>
        <p class="aide"><?= e($i['lieu_nom'] . ' · ' . ($i['etablissement_nom'] ?? t('stock.central')) . ' · ' . t('stock.inventaire_' . $i['type'])
            . ($i['categorie_nom_fr'] ? ' (' . champ(['nom_fr' => $i['categorie_nom_fr'], 'nom_en' => $i['categorie_nom_en']], 'nom') . ')' : '')) ?></p>
        <p class="aide"><?= e(t('stock.fige_le', ['date' => A::dateHeure($i['fige_le'])])) ?>
            <?php if ($i['compteur']): ?> · <?= e(t('stock.compte_par', ['nom' => $i['compteur']])) ?><?php endif; ?>
            <?php if ($i['validateur']): ?> · <?= e(t('stock.valide_par', ['nom' => $i['validateur']])) ?> (<?= e(A::dateHeure($i['valide_le'])) ?>)<?php endif; ?>
            <?php if ($i['valide_seul']): ?> · <span class="badge badge--braise"><?= e(t('stock.valide_seul')) ?></span><?php endif; ?></p>
    </div>
</div>

<?= View::rendre('gestion/tresorerie/_erreurs', ['erreurs' => array_values($erreurs)]) ?>

<?php if ($enCours && $peutCompter): ?>
    <section class="bloc" id="comptage" aria-labelledby="comptage-titre">
        <h2 id="comptage-titre"><?= e(t('stock.feuille_comptage')) ?></h2>
        <p class="aide"><?= e(t('stock.comptage_aide')) ?></p>
        <form method="post" action="/gestion/stock/inventaires/<?= (int) $i['id'] ?>/compter" novalidate data-comptage>
            <?= $csrf ?>
            <div class="stk-comptage">
                <?php foreach ($i['lignes'] as $produitId => $l): $a = $article((int) $produitId); $detail = $l['saisie'] ? (array) json_decode($l['saisie'], true) : []; ?>
                    <fieldset class="stk-compte" data-produit="<?= (int) $produitId ?>">
                        <legend class="stk-compte__nom"><?= e(champ($a, 'nom')) ?><?php if ($l['compte'] !== null): ?> <span class="badge badge--succes"><?= e(t('stock.compte')) ?></span><?php endif; ?></legend>
                        <div class="stk-compte__champs">
                            <?php foreach (InventaireController::champsComptage($a) as $cle => $taille):
                                $format = null;
                                foreach ($a['conditionnements'] as $c) { if ('f' . (int) $c['id'] === $cle) { $format = $c; } }
                                $nomChamp = $format ? champ($format, 'nom') : S::unite((string) $a['unite_base'], 2);
                                $valeur = isset($saisies[$produitId][$cle]) && is_string($saisies[$produitId][$cle]) ? $saisies[$produitId][$cle] : (isset($detail[$cle]) ? S::saisie($detail[$cle]) : '');
                                $erreurCle = 'compte-' . $produitId . '-' . $cle;
                                ?>
                                <div class="champ">
                                    <label for="c-<?= (int) $produitId ?>-<?= e($cle) ?>"><?= e($nomChamp) ?></label>
                                    <input id="c-<?= (int) $produitId ?>-<?= e($cle) ?>" name="comptes[<?= (int) $produitId ?>][<?= e($cle) ?>]" inputmode="decimal" autocomplete="off"
                                           value="<?= e($valeur) ?>" data-taille="<?= e((string) $taille) ?>"<?= isset($erreurs[$erreurCle]) ? ' aria-invalid="true"' : '' ?>>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <p class="champ__aide" data-total-compte data-unite="<?= e(S::unite((string) $a['unite_base'], 2)) ?>" aria-live="polite"></p>
                    </fieldset>
                <?php endforeach; ?>
            </div>
            <p class="aide"><?= e(t('stock.comptage_fraction_aide')) ?></p>
            <div class="tre-actions">
                <button class="bouton bouton--secondaire" type="submit" name="action" value="enregistrer"><?= e(t('stock.enregistrer_comptage')) ?></button>
                <button class="bouton" type="submit" name="action" value="terminer"><?= e(t('stock.terminer_comptage')) ?></button>
            </div>
        </form>
        <?php if ($autres !== []): ?>
            <form method="post" action="/gestion/stock/inventaires/<?= (int) $i['id'] ?>/ajouter" class="stk-form-ligne">
                <?= $csrf ?>
                <div class="champ">
                    <label for="ajout-produit"><?= e(t('stock.ajouter_article_inventaire')) ?></label>
                    <select id="ajout-produit" name="produit_id">
                        <?php foreach ($autres as $a): ?><option value="<?= (int) $a['id'] ?>"><?= e(champ($a, 'nom')) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <button class="bouton bouton--secondaire" type="submit"><?= e(t('stock.ajouter')) ?></button>
            </form>
        <?php endif; ?>
    </section>
<?php elseif ($enCours): ?>
    <p class="bloc aide"><?= e(t('stock.comptage_en_cours_autre')) ?></p>
<?php endif; ?>

<?php if (!$enCours): ?>
    <section class="bloc" aria-labelledby="ecarts-titre">
        <h2 id="ecarts-titre"><?= e(t('stock.ecarts_titre')) ?></h2>
        <p class="aide"><?= e(t('stock.ecarts_aide')) ?></p>
        <?php $valider = $i['statut'] === 'compte' && $peutValider; ?>
        <?php if ($valider): ?><form method="post" action="/gestion/stock/inventaires/<?= (int) $i['id'] ?>/valider" novalidate><?= $csrf ?><?php endif; ?>
        <div class="tre-defile">
            <table class="tableau stk-table stk-ecarts">
                <thead>
                    <tr>
                        <th scope="col"><?= e(t('stock.col_article')) ?></th>
                        <th scope="col" class="nombre"><?= e(t('stock.col_debut')) ?></th>
                        <th scope="col" class="nombre"><?= e(t('stock.col_entrees')) ?></th>
                        <th scope="col" class="nombre"><?= e(t('stock.col_ventes')) ?></th>
                        <th scope="col" class="nombre"><?= e(t('stock.col_pertes')) ?></th>
                        <th scope="col" class="nombre"><?= e(t('stock.col_autres')) ?></th>
                        <th scope="col" class="nombre"><?= e(t('stock.col_attendu')) ?></th>
                        <th scope="col" class="nombre"><?= e(t('stock.col_compte')) ?></th>
                        <th scope="col" class="nombre"><?= e(t('stock.col_ecart')) ?></th>
                        <th scope="col"><?= e(t('stock.col_justification')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($i['lignes'] as $produitId => $l): $a = $article((int) $produitId);
                        $ecart = $l['compte'] !== null ? round((float) $l['compte'] - (float) $l['attendu'], 3) : null;
                        $cout = $l['cout_unitaire'] !== null ? (float) $l['cout_unitaire'] : ($cumps[(int) $produitId] ?? null);
                        $valeurEcart = $ecart !== null && $cout !== null ? $ecart * $cout : null;
                        $totalEcart += $valeurEcart ?? 0;
                        ?>
                        <tr data-produit="<?= (int) $produitId ?>" data-ecart="<?= $ecart !== null ? e((string) $ecart) : '' ?>">
                            <th scope="row"><?= e(champ($a, 'nom')) ?></th>
                            <td class="nombre"><?= e(S::quantite($l['debut'], $a)) ?></td>
                            <td class="nombre"><?= (float) $l['entrees'] ? '+' . e(S::quantite($l['entrees'], $a)) : '—' ?></td>
                            <td class="nombre"><?= (float) $l['ventes'] ? '−' . e(S::quantite($l['ventes'], $a)) : '—' ?></td>
                            <td class="nombre"><?= (float) $l['pertes'] ? '−' . e(S::quantite($l['pertes'], $a)) : '—' ?></td>
                            <td class="nombre"><?= (float) $l['autres'] ? ((float) $l['autres'] > 0 ? '+' : '') . e(S::quantite($l['autres'], $a)) : '—' ?></td>
                            <td class="nombre"><strong><?= e(S::quantite($l['attendu'], $a)) ?></strong></td>
                            <td class="nombre"><?= $l['compte'] !== null ? '<strong>' . e(S::quantite($l['compte'], $a)) . '</strong>' : '<span class="tre-sous-texte">' . e(t('stock.non_compte')) . '</span>' ?></td>
                            <td class="nombre">
                                <?php if ($ecart !== null && abs($ecart) > 0.0005): ?>
                                    <strong class="<?= $ecart < 0 ? 'tre-negatif' : 'tre-entree' ?>"><?= $ecart > 0 ? '+' : '' ?><?= e(S::quantite($ecart, $a)) ?></strong>
                                    <?php if ($voirValeur && $valeurEcart !== null && $reference): ?><span class="tre-sous-texte"><?= e(A::montant($valeurEcart, $reference, true)) ?></span><?php endif; ?>
                                <?php elseif ($ecart !== null): ?><span class="badge badge--succes"><?= e(t('stock.juste')) ?></span><?php endif; ?>
                            </td>
                            <td>
                                <?php if ($valider && $ecart !== null && abs($ecart) > 0.0005): ?>
                                    <label class="visuellement-cache" for="j-<?= (int) $produitId ?>"><?= e(t('stock.col_justification') . ' · ' . champ($a, 'nom')) ?></label>
                                    <input class="saisie" id="j-<?= (int) $produitId ?>" name="justifications[<?= (int) $produitId ?>]" maxlength="255" value="<?= e($justifications[(int) $produitId] ?? '') ?>" placeholder="<?= e(t('stock.justification_exemple')) ?>">
                                <?php else: ?><?= e((string) $l['justification']) ?><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <?php if ($voirValeur && $reference): ?>
                    <tfoot><tr><th scope="row" colspan="8"><?= e(t('stock.valeur_ecarts')) ?></th><td class="nombre"><strong class="<?= $totalEcart < 0 ? 'tre-negatif' : '' ?>" data-valeur-ecarts><?= e(A::montant($totalEcart, $reference, true)) ?></strong></td><td></td></tr></tfoot>
                <?php endif; ?>
            </table>
        </div>
        <?php if ($valider): ?>
            <?php if ($seul && $separee): ?>
                <div class="tre-avertissement" role="note">
                    <p class="tre-avertissement__titre"><?= e(t('stock.separation_titre')) ?></p>
                    <p><?= e(t('stock.separation_texte')) ?></p>
                    <label class="coche"><input type="checkbox" name="seul" value="1"<?= isset($erreurs['seul']) ? ' aria-invalid="true"' : '' ?>> <?= e(t('stock.separation_confirmer')) ?></label>
                </div>
            <?php endif; ?>
            <div class="tre-actions"><button class="bouton" type="submit"><?= e(t('stock.valider_inventaire')) ?></button></div>
            </form>
        <?php elseif ($i['statut'] === 'compte'): ?>
            <p class="aide"><?= e(t('stock.attente_validation')) ?></p>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($ouvert && $peutCompter): ?>
    <section class="bloc stk-actions-secondaires">
        <div class="tre-actions">
            <?php if ($i['statut'] === 'compte'): ?>
                <form method="post" action="/gestion/stock/inventaires/<?= (int) $i['id'] ?>/rouvrir"><?= $csrf ?><button class="bouton bouton--secondaire" type="submit"><?= e(t('stock.reprendre_comptage')) ?></button></form>
            <?php else: ?>
                <form method="post" action="/gestion/stock/inventaires/<?= (int) $i['id'] ?>/actualiser"><?= $csrf ?><button class="bouton bouton--secondaire" type="submit"><?= e(t('stock.actualiser_attendu')) ?></button></form>
            <?php endif; ?>
            <details class="tre-supprimer">
                <summary class="lien tre-lien-danger"><?= e(t('stock.annuler_inventaire')) ?></summary>
                <p><?= e(t('stock.annuler_inventaire_texte')) ?></p>
                <form method="post" action="/gestion/stock/inventaires/<?= (int) $i['id'] ?>/annuler"><?= $csrf ?><button class="bouton bouton--danger" type="submit"><?= e(t('stock.annuler_inventaire_oui')) ?></button></form>
            </details>
        </div>
    </section>
<?php endif; ?>
<script src="/assets/js/gestion-stock.js" defer></script>
