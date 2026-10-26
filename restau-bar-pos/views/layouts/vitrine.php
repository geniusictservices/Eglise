<?php
/** @var string $contenu */
$accent = isset($etab['couleur']) && preg_match('/^#[0-9a-fA-F]{6}$/', (string) $etab['couleur']) ? $etab['couleur'] : '#1F6F78';
$description = isset($etab) ? champ($etab, 'slogan') : '';
?>
<!doctype html>
<html lang="<?= e(App\Core\I18n::langue()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= e($titre ?? 'Menu') ?></title>
    <?php if ($description !== ''): ?>
        <meta name="description" content="<?= e($description) ?>">
        <meta property="og:title" content="<?= e($titre ?? '') ?>">
        <meta property="og:description" content="<?= e($description) ?>">
    <?php endif; ?>
    <meta name="theme-color" content="<?= e($accent) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@700;800&display=swap">
    <link rel="stylesheet" href="/assets/css/vitrine.css">
    <style>:root { --accent: <?= e($accent) ?>; }</style>
</head>
<body>
<?= $contenu ?>
<script src="/assets/js/menu.js" defer></script>
</body>
</html>
