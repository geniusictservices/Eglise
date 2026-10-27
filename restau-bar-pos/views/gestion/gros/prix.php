<?php
/**
 * Ventes > Vente en gros > Prix de gros : prix de chaque format (casier, carton, bouteille) dans la grille
 * « Prix de gros », en devise locale et en devise de référence ; coût moyen à côté (avec rapports.marges).
 * @var array<int, array> $articles
 * @var array<int, array> $devises
 * @var array<int, array<int, float>> $prix
 * @var array<int, float> $cumps
 * @var ?array $reference
 * @var bool $peutModifier
 * @var array $saisies
 * @var array<string, string> $erreurs
 * @var ?int $grille
 */

use App\Controllers\Gestion\StockAffichage as S;
use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;

$jeton = e(App\Core\Session::jetonCsrf());
$groupes = [];
foreach ($articles as $a) {
    $groupes[$a['categorie']][] = $a;
}
$valeur = static function (int $condId, int $deviseId) use ($saisies, $prix): string {
    if (isset($saisies[$condId]) && is_array($saisies[$condId]) && array_key_exists($deviseId, $saisies[$condId])) {
        return is_scalar($saisies[$condId][$deviseId]) ? (string) $saisies[$condId][$deviseId] : '';
    }
    return isset($prix[$condId][$deviseId]) ? A::saisie($prix[$condId][$deviseId]) : '';
};
?>
<?= View::rendre('gestion/stock/_onglets', ['flash' => $flash ?? null]) ?>

<p class="tre-retour"><a class="lien" href="/gestion/gros">← <?= e(t('gros.titre')) ?></a></p>
<h1><?= e(t('gros.prix_titre')) ?></h1>
<p class="aide"><?= e(t('gros.prix_aide')) ?></p>

<?php if ($grille === null): ?>
    <p class="alerte" role="status"><?= e(t('gros.sans_grille')) ?></p>
<?php else: ?>
<?= View::rendre('gestion/tresorerie/_erreurs', ['erreurs' => array_values($erreurs)]) ?>
<form method="post" action="/gestion/gros/prix" novalidate>
    <input type="hidden" name="csrf" value="<?= $jeton ?>">
    <?php foreach ($groupes as $categorie => $liste): ?>
        <section class="bloc" aria-label="<?= e($categorie) ?>">
            <h2><?= e($categorie) ?></h2>
            <div class="tre-defile">
                <table class="tableau stk-table stk-table--cartes stk-prix-gros">
                    <thead>
                        <tr>
                            <th scope="col"><?= e(t('stock.article_format')) ?></th>
                            <?php if ($cumps !== []): ?><th scope="col" class="nombre"><?= e(t('gros.cout_format')) ?></th><?php endif; ?>
                            <?php foreach ($devises as $d): ?><th scope="col"><?= e(t('gros.prix_en', ['code' => $d['code']])) ?></th><?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($liste as $a): foreach ($a['conditionnements'] as $c): $cid = (int) $c['id']; ?>
                            <tr data-prix-gros="<?= $cid ?>">
                                <th scope="row" data-libelle="<?= e(t('stock.article_format')) ?>"><?= e(champ($a, 'nom')) ?> <span class="tre-sous-texte"><?= e(champ($c, 'nom') . ' · ' . S::base((float) $c['quantite_base'], $a)) ?></span></th>
                                <?php if ($cumps !== []): ?><td class="nombre" data-libelle="<?= e(t('gros.cout_format')) ?>"><?= isset($cumps[(int) $a['id']]) && $reference ? e(A::montant($cumps[(int) $a['id']] * (float) $c['quantite_base'], $reference)) : '—' ?></td><?php endif; ?>
                                <?php foreach ($devises as $did => $d): $cle = 'prix-' . $cid . '-' . (int) $did; ?>
                                    <td data-libelle="<?= e(t('gros.prix_en', ['code' => $d['code']])) ?>">
                                        <label class="visuellement-cache" for="<?= $cle ?>"><?= e(t('gros.prix_en', ['code' => $d['code']]) . ' · ' . champ($a, 'nom') . ' ' . champ($c, 'nom')) ?></label>
                                        <div class="tre-montant">
                                            <input class="saisie" id="<?= $cle ?>" name="prix[<?= $cid ?>][<?= (int) $did ?>]" inputmode="decimal" autocomplete="off" value="<?= e($valeur($cid, (int) $did)) ?>"<?= isset($erreurs[$cle]) ? ' aria-invalid="true"' : '' ?><?= $peutModifier ? '' : ' disabled' ?>>
                                            <span class="tre-montant__devise"><?= e($d['symbole']) ?></span>
                                        </div>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endforeach; ?>
    <?php if ($peutModifier): ?>
        <div class="tre-actions stk-actions-collantes"><button class="bouton" type="submit"><?= e(t('gros.enregistrer_prix')) ?></button></div>
    <?php endif; ?>
</form>
<?php endif; ?>
