# restau-bar-pos : repères pour reprendre le travail

Caisse (POS) et ERP pour restaurants-bars, lounges et nightclubs de Goma (RDC). PHP natif sans framework ni
Composer, MySQL/MariaDB, français et anglais. Une instance par client sur son sous-domaine, une base par client,
et une console super admin (`admin.<domaine>`) avec sa propre base. Hébergement cible : LWS mutualisé cPanel.

À lire selon le sujet : `README.md` (tout ce qui existe, écran par écran), `docs/cahier-des-charges.md` (règles
métier, section 17 « Décisions prises »), `docs/console.md`, `docs/installation-lws.md`, `docs/design-system.md`,
`docs/manuel/index.html` (manuel d'utilisation). Ne pas tout relire : chercher ce dont on a besoin.

## Avec le propriétaire du projet

- Répondre en **français**. Il préfère qu'on lui pose **toutes les questions avant** un gros chantier
  (outil de questions à choix), puis qu'on avance seul.
- Design : **jamais de bande de couleur sur un seul côté** (bordure gauche ou haute plus épaisse) ; contour fin
  égal, fond teinté, pastille ou texte coloré (`docs/design-system.md`, principe 5). Cibles tactiles ≥ 44 px.
- Les montants s'affichent devise locale (FC) d'abord, dollar ensuite.
- Le zip LWS se fait avec `php bin/paquet-lws.php --demo=rbp_demo` ; s'il demande un super admin déjà présent
  dans `installation/console-vide.sql`, lui demander l'e-mail et le mot de passe (ne jamais les écrire dans le dépôt).

## Architecture

- `public/index.php` : contrôleur frontal. `admin.*` → console (`app/console.php`, routes `app/routes/console/`).
  Sinon `App\Core\Instance::sousDomaineDepuisHote()` : `<sd>.<domaine_base>` ou `<sd>.localhost` ; hôte nu
  (localhost) → `instance_par_defaut` de `config/app.php` (`demo`). Config d'instance :
  `config/instances/<sd>.php` (gitignoré, écrit par la console ou `bin/creer-instance.php`).
- Routes : `app/routes/*.php` (chargés par glob), `$routeur->get|post('/chemin/{id}', [Controleur::class, 'methode'])`.
- Contrôleurs `app/Controllers/` (`Gestion/`, `Console/`, `Caisse*Controller.php` à la racine), dépôts SQL
  `app/Repositories/`, noyau `app/Core/`, console `app/Console/`.
- Vues `views/` (`layouts/`, `caisse/`, `gestion/`, `console/`, vitrine), `View::afficher($nom, $donnees, $gabarit)`
  et `View::rendre()` ; échapper avec `e()`.
- Textes : `t('cle', ['param' => …])`. Fichiers `lang/fr.php` + `lang/fr/*.php` et **les mêmes clés** en
  `lang/en/…` (parité vérifiée par les tests). `I18n::dans($langue, …)` pour un message dans la langue d'un client.
- Accès : `Acces::garder()` lit l'écran dans `app/Core/Modules.php` (modules, écrans, permission `voir` pour GET,
  `modifier` pour POST) ; permissions dans `app/Core/Permissions.php`. Une permission qui ne commence pas par
  `caisse.` ouvre la gestion (`Acces::aLaGestion`) : les droits des caissiers restent en `caisse.*`.
- CSRF sur tous les formulaires (`Session::jetonCsrf()`), sessions régénérées par `Session::regenerer()`.
- Journée d'exploitation : `App\Core\Journee` (une vente à 2 h compte pour la veille selon `etablissements.fin_journee`).
- Abonnement, modules et limites venant de la console : `App\Core\Plateforme` (cache, jamais bloquant en plein
  service). SMS (Africa's Talking) et WhatsApp Cloud : `App\Core\Messagerie`, clés réglées dans la console ;
  sans clés, les messages sont « inscrits, non envoyés ».
- Caisse hors ligne : `public/assets/js/caisse-sw.js` (servi par `/caisse/sw.js`, portée `/caisse`, version =
  code), `caisse-hors-ligne*.js` (IndexedDB, PIN vérifiés par PBKDF2, ventes signées), `CaisseHorsLigneController`.
- CSS : `public/assets/css/volcan.css` (jetons) puis la feuille de l'espace ; JS sans build, fichiers par écran.

## Base de données

- Schéma `database/schema.sql` + migrations `database/migrations/NNN_nom.sql` (**prochaine : 024**), appliquées
  par `Migrations::appliquer` et notées dans `schema_migrations` ; console : `database/console/schema.sql` +
  `database/console/migrations/` (**prochaine : 002**). Ne jamais modifier une migration déjà livrée.
- Données de départ dans une migration : `INSERT IGNORE`, droits des rôles modèles par code de rôle, et un
  identifiant fixe élevé si une ligne doit exister partout (ex. grille « gros » id 900 dans 021).
- Argent : chaque opération précise compte de trésorerie + devise ; journal unique `mouvements_tresorerie` ;
  conversions au taux du jour ; clés d'idempotence (`cle`) contre les doubles envois.
- Téléphones enregistrés en `+243…` (`DetteRepository::normaliserTelephone`).

## Lancer et tester (Linux, MariaDB)

```
service mariadb start
php bin/creer-instance.php demo --nom="Groupe Démo" --email=admin@demo.test --demo --db-hote=localhost
php -S 127.0.0.1:8080 -t public public/index.php      # http://demo.localhost:8080/gestion
php bin/migrer.php --toutes                              # migrations de toutes les instances
```

Le mot de passe du propriétaire de démo est affiché à la création ; PIN de démo dans le README.
Crons : `bin/relances.php --toutes` (ardoises) et `bin/console-relances.php` (abonnements).

Tests de bout en bout (Playwright + scripts PHP/SQL) dans `outils/tests/` : voir `outils/README.md`. Chaque suite
repart d'une copie fraîche de la démo (`reconstruire.sh` une fois, puis `lancer.sh <suite…>`). Avant de pousser :
`php -l` sur les fichiers touchés et les suites des modules concernés. Le manuel (HTML et PDF) se reconstruit
avec `outils/manuel/`.

## Git

- Branche de travail : `ccr-1a39a766-ygf4f3`. Messages de commit en français, descriptifs.
- Jamais de secrets dans le dépôt : `config/console.php`, `config/instances/*.php`, `config/app.local.php`,
  `storage/documents/`, `public/uploads/*` sont ignorés.

## Restent ouverts

- Proposé, pas encore décidé : un `.env` pour les réglages communs (base de la console, domaine, débogage), les
  accès de chaque bar restant dans `config/instances/` ; un bouton « Modifier les accès à la base » dans la fiche
  client de la console (aujourd'hui : éditer `config/instances/<sd>.php`, la fiche garde l'ancien nom affiché).
- Choix gardés par défaut, à confirmer : un serveur sans le rôle qui ouvre `/caisse/entree` ou `/caisse/vestiaire`
  sur une caisse partagée verrouille la caisse ; la caisse des achats au marché reste proposée à la caisse si
  c'est la seule caisse d'espèces de l'établissement.
- Photos réelles des produits : `bin/importer-photos.php` prêt (accès à Wikimedia Commons refusé jusqu'ici).
