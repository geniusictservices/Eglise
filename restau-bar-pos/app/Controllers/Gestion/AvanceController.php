<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Acces;
use App\Core\Session;
use App\Core\View;
use App\Repositories\PaieRepository;
use App\Repositories\PersonnelRepository;

/**
 * Personnel > Avances : avances sur salaire accordées à tout moment, payées depuis la trésorerie (compte et
 * devise, ou deux devises), déduites d'office du bulletin suivant. Voir : « personnel.paie » ; verser ou
 * annuler : « personnel.payer », pour l'établissement de l'employé.
 */
final class AvanceController
{
    private PaieRepository $paie;

    public function __construct()
    {
        $this->paie = new PaieRepository();
    }

    public function index(): void
    {
        $utilisateur = PersonnelAcces::connecte();
        $employeId = TresorerieSaisie::id($_GET['employe'] ?? null);
        $this->afficher($utilisateur, ['employe_id' => $employeId, 'date' => date('Y-m-d\TH:i'), 'motif' => ''], null, [], [], Session::lireFlash());
    }

    private function afficher(array $utilisateur, array $valeurs, ?array $lignes, array $erreurs, array $avertissements, ?string $flash, int $code = 200): void
    {
        http_response_code($code);
        $uid = (int) $utilisateur['id'];
        $statut = in_array($_GET['statut'] ?? '', ['a_deduire', 'deduite', 'annulee'], true) ? $_GET['statut'] : null;
        $employes = array_values(array_filter((new PersonnelRepository())->liste(PersonnelAcces::portee($uid, 'personnel.payer')),
            static fn ($e) => Acces::a($uid, 'personnel.payer', (int) $e['etablissement_id'])));
        $employe = null;
        foreach ($employes as $e) {
            if ((int) $e['id'] === (int) ($valeurs['employe_id'] ?? 0)) {
                $employe = $e;
            }
        }
        $comptes = $employe ? PersonnelPaiementSaisie::comptes($this->paie->tresorerie(), (int) $employe['etablissement_id']) : $this->paie->tresorerie()->comptesActifs();
        View::afficher('gestion/personnel/avances/index', [
            'titre' => t('pers.avances_titre'),
            'utilisateur' => $utilisateur,
            'flash' => $flash,
            'statut' => $statut,
            'avances' => $this->paie->avances(PersonnelAcces::portee($uid, 'personnel.paie'), ['statut' => $statut]),
            'employes' => $employes,
            'valeurs' => $valeurs,
            'lignes' => $lignes ?? [PersonnelPaiementSaisie::LIGNE_VIDE],
            'comptes' => $comptes,
            'devises' => $this->paie->tresorerie()->devises(),
            'reference' => $this->paie->tresorerie()->reference(),
            'erreurs' => $erreurs,
            'avertissements' => $avertissements,
            'peutPayer' => Acces::a($uid, 'personnel.payer'),
            'porteePayer' => PersonnelAcces::portee($uid, 'personnel.payer'),
        ], 'gestion');
    }

    public function creer(): void
    {
        $utilisateur = PersonnelAcces::connecte(true);
        $uid = (int) $utilisateur['id'];
        $valeurs = ['employe_id' => TresorerieSaisie::id($_POST['employe_id'] ?? null), 'date' => TresorerieSaisie::post('date'),
            'motif' => mb_substr(TresorerieSaisie::post('motif'), 0, 300)];
        $erreurs = [];
        $employe = $valeurs['employe_id'] ? (new PersonnelRepository())->employe($valeurs['employe_id']) : null;
        if ($employe === null || !$employe['actif'] || !Acces::a($uid, 'personnel.payer', (int) $employe['etablissement_id'])) {
            $erreurs['employe_id'] = t('pers.erreur_employe');
            $employe = null;
        }
        $comptes = $employe ? PersonnelPaiementSaisie::comptes($this->paie->tresorerie(), (int) $employe['etablissement_id']) : [];
        $saisie = PersonnelPaiementSaisie::lire($comptes, $this->paie->tresorerie()->devises());
        if (($_POST['action'] ?? '') === 'ajouter_ligne') {
            $this->afficher($utilisateur, $valeurs, PersonnelPaiementSaisie::ajouterLigne($saisie['lignes']), [], [], null);
            return;
        }
        if ($employe !== null) {
            $erreurs += $saisie['erreurs'];
        }
        [$date, $erreurDate] = TresorerieSaisie::dateOperation($valeurs['date']);
        if ($erreurDate !== null) {
            $erreurs['date'] = $erreurDate;
        }
        if (mb_strlen($valeurs['motif']) > 255) {
            $erreurs['motif'] = t('tresorerie.erreur_texte_long', ['n' => 255]);
        }
        if ($erreurs !== []) {
            $this->afficher($utilisateur, $valeurs, $saisie['lignes'], $erreurs, [], null, 422);
            return;
        }
        $avertissements = PersonnelPaiementSaisie::decouverts($saisie['paiements']);
        if ($avertissements !== [] && empty($_POST['confirme'])) {
            $this->afficher($utilisateur, $valeurs, $saisie['lignes'], [], $avertissements, null);
            return;
        }
        $this->paie->creerAvance($employe, $date, $valeurs['motif'] !== '' ? $valeurs['motif'] : null, $saisie['paiements'], 'Avance sur salaire ' . $employe['nom'], $uid);
        $montants = [];
        foreach ($saisie['paiements'] as $p) {
            $montants[(int) $p['devise']['id']] = ($montants[(int) $p['devise']['id']] ?? 0) + $p['montant'];
        }
        Session::flash(t('pers.avance_versee', ['nom' => $employe['nom'], 'montant' => PersonnelAffichage::montants($montants)]));
        rediriger('/gestion/personnel/avances');
    }

    public function annuler(string $id): void
    {
        $utilisateur = PersonnelAcces::connecte(true);
        $uid = (int) $utilisateur['id'];
        $avance = ctype_digit($id) ? ($this->paie->avances(null, ['id' => (int) $id])[0] ?? null) : null;
        if ($avance === null || !Acces::a($uid, 'personnel.payer', (int) $avance['etablissement_id'])) {
            pageIntrouvable();
        }
        $motif = mb_substr(TresorerieSaisie::post('motif'), 0, 255);
        if ($motif === '') {
            Session::flash(t('pers.erreur_motif'));
        } else {
            try {
                $this->paie->annulerPaiement((int) $avance['paiement_id'], $motif, $uid);
                Session::flash(t('pers.flash_avance_annulee', ['nom' => $avance['employe_nom']]));
            } catch (\DomainException $e) {
                Session::flash(t('pers.erreur_' . $e->getMessage()));
            }
        }
        rediriger('/gestion/personnel/avances');
    }
}
