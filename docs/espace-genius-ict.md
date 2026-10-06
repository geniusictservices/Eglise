# Espace Genius ICT : guide de l'équipe

L'espace d'administration de Waumini se trouve à l'adresse **https://waumini.com/admin**. Il est réservé à l'équipe Genius ICT. Les communautés n'y ont pas accès.

Une personne de l'équipe qui n'a pas de communauté arrive directement dans cet espace après sa connexion. Si elle a aussi un rôle dans une église, elle passe de l'un à l'autre par son menu (« Espace Genius ICT » / « Revenir à ma communauté »).

## Les rôles de l'équipe

| Rôle | Ce qu'il peut faire |
|---|---|
| **Direction** | Tout, y compris gérer l'équipe. |
| **Commercial et facturation** | Voir les communautés, enregistrer les paiements, prolonger un essai, fixer les tarifs. |
| **Support** | Voir les communautés et les demandes de démonstration, répondre aux tickets, ouvrir une communauté qui l'a autorisé. |
| **Contenus et juridique** | Modifier les textes juridiques, les coordonnées et les réglages. |

Le premier compte Direction se crée sur le serveur : `php artisan waumini:equipe 0812345678 "Prénom Nom"`. Les suivants s'ajoutent dans **Équipe Genius ICT**.

## Vue d'ensemble

Les chiffres du moment : communautés inscrites et abonnées, essais en cours, communautés à régulariser (délai de grâce ou lecture seule), montant encaissé dans le mois.

Le bloc **À traiter** liste les **paiements déclarés** par les communautés et les **tickets** en attente d'une réponse. En dessous : les renouvellements des 30 prochains jours, les essais qui finissent dans la semaine, les dernières inscriptions et les demandes de démonstration (avec un lien WhatsApp).

La **cloche** en haut à droite compte les nouveautés de l'équipe : un paiement déclaré (pour le commercial), une nouvelle demande ou une réponse d'une communauté (pour le support). Elles arrivent aussi sur le téléphone, comme pour les communautés.

## Offres et tarifs

- **Modifier les tarifs** : changez les prix voulus dans la grille, choisissez la **date d'effet** (aujourd'hui ou plus tard), ajoutez un motif, enregistrez. Seuls les prix modifiés créent un nouveau tarif ; l'historique est conservé.
- **La règle** : un nouveau tarif s'applique à partir de sa date d'effet aux communautés qui ne sont pas encore abonnées. Les communautés abonnées gardent leur prix jusqu'à la fin de leur période ; le nouveau tarif s'applique à leur prochain renouvellement. Elles voient l'annonce dans leur écran Abonnement.
- Un changement annoncé (date d'effet à venir) peut être annulé tant que la date n'est pas arrivée.
- Le nom, le sens, la description et le contenu de chaque offre se modifient en touchant son nom. Une offre peut être « sur devis » ou masquée.
- **Facturation** : les libellés des tailles de communauté et le nombre de mois offerts pour un paiement annuel.

## Communautés et paiements

La liste montre les églises indépendantes et les sièges, avec leur état (essai, abonnée, délai de grâce, lecture seule, suspendue) et leur offre.

Dans la fiche d'une communauté :

- **Paiements déclarés à vérifier** (en haut, en orange) : la communauté a payé par mobile money et déclaré son paiement depuis son écran Abonnement, avec l'offre, la taille, la durée et l'**ID de la transaction**. Waumini rappelle le montant **attendu** (en rouge si la somme envoyée est plus petite). Vérifiez sur le téléphone de Genius ICT que l'argent est arrivé avec cet ID, puis :
  - **Valider** : la période d'abonnement est enregistrée, exactement comme un paiement saisi à la main, et la communauté est prévenue ;
  - **Rejeter**, avec un motif envoyé à la communauté (« Aucun paiement reçu avec cet ID »).
  Une même référence ne peut pas être déclarée deux fois.
- **Enregistrer un paiement** : offre, taille, durée (mensuelle ou annuelle), moyen de paiement et **référence de la transaction**. Sans date de début, la période suit celle en cours (renouvellement) ou commence aujourd'hui. Waumini calcule le montant avec le tarif en vigueur au début de la période ; ce prix reste figé. Pour une offre sur devis, saisissez le prix mensuel convenu.
- **Prolonger l'essai** de quelques jours.
- **Suspendre** une communauté (elle passe en lecture seule) ou la réactiver.

Chaque matin, Waumini met à jour l'état de toutes les communautés : fin d'essai, délai de grâce, lecture seule.

## Tickets de support

**Tickets de support** (rôles Support et Direction). Une communauté écrit depuis **Administration › Support Genius ICT** : un sujet, une catégorie (question, problème, données, abonnement, idée) et son message. Chaque demande reçoit un numéro (`T2026-0001`).

- Les onglets : **À traiter** (la communauté attend une réponse), **En attente de la communauté** (vous avez répondu), **Réglés**, **Tous** ; la case **Pris en charge par moi**.
- Dans un ticket : l'échange, la communauté, la personne qui a écrit (avec son numéro WhatsApp), et qui s'en occupe. **Je m'en occupe** le prend en charge ; répondre le fait aussi.
- **Envoyer** prévient la personne dans ses nouveautés. Sa réponse revient vers celui qui s'occupe du ticket (ou vers tout le support si personne ne s'en occupe).
- **Marquer comme réglé** clôt le ticket ; la communauté peut aussi le faire. Un nouveau message le rouvre.

