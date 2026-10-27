<?php

// Ce fichier ne s'affiche que si le serveur web sert le dossier du projet au lieu de son dossier public/ :
// il remplace la liste des fichiers par une explication. L'application elle-même est dans public/index.php.
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>restau-bar-pos : configuration du serveur</title>
<style>
    body { margin: 0; padding: 32px 16px; font: 16px/1.55 system-ui, sans-serif; background: #f6f5f2; color: #1f1d1a; }
    main { max-width: 640px; margin: 0 auto; }
    code { background: #e9e6df; padding: 1px 5px; border-radius: 4px; }
    pre { background: #e9e6df; padding: 12px; border-radius: 8px; overflow-x: auto; }
</style>
</head>
<body>
<main>
    <h1>Le serveur ne pointe pas sur le bon dossier</h1>
    <p>restau-bar-pos doit être servi depuis son dossier <code>public/</code>, à la racine d'une adresse
        (pas dans un sous-dossier comme <code>http://localhost/bar-resto/</code>).</p>
    <p><strong>Sur un ordinateur</strong>, le plus simple, dans un terminal ouvert dans le dossier du projet :</p>
    <pre>php -S localhost:8000 -t public public/index.php</pre>
    <p>puis ouvrez <code>http://localhost:8000</code>. Avec XAMPP, WAMP ou Laragon, créez plutôt un hôte virtuel
        dont la racine est le dossier <code>public</code>.</p>
    <p><strong>Sur LWS (cPanel)</strong> : la racine du document de chaque sous-domaine est
        <code>…/restau-bar-pos/public</code>. Voir <code>docs/installation-lws.md</code>.</p>
    <hr>
    <p lang="en">The web server must serve the project's <code>public/</code> folder at the root of an address, not the project folder.</p>
</main>
</body>
</html>
