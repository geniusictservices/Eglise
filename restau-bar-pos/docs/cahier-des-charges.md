# Cahier des charges — restau-bar-pos

Système de caisse (POS) et ERP adaptable à trois contextes : **restaurant-bar**, **lounge bar** et **nightclub**.
Principe : un **socle commun** toujours actif + des **modules** activés selon le type d'établissement et l'heure.

> Les points marqués **⚠️ Hypothèse** n'ont pas encore été confirmés. Ils sont listés à la fin (section 18).

---

## 1. Architecture et déploiement

### 1.1 Une instance par client, sur un sous-domaine

Ce n'est pas un SaaS partagé : **chaque client a sa propre instance**, accessible par son **sous-domaine**.

```
admin.<domaine>            → Console super admin (clients, abonnements, instances)
monbar.<domaine>           → Instance du client « Mon Bar »
leclub.<domaine>           → Instance du client « Le Club »
...
```

- Chaque instance a **sa propre base de données** : les données des clients sont totalement séparées.
- **Sauvegardes, restauration et mises à jour par instance**.
- Le même code tourne partout. Les mises à jour sont déployées sur toutes les instances depuis la console.
- ⚠️ Hypothèse : toutes les instances sont hébergées sur vos serveurs. Une installation sur un serveur chez le client reste possible plus tard.

### 1.2 Console super admin

- **Clients** : créer un client, lui attribuer son sous-domaine, créer son compte propriétaire.
- **Instances** : créer, mettre à jour, sauvegarder, suspendre ou réactiver une instance.
- **Formules d'abonnement** : modules inclus (cuisine, nightclub, ERP, RH…), nombre d'établissements, de points de vente et d'utilisateurs, durée (mois, trimestre, année).
- **Facturation** : factures d'abonnement, paiements (mobile money, virement, espèces), relances avant l'échéance, essai gratuit, remises.
- **Retards de paiement** :
  1. relances ;
  2. délai de grâce ;
  3. accès en lecture seule (on voit les données, on ne vend plus) ;
  4. suspension.

  On ne coupe **jamais** une caisse en plein service : le blocage s'applique à la prochaine ouverture de caisse. Les données ne sont jamais supprimées.
- **Licence** : chaque instance vérifie régulièrement auprès de la console son abonnement et les modules autorisés. Ce qui n'est pas inclus est masqué.
- **Statistiques** : nombre de clients, revenus des abonnements, clients en retard, utilisation des instances.
- **Assistance** : accès à l'instance d'un client **uniquement avec son accord**, avec un historique.
- **Confidentialité** : le super admin ne voit pas les données commerciales des clients (ventes, clients, dettes), seulement ce qui concerne l'abonnement.

### 1.3 Technologie

- **PHP 8.3 natif**, sans framework : un petit noyau maison (routeur, accès base de données, vues, traductions).
- **MySQL ou MariaDB**, une base par instance, accès par PDO avec requêtes préparées.
- **HTML, CSS et JavaScript natifs** pour l'interface, sans outil de compilation.
- Pas de dépendance externe obligatoire : le code se dépose sur un hébergement PHP classique.
- Caisse hors ligne (plus tard) : application web installable (PWA) avec stockage local et synchronisation.

## 2. Organisation des lieux

```
CLIENT (instance)
  └── ÉTABLISSEMENT (restau-bar, lounge, club)
        ├── DÉPÔTS       (réserve, économat, chambre froide)
        ├── COMPTOIRS    (bar principal, bar VIP, cuisine, chicha…)
        └── POINTS DE VENTE (caisses, tablettes, téléphones)
```

Circuit de la marchandise :

```
Fournisseur ──► DÉPÔT ──(transfert)──► COMPTOIR ──► POINT DE VENTE ──► Client
```

### 2.1 Dépôt
- Lieu de **stockage** en grandes quantités (casiers, cartons, fûts, sacs).
- **Reçoit les livraisons** et **approvisionne les comptoirs** par des transferts.
- Possibilité d'un **dépôt central** qui approvisionne plusieurs établissements.
- Responsable : le **magasinier**.
- ⚠️ Hypothèse : un dépôt peut aussi **vendre en gros** (au casier) grâce à un point de vente rattaché, avec des prix de gros, des clients revendeurs et des emballages consignés. Option activable.

### 2.2 Comptoir
- Lieu de **préparation et de service**, avec **son propre stock**.
- **Chaque vente fait baisser le stock du comptoir**.
- **Inventaire à chaque service** : stock du début + transferts reçus − ventes = stock attendu. Le responsable justifie les écarts.
- Un transfert se valide **des deux côtés** : le magasinier confirme la sortie, le responsable du comptoir confirme la réception.

### 2.3 Point de vente
- **Appareil** où l'on enregistre et encaisse. Il n'a **pas de stock**.
- **Rattaché à un ou plusieurs comptoirs**, ce qui détermine :
  - quel stock faire baisser ;
  - où envoyer les commandes (boissons au bar, plats en cuisine) ;
  - quelle grille de prix appliquer (normal, VIP, nuit, gros).
- Exemples : tablette d'un serveur en salle, caisse du carré VIP, caisse de l'entrée du club (sans stock).

## 3. Socle commun

- **Catalogue** : produits, catégories et sous-catégories, conditionnements et tailles (section 9), prix par grille, taxes, options (« sans glaçons », cuisson…).
  - **Sous-catégories sur deux niveaux au plus** (catégorie > sous-catégorie : Bières > Locales, Importées ; Spiritueux > Whisky, Cognac). Un produit est rangé dans une catégorie ou dans une sous-catégorie. On ne peut ni créer un troisième niveau, ni ranger une catégorie dans une de ses sous-catégories, ni supprimer une catégorie qui a des sous-catégories ou des produits actifs. L'ordre se règle à l'intérieur de chaque niveau.
  - Sur la vitrine, les sous-catégories sont des intertitres dans leur catégorie (la barre des catégories reste au premier niveau) ; à la caisse, les onglets sont les catégories principales et une seconde rangée de puces filtre par sous-catégorie (« Toutes », « Locales », « Importées »).
