<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Acces;
use App\Core\Session;
use App\Core\View;
use App\Repositories\GestionDetteRepository;
use App\Repositories\GestionTresorerieRepository;

/**
 * Ventes > Remboursements : liste (période, établissement, type de compte, utilisateur, statut) avec les totaux
 * par devise ; saisie d'un remboursement dans la gestion (dettes.rembourser : un virement reçu à la banque,
 * un dépôt mobile money…) ; fiche avec la répartition sur les dettes ; annulation (dettes.gerer, motif).
 *
 * Le remboursement se répartit sur les dettes ouvertes du client que la personne peut rembourser (établissements
 * de sa portée), les plus anciennes d'abord, au taux du jour (DetteRepository::rembourser). Le formulaire porte
 * une clé (UUID) : un double envoi ne compte qu'une fois.
 */
final class RemboursementController
{
    private GestionDetteRepository $depot;

    public function __construct()
    {
        $this->depot = new GestionDetteRepository();
    }

    public function index(): void
    {
        $utilisateur = Session::exigerConnexion();
        $uid = (int) $utilisateur['id'];
        $periode = TresorerieSaisie::periode('mois');
        $etablissements = $this->depot->etablissements($uid, 'dettes.voir');
        $encaisseurs = $this->depot->encaisseurs($uid);
        $choix = static function (string $nom, array $liste): ?int {
            $id = TresorerieSaisie::id($_GET[$nom] ?? null);
            return $id !== null && in_array($id, array_map('intval', array_column($liste, 'id')), true) ? $id : null;
        };
        $filtres = [
            'debut' => $periode['debut'], 'fin' => $periode['fin'],
            'etablissement' => $choix('etablissement', $etablissements),
            'utilisateur' => $choix('utilisateur', $encaisseurs),
            'moyen' => in_array($_GET['moyen'] ?? null, GestionTresorerieRepository::TYPES, true) ? $_GET['moyen'] : null,
            'statut' => in_array($_GET['statut'] ?? null, GestionDetteRepository::STATUTS_REMBOURSEMENT, true) ? $_GET['statut'] : null,
            'client' => TresorerieSaisie::id($_GET['client'] ?? null),
        ];
        $page = DetteAffichage::page();
        $liste = $this->depot->listeRemboursements($filtres, $uid, $page);
        $pages = max(1, (int) ceil($liste['total'] / GestionDetteRepository::PAR_PAGE));
        if ($page > $pages) {
            $page = $pages;
            $liste = $this->depot->listeRemboursements($filtres, $uid, $page);
        }
        View::afficher('gestion/remboursements/index', [
            'titre' => t('gremb.titre'),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'periode' => $periode,
            'filtres' => $filtres,
            'remboursements' => $liste['remboursements'],
            'nombre' => $liste['total'],
            'page' => $page,
            'pages' => $pages,
            'totaux' => $this->depot->totauxRemboursements($filtres, $uid),
            'devises' => VenteController::devises(),
            'reference' => $this->tresorerieReference(),
            'etablissements' => $etablissements,
            'encaisseurs' => $encaisseurs,
            'client' => $filtres['client'] ? $this->depot->dettes()->client($filtres['client']) : null,
            'peutSaisir' => Acces::a($uid, 'dettes.rembourser'),
        ], 'gestion');
    }

    public function nouveau(): void
    {
        $utilisateur = Session::exigerConnexion();
        $this->exigerSaisie($utilisateur);
        $clientId = TresorerieSaisie::id($_GET['client'] ?? null);
        $client = $clientId !== null ? $this->depot->dettes()->client($clientId) : null;
        if ($client === null) {
            // Étape 1 : chercher le client
            $q = mb_substr(TresorerieSaisie::texte($_GET['q'] ?? null), 0, 64);
            View::afficher('gestion/remboursements/choisir', [
                'titre' => t('gremb.nouveau'),
                'utilisateur' => $utilisateur,
                'q' => $q,
                'resultats' => $this->depot->chercherClients($q, (int) $utilisateur['id']),
                'devises' => VenteController::devises(),
                'inconnu' => $clientId !== null,
            ], 'gestion');
            return;
        }
        $this->afficherFormulaire($utilisateur, $client, [
            'etablissement_id' => null, 'compte_id' => null, 'devise_id' => null, 'montant' => '', 'date' => '',
            'cle' => DetteAffichage::uuid(),
        ]);
    }

