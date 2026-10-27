<?php
/**
 * Cashless > Passif : soldes encore dus aux porteurs (par devise, dont cartes bloquées), et sur la période les
 * recharges (argent entré dans la trésorerie), paiements (sans mouvement de trésorerie), remboursements, annulations.
 * @var array $periode @var array $passif @var array<int, array> $devises @var array $bornes
 */
use App\Core\View;

$formateur = new App\Repositories\DeviseRepository();
?>
<link rel="stylesheet" href="/assets/css/gestion-salle.css">
<link rel="stylesheet" href="/assets/css/gestion-tresorerie.css">
<link rel="stylesheet" href="/assets/css/gestion-nuit.css">
<div class="sal-entete">
    <div>
        <h1><?= e(t('gnuit.passif_titre')) ?></h1>
        <p class="aide"><?= e(t('gnuit.passif_aide')) ?></p>
    </div>
    <a class="bouton bouton--secondaire" href="/gestion/cashless"><?= e(t('gnuit.cartes')) ?></a>
</div>
<section class="bloc" aria-labelledby="passif-soldes">
    <h2 id="passif-soldes"><?= e(t('gnuit.passif_soldes')) ?></h2>
    <div class="chiffres">
        <?php foreach ($devises as $id => $d): if (!isset($passif['soldes'][$id])) { continue; } $s = $passif['soldes'][$id]; ?>
            <div class="chiffre"><span class="chiffre__libelle"><?= e($d['code']) ?> · <?= e(t('gnuit.cartes_n', ['n' => $s['cartes']])) ?></span>
                <strong class="chiffre__valeur montant" data-passif="<?= e($d['code']) ?>"><?= e($formateur->formater($s['total'], $d)) ?></strong>
                <?php if ($s['bloquees'] > 0): ?><span class="aide"><?= e(t('gnuit.dont_bloquees', ['montant' => $formateur->formater($s['bloquees'], $d)])) ?></span><?php endif; ?></div>
        <?php endforeach; ?>
        <?php if ($passif['soldes'] === []): ?><p class="aide"><?= e(t('gnuit.aucune_carte')) ?></p><?php endif; ?>
    </div>
</section>
<?= View::rendre('gestion/tresorerie/_periode', ['periode' => $periode, 'action' => '/gestion/cashless/passif', 'garder' => []]) ?>
<section class="bloc" aria-labelledby="passif-periode">
    <h2 id="passif-periode"><?= e(t('gnuit.passif_periode', ['du' => date('d/m/Y', strtotime($bornes['du'])), 'au' => date('d/m/Y', strtotime($bornes['au']))])) ?></h2>
    <div class="defile">
        <table class="tableau">
            <thead><tr><th><?= e(t('nuit.c_devise')) ?></th><th class="nombre"><?= e(t('gnuit.mouvement_recharge')) ?></th><th class="nombre"><?= e(t('gnuit.mouvement_paiement')) ?></th><th class="nombre"><?= e(t('gnuit.mouvement_remboursement')) ?></th><th class="nombre"><?= e(t('gnuit.mouvement_annulation')) ?></th></tr></thead>
            <tbody>
                <?php foreach ($devises as $id => $d): if (!isset($passif['periode'][$id])) { continue; } $p = $passif['periode'][$id]; ?>
                    <tr><td><?= e($d['code']) ?></td>
                        <?php foreach (['recharge', 'paiement', 'remboursement', 'annulation'] as $type): ?>
                            <td class="nombre"><?= e($formateur->formater(abs($p[$type] ?? 0), $d)) ?><br><small><?= (int) ($p[$type . '_n'] ?? 0) ?></small></td>
                        <?php endforeach; ?></tr>
                <?php endforeach; ?>
                <?php if ($passif['periode'] === []): ?><tr><td colspan="5"><?= e(t('nuit.c_carte_vide')) ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
