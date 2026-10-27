<?php
/**
 * Reçu d'une recharge cashless (80 mm) : carte, montant chargé, paiements (compte, devise), monnaie, nouveau solde.
 * @var ?array $recharge (CashlessRepository::recharge)
 * @var array $etablissement
 * @var array<int, array> $devises
 */
if ($recharge === null) {
    return;
}
$formateur = new App\Repositories\DeviseRepository();
$montant = static fn (float|string $m, int $deviseId): string => isset($devises[$deviseId]) ? $formateur->formater((float) $m, $devises[$deviseId]) : (string) $m;
$deviseId = (int) $recharge['devise_id'];
?>
<article class="recu recu--recharge">
    <header class="recu__tete">
        <p class="recu__etab"><?= e($etablissement['nom']) ?></p>
        <p class="recu__titre"><?= e(t('nuit.recharge_titre')) ?></p>
    </header>
    <dl class="recu__meta">
        <div><dt><?= e(t('nuit.carte_titre')) ?></dt><dd><?= e($recharge['carte']['code'] ?? '') ?></dd></div>
        <div><dt><?= e(t('recu.date')) ?></dt><dd><?= e(date('d/m/Y H:i', strtotime($recharge['cree_le']))) ?></dd></div>
        <?php if (!empty($recharge['par_nom'])): ?><div><dt><?= e(t('recu.vendeur')) ?></dt><dd><?= e($recharge['par_nom']) ?></dd></div><?php endif; ?>
    </dl>
    <div class="recu__total"><span><?= e(t('nuit.recharge_montant')) ?></span><strong><?= e($montant($recharge['montant'], $deviseId)) ?></strong></div>
    <table class="recu__paiements">
        <tbody>
            <?php foreach ($recharge['paiements'] as $p): ?>
                <tr><td><?= e($p['compte']) ?><br><small><?= e(t('tresorerie.type_' . $p['moyen'])) ?></small></td><td class="recu__montant"><?= e($montant($p['recu'], (int) $p['devise_id'])) ?></td></tr>
            <?php endforeach; ?>
            <?php if ((float) $recharge['rendu'] > 0): ?>
                <tr class="recu__rendu"><td><?= e(t('recu.rendu')) ?></td><td class="recu__montant"><?= e($montant($recharge['rendu'], (int) $recharge['rendu_devise_id'])) ?></td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    <div class="recu__sous-total"><span><?= e(t('nuit.recharge_solde')) ?></span><span><?= e($montant($recharge['solde_apres'], $deviseId)) ?></span></div>
    <p class="recu__merci"><?= e(t('recu.merci')) ?></p>
</article>
