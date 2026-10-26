<?php
/**
 * @var array $qr
 * @var string $url
 */
$accent = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $qr['couleur']) ? $qr['couleur'] : '#1F6F78';
?>
<!doctype html>
<html lang="<?= e(App\Core\I18n::langue()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= e($qr['etablissement'] . ' · ' . $qr['libelle']) ?></title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@800&display=swap">
    <link rel="stylesheet" href="/assets/css/gestion.css">
    <style>:root { --accent: <?= e($accent) ?>; }</style>
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
