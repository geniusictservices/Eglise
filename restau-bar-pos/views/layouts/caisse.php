<?php /** @var string $contenu */ ?>
<!doctype html>
<html lang="<?= e(App\Core\I18n::langue()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="#15201E">
    <title><?= e(($titre ?? '') . ' · ' . ($etab['nom'] ?? '')) ?></title>
    <link rel="stylesheet" href="/assets/css/caisse.css">
</head>
<body>
<?= $contenu ?>
<script src="/assets/js/caisse.js" defer></script>
</body>
</html>
