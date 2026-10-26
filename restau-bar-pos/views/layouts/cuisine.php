<?php
/**
 * Écran de cuisine : plein écran, sans menu. Thème nuit par défaut (moins d'éblouissement en cuisine),
 * modifiable et mémorisé par appareil et par établissement.
 * @var string $contenu
 */
?>
<!doctype html>
<html lang="<?= e(App\Core\I18n::langue()) ?>" data-theme="nuit" data-theme-cle="cuisine.theme.<?= e($etab['slug'] ?? '') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="#111315">
    <title><?= e(($titre ?? '') . ' · ' . ($etab['nom'] ?? '')) ?></title>
    <?= App\Core\View::rendre('layouts/_polices') ?>
    <link rel="stylesheet" href="/assets/css/volcan.css">
    <link rel="stylesheet" href="/assets/css/cuisine.css">
    <script src="/assets/js/theme.js"></script>
</head>
<body>
<?= $contenu ?>
<?php foreach ($scripts ?? [] as $script): ?>
<script src="<?= e($script) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
