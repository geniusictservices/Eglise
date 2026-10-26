<?php
/**
 * @var array $qr
 * @var string $url
 */
$accent = couleurValide($qr['couleur']) ?? '#E04A12';
?>
<!doctype html>
<html lang="<?= e(App\Core\I18n::langue()) ?>" data-theme="jour">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= e($qr['etablissement'] . ' · ' . $qr['libelle']) ?></title>
    <?= App\Core\View::rendre('layouts/_polices') ?>
    <link rel="stylesheet" href="/assets/css/volcan.css">
    <link rel="stylesheet" href="/assets/css/gestion.css">
    <style>:root { --accent: <?= e($accent) ?>; --sur-accent: <?= e(texteSurCouleur($accent)) ?>; --accent-texte: <?= e(couleurLisible($accent, '#FFFFFF')) ?>; }</style>
</head>
<body class="impression">
<p class="impression__aide"><button class="bouton" type="button" data-imprimer><?= e(t('gestion.imprimer')) ?></button></p>
<div class="chevalet">
    <p class="chevalet__nom"><?= e($qr['etablissement']) ?></p>
    <?php if ($qr['zone_fr']): ?>
        <p class="chevalet__zone"><?= e(champ($qr, 'zone')) ?></p>
    <?php endif; ?>
    <div class="chevalet__qr" data-qr="<?= e($url) ?>" role="img" aria-label="QR"></div>
    <p class="chevalet__appel">Scannez pour voir le menu</p>
    <p class="chevalet__appel chevalet__appel--en" lang="en">Scan to see the menu</p>
    <p class="chevalet__url"><?= e(preg_replace('#^https?://#', '', $url)) ?></p>
</div>
<script src="/assets/vendor/qrcode.js"></script>
<script src="/assets/js/gestion-qr.js"></script>
</body>
</html>
