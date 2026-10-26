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
- **écran des modules** après la connexion (Caisse, Ventes, Achats, Stock, Personnel et paie, Finance, Rapports, Carte et vitrine, Paramètres), filtré selon les rôles ; dans un module, barre latérale sur ordinateur et barre du bas sur téléphone ;
- **rôles et permissions** vérifiés avant chaque action, connexion par téléphone ou e-mail ;
- **encaissement à la caisse** : payer en FC, en $ ou les deux ; pour chaque devise on touche directement le compte qui reçoit l'argent (Caisse du Comptoir, M-Pesa, Rawbank…), rangé sous son type ; raccourcis du panneau vers le compte principal de chaque type ; monnaie rendue depuis une caisse qui tient la devise ; reçu imprimable (80 mm) ;
- **caisse partagée** : un gérant autorise une tablette ; écran de verrouillage façon Windows (heure, message de l'administrateur, personnes avec leur photo), puis PIN de 6 chiffres ; verrouillage obligatoire après chaque encaissement et/ou après N minutes sans toucher, réglé par établissement ; blocage après 5 PIN faux ;
- **photos de profil** : par le gérant, dans « Mon compte », ou en selfie sur la caisse ;
- **écran de caisse** (`/caisse`) : produits en grille par catégorie (et sous-catégorie), choix de la taille et du format, ticket avec totaux dans chaque devise acceptée, prix de la zone, adapté au type d'établissement (grandes tuiles et thème nuit pour un nightclub ou un lounge), utilisable sur tablette et téléphone. L'enregistrement des ventes arrive en phase 3 ;
- **espace de gestion** (`/gestion`), en français et en anglais, utilisable sur téléphone :
  - connexion (limite des essais, langue préférée du compte appliquée) et tableau de bord ;
  - **catalogue** (`/gestion/catalogue`) : produits groupés par catégorie et sous-catégorie (deux niveaux) avec filtres, tailles d'un même produit (Primus Petite 33 cl, Grande 72 cl : chacune avec ses formats, prix et disponibilité) listées sous lui, « disponible / épuisé » en un toucher, ordre ; fiche produit (informations FR/EN, badges, allergènes, photo envoyée et ré-encodée avec son crédit, formats, tableau des prix formats × grilles × devises avec le calcul automatique affiché, établissements où il est vendu, désactivation) ; création sur une seule page ; catégories (`/gestion/catalogue/categories`) ;
  - **établissements** (`/gestion/etablissements`) : liste avec l'état ouvert / fermé, création, fiche en six sections (informations et liens, apparence : couleur, logo et couverture, prix et devises, horaires par service, zones avec leur grille, activation) ; **grilles de prix** (`/gestion/grilles`) ;
  - **devises et taux** (`/gestion/devises`) : devise de référence, taux du jour (avec date de début, éventuellement programmée), ajout et modification des devises (format, décimales, arrondi), activation, historique des taux ;
  - **trésorerie** (`/gestion/tresorerie`) : comptes de trois types (caisse, mobile money, banque) ; un compte tient une ou plusieurs devises, chacune avec son solde d'ouverture (« Caisse du Comptoir » a un solde en FC et un solde en $, comptés séparément ; une devise s'ajoute plus tard, et ne se retire que sans mouvement) ; comptes groupés par type avec le solde de chaque devise, totaux par devise (devise locale d'abord, dollar ensuite) et total général au taux du jour ; fiche d'un compte avec ses mouvements (colonne et filtre de devise) et le solde de la devise après chaque ligne sur une période ; **transferts** (`/gestion/tresorerie/transferts`) : compte et devise de chaque côté, change dans une même caisse (100 $ → 284 000 FC), entre deux devises avec le montant reçu proposé au taux du jour et le taux réellement appliqué enregistré ; **dépenses** (`/gestion/depenses`) : filtres (période, catégorie, établissement, type de compte), totaux par devise et équivalent en devise de référence, saisie où chaque paiement choisit le compte, une de ses devises et le montant (une dépense payée en dollars et en francs a deux paiements, depuis la même caisse ou deux comptes), catégories (`/gestion/depenses/categories`). Les soldes se calculent depuis un journal unique (`mouvements_tresorerie`) où les ventes et achats des phases suivantes écriront aussi ; un découvert (par compte et par devise) est signalé avant d'enregistrer, sans être bloqué ;
  - **ventes** (`/gestion/ventes`) : tickets encaissés, filtres (période, établissement, vendeur, type de compte, statut), totaux des ventes payées (par devise, encaissé par devise et par type de compte, équivalent en dollars), fiche avec le reçu à imprimer (80 mm), les paiements et les mouvements de trésorerie, **annulation** avec motif (contre-écriture datée de l'annulation dans chaque compte, journal) ; chacun ne voit que les ventes de ses établissements ; **rapport « ventes par vendeur »** (`/gestion/rapports/ventes`) : classement de la période, panier moyen, part, dix produits les plus vendus ;
  - **clients et dettes** (ardoises) : **clients** (`/gestion/clients`) : recherche par nom ou téléphone, filtres (type, avec une dette, en retard), encours par devise (devise locale, puis dollar), fiche (coordonnées, encours, dettes, remboursements, messages envoyés), création et modification (téléphone enregistré au format +243…, unique ; type, langue, canal préféré SMS ou WhatsApp, rappels, plafond et devise, crédit bloqué ; les clients « personnel » se créent seuls et restent en lecture seule) ; **dettes** (`/gestion/dettes`) : filtres (statut, échéance en retard ou à venir, période, établissement, responsable, servi par, devise, garant en attente), reste dû par devise, ancienneté (0–30, 31–60, plus de 60 jours), fiche (montant, reste, échéance, ticket, qui a servi, qui a accordé, garant et sa réponse, responsable, remboursements, messages) et décisions du gérant avec motif : changer le responsable, **mettre à la charge du responsable** après l'échéance (la dette devient une dette du personnel), annuler (sans remboursement) ou remettre ; **remboursements** (`/gestion/remboursements`) : liste et totaux par devise et type de compte, saisie (client, établissement, compte de l'établissement ou commun, devise, montant ; les plus anciennes dettes d'abord, au taux du jour ; un double envoi ne compte qu'une fois), fiche avec la répartition, annulation par le gérant ; **garanties** (`/gestion/garanties`) : chacun accepte ou refuse les ardoises dont il est garant (annoncées sur l'écran des modules) ; **messagerie** (`/gestion/messagerie`) : rappels automatiques (étapes, canal, responsable prévenu, heure d'envoi), dernier passage du script horaire `bin/relances.php`, message d'essai et journal des messages (les comptes SMS et WhatsApp sont ceux de la plateforme) ; **rapport des créances** (`/gestion/rapports/creances`) : total dû par devise et en dollars, ancienneté, par responsable, par serveur, par établissement, par devise, clients qui doivent le plus, garanties en attente. Chacun ne voit que les dettes de ses établissements. Les rappels partent seuls : il n'y a pas de bouton « Relancer » ;
  - **mon compte** (`/gestion/compte`) : nom, langue préférée, mot de passe ;
  - **médiathèque** (`/gestion/mediatheque`) : toutes les images, ajout par glisser-déposer, collage ou choix de fichiers, fiche (textes alternatifs, crédit, utilisations), suppression des images non utilisées ; le même champ image (glisser-déposer, collage, choix dans la médiathèque) sert pour la photo des produits, le logo et la couverture des établissements ;
  - codes QR et chevalet (`/gestion/qr`) ;
  - **utilisateurs** (`/gestion/utilisateurs`) : personnes (téléphone, e-mail, poste), rôles avec leur portée (tous les établissements ou un seul), PIN de caisse (tapé ou tiré au hasard), mot de passe de gestion, déblocage, désactivation ; rôles modifiables (cases à cocher par module) ; appareils de caisse partagée ; journal des actions sensibles ;
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
  demo-ventes.php        trois semaines de ventes de démonstration : php bin/demo-ventes.php demo [--reset]
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

### Comptes de démonstration

Créés avec `--demo` (en plus de l'administrateur, propriétaire, dont le mot de passe est affiché à la création) :

| Personne | Rôle et portée | Gestion (identifiant · mot de passe) | PIN de caisse |
|---|---|---|---|
| Patrick Kambale | Gérant, Comptoir du Lac | `patrick@demo.test` ou `+243 81 000 0101` · `Gerant-Goma-2026` | 482913 |
| Grace Mwamini | Comptable, tous | `grace@demo.test` · `Compta-Goma-2026` | — |
| Riziki Amani | Superviseure, Volcano Club | `riziki@demo.test` · `Superviseur-2026` | 662607 |
| Aline Mukeba | Serveuse, Comptoir du Lac | — | 271828 |
| Esther Kahindo | Caissière, Comptoir du Lac | — | 314159 |
| Jean-Paul Bahati | Barman, Volcano Club | — | 161803 |
| Christian Mugisho | Serveur, Volcano Club | — | 577215 |

Pour essayer la caisse partagée : se connecter comme Patrick, ouvrir la caisse, toucher son nom en haut puis « Faire de cet appareil une caisse partagée ».

## Mise en production

- Pointer `*.<domaine>` (enregistrement DNS générique) vers le serveur, avec un certificat HTTPS générique.
- Racine web : le dossier `public/`. Avec Apache, `public/.htaccess` envoie les requêtes vers `index.php`. Avec Nginx : `try_files $uri /index.php?$query_string;`.
- Renseigner `domaine_base` dans `config/app.local.php` et mettre `instance_par_defaut` à `null`.
- Créer chaque client avec `bin/creer-instance.php`.
- À chaque mise à jour du code : `php bin/migrer.php --toutes`.
- Photos envoyées depuis la gestion : jusqu'à 8 Mo si PHP le permet (`upload_max_filesize = 8M`, `post_max_size = 10M`) ; sinon la limite de PHP s'applique et elle est affichée dans les formulaires. Le dossier `public/uploads/` doit être accessible en écriture par PHP.
