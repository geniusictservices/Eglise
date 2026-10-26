<?php
/**
 * Achats > Dettes fournisseurs : par fournisseur (retards d'abord), reste dû par devise et factures à payer.
 * @var list<array> $dettes
 * @var array<int, float> $totaux
 * @var array<int, array> $devises
 */

use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;
?>
<?= View::rendre('gestion/stock/_onglets', ['flash' => $flash ?? null]) ?>

<div class="tre-entete">
    <div>
        <h1><?= e(t('achats.dettes_titre')) ?></h1>
        <p class="aide"><?= e(t('achats.dettes_aide')) ?></p>
    </div>
</div>

<?php if ($totaux !== []): ?>
    <div class="chiffres stk-chiffres">
        <?php foreach ($totaux as $deviseId => $m): if (!isset($devises[$deviseId])) { continue; } ?>
            <div class="chiffre" data-dette-devise="<?= e($devises[$deviseId]['code']) ?>"><span class="chiffre__valeur"><?= e(A::montant($m, $devises[$deviseId])) ?></span><span class="chiffre__libelle"><?= e(t('achats.du_en', ['code' => $devises[$deviseId]['code']])) ?></span></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($dettes === []): ?>
    <p class="bloc aide"><?= e(t('achats.aucune_dette')) ?></p>
<?php endif; ?>

<?php foreach ($dettes as $d): ?>
    <section class="bloc" data-dette-fournisseur="<?= (int) $d['fournisseur_id'] ?>">
        <div class="stk-titre-liste">
            <h2><a href="/gestion/achats/fournisseurs/<?= (int) $d['fournisseur_id'] ?>"><?= e($d['fournisseur']) ?></a>
                <?php if ($d['en_retard']): ?><span class="badge badge--erreur"><?= e(t('achats.n_en_retard', ['n' => $d['en_retard']])) ?></span><?php endif; ?></h2>
            <p class="stk-valeur"><strong class="montant"><?= e(implode(' + ', array_map(static fn ($id, $m) => isset($devises[$id]) ? A::montant($m, $devises[$id]) : '', array_keys($d['par_devise']), $d['par_devise']))) ?></strong></p>
        </div>
        <?php if ($d['prochaine']): ?><p class="aide"><?= e(t('achats.prochaine_echeance', ['date' => A::jour($d['prochaine'])])) ?></p><?php endif; ?>
        <div class="tre-defile">
            <table class="tableau stk-table">
                <thead><tr><th scope="col"><?= e(t('stock.col_numero')) ?></th><th scope="col" class="nombre"><?= e(t('achats.total')) ?></th><th scope="col"><?= e(t('achats.paiement')) ?></th><th scope="col"><span class="visuellement-cache"><?= e(t('achats.payer')) ?></span></th></tr></thead>
                <tbody>
                    <?php foreach ($d['receptions'] as $r): ?>
                        <tr data-reception="<?= (int) $r['id'] ?>">
                            <td><a class="stk-numero" href="/gestion/achats/receptions/<?= (int) $r['id'] ?>"><?= e($r['numero']) ?></a><span class="tre-sous-texte"><?= e(A::jour($r['date_reception'])) ?></span></td>
                            <td class="nombre"><?= isset($devises[(int) $r['devise_id']]) ? e(A::montant($r['total'], $devises[(int) $r['devise_id']])) : '' ?></td>
                            <td><?= View::rendre('gestion/achats/_etat-paiement', ['r' => $r, 'devises' => $devises]) ?></td>
                            <td><a class="bouton bouton--secondaire bouton--petit" href="/gestion/achats/receptions/<?= (int) $r['id'] ?>#payer"><?= e(t('achats.payer')) ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endforeach; ?>