## Accès du support à une communauté

Une communauté peut autoriser le support à **voir** son espace, pour 1, 3 ou 7 jours (Paramètres › Support), et retirer cet accord à tout moment.

- Dans la fiche de la communauté, le bloc **Accès du support** indique jusqu'à quand l'accès est autorisé, et pour quel niveau. **Ouvrir** fait entrer l'agent dans la communauté.
- L'agent voit la communauté **en lecture seule** : un bandeau sombre le rappelle en haut de chaque écran. Il voit le registre, les finances, les rapports, le budget et la paie ; **jamais** le suivi pastoral ni les données sensibles des membres. Il ne peut **rien modifier**.
- **Quitter**, dans le bandeau, le ramène dans l'espace Genius ICT.
- Chaque ouverture et chaque fermeture sont inscrites au **journal d'audit** de la communauté, avec le nom de l'agent ; la communauté les voit aussi dans Paramètres › Support.
- Si la communauté retire son accord, ou s'il expire, l'agent est renvoyé à l'espace Genius ICT dès son prochain clic.

## Sauvegardes

**Sauvegardes** (rôle Direction). Chaque nuit à 2 h 15, Waumini sauvegarde toute la base et les fichiers envoyés dans une archive chiffrée ; les 14 dernières sont gardées. L'écran montre les archives, avec leur date et leur taille :

- **Télécharger** une archive : à faire **chaque semaine**, pour en garder une copie hors du serveur (sauf si une copie distante automatique est réglée) ;
- **Sauvegarder maintenant** : avant une mise à jour, une reprise de données, une manipulation délicate.

Deux cadres rappellent si les archives sont **chiffrées** (mot de passe `BACKUP_PASSWORD`, à garder précieusement hors du serveur : sans lui, une archive est illisible) et si elles sont **copiées hors du serveur**. La restauration est décrite dans le [guide de déploiement](deploiement-lws.md#restaurer).

## Textes juridiques

Les conditions d'utilisation et la politique de confidentialité publiées sur waumini.com. Les textes de départ citent le droit congolais (Constitution, Code du numérique du 13 mars 2023, loi du 9 juillet 2018 sur la protection du consommateur, droit OHADA) : **faites-les relire par un juriste**.

Pour modifier un texte : **Modifier**, corrigez le texte (en Markdown), vérifiez dans **Aperçu**, indiquez ce qui change, puis **Publier**. Un **brouillon** n'est pas visible du public. Chaque publication crée une version datée ; l'historique reste consultable. La version acceptée par chaque administrateur à l'inscription est enregistrée.

## Coordonnées et réglages

- Le **numéro WhatsApp** et l'**e-mail** de Genius ICT : ils apparaissent sur le site public, dans l'écran Abonnement des communautés et dans les modèles Excel.
- **Où payer l'abonnement** : les numéros mobile money de Genius ICT (« M-Pesa 0812 … · Airtel Money 0970 … »), montrés aux communautés au moment de déclarer leur paiement.
- La durée de l'**essai gratuit** (nouvelles inscriptions) et du **délai de grâce**.
