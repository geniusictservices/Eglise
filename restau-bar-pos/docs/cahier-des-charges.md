# Cahier des charges — restau-bar-pos

Système de caisse (POS) et ERP adaptable à trois contextes : **restaurant-bar**, **lounge bar** et **nightclub**.
Principe : un **socle commun** toujours actif + des **modules** activés selon le type d'établissement et l'heure.

---

## 1. Socle commun

- **Catalogue** : produits, catégories, prix, taxes, options (« sans glaçons », cuisson…).
- **Commandes et encaissement** : espèces, carte, mobile money, paiements mixtes.
- **Utilisateurs et rôles** : serveur, barman, caissier, gérant, avec un code PIN chacun.
- **Postes de préparation** : bar, cuisine, chicha, chacun avec son imprimante ou son écran.
- **Sessions de caisse** : ouverture, clôture, fond de caisse, écarts.
- **Rapports** : ventes, produits les plus vendus, ventes par employé, par période.
- **Stock** de base.
- **Clients et dettes** (voir section 4).

## 2. Modules selon le contexte

| Module | Restau-bar | Lounge | Nightclub |
|---|:-:|:-:|:-:|
| Plan de salle et tables | ✅ | ✅ | ✅ (zones VIP) |
| Notes ouvertes (on paie à la fin) | ✅ | ✅ | ✅ |
| Partage de l'addition | ✅ | ✅ | ➖ |
| Envoi des plats en cuisine en plusieurs temps | ✅ | ➖ | ❌ |
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

✅ = activé par défaut · ➖ = optionnel · ❌ = désactivé par défaut

## 3. Services horaires

Un même lieu peut changer de mode dans la journée. Exemple :

| Service | Horaires | Modules principaux |
|---|---|---|
| Restaurant | 12h – 15h | tables, cuisine, partage de l'addition |
| Lounge | 18h – 23h | bouteilles, happy hour |
| Club | 23h – 5h | entrées, mode comptoir rapide, cashless, carte de prix « nuit » |

Chaque service a ses propres prix, modules et postes. Tout reste dans **une seule base de données**, avec des rapports consolidés.

## 4. Gestion des clients et des dettes

### 4.1 Fiche client

- Nom, téléphone (identifiant principal), e-mail, date d'anniversaire.
- Type : particulier, habitué, VIP, entreprise, membre du personnel.
- Préférences et notes (boisson favorite, table préférée, allergies).
- Historique : visites, commandes, montant total dépensé, panier moyen.
- Recherche rapide à la caisse par nom ou téléphone.
- Création rapide pendant une commande (nom + téléphone suffisent).

### 4.2 Dettes (crédit, « ardoise »)

- **Mettre une note à crédit** : une note ou une partie d'une note est imputée au compte d'un client identifié.
- **Autorisation** : selon le rôle, seul un gérant ou un employé autorisé peut accorder un crédit, avec validation par PIN.
- **Plafond de crédit** par client, et blocage automatique s'il est dépassé ou en cas de retard (le gérant peut forcer).
- **Échéance** : date limite de remboursement par dette.
- **Remboursements** : partiels ou totaux, avec n'importe quel moyen de paiement. Un remboursement est réparti sur les dettes les plus anciennes en premier.
- **Relevé de compte** : solde, liste des dettes et des remboursements, imprimable ou envoyé par SMS ou WhatsApp.
- **Relances** : alertes pour les échéances proches ou dépassées, et messages de rappel au client.
- **Responsabilité** : on enregistre toujours qui a accordé le crédit. En option, l'employé reste responsable de la dette qu'il a accordée.
- **Comptes entreprise** : consommations cumulées et facture mensuelle.
- **Consommations du personnel** : dettes des employés, déductibles du salaire.
- **Avances et soldes positifs** : un client peut déposer de l'argent à l'avance (lien avec le module cashless).
- **Annulation ou remise de dette** : réservée au gérant, avec un motif obligatoire et un historique.

### 4.3 Rapports

