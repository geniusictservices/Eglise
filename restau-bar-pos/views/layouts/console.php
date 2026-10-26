<?php
/**
 * Gabarit de la console super admin (admin.<domaine>) : même design Volcan que la gestion des instances ;
 * barre latérale sur ordinateur, barre du bas sur téléphone, sans menu caché.
 * @var string $contenu
 * @var ?array $admin
 */
use App\Core\Icones;

$autreLangue = App\Core\I18n::langue() === 'fr' ? 'en' : 'fr';
$chemin = '/' . trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
$ecrans = [
    ['lien' => '/', 'cle' => 'tableau', 'icone' => 'rapports'],
    ['lien' => '/clients', 'cle' => 'clients', 'icone' => 'clients'],
    ['lien' => '/formules', 'cle' => 'formules', 'icone' => 'prix'],
    ['lien' => '/messagerie', 'cle' => 'messagerie', 'icone' => 'messagerie'],
    ['lien' => '/reglages', 'cle' => 'reglages', 'icone' => 'parametres'],
    ['lien' => '/journal', 'cle' => 'journal', 'icone' => 'inventaire'],
    ['lien' => '/compte', 'cle' => 'compte', 'icone' => 'compte'],
];
$courant = null;
foreach ($ecrans as $ecran) {
    if ($chemin === $ecran['lien'] || ($ecran['lien'] !== '/' && str_starts_with($chemin, $ecran['lien'] . '/'))) {
        $courant = $ecran['cle'];
    }
}
$plateforme = 'restau-bar-pos';
if (!empty($admin)) {
    try {
        $plateforme = App\Console\Reglages::get('plateforme.nom', 'restau-bar-pos');
    } catch (Throwable) {
    }
}
?>
<!doctype html>
<html lang="<?= e(App\Core\I18n::langue()) ?>" data-theme="auto" data-theme-cle="console.theme"
      data-mdp-afficher="<?= e(t('mdp.afficher')) ?>" data-mdp-masquer="<?= e(t('mdp.masquer')) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#F4F3F1">
    <title><?= e(($titre ?? '') . ' · ' . t('console.nom')) ?></title>
    <?= App\Core\View::rendre('layouts/_polices') ?>
    <link rel="stylesheet" href="/assets/css/volcan.css">
    <link rel="stylesheet" href="/assets/css/gestion.css">
    <link rel="stylesheet" href="/assets/css/console.css">
    <script src="/assets/js/theme.js"></script>
    <script src="/assets/js/mot-de-passe.js" defer></script>
</head>
<body class="console<?= !empty($admin) ? ' avec-module' : '' ?>">
<header class="haut">
    <div class="haut__ligne">
        <a class="haut__marque" href="/"><span class="haut__client"><?= e($plateforme) ?></span><span class="badge badge--lave con-marque"><?= e(t('console.nom_court')) ?></span></a>
        <div class="haut__droite">
            <a class="haut__langue" href="<?= e(urlAvec(['lang' => $autreLangue])) ?>" lang="<?= e($autreLangue) ?>"><?= strtoupper($autreLangue) ?></a>
            <?= App\Core\View::rendre('layouts/_bouton-theme') ?>
            <?php if (!empty($admin)): ?>
                <a class="haut__icone" href="/compte" title="<?= e($admin['nom']) ?>"><?= Icones::svg('compte', 20) ?><span class="visuellement-cache"><?= e($admin['nom']) ?></span></a>
                <form method="post" action="/deconnexion">
                    <input type="hidden" name="csrf" value="<?= e(App\Console\SessionConsole::jetonCsrf()) ?>">
                    <button class="lien haut__sortie" type="submit" title="<?= e(t('gestion.deconnexion')) ?>"><?= Icones::svg('sortie', 20) ?><span><?= e(t('gestion.deconnexion')) ?></span></button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</header>
<div class="cadre">
    <?php if (!empty($admin)): ?>
        <nav class="lateral" aria-label="<?= e(t('console.navigation')) ?>">
            <p class="lateral__titre"><?= Icones::svg('parametres', 22) ?><?= e(t('console.nom')) ?></p>
            <ul class="lateral__liste">
                <?php foreach ($ecrans as $ecran): ?>
                    <li><a class="lateral__lien" href="<?= e($ecran['lien']) ?>" <?= $courant === $ecran['cle'] ? 'aria-current="page"' : '' ?>><?= Icones::svg($ecran['icone'], 20) ?><span><?= e(t('console.ecran.' . $ecran['cle'])) ?></span></a></li>
                <?php endforeach; ?>
            </ul>
        </nav>
    <?php endif; ?>
    <main class="page">
        <?php if (!empty($flash)): ?>
            <p class="alerte<?= $flash['ton'] === 'erreur' ? ' alerte--erreur' : '' ?>" role="<?= $flash['ton'] === 'erreur' ? 'alert' : 'status' ?>" data-flash><?= e($flash['texte']) ?></p>
        <?php endif; ?>
        <?= $contenu ?>
    </main>
</div>
<?php if (!empty($admin)): ?>
    <nav class="barre-bas" aria-label="<?= e(t('console.navigation')) ?>">
        <?php foreach ($ecrans as $ecran): ?>
            <a class="barre-bas__lien" href="<?= e($ecran['lien']) ?>" <?= $courant === $ecran['cle'] ? 'aria-current="page"' : '' ?>><?= Icones::svg($ecran['icone'], 22) ?><span><?= e(t('console.ecran_court.' . $ecran['cle'])) ?></span></a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>
</body>
</html>
