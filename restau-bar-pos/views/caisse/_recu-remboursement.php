<?php
/**
 * Reçu d'un remboursement d'ardoise (80 mm) : client, montant payé et compte, ardoises réglées, reste dû.
 *
 * @var array $remboursement
 * @var array $client
 * @var array $compte
 * @var ?array $caissier
 * @var list<array> $affectations
 * @var array<int, float> $encours reste dû par devise après le remboursement
 * @var array<int, array> $devises
 * @var array $etablissement
 */
$formateur = new App\Repositories\DeviseRepository();
$montant = static fn (float|string $m, int $deviseId): string => isset($devises[$deviseId]) ? $formateur->formater((float) $m, $devises[$deviseId]) : (string) $m;
$restes = array_filter($encours, static fn (float $m) => $m > 0);
// Devise locale d'abord, le dollar ensuite
uksort($restes, static fn (int $a, int $b) => [-(int) ($devises[$a]['est_locale'] ?? 0), ($devises[$a]['code'] ?? '') !== 'USD', $a]
    <=> [-(int) ($devises[$b]['est_locale'] ?? 0), ($devises[$b]['code'] ?? '') !== 'USD', $b]);
?>
<article class="recu" aria-labelledby="recu-remboursement-<?= (int) $remboursement['id'] ?>">
    <header class="recu__tete">
        <p class="recu__etab"><?= e($etablissement['nom']) ?></p>
        <?php if (!empty($etablissement['adresse'])): ?><p class="recu__info"><?= e($etablissement['adresse']) ?></p><?php endif; ?>
        <?php if (!empty($etablissement['telephone'])): ?><p class="recu__info"><?= e(afficherTelephone($etablissement['telephone'])) ?></p><?php endif; ?>
    </header>
    <p class="recu__titre" id="recu-remboursement-<?= (int) $remboursement['id'] ?>"><?= e(t('recu.remboursement')) ?></p>
    <dl class="recu__meta">
        <div><dt><?= e(t('recu.client')) ?></dt><dd><?= e($client['nom']) ?></dd></div>
        <div><dt><?= e(t('recu.date')) ?></dt><dd><?= e(date('d/m/Y H:i', strtotime($remboursement['cree_le']))) ?></dd></div>
        <?php if ($caissier): ?><div><dt><?= e(t('recu.vendeur')) ?></dt><dd><?= e($caissier['nom']) ?></dd></div><?php endif; ?>
    </dl>
    <table class="recu__lignes">
        <tbody>
            <?php foreach ($affectations as $a): ?>
                <tr><td><?= e(t('recu.affecte', ['date' => date('d/m/Y', strtotime($a['dette_le']))])) ?></td><td class="recu__montant"><?= e($montant($a['montant'], (int) $a['devise_id'])) ?></td></tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div class="recu__total">
        <span><?= e(t('recu.paye')) ?></span>
        <strong><?= e($montant($remboursement['montant'], (int) $remboursement['devise_id'])) ?></strong>
    </div>
    <table class="recu__paiements">
        <tbody>
            <tr><td><?= e($compte['nom']) ?><br><small><?= e(t('tresorerie.type_' . $compte['type'])) ?></small></td><td class="recu__montant"><?= e($montant($remboursement['montant'], (int) $remboursement['devise_id'])) ?></td></tr>
            <tr class="recu__rendu"><td><?= e(t('recu.reste_du')) ?></td><td class="recu__montant"><?= $restes === [] ? '—' : e(implode(' · ', array_map(static fn (int $id, float $m) => $montant($m, $id), array_keys($restes), $restes))) ?></td></tr>
        </tbody>
    </table>
    <p class="recu__merci"><?= e($restes === [] ? t('recu.solde') : t('recu.merci')) ?></p>
</article>