- **Commandes et encaissement** : espèces, carte, mobile money, paiements en plusieurs devises (section 10).
- **Utilisateurs, rôles et permissions** (section 6).
- **Sessions de caisse** : ouverture, clôture, fond de caisse et écarts par devise.
- **Stock** par dépôt et par comptoir.
- **Clients et dettes** (section 7).
- **Rapports** : ventes, produits les plus vendus, ventes par employé, par période.
- **Langues** français et anglais (section 11).

## 4. Modules selon le contexte

| Module | Restau-bar | Lounge | Nightclub |
|---|:-:|:-:|:-:|
| Plan de salle et tables | ✅ | ✅ | ✅ (zones VIP) |
| Notes ouvertes (on paie à la fin) | ✅ | ✅ | ✅ |
| Partage de l'addition | ✅ | ✅ | ➖ |
| Cuisine complète (section 8) | ✅ | ➖ | ❌ |
| Réservations | ✅ | ✅ | ✅ |
| Bouteilles avec softs, formules | ➖ | ✅ | ✅ |
| Minimum de consommation (VIP) | ❌ | ➖ | ✅ |
| Prix selon l'heure (happy hour) | ✅ | ✅ | ✅ |
| Entrées et billetterie | ❌ | ➖ | ✅ |
| Liste d'invités et promoteurs (commissions) | ❌ | ➖ | ✅ |
| Vestiaire (tickets) | ❌ | ➖ | ✅ |
| Cashless (bracelet, carte prépayée, QR) | ❌ | ➖ | ✅ |
| Mode comptoir rapide (gros boutons, un seul clic pour vendre) | ➖ | ➖ | ✅ |
| Fidélité (points, remises) | ➖ | ✅ | ➖ |
| Vente en gros au dépôt | ➖ | ➖ | ➖ |

✅ = activé par défaut · ➖ = optionnel · ❌ = désactivé par défaut

Les modules disponibles dépendent aussi de la formule d'abonnement (section 1.2).

## 5. Services horaires

Un même lieu peut changer de mode dans la journée. Exemple :

| Service | Horaires | Modules principaux |
|---|---|---|
| Restaurant | 12h – 15h | tables, cuisine, partage de l'addition |
| Lounge | 18h – 23h | bouteilles, happy hour |
| Club | 23h – 5h | entrées, mode comptoir rapide, cashless, carte de prix « nuit » |

- Chaque service a ses propres prix, modules et postes. Quand la cuisine ferme, ses plats disparaissent des points de vente.
- **Journée d'exploitation** : un service qui passe minuit compte pour la journée où il a commencé. L'heure de fin de journée se règle (par exemple 7h).
- Tout reste dans **une seule base de données par client**, avec des rapports consolidés.

## 6. Rôles et permissions

### 6.1 Principe
Un rôle donne :
- des **permissions** : ce que la personne a le droit de faire ;
- une **portée** : où elle a ce droit (établissement, dépôt, comptoir, point de vente).

Les rôles ci-dessous sont des modèles. Le propriétaire peut les modifier ou en créer d'autres.

| Rôle | Ce qu'il peut faire | Portée habituelle |
|---|---|---|
| **Propriétaire / Direction** | Tout, y compris les rapports financiers et la configuration | Tous les établissements |
| **Gérant** | Valider les annulations, remises et crédits, clôturer les caisses, fixer les taux de change, gérer le personnel | Son établissement |
| **Superviseur / chef de service** | Surveiller un service, accorder de petites remises dans une limite | Un service, des comptoirs |
| **Caissier** | Ouvrir et fermer la session de caisse, encaisser, recevoir les remboursements de dettes | Un point de vente |
| **Serveur** | Prendre les commandes sur ses tables, éventuellement encaisser | Ses tables, ses points de vente |
| **Barman** | Vendre au comptoir, préparer, recevoir les transferts, faire l'inventaire du comptoir | Un comptoir |
| **Chef de cuisine** | Fiches techniques, ruptures, commandes, inventaires, pertes, coûts matière | La cuisine |
| **Cuisinier** | Voir les commandes de son poste, indiquer qu'un plat est prêt | Un poste de cuisine |
| **Acheteur / économe** | Achats au marché, réceptions, caisse de dépenses de la cuisine | La cuisine, l'économat |
| **Magasinier** | Recevoir les livraisons, faire les transferts, faire l'inventaire du dépôt | Un dépôt |
| **Comptable** | Dépenses, trésorerie, rapports, aucun accès à la caisse | Un ou plusieurs établissements |
| **RH** | Employés, plannings, présence, salaires, avances | Un ou plusieurs établissements |
| **Agent d'entrée** (club) | Billetterie, liste d'invités | L'entrée |
| **Vestiaire** (club) | Tickets de vestiaire | Le vestiaire |
| **Promoteur** | Voir ses invités et ses commissions | Ses soirées |

Le **super admin** n'est pas un rôle de l'instance : il n'existe que dans la console (section 1.2).

### 6.2 Règles
- **Permissions fines** : « annuler un article déjà envoyé », « remise de plus de 10 % », « accorder un crédit », « voir les marges », « modifier les prix », « modifier les taux »…
- **Limites chiffrées** : remise maximale en %, crédit maximal qu'un employé peut accorder.
- **Validation par un supérieur** : le gérant tape son PIN sur l'appareil de l'employé. L'historique garde le nom des deux personnes.
- **Plusieurs rôles par personne**.
- **Séparation des tâches** : la personne qui reçoit la marchandise n'est pas la seule à valider l'inventaire.
- **Changement rapide d'utilisateur** sur une tablette partagée, avec le PIN.
- **Historique complet** : qui a fait quoi, quand, sur quel appareil.

