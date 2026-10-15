# Journal des modifications

## Non publié — corrections de l'audit d'octobre 2026

Les deux rapports sont dans `docs/audit-2026-10-14.md` (sécurité) et `docs/evaluation-finances-2026-10-14.md` (finances). Chaque rapport se termine par l'état des corrections.

### Accès et cloisonnement
- Un gestionnaire d'utilisateurs ne modifie plus ses propres rôles et n'attribue pas une permission qu'il n'a pas ; un gestionnaire de rôles ne modifie pas un rôle qu'il porte ni ne coche une permission qu'il n'a pas ; un rôle avec toutes les permissions vaut administrateur.
- Un mot de passe provisoire bloque aussi les actions Livewire hors du profil ; les sessions sont fermées à la réinitialisation du mot de passe et à la désactivation d'un compte.
- Les comptes créés dans une démo sont marqués démo et effacés avec elle ; une démo ne se rattache plus à une vraie communauté.
- Bulletin de paie relu dans la paie affichée ; membres, ménages, départements et catégories vérifiés dans la communauté sur toutes les saisies financières.
- Le journal d'audit ne contient plus la personne, le motif ni le téléphone d'un dossier pastoral.

### Finances
- Catégories système (salaires, retours sur avance, promesses, projets, quotes-parts, versements au siège) retrouvées par un code stable, plus par leur nom (migration `2026_11_01_000100`).
- Paie : retenues intactes au paiement ; bouton Payer pour tout bulletin à payer ; le nom des bénéficiaires ne se lit qu'avec la permission paie, dans le journal comme dans le budget ; payer demande aussi la permission de décaisser.
- Change refusé au-delà de 10 % d'écart avec le taux du jour (réglage `finance.exchange_tolerance`) ; taux pratiqué dans le libellé ; taux officiel très différent du précédent à confirmer.
- ID de transaction mobile money saisi une seule fois sur les recettes.
- Dépense : ligne budgétaire limitée à l'exercice de la dépense, motif obligatoire à l'annulation, pièces enregistrées après succès de la justification, décaissement refusé depuis un compte fermé.
- Budget : excédent affiché cohérent avec le résumé des sources.
- Promesses : échéancier unique (dernière échéance = solde), liste paginée en base.
- Paiements déclarés : le déclarant est prévenu du résultat ; un don sans catégorie devient une offrande.
- Quote-part plafonnée au reste dû ; consolidation sans les retours d'avance.
- Un compte ne se ferme pas avec un solde ; une devise désactivée disparaît des saisies ; pas d'opération avant l'ouverture d'une devise ; fuseau horaire de la communauté appliqué.
- Abonnement : la taille déclarée ne descend pas sous l'effectif réel.

### Second lot
- Une opération enregistrée ne se modifie ni ne se supprime, même hors des écrans : le modèle le refuse ; elle s'annule une seule fois, et jamais si un solde passé devenait négatif.
- Inscription : chaque essai compte, réussi ou non (20 par heure et par appareil).
- Le support Genius ICT ne peut rien écrire, même si une permission le laissait passer.
- Avances à justifier : rappel deux jours avant l'échéance, puis le lendemain et chaque semaine de retard (commande `waumini:avances`, 7 h).
- Quotes-parts : les arriérés plus anciens que six mois restent visibles et se versent ; le niveau supérieur voit les arriérés de chaque niveau.
- Projet du siège : la paroisse voit ce qui attend la confirmation du siège.
- Rapport d'une période clôturée : un avertissement si les chiffres ont changé depuis la clôture.
- Taux corrigé après coup : le nombre d'opérations qui gardent l'ancien taux est indiqué.
- Collecte : changer de caisse ne fait plus disparaître de montants.

### Exploitation
- En-têtes de sécurité HTTP ; export Excel sans formule ; sauvegardes en instantané cohérent, chiffrement vérifié, copie distante jamais silencieuse.
- File d'attente dans le planificateur (plus de `proc_open`), verrous courts, journal `schedule.log`, échecs signalés, ménage quotidien réel.
- Extensions PHP déclarées, audits de dépendances en CI, Dependabot.
