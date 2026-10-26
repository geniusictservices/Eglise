<?php
/**
 * En-tête commun des écrans Stock et Achats : feuilles de style et message flash.
 * @var ?string $flash
 */
?>
<link rel="stylesheet" href="/assets/css/gestion-tresorerie.css">
<link rel="stylesheet" href="/assets/css/gestion-stock.css">
<?php if (!empty($flash)): ?>
    <p class="alerte" role="status"><?= e($flash) ?></p>
<?php endif; ?>
