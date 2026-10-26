<?php
/** Vitrine publique d'une instance suspendue : message neutre (rien sur l'abonnement), dans les deux langues. */
$nom = (string) App\Core\Config::get('parametres.nom_client', '');
?>
<!doctype html>
<html lang="<?= e(App\Core\I18n::langue()) ?>" data-theme="nuit">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="#0F1113">
    <title><?= e($nom !== '' ? $nom : t('plateforme.indisponible.titre')) ?></title>
    <?= App\Core\View::rendre('layouts/_polices') ?>
    <link rel="stylesheet" href="/assets/css/volcan.css">
    <link rel="stylesheet" href="/assets/css/vitrine.css">
</head>
<body>
<main class="enveloppe erreur">
    <?php if ($nom !== ''): ?><p class="surtitre"><?= e($nom) ?></p><?php endif; ?>
    <h1><?= e(t('plateforme.indisponible.titre')) ?></h1>
    <p><?= e(t('plateforme.indisponible.texte')) ?></p>
</main>
</body>
</html>
