<?php
/** @var string $actif personnes | roles | appareils | journal */
$onglets = [
    'personnes' => ['/gestion/utilisateurs', 'utilisateurs.onglet_personnes'],
    'roles' => ['/gestion/utilisateurs/roles', 'utilisateurs.onglet_roles'],
    'appareils' => ['/gestion/utilisateurs/appareils', 'utilisateurs.onglet_appareils'],
    'journal' => ['/gestion/utilisateurs/journal', 'utilisateurs.onglet_journal'],
];
?>
<link rel="stylesheet" href="/assets/css/gestion-utilisateurs.css">
<nav class="uti-onglets" aria-label="<?= e(t('ecran.utilisateurs')) ?>">
    <?php foreach ($onglets as $cle => [$lien, $texte]): ?>
        <a class="puce" href="<?= $lien ?>" <?= $cle === $actif ? 'aria-current="page"' : '' ?>><?= e(t($texte)) ?></a>
    <?php endforeach; ?>
</nav>
