# Lancer Waumini avec les communautés pilotes

Ce guide accompagne Genius ICT pendant le lancement des deux ou trois premières communautés : préparer leurs données, les former, puis les suivre pendant le premier mois. Il complète le [guide de déploiement](deploiement-lws.md) et le [guide de l'équipe](espace-genius-ict.md).

## 1. Avant : choisir et préparer

**Choisir des pilotes variés** : par exemple une paroisse d'une dénomination (avec son siège, pour la consolidation), une église indépendante de taille moyenne, et, si possible, une communauté qui paie déjà ses ouvriers (pour la paie).

Pour chacune, désigner **un référent** : la personne qui sera l'administrateur dans Waumini, joignable sur WhatsApp.

**Créer le compte** : le référent s'inscrit lui-même (c'est plus formateur), ou Genius ICT l'inscrit avec lui. Prolonger l'essai si besoin (espace Genius ICT, fiche de la communauté, *Prolonger l'essai*) : le lancement d'un pilote prend souvent plus de 30 jours.

**Montrer d'abord la démo** : *Essayer la démo* sur la page d'accueil, puis passer d'un rôle à l'autre (administrateur, pasteur, trésorière, secrétaire, membre). C'est le meilleur support de présentation au comité.

## 2. Rassembler les données

| Données | Où les trouver | Comment les reprendre |
|---|---|---|
| **Le registre des membres** | Cahiers, fichiers Excel | *Membres › Importer* : télécharger le **modèle Excel de Waumini**, le remplir (une ligne par personne, les ménages par leur nom de famille), puis l'importer. Waumini vérifie chaque ligne avant d'importer, et l'import s'annule d'un geste. Voir le chapitre [Importer depuis Excel](manuel/14-import-export.md). |
| **Le format du numéro de membre et les statuts** | Les cartes actuelles | *Membres › Réglages du registre*, **avant** l'import. Voir [Réglages du registre](manuel/15-reglages-registre.md). |
| **Les départements, les fonctions** | Le comité | Saisis à la main : quelques minutes. |
| **Les caisses et comptes** | Le trésorier | *Finances › Comptes* : chaque caisse, compte mobile money et compte bancaire, avec son **solde de départ** à une date précise (par exemple le 1er du mois du lancement). Voir [Comptes et recettes](manuel/16-finances.md). |
| **Le taux du jour** | Le trésorier | *Devises et taux* : le taux du franc congolais du jour. |
| **Les promesses en cours** | Le cahier des promesses | *Finances › Promesses*, une par personne, avec ce qui est déjà versé. |
| **Le budget de l'année** | Le comité des finances | *Budget* : soit saisi directement, soit proposé par les départements. |
| **Les ouvriers payés** | La liste de paie | *Paie › Bénéficiaires*, avec leurs éléments (salaire de base, primes, retenues). |
| **Les anciens registres** (baptêmes, mariages) | L'armoire | *Registres* : recopiés **petit à petit** par la secrétaire, en commençant par les plus demandés. Ce n'est pas un préalable au lancement. |

**Règle d'or : partir d'une date.** Les finances commencent au **solde de départ** ; l'historique antérieur reste dans les cahiers. Ne pas ressaisir l'année passée.

Les fichiers reçus des communautés contiennent des données personnelles : les garder dans un dossier protégé de Genius ICT, et les effacer une fois l'import vérifié.

## 3. Former : une session par rôle

Former **sur le téléphone de chacun**, avec la vraie communauté une fois les données reprises (ou sur la démo avant). Compter 1 h à 1 h 30 par session, en petit groupe.

| Session | Pour qui | Ce qu'on fait ensemble | Chapitres du manuel |
|---|---|---|---|
| **Installer et se repérer** | Tout le monde | Installer Waumini sur le téléphone, se connecter, changer son mot de passe, activer les notifications, ouvrir le manuel | 1, 2, 3, 10, 25 |
| **L'administrateur** | Le référent | Hiérarchie, utilisateurs et rôles, paramètres (identité, couleurs, libellés), accès du support, abonnement | 4 à 9, 11 |
| **Le secrétariat** | Secrétaire | Fiche d'un membre, ménages, import, carte de membre, documents avec QR code, registres, annonces, calendrier, site vitrine | 12 à 15, 26 à 30, 34 |
| **La trésorerie** | Trésorier, adjoints | Une **vraie collecte du culte** du dimanche, une dépense de bout en bout, un paiement déclaré, la clôture du mois, les rapports | 16 à 20, 24 |
| **Le pasteur et le comité** | Pasteur, conseil | Tableau de bord, approbations, budget et plan d'action, suivi pastoral et notes confidentielles | 21 à 23, 31 |
| **Les responsables** | Départements, groupes | Besoins budgétaires, demandes de dépense, rencontres et présences de leur groupe | 21, 26, 27 |
| **Les membres** | Quelques membres volontaires | Leur espace : carte, dons et reçus, demander une attestation ou la prière, déclarer un don | 32 |

Laisser à chacun le lien du manuel (`waumini.com/aide`) et le chemin **Administration › Support Genius ICT** pour poser ses questions.

## 4. Le premier mois

| Quand | Quoi | Vérifier |
|---|---|---|
| Premier dimanche | La collecte du culte saisie dans Waumini, avec la feuille papier en parallèle | Les totaux sont identiques |
| Première semaine | Les présences du culte, une annonce partagée sur WhatsApp | Les membres reçoivent les nouveautés |
| Deuxième semaine | Une dépense demandée, approuvée et décaissée | Les signatures suivent le circuit choisi |
| Fin du mois | La **clôture du mois** et le rapport financier présenté au comité | Les soldes correspondent aux caisses réelles |
| Chaque semaine | Un appel ou un message au référent | Les tickets de support sont traités |

Noter les questions qui reviennent : elles enrichissent la [FAQ](manuel/faq.md) et le manuel. Relever aussi les **mots** que la communauté emploie (« Berger », « Ministère »…) et les régler dans *Paramètres › Libellés*.

## 5. Liste de contrôle du lancement

- [ ] Serveur en production, HTTPS, tâche cron active, sauvegarde de la nuit vérifiée, essai de restauration fait
- [ ] Coordonnées, numéros de paiement, tarifs et textes juridiques relus dans l'espace Genius ICT
- [ ] Clés des notifications réglées, notification reçue sur un téléphone de test
- [ ] Compte de chaque pilote créé, essai prolongé si besoin, référent formé
- [ ] Réglages du registre faits **avant** l'import des membres
- [ ] Membres importés et vérifiés par la secrétaire
- [ ] Caisses et soldes de départ saisis, taux du jour enregistré
- [ ] Utilisateurs créés avec le bon rôle ; aucun mot de passe partagé
- [ ] Première collecte et première clôture faites dans Waumini
- [ ] Bilan avec chaque pilote à la fin du premier mois
