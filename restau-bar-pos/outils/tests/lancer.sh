#!/bin/bash
# Lance une ou plusieurs suites adaptées sur la copie d'intégration (base restaurée avant chacune)
I=/home/user/Eglise/restau-bar-pos/outils/tests
S=$I/suites
export NODE_PATH=$(npm root -g)
mkdir -p $I/resultats
cd /home/user/Eglise/restau-bar-pos
for nom in "$@"; do
  case $nom in
    caisse-ardoise|sessions|reg-api|reg-caisse) $I/restaurer.sh sans-garant; cmd="node $S/dettes/$nom.js";;
    relances) $I/restaurer.sh; cmd="bash $S/dettes/relances.sh";;
    coeur) $I/restaurer.sh sans-garant; cmd="php $S/dettes/coeur.php";;
    pin) $I/restaurer.sh sans-garant; cmd="node $S/utilisateurs/pin.js";;
    gestion-utilisateurs) $I/restaurer.sh sans-garant; cmd="node $S/utilisateurs/gestion.js";;
    profils) $I/restaurer.sh sans-garant; cmd="node $S/profils/tests.js";;
    tailles) $I/restaurer.sh; cmd="node $S/agents/tailles/tests.js";;
    audit) $I/restaurer.sh; cmd="node $S/agents/final/audit.js";;
    tresorerie|ventes|dettes-e2e|stock|stock-suite|personnel|visite|rapports)
      case $nom in personnel|visite) $I/restaurer.sh sans-garant;; *) $I/restaurer.sh;; esac
      case $nom in tresorerie) f=tresorerie/e2e;; ventes) f=ventes/e2e;; dettes-e2e) f=dettes/e2e;; stock) f=stock/e2e;; stock-suite) f=stock/e2e-suite;; personnel) f=personnel/e2e;; visite) f=personnel/visite;; rapports) f=rapports/e2e;; esac
      cmd="node $S/agents/$f.js";;
    salle-notes|salle-paiement|salle-reglages|salle-stock|salle-ecrans) cmd="node $S/agents/salle/tests/${nom#salle-}.js";;
    nuit-remises|nuit-formules-minimum|nuit-entrees-invites|nuit-cashless-vestiaire-fidelite|nuit-ecrans) cmd="node $S/agents/nuit/e2e/${nom#nuit-}.js";;
    hl-personnel|hl-partagee|hl-nuit|hl-impression) cmd="node $S/agents/horsligne/${nom#hl-}.js";;
    impression-cuisine|vip|haut-tel) $I/restaurer.sh; cmd="node $I/propres/$nom.js";;
    recu-salle|pin-double) $I/restaurer.sh sans-garant; cmd="node $I/propres/$nom.js";;
    deconnexion-hl) cmd="node $I/propres/$nom.js";;
    parcours) $I/restaurer.sh; cmd="node $I/propres/parcours.js";;
    *) echo "suite inconnue $nom"; continue;;
  esac
  debut=$(date +%s)
  timeout 1500 $cmd > $I/resultats/$nom.log 2>&1; code=$?
  bilan=$(grep -E '^ *[0-9]+ */ *[0-9]+|réussi|[0-9]+ ok' $I/resultats/$nom.log | tail -1)
  echo "$nom : $bilan · $(grep -c 'ÉCHEC\|ECHEC' $I/resultats/$nom.log) échecs · code $code · $(( $(date +%s) - debut )) s"
done
