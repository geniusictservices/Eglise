<?php
/**
 * Tableau de la masse salariale (mois × établissement) ; montants en devise locale d'abord, dollar ensuite.
 * @var array $masse mois → établissement → brut, retenues, net, salaires, avances, bulletins
 */
use App\Controllers\Gestion\PersonnelAffichage as P;
?>
<section class="bloc" aria-labelledby="per-masse">
    <h2 id="per-masse"><?= e(t('pers.masse_titre')) ?></h2>
    <?php if ($masse === []): ?>
        <p class="aide"><?= e(t('pers.masse_vide')) ?></p>
    <?php else: ?>
        <div class="defile">
            <table class="tableau per-tableau">
                <thead><tr>
                    <th scope="col"><?= e(t('pers.mois')) ?></th><th scope="col"><?= e(t('gestion.etablissement')) ?></th>
                    <th scope="col" class="nombre"><?= e(t('pers.bulletins')) ?></th><th scope="col" class="nombre"><?= e(t('pers.total_brut')) ?></th>
                    <th scope="col" class="nombre"><?= e(t('pers.total_retenues')) ?></th><th scope="col" class="nombre"><?= e(t('pers.col_net')) ?></th>
                    <th scope="col" class="nombre"><?= e(t('pers.salaires_verses')) ?></th><th scope="col" class="nombre"><?= e(t('pers.avances_versees')) ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($masse as $mois => $etabs): foreach ($etabs as $c): ?>
                        <tr data-masse="<?= e($mois) ?>">
                            <th scope="row"><?= e(P::nomMois((int) substr($mois, 5, 2)) . ' ' . substr($mois, 0, 4)) ?></th>
                            <td><?= e($c['etablissement']) ?></td>
                            <td class="nombre"><?= (int) $c['bulletins'] ?></td>
                            <td class="nombre montant"><?= e(P::montants($c['brut'])) ?></td>
                            <td class="nombre montant"><?= e(P::montants($c['retenues'])) ?></td>
                            <td class="nombre montant"><strong><?= e(P::montants($c['net'])) ?></strong></td>
                            <td class="nombre montant"><?= e(P::montants($c['salaires'])) ?></td>
                            <td class="nombre montant"><?= e(P::montants($c['avances'])) ?></td>
                        </tr>
                    <?php endforeach; endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
