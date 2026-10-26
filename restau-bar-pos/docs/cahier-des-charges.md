# Cahier des charges — restau-bar-pos

Système de caisse (POS) adaptable à trois contextes : **restaurant-bar**, **lounge bar** et **nightclub**.
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

## 5. Exigences transverses

- **Mode hors ligne** : la caisse fonctionne sans réseau et se synchronise quand il revient.
- **Rapidité** : un barman doit encaisser en 2 ou 3 secondes.
- **Multi-établissements** possible à terme.
- **Sécurité** : annulations, remises et crédits validés par un gérant, avec historique complet.
