<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Acces;
use App\Core\Session;
use App\Core\View;
use App\Repositories\PaieRepository;
use App\Repositories\PersonnelRepository;

/**
 * Personnel > Paie : bulletins d'une période (mois ou semaine) et d'un établissement, préparation des
 * brouillons, répartition des pourboires, bulletin (modifier les heures, réduire ou étaler les retenues
 * proposées, primes, valider, annuler), paiement depuis la trésorerie, impression A4, masse salariale.
 * Écran : « personnel.paie » ; payer : « personnel.payer », pour l'établissement du bulletin.
 */
final class PaieController
{
    private PaieRepository $paie;

    public function __construct()
    {
        $this->paie = new PaieRepository();
    }

    /** @return array{type: string, debut: string, fin: string, valeur: string} période choisie (mois par défaut) */
    private static function periode(): array
    {
        $type = ($_GET['type'] ?? $_POST['type'] ?? '') === 'semaine' ? 'semaine' : 'mois';
        if ($type === 'semaine') {
            $valeur = PresenceController::date($_GET['semaine'] ?? $_POST['semaine'] ?? null) ?? date('Y-m-d');
            [$debut, $fin] = PaieRepository::semaine($valeur);
            return ['type' => 'semaine', 'debut' => $debut, 'fin' => $fin, 'valeur' => $debut];
        }
        $mois = $_GET['mois'] ?? $_POST['mois'] ?? null;
        $mois = is_string($mois) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mois) ? $mois : date('Y-m');
        [$debut, $fin] = PaieRepository::mois($mois);
        return ['type' => 'mois', 'debut' => $debut, 'fin' => $fin, 'valeur' => $mois];
    }

    public function index(): void
    {
        $utilisateur = PersonnelAcces::connecte();
        $this->afficherIndex($utilisateur, [], Session::lireFlash());
    }

    private function afficherIndex(array $utilisateur, array $erreurs, ?string $flash, int $code = 200): void
    {
        http_response_code($code);
        $uid = (int) $utilisateur['id'];
        $etablissements = PersonnelAcces::etablissements($uid, 'personnel.paie');
        $etab = PersonnelAcces::etablissementChoisi($etablissements, $_GET['etablissement'] ?? ($_POST['etablissement_id'] ?? null));
        $periode = self::periode();
        $bulletins = $etab ? $this->paie->bulletins(null, ['debut' => $periode['debut'], 'fin' => $periode['fin'], 'etablissement' => (int) $etab['id']]) : [];
        $avecBulletin = array_map(static fn ($b) => (int) $b['employe_id'], $bulletins);
        $aPreparer = [];
        if ($etab) {
            foreach ((new PersonnelRepository())->liste(null, ['etablissement' => (int) $etab['id']]) as $e) {
                $periodeOk = $e['periode'] === 'soiree' || $e['periode'] === $periode['type'];
                if ((int) $e['etablissement_id'] === (int) $etab['id'] && $periodeOk && !in_array((int) $e['id'], $avecBulletin, true)
                    && $e['debut'] <= $periode['fin'] && ($e['fin'] === null || $e['fin'] >= $periode['debut'])) {
                    $aPreparer[] = $e;
                }
            }
        }
        $totaux = ['net' => [], 'paye' => [], 'brut' => []];
        foreach ($bulletins as $b) {
            foreach ($b['totaux']['net'] as $d => $m) {
                $totaux['net'][$d] = ($totaux['net'][$d] ?? 0) + $m;
            }
            foreach ($b['totaux']['gains'] as $d => $m) {
                $totaux['brut'][$d] = ($totaux['brut'][$d] ?? 0) + $m;
            }
            foreach ($b['paye'] as $d => $m) {
                $totaux['paye'][$d] = ($totaux['paye'][$d] ?? 0) + $m;
            }
        }
        View::afficher('gestion/personnel/paie/index', [
            'titre' => t('pers.paie_titre'),
            'utilisateur' => $utilisateur,
            'flash' => $flash,
            'etablissements' => $etablissements,
            'etab' => $etab,
            'periode' => $periode,
            'bulletins' => $bulletins,
            'aPreparer' => $aPreparer,
            'totaux' => $totaux,
            'brouillonsPourboires' => array_filter($bulletins, static fn ($b) => $b['statut'] === 'brouillon'),
            'devises' => array_filter(PersonnelAffichage::devises(), static fn ($d) => (bool) ($d['active'] ?? true)),
            'erreurs' => $erreurs,
        ], 'gestion');
    }

    public function preparer(): void
    {
        $utilisateur = PersonnelAcces::connecte(true);
        $uid = (int) $utilisateur['id'];
        $etab = PersonnelAcces::etablissementChoisi(PersonnelAcces::etablissements($uid, 'personnel.paie'), $_POST['etablissement_id'] ?? null, true);
        if ($etab === null) {
            PersonnelAcces::interdit($utilisateur);
        }
        $periode = self::periode();
        $choisis = array_map('intval', is_array($_POST['employes'] ?? null) ? array_filter($_POST['employes'], 'ctype_digit') : []);
        $personnel = new PersonnelRepository();
        $prepares = 0;
        $deja = [];
        foreach ($personnel->liste(null, ['etablissement' => (int) $etab['id']]) as $e) {
            if ((int) $e['etablissement_id'] !== (int) $etab['id'] || ($choisis !== [] && !in_array((int) $e['id'], $choisis, true))) {
                continue;
            }
            if (!($e['periode'] === 'soiree' || $e['periode'] === $periode['type']) || $e['debut'] > $periode['fin'] || ($e['fin'] !== null && $e['fin'] < $periode['debut'])) {
                continue;
            }
            try {
                $this->paie->preparer($e, $periode['debut'], $periode['fin'], $uid);
                $prepares++;
            } catch (\DomainException) {
                $deja[] = $e['nom'];
            }
        }
        Session::flash(t('pers.bulletins_prepares', ['n' => $prepares]) . ($deja !== [] ? ' ' . t('pers.bulletins_deja', ['noms' => implode(', ', $deja)]) : ''));
        rediriger('/gestion/personnel/paie?' . http_build_query(['etablissement' => (int) $etab['id'], 'type' => $periode['type'], $periode['type'] => $periode['valeur']]));
    }

    public function pourboires(): void
    {
        $utilisateur = PersonnelAcces::connecte(true);
        $uid = (int) $utilisateur['id'];
        $etab = PersonnelAcces::etablissementChoisi(PersonnelAcces::etablissements($uid, 'personnel.paie'), $_POST['etablissement_id'] ?? null, true);
        if ($etab === null) {
            PersonnelAcces::interdit($utilisateur);
        }
        $_GET['etablissement'] = (string) $etab['id'];
        $periode = self::periode();
        $devises = PersonnelAffichage::devises();
        $deviseId = TresorerieSaisie::id($_POST['devise_id'] ?? null);
        $erreurs = [];
        if ($deviseId === null || !isset($devises[$deviseId])) {
            $erreurs['pourboires'] = t('pers.erreur_devise');
        } else {
            [$montant, $erreur] = TresorerieSaisie::montant($_POST['montant'] ?? null, $devises[$deviseId]);
            if ($erreur !== null) {
                $erreurs['pourboires'] = $erreur;
            }
        }
        if ($erreurs === []) {
            try {
                $parts = $this->paie->repartirPourboires((int) $etab['id'], $periode['debut'], $periode['fin'], $deviseId, $montant,
                    t('pers.ligne_pourboires', ['periode' => PersonnelAffichage::periode($periode['debut'], $periode['fin'])]), $uid);
                Session::flash(t('pers.pourboires_repartis', ['montant' => PersonnelAffichage::montant($montant, $deviseId), 'n' => count($parts)]));
                rediriger('/gestion/personnel/paie?' . http_build_query(['etablissement' => (int) $etab['id'], 'type' => $periode['type'], $periode['type'] => $periode['valeur']]));
            } catch (\DomainException) {
                $erreurs['pourboires'] = t('pers.erreur_pourboires_personne');
            }
        }
        $this->afficherIndex($utilisateur, $erreurs, null, 422);
    }

    // ---------- Bulletin ----------

    public function fiche(string $id): void
    {
        $utilisateur = PersonnelAcces::connecte();
        $bulletin = $this->bulletinOu404($id, (int) $utilisateur['id']);
        $this->afficherFiche($utilisateur, $bulletin, [], [], null, Session::lireFlash());
    }

    private function afficherFiche(array $utilisateur, array $bulletin, array $erreurs, array $avertissements, ?array $lignesPaiement, ?string $flash, int $code = 200): void
    {
        http_response_code($code);
        $uid = (int) $utilisateur['id'];
        $comptes = PersonnelPaiementSaisie::comptes($this->paie->tresorerie(), (int) $bulletin['etablissement_id']);
        $reste = PaieRepository::resteAPayer($bulletin);
        $employe = (new PersonnelRepository())->employe((int) $bulletin['employe_id']);
        View::afficher('gestion/personnel/paie/bulletin', [
            'titre' => t('pers.bulletin_titre', ['nom' => $bulletin['employe_nom']]),
            'utilisateur' => $utilisateur,
            'flash' => $flash,
            'bulletin' => $bulletin,
            'employe' => $employe,
            'reste' => $reste,
            'maximums' => array_map(fn ($l) => in_array($l['type'], ['dette', 'manquant'], true) ? $this->paie->maximumRetenue($l) : null, array_column($bulletin['lignes'], null, 'id')),
            'peutPayer' => Acces::a($uid, 'personnel.payer', (int) $bulletin['etablissement_id']),
            'comptes' => $comptes,
            'devises' => $this->paie->tresorerie()->devises(),
            'reference' => $this->paie->tresorerie()->reference(),
            'lignesPaiement' => $lignesPaiement ?? PersonnelPaiementSaisie::proposer($comptes, $reste),
            'erreurs' => $erreurs,
            'avertissements' => $avertissements,
            'voirComptes' => Acces::a($uid, 'finance.voir'),
        ], 'gestion');
    }

    /** POST : enregistrer les changements, recalculer, ou valider. */
    public function modifier(string $id): void
    {
        $utilisateur = PersonnelAcces::connecte(true);
        $uid = (int) $utilisateur['id'];
        $bulletin = $this->bulletinOu404($id, $uid);
        if ($bulletin['statut'] !== 'brouillon') {
            $this->afficherFiche($utilisateur, $bulletin, ['statut' => t('pers.erreur_pas_brouillon')], [], null, null, 409);
            return;
        }
        $action = $_POST['action'] ?? 'enregistrer';
        if ($action === 'recalculer') {
            $this->paie->calculer((int) $bulletin['id'], (new PersonnelRepository())->employe((int) $bulletin['employe_id']), $uid);
            Session::flash(t('pers.bulletin_recalcule'));
            rediriger('/gestion/personnel/paie/' . (int) $bulletin['id']);
        }
        [$changements, $erreurs] = $this->lireChangements($bulletin);
        if ($erreurs !== []) {
            $this->afficherFiche($utilisateur, $bulletin, $erreurs, [], null, null, 422);
            return;
        }
        $this->paie->modifier((int) $bulletin['id'], $changements, $uid);
        if ($action === 'valider') {
            try {
                $this->paie->valider((int) $bulletin['id'], $uid);
            } catch (\DomainException $e) {
                $this->afficherFiche($utilisateur, $this->paie->bulletin((int) $bulletin['id']), ['validation' => t('pers.erreur_' . $e->getMessage())], [], null, null, 409);
                return;
            }
            Session::flash(t('pers.flash_bulletin_valide', ['nom' => $bulletin['employe_nom']]));
        } else {
            Session::flash(t('pers.bulletin_enregistre'));
        }
        rediriger('/gestion/personnel/paie/' . (int) $bulletin['id']);
    }

    /** @return array{0: array, 1: array<string, string>} */
    private function lireChangements(array $bulletin): array
    {
        $lignes = array_column($bulletin['lignes'], null, 'id');
        $devises = PersonnelAffichage::devises();
        $erreurs = [];
        $c = ['quantites' => [], 'montants' => [], 'retirer' => [], 'nouvelle' => null, 'note' => mb_substr(TresorerieSaisie::post('note', true), 0, 2000) ?: null];
        foreach (is_array($_POST['quantite'] ?? null) ? $_POST['quantite'] : [] as $id => $saisie) {
            $l = $lignes[(int) $id] ?? null;
            if ($l === null || !in_array($l['type'], ['heures_sup', 'ferie', 'absence', 'sans_solde'], true)) {
                continue;
            }
            $n = DeviseAffichage::lireNombre(TresorerieSaisie::texte($saisie));
            if ($n === null || (float) $n < 0 || (float) $n > 500) {
                $erreurs['quantite-' . $id] = t('pers.erreur_quantite', ['ligne' => PersonnelAffichage::libelleLigne($l)]);
            } else {
                $c['quantites'][(int) $id] = round((float) $n, 2);
            }
        }
        foreach (is_array($_POST['montant'] ?? null) ? $_POST['montant'] : [] as $id => $saisie) {
            $l = $lignes[(int) $id] ?? null;
            if ($l === null || !(in_array($l['type'], ['dette', 'manquant'], true) || !$l['auto'])) {
                continue;
            }
            $etaler = (int) ($_POST['etaler'][$id] ?? 1);
            if (in_array($l['type'], ['dette', 'manquant'], true) && $etaler > 1 && $etaler <= 12) {
                $maximum = $this->paie->maximumRetenue($l);
                $f = 10 ** (int) $devises[(int) $l['devise_id']]['decimales'];
                $c['montants'][(int) $id] = ceil($maximum / $etaler * $f) / $f;
                continue;
            }
            [$montant, $erreur] = TresorerieSaisie::montant($saisie, $devises[(int) $l['devise_id']], in_array($l['type'], ['dette', 'manquant'], true));
            if ($erreur !== null) {
                $erreurs['montant-' . $id] = PersonnelAffichage::libelleLigne($l) . ' : ' . $erreur;
            } elseif (in_array($l['type'], ['dette', 'manquant'], true) && $montant > $this->paie->maximumRetenue($l) + 0.0005) {
                $erreurs['montant-' . $id] = t('pers.erreur_retenue_max', ['ligne' => PersonnelAffichage::libelleLigne($l),
                    'max' => PersonnelAffichage::montant($this->paie->maximumRetenue($l), (int) $l['devise_id'])]);
            } else {
                $c['montants'][(int) $id] = $montant;
            }
        }
        foreach (is_array($_POST['retirer'] ?? null) ? $_POST['retirer'] : [] as $id) {
            if (ctype_digit((string) $id)) {
                $c['retirer'][] = (int) $id;
            }
        }
        $type = TresorerieSaisie::texte($_POST['nouvelle']['type'] ?? null);
        $libelle = mb_substr(TresorerieSaisie::texte($_POST['nouvelle']['libelle'] ?? null), 0, 255);
        $montantNouveau = TresorerieSaisie::texte($_POST['nouvelle']['montant'] ?? null);
        if ($montantNouveau !== '' || $libelle !== '') {
            $deviseId = TresorerieSaisie::id($_POST['nouvelle']['devise_id'] ?? null);
            if (!in_array($type, PaieRepository::MANUELLES, true)) {
                $erreurs['nouvelle'] = t('pers.erreur_type_ligne');
            } elseif ($deviseId === null || !isset($devises[$deviseId])) {
                $erreurs['nouvelle'] = t('pers.erreur_devise');
            } else {
                [$montant, $erreur] = TresorerieSaisie::montant($montantNouveau, $devises[$deviseId]);
                if ($erreur !== null) {
                    $erreurs['nouvelle'] = $erreur;
                } else {
                    $c['nouvelle'] = ['type' => $type, 'libelle' => $libelle !== '' ? $libelle : t('pers.type_' . $type), 'devise_id' => $deviseId, 'montant' => $montant];
                }
            }
        }
        return [$c, $erreurs];
    }

    public function annuler(string $id): void
    {
        $utilisateur = PersonnelAcces::connecte(true);
        $bulletin = $this->bulletinOu404($id, (int) $utilisateur['id']);
        $motif = mb_substr(TresorerieSaisie::post('motif'), 0, 255);
        if ($bulletin['statut'] !== 'brouillon' && $motif === '') {
            $this->afficherFiche($utilisateur, $bulletin, ['motif' => t('pers.erreur_motif')], [], null, null, 422);
            return;
        }
        try {
            $this->paie->annuler((int) $bulletin['id'], $motif !== '' ? $motif : 'Brouillon supprimé', (int) $utilisateur['id']);
        } catch (\DomainException $e) {
            $this->afficherFiche($utilisateur, $bulletin, ['annulation' => t('pers.erreur_' . $e->getMessage())], [], null, null, 409);
            return;
        }
        Session::flash(t($bulletin['statut'] === 'brouillon' ? 'pers.brouillon_supprime' : 'pers.flash_bulletin_annule', ['nom' => $bulletin['employe_nom']]));
        rediriger($bulletin['statut'] === 'brouillon'
            ? '/gestion/personnel/paie?etablissement=' . (int) $bulletin['etablissement_id'] . ($bulletin['periode'] === 'semaine' ? '&type=semaine&semaine=' . $bulletin['debut'] : '&mois=' . substr($bulletin['debut'], 0, 7))
            : '/gestion/personnel/paie/' . (int) $bulletin['id']);
    }

    public function payer(string $id): void
    {
        $utilisateur = PersonnelAcces::connecte(true);
        $uid = (int) $utilisateur['id'];
        $bulletin = $this->bulletinOu404($id, $uid);
        if (!Acces::a($uid, 'personnel.payer', (int) $bulletin['etablissement_id'])) {
            PersonnelAcces::interdit($utilisateur);
        }
        $comptes = PersonnelPaiementSaisie::comptes($this->paie->tresorerie(), (int) $bulletin['etablissement_id']);
        $devises = $this->paie->tresorerie()->devises();
        $saisie = PersonnelPaiementSaisie::lire($comptes, $devises);
        if (($_POST['action'] ?? '') === 'ajouter_ligne') {
            $this->afficherFiche($utilisateur, $bulletin, [], [], PersonnelPaiementSaisie::ajouterLigne($saisie['lignes']), null);
            return;
        }
        $erreurs = $saisie['erreurs'];
        [$date, $erreurDate] = TresorerieSaisie::dateOperation(TresorerieSaisie::post('date'));
        if ($erreurDate !== null) {
            $erreurs['date'] = $erreurDate;
        }
        if ($bulletin['statut'] !== 'valide') {
            $erreurs['statut'] = t('pers.erreur_pas_valide');
        }
        $reste = PaieRepository::resteAPayer($bulletin);
        $parDevise = [];
        foreach ($saisie['paiements'] as $p) {
            $parDevise[(int) $p['devise']['id']] = ($parDevise[(int) $p['devise']['id']] ?? 0) + $p['montant'];
        }
        foreach ($parDevise as $d => $m) {
            if (!isset($reste[$d])) {
                $erreurs['paiements'] = t('pers.erreur_devise_rien', ['code' => $devises[$d]['code']]);
            } elseif ($m > $reste[$d] + 0.0005) {
                $erreurs['paiements'] = t('pers.erreur_paiement_trop', ['code' => $devises[$d]['code'], 'reste' => PersonnelAffichage::montant($reste[$d], $d)]);
            }
        }
        if ($erreurs !== []) {
            $this->afficherFiche($utilisateur, $bulletin, $erreurs, [], $saisie['lignes'], null, 422);
            return;
        }
        $avertissements = PersonnelPaiementSaisie::decouverts($saisie['paiements']);
        if ($avertissements !== [] && empty($_POST['confirme'])) {
            $this->afficherFiche($utilisateur, $bulletin, [], $avertissements, $saisie['lignes'], null);
            return;
        }
        try {
            $this->paie->payer((int) $bulletin['id'], $saisie['paiements'], $date, mb_substr(TresorerieSaisie::post('note'), 0, 255) ?: null,
                'Salaire ' . $bulletin['employe_nom'] . ' — ' . PersonnelAffichage::periode($bulletin['debut'], $bulletin['fin']), $uid);
        } catch (\DomainException $e) {
            $this->afficherFiche($utilisateur, $this->paie->bulletin((int) $bulletin['id']), ['paiements' => t('pers.erreur_paiement_' . $e->getMessage())], [], $saisie['lignes'], null, 409);
            return;
        }
        Session::flash(t('pers.salaire_paye', ['nom' => $bulletin['employe_nom'], 'montant' => PersonnelAffichage::montants($parDevise)]));
        rediriger('/gestion/personnel/paie/' . (int) $bulletin['id']);
    }

    public function annulerPaiement(string $id): void
    {
        $utilisateur = PersonnelAcces::connecte(true);
        $uid = (int) $utilisateur['id'];
        $paiement = ctype_digit($id) ? $this->paie->paiement((int) $id) : null;
        if ($paiement === null || !Acces::a($uid, 'personnel.payer', (int) $paiement['etablissement_id'])) {
            pageIntrouvable();
        }
        $retour = $paiement['bulletin_id'] !== null ? '/gestion/personnel/paie/' . (int) $paiement['bulletin_id'] : '/gestion/personnel/avances';
        $motif = mb_substr(TresorerieSaisie::post('motif'), 0, 255);
        if ($motif === '') {
            Session::flash(t('pers.erreur_motif'));
            rediriger($retour);
        }
        try {
            $this->paie->annulerPaiement((int) $paiement['id'], $motif, $uid);
            Session::flash(t('pers.paiement_annule'));
        } catch (\DomainException $e) {
            Session::flash(t('pers.erreur_' . $e->getMessage()));
        }
        rediriger($retour);
    }

    public function imprimer(string $id): void
    {
        $utilisateur = PersonnelAcces::connecte();
        $bulletin = $this->bulletinOu404($id, (int) $utilisateur['id']);
        View::afficher('gestion/personnel/paie/imprimer', [
            'titre' => t('pers.bulletin_titre', ['nom' => $bulletin['employe_nom']]),
            'bulletin' => $bulletin,
            'employe' => (new PersonnelRepository())->employe((int) $bulletin['employe_id']),
            'nomClient' => \App\Core\Config::get('parametres.nom_client', ''),
        ], null);
    }

    public function masse(): void
    {
        $utilisateur = PersonnelAcces::connecte();
        $uid = (int) $utilisateur['id'];
        $etablissements = PersonnelAcces::etablissements($uid, 'personnel.paie');
        $etab = PersonnelAcces::etablissementChoisi($etablissements, $_GET['etablissement'] ?? null, true);
        $annee = is_string($_GET['annee'] ?? null) && preg_match('/^20\d\d$/', $_GET['annee']) ? (int) $_GET['annee'] : (int) date('Y');
        View::afficher('gestion/personnel/paie/masse', [
            'titre' => t('pers.masse_titre'),
            'utilisateur' => $utilisateur,
            'etablissements' => $etablissements,
            'etab' => $etab,
            'annee' => $annee,
            'masse' => $this->paie->masseSalariale(PersonnelAcces::portee($uid, 'personnel.paie'), $annee . '-01-01', $annee . '-12-31', $etab ? (int) $etab['id'] : null),
        ], 'gestion');
    }

    private function bulletinOu404(string $id, int $uid): array
    {
        $bulletin = ctype_digit($id) && strlen($id) < 10 ? $this->paie->bulletin((int) $id) : null;
        if ($bulletin === null || !Acces::a($uid, 'personnel.paie', (int) $bulletin['etablissement_id'])) {
            pageIntrouvable();
        }
        return $bulletin;
    }
}
