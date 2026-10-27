# Installer restau-bar-pos sur LWS (hébergement mutualisé cPanel)

Ce guide part de l'archive `restau-bar-pos-lws-AAAAMMJJ.zip` et d'un hébergement **LWS avec cPanel**. Il ne demande **aucun accès SSH** : tout se fait dans cPanel (gestionnaire de fichiers, bases MySQL, domaines, tâches cron) et dans le navigateur.

Organisation retenue (cahier des charges, section 1) :

```
mondomaine.cd                  site vitrine de la plateforme (facultatif)
admin.mondomaine.cd            console super admin (abonnements, API SMS et WhatsApp)
<client>.mondomaine.cd         une instance par client : sa caisse, sa gestion, sa vitrine (une base par client)
```

Tous ces sous-domaines pointent vers **le même dossier** `public/` du projet ; l'application reconnaît l'instance d'après le sous-domaine.

## 1. Avant de commencer

- **Formule LWS** : un hébergement cPanel qui permet assez de **bases MySQL** et de **sous-domaines** (une base et un sous-domaine par client, plus la console). Vérifiez les limites de la formule dans l'espace client LWS.
- **Nom de domaine** géré chez LWS (ou dont les DNS pointent vers l'hébergement).
- **PHP 8.2 ou plus récent** (8.3 conseillé) : cPanel > **Sélectionner une version de PHP** (ou *MultiPHP Manager*). Extensions à cocher : `pdo_mysql`, `mbstring`, `curl`, `gd`, `fileinfo`, `json`, `zip`, `intl` (conseillée), `openssl`.
- **Réglages PHP** (même écran, onglet *Options*) : `upload_max_filesize` et `post_max_size` à 16M (photos, justificatifs), `memory_limit` à 256M, `max_execution_time` à 120 (création d'un client), `date.timezone` à `Africa/Lubumbashi`.
- **MySQL / MariaDB** en UTF-8 (utf8mb4) : c'est le cas par défaut chez LWS.

## 2. Déposer le code

1. cPanel > **Gestionnaire de fichiers** > dossier personnel (`/home/moncompte`). **Ne pas** décompresser dans `public_html` : seul le dossier `public/` du projet doit être servi sur le web.
2. **Charger** le fichier zip, puis clic droit > **Extraire**. Vous obtenez `/home/moncompte/restau-bar-pos/` avec `app/`, `bin/`, `config/`, `database/`, `docs/`, `installation/`, `lang/`, `public/`, `storage/`, `views/`.
3. **Droits d'écriture** (clic droit > *Modifier les autorisations*) : `755` pour les dossiers `storage/`, `storage/logs/`, `public/uploads/`, `config/` et `config/instances/` ; les fichiers restent en `644`. Ces dossiers reçoivent les journaux, les photos envoyées et les fichiers de configuration écrits par la console.
4. **Réglages du serveur** : dans `config/`, créez le fichier `app.local.php` (clic sur *+ Fichier*), avec votre domaine :

   ```php
   <?php
   return [
       'domaine_base' => 'mondomaine.cd',
       // Pas d'instance pour le domaine nu : il affiche « page introuvable » (ou votre site vitrine)
       'instance_par_defaut' => null,
   ];
   ```

   Ce fichier n'est jamais remplacé lors d'une mise à jour.

## 3. Installer la console super admin

Suivez **[docs/console.md, section 1](console.md#1-installer-la-console-sur-lws)** :

1. cPanel > **Domaines** > créer `admin.mondomaine.cd`, **racine du document** = `/home/moncompte/restau-bar-pos/public` (décocher « créer un dossier »).
2. cPanel > **Bases de données MySQL** : base `rbpconsole`, utilisateur `rbpconsole`, **tous les privilèges** (LWS préfixe les noms : `moncompte_rbpconsole`).
3. **HTTPS** : cPanel > *SSL/TLS Status* (Let's Encrypt) pour `admin.mondomaine.cd`.
4. Ouvrez `https://admin.mondomaine.cd` : la **page d'installation** demande une preuve (un fichier vide à créer dans `storage/`), la base, puis le premier super admin. Elle disparaît ensuite pour toujours.

*Si la page d'installation ne peut pas créer les tables* (délai dépassé, droits) : avec **phpMyAdmin**, importez `installation/console-vide.sql` dans la base de la console, puis rechargez la page : elle passe directement au premier super admin.

Dans la console : **Réglages** (contact de la plateforme, délais de grâce), **Formules** (prix, modules, limites), **API de messagerie** (voir la section 6).

## 4. Créer un client (une instance)

Suivez **[docs/console.md, section 5](console.md#5-créer-un-client-et-installer-son-instance)**. En bref, pour le client « Le Comptoir du Lac » au sous-domaine `comptoir` :

1. Console > **Clients** > *Nouveau client* : nom, contact (e-mail et téléphone du propriétaire), sous-domaine, formule, essai.
2. La fiche du client affiche les **étapes exactes dans cPanel** :
   - **Domaines** > créer `comptoir.mondomaine.cd`, racine = le même `public/` ;
   - **Bases de données MySQL** > base `rbp_comptoir` + utilisateur `rbp_comptoir` (mot de passe fort), **tous les privilèges** ;
   - certificat **SSL** pour `comptoir.mondomaine.cd`.
3. Dans la fiche : saisissez la base (`moncompte_rbp_comptoir`, utilisateur, mot de passe) > **Tester la connexion** > **Installer**. La console écrit `config/instances/comptoir.php`, crée les tables et le compte du propriétaire, et affiche **une seule fois** son mot de passe temporaire (à changer à la première connexion).
4. Le propriétaire ouvre `https://comptoir.mondomaine.cd/gestion` et crée ses établissements, ses produits, ses comptes de trésorerie, son personnel.

*Si « Installer » échoue sur un délai dépassé* : importez `installation/instance-vide.sql` dans la base du client avec **phpMyAdmin**, puis cliquez de nouveau sur **Installer** : la console reconnaît une base importée sans utilisateur, applique seulement les migrations plus récentes et crée le propriétaire.

*Démonstration* : `installation/instance-demo.sql` (s'il est dans l'archive) donne une instance complète de démonstration : créez un client « Démo » au sous-domaine `demo`, importez ce fichier dans sa base avant d'utiliser *Rattacher* dans la console. Les comptes de démonstration sont décrits dans le README.

## 5. Tâches cron

cPanel > **Tâches Cron** : deux lignes (voir **[docs/console.md, section 13](console.md#13-tâches-cron-dans-cpanel)** ; l'écran *Réglages* de la console affiche les lignes avec les vrais chemins) :

| Quand | Commande |
|---|---|
| chaque heure, minute 5 | `/usr/local/bin/php /home/moncompte/restau-bar-pos/bin/relances.php --toutes` |
| chaque heure, minute 20 | `/usr/local/bin/php /home/moncompte/restau-bar-pos/bin/console-relances.php` |

- `relances.php` : rappels des ardoises aux clients des bars et aux responsables (la veille de l'échéance, puis J+1, J+7, J+30), demandes aux garants.
- `console-relances.php` : rappels d'abonnement aux propriétaires.
- Le chemin de PHP dépend de la version choisie (`/usr/local/bin/php`, `/opt/alt/php83/usr/bin/php`…) : il est indiqué dans cPanel. Un message d'erreur de cron arrive par e-mail à l'adresse réglée en haut de l'écran *Tâches Cron*.

## 6. SMS et WhatsApp

Un seul compte pour tous les clients, réglé par le super admin dans la console > **API de messagerie** :

- **SMS** : [docs/plateformes/africastalking.md](plateformes/africastalking.md) (compte, vérification, clé d'API, identifiant d'expéditeur pour la RDC, crédit) ;
- **WhatsApp** : [docs/plateformes/whatsapp-business.md](plateformes/whatsapp-business.md) (compte Meta Business, numéro, jeton permanent, les trois modèles de messages à faire approuver).

Tant que rien n'est réglé, les messages sont seulement inscrits au journal (« inscrit, non envoyé ») : l'application fonctionne normalement.

## 7. Vérifications après l'installation

- `https://admin.mondomaine.cd` : connexion du super admin ; le tableau de bord de la console s'affiche.
- `https://comptoir.mondomaine.cd/gestion` : connexion du propriétaire ; l'écran des modules s'affiche.
- `https://comptoir.mondomaine.cd/caisse` : la caisse s'ouvre (après la création d'un établissement et de produits).
- Vitrine publique : `https://comptoir.mondomaine.cd/` (la page du bar et son menu par code QR).
- `storage/logs/php-erreurs.log` : vide ou presque (Gestionnaire de fichiers).

## 8. Mettre à jour le code

1. **Sauvegardez** : console > fiche de chaque client > *Sauvegarde* (fichier SQL), et la base de la console avec phpMyAdmin (*Exporter*).
2. Chargez la nouvelle archive dans `/home/moncompte`, extrayez-la dans un dossier temporaire, puis remplacez les dossiers `app/`, `bin/`, `database/`, `docs/`, `lang/`, `public/assets/`, `views/` et les fichiers `public/index.php`, `README.md`.
   **Ne remplacez jamais** `config/app.local.php`, `config/console.php`, `config/instances/`, `storage/` ni `public/uploads/`.
3. Console > **Clients** > *Mettre à jour toutes les instances* (et la console elle-même si elle le propose) : les nouvelles migrations s'appliquent.

## 9. Essayer sur un ordinateur (XAMPP, WAMP, Laragon)

L'application doit être servie depuis son dossier **`public/`, à la racine d'une adresse**. Une adresse comme `http://localhost/bar-resto/` (le projet dans un sous-dossier de `htdocs` ou `www`) ne fonctionne pas : elle n'affiche qu'une page d'explication, et les dossiers `config/`, `database/`, `storage/`, `bin/` et `installation/` y sont refusés.

1. **Base de données** : dans phpMyAdmin (`http://localhost/phpmyadmin`), créez la base `rbp_demo` (interclassement `utf8mb4_unicode_ci`), puis **Importer** > `installation/instance-demo.sql` (démonstration complète) ou `installation/instance-vide.sql` (bar vide).
2. **Accès à la base** : copiez `config/instances/exemple.php.dist` en `config/instances/demo.php` et remplissez-le (avec XAMPP : utilisateur `root`, mot de passe vide) :

   ```php
   <?php
   return [
       'actif' => true,
       'db' => ['hote' => '127.0.0.1', 'port' => 3306, 'nom' => 'rbp_demo', 'utilisateur' => 'root', 'mot_de_passe' => ''],
   ];
   ```

   L'adresse `localhost` ouvre l'instance `demo` (réglage `instance_par_defaut` de `config/app.php`).
3. **Lancer** : le plus simple est le serveur intégré de PHP, dans un terminal ouvert dans le dossier du projet (avec XAMPP sous Windows : `C:\xampp\php\php.exe` à la place de `php`) :

   ```
   php -S localhost:8000 -t public public/index.php
   ```

   Ouvrez ensuite `http://localhost:8000/gestion` (comptes de démonstration : voir le README) et `http://localhost:8000/caisse`.

   **Ou avec Apache** (XAMPP, WAMP) : ajoutez un hôte virtuel dont la racine est le dossier `public`, par exemple dans `C:\xampp\apache\conf\extra\httpd-vhosts.conf`, puis redémarrez Apache et ouvrez `http://localhost:8000` :

   ```apache
   Listen 8000
   <VirtualHost *:8000>
       DocumentRoot "C:/xampp/htdocs/bar-resto/public"
       <Directory "C:/xampp/htdocs/bar-resto/public">
           AllowOverride All
           Require all granted
       </Directory>
   </VirtualHost>
   ```

   Le module `mod_rewrite` doit être actif (c'est le cas par défaut avec XAMPP). Avec Laragon, *Menu > Apache > Document root* ou un hôte virtuel vers `public`.
4. **Console (facultatif)** : créez la base `rbp_console`, importez `installation/console-vide.sql`, copiez `config/console.php.dist` en `config/console.php` avec ces accès, et ouvrez `http://admin.localhost:8000` (Chrome, Edge et Firefox envoient `*.localhost` vers l'ordinateur lui-même). Un autre bar s'essaie de même sur `http://<sous-domaine>.localhost:8000` avec son fichier `config/instances/<sous-domaine>.php`.

## 10. Sécurité

- Les fichiers `config/console.php` et `config/instances/*.php` contiennent des mots de passe : ils ne sont jamais dans l'archive ni dans le dépôt, et sont hors de `public/` (non servis sur le web).
- HTTPS sur chaque sous-domaine (Let's Encrypt, renouvelé automatiquement par LWS).
- Mots de passe forts pour chaque base MySQL (un utilisateur par client) ; le super admin a 12 caractères au moins.
- Les clés Africa's Talking et WhatsApp ne sont jamais réaffichées par la console.
- Gardez des sauvegardes régulières hors de l'hébergement (console > sauvegarde de chaque client, et phpMyAdmin pour la console).
