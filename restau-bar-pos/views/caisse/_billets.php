<?php
/**
 * Billets d'entrée et tickets de vestiaire à imprimer (80 mm) ou à montrer : un bloc par billet, avec son code QR
 * (dessiné par caisse-nuit.js depuis data-qr) et le code écrit en dessous (saisie à la main à la porte).
 * @var array $etablissement
 * @var list<array> $billets
 * @var list<array> $depots tickets de vestiaire
 * @var bool $seul sans vente : les billets forment leur propre reçu
 */
$categorie = static fn (string $c): string => t('nuit.categorie_' . $c);
?>
<?php if ($seul): ?><article class="recu recu--billets"><?php endif; ?>
<?php foreach ($billets as $b): ?>
    <section class="billet" data-billet="<?= (int) $b['id'] ?>">
        <p class="billet__etab"><?= e($etablissement['nom']) ?></p>
        <p class="billet__titre"><?= e(t('nuit.billet_titre')) ?> · <?= e($categorie($b['categorie'])) ?></p>
        <?php if (!empty($b['evenement_titre'])): ?><p class="billet__soiree"><?= e($b['evenement_titre']) ?></p><?php endif; ?>
        <p class="billet__date"><?= e(date('d/m/Y', strtotime($b['journee']))) ?><?= !empty($b['tarif_fr']) ? ' · ' . e(champ($b, 'tarif')) : '' ?></p>
        <div class="billet__qr" data-qr="<?= e($b['code']) ?>" role="img" aria-label="<?= e(t('nuit.billet_titre')) ?> <?= e($b['code']) ?>"></div>
        <p class="billet__code"><?= e($b['code']) ?></p>
        <p class="billet__aide"><?= e(t('nuit.billet_un_seul')) ?></p>
    </section>
<?php endforeach; ?>
<?php foreach ($depots as $d): ?>
    <section class="billet billet--vestiaire" data-depot="<?= (int) $d['id'] ?>">
        <p class="billet__etab"><?= e($etablissement['nom']) ?></p>
        <p class="billet__titre"><?= e(t('nuit.vestiaire_ticket')) ?></p>
        <p class="billet__numero"><?= e(t('nuit.vestiaire_numero', ['numero' => $d['numero']])) ?></p>
        <?php if (!empty($d['description'])): ?><p class="billet__soiree"><?= e($d['description']) ?></p><?php endif; ?>
        <p class="billet__date"><?= e(date('d/m/Y H:i', strtotime($d['depose_le']))) ?></p>
        <div class="billet__qr" data-qr="<?= e($d['code']) ?>" role="img" aria-label="<?= e(t('nuit.vestiaire_ticket')) ?> <?= e((string) $d['numero']) ?>"></div>
        <p class="billet__code"><?= e($d['code']) ?></p>
        <p class="billet__aide"><?= e(t('nuit.vestiaire_garder')) ?></p>
    </section>
<?php endforeach; ?>
<?php if ($seul): ?></article><?php endif; ?>
