<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Acces;
use App\Core\Session;
use App\Core\View;
use App\Repositories\GestionDetteRepository;

/**
 * Ventes > Dettes : liste filtrée (statut, établissement, responsable, servi par, garant en attente, retard ou à venir,
 * devise, période) avec les totaux par devise et l'ancienneté ; fiche d'une dette ; décisions du gérant
 * (dettes.gerer pour l'établissement de la dette) : changer le responsable, mettre à la charge du responsable
 * (après l'échéance), annuler (sans remboursement) ou remettre, toujours avec un motif.
 * Les relances partent automatiquement (bin/relances.php) : pas de bouton « Relancer ».
 */
final class DetteController
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
        $periode = TresorerieSaisie::periode('tout');
        $etablissements = $this->depot->etablissements($uid, 'dettes.voir');
        $personnes = $this->depot->personnesDettes($uid);
        $devises = VenteController::devises();
        $utilisees = $this->depot->devisesDettes($uid);
        $devisesPresentes = array_values(array_filter(array_keys($devises), static fn (int $id) => in_array($id, $utilisees, true)));

        $choix = static function (string $nom, array $liste): ?int {
            $id = TresorerieSaisie::id($_GET[$nom] ?? null);
            return $id !== null && in_array($id, array_map('intval', array_column($liste, 'id')), true) ? $id : null;
        };
        $statut = $_GET['statut'] ?? 'ouverte';
        $filtres = [
            'debut' => $periode['debut'], 'fin' => $periode['fin'],
            'statut' => in_array($statut, GestionDetteRepository::STATUTS, true) ? $statut : null,
            'etablissement' => $choix('etablissement', $etablissements),
            'responsable' => $choix('responsable', $personnes['responsables']),
            'servi' => $choix('servi', $personnes['servis']),
            'garant' => ($_GET['garant'] ?? '') === '1',
            'echeance' => in_array($_GET['echeance'] ?? null, ['retard', 'a_venir'], true) ? $_GET['echeance'] : null,
            'devise' => in_array(TresorerieSaisie::id($_GET['devise'] ?? null), $devisesPresentes, true) ? TresorerieSaisie::id($_GET['devise']) : null,
            'client' => TresorerieSaisie::id($_GET['client'] ?? null),
        ];
        $page = DetteAffichage::page();
        $liste = $this->depot->listeDettes($filtres, $uid, $page);
        $pages = max(1, (int) ceil($liste['total'] / GestionDetteRepository::PAR_PAGE));
        if ($page > $pages) {
            $page = $pages;
            $liste = $this->depot->listeDettes($filtres, $uid, $page);
        }
        View::afficher('gestion/dettes/index', [
            'titre' => t('gdettes.titre'),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'periode' => $periode,
            'filtres' => $filtres,
            'statutChoisi' => $statut === 'toutes' ? 'toutes' : ($filtres['statut'] ?? 'toutes'),
            'dettes' => $liste['dettes'],
            'nombre' => $liste['total'],
            'page' => $page,
            'pages' => $pages,
            'totaux' => $this->depot->totauxDettes($filtres, $uid),
            'devises' => $devises,
            'devisesPresentes' => $devisesPresentes,
            'etablissements' => $etablissements,
            'personnes' => $personnes,
            'client' => $filtres['client'] ? $this->depot->dettes()->client($filtres['client']) : null,
        ], 'gestion');
    }

    public function fiche(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        $this->afficherFiche($utilisateur, $this->ficheOu404($id, $utilisateur));
    }

    public function responsable(string $id): void
    {
        [$utilisateur, $fiche] = $this->debutDecision($id);
        $nouveau = TresorerieSaisie::id($_POST['responsable_id'] ?? null);
        $motif = TresorerieSaisie::post('motif_responsable');
        $erreurs = [];
        if ($nouveau === null) {
            $erreurs['responsable_id'] = t('gdettes.erreur_responsable');
        } elseif ($nouveau === (int) $fiche['dette']['responsable_id']) {
            $erreurs['responsable_id'] = t('gdettes.erreur_meme_responsable');
        }
        $this->verifierMotif($motif, 'motif_responsable', $erreurs);
        if ($erreurs === []) {
            try {
                $this->depot->dettes()->changerResponsable((int) $fiche['dette']['id'], $nouveau, (int) $utilisateur['id'], $motif);
                $nom = array_column($this->depot->utilisateursActifs(), 'nom', 'id')[$nouveau] ?? '';
                Session::flash(t('gdettes.responsable_flash', ['nom' => $nom]));
                rediriger('/gestion/dettes/' . (int) $fiche['dette']['id']);
            } catch (\DomainException $e) {
                $erreurs[$e->getMessage() === 'utilisateur' ? 'responsable_id' : 'decision'] = t('gdettes.erreur_' . $e->getMessage());
            }
        }
        $this->afficherFiche($utilisateur, $fiche, $erreurs, ['responsable_id' => $nouveau, 'motif_responsable' => $motif], isset($erreurs['decision']) ? 409 : 422, 'responsable');
    }

    public function aCharge(string $id): void
    {
        [$utilisateur, $fiche] = $this->debutDecision($id);
        $motif = TresorerieSaisie::post('motif_charge');
        $erreurs = [];
        $this->verifierMotif($motif, 'motif_charge', $erreurs);
        if (empty($_POST['confirme'])) {
            $erreurs['confirme_charge'] = t('gdettes.erreur_confirmer');
        }
        if ($erreurs === []) {
            try {
                $nouvelle = $this->depot->dettes()->mettreALaCharge((int) $fiche['dette']['id'], (int) $utilisateur['id'], $motif);
                Session::flash(t('gdettes.a_charge_flash', ['nom' => (string) $fiche['dette']['responsable_nom']]));
                rediriger('/gestion/dettes/' . $nouvelle);
            } catch (\DomainException $e) {
                $erreurs['decision'] = t('gdettes.erreur_' . $e->getMessage(), ['date' => TresorerieAffichage::jour($fiche['dette']['echeance'])]);
            }
        }
        $this->afficherFiche($utilisateur, $fiche, $erreurs, ['motif_charge' => $motif], isset($erreurs['decision']) ? 409 : 422, 'charge');
    }

    public function cloturer(string $id): void
    {
        [$utilisateur, $fiche] = $this->debutDecision($id);
        $statut = in_array($_POST['statut'] ?? null, ['annulee', 'remise'], true) ? $_POST['statut'] : null;
        $motif = TresorerieSaisie::post('motif_cloture');
        $erreurs = [];
        if ($statut === null) {
            $erreurs['statut'] = t('gdettes.erreur_statut');
        }
        $this->verifierMotif($motif, 'motif_cloture', $erreurs);
        if (empty($_POST['confirme'])) {
            $erreurs['confirme_cloture'] = t('gdettes.erreur_confirmer');
        }
        if ($erreurs === []) {
            try {
                $this->depot->dettes()->cloturer((int) $fiche['dette']['id'], $statut, (int) $utilisateur['id'], $motif);
                Session::flash(t($statut === 'annulee' ? 'gdettes.annulee_flash' : 'gdettes.remise_flash'));
                rediriger('/gestion/dettes/' . (int) $fiche['dette']['id']);
            } catch (\DomainException $e) {
                $erreurs['decision'] = t('gdettes.erreur_' . $e->getMessage());
            }
        }
        $this->afficherFiche($utilisateur, $fiche, $erreurs, ['statut' => $statut, 'motif_cloture' => $motif], isset($erreurs['decision']) ? 409 : 422, 'cloture');
    }

    private function verifierMotif(string $motif, string $champ, array &$erreurs): void
    {
        if ($motif === '') {
            $erreurs[$champ] = t('gdettes.erreur_motif');
        } elseif (mb_strlen($motif) > 255) {
            $erreurs[$champ] = t('gdettes.erreur_motif_long', ['n' => 255]);
        }
    }

    /** @return array{0: array, 1: array} utilisateur et fiche, après CSRF et dettes.gerer pour l'établissement de la dette */
    private function debutDecision(string $id): array
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $fiche = $this->ficheOu404($id, $utilisateur);
        if (!Acces::a((int) $utilisateur['id'], 'dettes.gerer', (int) $fiche['dette']['etablissement_id'])) {
            DetteAffichage::interdit($utilisateur);
        }
        return [$utilisateur, $fiche];
    }

    private function afficherFiche(array $utilisateur, array $fiche, array $erreurs = [], array $saisie = [], int $code = 200, ?string $ouvert = null): void
    {
        http_response_code($code);
        $uid = (int) $utilisateur['id'];
        $dette = $fiche['dette'];
        View::afficher('gestion/dettes/fiche', [
            'titre' => t('gdettes.fiche_titre', ['id' => $dette['id']]),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'fiche' => $fiche,
            'devises' => VenteController::devises(),
            'aujourdhui' => date('Y-m-d'),
            'peutGerer' => Acces::a($uid, 'dettes.gerer', (int) $dette['etablissement_id']),
            'peutRembourser' => Acces::a($uid, 'dettes.rembourser', (int) $dette['etablissement_id']),
            'voirVentes' => Acces::a($uid, 'ventes.voir', (int) $dette['etablissement_id']),
            'voirClients' => Acces::a($uid, 'clients.voir'),
            'utilisateurs' => $this->depot->utilisateursActifs(),
            'erreurs' => $erreurs,
            'saisie' => $saisie,
            'ouvert' => $ouvert,
        ], 'gestion');
    }

    private function ficheOu404(string $id, array $utilisateur): array
    {
        $fiche = ctype_digit($id) && strlen($id) < 11 ? $this->depot->ficheDette((int) $id, (int) $utilisateur['id']) : null;
        if ($fiche === null) {
            pageIntrouvable();
        }
        return $fiche;
    }
}
