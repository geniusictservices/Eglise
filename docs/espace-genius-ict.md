# Espace Genius ICT : guide de l'équipe

L'espace d'administration de Waumini se trouve à l'adresse **https://waumini.com/admin**. Il est réservé à l'équipe Genius ICT. Les communautés n'y ont pas accès.

Une personne de l'équipe qui n'a pas de communauté arrive directement dans cet espace après sa connexion. Si elle a aussi un rôle dans une église, elle passe de l'un à l'autre par son menu (« Espace Genius ICT » / « Revenir à ma communauté »).

## Les rôles de l'équipe

| Rôle | Ce qu'il peut faire |
|---|---|
| **Direction** | Tout, y compris gérer l'équipe. |
| **Commercial et facturation** | Voir les communautés, enregistrer les paiements, prolonger un essai, fixer les tarifs. |
| **Support** | Voir les communautés et les demandes de démonstration. |
| **Contenus et juridique** | Modifier les textes juridiques, les coordonnées et les réglages. |

Le premier compte Direction se crée sur le serveur : `php artisan waumini:equipe 0812345678 "Prénom Nom"`. Les suivants s'ajoutent dans **Équipe Genius ICT**.

## Vue d'ensemble

Les chiffres du moment : communautés inscrites et abonnées, essais en cours, communautés à régulariser (délai de grâce ou lecture seule), montant encaissé dans le mois. En dessous : les renouvellements des 30 prochains jours, les essais qui finissent dans la semaine, les dernières inscriptions et les demandes de démonstration (avec un lien WhatsApp).

## Offres et tarifs

- **Modifier les tarifs** : changez les prix voulus dans la grille, choisissez la **date d'effet** (aujourd'hui ou plus tard), ajoutez un motif, enregistrez. Seuls les prix modifiés créent un nouveau tarif ; l'historique est conservé.
- **La règle** : un nouveau tarif s'applique à partir de sa date d'effet aux communautés qui ne sont pas encore abonnées. Les communautés abonnées gardent leur prix jusqu'à la fin de leur période ; le nouveau tarif s'applique à leur prochain renouvellement. Elles voient l'annonce dans leur écran Abonnement.
- Un changement annoncé (date d'effet à venir) peut être annulé tant que la date n'est pas arrivée.
- Le nom, le sens, la description et le contenu de chaque offre se modifient en touchant son nom. Une offre peut être « sur devis » ou masquée.
- **Facturation** : les libellés des tailles de communauté et le nombre de mois offerts pour un paiement annuel.

## Communautés et paiements

La liste montre les églises indépendantes et les sièges, avec leur état (essai, abonnée, délai de grâce, lecture seule, suspendue) et leur offre.

Dans la fiche d'une communauté :

- **Enregistrer un paiement** : offre, taille, durée (mensuelle ou annuelle), moyen de paiement et **référence de la transaction**. Sans date de début, la période suit celle en cours (renouvellement) ou commence aujourd'hui. Waumini calcule le montant avec le tarif en vigueur au début de la période ; ce prix reste figé. Pour une offre sur devis, saisissez le prix mensuel convenu.
- **Prolonger l'essai** de quelques jours.
- **Suspendre** une communauté (elle passe en lecture seule) ou la réactiver.

Chaque matin, Waumini met à jour l'état de toutes les communautés : fin d'essai, délai de grâce, lecture seule.

## Textes juridiques

Les conditions d'utilisation et la politique de confidentialité publiées sur waumini.com. Les textes de départ citent le droit congolais (Constitution, Code du numérique du 13 mars 2023, loi du 9 juillet 2018 sur la protection du consommateur, droit OHADA) : **faites-les relire par un juriste**.

Pour modifier un texte : **Modifier**, corrigez le texte (en Markdown), vérifiez dans **Aperçu**, indiquez ce qui change, puis **Publier**. Un **brouillon** n'est pas visible du public. Chaque publication crée une version datée ; l'historique reste consultable. La version acceptée par chaque administrateur à l'inscription est enregistrée.

## Coordonnées et réglages

- Le **numéro WhatsApp** et l'**e-mail** de Genius ICT : ils apparaissent sur le site public, dans l'écran Abonnement des communautés et dans les modèles Excel.
- La durée de l'**essai gratuit** (nouvelles inscriptions) et du **délai de grâce**.
