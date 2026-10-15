# Évaluation du périmètre financier de Waumini — 14 octobre 2026

Évaluation fonctionnelle et technique, en lecture seule, de tout ce qui touche à l'argent dans Waumini : caisses et devises, recettes et reçus, collecte du culte, virements et change, journal, dépenses et avances, promesses de dons, paiements déclarés par mobile money, clôtures et rapports, budget, projets, quotes-parts, consolidation, paie et avances sur salaire, abonnement à la plateforme. Chaque module a été revu sur six axes : fonctionnement réel, exactitude des calculs, cas limites, complétude par rapport aux bonnes pratiques d'une trésorerie d'église, expérience utilisateur dans les écrans, couverture de tests. Les constats de sécurité relevés dans `docs/audit-2026-10-14.md` ne sont pas répétés ici, sauf nuance nouvelle.

## 1. Verdict

Le cœur financier est juste et bien protégé. Les soldes sont dérivés des opérations, jamais stockés. Chaque opération fige son taux et son équivalent en dollars. Les sorties d'argent sont faites sous verrou, avec contrôle du solde le jour même et les jours suivants. Les clôtures verrouillent réellement les saisies, les annulations et les virements. Les documents (collecte, dépense, paie, avance, quote-part, versement de projet) possèdent leurs opérations et sont seuls à pouvoir les annuler, ce qui évite les doubles comptages. Les tests couvrent les chemins nominaux de tous les modules sur une vraie base MariaDB.

Les faiblesses tiennent en cinq familles :

1. **Promesses du manuel non tenues** : refus d'un ID mobile money déjà enregistré, taux réel dans le libellé d'un change, autosave « enregistré au fur et à mesure » de la collecte, « disparaît des saisies » pour une devise désactivée.
2. **Couplages fragiles** : les catégories système (salaires, quotes-parts, versements au siège) sont retrouvées par leur nom, que l'utilisateur peut renommer. Un renommage désactive silencieusement le contrôle budgétaire de la paie et le dédoublonnage de la consolidation.
3. **Bugs de cohérence** : retenues faussées lors du paiement d'une paie, run de paie bloqué en « approuvé », excédent du budget gonflé par le solde reporté, échéancier de promesse affiché différent de celui calculé, pièces de dépense enregistrées même si la justification échoue.
4. **Boucles de retour absentes** : le donateur qui déclare un paiement n'est jamais informé du résultat, le bénéficiaire d'une avance en retard n'est pas relancé, la paroisse ne voit pas qu'un versement au siège attend confirmation.
5. **Fonctions attendues d'une trésorerie absentes** : rapprochement bancaire et comptage physique, pièces jointes sur recettes, différence de change, relevé annuel par membre côté trésorier, historique de paie par bénéficiaire, alertes de seuil budgétaire, exports du journal et de la consolidation.

Notes de maturité (sur 5) :

| Module | Note | En une phrase |
|---|---|---|
| Collecte du culte | 4 | Le module le plus abouti : double comptage, écart bloquant, verrou, procès-verbal signé. |
| Clôtures | 4 | Verrouillage solide et testé, mais l'archivage reste virtuel. |
| Élaboration du budget | 4 | Machine à états propre, imports idempotents, séparation présentation/approbation. |
| Circuit de dépense | 3,5 | Solide et auditable, sans seuil ni voie rapide pour les petites dépenses. |
| Suivi budgétaire | 3,5 | Contrôle réel, fragilisé par le couplage par nom de catégorie. |
| Projets locaux | 3,5 | Modèle financier cohérent, effets de bord au rendu. |
| Consolidation | 3,5 | Agrégation correcte et exclusions testées, pas d'export. |
| Abonnement plateforme | 3,5 | Cycle de vie et tarif figé bien faits, palier auto-déclaré. |
| Caisses, devises, taux | 3 | Modèle multi-devises sain, mais ouverture, fermeture et correction de taux hors logique comptable. |
| Recettes, reçus, virements, journal | 3 | Flux solides, ID mobile money non unique, pas de différence de change ni d'export du journal. |
| Rapports et tableau de bord | 3 | Rapport exact et imprimable, ventilations incomplètes, comptes fermés traités différemment. |
| Avances et justification | 3 | Mécanique correcte, pas de suivi des avances ouvertes ni de justification partielle. |
| Promesses de dons | 3 | Calculs rigoureux, index non scalable, relance minimale. |
| Paiements déclarés | 3 | Rapprochement propre, donateur jamais informé, montant jamais contrôlé. |
| Sources de recettes budgétées | 3 | Idée claire et testée, deux définitions divergentes de l'argent d'un projet. |
| Projets du siège (relais) | 3 | Flux complet, montants USD divergents entre paroisse et siège. |
| Quotes-parts | 3 | Simple et fonctionnel, règle sans historique, arriérés invisibles. |
| Paie et avances | 3 | Circuit bien pensé et testé, un bug de retenues, confidentialité des salaires poreuse. |

## 2. Architecture financière

Tout passe par un seul grand livre, `app/Services/Ledger.php`. Une opération (`finance_transactions`) a un type parmi six : recette, dépense, virement entrant ou sortant, change entrant ou sortant. Elle porte son compte, sa devise, son montant arrondi aux décimales de la devise, le taux du jour et l'équivalent en dollars figés, sa date, son auteur, et des liens optionnels vers un membre, un département, une catégorie, une feuille de collecte, une demande de dépense, un bulletin de paie, un projet, une promesse. Elle n'est jamais modifiée ni supprimée : elle est annulée par marquage, avec motif et auteur. Le solde d'un compte dans une devise est toujours recalculé : solde d'ouverture plus entrées moins sorties, opérations annulées exclues.

La convention de taux est cohérente partout : le taux est le nombre d'unités de devise pour un dollar, l'équivalent en dollars est le montant divisé par le taux. Une paroisse sans taux propre hérite de celui de son niveau supérieur.