### 6.3 Connexion et caisse partagée (décidé)
- **Gestion** : connexion avec le **numéro de téléphone ou l'e-mail** et un mot de passe (10 caractères au moins). Blocage de 5 minutes après 5 essais, par compte. Seules les personnes qui ont un module de gestion reçoivent un mot de passe.
- **Caisse** : **PIN de 6 chiffres**. Un PIN trop simple (000000, 123456…) est refusé, et deux personnes actives n'ont jamais le même PIN. Après 5 PIN faux, le PIN est bloqué ; le gérant le débloque.
- **Caisse partagée** : seul un **appareil autorisé par un gérant** accepte les PIN (tablette du bar…). Sur cet appareil, on touche son nom puis on tape son PIN. Un appareil perdu se retire dans Paramètres > Utilisateurs > Appareils.
- **Écran de verrouillage façon Windows** : fond de l'établissement (sa couverture), grande heure et date, message choisi par l'administrateur, personnes en bas à gauche avec leur photo ; une personne touchée → sa grande photo au centre et le pavé du PIN. Sur téléphone, les personnes sont en grille et le PIN en plein écran.
- **Verrouillage choisi par l'administrateur, pour chaque établissement** (Paramètres > Utilisateurs > Caisses partagées) : **obligatoire après chaque encaissement** (proposé par défaut), et/ou **après 1 à 30 minutes sans toucher l'écran** (avertissement 10 secondes avant). Le bouton « Verrouiller » le fait à tout moment. Chacun garde son propre ticket en cours.
- **Photo de profil** : ajoutée par le gérant sur la fiche de la personne, par la personne elle-même dans « Mon compte », ou prise sur la caisse (selfie) si l'établissement le permet. Sans photo, les initiales sur une couleur propre à chacun.
- **Quitter la caisse** : seules les personnes qui ont des modules de gestion voient « Revenir aux modules », et la gestion leur demande leur mot de passe. Un serveur ne voit que la caisse.
- **Pas d'élévation de droits** : on ne donne que les droits qu'on a soi-même, pour les établissements où on les a. On ne modifie que les personnes dont on pourrait donner tous les rôles. Seul un propriétaire modifie un propriétaire, et il reste toujours au moins un propriétaire actif.

## 7. Clients et dettes

### 7.1 Fiche client
- Nom, téléphone (identifiant principal), e-mail, date d'anniversaire, langue préférée.
- Type : particulier, habitué, VIP, entreprise, revendeur (vente en gros), membre du personnel.
- Préférences et notes (boisson favorite, table préférée, allergies).
- Historique : visites, commandes, montant dépensé, panier moyen.
- Recherche rapide à la caisse par nom ou téléphone, et création rapide pendant une commande.

### 7.2 Dettes (crédit, « ardoise »)
- **Mettre une note à crédit**, en entier ou en partie, au compte d'un client identifié.
- **Autorisation** par un employé autorisé, avec validation par PIN. On garde toujours qui a accordé le crédit.
- **Plafond de crédit** par client. Blocage automatique en cas de dépassement ou de retard (le gérant peut forcer).
- **Échéance** par dette.
- **Devise** : chaque dette est enregistrée dans une devise. Un remboursement dans une autre devise est converti au taux du jour du remboursement.
- **Remboursements** partiels ou totaux, avec n'importe quel moyen de paiement. Les dettes les plus anciennes sont remboursées en premier.
- **Relevé de compte** imprimable ou envoyé par SMS ou WhatsApp, et **relances** pour les échéances proches ou dépassées.
- **Responsabilité** : en option, l'employé reste responsable de la dette qu'il a accordée.
- **Personnes liées à chaque dette** : l'utilisateur qui a **servi** le client, celui qui a **accordé** le crédit et celui qui en est **responsable** (par défaut le serveur ou la serveuse ; le gérant peut le changer). N'importe quel utilisateur du système peut être lié : serveur, serveuse, barman, caissier, gérant…
- **Comptes entreprise** avec une facture mensuelle.
- **Dettes du personnel**, déductibles du salaire.
- **Avances et soldes positifs** : le client dépose de l'argent à l'avance (lien avec le cashless).
- **Annulation ou remise de dette** réservée au gérant, avec un motif obligatoire.

### 7.3 Rapports
- Total des créances en cours, et ancienneté : 0–30 jours, 31–60 jours, plus de 60 jours.
- Dettes par client, par employé qui a accordé le crédit, par établissement, par devise.
- Distinction entre **chiffre d'affaires** et **encaissements** : une vente à crédit compte dans le chiffre d'affaires, mais pas dans la caisse du jour.
- Remboursements encaissés par session de caisse.

## 8. Cuisine

La cuisine est un **comptoir** qui **transforme des ingrédients en plats**.

```
Fournisseurs / marché
        │
        ▼
DÉPÔT CUISINE (économat, chambre froide, congélateur)
        │  transfert (ou livraison directe en cuisine pour le frais)
        ▼
COMPTOIR CUISINE ──► postes : grill, froid, pizza, pâtisserie…
        ▲
        │ commandes
POINTS DE VENTE
```

### 8.1 Stock de la cuisine
- Ingrédients en kg, litres ou pièces, avec conversions (section 9).
- **Fiche technique de chaque plat** : chaque vente fait baisser les ingrédients utilisés.
- **Rendement** : 1 kg de poisson acheté ne donne que 600 g utilisables.
- **Préparations intermédiaires** (sauces, marinades, bouillons) : préparées à l'avance, stockées, utilisées dans plusieurs plats.
- **Dates de péremption**, règle « premier entré, premier sorti », alertes.
- **Pertes** : plats ratés, renvoyés, périmés, repas du personnel, avec un motif.
- **Inventaires** : chaque jour pour les produits chers, chaque semaine pour le reste.

