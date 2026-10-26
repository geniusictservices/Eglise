<?php
/**
 * Achats > Réceptions : livraisons de la période, filtres (fournisseur, lieu, paiement), totaux par devise.
 * @var array $periode
 * @var array $filtres
 * @var list<array> $receptions
 * @var array<int, array> $totaux devise => total, paye, reste
 * @var array<int, array> $lieux
 * @var list<array> $fournisseurs
 * @var array<int, array> $devises
 * @var bool $peutRecevoir
 */

use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;
?>
<?= View::rendre('gestion/stock/_onglets', ['flash' => $flash ?? null]) ?>

<div class="tre-entete">
    <div>
        <h1><?= e(t('achats.receptions_titre')) ?></h1>
        <p class="aide"><?= e(t('achats.receptions_aide')) ?></p>
    </div>
    <?php if ($peutRecevoir): ?><div class="tre-entete__actions"><a class="bouton" href="/gestion/achats/receptions/nouvelle">+ <?= e(t('achats.nouvelle_reception')) ?></a></div><?php endif; ?>
</div>

<section class="bloc tre-filtres" aria-label="<?= e(t('stock.filtres')) ?>">
    <?= View::rendre('gestion/tresorerie/_periode', ['periode' => $periode, 'action' => '/gestion/achats/receptions', 'avecDates' => false]) ?>
    <form class="tre-filtres__formulaire" method="get" action="/gestion/achats/receptions">
        <?php if ($periode['cle'] !== 'perso'): ?><input type="hidden" name="periode" value="<?= e($periode['cle']) ?>"><?php endif; ?>
        <div class="champ"><label for="f-du"><?= e(t('tresorerie.du')) ?></label><input type="date" id="f-du" name="du" value="<?= e($periode['cle'] === 'perso' ? (string) $periode['du'] : '') ?>"></div>
        <div class="champ"><label for="f-au"><?= e(t('tresorerie.au')) ?></label><input type="date" id="f-au" name="au" value="<?= e($periode['cle'] === 'perso' ? (string) $periode['au'] : '') ?>"></div>
        <div class="champ"><label for="f-fournisseur"><?= e(t('achats.fournisseur')) ?></label>
            <select id="f-fournisseur" name="fournisseur"><option value=""><?= e(t('achats.tous_fournisseurs')) ?></option>
                <?php foreach ($fournisseurs as $f): ?><option value="<?= (int) $f['id'] ?>" <?= $filtres['fournisseur'] === (int) $f['id'] ? 'selected' : '' ?>><?= e($f['nom']) ?></option><?php endforeach; ?></select></div>
        <div class="champ"><label for="f-lieu"><?= e(t('stock.lieu')) ?></label>
            <select id="f-lieu" name="lieu"><option value=""><?= e(t('stock.tous_lieux')) ?></option>
                <?php foreach ($lieux as $l): ?><option value="<?= (int) $l['id'] ?>" <?= $filtres['lieu'] === (int) $l['id'] ? 'selected' : '' ?>><?= e($l['nom'] . ' · ' . ($l['etablissement_nom'] ?? t('stock.central'))) ?></option><?php endforeach; ?></select></div>
        <div class="champ"><label for="f-paiement"><?= e(t('achats.paiement')) ?></label>
            <select id="f-paiement" name="paiement"><option value=""><?= e(t('achats.tous_paiements')) ?></option>
                <?php foreach (['non_payee', 'partielle', 'payee'] as $p): ?><option value="<?= $p ?>" <?= $filtres['paiement'] === $p ? 'selected' : '' ?>><?= e(t('achats.paiement_' . $p)) ?></option><?php endforeach; ?></select></div>
        <div class="tre-filtres__boutons"><button class="bouton bouton--secondaire" type="submit"><?= e(t('tresorerie.afficher')) ?></button></div>
    </form>
</section>

<?php if ($totaux !== []): ?>
    <div class="chiffres stk-chiffres">
        <?php foreach ($totaux as $deviseId => $t): if (!isset($devises[$deviseId])) { continue; } ?>
            <div class="chiffre" data-total-devise="<?= e($devises[$deviseId]['code']) ?>">
                <span class="chiffre__valeur"><?= e(A::montant($t['total'], $devises[$deviseId])) ?></span>
                <span class="chiffre__libelle"><?= e(t('achats.achats_en', ['code' => $devises[$deviseId]['code']])) ?> · <?= e(t('achats.reste', ['montant' => A::montant($t['reste'], $devises[$deviseId])])) ?></span>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<section class="bloc" aria-labelledby="liste-receptions">
    <h2 id="liste-receptions"><?= e(t(count($receptions) > 1 ? 'achats.n_receptions' : 'achats.une_reception', ['n' => count($receptions)])) ?></h2>
    <?php if ($receptions === []): ?><p class="aide"><?= e(t('achats.aucune_reception')) ?></p><?php else: ?>
        <div class="tre-defile">
            <table class="tableau stk-table">
                <thead><tr><th scope="col"><?= e(t('stock.col_numero')) ?></th><th scope="col"><?= e(t('achats.fournisseur')) ?></th><th scope="col" class="nombre"><?= e(t('achats.total')) ?></th><th scope="col"><?= e(t('achats.paiement')) ?></th></tr></thead>
                <tbody>
                    <?php foreach ($receptions as $r): ?>
                        <tr data-reception="<?= (int) $r['id'] ?>">
                            <td><a class="stk-numero" href="/gestion/achats/receptions/<?= (int) $r['id'] ?>"><?= e($r['numero']) ?></a><span class="tre-sous-texte"><?= e(A::dateHeure($r['date_reception'])) ?></span></td>
                            <td><?= e($r['fournisseur']) ?><span class="tre-sous-texte"><?= e($r['lieu_nom'] . ' · ' . ($r['etablissement_nom'] ?? t('stock.central'))) ?><?= $r['sans_facture'] ? ' · ' . e(t('achats.sans_facture_court')) : '' ?></span></td>
                            <td class="nombre"><strong><?= isset($devises[(int) $r['devise_id']]) ? e(A::montant($r['total'], $devises[(int) $r['devise_id']])) : '' ?></strong></td>
                            <td><?= View::rendre('gestion/achats/_etat-paiement', ['r' => $r, 'devises' => $devises]) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