Autour du grand livre, chaque document métier (feuille de collecte, demande de dépense, run de paie, avance, versement de quote-part, versement de projet, paiement déclaré, promesse) crée ses opérations au moment voulu et est le seul autorisé à les annuler. Les clôtures mensuelles et annuelles bloquent toute écriture dans la période, y compris les annulations.

## 3. Caisses, devises et taux

**Fonctionnement.** Un compte (espèces, mobile money, banque) tient plusieurs devises, chacune avec un solde d'ouverture, une date d'ouverture et un drapeau actif. Les devises de l'organisation s'ajoutent à l'USD. Un taux est saisi par devise et par date, l'historique est conservé, et le taux applicable est le dernier connu à la date de l'opération.

**Points forts.** Convention de taux unique, héritage hiérarchique, arrondi par devise (CDF sans décimale), verrou sur la ligne de devise lors des sorties, modal de compte en un seul écran.

**Constats.**

- **Moyenne. La date d'ouverture n'a aucun effet comptable.** `Ledger::balance` inclut le solde d'ouverture à toute date et `record()` accepte une opération antidatée avant l'ouverture. Seul `Closings::firstMonth` l'utilise. Un rapport d'un mois antérieur à l'ouverture affiche déjà le solde de départ.
- **Moyenne. Un compte fermé avec solde disparaît du tableau de bord mais reste dans le rapport.** `Finances/Settings.php:152` accepte la fermeture sans contrôle ; `Ledger::balances` exclut les comptes inactifs alors que `FinanceReports::period` les garde tant que le solde n'est pas nul. La « trésorerie totale » baisse silencieusement.
- **Moyenne. Désactiver une devise de l'organisation ne la retire pas des saisies.** `Currencies/Index::toggle` ne touche pas aux devises des comptes ; `IncomeForm::accountCurrencies` et `TransferForm::options` lisent seulement les devises des comptes. Le manuel du chapitre 07 promet le contraire. Pire, la ligne de devise devient invisible dans le modal du compte tout en restant active.
- **Moyenne. Corriger un taux passé ne recalcule rien.** `setRate` écrase le taux, mais `usd_amount` et `rate` figés sur les opérations restent à l'ancienne valeur. Journal, rapports et clôtures gardent l'ancien taux, les soldes du tableau de bord passent au nouveau. Le manuel invite pourtant à « corriger un oubli d'hier ».
- **Moyenne. Aucun contrôle de vraisemblance du taux.** `Currencies/Index.php:44` accepte de 0,01 à 100 000 000. Saisir 28,5 au lieu de 2 850 fige des équivalents USD cent fois trop élevés sur toutes les opérations du jour. Un écart supérieur à un seuil par rapport au taux précédent ou à celui du parent devrait alerter.
- **Faible.** Taux absent à une date passée : le message dit « saisissez le taux du jour » alors qu'un taux existe aujourd'hui, sans indiquer la date manquante. Date d'ouverture future acceptée. Solde d'ouverture négatif impossible, donc pas de découvert bancaire représentable.

**Manques.** Rapprochement bancaire, comptage physique hors collecte et écriture d'écart ou d'ajustement, bordereau de dépôt, plafond de caisse.

**Tests.** Couverts : compte multi-devises, devise non tenue, taux manquant, conversions, héritage. Non couverts : désactivation de devise, fermeture d'un compte avec solde, date d'ouverture, correction de taux.

## 4. Recettes, reçus, virements, journal

**Fonctionnement.** Une recette se saisit en six à huit champs : compte, devise, catégorie (dont la nature impose un membre, un donateur de passage ou un département), montant, date, moyen de paiement, ID de transaction obligatoire pour le mobile money. Elle reçoit un numéro de reçu séquentiel par organisation et par année. Le reçu s'imprime en A4 ou en ticket thermique, avec mention « annulé » le cas échéant. Un virement entre comptes dans la même devise crée deux opérations liées ; dès que la devise change, c'est un change avec montant reçu obligatoire. Le journal liste un mois civil, filtrable, paginé, avec annulation motivée.

**Points forts.** Saisie en série (compte, catégorie et date conservés après enregistrement), recherche de membre en direct, reçu accessible par le membre lui-même, noms masqués sans la permission des contributions nominatives, virements et changes neutres dans les rapports, annulation groupée des deux jambes.

**Constats.**

- **Élevée. L'ID mobile money n'est pas unique.** Le manuel (chapitre 16, « Enregistrer une recette ») promet que Waumini refuse un ID déjà enregistré. `IncomeForm.php:105` ne pose aucune règle d'unicité et la migration ne crée aucun index. Seules les déclarations de paiement vérifient les doublons. Un même paiement M-Pesa peut être saisi deux fois.
- **Moyenne. Pas d'écriture de différence de change.** Chaque jambe d'un change porte son équivalent USD au taux officiel de sa devise ; pour un change à taux pratiqué différent, les deux équivalents diffèrent. La trésorerie USD du tableau de bord bouge sans trace de gain ou de perte.
- **Faible.** Le manuel annonce « le taux réel du change apparaît dans le libellé » ; `Ledger.php:157-159` écrit seulement « Change USD → CDF ». Numérotation des reçus par année de la date d'opération : une recette antidatée reçoit le prochain numéro de l'année passée, et l'ordre des numéros ne suit pas l'ordre des dates. `Ledger::record` et `Ledger::transfer` n'exigent pas un compte actif, seule la vue filtre. Le champ montant accepte des centimes en CDF que l'arrondi efface sans avertir. Les erreurs de clôture ou de taux sont affichées sous le champ montant même quand la cause est la date.

