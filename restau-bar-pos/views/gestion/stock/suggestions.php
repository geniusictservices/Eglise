<?php
/**
 * Stock > Suggestions : pour chaque article au seuil ou en dessous, un achat (dépôts : regroupé par
 * fournisseur habituel, bon de commande en un toucher) ou un transfert (comptoirs : depuis le dépôt de
 * l'établissement qui en a le plus).
 * @var list<array> $achat fournisseur_id, fournisseur, lieu, lignes
 * @var list<array> $transferts source, destination, lignes
 * @var array<int, array> $devises
 * @var callable $peutCommander
 * @var callable $peutTransferer
 */

use App\Controllers\Gestion\StockAffichage as S;
use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;

$jeton = e(App\Core\Session::jetonCsrf());
$etabDe = static fn (array $l): ?int => $l['etablissement_id'] !== null ? (int) $l['etablissement_id'] : null;
?>
<?= View::rendre('gestion/stock/_onglets', ['flash' => $flash ?? null]) ?>

<p class="tre-retour"><a class="lien" href="/gestion/stock">← <?= e(t('stock.titre')) ?></a></p>
<h1><?= e(t('stock.suggestions_titre')) ?></h1>
<p class="aide"><?= e(t('stock.suggestions_aide')) ?></p>

<?php if ($achat === [] && $transferts === []): ?>
    <p class="bloc aide"><?= e(t('stock.suggestions_aucune')) ?></p>
<?php endif; ?>

<?php foreach ($transferts as $g): ?>
    <section class="bloc stk-suggestion" data-suggestion="transfert">
        <h2><?= e(t('stock.suggestion_transfert', ['source' => $g['source']['nom'], 'destination' => $g['destination']['nom']])) ?></h2>
        <p class="aide"><?= e($g['destination']['etablissement_nom'] ?? '') ?></p>
        <ul class="stk-liste-lignes">
            <?php foreach ($g['lignes'] as $l): ?>
                <li>
                    <span><strong><?= e(champ($l['article'], 'nom')) ?></strong>
                        <span class="tre-sous-texte"><?= e(t('stock.suggestion_detail', ['stock' => S::quantite($l['niveau']['quantite'], $l['article']), 'seuil' => S::base((float) $l['niveau']['seuil_alerte'], $l['article'])])) ?></span></span>
                    <span class="stk-proposition"><?= e(S::nombre($l['nombre']) . ' ' . S::format($l['format'], $l['nombre'])) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if ($peutTransferer($etabDe($g['source']))):
            $requete = ['source' => (int) $g['source']['id'], 'destination' => (int) $g['destination']['id']];
            foreach ($g['lignes'] as $i => $l) {
                $requete['lignes'][$i] = ['conditionnement_id' => (int) $l['format']['id'], 'quantite' => $l['nombre']];
            }
            ?>
            <div class="tre-actions"><a class="bouton bouton--secondaire" href="/gestion/stock/transferts/nouveau?<?= e(http_build_query($requete)) ?>"><?= e(t('stock.preparer_transfert')) ?></a></div>
        <?php endif; ?>
    </section>
<?php endforeach; ?>

<?php foreach ($achat as $g): ?>
    <section class="bloc stk-suggestion" data-suggestion="achat">
        <h2><?= e($g['fournisseur'] ? t('stock.suggestion_achat', ['fournisseur' => $g['fournisseur'], 'lieu' => $g['lieu']['nom']]) : t('stock.suggestion_achat_sans', ['lieu' => $g['lieu']['nom']])) ?></h2>
        <p class="aide"><?= e($g['lieu']['etablissement_nom'] ?? t('stock.central')) ?></p>
        <form method="post" action="/gestion/stock/suggestions/commande">
            <input type="hidden" name="csrf" value="<?= $jeton ?>">
            <input type="hidden" name="lieu_id" value="<?= (int) $g['lieu']['id'] ?>">
            <input type="hidden" name="fournisseur_id" value="<?= (int) $g['fournisseur_id'] ?>">
            <ul class="stk-liste-lignes">
                <?php foreach ($g['lignes'] as $i => $l): $devise = $l['devise_id'] !== null ? ($devises[$l['devise_id']] ?? null) : null; ?>
                    <li>
                        <span><strong><?= e(champ($l['article'], 'nom')) ?></strong>
                            <span class="tre-sous-texte"><?= e(t('stock.suggestion_detail', ['stock' => S::quantite($l['niveau']['quantite'], $l['article']), 'seuil' => S::base((float) $l['niveau']['seuil_alerte'], $l['article'])])) ?></span></span>
                        <span class="stk-proposition"><?= e(S::nombre($l['nombre']) . ' ' . S::format($l['format'], $l['nombre'])) ?>
                            <?php if ($l['prix'] !== null && $devise): ?><span class="tre-sous-texte"><?= e(t('stock.dernier_prix', ['prix' => A::montant($l['prix'], $devise)])) ?></span><?php endif; ?></span>
                        <input type="hidden" name="lignes[<?= $i ?>][conditionnement_id]" value="<?= (int) $l['format']['id'] ?>">
                        <input type="hidden" name="lignes[<?= $i ?>][quantite]" value="<?= (int) $l['nombre'] ?>">
                        <input type="hidden" name="lignes[<?= $i ?>][prix]" value="<?= $l['prix'] !== null ? e((string) $l['prix']) : '' ?>">
                        <?php if ($l['devise_id'] !== null && $i === 0): ?><input type="hidden" name="devise_id" value="<?= (int) $l['devise_id'] ?>"><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($g['fournisseur_id'] && $peutCommander($etabDe($g['lieu']))): ?>
                <div class="tre-actions"><button class="bouton bouton--secondaire" type="submit"><?= e(t('stock.creer_commande')) ?></button></div>
            <?php elseif (!$g['fournisseur_id']): ?>
                <p class="aide"><?= e(t('stock.suggestion_sans_fournisseur')) ?></p>
            <?php endif; ?>
        </form>
    </section>
<?php endforeach; ?>
