<?php
/**
 * Gabarit des pages publiques du pointage : borne (tablette à l'entrée) et téléphone de l'employé.
 * Léger (pas de menu), lisible au soleil comme dans la pénombre ; thème nuit pour la borne, système pour le téléphone.
 * @var string $contenu
 * @var string $theme nuit | jour | auto
 */
$autreLangue = App\Core\I18n::langue() === 'fr' ? 'en' : 'fr';
?>
<!doctype html>
<html lang="<?= e(App\Core\I18n::langue()) ?>" data-theme="<?= e($theme ?? 'auto') ?>" data-theme-cle="pointage.theme">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="#111315">
    <title><?= e($titre ?? t('pointage.titre')) ?></title>
    <?= App\Core\View::rendre('layouts/_polices') ?>
    <link rel="stylesheet" href="/assets/css/volcan.css">
    <link rel="stylesheet" href="/assets/css/pointage.css">
    <script src="/assets/js/theme.js"></script>
</head>
<body class="pointage">
<div class="pointage__barre">
    <a class="pointage__langue" href="<?= e(urlAvec(['lang' => $autreLangue])) ?>" lang="<?= e($autreLangue) ?>"><?= strtoupper($autreLangue) ?></a>
    <?= App\Core\View::rendre('layouts/_bouton-theme') ?>
</div>
<?= $contenu ?>
<?php foreach ($scripts ?? [] as $script): ?>
<script src="<?= e($script) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