**Manques.** Pièces justificatives sur recettes (seules les dépenses ont des pièces jointes), relevé annuel et attestation par membre côté trésorier (le membre voit ses trente derniers dons dans son espace, rien dans la fiche membre), export ou impression du journal, solde progressif par compte, frais de virement intégrés.

**Tests.** Couverts : reçu et équivalent USD, masquage des noms, change interne, virement, solde insuffisant, annulation des deux côtés, clôture. Non couverts : ID dupliqué, antidatage avant ouverture, formats thermiques, filtres et totaux du journal.

## 5. Collecte du culte

**Fonctionnement.** Une feuille brouillon est créée pour le dimanche courant sur la première caisse espèces. On y saisit le comptage par billets et pièces, les montants de la boîte par catégorie collective et par devise, les enveloppes nominatives, et au moins un compteur. La validation exige un écart nul entre compté et déclaré par devise, relit la feuille sous verrou, puis crée une recette par ligne et par enveloppe. Un procès-verbal imprimable porte les signatures des compteurs et du trésorier. L'annulation annule les recettes.

**Points forts.** Écart bloquant, verrou contre la double validation, enveloppes rattachées au membre, procès-verbal, focus qui revient sur la recherche après chaque enveloppe.

**Constats.**

- **Moyenne. L'autosave échoue en silence.** `Sheet::updated` valide à chaque champ quitté, mais les erreurs (date future, lignes invalides) ne sont affichées nulle part dans la vue, qui ne gère que les erreurs de comptage. L'utilisateur croit sa saisie enregistrée.
- **Moyenne. Changer de compte après saisie supprime des lignes.** `Sheet::updated` recrée les lignes en filtrant sur les devises du nouveau compte et supprime les autres sans message, tandis que les enveloppes dans ces devises restent et font échouer la validation.
- **Faible.** Les lignes sont stockées sans arrondi alors que le grand livre arrondit le CDF à l'unité : le total enregistré peut différer du déclaré. Le calcul du compteur utilisé passe par des flottants. La règle « deux compteurs au moins » affichée n'est pas imposée (le code exige un). Un compte fermé entre le brouillon et la validation n'est pas détecté. Le dimanche est la date par défaut, à corriger pour un culte du samedi.

**Manques.** Nombre de contributeurs, comparaison avec les dimanches précédents, lien vers le dépôt bancaire.

**Expérience utilisateur.** Création en un clic ; billets sur quinze champs pour deux devises ; chaque champ quitté déclenche une requête et un rendu complet, ce qui peut être lent sur réseau mobile faible.

**Tests.** Flux complet, écart bloquant, annulation. Non couverts : changement de compte, donateur de passage, suppression de brouillon.

## 6. Dépenses, avances, pièces jointes, notifications

**Fonctionnement.** Une demande numérotée `D-AAAA-NNNN` suit la chaîne soumise → contrôlée → approuvée → décaissée → justifiée, avec refus ou annulation possibles. Le nombre de signatures requises (une à trois) est figé à la soumission. Le contrôle et le décaissement relèvent de la permission de décaissement, l'approbation d'une permission distincte, et le demandeur ne peut pas signer. Le contrôle bloque si le budget ou le projet n'a pas la marge. Le décaissement crée la dépense dans la devise de la demande. La justification enregistre le montant dépensé et crée automatiquement une recette « retour sur avance » pour le reste. Le circuit notifie à chaque étape les porteurs de la permission suivante.

**Points forts.** Numérotation sous verrou, réglage figé par demande, demande non modifiable après soumission, pièces jointes à toute étape, blocage des nouvelles avances quand une avance est en retard, notifications remplacées plutôt qu'empilées.

**Constats.**

