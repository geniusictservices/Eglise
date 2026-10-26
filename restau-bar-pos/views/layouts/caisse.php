<?php
/** @var string $contenu */
// Thème par défaut selon le type d'établissement : nightclub et lounge de nuit, restaurant-bar de jour.
// Chaque appareil peut changer de thème ; le choix est mémorisé par établissement.
$theme = in_array($etab['type'] ?? '', ['nightclub', 'lounge'], true) ? 'nuit' : 'jour';
?>
<!doctype html>
<html lang="<?= e(App\Core\I18n::langue()) ?>" data-theme="<?= e($theme) ?>" data-theme-cle="caisse.theme.<?= e($etab['slug'] ?? '') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="<?= $theme === 'nuit' ? '#111315' : '#F4F3F1' ?>">
    <title><?= e(($titre ?? '') . ' · ' . ($etab['nom'] ?? '')) ?></title>
    <?= App\Core\View::rendre('layouts/_polices') ?>
    <link rel="stylesheet" href="/assets/css/volcan.css">
    <link rel="stylesheet" href="/assets/css/caisse.css">
    <script src="/assets/js/theme.js"></script>
</head>
<body>
<?= $contenu ?>
<script src="/assets/js/caisse.js" defer></script>
</body>
</html>
