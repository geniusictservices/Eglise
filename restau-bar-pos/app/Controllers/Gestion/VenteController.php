<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Acces;
use App\Core\Session;
use App\Core\View;
use App\Repositories\DetteRepository;
use App\Repositories\DeviseRepository;
use App\Repositories\GestionDeviseRepository;
use App\Repositories\GestionTresorerieRepository;
use App\Repositories\GestionVenteRepository;

/**
 * Ventes > Tickets : liste des ventes encaissées (filtres, totaux, pages), fiche avec le reçu,
 * annulation avec motif. La portée (établissements de la personne) est appliquée par le dépôt.
 */
final class VenteController
{
    /** Raccourcis de période de l'écran (pas « cette année » : une liste de tickets se lit au jour ou au mois). */
    public const PERIODES = ['jour', '7j', 'mois', 'mois_precedent', 'tout'];

    private GestionVenteRepository $ventes;

    public function __construct()
    {
        $this->ventes = new GestionVenteRepository();
    }

    public function index(): void
    {
        $utilisateur = Session::exigerConnexion();
        $id = (int) $utilisateur['id'];
        $periode = TresorerieSaisie::periode('jour');
        $etablissements = $this->ventes->etablissements($id, 'ventes.voir');
        $vendeurs = $this->ventes->vendeurs($id);

        $etablissement = TresorerieSaisie::id($_GET['etablissement'] ?? null);
        if ($etablissement !== null && !in_array($etablissement, array_map('intval', array_column($etablissements, 'id')), true)) {
            $etablissement = null;
        }
        $vendeur = TresorerieSaisie::id($_GET['vendeur'] ?? null);
        if ($vendeur !== null && !in_array($vendeur, array_map('intval', array_column($vendeurs, 'id')), true)) {
            $vendeur = null;
        }
        $moyen = in_array($_GET['moyen'] ?? null, GestionTresorerieRepository::TYPES, true) ? $_GET['moyen'] : null;
        $statut = in_array($_GET['statut'] ?? null, GestionVenteRepository::STATUTS, true) ? $_GET['statut'] : null;
        $filtres = [
            'debut' => $periode['debut'], 'fin' => $periode['fin'],
            'etablissement' => $etablissement, 'vendeur' => $vendeur, 'moyen' => $moyen, 'statut' => $statut,
            // Ventes faites sans réseau (mode hors ligne de la caisse)
            'hors_ligne' => ($_GET['hors_ligne'] ?? '') === '1',
        ];

        $page = TresorerieSaisie::id($_GET['page'] ?? null) ?? 1;
        $liste = $this->ventes->liste($filtres, $id, $page);
        $pages = max(1, (int) ceil($liste['total'] / GestionVenteRepository::PAR_PAGE));
        if ($page > $pages) {
            $page = $pages;
            $liste = $this->ventes->liste($filtres, $id, $page);
        }

        // Export CSV : tous les tickets des filtres (Rapports, cahier des charges 13.7)
        if (\App\Core\Csv::demande()) {
            ExportCsv::ventes($this->ventes, $filtres, $id, $periode, self::devises());
        }

        View::afficher('gestion/ventes/index', [
            'titre' => t('gventes.titre'),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'periode' => $periode,
            'periodes' => self::PERIODES,
            'filtres' => $filtres,
            'ventes' => $liste['ventes'],
            'nombre' => $liste['total'],
            'page' => $page,
            'pages' => $pages,
            'totaux' => $this->ventes->totaux($filtres, $id),
            'devises' => self::devises(),
            'reference' => (new DeviseRepository())->reference(),
            'etablissements' => $etablissements,
            'vendeurs' => $vendeurs,
        ], 'gestion');
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
        $uid = (int) $utilisateur['id'];
        // ventes.annuler pour l'établissement de cette vente (le dépôt revérifie dans la transaction)
        if (!Acces::a($uid, 'ventes.annuler', (int) $fiche['vente']['etablissement_id'])) {
            $this->interdit($utilisateur);
        }

        $motif = TresorerieSaisie::post('motif');
        $erreurs = [];
        if ($fiche['vente']['statut'] !== 'payee') {
            $erreurs['annulation'] = t('gventes.erreur_deja_annulee');
        } else {
            if ($motif === '') {
                $erreurs['motif'] = t('gventes.erreur_motif');
            } elseif (mb_strlen($motif) > 255) {
                $erreurs['motif'] = t('gventes.erreur_motif_long', ['n' => 255]);
            }
            if (empty($_POST['confirme'])) {
                $erreurs['confirme'] = t('gventes.erreur_confirmer');
            }
        }
        if ($erreurs !== []) {
            $this->afficherFiche($utilisateur, $fiche, $erreurs, mb_substr($motif, 0, 1000), isset($erreurs['annulation']) ? 409 : 422);
            return;
        }

        $resultat = $this->ventes->annuler((int) $fiche['vente']['id'], $uid, $motif);
        switch ($resultat) {
            case 'introuvable':
                pageIntrouvable();
            case 'interdit':
                $this->interdit($utilisateur);
            case 'deja':
                $fiche = $this->ficheOu404($id, $utilisateur);
                $this->afficherFiche($utilisateur, $fiche, ['annulation' => t('gventes.erreur_deja_annulee')], '', 409);
                return;
            case 'credit_rembourse':
                $this->afficherFiche($utilisateur, $fiche, ['annulation' => t('gventes.erreur_credit_rembourse')], mb_substr($motif, 0, 1000), 409);
                return;
        }
        Session::flash(t('gventes.annulee_flash', ['numero' => $fiche['vente']['numero']]));
        rediriger('/gestion/ventes/' . (int) $fiche['vente']['id']);
    }

