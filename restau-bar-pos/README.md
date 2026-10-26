# restau-bar-pos

Système de caisse (POS) et ERP adaptable aux restaurants-bars, lounge bars et nightclubs.
Chaque client dispose de sa propre instance sur un sous-domaine, gérée depuis une console super admin.

## Documentation

- [Manuel d'utilisation](docs/manuel/index.html) (HTML avec captures ordinateur et téléphone) et sa [version PDF](docs/manuel/manuel-restau-bar-pos.pdf)
- [Cahier des charges](docs/cahier-des-charges.md)
- [Présentation du projet (PDF)](docs/restau-bar-pos-presentation.pdf)
- [Système de design « Volcan »](docs/design-system.md)
- [Photos des produits](docs/photos.md)

## État d'avancement

Phase 1 en cours. Déjà disponible :

- résolution de l'instance par sous-domaine (`monbar.<domaine>` → `config/instances/monbar.php`) ;
- **vitrine publique** de chaque établissement : présentation, horaires, état d'ouverture, menu avec les prix dans la devise choisie, prix de la zone (VIP…), photos des produits avec leur crédit, recherche, français et anglais, thème jour ou nuit selon le type d'établissement ;
- **codes QR** : `/q/<code>` compte le scan puis ouvre le menu (avec la zone) ; création, téléchargement (SVG) et chevalet de table à imprimer depuis l'espace de gestion ;
- **écran de caisse** (`/caisse`) : produits en grille par catégorie (et sous-catégorie), choix de la taille et du format, ticket avec totaux dans chaque devise acceptée, prix de la zone, adapté au type d'établissement (grandes tuiles et thème nuit pour un nightclub ou un lounge), utilisable sur tablette et téléphone. L'enregistrement des ventes arrive en phase 3 ;
- **espace de gestion** (`/gestion`), en français et en anglais, utilisable sur téléphone :
  - connexion (limite des essais, langue préférée du compte appliquée) et tableau de bord ;
  - **catalogue** (`/gestion/catalogue`) : produits groupés par catégorie et sous-catégorie (deux niveaux) avec filtres, tailles d'un même produit (Primus Petite 33 cl, Grande 72 cl : chacune avec ses formats, prix et disponibilité) listées sous lui, « disponible / épuisé » en un toucher, ordre ; fiche produit (informations FR/EN, badges, allergènes, photo envoyée et ré-encodée avec son crédit, formats, tableau des prix formats × grilles × devises avec le calcul automatique affiché, établissements où il est vendu, désactivation) ; création sur une seule page ; catégories (`/gestion/catalogue/categories`) ;
  - **établissements** (`/gestion/etablissements`) : liste avec l'état ouvert / fermé, création, fiche en six sections (informations et liens, apparence : couleur, logo et couverture, prix et devises, horaires par service, zones avec leur grille, activation) ; **grilles de prix** (`/gestion/grilles`) ;
  - **devises et taux** (`/gestion/devises`) : devise de référence, taux du jour (avec date de début, éventuellement programmée), ajout et modification des devises (format, décimales, arrondi), activation, historique des taux ;
  - **trésorerie** (`/gestion/tresorerie`) : comptes en espèces, mobile money et banque (une devise par compte : « Caisse USD », « M-Pesa CDF »…) groupés par moyen avec leur solde, totaux par devise (devise locale d'abord, dollar ensuite) et total général au taux du jour ; fiche d'un compte avec ses mouvements et le solde après chaque ligne sur une période ; **transferts** entre comptes (`/gestion/tresorerie/transferts`), entre deux devises avec le montant reçu proposé au taux du jour et le taux réellement appliqué enregistré ; **dépenses** (`/gestion/depenses`) : filtres (période, catégorie, établissement, moyen), totaux par devise et équivalent en devise de référence, saisie où chaque paiement choisit le moyen puis le compte (une dépense payée en dollars et en francs a deux paiements), catégories (`/gestion/depenses/categories`). Les soldes se calculent depuis un journal unique (`mouvements_tresorerie`) où les ventes et achats des phases suivantes écriront aussi ; un découvert est signalé avant d'enregistrer, sans être bloqué ;
  - **mon compte** (`/gestion/compte`) : nom, langue préférée, mot de passe ;
  - **médiathèque** (`/gestion/mediatheque`) : toutes les images, ajout par glisser-déposer, collage ou choix de fichiers, fiche (textes alternatifs, crédit, utilisations), suppression des images non utilisées ; le même champ image (glisser-déposer, collage, choix dans la médiathèque) sert pour la photo des produits, le logo et la couverture des établissements ;
  - codes QR et chevalet (`/gestion/qr`) ;
  - **aide** (`/gestion/aide`) : le manuel d'utilisation et sa version PDF ;
  - **système de design** (`/gestion/design`) : page vivante des couleurs, contrastes, typographie et composants, en jour et en nuit.

Photos de démonstration : l'outil `bin/importer-photos.php` est prêt mais n'a pas encore pu être lancé (accès réseau à Wikimedia Commons refusé) ; la démo utilise des illustrations SVG. Voir [docs/photos.md](docs/photos.md).

## Technologie

- PHP 8.1 ou plus, sans framework ni dépendance à installer.
- MySQL 8 ou MariaDB 10.6+, une base par instance.
- HTML, CSS et JavaScript natifs. La seule bibliothèque (dessin des codes QR, licence MIT) est incluse dans `public/assets/vendor/`.

## Organisation du code

```
app/
  bootstrap.php          chargement des classes et de la configuration
  helpers.php            fonctions d'affichage (e(), t(), champ()…) et couleurs (contraste, texte lisible)
  Core/                  noyau : Config, Instance, Database, Router, View, I18n, Session,
                         Migrations, Images (contrôle et ré-encodage des images envoyées)
  Controllers/           VitrineController (pages publiques), CaisseController, Gestion/ (espace de gestion)
  Repositories/          accès aux données : devises et prix, établissements, catalogue,
                         Gestion*Repository pour les écrans de gestion
  routes/                routes d'un module : un fichier par module (catalogue, etablissements,
                         devises, tresorerie, compte, design), chargé par public/index.php
bin/
  creer-instance.php     création d'une instance client (base, schéma, migrations, démo, compte admin)
  migrer.php             applique les migrations en attente : php bin/migrer.php demo | --toutes
  importer-photos.php    photos libres de droits de la démo depuis Wikimedia Commons (voir docs/photos.md)
config/
  app.php                configuration commune (domaine de base, langues…)
  app.local.php          surcharge propre au serveur (non versionnée)
  instances/             une configuration par client (non versionnée)
database/
  schema.sql             tables d'une instance (version de départ)
  migrations/            évolutions du schéma : NNN_nom.sql, appliquées une seule fois et dans l'ordre
                         (table schema_migrations)
  demo.sql               données de démonstration ; demo-illustrations.sql : illustrations SVG ;
                         demo-tresorerie.sql : comptes, transferts et dépenses de démonstration
  photos-demo.json       requêtes de recherche des photos de démonstration
docs/
  design-system.md       système de design « Volcan » : principes, jetons, composants, thèmes
  photos.md              ajouter des photos ; credits-photos.md : crédits des photos importées
lang/
  fr.php, en.php         textes communs
  fr/<module>.php        textes d'un module, fusionnés après les textes communs (même chose pour en/)
public/                  racine web : index.php et fichiers statiques
  assets/css/            volcan.css (jetons et composants), vitrine.css, caisse.css, gestion.css,
                         gestion-<module>.css
  assets/js/             theme.js (jour / nuit), menu.js, caisse.js, gestion-<module>.js
  uploads/<instance>/    images envoyées depuis la gestion (produits, établissements)
views/                   gabarits PHP : layouts/, vitrine/, caisse/, gestion/<module>/, erreurs/
```

Ajouter un module : un fichier `app/routes/<module>.php` (qui reçoit `$routeur`), des traductions `lang/fr/<module>.php` et `lang/en/<module>.php` (mêmes clés), une migration `database/migrations/<numéro>_<nom>.sql` si le schéma change (puis `php bin/migrer.php --toutes`), et des vues qui reprennent les composants de `volcan.css` (voir [docs/design-system.md](docs/design-system.md)).

## Installation en local

1. Créer une instance de démonstration (MySQL ou MariaDB doit tourner) :

   ```sh
   php bin/creer-instance.php demo --email=admin@demo.test --demo --db-hote=localhost
   ```

   Le script crée la base `rbp_demo`, un utilisateur MySQL dédié, les tables, les données de démonstration et le compte administrateur. Il affiche le mot de passe de ce compte.

   Options : `--nom="Nom du client"`, `--db-hote`, `--db-port`, `--db-admin` (par défaut `root`), `--db-admin-mdp` (ou la variable `RBP_DB_ADMIN_MDP`).

2. Lancer le serveur de développement :

   ```sh
   php -S 127.0.0.1:8080 -t public public/index.php
   ```

3. Ouvrir :
   - `http://127.0.0.1:8080/` : vitrine (sans sous-domaine, l'instance `demo` est utilisée) ;
   - `http://127.0.0.1:8080/e/volcano-club?zone=vip&devise=CDF` : menu du club avec les prix VIP en francs congolais ;
   - `http://127.0.0.1:8080/gestion` : espace de gestion.

   Avec des sous-domaines en local : `http://demo.localhost:8080/`.

Pour afficher les erreurs PHP en développement, créer `config/app.local.php` :

```php
<?php return ['debug' => true];
```

## Mise en production

- Pointer `*.<domaine>` (enregistrement DNS générique) vers le serveur, avec un certificat HTTPS générique.
- Racine web : le dossier `public/`. Avec Apache, `public/.htaccess` envoie les requêtes vers `index.php`. Avec Nginx : `try_files $uri /index.php?$query_string;`.
- Renseigner `domaine_base` dans `config/app.local.php` et mettre `instance_par_defaut` à `null`.
- Créer chaque client avec `bin/creer-instance.php`.
- À chaque mise à jour du code : `php bin/migrer.php --toutes`.
- Photos envoyées depuis la gestion : jusqu'à 8 Mo si PHP le permet (`upload_max_filesize = 8M`, `post_max_size = 10M`) ; sinon la limite de PHP s'applique et elle est affichée dans les formulaires. Le dossier `public/uploads/` doit être accessible en écriture par PHP.
