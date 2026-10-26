# restau-bar-pos

Système de caisse (POS) et ERP adaptable aux restaurants-bars, lounge bars et nightclubs.
Chaque client dispose de sa propre instance sur un sous-domaine, gérée depuis une console super admin.

## Documentation

- [Cahier des charges](docs/cahier-des-charges.md)
- [Présentation du projet (PDF)](docs/restau-bar-pos-presentation.pdf)

## État d'avancement

Phase 1 en cours. Déjà disponible :

- résolution de l'instance par sous-domaine (`monbar.<domaine>` → `config/instances/monbar.php`) ;
- catalogue : catégories, produits, conditionnements, grilles de prix, devises configurables et taux ;
- **vitrine publique** de chaque établissement : présentation, horaires, état d'ouverture, menu avec les prix dans la devise choisie, prix de la zone (VIP…), recherche, français et anglais ;
- **codes QR** : `/q/<code>` compte le scan puis ouvre le menu ; création, téléchargement (SVG) et chevalet de table à imprimer depuis l'espace de gestion ;
- **écran de caisse** (`/caisse`) : produits en grille par catégorie, choix du format, ticket avec totaux dans chaque devise acceptée, adapté au type d'établissement (grandes tuiles et thème sombre pour un nightclub), utilisable sur tablette et téléphone. L'enregistrement des ventes arrive en phase 3 ;
- espace de gestion : connexion et tableau de bord.

## Technologie

- PHP 8.1 ou plus, sans framework ni dépendance à installer.
- MySQL 8 ou MariaDB 10.6+, une base par instance.
- HTML, CSS et JavaScript natifs. La seule bibliothèque (dessin des codes QR, licence MIT) est incluse dans `public/assets/vendor/`.

## Organisation du code

```
app/
  bootstrap.php          chargement des classes et de la configuration
  helpers.php            fonctions d'affichage (e(), t(), champ()…)
  Core/                  noyau : Config, Instance, Database, Router, View, I18n, Session
  Controllers/           VitrineController (pages publiques), Gestion/ (espace de gestion)
  Repositories/          accès aux données : devises et prix, établissements, catalogue
bin/creer-instance.php   création d'une instance client
config/
  app.php                configuration commune (domaine de base, langues…)
  app.local.php          surcharge propre au serveur (non versionnée)
  instances/             une configuration par client (non versionnée)
database/
  schema.sql             tables d'une instance
  demo.sql               données de démonstration
lang/                    traductions fr et en
public/                  racine web : index.php et fichiers statiques
views/                   gabarits PHP
```

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