    /**
     * Devises pour afficher les montants, indexées par id : les actives dans l'ordre d'affichage
     * (locale, dollar, autres), puis celles désactivées depuis (une vente ancienne peut en avoir).
     * @return array<int, array>
     */
    public static function devises(): array
    {
        $devises = (new DeviseRepository())->actives();
        foreach ((new GestionDeviseRepository())->toutes() as $devise) {
            $devises[(int) $devise['id']] ??= $devise;
        }
        return $devises;
    }

    private function afficherFiche(array $utilisateur, array $fiche, array $erreurs = [], string $motif = '', int $code = 200): void
    {
        http_response_code($code);
        $uid = (int) $utilisateur['id'];
        View::afficher('gestion/ventes/fiche', [
            'titre' => t('gventes.fiche_titre', ['numero' => $fiche['vente']['numero']]),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'fiche' => $fiche,
            'devises' => self::devises(),
            'peutAnnuler' => $this->ventes->peutAnnuler($fiche['vente'], $uid),
            // Lien vers la fiche d'un compte seulement pour qui voit la trésorerie
            'voirComptes' => Acces::a($uid, 'finance.voir'),
            // Part du ticket mise à l'ardoise d'un client (Clients et dettes)
            'credits' => (new DetteRepository())->dettesDeVente((int) $fiche['vente']['id']),
            'voirDettes' => Acces::a($uid, 'dettes.voir', (int) $fiche['vente']['etablissement_id']),
            'voirClients' => Acces::a($uid, 'clients.voir'),
            'erreurs' => $erreurs,
            'motif' => $motif,
        ], 'gestion');
    }

    private function ficheOu404(string $id, array $utilisateur): array
    {
        $fiche = ctype_digit($id) && strlen($id) < 11 ? $this->ventes->fiche((int) $id, (int) $utilisateur['id']) : null;
        if ($fiche === null) {
            pageIntrouvable();
        }
        return $fiche;
    }

    private function interdit(array $utilisateur): never
    {
        http_response_code(403);
        View::afficher('gestion/interdit', ['titre' => t('acces.interdit_titre'), 'utilisateur' => $utilisateur], 'gestion');
        exit;
    }
}
