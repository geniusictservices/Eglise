<?php
/**
 * @var array $utilisateur
 * @var array $etablissements
 * @var array $devises
 * @var ?array $reference
 * @var int $nbProduits
 * @var int $scans7j
 */
?>
<p class="surtitre"><?= e(t('gestion.tableau')) ?></p>
<h1><?= e(t('gestion.bonjour', ['nom' => $utilisateur['nom']])) ?></h1>

<div class="chiffres">
    <div class="chiffre"><span class="chiffre__valeur"><?= count($etablissements) ?></span><span class="chiffre__libelle"><?= e(t('gestion.etablissements')) ?></span></div>
    <div class="chiffre"><span class="chiffre__valeur"><?= $nbProduits ?></span><span class="chiffre__libelle"><?= e(t('gestion.produits')) ?></span></div>
    <div class="chiffre"><span class="chiffre__valeur"><?= $scans7j ?></span><span class="chiffre__libelle"><?= e(t('gestion.scans_7j')) ?></span></div>
</div>

<section class="bloc">
    <h2><?= e(t('gestion.etablissements')) ?></h2>
    <table class="tableau">
        <thead><tr><th><?= e(t('gestion.nom_colonne')) ?></th><th><?= e(t('gestion.type')) ?></th><th><?= e(t('gestion.page_publique')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($etablissements as $etab): ?>
            <tr>
                <td><?= e($etab['nom']) ?></td>
                <td><?= e(t('type.' . $etab['type'])) ?></td>
                <td><a href="/e/<?= e($etab['slug']) ?>" target="_blank" rel="noopener">/e/<?= e($etab['slug']) ?></a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<section class="bloc">
    <h2><?= e(t('gestion.devises')) ?></h2>
    <table class="tableau">
        <thead><tr><th><?= e(t('gestion.devise')) ?></th><th><?= e(t('gestion.taux')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($devises as $devise): ?>
            <tr>
                <td><?= e($devise['code']) ?> · <?= e(champ($devise, 'nom')) ?>
                    <?php if ($devise['est_reference']): ?><span class="pastille"><?= e(t('gestion.reference')) ?></span><?php endif; ?></td>
                <td class="nombre">
                    <?php if ($devise['est_reference'] || !$reference): ?>—
                    <?php elseif ($devise['taux'] === null): ?><span class="pastille pastille--alerte"><?= e(t('gestion.taux_manquant')) ?></span>
                    <?php else: ?>1 <?= e($reference['code']) ?> = <?= e(rtrim(rtrim(number_format((float) $devise['taux'], 6, '.', ''), '0'), '.')) ?> <?= e($devise['code']) ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="aide"><?= e(t('gestion.devises_aide')) ?></p>
</section>