- Total des créances en cours.
- Ancienneté des dettes : 0–30 jours, 31–60 jours, plus de 60 jours.
- Dettes par client, par employé qui a accordé le crédit, par établissement.
- Distinction claire entre **chiffre d'affaires** (ventes) et **encaissements** (argent réellement reçu) : une vente à crédit compte dans le chiffre d'affaires, mais pas dans la caisse du jour.
- Remboursements encaissés pendant une session de caisse.

## 5. ERP (gestion de l'établissement)

La caisse n'est que la partie visible. L'ERP gère tout ce qui se passe en coulisses. Caisse et ERP partagent **la même base de données** : chaque vente fait baisser le stock, chaque crédit alimente les créances et chaque dépense arrive dans la trésorerie, sans ressaisie.

### 5.1 Achats et fournisseurs

- Fiche fournisseur : brasseries, distributeurs, grossistes, marché.
- Bons de commande, réception des marchandises (quantités commandées ou reçues, écarts).
- Factures fournisseurs, paiements, **dettes envers les fournisseurs** et leurs échéances.
- Historique des prix d'achat par produit et par fournisseur.

### 5.2 Stock avancé

- **Plusieurs dépôts** : réserve, bar, cuisine, et un stock par site. Transferts entre dépôts (réserve → bar).
- **Fiches techniques (recettes)** : un cocktail consomme par exemple 4 cl de rhum, 2 cl de sirop et 1 citron. Une bouteille servie au verre se compte en doses.
- **Coût de revient et marge** par produit, calculés à partir des recettes et des prix d'achat.
- **Inventaires** : comptage physique et écarts avec le stock théorique, par employé et par service.
- **Pertes** : casse, offerts, consommations du personnel, périmés, avec un motif.
- **Emballages consignés** : bouteilles et casiers vides, consignes payées et récupérées auprès des fournisseurs.
- **Alertes** de stock bas et suggestions de commande.

### 5.3 Dépenses et trésorerie

- Saisie des dépenses par catégorie : loyer, électricité, carburant du groupe électrogène, entretien, sécurité, artistes…
- Comptes de trésorerie : caisse, banque, mobile money, avec les transferts entre eux.
- Vue de la trésorerie : entrées (ventes, remboursements de dettes) et sorties (fournisseurs, dépenses, salaires).
- TVA et taxes, et export vers un logiciel comptable ou vers un comptable.

### 5.4 Ressources humaines

- Fiche employé, contrat, poste, site.
- Plannings par service et pointage (arrivées et départs à la caisse avec le PIN).
- Salaires, **avances sur salaire**, retenues (dettes du personnel, manquants de caisse).
- Commissions des serveurs et des promoteurs, et répartition des pourboires.

### 5.5 Événements (lounge et nightclub)

- Soirées : date, thème, DJ ou artistes, budget.
- Coûts (cachets, publicité, location de matériel) comparés aux recettes (entrées, bar, bouteilles VIP).
- **Rentabilité par soirée**.

### 5.6 Tableaux de bord de la direction

- Chiffre d'affaires, marge, dépenses et résultat, par jour, par service, par site.
- Produits les plus rentables et les moins vendus.
- Créances clients et dettes fournisseurs.
- Performances des employés.

## 6. Exigences transverses

- **Mode hors ligne** : la caisse fonctionne sans réseau et se synchronise quand il revient.
- **Rapidité** : un barman doit encaisser en 2 ou 3 secondes.
- **Multi-établissements** possible à terme.
- **Sécurité** : annulations, remises et crédits validés par un gérant, avec historique complet.

## 7. Découpage en phases

Le périmètre est large : on le construit par étapes, chaque phase étant utilisable seule.

| Phase | Contenu |
|---|---|
| 1 | Socle de la caisse (catalogue, commandes, encaissement, rôles, sessions de caisse), stock simple, clients et dettes |
| 2 | Plan de salle, postes de préparation, services horaires, modules lounge et nightclub |
| 3 | ERP : achats et fournisseurs, stock avancé (recettes, inventaires, consignes), dépenses et trésorerie |
| 4 | ERP : ressources humaines (plannings, pointage, salaires, commissions), événements |
| 5 | Tableaux de bord avancés, multi-établissements, export comptable |
