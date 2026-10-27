# Outils de développement : tests de bout en bout et manuel

Ces scripts ont été écrits et lancés sur un serveur Linux (MariaDB locale, connexion `root` par le socket,
Node.js avec Playwright et Chromium). Ils supposent le projet dans `/home/user/Eglise/restau-bar-pos` :
ailleurs, remplacer ce chemin partout dans `outils/` (une seule chaîne à remplacer). Sous Windows, les lancer
depuis WSL. Rien ici n'est nécessaire en production (le zip LWS les contient mais ne les utilise pas).

## Tests (`outils/tests/`)

Chaque suite repart d'une copie fraîche de la démo, l'instance « integ » (base `rbp_integ`, `http://integ.localhost:8250`).

```
echo '127.0.0.1 integ.localhost' >> /etc/hosts          # pour Node (les navigateurs résolvent *.localhost seuls)
sh outils/tests/reconstruire.sh                          # une fois : démo « integ » créée de zéro, gardée comme état de départ
php -S 127.0.0.1:8250 -t public public/index.php &       # serveur des tests
bash outils/tests/lancer.sh ventes stock pin             # une ou plusieurs suites ; journaux dans outils/tests/resultats/
```

`reconstruire.sh` écrit le mot de passe du propriétaire dans `outils/tests/instance.txt` (non versionné), lu par les
suites. `restaurer.sh [sans-garant]` remet la base et les photos à l'état de départ (lancé par `lancer.sh` avant
chaque suite ; `sans-garant` refuse les garanties en attente, dont la fenêtre s'ouvrirait sur la caisse).

Suites de `lancer.sh` (vérifications lors du dernier passage complet, tout au vert) :

| Domaine | Suites |
|---|---|
| Ardoises, sessions | `coeur` (35), `caisse-ardoise` (41), `sessions` (19), `reg-api` (19), `reg-caisse` (26), `relances` (26), `dettes-e2e` (694) |
| Utilisateurs, caisse partagée | `pin` (22), `gestion-utilisateurs` (43), `profils` (33), `pin-double` (3), `tailles` (116) |
| Argent | `tresorerie` (159), `ventes` (394) |
| Stock, achats | `stock` (693), `stock-suite` (542) |
| Personnel | `personnel` (99), `visite` (écrans × largeurs × thèmes × langues) |
| Rapports | `rapports` (307) |
| Salle, cuisine | `salle-notes`, `salle-paiement`, `salle-reglages`, `salle-stock`, `salle-ecrans`, `impression-cuisine`, `recu-salle` |
| Nuit | `nuit-remises`, `nuit-formules-minimum`, `nuit-entrees-invites`, `nuit-cashless-vestiaire-fidelite`, `nuit-ecrans` |
| Hors ligne | `hl-personnel`, `hl-partagee`, `hl-nuit`, `deconnexion-hl` |
| Écrans | `haut-tel`, `audit` (toutes les pages), `parcours` (toutes les routes GET, plusieurs comptes, FR/EN, téléphone et ordinateur) |

Console super admin : `sh outils/tests/suites/agents/console/tout.sh` (233 vérifications ; crée puis efface ses
propres bases, instances et serveur).

## Manuel (`outils/manuel/`)

`docs/manuel/index.html` est assemblé depuis `outils/manuel/src/*.html` ; les captures viennent d'une copie de la démo.

```
sh outils/manuel/preparer.sh                              # base rbp_manuel = copie de rbp_demo + une soirée pour ce soir
# config/instances/manuel.php : copie de demo.php avec 'nom' => 'rbp_manuel' ; serveur sur 8260 (manuel.localhost)
echo 'Mot de passe : <celui du propriétaire de la démo>' > outils/manuel/instance.txt
node outils/manuel/cap-caisse.js; node outils/manuel/cap-gestion.js; node outils/manuel/cap-nuit.js; node outils/manuel/cap-tel.js
sh outils/manuel/copier-console.sh                        # captures de la console (docs/captures/console)
python3 outils/manuel/construire.py                       # assemble index.html (sommaire, numéros, images)
node outils/manuel/pdf.js                                 # docs/manuel/manuel-restau-bar-pos.pdf (A4) ; relance construire.py pour les numéros du sommaire
```

Il faut aussi ImageMagick (`convert`), poppler (`pdfinfo`, `pdftotext`, `pdfunite`) et Python 3 avec Pillow.
