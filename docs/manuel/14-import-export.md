# 14. Importer depuis Excel et exporter

Beaucoup d'églises tiennent déjà leur registre sur papier ou dans un fichier Excel. Waumini permet de le **reprendre en une fois**, avec l'aide de Genius ICT si besoin. Rien n'est enregistré avant que vous ayez vérifié chaque ligne, et un import peut être **annulé** pendant 30 jours.

> Permissions nécessaires : **Importer des données depuis Excel** pour importer, **Exporter le registre** pour exporter. Par défaut, l'administrateur et le secrétaire ont les deux.

## 1. Préparer le fichier

<table><tr>
<td width="68%"><img src="captures/bureau/34-import-modele.png" alt="Import : préparer le fichier sur ordinateur"></td>
<td width="32%"><img src="captures/mobile/34-import-modele.png" alt="Import : préparer le fichier sur téléphone"></td>
</tr></table>

Ouvrez **Membres › Excel › Importer depuis Excel**.

① Téléchargez le **modèle Excel du registre**. Il est fait pour votre communauté : il contient vos statuts, vos champs propres (cellule de prière…), des **listes déroulantes** (sexe, statut, état civil, place dans le ménage) et une feuille **Mode d'emploi**.

Recopiez votre registre dans la feuille « Membres », **une ligne par personne** :

- seul le **nom** est obligatoire ;
- les dates s'écrivent **JJ/MM/AAAA** (07/12/1973). Une année seule (1973) est acceptée et enregistrée au 1er janvier ;
- les téléphones s'écrivent comme d'habitude : 0812 345 678 ou +243 812 345 678 ;
- **Numéro de membre** : laissez vide pour que Waumini l'attribue, ou recopiez celui de l'ancien registre ;
- **Ménage** : écrivez le même nom de ménage (« Famille MUMBERE ») sur la ligne de chaque personne de la famille, avec sa **place dans le ménage** ;
- **Date de baptême** et **N° registre de baptême** créent l'étape « Baptême » dans le parcours de la personne.

Vous pouvez aussi envoyer votre propre fichier : Waumini reconnaît les titres courants (Noms, Post-nom, Tél., Genre, Né le…). Les colonnes qu'il ne reconnaît pas sont simplement ignorées.

② Envoyez le fichier rempli (Excel, OpenDocument ou CSV, jusqu'à 5 000 lignes). La vérification commence aussitôt.

## 2. Vérifier

<table><tr>
<td width="68%"><img src="captures/bureau/35-import-verification.png" alt="Import : vérification ligne par ligne sur ordinateur"></td>
<td width="32%"><img src="captures/mobile/35-import-verification.png" alt="Import : vérification ligne par ligne sur téléphone"></td>
</tr></table>

① Le résumé : les lignes **prêtes à importer**, les **doublons possibles** (la personne semble déjà inscrite, ou apparaît deux fois dans le fichier), les lignes **avec erreurs** et le nombre de ménages. Touchez un chiffre pour ne voir que ces lignes.

② Chaque ligne indique son numéro dans le fichier (L. 6) et ce qui ne va pas : date impossible, téléphone incomplet, statut inconnu, numéro déjà attribué… Les remarques en gris ne bloquent pas (une année seule, par exemple).

Pour corriger, modifiez le fichier dans Excel puis touchez **Envoyer un autre fichier**. Vous pouvez aussi importer tout de suite les lignes prêtes : les lignes en erreur sont ignorées, et vous les importerez plus tard.

Les **doublons possibles** ne sont pas importés, sauf si vous cochez **Importer aussi les doublons possibles** (quand ce sont bien d'autres personnes).

③ Touchez **Importer**, puis confirmez.

## 3. Importer et, si besoin, annuler

Waumini crée les fiches, attribue les numéros, regroupe les ménages et ajoute les baptêmes. Le [journal d'audit](09-journal.md) garde une ligne pour l'import.

En bas de l'écran d'import, la liste des **imports précédents** permet d'**annuler un import** pendant 30 jours : les membres importés et les ménages créés sont retirés du registre, même s'ils ont été modifiés depuis. Les numéros redeviennent libres.

## Exporter le registre

Dans **Membres**, le menu **Excel › Exporter le registre** télécharge la liste des membres dans un fichier Excel, **avec les mêmes colonnes que le modèle**. Si vous avez fait une recherche ou choisi des filtres (un quartier, un département…), seule la sélection est exportée.

Les champs sensibles ne sont exportés que pour les personnes autorisées à les voir. Un fichier exporté contient des données personnelles : gardez-le en lieu sûr et ne le partagez pas.