- **Moyenne. Les pièces sont enregistrées avant la validation métier.** `Expenses/Show.php:110` stocke les fichiers puis appelle `justify()` ; si celui-ci échoue (montant supérieur à l'avance), les fichiers sont déjà créés et le champ n'est pas vidé, donc un second clic les enregistre en double.
- **Moyenne. Le contrôleur peut aussi signer.** `canSign` exclut seulement le demandeur. Rien n'empêche la personne qui a contrôlé de signer si elle a aussi la permission d'approuver, cas de l'administrateur par défaut.
- **Moyenne. Justification tout-ou-rien.** Une seule justification ferme l'avance. Pas de dépôt de factures en plusieurs fois, pas de distinction entre « justifié par factures » et « rendu en caisse » : le retour d'avance est automatique sans confirmation que l'argent est revenu. Un dépassement payé de sa poche par le bénéficiaire ne peut être ni enregistré ni remboursé.
- **Moyenne. Aucune relance d'avance en retard.** Seuls un compteur et un onglet existent ; aucune tâche planifiée ni notification à l'échéance.
- **Moyenne. Pas de cycle de vie des pièces.** Aucune suppression ni remplacement, aucune trace d'audit ; toutes les pièces du formulaire initial sont typées « devis ».
- **Moyenne. Silence du circuit sur deux étapes.** Aucune notification à l'annulation (le demandeur n'apprend pas que l'argent est repris) ni à la justification. Les signatures intermédiaires ne sont pas annoncées.
- **Faible.** Le blocage des avances en retard compare un nom libre de bénéficiaire, contournable par une variante. La justification d'une dépense directe n'exige aucune pièce. Les demandes annulées n'ont pas d'onglet. L'index est limité à cent lignes sans pagination. Le service `disburse` n'exige pas un compte actif.

**Manques.** Régime simplifié pour les petites dépenses (cinq dollars de transport exigent cinq actions par trois personnes), seuil de montant déclenchant plus de signatures, pièces obligatoires au-dessus d'un montant, dépenses récurrentes (loyer, électricité), référentiel fournisseurs, tableau des avances ouvertes par bénéficiaire avec ancienneté, bon de commande imprimable. Les retenues fiscales ne sont pas attendues dans ce module.

**Tests.** Couverts : circuit complet à deux signatures, auto-approbation interdite, réglage un à trois, refus motivé, retour d'avance, blocage, annulation après décaissement. Non couverts : héritage siège/paroisse du réglage, solde insuffisant au décaissement, contrôleur signataire, doublon de pièces, destinataires des notifications (aucun test).

## 7. Promesses de dons

**Fonctionnement.** Une promesse (membre, ménage, département ou autre) porte un montant, une devise, un échéancier (parts arrondies vers le bas, dernière part égale au solde) et un projet facultatif. Un versement crée une recette dans la devise saisie et convertit vers la devise de la promesse via le dollar au taux du jour du versement. Un don en nature est enregistré avec sa valeur. La relance est un message WhatsApp prérempli ouvert depuis le téléphone de l'utilisateur, tracé au clic.

**Points forts.** Calculs en décimal exact, conversion testée, fiche riche (anneau de progression, échéancier, reçus), statut remis à « active » si un versement est annulé.

**Constats.**

- **Élevée (performance). La liste charge toutes les promesses en mémoire.** `Pledges/Index.php:57-72` récupère toutes les promesses filtrées, calcule la progression de chacune (deux sommes SQL par promesse), filtre les retards en PHP puis pagine avec `forPage`. Quelques centaines de promesses ralentissent, quelques milliers bloquent.
- **Moyenne. L'échéancier affiché ne correspond pas à celui calculé.** `Pledges/Show.php` recalcule en flottants (montant divisé par le nombre d'échéances) alors que `expected()` arrondit vers le bas avec solde sur la dernière. Pour 600 dollars en sept échéances, la vue affiche 85,71 par ligne et une dernière échéance fausse.
- **Moyenne. La relance est tracée au clic, pas à l'envoi.** Fermer WhatsApp sans envoyer laisse une relance enregistrée. Pas de relance groupée, pas de « dernière relance il y a plus de trente jours », pas de lettre imprimable.
- **Moyenne.** Première échéance non bornée (peut précéder la date de promesse ou tomber dans dix ans), jusqu'à 520 échéances hebdomadaires recalculées à chaque rendu.
- **Faible.** Versement d'un ménage sans imputation à aucun membre, donc absent des relevés individuels. Don en nature à valeur zéro accepté. Promesse créable sur un projet terminé sans alerte. Saisie antidatée sans taux à cette date : le taux courant est pris silencieusement.

**Tests.** Couverts : échéances mensuelles, conversion CDF, don en nature, relance, filtre retard. Non couverts : annulation de promesse, annulation d'un versement, promesse hebdomadaire, ménage.

## 8. Paiements déclarés (mobile money)

**Fonctionnement.** Un paiement peut être déclaré par la finance, par un membre connecté depuis son espace, ou par un visiteur du site vitrine. L'ID est normalisé (majuscules sans espaces). La validation refuse les doublons (autres déclarations non rejetées, opérations valides de même référence), relit sous verrou, puis crée la recette avec reçu, ou alimente une promesse. Un compte est présélectionné selon l'opérateur. L'annulation de la recette dans le journal repasse la déclaration en « rejetée » avec le motif.

**Points forts.** Normalisation, détection de doublons, verrou, capture d'écran jointe côté finance, catégorie du projet appliquée automatiquement.

**Constats.**

- **Élevée. Le déclarant n'est jamais prévenu du résultat.** `CircuitNotices::declarationReviewed` ne fait que clore la notification interne. Le membre ne voit que « en cours » ou « non retrouvé » dans son espace ; aucun reçu, aucune notification ne lui parvient.
- **Moyenne. Recette validée sans catégorie possible.** Dans l'espace membre, l'option « Offrande » a une valeur vide ; la validation accepte `category_id` nul et la recette est invisible dans les rapports par catégorie. Même chose depuis le site vitrine si la catégorie n'est pas dans la liste autorisée.
- **Moyenne. Le montant n'est jamais contrôlé.** La finance valide le montant déclaré sans saisir le montant réellement reçu.
- **Moyenne.** Le membre ne peut pas joindre de capture depuis son espace, contrairement à ce que promet le chapitre 18 du manuel. La catégorie choisie côté membre n'est pas restreinte aux catégories personnelles côté serveur.
- **Faible.** Le doublon n'est détecté qu'à la validation, pas à la déclaration. Doublon bloquant sans dérogation possible. Liste limitée à cent déclarations par statut.

**Tests.** Couverts : saisie finance, capture, validation, doublon, rejet, promesse soldée. Non couverts : déclaration par le membre, validation en CDF, catégorie nulle, retour en « rejeté » après annulation.

## 9. Clôtures, rapports, tableau de bord

**Fonctionnement.** Les mois se clôturent dans l'ordre, une fois terminés, avec une liste de contrôle informative (feuilles brouillon, déclarations en attente, avances). La clôture écrit un instantané des totaux et des soldes. L'exercice se clôture quand ses douze mois le sont. La réouverture, motivée, rouvre en cascade les mois suivants et les exercices. Le rapport d'une période donne, par compte et devise, le solde d'ouverture, les recettes, les dépenses, les virements et le solde de clôture, puis les catégories, les dépenses par département et le mois par mois en dollars. Il s'imprime en A4 avec signatures et s'exporte en Excel.

**Points forts.** Verrouillage réel à chaque point d'écriture, cohérence par construction (ouverture + recettes − dépenses + virements = clôture), exclusion des annulées, exercice non calendaire géré et testé, badges « clôturé » ou « provisoire ».

**Constats.**

