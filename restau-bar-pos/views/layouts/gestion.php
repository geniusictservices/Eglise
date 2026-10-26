<?php
/** @var string $contenu */
$autreLangue = App\Core\I18n::langue() === 'fr' ? 'en' : 'fr';
$chemin = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
?>
<!doctype html>
<html lang="<?= e(App\Core\I18n::langue()) ?>" data-theme="auto" data-theme-cle="gestion.theme"
      data-mdp-afficher="<?= e(t('mdp.afficher')) ?>" data-mdp-masquer="<?= e(t('mdp.masquer')) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="#F4F3F1">
    <title><?= e(($titre ?? '') . ' · ' . t('gestion.nom')) ?></title>
    <?= App\Core\View::rendre('layouts/_polices') ?>
    <link rel="stylesheet" href="/assets/css/volcan.css">
    <link rel="stylesheet" href="/assets/css/gestion.css">
    <script src="/assets/js/theme.js"></script>
    <script src="/assets/js/mot-de-passe.js" defer></script>
</head>
<body>
<header class="haut">
    <div class="haut__ligne">
        <a class="haut__marque" href="/gestion"><?= e(App\Core\Config::get('parametres.nom_client', '')) ?></a>
        <?php if (!empty($utilisateur)): ?>
            <nav class="haut__nav" aria-label="<?= e(t('gestion.nom')) ?>">
                <a href="/gestion" <?= $chemin === '/gestion' ? 'aria-current="page"' : '' ?>><?= e(t('gestion.tableau')) ?></a>
                <a href="/caisse"><?= e(t('gestion.caisse')) ?></a>
                <?php foreach ([
                    '/gestion/catalogue' => 'gestion.menu_catalogue',
                    '/gestion/etablissements' => 'gestion.menu_etablissements',
                    '/gestion/devises' => 'gestion.menu_devises',
                    '/gestion/mediatheque' => 'gestion.menu_mediatheque',
                    '/gestion/qr' => 'gestion.qr',
                    '/gestion/compte' => 'gestion.menu_compte',
                    '/gestion/aide' => 'gestion.menu_aide',
                ] as $lien => $cleTexte): ?>
                    <a href="<?= $lien ?>" <?= str_starts_with((string) $chemin, $lien) ? 'aria-current="page"' : '' ?>><?= e(t($cleTexte)) ?></a>
                <?php endforeach; ?>
                <a href="/" target="_blank" rel="noopener"><?= e(t('gestion.voir_vitrine')) ?></a>
            </nav>
        <?php endif; ?>
        <div class="haut__droite">
            <a class="haut__langue" href="<?= e(urlAvec(['lang' => $autreLangue])) ?>" lang="<?= e($autreLangue) ?>"><?= strtoupper($autreLangue) ?></a>
            <?= App\Core\View::rendre('layouts/_bouton-theme') ?>
            <?php if (!empty($utilisateur)): ?>
                <form method="post" action="/gestion/deconnexion">
                    <input type="hidden" name="csrf" value="<?= e(App\Core\Session::jetonCsrf()) ?>">
                    <button class="lien" type="submit"><?= e(t('gestion.deconnexion')) ?></button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</header>
<main class="page">
<?= $contenu ?>
</main>
<?php if (!empty($utilisateur)): ?>
    <footer class="pied">
        <a href="/gestion/design" <?= $chemin === '/gestion/design' ? 'aria-current="page"' : '' ?>><?= e(t('design.titre')) ?></a>
    </footer>
<?php endif; ?>
</body>
</html>
