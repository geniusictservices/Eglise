<?php
/**
 * Navigation du module : comptes, transferts, dépenses.
 * @var string $actif comptes | transferts | depenses
 */
?>
<nav class="tre-onglets" aria-label="<?= e(t('tresorerie.titre')) ?>">
    <?php foreach ([
        'comptes' => ['/gestion/tresorerie', 'tresorerie.onglet_comptes'],
        'transferts' => ['/gestion/tresorerie/transferts', 'tresorerie.onglet_transferts'],
        'depenses' => ['/gestion/depenses', 'tresorerie.onglet_depenses'],
    ] as $cle => [$lien, $texte]): ?>
        <a class="puce" href="<?= $lien ?>"<?= $actif === $cle ? ' aria-current="page"' : '' ?>><?= e(t($texte)) ?></a>
    <?php endforeach; ?>
</nav>
