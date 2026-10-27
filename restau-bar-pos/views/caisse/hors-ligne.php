<?php
/**
 * Page de secours sans réseau (gardée d'avance par le service worker) : l'écran demandé n'est pas encore sur
 * l'appareil (entrée, vestiaire, une zone jamais ouverte, une personne jamais entrée ici avec le réseau).
 * Autonome : volcan.css vient du cache, aucune police ni script externe.
 */
?>
<!doctype html>
<html lang="<?= e(App\Core\I18n::langue()) ?>" data-theme="nuit">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <title><?= e(t('horsligne.page_titre')) ?></title>
    <link rel="stylesheet" href="/assets/css/volcan.css">
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: var(--fond); color: var(--encre); font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
        .hl-secours { max-width: 30rem; margin: 16px; padding: 24px; border: 1px solid var(--ligne); border-radius: 16px; background: var(--surface); display: grid; gap: 16px; text-align: center; }
        .hl-secours h1 { margin: 0; font: 800 1.5rem/1.2 "Arial Black", "Segoe UI", system-ui, sans-serif; }
        .hl-secours p { margin: 0; color: var(--doux); }
        .hl-secours__actions { display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; }
    </style>
</head>
<body>
<main class="hl-secours">
    <h1><?= e(t('horsligne.page_titre')) ?></h1>
    <p><?= e(t('horsligne.page_texte')) ?></p>
    <div class="hl-secours__actions">
        <a class="bouton" href="/caisse"><?= e(t('horsligne.page_retour')) ?></a>
        <button class="bouton bouton--secondaire" type="button" onclick="location.reload()"><?= e(t('horsligne.page_reessayer')) ?></button>
    </div>
</main>
</body>
</html>
