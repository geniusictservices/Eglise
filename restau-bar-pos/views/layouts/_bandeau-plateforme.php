<?php
/**
 * Bandeaux de la plateforme en haut de la gestion (App\Core\Plateforme::bandeaux()) : abonnement à relancer,
 * délai de grâce, lecture seule, fin d'essai, session d'assistance. Rien sans console.
 * @var ?array $utilisateur
 */
$bandeaux = App\Core\Plateforme::bandeaux($utilisateur ?? null);
if ($bandeaux === []) {
    return;
}
?>
<link rel="stylesheet" href="/assets/css/plateforme.css">
<div class="plf-bandeaux">
    <?php foreach ($bandeaux as $bandeau): ?>
        <p class="plf-bandeau plf-bandeau--<?= e($bandeau['ton']) ?>" role="status"><?= e($bandeau['texte']) ?></p>
    <?php endforeach; ?>
</div>