- **Moyenne. L'instantané de clôture n'est jamais relu.** Aucun code hors `Closings` ne lit `snapshot`. Le rapport d'une période clôturée est recalculé en direct ; un renommage de compte ou de catégorie, une fermeture de compte ou un changement de date d'ouverture modifient un rapport censé figé. L'archivage est latent.
- **Moyenne. Le fuseau horaire de l'organisation n'est jamais appliqué.** Les paramètres proposent Kinshasa, Nairobi ou Kampala, mais aucun code ne l'utilise. Tout « aujourd'hui » est en heure de Lubumbashi. Une église de Kinshasa ne peut pas dater une recette du jour entre 23 h et minuit ; une église de Nairobi entre 0 h et 1 h.
- **Faible.** L'impression du rapport prend l'année civile par défaut au lieu de l'exercice courant. L'export Excel impose deux décimales à toutes les colonnes, CDF compris. Le rapport par département ne ventile que les dépenses. La réouverture n'est conservée qu'une fois dans la clôture (l'historique complet est dans le journal d'audit). Le changement de début d'exercice n'est bloqué qu'après une clôture d'exercice, pas après des clôtures mensuelles.

**Tableau de bord.** Soldes par compte et devise, total par devise, équivalent USD au taux du jour, argent réservé aux projets, recettes et dépenses du mois civil, dernières opérations, alertes. Juste, mais aveugle aux comptes fermés avec solde, et sans aucun test sur les chiffres.

**Manques.** Procès-verbal de clôture signé, rapprochement obligatoire avant clôture, PDF serveur pour envoi et archivage, rapport de rapprochement, export du journal.

**Tests.** Couverts : ordre, verrouillage (saisie, virement, annulation, écran), réouverture en cascade, exercice juillet-juin, totaux d'un mois, impression et Excel. Non couverts : liste de contrôle, contenu de l'instantané, colonne virements, mois par mois, départements, contenu de l'Excel.

## 10. Budget

### Élaboration

**Fonctionnement.** Chaque département propose ses besoins (brouillon → envoyée, retour possible avec note), en USD ou en CDF converti au taux du jour. La finance prépare une version qui importe les propositions, la masse salariale habituelle et les projets, arbitre ligne par ligne, puis la présente. L'approbation par une autre personne l'adopte et remplace la version adoptée précédente. Une révision copie les lignes en nouvelle version ; une seule version en attente à la fois. La présentation exige un écart nul entre dépenses et ressources utilisables.

**Points forts.** Imports idempotents, impossibilité d'avoir deux budgets adoptés, le suivi suit automatiquement la version adoptée, impression A4 avec pourcentages et signatures.

**Constats.**

- **Moyenne. L'excédent affiché sur l'écran Budget est gonflé.** `budget/index.blade.php:22` calcule recettes moins dépenses en comptant le solde reporté comme une recette, alors que la version et l'impression utilisent le résumé des sources qui réserve ce montant aux projets.
- **Moyenne. Une dépense peut se rattacher à une ligne de n'importe quel exercice adopté.** `Expenses::budgetLink` ne filtre pas sur l'exercice de la demande ; le contrôle porte sur l'exercice de la date de besoin, où la ligne n'existe pas, d'où un « hors budget » incompréhensible.
- **Faible.** Une version brouillon est imprimable par URL avec le bloc signatures. Les propositions de départements désactivés sont reprises mais ne sont plus consultables. Une ligne de recette avec projet mais sans source est interprétée différemment par les deux services.

**Manques.** Comparaison avec l'année précédente, ventilation mensuelle, report de crédits hors projets, export Excel, indicateur d'avancement du cycle (« trois propositions sur sept reçues »), accès du responsable de département au budget adopté de son département.

### Suivi d'exécution et dépassements

