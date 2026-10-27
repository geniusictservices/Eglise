<?php
/**
 * Cartes ou bracelets cashless à imprimer : le code QR (numéro + jeton) et le code écrit, pour la saisie à la main.
 * Format carte bancaire (85 × 54 mm) en gestion, ticket de 80 mm à la caisse.
 * @var list<array> $cartes
 * @var array|null $etablissement
 */
?>
<article class="recu recu--cartes">
    <?php foreach ($cartes as $c): ?>
        <section class="carte-cashless" data-carte="<?= (int) $c['id'] ?>">
            <p class="carte-cashless__titre"><?= e($c['support'] === 'bracelet' ? t('nuit.bracelet_titre') : t('nuit.carte_titre')) ?><?= !empty($etablissement['nom']) ? ' · ' . e($etablissement['nom']) : '' ?></p>
            <div class="carte-cashless__qr" data-qr="<?= e($c['code']) ?>" role="img" aria-label="<?= e($c['code']) ?>"></div>
            <p class="carte-cashless__numero">N° <?= e(str_pad((string) $c['numero'], 4, '0', STR_PAD_LEFT)) ?></p>
            <p class="carte-cashless__code"><?= e($c['code']) ?></p>
            <?php if (!empty($c['porteur'])): ?><p class="carte-cashless__porteur"><?= e($c['porteur']) ?></p><?php endif; ?>
            <p class="carte-cashless__aide"><?= e(t('nuit.carte_aide')) ?></p>
        </section>
    <?php endforeach; ?>
</article>