### 8.2 Circuit d'une commande
1. Le serveur saisit la commande avec les précisions : cuisson, « sans piment », **allergies**.
2. La commande arrive au bon poste, sur un **écran** et/ou un **bon imprimé**.
3. **Envoi en plusieurs temps** : les entrées partent tout de suite, les plats attendent que le serveur les lance.
4. **Statuts** : reçu → en préparation → prêt → servi. Le serveur est prévenu quand c'est prêt.
5. **Temps de préparation** mesuré, avec une alerte en cas de retard.
6. **Rupture** : le chef bloque un plat, qui disparaît immédiatement des points de vente.
7. **Annulation** d'un plat déjà lancé : avec validation, et le plat compte comme perte.

### 8.3 Achats au marché
- **Caisse de dépenses** de la cuisine (fonds remis au chef ou à l'acheteur).
- Achats **sans facture**, avec la quantité et le prix réellement payé.
- **Justificatif** : photo du ticket ou de la marchandise, signature.
- **Mise à jour automatique du coût de revient** à partir des prix réels.

### 8.4 Suivi des coûts
- **Coût matière** de chaque plat et son **pourcentage du prix de vente**.
- **Écart** entre la consommation attendue (selon les ventes) et réelle (selon l'inventaire).
- **Rentabilité par plat**.

## 9. Conditionnement

### 9.1 Principe
Chaque produit a une **unité de base** (bouteille, cl, gramme, pièce) et **plusieurs conditionnements**. Les conversions sont propres **à chaque produit**.

| Produit | Unité de base | Conditionnements |
|---|---|---|
| Bière 72 cl | bouteille | casier = 12 bouteilles · palette = 70 casiers |
| Coca 33 cl | canette | pack = 6 · carton = 24 |
| Whisky 70 cl | cl | bouteille = 70 cl · dose = 4 cl · double = 8 cl |
| Bière pression | litre | fût = 30 L · verre = 25 cl · pinte = 50 cl |
| Riz | kg | sac = 25 kg · portion = 150 g |

### 9.2 Règles
- **Achat, stock et vente dans des unités différentes** : on achète au casier, on stocke à la bouteille, on vend à la bouteille ou au casier.
- **Affichage lisible du stock** : « 9 casiers + 7 bouteilles ».
- **Un prix par conditionnement vendu**, qui peut varier selon la grille de prix.
- **Rendement réel** : une bouteille de 70 cl donne 16 doses de 4 cl en pratique, au lieu de 17 en théorie.
- **Bouteilles entamées** saisies à l'inventaire (fraction ou cl), et **perte normale** pour la pression.
- **Code-barres par conditionnement**.

### 9.3 Tailles
Deux situations différentes, à ne pas confondre :

- **Formats en cl d'une même bouteille** : un vin ou un whisky est servi au verre, à la dose ou à la bouteille depuis **la même bouteille physique**. L'unité de base est le cl et chaque façon de servir est un **conditionnement** du même produit : vin rouge de la maison (verre 15 cl, carafe 30 cl, bouteille 75 cl) ; Johnnie Walker (dose 4 cl, double 8 cl, bouteille 70 cl). Un seul stock, compté en cl.
- **Tailles** : la Primus ou la Mützig existent en **petite (33 cl)** et en **grande (72 cl ou 65 cl)** : ce sont des **bouteilles physiquement différentes**, avec chacune **son stock, ses formats d'achat (casier de 24 petites, casier de 12 grandes), ses prix (y compris prix fixes en francs congolais et prix VIP) et son état disponible / épuisé**. Une taille est un **produit « variante »** rattaché à un **produit principal** (même catégorie) ; le produit principal peut n'avoir aucun format à lui et sert alors de groupe de tailles.

Affichage :
- **Gestion** : la fiche du produit principal a une section « Tailles » (nom de la taille en français et en anglais, contenance, prix principal, ordre, lien vers la fiche de chaque taille) ; « Ajouter une taille » reprend la catégorie, les établissements, l'unité, les badges et la photo du produit principal. La fiche d'une taille rappelle son produit principal. Désactiver le produit principal désactive ses tailles. Dans la liste du catalogue, les tailles apparaissent en retrait sous leur produit.
- **Vitrine** : une seule ligne « Primus » avec un prix par taille et par format vendu (« Petite 33 cl · $1,50 », « Grande 72 cl · $2 ») ; une taille épuisée est marquée seule ; la recherche trouve aussi le nom des tailles.
- **Caisse** : une seule tuile « Primus » (« 2 tailles », plus petit prix suivi de « + ») ; un toucher ouvre le choix de la taille et du format ; la ligne du ticket affiche « Primus Grande 72 cl ».

### 9.4 Emballages consignés
- Casiers vides et bouteilles vides gérés comme **articles de stock**, avec une **valeur de consigne**.
- Échange des vides avec les fournisseurs, ou paiement de la consigne.
- En vente en gros, suivi de **ce que chaque client et chaque fournisseur doit en emballages**.

## 10. Devises et paiements

### 10.1 Principe
- **Devises entièrement configurables** : le propriétaire ajoute ou retire les devises qu'il accepte. Pour chacune, il définit le code (USD, CDF, EUR…), le symbole, le nombre de décimales et la règle d'arrondi.
- **Une devise de référence**, choisie par le propriétaire, pour la comptabilité et les rapports.
- **Une devise locale** (le franc congolais à Goma), choisie dans « Devises et taux » : **par défaut, les tickets et les factures sont en devise locale, avec leur équivalent en dollars**.
- **Ordre d'affichage partout : la devise locale, puis le dollar américain, toujours en deuxième position, puis les autres devises** (par exemple « 88 000 FC · 31 $ · 44 950 FRw »). La caisse, les tickets, les factures, la trésorerie et les rapports suivent cet ordre.
- **Devises acceptées à choisir** par établissement, par point de vente et par moyen de paiement.
- Valeurs proposées à l'installation : USD comme devise de référence, USD et CDF acceptés. Tout se modifie ensuite.

### 10.2 Taux de change
- Chaque devise a **un taux** par rapport à la devise de référence. Les conversions entre deux autres devises sont calculées automatiquement, ou fixées par un **taux direct**.
- ⚠️ Hypothèse : le gérant fixe les taux **chaque jour** ou **par session de caisse**. Chaque modification est enregistrée.
- Chaque opération garde **le taux appliqué au moment où elle est faite**.
- **Note ouverte quand le taux change** : on applique le taux du moment du paiement (par défaut) ou le taux figé à l'ouverture de la note, selon le paramétrage.

### 10.3 Prix
Au choix, par produit ou par carte :
1. **Prix dans une seule devise**, les autres sont calculées avec le taux ;
2. **Prix fixe dans chaque devise**, pour garder des montants ronds.

**Règles d'arrondi par devise** (par exemple à 100 ou 500 CDF). L'écart d'arrondi est enregistré.

### 10.4 Encaissement
- **Total affiché dans toutes les devises acceptées**, à l'écran et sur le ticket : la devise locale en grand, le dollar en deuxième position.
- **Paiement en plusieurs devises** pour une même note, et **reste à payer** recalculé dans chaque devise après chaque paiement.
- **Monnaie rendue dans la devise choisie**.
- **Chaque paiement précise toujours** :
  - le **compte de trésorerie** qui reçoit l'argent (section 10.5), donc son **type** : **caisse**, **mobile money** ou **banque** (virement, carte, chèque) ; à la vente s'ajoute le **crédit client** (dette, section 7) ;
  - la **devise** : la devise locale, le dollar, **ou les deux** (par exemple 20 $ dans la Caisse du Comptoir et 15 000 FC sur M-Pesa pour une même note).
- Chaque ligne de paiement enregistre : compte, type du compte, devise, montant, taux appliqué, contre-valeur dans la devise de référence.
- La même règle vaut pour **toutes les opérations d'argent** : ventes, achats aux fournisseurs, paiements et remboursements de dettes, **dépenses**, salaires, transferts.

**Calcul retenu (fait)** : chaque devise a son propre total, avec ses propres prix (88 000 FC ou 31 $ pour le même ticket). Un paiement couvre une **part** du ticket dans sa devise : 20 $ sur 31 $ = 64,5 %. Le reste à payer s'affiche dans chaque devise, arrondi vers le haut ; le ticket est réglé quand il reste moins d'un demi-pas d'arrondi de la devise du ticket (moins de 50 FC) ; la monnaie se rend en espèces, dans la devise choisie, arrondie vers le bas. La caisse calcule pour l'affichage, le serveur refait tout (prix, totaux, règlement) et refuse ce qui ne colle pas.

**À l'encaissement (fait)** :
- « Encaisser » ou les raccourcis du panneau (le compte principal de chaque type : Caisse du Comptoir, M-Pesa, Rawbank) ouvrent la fenêtre : payer en FC, en $ ou les deux ; pour chaque devise, le montant reçu et **le compte touché directement**, parmi ceux qui tiennent cette devise, rangés sous leur type (Caisse, Mobile money, Banque) ; montants rapides quand le compte est une caisse (montant exact, billets ronds).
- Seuls les comptes de l'établissement et les comptes communs sont proposés ; le serveur refuse un compte d'ailleurs ou qui ne tient pas la devise.
- Chaque paiement entre dans son compte, dans sa devise ; la monnaie sort d'une caisse qui tient la devise rendue (celle qui a reçu les espèces, sinon la caisse de l'établissement).
- La vente garde le numéro du ticket par établissement (CDL-000123), qui a vendu, sur quel appareil, les lignes au prix du moment et le taux de chaque devise.
- Un double toucher ou une réponse perdue ne crée jamais deux ventes (clé unique par ticket).
- Reçu à l'écran, imprimable au format des imprimantes de tickets (80 mm) : total en devise locale, puis en dollars.
- Un rôle sans le droit « encaisser » (serveur) prépare le ticket et appelle un caissier.

Exemple : note de **50 $**. Taux : 1 € = 1,08 $ · 1 $ = 1 300 RWF · 1 $ = 2 800 CDF.

| Paiement | Valeur en dollars | Reste à payer |
|---|---|---|
| 20 € | 21,60 $ | 28,40 $ |
| 26 000 RWF | 20,00 $ | 8,40 $ (= 23 520 CDF) |
| 23 500 CDF (arrondi) | 8,39 $ | **0, note réglée** |

L'écart de 20 CDF est enregistré comme écart d'arrondi.

### 10.5 Caisse, trésorerie et rapports
- **Fond de caisse, clôture et écarts par devise** : les écarts de deux devises ne se compensent pas.
- **Trois types de comptes** : **caisse**, **mobile money**, **banque**. Ce sont des types, pas des comptes : on crée autant de comptes que nécessaire dans chaque type (Caisse du Comptoir, Caisse du Club, M-Pesa, Airtel Money, Rawbank, Equity BCDC).
- **Un compte tient une ou plusieurs devises**, chacune avec son solde : la Caisse du Comptoir a un solde en FC et un solde en $, comptés séparément (fait). Une devise s'ajoute plus tard ; elle ne se retire que si elle n'a aucun mouvement. Chaque compte peut être lié à un établissement (sinon il est commun), avec l'opérateur ou la banque et le numéro.
- **Solde de chaque compte et de chaque devise** calculé à partir de ses mouvements (solde d'ouverture, ventes, dépenses, achats, transferts…) ; total par devise et total converti dans la devise de référence.
- **Transferts entre comptes** : de la caisse vers la banque, de M-Pesa vers la caisse… Chaque côté précise le compte et la devise ; un change dans une même caisse (100 $ → 284 000 FC) est un transfert. Entre deux devises, le montant reçu est proposé avec le taux du jour, modifiable, et le taux réellement appliqué est enregistré.
- **Encaissements par devise**, et totaux convertis dans la devise de référence.
- **Gains et pertes de change** calculés automatiquement.
- Achats fournisseurs dans n'importe quelle devise, avec conversion du coût de revient.

## 11. Langues

- **Français et anglais**.
- **Langue choisie par utilisateur**, et **langue par établissement** pour les tickets, factures et relevés.
- **Carte des produits traduite** : chaque produit a un nom dans chaque langue.
- **Formats** de dates, nombres et monnaies selon la langue.
- Traductions dans des fichiers à part, pour pouvoir ajouter une langue plus tard sans toucher au code.

## 12. Page publique et menu par code QR

Chaque établissement a une **page publique** : la vitrine du bar, avec tous ses produits et leurs prix. Les clients y accèdent en scannant un **code QR** posé sur les tables, au comptoir ou à l'entrée.

### 12.1 Adresses

```
monbar.<domaine>/                    → présentation du client et de ses établissements
monbar.<domaine>/e/<etablissement>   → page d'un établissement avec son menu
monbar.<domaine>/e/<etablissement>?zone=vip → menu avec les prix de la zone VIP
```

### 12.2 Présentation de l'établissement
- Nom, logo, photo de couverture, description.
- Type (restaurant-bar, lounge, nightclub), adresse, lien vers la carte, téléphone, WhatsApp, réseaux sociaux.
- **Horaires par service** (restaurant, lounge, club) et indication « ouvert » ou « fermé » en ce moment.

### 12.3 Menu
- **Catégories** et **produits** avec photo, description et badges (nouveau, populaire, épicé…).
- **Prix de chaque conditionnement vendu** : verre, bouteille, dose, casier…
- **Prix dans les devises acceptées**, avec un sélecteur de devise.
- **Grille de prix selon le code QR** : un code QR posé dans le carré VIP affiche les prix VIP.
- **Carte du service en cours** : après 23h, la carte et les prix de nuit. La cuisine fermée fait disparaître les plats.
- **Disponibilité en temps réel** : un produit en rupture est affiché « épuisé » ou masqué.
- Allergènes pour les plats.
- Recherche et filtres par catégorie.
- **Français et anglais**, au choix du visiteur.
- Chaque produit a une case « visible sur le menu public ».

### 12.4 Codes QR
- Générés depuis l'espace de gestion : un par établissement, par zone ou par table.
- Téléchargeables et imprimables (avec le logo et le nom de l'établissement).
- **Statistiques de scans** : nombre de visites par code QR et par jour.

### 12.5 Exigences
- Pensée **d'abord pour le téléphone**, légère et rapide même avec une connexion faible.
- Bien référencée sur les moteurs de recherche.
- Plus tard : commande depuis la table, appel du serveur, avis des clients.

## 13. ERP (gestion de l'établissement)

Caisse et ERP partagent **la même base de données** : chaque vente fait baisser le stock, chaque crédit alimente les créances et chaque dépense arrive dans la trésorerie, sans ressaisie.

### 13.1 Achats et fournisseurs
- Fiche fournisseur : brasseries, distributeurs, grossistes, marché.
- Bons de commande, réception (quantités commandées ou reçues, écarts).
- Factures fournisseurs, paiements, **dettes envers les fournisseurs** et échéances.
- Historique des prix d'achat par produit et par fournisseur.

### 13.2 Stock avancé
- **Transferts** entre dépôts et comptoirs (section 2).
- **Fiches techniques** pour les cocktails et les plats.
- **Coût de revient et marge** par produit.
- **Inventaires** : comptage physique et écarts, par employé et par service.
- **Pertes** : casse, offerts, consommations du personnel, périmés, avec un motif.
- **Emballages consignés** (section 9.4).
- **Alertes** de stock bas et suggestions de commande.

### 13.3 Dépenses et trésorerie
- Dépenses par catégorie : loyer, électricité, carburant du groupe électrogène, entretien, sécurité, artistes…
- **Chaque dépense précise le compte payeur (caisse, mobile money, banque) et la devise, ou les deux devises** ; une dépense peut être payée en partie en dollars et en partie en francs (section 10.4).
- Comptes de trésorerie, chacun avec ses devises (section 10.5), et les transferts entre eux.
- Vue de la trésorerie : entrées (ventes, remboursements de dettes) et sorties (fournisseurs, dépenses, salaires).
- TVA et taxes, export vers un logiciel comptable ou un comptable.

### 13.4 Ressources humaines et présence

**Pointage par code QR**
- Un **code QR** est affiché à l'entrée de l'établissement. L'employé le scanne avec son téléphone pour pointer à l'arrivée et au départ.
- Le code QR **change régulièrement**, pour qu'on ne puisse pas pointer avec une photo du code prise la veille.
- **Position GPS** vérifiée au moment du scan : l'employé doit être sur place.
- ⚠️ Hypothèse : pour un employé sans smartphone, un pointage de secours se fait sur la caisse avec le PIN, validé par le gérant.

**Plannings et suivi**
- Plannings par service (restaurant, lounge, club) et par poste (bar, cuisine, salle, sécurité).
- Services de nuit rattachés à la journée d'exploitation (section 5).
- **Retards, absences, départs anticipés**, comparés au planning. Pauses, heures supplémentaires, jours fériés.
- **Demandes de congé** et **justification des absences**, validées par le gérant.
- **Extras et journaliers** (sécurité, hôtesses) : pointage et paiement par soirée.

**Lien avec la caisse et la paie**
- Un employé **non pointé ne peut pas ouvrir de caisse**. Pour dépointer, il doit d'abord **clôturer sa caisse**.
- Heures travaillées, retards et absences vont au **calcul des salaires**.
- Fiche employé, contrat, poste, établissement.
- Salaires, **avances sur salaire**, retenues (dettes du personnel, manquants de caisse).
- Commissions des serveurs et des promoteurs, répartition des pourboires.

**Paie**
- **Salaire fixé en dollars, en francs ou dans les deux** (par exemple 150 $ + 100 000 FC), par mois, par semaine ou par soirée pour les extras.
- **Bulletin de paie** : salaire de base, heures supplémentaires, primes et commissions, moins les avances, les retenues (dettes du personnel, manquants de caisse) et les absences non justifiées ; montants en devise locale d'abord, le dollar en deuxième position.
- **Chaque paiement de salaire ou d'avance précise le compte payeur (caisse, mobile money, banque) et la devise, ou les deux devises** : il sort du compte de trésorerie choisi (section 10.5) et apparaît dans les dépenses de salaires.
- Avances sur salaire accordées à tout moment, déduites automatiquement de la paie suivante.
- Historique des paies par employé, et masse salariale par établissement et par mois.
- Module « Personnel et paie » sur l'écran d'accueil des modules.

### 13.5 Événements (lounge et nightclub)
- Soirées : date, thème, DJ ou artistes, budget.
- Coûts (cachets, publicité, location de matériel) comparés aux recettes (entrées, bar, bouteilles VIP).
- **Rentabilité par soirée**.

### 13.6 Tableaux de bord de la direction
- Chiffre d'affaires, marge, dépenses et résultat, par jour, par service, par établissement.
- Produits les plus rentables et les moins vendus.
- Créances clients et dettes fournisseurs.
- Performances et présence des employés (détail en section 13.7).

### 13.7 Rapports du personnel

Chaque vente garde l'utilisateur qui a **pris la commande** et celui qui l'a **encaissée** ; chaque dette garde qui a servi, qui a accordé le crédit et qui en est responsable. Les rapports ci-dessous en découlent.

**Serveur de la période** (serveur ou serveuse de la semaine, du mois, ou de toute période choisie)
- Période au choix : aujourd'hui, cette semaine, ce mois, ou du … au … ; par établissement et par service (restaurant, lounge, club).
- **Classement** de tous les utilisateurs qui ont vendu, avec pour chacun : chiffre d'affaires encaissé, nombre de tickets, panier moyen, nombre d'articles, tables servies, pourboires, ventes à crédit accordées, annulations et remises.
- **Critère du classement** choisi par le gérant : chiffre d'affaires encaissé (par défaut), nombre de tickets ou panier moyen. Une vente à crédit ne compte qu'une fois remboursée si le gérant le choisit, pour ne pas récompenser des crédits jamais payés.
- **Mise en avant** sur le tableau de bord : « Serveuse de la semaine », avec son résultat et l'écart avec la semaine précédente.
- Comparaison avec la période précédente et évolution jour par jour.
- Chaque utilisateur voit ses propres résultats ; le gérant et le propriétaire voient tout le classement.

**Dettes des clients liées aux utilisateurs**
- Pour chaque utilisateur (serveur, serveuse, barman, caissier, gérant…) : total des dettes en cours dont il est responsable, nombre de clients concernés, montant en retard, ancienneté (0–30, 31–60, plus de 60 jours), montant remboursé sur la période.
- Détail par utilisateur : chaque client, chaque dette, sa date, son échéance, ce qui reste à payer, par devise.
- Filtres : période, établissement, devise, « en retard seulement ».
- Option de **responsabilité financière** : après un délai fixé par le gérant, une dette non remboursée peut être retenue sur le salaire de l'utilisateur responsable (lien avec la paie, section 13.4).
- Les **dettes du personnel** lui-même (consommations d'un employé à crédit) sont présentées à part.
- Alertes : un utilisateur qui dépasse un plafond de dettes en cours ne peut plus accorder de crédit sans validation du gérant.

**Export** : chaque rapport s'imprime et s'exporte en PDF et en CSV (tableur).

## 14. Interfaces simples, adaptées au contexte

Principe : chaque personne ne voit que ce dont elle a besoin, au bon moment, sur son appareil. Moins de texte, de grands boutons, des couleurs qui aident à se repérer.

### 14.1 Ce qui adapte l'interface

| Contexte | Ce qui change |
|---|---|
| **Type d'établissement** | Restaurant-bar : tables, envoi en cuisine. Lounge : bouteilles, notes ouvertes. Nightclub : mode comptoir rapide, grandes tuiles, thème sombre. |
| **Service en cours** | La carte, les prix et les modules du service actif (la cuisine fermée disparaît de la caisse, prix de nuit après 23h). |
| **Rôle** | Chaque rôle arrive directement sur son écran : le barman sur la caisse de son comptoir, le chef sur l'écran de cuisine, le magasinier sur le stock. Les menus des écrans non autorisés ne s'affichent pas. |
| **Lieu** | Un point de vente ne montre que les produits de ses comptoirs, avec la grille de prix de sa zone (carré VIP…). |
| **Appareil** | Caisse fixe ou tablette : grille et ticket côte à côte. Téléphone de serveur : grille en plein écran et ticket dans un panneau, utilisable d'une main. |
| **Langue et devise** | Celles de l'utilisateur et de l'établissement. |

### 14.2 Règles communes
- **Cibles tactiles d'au moins 44 px**, utilisables avec des doigts mouillés ou dans la pénombre.
- **Couleur par catégorie** reprise partout (onglets, tuiles, lignes du ticket) pour se repérer sans lire.
- **Retour immédiat** à chaque action, et **annulation facile** (bouton −, vider le ticket).
- **Thème clair ou sombre**, choisi par défaut selon le type d'établissement et modifiable sur chaque appareil.
- **Peu de saisie** : on touche plutôt qu'on tape. La recherche est là pour aller plus vite, pas obligatoire.
- **Réglages mémorisés par appareil** (taille des tuiles, thème).

### 14.3 Caisse : produits en grille
- **Aucun menu sur la caisse** : l'écran sert uniquement à vendre (établissement et zone, recherche, catégories, produits, ticket). Pour en sortir, une seule icône discrète « Quitter la caisse » ouvre une confirmation ; plus tard, elle demandera le PIN d'un gérant (phase 2).
- **Grille de tuiles** : nom, prix dans la devise principale, photo si elle existe, bandeau de la couleur de la catégorie.
- **Onglets de catégories** en haut, avec « Tout » en premier. Une catégorie qui a des sous-catégories ouvre une **seconde rangée de puces** (« Toutes », « Locales », « Importées ») ; la couleur reste celle de la catégorie principale.
- **Un toucher = un article.** Si le produit a plusieurs formats (dose, bouteille…) ou plusieurs tailles (Petite 33 cl, Grande 72 cl), une fenêtre propose chaque taille et chaque format avec son prix (section 9.3).
- **Quantité affichée sur la tuile** : on voit tout de suite ce qui est déjà dans le ticket.
- **Produit en rupture** grisé et impossible à toucher.
- **5 tuiles par ligne** (3 sur téléphone, pour garder des cibles faciles à toucher).
- **Trois tailles de tuiles** (petite, moyenne, grande) qui règlent la hauteur et la taille du texte. Le nightclub démarre en grandes tuiles.
- **Ticket** : lignes avec boutons − et +, total dans la devise principale, et total dans chaque autre devise acceptée.
- **Recherche** : la touche « / » place le curseur dans la recherche, Entrée ajoute le produit s'il est seul (prépare le lecteur de codes-barres).
- Plus tard : produits favoris et les plus vendus en premier, selon le service et le comptoir.

## 15. Exigences transverses

- **Mode hors ligne** : la caisse fonctionne sans réseau et se synchronise quand il revient.
- **Rapidité** : un barman doit encaisser en 2 ou 3 secondes.
- **Plusieurs établissements** par client.
- **Sécurité** : annulations, remises, crédits et changements de taux validés par un gérant, avec historique complet.
- **Isolation des clients** : une base de données par instance (section 1.1).

## 16. Découpage en phases

Chaque phase est utilisable seule.

| Phase | Contenu |
|---|---|
| 1 | Fondations : structure du code, instances par sous-domaine · catalogue (catégories, produits, conditionnements, prix, devises configurables) · **page publique et menu par code QR** · espace de gestion du catalogue · **écran de caisse en grille** (sans enregistrement des ventes) · gestion des établissements, des devises et taux, du compte · design Volcan · français et anglais |
| 2 | Console super admin (clients, sous-domaines, instances, abonnements, activation des modules) · ~~utilisateurs, rôles et permissions~~ (fait : rôles avec portée, PIN, caisse partagée, journal) · dépôts, comptoirs, points de vente |
| 3 | Caisse : commandes, encaissement multi-devises, taux de change, sessions de caisse · stock simple et transferts · **rapport des ventes par utilisateur et serveur de la période** |
| 4 | Clients et dettes · **dettes des clients liées aux utilisateurs** (servi par, accordé par, responsable) · plan de salle · cuisine (circuit des commandes, fiches techniques) · services horaires |
| 5 | Modules lounge et nightclub (bouteilles, VIP, entrées, invités, vestiaire, cashless, mode comptoir rapide) · vente en gros au dépôt et consignes |
| 6 | ERP : achats et fournisseurs, stock avancé (les comptes de trésorerie, les transferts et les dépenses sont avancés en phase 1) |
| 7 | Ressources humaines : présence par code QR, plannings, paie, commissions · événements |
| 8 | Tableaux de bord avancés · facturation automatique des abonnements · export comptable |

## 17. Décisions prises

| Sujet | Décision |
|---|---|
| Déploiement | Une instance par client, sur un sous-domaine, gérée depuis une console super admin. Pas de SaaS partagé. |
| Langues | Français et anglais |
| Pointage | Par code QR |
| Devises | Entièrement configurables par le propriétaire |
| Technologie | PHP natif, sans framework (section 1.3) |
| Vitrine | Page publique par établissement avec le menu et les prix, accessible par code QR |
| Interfaces | Simples et adaptées au contexte (établissement, service, rôle, lieu, appareil) ; à la caisse, les produits sont en grille |
| Design | Direction « Volcan », choisie parmi 4 propositions : thème nuit (nightclub, lounge) et thème jour (restaurant-bar), polices Unbounded et Sora (voir docs/design-system.md) |
| Images | Illustrations SVG pour la démonstration ; les vraies photos s'ajoutent depuis la gestion du catalogue ; import de photos libres possible (docs/photos.md) |
| Devises à l'affichage | Devise locale d'abord (tickets et factures), le dollar toujours en deuxième position, puis les autres (section 10.1) |
| Paiements | Toute opération d'argent précise le compte (de type caisse, mobile money ou banque) et la devise, ou les deux devises ; plusieurs comptes par type, chacun avec une ou plusieurs devises, et transferts (sections 10.4 et 10.5) |
| Connexion | Gestion : téléphone ou e-mail et mot de passe ; caisse : PIN de 6 chiffres sur un appareil autorisé, verrouillage après chaque encaissement (section 6.3) |
| Navigation | Après la connexion, écran des modules ; dans un module, barre latérale sur ordinateur et barre du bas sur téléphone, sans menu caché |
| Rapports du personnel | Serveur ou serveuse de la période (classement configurable) et dettes des clients liées à chaque utilisateur (section 13.7) |

## 18. Questions ouvertes

1. Le dépôt **vend-il en gros** à d'autres clients, ou approvisionne-t-il seulement les comptoirs ?
2. Un **dépôt central** pour plusieurs établissements, ou un dépôt par établissement ?
3. Les **serveurs encaissent-ils eux-mêmes**, ou tout passe-t-il par un caissier ?
4. Le barman est-il **responsable des écarts** de stock de son comptoir ?
5. La cuisine achète-t-elle surtout **au marché en espèces** ou chez des **fournisseurs avec facture** ?
6. Commandes en cuisine sur **écran**, sur **bon imprimé**, ou les deux ?
7. Repas du personnel **gratuits** (pertes) ou **déduits du salaire** ?
8. Les prix sont-ils fixés dans **une seule devise** (convertie) ou **dans chaque devise** ?
9. **Qui fixe les taux de change**, et à quelle fréquence ?
10. **Contenu des casiers** de vos principaux produits.
11. **Hébergement** des instances : sur vos serveurs, ou parfois chez le client ?
12. **Paiement des abonnements** par vos clients : mobile money, virement, autre ?
