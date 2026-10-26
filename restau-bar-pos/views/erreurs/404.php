<!doctype html>
<html lang="<?= e(App\Core\I18n::langue()) ?>" data-theme="nuit">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(t('erreur.introuvable_titre')) ?></title>
    <meta name="theme-color" content="#111315">
    <?= App\Core\View::rendre('layouts/_polices') ?>
    <link rel="stylesheet" href="/assets/css/volcan.css">
    <link rel="stylesheet" href="/assets/css/vitrine.css">
</head>
<body>
<main class="enveloppe erreur">
    <p class="erreur__code">404</p>
    <h1><?= e(t('erreur.introuvable_titre')) ?></h1>
    <p><?= e(t('erreur.introuvable_texte')) ?></p>
    <p><a class="bouton" href="/"><?= e(t('erreur.retour_accueil')) ?></a></p>
</main>
</body>
</html>