    public function creer(): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $this->exigerSaisie($utilisateur);
        $uid = (int) $utilisateur['id'];
        $client = $this->depot->dettes()->client(TresorerieSaisie::id($_POST['client_id'] ?? null) ?? 0);
        if ($client === null) {
            pageIntrouvable();
        }
        $cle = is_string($_POST['cle'] ?? null) && DetteAffichage::estUuid($_POST['cle']) ? $_POST['cle'] : DetteAffichage::uuid();
        $valeurs = [
            'etablissement_id' => TresorerieSaisie::id($_POST['etablissement_id'] ?? null),
            'compte_id' => TresorerieSaisie::id($_POST['compte_id'] ?? null),
            'devise_id' => TresorerieSaisie::id($_POST['devise_id'] ?? null),
            'montant' => TresorerieSaisie::post('montant'),
            'date' => TresorerieSaisie::post('date'),
            'cle' => $cle,
        ];
        $erreurs = [];
        $etablissements = $this->depot->etablissements($uid, 'dettes.rembourser');
        $ids = array_map('intval', array_column($etablissements, 'id'));
        if (count($ids) === 1 && $valeurs['etablissement_id'] === null) {
            $valeurs['etablissement_id'] = $ids[0];
        }
        if ($valeurs['etablissement_id'] === null || !in_array($valeurs['etablissement_id'], $ids, true)) {
            $erreurs['etablissement_id'] = t('gremb.erreur_etablissement');
        }
        $dettes = $this->depot->dettesARembourser((int) $client['id'], $uid);
        if ($dettes === []) {
            $erreurs['client'] = t('gremb.erreur_rien_a_rembourser', ['nom' => $client['nom']]);
        }
        $comptes = $this->depot->comptesPour($valeurs['etablissement_id'] !== null ? [$valeurs['etablissement_id']] : $ids);
        $compte = $valeurs['compte_id'] !== null ? ($comptes[$valeurs['compte_id']] ?? null) : null;
        $devises = (new GestionTresorerieRepository())->devises();
        $devise = null;
        if ($compte === null) {
            $erreurs['compte_id'] = t('gremb.erreur_compte');
        } else {
            $tenues = array_keys(array_filter($compte['devises'], static fn (array $d) => $d['tenue']));
            if ($valeurs['devise_id'] === null && count($tenues) === 1) {
                $valeurs['devise_id'] = $tenues[0];
            }
            if ($valeurs['devise_id'] === null || !in_array($valeurs['devise_id'], $tenues, true) || !isset($devises[$valeurs['devise_id']])) {
                $erreurs['devise_id'] = t('tresorerie.erreur_devise_compte', [
                    'compte' => $compte['nom'],
                    'codes' => implode(', ', array_map(static fn (int $id): string => $devises[$id]['code'] ?? '?', $tenues)),
                ]);
            } else {
                $devise = $devises[$valeurs['devise_id']];
            }
        }
        $montant = null;
        if ($devise !== null) {
            [$montant, $erreur] = TresorerieSaisie::montant($valeurs['montant'], $devise);
            if ($erreur !== null) {
                $erreurs['montant'] = $erreur;
            }
        } elseif ($valeurs['montant'] === '') {
            $erreurs['montant'] = t('gremb.erreur_montant_vide');
        }
        [$date, $erreurDate] = TresorerieSaisie::dateOperation($valeurs['date']);
        if ($erreurDate !== null) {
            $erreurs['date'] = $erreurDate;
        }
        if ($erreurs === []) {
            try {
                // Portée limitée : seulement les dettes des établissements où la personne peut encaisser
                $tout = Acces::etablissements($uid, 'dettes.rembourser') === null;
                $id = $this->depot->dettes()->rembourser([
                    'client_id' => (int) $client['id'], 'etablissement_id' => $valeurs['etablissement_id'],
                    'compte' => ['id' => (int) $compte['id'], 'type' => $compte['type']], 'devise_id' => (int) $devise['id'],
                    'montant' => $montant, 'utilisateur_id' => $uid, 'cle' => $cle,
                    'dettes' => $tout ? null : array_map(static fn (array $d) => (int) $d['id'], $dettes),
                    'date' => $date,
                ]);
                $reste = $this->depot->encoursDe([(int) $client['id']], $uid, 'dettes.rembourser')[(int) $client['id']]['encours'] ?? [];
                Session::flash($reste === []
                    ? t('gremb.enregistre_solde', ['montant' => TresorerieAffichage::montant($montant, $devise), 'nom' => $client['nom']])
                    : t('gremb.enregistre_flash', ['montant' => TresorerieAffichage::montant($montant, $devise), 'nom' => $client['nom'], 'reste' => DetteAffichage::montants($reste, VenteController::devises())]));
                rediriger('/gestion/remboursements/' . $id);
            } catch (\DomainException $e) {
                $code = $e->getMessage();
                $erreurs[$code === 'trop' || $code === 'montant' ? 'montant' : 'client'] = t('gremb.erreur_' . $code, [
                    'maximum' => $devise !== null ? TresorerieAffichage::montant($this->depot->dettes()->maximum, $devise) : '',
                    'nom' => $client['nom'],
                ]);
            }
        }
        $this->afficherFormulaire($utilisateur, $client, $valeurs, $erreurs, 422);
    }

    public function fiche(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        $this->afficherFiche($utilisateur, $this->ficheOu404($id, $utilisateur));
    }

    public function annuler(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $fiche = $this->ficheOu404($id, $utilisateur);
        $r = $fiche['remboursement'];
        if (!Acces::a((int) $utilisateur['id'], 'dettes.gerer', (int) $r['etablissement_id'])) {
            DetteAffichage::interdit($utilisateur);
        }
        $motif = TresorerieSaisie::post('motif');
        $erreurs = [];
        if ($r['statut'] !== 'valide') {
            $erreurs['annulation'] = t('gremb.erreur_deja_annule');
        } else {
            if ($motif === '') {
                $erreurs['motif'] = t('gdettes.erreur_motif');
            } elseif (mb_strlen($motif) > 255) {
                $erreurs['motif'] = t('gdettes.erreur_motif_long', ['n' => 255]);
            }
            if (empty($_POST['confirme'])) {
                $erreurs['confirme'] = t('gdettes.erreur_confirmer');
            }
        }
        if ($erreurs === []) {
            try {
                $this->depot->dettes()->annulerRemboursement((int) $r['id'], (int) $utilisateur['id'], $motif);
                Session::flash(t('gremb.annule_flash'));
                rediriger('/gestion/remboursements/' . (int) $r['id']);
            } catch (\DomainException $e) {
                $erreurs['annulation'] = t($e->getMessage() === 'dette_close' ? 'gremb.erreur_dette_close' : 'gremb.erreur_deja_annule');
            }
        }
        $this->afficherFiche($utilisateur, $fiche, $erreurs, mb_substr($motif, 0, 1000), isset($erreurs['annulation']) ? 409 : 422);
    }

    private function afficherFormulaire(array $utilisateur, array $client, array $valeurs, array $erreurs = [], int $code = 200): void
    {
        http_response_code($code);
        $uid = (int) $utilisateur['id'];
        $etablissements = $this->depot->etablissements($uid, 'dettes.rembourser');
        $dettes = $this->depot->dettesARembourser((int) $client['id'], $uid);
        // Établissement proposé : celui de la plus ancienne dette ouverte, sinon le seul de la portée
        if ($valeurs['etablissement_id'] === null) {
            $ids = array_map('intval', array_column($etablissements, 'id'));
            $propose = $dettes !== [] ? (int) $dettes[0]['etablissement_id'] : null;
            $valeurs['etablissement_id'] = $propose !== null && in_array($propose, $ids, true) ? $propose : (count($ids) === 1 ? $ids[0] : null);
        }
        $encours = [];
        foreach ($dettes as $d) {
            $encours[(int) $d['devise_id']] = ($encours[(int) $d['devise_id']] ?? 0.0) + (float) $d['reste'];
        }
        View::afficher('gestion/remboursements/nouveau', [
            'titre' => t('gremb.nouveau'),
            'utilisateur' => $utilisateur,
            'client' => $client,
            'dettes' => $dettes,
            'encours' => $encours,
            'valeurs' => $valeurs,
            'erreurs' => $erreurs,
            'etablissements' => $etablissements,
            'comptes' => $this->depot->comptesPour(array_map('intval', array_column($etablissements, 'id'))),
            'devises' => (new GestionTresorerieRepository())->devises(),
            'devisesAffichage' => VenteController::devises(),
        ], 'gestion');
    }

    private function afficherFiche(array $utilisateur, array $fiche, array $erreurs = [], string $motif = '', int $code = 200): void
    {
        http_response_code($code);
        $uid = (int) $utilisateur['id'];
        $r = $fiche['remboursement'];
        View::afficher('gestion/remboursements/fiche', [
            'titre' => t('gremb.fiche_titre', ['id' => $r['id']]),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'fiche' => $fiche,
            'devises' => VenteController::devises(),
            'reference' => $this->tresorerieReference(),
            'peutAnnuler' => $r['statut'] === 'valide' && Acces::a($uid, 'dettes.gerer', (int) $r['etablissement_id']),
            'voirComptes' => Acces::a($uid, 'finance.voir'),
            'voirClients' => Acces::a($uid, 'clients.voir'),
            'voirVentes' => Acces::a($uid, 'ventes.voir'),
            'erreurs' => $erreurs,
            'motif' => $motif,
        ], 'gestion');
    }

    private function tresorerieReference(): ?array
    {
        return (new GestionTresorerieRepository())->reference();
    }

    private function exigerSaisie(array $utilisateur): void
    {
        if (!Acces::a((int) $utilisateur['id'], 'dettes.rembourser')) {
            DetteAffichage::interdit($utilisateur);
        }
    }

    private function ficheOu404(string $id, array $utilisateur): array
    {
        $fiche = ctype_digit($id) && strlen($id) < 11 ? $this->depot->ficheRemboursement((int) $id, (int) $utilisateur['id']) : null;
        if ($fiche === null) {
            pageIntrouvable();
        }
        return $fiche;
    }
}