**Fonctionnement.** Par clé département-catégorie : prévu (lignes adoptées plus dépassements autorisés moins transferts cédés), réalisé (dépenses décaissées au taux figé, retours d'avance déduits), engagé (demandes contrôlées ou approuvées et bulletins de paie non payés, au taux courant), disponible. Le contrôle bloque au contrôle de la dépense et à la présentation de la paie. Un dépassement se demande avec une source (transfert, réserves, nouvelle recette) et se décide par un tiers.

**Constats.**

- **Élevée. Les catégories système sont retrouvées par leur nom modifiable.** `BudgetControl::salaryCategoryId` cherche « Rémunérations et motivations », `Consolidation::QUOTA_CATEGORIES` cherche « Quotes-parts reçues » et « Quote-part versée au niveau supérieur », `ProjectNetwork::SENT_CATEGORY` cherche « Versements au siège pour les projets ». L'écran des réglages permet de renommer n'importe quelle catégorie et la table n'a aucune clé système. Un renommage désactive le contrôle budgétaire de la paie (qui passe alors sans contrôle), recrée une catégorie homonyme à l'import suivant, et fait compter deux fois les quotes-parts dans la consolidation.
- **Moyenne. Les recettes par département sont attribuées arbitrairement.** Une recette d'une catégorie budgétée par deux départements tombe sur la première ligne trouvée, car le formulaire de recette ne pose le département que pour les catégories de nature « groupe ».
- **Moyenne. Un dépassement est accordé par clé, pas par demande.** Autoriser un dépassement pour la demande X augmente le disponible de toute la clé, utilisable par une demande Y.
- **Faible.** Une demande avec date de besoin l'an prochain est contrôlée sur un budget généralement inexistant, donc sans contrôle. Les demandes seulement soumises ne sont pas engagées. Sans taux de change, l'engagé passe silencieusement à zéro pour la paie.

**Manques.** Alerte à 80 %, notification aux responsables, réservation de crédit dès la soumission, suivi mensuel, impression ou export du suivi. Un responsable de département n'a pas accès au suivi de ses propres lignes.

### Sources de recettes budgétées

**Fonctionnement.** Pour chaque projet, la part couverte par sa propre collecte et son report, la part « ordinaire » prise sur les recettes générales, et le reste réservé. La part ordinaire est débloquée au rythme des rentrées : proportionnellement au ratio recettes ordinaires réalisées sur prévues.

**Constats.**

- **Moyenne. Deux définitions de l'argent d'un projet.** Le report d'une année sur l'autre inclut la part ordinaire débloquée, alors que la protection des caisses et les versements au siège ne comptent que les transactions marquées du projet. Un projet dont la part ordinaire n'a pas été dépensée voit son report gonflé sans que l'argent soit réservé nulle part.
- **Moyenne.** Le ratio de rentrées est global : les quotes-parts reçues comptent dans le réalisé mais rarement dans le prévu, ce qui gonfle artificiellement le ratio pour un siège ou un secteur.

**Tests.** Couverts : proposition, arbitrage et approbation à deux, renvoi, révision, dépassement et décision, hors ligne, transfert limité, sans budget, écart bloquant, part ordinaire. Non couverts : abandon d'une version, approbation concurrente, exercice non calendaire, impression, retours d'avance dans le réalisé, repli des recettes, taux manquant.

## 11. Projets

### Projets locaux

**Fonctionnement.** Un projet porte un objectif ou des tranches annuelles (collecte et dépenses prévues), des indicateurs pondérés dont la moyenne donne l'avancement, et une catégorie de recette créée automatiquement. Son argent est suivi par le lien projet sur les opérations : recettes, dépenses, retours d'avance, engagé des demandes, promesses. Le statut (prévu, en cours, terminé) est déduit de l'avancement.

**Constats.**

- **Moyenne. Un simple affichage écrit en base.** `progressOf(..., sync: true)` est appelé dans le rendu de la liste et de la fiche : un GET met à jour l'avancement et le statut, y compris pour une organisation en lecture seule, et peut faire passer un projet à « terminé » sans action humaine, ce qui l'exclut ensuite des imports budgétaires.
- **Moyenne. Conversion silencieuse à zéro.** `Projects::usd` renvoie zéro si le taux manque (promesses ou engagé en CDF), ce qui surestime le disponible sans message, contrairement au contrôle budgétaire qui lève une erreur.
- **Faible.** Le statut modifié à la main est écrasé au rendu suivant si des indicateurs existent. Un projet en cours sans tranche pour l'exercice n'entre pas au budget et n'est pas signalé. Le compte dédié d'un projet est purement indicatif.

**Manques.** Budget détaillé par poste d'un projet, jalons financiers, impression de la fiche, export.

### Projets du siège portés par les paroisses

**Fonctionnement.** Le siège fixe une part par paroisse ; un projet relais est créé dans la paroisse avec objectif, tranches et deux indicateurs automatiques. La paroisse verse (dépense locale plafonnée à l'argent réservé du relais, remise « envoyée ») ; le siège confirme (recette au taux du siège à la date de réception, remise « reçue »).

**Constats.**

- **Moyenne. Équivalents USD divergents entre paroisse et siège.** Le montant et la devise sont identiques mais chaque organisation applique son taux à sa date. La vue d'ensemble du siège utilise le dollar de la paroisse, la fiche du projet parent celui du siège ; les deux chiffres « reçu » peuvent différer pour un versement en CDF.
- **Moyenne. Une remise envoyée mais jamais confirmée n'est signalée nulle part côté paroisse.** Aucun délai d'alerte, une seule notification au siège.
- **Faible.** La paroisse peut modifier son projet relais (objectif, tranches, statut), ce qui casse la vue d'ensemble. Le contrôle du plafond se fait en flottants, le grand livre recalcule en décimal.

**Tests.** Un seul scénario complet, en USD. Non couverts : CDF et taux divergents, retrait de part avec remises en attente, modification du relais.

## 12. Quotes-parts et consolidation

### Quotes-parts

**Fonctionnement.** Une règle par organisation (pourcentage ou montant fixe, à partir d'une période) s'applique à ses enfants directs. Le dû d'un mois civil est calculé sur les recettes valides hors projets, hors retours d'avance, hors catégories de quotes-parts. L'enfant verse (dépense « quote-part versée »), le parent confirme (recette « quotes-parts reçues » à son taux).

**Constats.**

- **Moyenne. La règle n'a pas d'historique.** Modifier le pourcentage en cours d'année recalcule tous les mois depuis le début avec le nouveau taux et rend rétroactivement « non à jour » des mois soldés.
- **Moyenne. Les arriérés de plus de six mois sont invisibles.** L'écran ne montre que les six derniers mois et une grille de trois ; aucun cumul « total dû moins total versé ».
- **Faible.** La période de versement n'est pas validée côté serveur (une valeur altérée provoque une erreur 500). La notion de « recette validée » du pitch n'existe pas : toute recette non annulée compte.

### Consolidation

**Fonctionnement.** Pour un sous-arbre, sommes des équivalents USD figés des recettes et dépenses valides, hors catégories de quotes-parts et hors les deux opérations de chaque remise de projet, plus effectifs, nouveaux membres, présence au culte, dernière saisie et retards de plus de quatorze jours.

**Constats.**

- **Moyenne. Les retours d'avance gonflent les recettes et les dépenses brutes.** Le résultat net est exact, mais la consolidation compte le décaissement brut en dépenses et le retour en recettes, contrairement au contrôle budgétaire et au calcul des quotes-parts qui les neutralisent.
- **Faible.** La période « année » est l'année civile, pas l'exercice. Les organisations en lecture seule, suspendues ou de démonstration ne sont pas exclues. Le champ « clôturé » est calculé mais non affiché.

**Manques.** Export ou impression du rapport consolidé, comparaison avec l'année précédente, tendance.

**Tests.** Couverts : base hors projet, envoi et réception, exclusion des quotes-parts et des remises, retards, accès refusé. Non couverts : montant fixe en CDF sans taux, changement de règle, arriérés anciens, période annuelle, retours d'avance.

## 13. Paie et avances sur salaire

**Fonctionnement.** Un rythme (mensuel, hebdomadaire, à la prestation) regroupe des bénéficiaires (membre ou nom libre, une devise, une base, un département pour le budget, un mode de paiement). Des éléments de paie (gain ou retenue, fixe ou en pourcentage) s'appliquent à tous ou par bénéficiaire. Un run génère un bulletin par bénéficiaire actif : base, gains, brut, retenues, avances plafonnées au reste, net jamais négatif, le tout figé. Le run suit brouillon → présenté → approuvé (par une autre personne) → payé. Le paiement crée une dépense par bulletin, groupée par devise de bulletin, avec conversion si la devise payée diffère, et enregistre les retenues d'avances sous verrou. L'état de paie et les bulletins s'impriment avec émargement. Une avance suit demande → approbation → paiement → remboursement par retenues planifiées.

**Points forts.** Chevauchement de périodes refusé, bulletins figés, approbateur distinct du présentateur, paiement tout-ou-rien par devise, double retenue impossible, annulation après paiement cohérente, contrôle budgétaire à la présentation, émargement imprimé.

**Constats.**

- **Élevée. Les retenues sont faussées au paiement.** `PayRuns.php:237` soustrait des « retenues » du bulletin la part d'avance finalement non retenue, alors que `Payroll::compute` ne met jamais les avances dans les retenues. Après paiement d'un bulletin dont l'avance a déjà été retenue ailleurs, les retenues deviennent fausses, voire négatives, et l'état de paie n'est plus cohérent (brut − retenues − avances ≠ net).
- **Moyenne. Un run peut rester bloqué en « approuvé ».** L'écran ne propose « Payer » que pour les bulletins à net positif (`Run.php:216`), alors que le paiement et la clôture du run attendent aussi les bulletins à net nul mais avance positive. Si une devise ne contient que de tels bulletins, aucun bouton n'apparaît et le run ne passe jamais à « payé ».
- **Moyenne. Les éléments fixes n'ont pas de devise.** « Transport 15 » pensé en dollars s'applique comme 15 francs à un bénéficiaire en CDF, sans conversion ni avertissement.
- **Moyenne. Les salaires sont visibles hors de la paie.** Le journal affiche « Paie Octobre 2026 · Nom » avec le montant à tout détenteur de la permission de voir les finances (rôle « Conseil » inclus) ; le budget affiche « Paie : Pasteur titulaire (Nom) » avec le montant annuel à tout détenteur de la permission de voir le budget, responsables de département compris.
- **Moyenne. Le paiement ne demande pas la permission de décaisser.** Un rôle « paie » peut sortir l'argent de la caisse sans la permission finance correspondante. L'annulation d'une paie payée ne demande ni contre-signature ni motif propre.
- **Moyenne. Date d'opération imposée à aujourd'hui.** Une paie d'octobre payée le 2 novembre est imputée à novembre dans les rapports et le budget.
- **Moyenne. Avance orpheline.** Si le bénéficiaire est désactivé, arrive en fin de contrat ou change de devise, l'avance reste « en remboursement » indéfiniment, sans alerte ni remboursement manuel possible.
- **Moyenne. Contrôle budgétaire contourné sans taux.** Sans taux CDF, l'équivalent USD de la paie vaut zéro et le contrôle passe.
- **Faible.** Retenues non plafonnées au brut. Ajustement validé avant arrondi (0,4 FC devient une ligne à zéro). Doublon de période possible par deux clics simultanés. Un bénéficiaire changé de rythme reste dans l'ancien run et entre dans le nouveau. L'état de paie imprimé omet les bulletins à net nul alors que les totaux les incluent. Pas de limite au cumul d'avances.

**Manques.** Retenues légales (CNSS, INPP, ONEM, IPR) assumées absentes par le manuel, à signaler clairement. Historique annuel par bénéficiaire (utile pour une attestation de revenus). Paie en deux devises pour une même personne, courante en RDC. Plusieurs comptes de paiement dans une même devise. Run exceptionnel hors rythme (gratification). Bulletin PDF ou WhatsApp. Export.

**Expérience utilisateur.** Paie mensuelle de dix personnes en sept actions, deux rôles, un écran principal : bon. La mise à jour des bulletins après modification d'une fiche est manuelle, sans indicateur de péremption. Listes de bénéficiaires et d'avances sans recherche ni pagination.

**Tests.** Couverts : calcul, plafond d'avance, prestation, cycle complet avec conversion, renvoi, avance en deux mensualités, double retenue évitée, annulation après paiement, lien budget. Non couverts : bug des retenues, run bloqué, solde insuffisant, clôture, bénéficiaire désactivé, retenues supérieures au brut, confidentialité.

## 14. Abonnement à la plateforme

**Fonctionnement.** Essai, actif, grâce, lecture seule, suspendu ; recalcul quotidien et après chaque paiement ; prix figé à la date de début, remise annuelle ; déclaration de paiement validée par Genius ICT. La lecture seule est appliquée écran par écran.

**Constats.**

- **Élevée (contrôle). Le palier de taille est auto-déclaré.** Aucun comptage de membres ; une communauté de deux mille membres peut payer le tarif de la plus petite.
- **Moyenne.** La validation enregistre la période complète au tarif catalogue quel que soit le montant déclaré. La lecture seule dépend d'un appel explicite dans chaque composant ; au moins une action (journaliser une relance de promesse) l'oublie. L'abonnement est en USD seulement alors que les paiements mobile money sont souvent en CDF.

## 15. Promesses du manuel et du pitch confrontées au code

| Promesse | État |
|---|---|
| « Waumini refuse un ID déjà enregistré » (manuel 16) | Non tenu pour les recettes saisies à la main |
| « Le taux réel du change apparaît dans le libellé » (manuel 16) | Non tenu |
| « Chaque saisie est enregistrée au fur et à mesure » (collecte) | Tenu, mais les échecs sont silencieux |
| « Désactiver une devise : elle disparaît des saisies » (manuel 07) | Non tenu |
| « Si possible, la capture » depuis l'espace membre (manuel 18) | Non tenu |
| Seuil de validation des dépenses (pitch) | Non implémenté, seulement un nombre de signatures |
| Argent des projets protégé (pitch) | Avertissement seulement |
| Clôture : opérations verrouillées, rapport archivé (pitch) | Verrouillage tenu, archivage latent |
| Rapprochement avec comptage physique (pitch) | Absent hors collecte |
| Quote-part sur les recettes validées (pitch) | Sur toutes les recettes non annulées |
| Relevé annuel par membre (pitch) | Côté membre seulement, sans attestation |
| Reçu envoyé par SMS ou WhatsApp (pitch) | Absent (impression seulement) |

## 16. Couverture de tests du périmètre financier

Environ 2 900 lignes de tests sur douze fichiers couvrent les chemins nominaux de chaque module, sur MariaDB réelle, avec des assertions de valeur. Les trous récurrents : concurrence (non testable sous SQLite mais la CI est sur MariaDB), identifiants inter-organisations, taux manquant ou aberrant, comptes fermés, confidentialité des montants, notifications (aucun test sur les destinataires des dépenses), contenu des exports, et les bugs listés ci-dessus. Le tableau de bord financier n'a aucun test sur ses chiffres.

## 17. Plan d'action proposé

Avant le premier pilote réel :

1. Clé système sur `finance_categories` (colonne `code` non modifiable, ou nom verrouillé pour les catégories système) et remplacement des recherches par nom dans `BudgetControl`, `Consolidation`, `ProjectNetwork`, `Quotas`, `Budgets`.
2. Correction des retenues au paiement de la paie (`PayRuns::settleAdvances`) et alignement du filtre « Payer » sur le filtre de clôture du run.
3. Unicité de l'ID mobile money sur les recettes valides d'une organisation, avec lien vers l'opération existante.
4. Notification au déclarant à la validation ou au rejet d'un paiement, avec le reçu.
5. Application du fuseau horaire de l'organisation.
6. Pagination en base de la liste des promesses.

Ensuite, par ordre de valeur :

7. Excédent de l'écran Budget sur le résumé des sources ; ligne budgétaire filtrée sur l'exercice ; recettes par département non arbitraires.
8. Pièces de dépense enregistrées après succès de la justification ; contrôleur exclu des signataires ; motif obligatoire à l'annulation ; relance automatique des avances en retard ; tableau des avances ouvertes.
9. Masquage des noms et montants de paie dans le journal et le budget pour qui n'a pas la permission paie ; permission de décaissement exigée au paiement ; date de paiement choisissable.
10. Date d'ouverture de compte prise en compte dans les soldes ; cohérence tableau de bord/rapport sur les comptes fermés ; désactivation de devise propagée ; alerte sur taux aberrant ; recalcul ou avertissement à la correction d'un taux.
11. Autosave de la collecte avec erreurs affichées et changement de compte sans perte.
12. Historique de règle de quote-part et cumul des arriérés ; suivi côté paroisse des remises non confirmées ; équivalent USD unique par remise.
13. Lecture effective de l'instantané de clôture pour les périodes clôturées ; retours d'avance neutralisés dans la consolidation ; synchronisation des projets hors du rendu.
14. Palier d'abonnement calculé sur l'effectif réel.

Fonctions à ajouter pour atteindre le niveau d'une trésorerie d'église complète : rapprochement et comptage physique avec écriture d'écart, pièces jointes sur recettes, différence de change, relevé annuel et attestation par membre, historique de paie par bénéficiaire, régime simplifié des petites dépenses avec seuil, dépenses récurrentes, alertes de seuil budgétaire, exports du journal et de la consolidation, PDF serveur pour reçus et rapports.

## 18. Suite donnée

Corrigé dans le code après ce rapport (voir `CHANGELOG.md` et `tests/Feature/AuditCorrectionsTest.php`) : les points 1 à 6 du plan d'action, puis 7 (excédent, ligne budgétaire par exercice), 8 pour partie (pièces après succès, motif d'annulation), 9 (confidentialité de la paie dans le journal et le budget, permission de décaisser au paiement), 10 (date d'ouverture, fermeture de compte, devise désactivée, taux aberrant à confirmer), 11 pour partie (erreurs d'autosave affichées), 12 pour partie (quote-part plafonnée), 13 pour partie (retours d'avance neutralisés en consolidation, projets non synchronisés en lecture seule), 14 (palier d'abonnement imposé par l'effectif). S'y ajoutent : don déclaré sans catégorie devenu une offrande, taux pratiqué dans le libellé d'un change, bug des retenues de paie, run bloqué en « approuvé ».

Décisions prises en corrigeant :

- Le contrôleur d'une dépense peut encore la signer : les tests et l'usage d'une petite église (un administrateur fait souvent les deux) reposent dessus. La séparation demandeur ≠ signataire est maintenue.
- Les recettes par département (point 7, attribution arbitraire) ne sont pas corrigées : elles demandent de rendre le département obligatoire à la saisie pour les catégories budgétées par département.
- La paie en deux devises par personne, les éléments fixes avec devise, l'historique par bénéficiaire et la justification partielle des avances sont des évolutions de schéma, hors de ce lot.

Second lot : relance des avances à justifier, arriérés de quotes-parts visibles au-delà de six mois, remises de projet en attente visibles côté paroisse, avertissement quand une période clôturée a changé, avertissement à la correction d'un taux passé, changement de caisse d'une collecte sans perte, annulation unique et sans solde passé négatif, immutabilité des opérations gardée par le modèle.

Reste à faire : historique des règles de quote-part (une évolution de schéma), recettes par département, et les manques fonctionnels listés en fin de plan d'action.
