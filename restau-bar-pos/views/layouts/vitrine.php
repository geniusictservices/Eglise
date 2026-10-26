<?php
/** @var string $contenu */
$description = isset($etab) ? champ($etab, 'slogan') : '';

// Thème selon le contexte : nightclub et lounge de nuit, restaurant-bar de jour.
// Page d'accueil : de jour seulement si tous les établissements sont des restaurants-bars.
if (isset($etab)) {
    $theme = in_array($etab['type'], ['nightclub', 'lounge'], true) ? 'nuit' : 'jour';
} elseif (!empty($etablissements)) {
    $theme = array_diff(array_column($etablissements, 'type'), ['restaurant_bar']) === [] ? 'jour' : 'nuit';
} else {
    $theme = 'nuit';
}

// Couleur propre à l'établissement : remplace la lave, avec un texte lisible posé dessus
$accent = couleurValide($etab['couleur'] ?? null);
?>
<!doctype html>
<html lang="<?= e(App\Core\I18n::langue()) ?>" data-theme="<?= e($theme) ?>" data-theme-cle="vitrine.theme">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= e($titre ?? 'Menu') ?></title>
    <?php if ($description !== ''): ?>
        <meta name="description" content="<?= e($description) ?>">
        <meta property="og:title" content="<?= e($titre ?? '') ?>">
        <meta property="og:description" content="<?= e($description) ?>">
    <?php endif; ?>
    <meta name="theme-color" content="<?= $theme === 'nuit' ? '#111315' : '#F4F3F1' ?>">
    <?= App\Core\View::rendre('layouts/_polices') ?>
    <link rel="stylesheet" href="/assets/css/volcan.css">
    <link rel="stylesheet" href="/assets/css/vitrine.css">
    <?php if ($accent): ?>
        <style>
            :root { --accent: <?= e($accent) ?>; --sur-accent: <?= e(texteSurCouleur($accent)) ?>; }
            [data-theme="jour"] { --accent-texte: <?= e(couleurLisible($accent, '#F4F3F1')) ?>; }
            [data-theme="nuit"] { --accent-texte: <?= e(couleurLisible($accent, '#24282D')) ?>; }
        </style>
    <?php endif; ?>
    <script src="/assets/js/theme.js"></script>
</head>
<body>
<?= $contenu ?>
<script src="/assets/js/menu.js" defer></script>
</body>
</html>
