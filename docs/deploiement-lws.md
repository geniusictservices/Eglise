# Déployer Waumini sur LWS (hébergement mutualisé)

Ce guide installe Waumini sur une offre mutualisée LWS (panneau cPanel). Il suppose le nom de domaine `waumini.com`, déjà pointé vers l'hébergement.

## 1. Préparer l'hébergement

1. **Version de PHP** : dans cPanel, *Sélectionner une version de PHP*, choisir **PHP 8.3** et activer les extensions `pdo_mysql`, `mbstring`, `intl`, `fileinfo`, `zip`, `gd`, `openssl`, `sodium`, `gmp` (notifications sur le téléphone).
   Dans les *Options* de PHP, régler : `upload_max_filesize = 20M`, `post_max_size = 24M` (audios des prédications, photos), `max_execution_time = 120` (création d'une démo, sauvegarde), `memory_limit = 256M`.
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
APP_TIMEZONE=Africa/Lubumbashi
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

# Notifications sur les téléphones (clés générées plus bas)
VAPID_PUBLIC_KEY=
VAPID_PRIVATE_KEY=
VAPID_SUBJECT=mailto:geniusictservices@gmail.com

# Sauvegardes : un mot de passe long, gardé aussi hors du serveur
BACKUP_PASSWORD=********
BACKUP_KEEP=14
```

`APP_TIMEZONE` règle l'heure affichée partout (Goma et Lubumbashi : `Africa/Lubumbashi` ; Kinshasa : `Africa/Kinshasa`).

Puis, en SSH (ou via le *Terminal* de cPanel) :

```bash
cd ~/waumini
php artisan key:generate
php artisan waumini:vapid       # affiche les deux clés à copier dans .env
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

Ne **jamais** lancer `--seed` en production : il crée la communauté de démonstration.

## 4. Tâche planifiée (cron)

Dans cPanel, *Tâches Cron*, ajouter une tâche **toutes les minutes** :

```
* * * * * /usr/local/bin/php /home/lwsuser/waumini/artisan schedule:run >> /dev/null 2>&1
```

Le chemin de PHP peut varier ; cPanel l'indique dans la page *Tâches Cron*. Cette seule tâche suffit. Elle lance :

| Quand | Quoi |
|---|---|
| Chaque minute | La file d'attente : notifications sur les téléphones, rapports |
| Chaque heure | L'effacement des démos publiques arrivées à leur terme |
| Chaque nuit, 2 h 15 | La **sauvegarde** de la base et des fichiers |
| Chaque matin, 5 h | L'état des abonnements : fin d'essai, délai de grâce, lecture seule |
| Chaque matin, 6 h 30 | Les anniversaires du jour, à l'équipe pastorale |
| Chaque jour | Le ménage des sessions et jetons expirés |

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
- `php artisan schedule:list` montre les tâches planifiées, et `php artisan waumini:sauvegarde` produit une archive.
- Dans **Nouveautés**, le cadre *Sur cet appareil* propose **Activer**, et une nouveauté arrive sur le téléphone (clés VAPID).
- **Essayer la démo**, sur la page d'accueil, crée une démo en quelques secondes.
- Le journal `~/waumini/storage/logs/laravel.log` ne contient pas d'erreur.

## Mettre à jour

```bash
cd ~/waumini
php artisan waumini:sauvegarde  # toujours, avant une mise à jour
php artisan down
git pull                        # ou extraire la nouvelle archive
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan up
```

Envoyer aussi le nouveau dossier `public/build` compilé.

## Sauvegardes

Waumini se sauvegarde **lui-même chaque nuit** (2 h 15) : une archive `storage/app/backups/waumini-AAAA-MM-JJ-HHMMSS.zip` contient

- `database.sql` : toute la base, en SQL ;
- `fichiers/` : les fichiers envoyés (logos, photos de membres, justificatifs de dépenses, captures de paiements, photos des sites, audios des prédications).

L'archive est **chiffrée** (AES-256) avec `BACKUP_PASSWORD`. Les 14 dernières sont gardées (`BACKUP_KEEP`).

Une sauvegarde qui reste sur le même serveur ne protège pas d'une panne de l'hébergement. Il faut donc **une copie ailleurs** :

- **Le plus simple** : dans l'espace Genius ICT, **Sauvegardes**, télécharger la dernière archive **chaque semaine** et la ranger sur un disque et dans un espace en ligne de Genius ICT. Le même écran permet de **sauvegarder maintenant**, avant une mise à jour par exemple.
- **Automatique** : déclarer un disque distant (FTP, SFTP ou S3) dans `config/filesystems.php`, puis `BACKUP_DISK=nom_du_disque` ; chaque archive y est copiée dans un dossier `waumini/`.
- Garder aussi les **sauvegardes automatiques de LWS** (fichiers et bases), en complément.

Faire une sauvegarde à la main : `php artisan waumini:sauvegarde`.

### Restaurer

1. Ouvrir l'archive avec le mot de passe (7-Zip sous Windows, ou `unzip` sur le serveur).
2. Mettre le site en maintenance : `php artisan down`.
3. Dans *phpMyAdmin*, vider la base (ou en créer une nouvelle), puis **Importer** `database.sql`. En SSH : `mysql -u UTILISATEUR -p BASE < database.sql`.
4. Replacer le contenu du dossier `fichiers/` dans `~/waumini/storage/app/private/`.
5. `php artisan config:cache && php artisan up`.

Faites un **essai de restauration** sur une base de test au moins une fois, avant l'arrivée des communautés pilotes.

## Les fichiers envoyés

Logos, photos, justificatifs et audios sont rangés dans `storage/app/private`, **hors du dossier public** : Waumini les montre seulement aux personnes qui y ont droit (ou, pour le logo, la photo d'un site vitrine et ses audios, au public). Aucun lien symbolique `storage:link` n'est nécessaire.

## Passer plus tard sur un serveur dédié (VPS)

Rien n'est à réécrire : il suffit de remplacer le cron de la file d'attente par un processus permanent (`supervisor`), et éventuellement de passer le cache et les sessions sur Redis.
