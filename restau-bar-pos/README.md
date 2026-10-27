# restau-bar-pos

Système de caisse (POS) et ERP adaptable aux restaurants-bars, lounge bars et nightclubs.
Chaque client dispose de sa propre instance sur un sous-domaine, gérée depuis une console super admin.

## Documentation

- [Manuel d'utilisation](docs/manuel/index.html) (HTML avec captures ordinateur et téléphone) et sa [version PDF](docs/manuel/manuel-restau-bar-pos.pdf)
- [Cahier des charges](docs/cahier-des-charges.md)
- [Présentation du projet (PDF)](docs/restau-bar-pos-presentation.pdf)
- [Système de design « Volcan »](docs/design-system.md)
- [Photos des produits](docs/photos.md)
- [Console super admin](docs/console.md) : clients, instances, formules, abonnements, API de messagerie ; guides [Africa's Talking](docs/plateformes/africastalking.md) et [WhatsApp Business](docs/plateformes/whatsapp-business.md)

## État d'avancement

Phase 1 en cours. Déjà disponible :

- résolution de l'instance par sous-domaine (`monbar.<domaine>` → `config/instances/monbar.php`) ;
- **console super admin** (`admin.<domaine>`, [guide](docs/console.md)) : sa propre base (`config/console.php`, installation par le navigateur ou `bin/console-admin.php`) ; clients et création guidée de leur instance sur cPanel (test de la connexion, tables, compte propriétaire avec mot de passe temporaire), rattachement d'une instance existante ; formules (modules inclus, limites, prix par période, SMS compris) ; paiements notés à la main qui prolongent l'échéance ; statuts calculés (essai, à relancer, grâce, lecture seule, suspendu) ou décidés à la main, appliqués dans chaque bar à la prochaine ouverture de la caisse, jamais en plein service ; relances d'abonnement par SMS et e-mail (`bin/console-relances.php`) ; API de messagerie communes (Africa's Talking, WhatsApp Cloud API), sender ID par client et SMS du mois ; mises à jour (migrations), sauvegardes SQL, assistance avec l'accord du propriétaire (Paramètres > Assistance) ; tableau de bord (revenu mensuel récurrent, encaissé, retards, essais) ;
- **vitrine publique** de chaque établissement : présentation, horaires, état d'ouverture, menu avec les prix dans la devise choisie, prix de la zone (VIP…), photos des produits avec leur crédit, recherche, français et anglais, thème jour ou nuit selon le type d'établissement ;
- **codes QR** : `/q/<code>` compte le scan puis ouvre le menu (avec la zone) ; création, téléchargement (SVG) et chevalet de table à imprimer depuis l'espace de gestion ;
- **écran des modules** après la connexion (Caisse, Ventes, Achats, Stock, Personnel et paie, Finance, Rapports, Carte et vitrine, Paramètres), filtré selon les rôles ; dans un module, barre latérale sur ordinateur et barre du bas sur téléphone ;
- **rôles et permissions** vérifiés avant chaque action, connexion par téléphone ou e-mail ;
- **encaissement à la caisse** : payer en FC, en $ ou les deux ; pour chaque devise on touche directement le compte qui reçoit l'argent (Caisse du Comptoir, M-Pesa, Rawbank…), rangé sous son type ; raccourcis du panneau vers le compte principal de chaque type ; monnaie rendue depuis une caisse qui tient la devise ; reçu imprimable (80 mm) ;
- **caisse partagée** : un gérant autorise une tablette ; écran de verrouillage façon Windows (heure, message de l'administrateur, personnes avec leur photo), puis PIN de 6 chiffres ; verrouillage obligatoire après chaque encaissement et/ou après N minutes sans toucher, réglé par établissement ; blocage après 5 PIN faux ;
- **photos de profil** : par le gérant, dans « Mon compte », ou en selfie sur la caisse ;
- **écran de caisse** (`/caisse`) : produits en grille par catégorie (et sous-catégorie), choix de la taille et du format, ticket avec totaux dans chaque devise acceptée, prix de la zone, adapté au type d'établissement (grandes tuiles et thème nuit pour un nightclub ou un lounge), utilisable sur tablette et téléphone. L'enregistrement des ventes arrive en phase 3 ;
- **salle et cuisine à la caisse** : bouton « Salle » (plan de chaque zone, tables libres, occupées avec le montant et la durée de leur note, addition demandée, réservées ; notes nommées comme « Bar — Patrick ») ; un ticket s'**envoie à une table sans être payé** (note ouverte, `/caisse/notes/*`) ; plusieurs serveurs ajoutent à la même note (chaque ligne garde qui l'a commandée) ; précisions par ligne (cuisson, « sans piment »), **allergies** en évidence, **envoi en plusieurs temps** (« à lancer plus tard », puis « Lancer la suite ») ; « Addition » imprime la pré-note (80 mm) ; « Encaisser » ouvre la même fenêtre de paiement (devises, comptes, ardoise, monnaie, clé unique) et crée la vente depuis les lignes de la note, prix recalculés avec la grille du moment de la commande (happy hour, VIP) ; **partage de l'addition** par articles ou en parts égales (plusieurs ventes, quantités en fractions : ½ Tilapia) ; transfert et fusion de notes ; annuler une ligne déjà lancée demande le PIN d'un supérieur (« caisse.annuler », le journal garde les deux noms) et compte comme une perte dans le stock ; un serveur sans « encaisser » ouvre des notes, envoie et demande l'addition, un caissier encaisse ; « Encaisser » reste direct au comptoir (mode rapide). Les plats partent au **poste** de leur catégorie (routage de la cuisine, sinon le comptoir du stock), y compris pour une vente directe ; **écran de cuisine** (`/caisse/cuisine`, un poste ou tous : reçu → en préparation → prêt → servi, temps écoulé et retard, plats à suivre, son et impression automatique du bon de 80 mm, **ruptures** qui grisent le plat sur toutes les caisses en quelques secondes) ouvert par une personne connectée (« cuisine.voir ») ou sur un **écran autorisé** par un gérant comme une caisse partagée ; le serveur est prévenu quand c'est prêt. La caisse suit le **service en cours** (grille de prix, catégories vendues, modules, mode rapide) et le **happy hour** ; en mode stock « bloquer », les formats épuisés au comptoir sont grisés et refusés par le serveur ;
- **espace de gestion** (`/gestion`), en français et en anglais, utilisable sur téléphone :
  - connexion (limite des essais, langue préférée du compte appliquée) et tableau de bord ;
  - **catalogue** (`/gestion/catalogue`) : produits groupés par catégorie et sous-catégorie (deux niveaux) avec filtres, tailles d'un même produit (Primus Petite 33 cl, Grande 72 cl : chacune avec ses formats, prix et disponibilité) listées sous lui, « disponible / épuisé » en un toucher, ordre ; fiche produit (informations FR/EN, badges, allergènes, photo envoyée et ré-encodée avec son crédit, formats, tableau des prix formats × grilles × devises avec le calcul automatique affiché, établissements où il est vendu, désactivation) ; création sur une seule page ; catégories (`/gestion/catalogue/categories`) ;
  - **établissements** (`/gestion/etablissements`) : liste avec l'état ouvert / fermé, création, fiche en six sections (informations et liens, apparence : couleur, logo et couverture, prix et devises, horaires par service, zones avec leur grille, activation) ; **grilles de prix** (`/gestion/grilles`) ;
  - **devises et taux** (`/gestion/devises`) : devise de référence, taux du jour (avec date de début, éventuellement programmée), ajout et modification des devises (format, décimales, arrondi), activation, historique des taux ;
  - **trésorerie** (`/gestion/tresorerie`) : comptes de trois types (caisse, mobile money, banque) ; un compte tient une ou plusieurs devises, chacune avec son solde d'ouverture (« Caisse du Comptoir » a un solde en FC et un solde en $, comptés séparément ; une devise s'ajoute plus tard, et ne se retire que sans mouvement) ; comptes groupés par type avec le solde de chaque devise, totaux par devise (devise locale d'abord, dollar ensuite) et total général au taux du jour ; fiche d'un compte avec ses mouvements (colonne et filtre de devise) et le solde de la devise après chaque ligne sur une période ; **transferts** (`/gestion/tresorerie/transferts`) : compte et devise de chaque côté, change dans une même caisse (100 $ → 284 000 FC), entre deux devises avec le montant reçu proposé au taux du jour et le taux réellement appliqué enregistré ; **dépenses** (`/gestion/depenses`) : filtres (période, catégorie, établissement, type de compte), totaux par devise et équivalent en devise de référence, saisie où chaque paiement choisit le compte, une de ses devises et le montant (une dépense payée en dollars et en francs a deux paiements, depuis la même caisse ou deux comptes), catégories (`/gestion/depenses/categories`). Les soldes se calculent depuis un journal unique (`mouvements_tresorerie`) où les ventes et achats des phases suivantes écriront aussi ; un découvert (par compte et par devise) est signalé avant d'enregistrer, sans être bloqué ;
  - **ventes** (`/gestion/ventes`) : tickets encaissés, filtres (période, établissement, vendeur, type de compte, statut), totaux des ventes payées (par devise, encaissé par devise et par type de compte, équivalent en dollars), fiche avec le reçu à imprimer (80 mm), les paiements et les mouvements de trésorerie, **annulation** avec motif (contre-écriture datée de l'annulation dans chaque compte, journal) ; chacun ne voit que les ventes de ses établissements ; **rapport « ventes par vendeur »** (`/gestion/rapports/ventes`) : classement de la période, panier moyen, part, dix produits les plus vendus ;
  - **clients et dettes** (ardoises) : **clients** (`/gestion/clients`) : recherche par nom ou téléphone, filtres (type, avec une dette, en retard), encours par devise (devise locale, puis dollar), fiche (coordonnées, encours, dettes, remboursements, messages envoyés), création et modification (téléphone enregistré au format +243…, unique ; type, langue, canal préféré SMS ou WhatsApp, rappels, plafond et devise, crédit bloqué ; les clients « personnel » se créent seuls et restent en lecture seule) ; **dettes** (`/gestion/dettes`) : filtres (statut, échéance en retard ou à venir, période, établissement, responsable, servi par, devise, garant en attente), reste dû par devise, ancienneté (0–30, 31–60, plus de 60 jours), fiche (montant, reste, échéance, ticket, qui a servi, qui a accordé, garant et sa réponse, responsable, remboursements, messages) et décisions du gérant avec motif : changer le responsable, **mettre à la charge du responsable** après l'échéance (la dette devient une dette du personnel), annuler (sans remboursement) ou remettre ; **remboursements** (`/gestion/remboursements`) : liste et totaux par devise et type de compte, saisie (client, établissement, compte de l'établissement ou commun, devise, montant ; les plus anciennes dettes d'abord, au taux du jour ; un double envoi ne compte qu'une fois), fiche avec la répartition, annulation par le gérant ; **garanties** (`/gestion/garanties`) : chacun accepte ou refuse les ardoises dont il est garant (annoncées sur l'écran des modules) ; **messagerie** (`/gestion/messagerie`) : rappels automatiques (étapes, canal, responsable prévenu, heure d'envoi), dernier passage du script horaire `bin/relances.php`, message d'essai et journal des messages (les comptes SMS et WhatsApp sont ceux de la plateforme) ; **rapport des créances** (`/gestion/rapports/creances`) : total dû par devise et en dollars, ancienneté, par responsable, par serveur, par établissement, par devise, clients qui doivent le plus, garanties en attente. Chacun ne voit que les dettes de ses établissements. Les rappels partent seuls : il n'y a pas de bouton « Relancer » ;
  - **stock** (`/gestion/stock`) : **dépôts et comptoirs** par établissement (réserve, chambre froide ; bar, bar VIP, cuisine…) et dépôts centraux, comptoir par défaut (« Bar » créé pour chaque établissement), **routage des ventes** (la catégorie d'abord : Plats → Cuisine ; puis l'appareil de caisse ; puis la zone : Carré VIP → Bar VIP) ; stock de chaque produit dans chaque lieu, compté dans son unité de base et affiché en formats lisibles (« 9 casiers + 7 bouteilles », « 3 bouteilles 70 cl + 12 cl »), valeur au coût moyen pondéré, seuils d'alerte et stock visé, **suggestions** (transfert depuis le dépôt pour un comptoir, bon de commande au fournisseur habituel pour un dépôt) ; **chaque vente fait baisser le comptoir qui la sert** (quantité × contenu du format ; un cocktail ou un plat fait sortir les ingrédients de sa **fiche technique**, avec la part utilisable de chaque ingrédient ; une bouteille consignée rend son vide au comptoir), l'annulation d'une vente remet tout en stock, et une vente n'est jamais bloquée par le stock ; **stock négatif** au choix de l'établissement : vendre quand même avec une alerte, ou bloquer (`StockRepository::epuises()` donne les formats épuisés à la caisse) ; **journal des mouvements** (qui, quand, pourquoi) ; **transferts** validés des deux côtés (sortie confirmée par l'expéditeur, réception avec la quantité reçue et l'écart expliqué) ; **inventaires** complets, de fin de service ou par catégorie, comptés à l'aveugle dans n'importe quel format (bouteille entamée en fraction ou en cl), écarts détaillés (début + entrées − ventes − pertes ± autres = attendu), validation de préférence par une autre personne, qui corrige le stock ; **pertes** avec motif ; **fiches techniques** et coûts de revient, marges par format (droit `rapports.marges`) ; **emballages consignés** (casiers et bouteilles vides avec leur consigne, formats qui les apportent, soldes dus par fournisseur et par client) ; **préparations intermédiaires** (sauce, marinade, bouillon : fiche « préparation » produite à l'avance dans **Productions**, les ingrédients sortent, la préparation entre en stock avec son coût et sa péremption ; les fiches des plats l'utilisent, un seul niveau) ; **lots datés** (réceptions, achats au marché, productions, transferts) : les sorties prennent d'abord ce qui périme le plus tôt, écran **Péremptions** (périmés à déclarer en perte d'un clic, ceux qui périment dans N jours, alerte dans l'état du stock) ; **caisse de la cuisine** (compte de trésorerie de type caisse remis au chef, choisi dans les réglages de l'établissement) et **achats au marché** (`/gestion/achats/marche`, droit `achats.marche` : photo obligatoire, payés depuis ce fonds, les prix payés mettent à jour le coût moyen) ; **vente en gros au dépôt** (`/gestion/gros`, droit `ventes.gros` : dépôt qui vend en gros, grille « Prix de gros » par casier, clients revendeurs, vente enregistrée comme à la caisse avec paiements et ardoise, emballages prêtés ou consigne facturée, vides rapportés) ; rapport **Coûts et rentabilité** (`/gestion/rapports/couts` : coût matière et marge par plat, écarts entre consommation attendue et réelle, CSV et impression) ;
  - **achats** (`/gestion/achats/receptions`) : **fournisseurs** (brasserie, distributeur, grossiste, marché ; délai de paiement), **bons de commande** (brouillon, envoyé, reçu en partie ou en entier, texte à envoyer par SMS ou WhatsApp), **réceptions** dans un dépôt ou un comptoir, dans les formats d'achat, depuis une commande (écarts commandé / reçu) ou libres, achats au marché sans facture avec la photo du ticket, vides rendus et consigne payée, coût moyen pondéré mis à jour au taux du jour ; **paiements** par la trésorerie comme une dépense (compte + devise, plusieurs lignes, facture en dollars payée en francs, découvert signalé, trop-payé refusé ; mouvement de nature « achat ») ; **dettes fournisseurs** avec échéances et retards ; **historique des prix d'achat** par produit et fournisseur ;
  - **salle** (`/gestion/salle`, module Salle) : **plan de salle** au glisser-déposer (tables par zone sur une grille de 24 × 16 cases, places, forme ; formulaire de chaque table sans JavaScript), **notes** en cours et de la journée d'exploitation avec leur détail (qui a commandé, cuisine, annulations, ventes) et les temps de préparation, **réservations** du jour (nom, téléphone, personnes, heure, table, notes ; arrivée, absence, annulation), **services horaires** (pour chaque service des horaires : grille de prix, catégories vendues, modules ; happy hour = grille sur une plage horaire ; heure de fin de la journée d'exploitation, utilisée par les rapports via `App\Core\Journee`) ; **cuisine** (`/gestion/cuisine`, module Cuisine) : postes, routage des catégories, écrans autorisés, temps de préparation ;
  - **tableau de bord de la direction** (`/gestion/tableau-de-bord`, Rapports) : sur une période de journées d'exploitation (une vente à 2 h compte pour la veille, selon l'heure de fin de journée de l'établissement) et un établissement ou tous — chiffre d'affaires (devise locale, puis dollar), encaissé et part à crédit, marge brute (droit « Voir les marges », si le stock connaît les coûts d'achat), dépenses et salaires, résultat, comparés à la période précédente ; CA jour par jour (graphique SVG, encaissé et à crédit, données dans « Voir les données »), par service (horaires) et par établissement ; produits les plus rentables et les moins vendus ; créances clients par ancienneté et dettes fournisseurs ; **serveuse ou serveur de la semaine** et son écart avec la semaine d'avant ; présence du personnel ;
  - **rapport du personnel** (`/gestion/rapports/personnel`) : classement de toutes les personnes qui ont vendu (critère : CA encaissé, tickets ou panier moyen ; option « une vente à crédit ne compte qu'une fois remboursée » ; vente attribuée à qui a servi la table ou à qui a encaissé), avec CA, tickets, panier moyen, articles, ventes à crédit et crédits accordés, annulations, écart et rang de la période précédente, évolution jour par jour ; dettes des clients par responsable (reste dû, clients, retard, ancienneté, remboursé sur la période, détail de chaque dette ; filtres devise et « en retard seulement ») et dettes du personnel à part ; présence (module Personnel et paie). Chacun voit ses propres résultats ; le classement complet demande « Voir les rapports » ;
  - **événements** (`/gestion/evenements`, module Événements, montré s'il y a un lounge ou un nightclub) : soirées (date et heures, thème, DJ et artistes, budget) en calendrier, fiche avec les recettes de la nuit (ventes de l'établissement de l'ouverture à la fermeture : par heure, par catégorie, produits), les coûts (dépenses de Finance rattachées à la soirée, avec leur compte payeur et leur devise ; champ « Soirée » dans le formulaire d'une dépense), le coût des marchandises si connu, le résultat et la part du budget ; **rentabilité** (`/gestion/evenements/rentabilite`) des soirées passées ; droits « evenements.voir » et « evenements.gerer » ;
  - **exports** : chaque rapport (tickets, ventes par vendeur, créances, comptes et mouvements de trésorerie, dépenses, tableau de bord, personnel, soirées) a un groupe « Exporter » : **CSV** (UTF-8 avec BOM, séparateur « ; », montants en nombres simples avec une colonne de devise, mêmes filtres que l'écran) et **Imprimer / PDF** (feuille d'impression A4 sans navigation ; « Enregistrer au format PDF » du navigateur ; `?imprimer=1` sans JavaScript) ; **export comptable** (`/gestion/rapports/export-comptable`, droit « Voir la trésorerie ») : journaux de trésorerie, des ventes et des dépenses d'une période, en CSV ;
  - **personnel et paie** (`/gestion/personnel/*`, module Personnel et paie) : **employés** avec ou sans compte (agent de sécurité, hôtesse), poste, établissements, contrat (CDI, CDD, journalier), salaire en dollars, en francs ou les deux (150 $ + 100 000 FC) par mois, semaine ou soirée, commission sur ses ventes payées, pourboires, documents gardés hors de la racine web (`storage/documents/`), fiche avec la présence du mois, les bulletins, les avances, les dettes du personnel et les manquants de caisse ; **pointage par code QR** : une tablette autorisée par un gérant (Présence > Bornes, cookie comme une caisse partagée) affiche à l'entrée un code QR signé qui change toutes les 30 s (`/pointage/borne`) ; l'employé le scanne, s'identifie la première fois avec son téléphone et son PIN (le téléphone est ensuite reconnu, un seul par employé) et pointe son arrivée ou son départ (`/pointer`) avec sa **position GPS**, refusée au-delà du rayon de l'établissement (100 m par défaut, position réglée dans Présence > Réglages) ; un code expiré ou déjà servi est refusé ; pour dépointer, une caissière clôture d'abord sa caisse ; **pointage de secours** à la caisse (fenêtre de la personne : PIN de l'employé validé par le PIN d'un gérant) ; « **pointage obligatoire pour ouvrir la caisse** » au choix de chaque établissement (encaisser et ouvrir sa session sont refusés à un employé non pointé) ; tableau du jour, pointages et **corrections** avec motif (journal) ; **plannings** de la semaine par service et poste, comparés aux pointages (retards, absences, départs anticipés, heures sup), copie de la semaine précédente, **jours fériés** de la RDC modifiables ; **congés** et justifications d'absence validés par le gérant ; **paie** : bulletins du mois ou de la semaine (base, heures sup, heures un jour férié, commission, primes, pourboires répartis au prorata des heures ; moins les avances, les absences non justifiées et les **retenues proposées** — dettes du personnel et manquants de caisse — que le gérant réduit ou étale avant de valider ; la retenue d'une dette la réduit sans mouvement de trésorerie, un manquant retenu n'est plus proposé), paiement depuis la trésorerie (compte et devise, plusieurs lignes, découvert signalé ; mouvements « salaire »), bulletin A4 imprimable, annulation, **masse salariale** par établissement et par mois ; **avances** payées depuis un compte (mouvements « avance »), déduites d'office du bulletin suivant ; **rapport de présence** (`/gestion/rapports/presence`, CSV) ; démonstration : `php bin/demo-personnel.php demo` ;
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
                         Migrations, Images (contrôle et ré-encodage des images envoyées),
                         Plateforme (abonnement, modules, limites et messagerie décidés par la console),
                         BaseConsole, Installation (installation d'une instance)
  console.php            point d'entrée de la console super admin (admin.<domaine>)
  Console/               console : abonnements, clients, formules, instances, relances, sauvegardes
  Controllers/           VitrineController (pages publiques), CaisseController, Gestion/ (espace de gestion),
                         Console/ (écrans de la console ; routes dans routes/console/)
  Repositories/          accès aux données : devises et prix, établissements, catalogue,
                         Gestion*Repository pour les écrans de gestion
  routes/                routes d'un module : un fichier par module (catalogue, etablissements,
                         devises, tresorerie, compte, design), chargé par public/index.php
bin/
  creer-instance.php     création d'une instance client (base, schéma, migrations, démo, compte admin ; App\Core\Installation)
  console-admin.php      console super admin : installer | migrer | admin --email=… | desactiver --email=…
  console-relances.php   relances d'abonnement au propriétaire de chaque bar (cron, chaque heure)
  migrer.php             applique les migrations en attente : php bin/migrer.php demo | --toutes
  demo-ventes.php        trois semaines de ventes de démonstration : php bin/demo-ventes.php demo [--reset]
  demo-personnel.php     employés, plannings, pointages, avance et bulletins de démonstration (idempotent)
  demo-evenements.php    soirées de démonstration du Volcano Club (passées avec leurs coûts, une à venir) : php bin/demo-evenements.php demo
  demo-salle.php         plan de salle, postes, services, notes ouvertes et réservations de ce soir : php bin/demo-salle.php demo
  demo-stock.php         stock et achats de démonstration (lieux, fournisseurs, réceptions, transferts, inventaires),
                         et sorties de stock des ventes existantes : php bin/demo-stock.php demo [--rejouer-ventes] [--reset]
  demo-stock-suite.php   suite du stock de démonstration (préparations et productions, caisse de la cuisine et achats
                         au marché, vente en gros, Volcano Club en « bloquer ») : php bin/demo-stock-suite.php demo [--effacer]
  importer-photos.php    photos libres de droits de la démo depuis Wikimedia Commons (voir docs/photos.md)
config/
  app.php                configuration commune (domaine de base, langues…)
  app.local.php          surcharge propre au serveur (non versionnée)
  instances/             une configuration par client (non versionnée)
  console.php            base de la console super admin (non versionné ; modèle console.php.dist)
database/
  schema.sql             tables d'une instance (version de départ)
  migrations/            évolutions du schéma : NNN_nom.sql, appliquées une seule fois et dans l'ordre
                         (table schema_migrations)
  console/               base de la console super admin : schema.sql et migrations/
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
- Console super admin sur `admin.<domaine>` (même racine `public/`) : voir [docs/console.md](docs/console.md). Créer chaque client depuis la console (création guidée pour cPanel), ou avec `bin/creer-instance.php` puis « Rattacher ».
- À chaque mise à jour du code : « Mettre à jour toutes les instances » dans la console, ou `php bin/migrer.php --toutes`.
- Tâches cron (chaque heure) : `bin/relances.php --toutes` et `bin/console-relances.php` (voir docs/console.md, section 13).
- Photos envoyées depuis la gestion : jusqu'à 8 Mo si PHP le permet (`upload_max_filesize = 8M`, `post_max_size = 10M`) ; sinon la limite de PHP s'applique et elle est affichée dans les formulaires. Le dossier `public/uploads/` doit être accessible en écriture par PHP.

## Mise en ligne sur LWS (mutualisé cPanel)

- Guide pas à pas, sans SSH : [docs/installation-lws.md](docs/installation-lws.md) (dépôt du code, console super admin, création des clients, tâches cron, SMS et WhatsApp, mises à jour).
- Archive prête à déposer : `php bin/paquet-lws.php [--demo=rbp_demo]` → `restau-bar-pos-lws-AAAAMMJJ.zip` (le code du dernier commit et `installation/` : base vide d'une instance, base vide de la console, démonstration en option).
