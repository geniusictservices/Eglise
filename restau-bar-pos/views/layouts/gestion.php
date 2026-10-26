<?php
/** @var string $contenu */
$autreLangue = App\Core\I18n::langue() === 'fr' ? 'en' : 'fr';
$chemin = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
use App\Core\Icones;
use App\Core\Modules;

// Module de la page (barre latérale sur ordinateur, barre du bas sur téléphone) ; aucun sur l'accueil des modules
$modules = !empty($utilisateur) ? Modules::pour((int) $utilisateur['id']) : [];
$module = !empty($utilisateur) ? Modules::courant($chemin) : null;
if ($module !== null && !isset($modules[$module['module']])) {
    $module = null;
}
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
<body<?= $module ? ' class="avec-module"' : '' ?>>
<header class="haut">
    <div class="haut__ligne">
        <a class="haut__marque" href="/gestion" title="<?= e(t('modules.retour_titre')) ?>"><span class="haut__client"><?= e(App\Core\Config::get('parametres.nom_client', '')) ?></span></a>
        <?php if ($module): ?>
            <span class="haut__module"><?= Icones::svg($modules[$module['module']]['icone'], 18) ?><?= e(t('module.' . $module['module'] . '.nom')) ?></span>
        <?php endif; ?>
        <div class="haut__droite">
            <?php if ($module && $module['module'] === 'carte'): ?>
                <a class="haut__icone" href="/" target="_blank" rel="noopener" title="<?= e(t('gestion.voir_vitrine')) ?>"><?= Icones::svg('vitrine', 20) ?><span class="visuellement-cache"><?= e(t('gestion.voir_vitrine')) ?></span></a>
            <?php endif; ?>
            <a class="haut__langue" href="<?= e(urlAvec(['lang' => $autreLangue])) ?>" lang="<?= e($autreLangue) ?>"><?= strtoupper($autreLangue) ?></a>
            <?= App\Core\View::rendre('layouts/_bouton-theme') ?>
            <?php if (!empty($utilisateur)): $moi = App\Core\Database::un('SELECT nom, avatar FROM utilisateurs WHERE id = ?', [(int) $utilisateur['id']]); ?>
                <a class="haut__profil" href="/gestion/compte" title="<?= e(t('ecran.compte')) ?>"><?= App\Core\View::rendre('partials/_avatar', ['nom' => $moi['nom'] ?? $utilisateur['nom'], 'photo' => $moi['avatar'] ?? null, 'classe' => 'avatar--petit']) ?><span class="visuellement-cache"><?= e(t('ecran.compte')) ?> : <?= e($moi['nom'] ?? $utilisateur['nom']) ?></span></a>
                <form method="post" action="/gestion/deconnexion">
                    <input type="hidden" name="csrf" value="<?= e(App\Core\Session::jetonCsrf()) ?>">
                    <button class="lien haut__sortie" type="submit" title="<?= e(t('gestion.deconnexion')) ?>"><?= Icones::svg('sortie', 20) ?><span><?= e(t('gestion.deconnexion')) ?></span></button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</header>
<?= App\Core\View::rendre('layouts/_bandeau-plateforme', ['utilisateur' => $utilisateur ?? null]) ?>
<div class="cadre">
    <?php if ($module): $code = $module['module']; $nomModule = t('module.' . $code . '.nom'); ?>
        <nav class="lateral" aria-label="<?= e(t('modules.navigation', ['module' => $nomModule])) ?>">
            <a class="lateral__retour" href="/gestion" title="<?= e(t('modules.retour_titre')) ?>"><?= Icones::svg('modules', 18) ?><?= e(t('modules.retour')) ?></a>
            <p class="lateral__titre"><?= Icones::svg($modules[$code]['icone'], 22) ?><?= e($nomModule) ?></p>
            <ul class="lateral__liste">
                <?php foreach ($modules[$code]['ecrans'] as $ecran): ?>
                    <li>
                        <?php if ($ecran['lien'] !== null): ?>
                            <a class="lateral__lien" href="<?= e($ecran['lien']) ?>" <?= $ecran['cle'] === $module['ecran'] ? 'aria-current="page"' : '' ?>><?= Icones::svg($ecran['icone'], 20) ?><span><?= e(t('ecran.' . $ecran['cle'])) ?></span></a>
                        <?php else: ?>
                            <span class="lateral__lien lateral__lien--bientot" aria-disabled="true"><?= Icones::svg($ecran['icone'], 20) ?><span><?= e(t('ecran.' . $ecran['cle'])) ?></span><span class="badge badge--neutre"><?= e(t('modules.bientot')) ?></span></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
                <?php if ($code === 'carte'): ?>
                    <li><a class="lateral__lien" href="/" target="_blank" rel="noopener"><?= Icones::svg('vitrine', 20) ?><span><?= e(t('gestion.voir_vitrine')) ?></span></a></li>
                <?php endif; ?>
            </ul>
        </nav>
    <?php endif; ?>
    <main class="page">
    <?= $contenu ?>
    </main>
</div>
<?php if ($module): ?>
    <?php // Téléphone : barre du bas du module (ses écrans disponibles) et retour aux modules, sans menu caché ?>
    <nav class="barre-bas" aria-label="<?= e(t('modules.navigation', ['module' => $nomModule])) ?>">
        <a class="barre-bas__lien" href="/gestion"><?= Icones::svg('modules', 22) ?><span><?= e(t('modules.retour')) ?></span></a>
        <?php foreach ($modules[$code]['ecrans'] as $ecran): if ($ecran['lien'] === null) { continue; } ?>
            <a class="barre-bas__lien" href="<?= e($ecran['lien']) ?>" <?= $ecran['cle'] === $module['ecran'] ? 'aria-current="page"' : '' ?>><?= Icones::svg($ecran['icone'], 22) ?><span><?= e(t('ecran.' . $ecran['cle'])) ?></span></a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>
</body>
</html>
