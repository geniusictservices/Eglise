<?php
/**
 * Onglets d'un écran du module Personnel (présence, plannings, paie).
 * @var array<string, array{0: string, 1: string}> $onglets clé => [lien, clé de texte]
 * @var string $actif
 * @var string $libelle nom de la navigation
 */
?>
<link rel="stylesheet" href="/assets/css/gestion-tresorerie.css">
<link rel="stylesheet" href="/assets/css/gestion-personnel.css">
<nav class="per-onglets" aria-label="<?= e($libelle) ?>">
    <?php foreach ($onglets as $cle => [$lien, $texte]): ?>
        <a class="puce" href="<?= e($lien) ?>" <?= $cle === $actif ? 'aria-current="page"' : '' ?>><?= e(t($texte)) ?></a>
    <?php endforeach; ?>
</nav>
