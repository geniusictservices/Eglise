# Déployer Waumini sur LWS (hébergement mutualisé)

Ce guide installe Waumini sur une offre mutualisée LWS (panneau cPanel). Il suppose le nom de domaine `waumini.com`, déjà pointé vers l'hébergement.

## 1. Préparer l'hébergement

1. **Version de PHP** : dans cPanel, *Sélectionner une version de PHP*, choisir **PHP 8.3** et activer les extensions `pdo_mysql`, `mbstring`, `intl`, `fileinfo`, `zip`, `gd`, `openssl`, `sodium`.
2. **Base de données** : *Bases de données MySQL*, créer une base (ex. `lwsuser_waumini`) et un utilisateur, puis donner **tous les privilèges** à l'utilisateur sur la base. Noter les trois valeurs.
3. **Certificat HTTPS** : *SSL/TLS Status* ou *Let's Encrypt*, activer le certificat pour le domaine. **Indispensable** : sans HTTPS, l'application ne s'installe pas sur les téléphones et Windows, et les notifications ne fonctionnent pas.

## 2. Envoyer les fichiers

Le code doit être **en dehors** du dossier public du site ; seul le dossier `public/` de Waumini doit être visible.

Organisation recommandée :

```
/home/lwsuser/
├── waumini/              ← tout le projet
└── waumini.com/       ← racine du domaine (document root)
```

- **Avec accès SSH** (recommandé) : `git clone` du dépôt dans `~/waumini`, puis `composer install --no-dev --optimize-autoloader`. Compiler les fichiers d'interface **sur votre ordinateur** (`npm ci && npm run build`) et envoyer le dossier `public/build`.
- **Sans SSH** : sur votre ordinateur, lancer `scripts/build-release.sh`, qui produit une archive `waumini-release-….zip` complète (dépendances et fichiers compilés). L'envoyer avec le *Gestionnaire de fichiers* de cPanel dans `~/waumini`, puis l'extraire.

Faire ensuite pointer le domaine vers `~/waumini/public` : dans cPanel, *Domaines*, modifier la **racine du document** du domaine pour `waumini/public`. Si l'offre ne le permet pas, copier le contenu de `public/` dans la racine du domaine et corriger les deux chemins de `index.php` (`__DIR__.'/../waumini/vendor/autoload.php'` et `__DIR__.'/../waumini/bootstrap/app.php'`).

## 3. Configurer

Créer `~/waumini/.env` à partir de `.env.example` :

```dotenv
APP_NAME=Waumini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://waumini.com
APP_LOCALE=fr

DB_CONNECTION=mysql
DB_HOST=localhost
DB_DATABASE=lwsuser_waumini
DB_USERNAME=lwsuser_waumini
DB_PASSWORD=********

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=database
```

Puis, en SSH (ou via le *Terminal* de cPanel) :

```bash
cd ~/waumini
php artisan key:generate
php artisan migrate --force
php artisan storage:link        # si refusé, voir plus bas
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

Ne **jamais** lancer `--seed` en production : il crée la communauté de démonstration.

## 4. Tâche planifiée (cron)

Dans cPanel, *Tâches Cron*, ajouter une tâche **toutes les minutes** :

```
* * * * * /usr/local/bin/php /home/lwsuser/waumini/artisan schedule:run >> /dev/null 2>&1
```

Le chemin de PHP peut varier ; cPanel l'indique dans la page *Tâches Cron*. Cette seule tâche suffit : elle traite la file d'attente (notifications, rapports), le ménage quotidien et, chaque matin, l'état des abonnements (fin d'essai, délai de grâce, lecture seule).

## 4 bis. Premier compte de l'équipe Genius ICT

L'espace d'administration (`https://waumini.com/admin`) est réservé à l'équipe Genius ICT. Créer le premier compte, avec le rôle Direction :

```bash
php artisan waumini:equipe 0812345678 "Prénom Nom"
```

La commande affiche un mot de passe provisoire, à changer à la première connexion. Ensuite, les autres membres de l'équipe s'ajoutent depuis l'écran **Équipe Genius ICT**. Dans l'espace d'administration, renseigner tout de suite :

- **Coordonnées et réglages** : le numéro WhatsApp et l'e-mail de contact ;
- **Offres et tarifs** : les prix définitifs ;
- **Textes juridiques** : les conditions d'utilisation et la politique de confidentialité relues par le juriste.

## 5. Vérifier

- `https://waumini.com/up` répond « Application up ».
- La page de connexion s'affiche, et le bouton « Installer » apparaît dans Chrome ou Edge.
- Le journal `~/waumini/storage/logs/laravel.log` ne contient pas d'erreur.

## Mettre à jour

```bash
cd ~/waumini
php artisan down
git pull                        # ou extraire la nouvelle archive
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan up
```

Envoyer aussi le nouveau dossier `public/build` compilé.

## Sauvegardes

- Activer les **sauvegardes automatiques** de LWS (fichiers et bases).
- En complément, exporter chaque semaine la base depuis *phpMyAdmin*, et la conserver hors de l'hébergement.

## Si `storage:link` est refusé

Certains hébergements interdisent les liens symboliques. Créer le dossier `public/storage` et y copier `storage/app/public`, ou demander au support LWS d'activer les liens symboliques. Ce point ne concerne que les fichiers envoyés par les utilisateurs (logos, photos), qui arriveront avec les prochains modules.

## Passer plus tard sur un serveur dédié (VPS)

Rien n'est à réécrire : il suffit de remplacer le cron de la file d'attente par un processus permanent (`supervisor`), et éventuellement de passer le cache et les sessions sur Redis.
