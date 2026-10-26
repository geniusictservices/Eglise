<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Acces;
use App\Core\Database;
use App\Core\Session;
use App\Core\View;
use App\Repositories\DetteRepository;
use App\Repositories\PaieRepository;
use App\Repositories\PersonnelRepository;
use App\Repositories\PlanningRepository;
use App\Repositories\PointageRepository;

/**
 * Personnel > Employés : liste, fiche (présence du mois, bulletins, avances, dettes, manquants, téléphone
 * de pointage, documents), création et modification. Le salaire ne se voit et ne se modifie qu'avec
 * « personnel.paie » ; les documents avec « personnel.gerer ».
 */
final class EmployeController
{
    private PersonnelRepository $personnel;

    public function __construct()
    {
        $this->personnel = new PersonnelRepository();
    }

    public function index(): void
    {
        $utilisateur = PersonnelAcces::connecte();
        $uid = (int) $utilisateur['id'];
        $etablissements = PersonnelAcces::etablissements($uid, 'personnel.voir');
        $etab = PersonnelAcces::etablissementChoisi($etablissements, $_GET['etablissement'] ?? null, true);
        $statut = in_array($_GET['statut'] ?? '', ['actifs', 'inactifs', 'tous'], true) ? $_GET['statut'] : 'actifs';
        $q = mb_substr(TresorerieSaisie::texte($_GET['q'] ?? null), 0, 80);
        $employes = $this->personnel->liste(PersonnelAcces::portee($uid, 'personnel.voir'), [
            'etablissement' => $etab ? (int) $etab['id'] : null, 'q' => $q, 'statut' => $statut,
        ]);
        $ids = array_map(static fn (array $e) => (int) $e['id'], $employes);
        $presents = [];
        if ($ids !== []) {
            foreach (Database::tous('SELECT employe_id, etablissement_id, arrivee FROM pointages WHERE depart IS NULL AND arrivee >= ? AND employe_id IN (' . implode(',', $ids) . ')',
                [date('Y-m-d H:i:s', time() - PointageRepository::HEURES_MAX * 3600)]) as $p) {
                $presents[(int) $p['employe_id']] = $p;
            }
        }
        View::afficher('gestion/personnel/employes/index', [
            'titre' => t('pers.employes_titre'),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'employes' => $employes,
            'presents' => $presents,
            'etablissements' => $etablissements,
            'etab' => $etab,
            'statut' => $statut,
            'q' => $q,
            'peutGerer' => Acces::a($uid, 'personnel.gerer'),
            'voirSalaires' => Acces::a($uid, 'personnel.paie'),
            'portee' => PersonnelAcces::portee($uid, 'personnel.paie'),
        ], 'gestion');
    }

    public function fiche(string $id): void
    {
        $utilisateur = PersonnelAcces::connecte();
        $this->afficherFiche($utilisateur, PersonnelAcces::employeOu404($id, (int) $utilisateur['id']), null, null, Session::lireFlash());
    }

    public function nouveau(): void
    {
        $utilisateur = PersonnelAcces::connecte();
        $etablissements = PersonnelAcces::etablissements((int) $utilisateur['id'], 'personnel.gerer');
        if ($etablissements === []) {
            PersonnelAcces::interdit($utilisateur);
        }
        $this->formulaire($utilisateur, null, [
            'utilisateur_id' => null, 'nom' => '', 'telephone' => '', 'poste' => '', 'etablissement_id' => (int) $etablissements[0]['id'],
            'etablissements' => [], 'contrat' => 'cdi', 'debut' => date('Y-m-d'), 'fin' => '', 'periode' => 'mois',
            'commission_pct' => '', 'pourboires' => false, 'notes' => '', 'salaires' => [],
        ]);
    }

    public function creer(): void
    {
        $utilisateur = PersonnelAcces::connecte(true);
        $this->enregistrer($utilisateur, null);
    }

    public function modifier(string $id): void
    {
        $utilisateur = PersonnelAcces::connecte();
        $employe = PersonnelAcces::employeOu404($id, (int) $utilisateur['id'], 'personnel.gerer');
        $this->formulaire($utilisateur, $employe, [
            'utilisateur_id' => $employe['utilisateur_id'] !== null ? (int) $employe['utilisateur_id'] : null,
            'nom' => $employe['nom'], 'telephone' => (string) $employe['telephone'], 'poste' => (string) $employe['poste'],
            'etablissement_id' => (int) $employe['etablissement_id'], 'etablissements' => $employe['etablissements'],
            'contrat' => $employe['contrat'], 'debut' => $employe['debut'], 'fin' => (string) $employe['fin'], 'periode' => $employe['periode'],
            'commission_pct' => $employe['commission_pct'] !== null ? TresorerieAffichage::saisie($employe['commission_pct']) : '',
            'pourboires' => (bool) $employe['pourboires'], 'notes' => (string) $employe['notes'],
            'salaires' => array_map(static fn ($m) => TresorerieAffichage::saisie($m), $employe['salaires']),
        ]);
    }

    public function enregistrerModification(string $id): void
    {
        $utilisateur = PersonnelAcces::connecte(true);
        $employe = PersonnelAcces::employeOu404($id, (int) $utilisateur['id'], 'personnel.gerer');
        $this->enregistrer($utilisateur, $employe);
    }

    private function enregistrer(array $utilisateur, ?array $employe): void
    {
        $uid = (int) $utilisateur['id'];
        $etablissements = PersonnelAcces::etablissements($uid, 'personnel.gerer');
        if ($etablissements === []) {
            PersonnelAcces::interdit($utilisateur);
        }
        $permis = array_map(static fn (array $e) => (int) $e['id'], $etablissements);
        $peutSalaire = $employe === null ? Acces::a($uid, 'personnel.paie') : PersonnelAcces::peutEmploye($uid, 'personnel.paie', $employe);
        $devises = PersonnelAffichage::devises();

        $v = [
            'utilisateur_id' => TresorerieSaisie::id($_POST['utilisateur_id'] ?? null),
            'nom' => mb_substr(TresorerieSaisie::post('nom'), 0, 200),
            'telephone' => mb_substr(TresorerieSaisie::post('telephone'), 0, 40),
            'poste' => mb_substr(TresorerieSaisie::post('poste'), 0, 120),
            'etablissement_id' => TresorerieSaisie::id($_POST['etablissement_id'] ?? null),
            'etablissements' => array_values(array_filter(array_map(static fn ($x) => TresorerieSaisie::id($x), is_array($_POST['etablissements'] ?? null) ? $_POST['etablissements'] : []))),
            'contrat' => TresorerieSaisie::post('contrat'),
            'debut' => TresorerieSaisie::post('debut'),
            'fin' => TresorerieSaisie::post('fin'),
            'periode' => TresorerieSaisie::post('periode'),
            'commission_pct' => TresorerieSaisie::post('commission_pct'),
            'pourboires' => !empty($_POST['pourboires']),
            'notes' => mb_substr(TresorerieSaisie::post('notes', true), 0, 5000),
            'salaires' => [],
        ];
        foreach ($devises as $deviseId => $devise) {
            $v['salaires'][$deviseId] = TresorerieSaisie::texte($_POST['salaires'][$deviseId] ?? null);
        }

        $erreurs = [];
        $compte = null;
        if ($v['utilisateur_id'] !== null) {
            $libres = array_column($this->personnel->comptesLibres($employe['utilisateur_id'] ?? null), null, 'id');
            $compte = $libres[$v['utilisateur_id']] ?? null;
            if ($compte === null) {
                $erreurs['utilisateur_id'] = t('pers.erreur_compte');
                $v['utilisateur_id'] = null;
            }
        }
        if ($v['nom'] === '' && $compte !== null) {
            $v['nom'] = $compte['nom'];
        }
        if ($v['nom'] === '') {
            $erreurs['nom'] = t('pers.erreur_nom');
        } elseif (mb_strlen($v['nom']) > 128) {
            $erreurs['nom'] = t('tresorerie.erreur_texte_long', ['n' => 128]);
        }
        $telephone = null;
        if ($compte === null && $v['telephone'] !== '') {
            $telephone = DetteRepository::normaliserTelephone($v['telephone']);
            if ($telephone === null) {
                $erreurs['telephone'] = t('pers.erreur_telephone');
            } elseif ($this->personnel->telephonePris($telephone, (int) ($employe['id'] ?? 0))
                || Database::un('SELECT id FROM utilisateurs WHERE telephone = ?', [$telephone]) !== null) {
                $erreurs['telephone'] = t('pers.erreur_telephone_pris');
            }
        }
        if (mb_strlen($v['poste']) > 80) {
            $erreurs['poste'] = t('tresorerie.erreur_texte_long', ['n' => 80]);
        }
        if ($v['etablissement_id'] === null || !in_array($v['etablissement_id'], $permis, true)) {
            $erreurs['etablissement_id'] = t('pers.erreur_etablissement');
        }
        $autres = array_values(array_intersect($v['etablissements'], $permis));
        // Un établissement hors de ma portée reste rattaché
        if ($employe !== null) {
            $autres = array_values(array_unique(array_merge($autres, array_diff($employe['etablissements'], $permis))));
        }
        if (!in_array($v['contrat'], PersonnelRepository::CONTRATS, true)) {
            $erreurs['contrat'] = t('pers.erreur_contrat');
        }
        if (!in_array($v['periode'], PersonnelRepository::PERIODES, true)) {
            $erreurs['periode'] = t('pers.erreur_periode');
        }
        $debut = \DateTimeImmutable::createFromFormat('!Y-m-d', $v['debut']);
        if ($debut === false || $debut->format('Y-m-d') !== $v['debut'] || $v['debut'] < '2000-01-01') {
            $erreurs['debut'] = t('pers.erreur_date');
        }
        $fin = null;
        if ($v['fin'] !== '') {
            $f = \DateTimeImmutable::createFromFormat('!Y-m-d', $v['fin']);
            if ($f === false || $f->format('Y-m-d') !== $v['fin']) {
                $erreurs['fin'] = t('pers.erreur_date');
            } elseif (!isset($erreurs['debut']) && $v['fin'] < $v['debut']) {
                $erreurs['fin'] = t('pers.erreur_fin_avant');
            } else {
                $fin = $v['fin'];
            }
        }
        $commission = null;
        if ($v['commission_pct'] !== '') {
            $n = DeviseAffichage::lireNombre($v['commission_pct']);
            if ($n === null || (float) $n < 0 || (float) $n > 50) {
                $erreurs['commission_pct'] = t('pers.erreur_commission');
            } else {
                $commission = (float) $n > 0 ? round((float) $n, 2) : null;
            }
        }
        $salaires = $employe['salaires'] ?? [];
        if ($peutSalaire) {
            $salaires = [];
            foreach ($v['salaires'] as $deviseId => $saisie) {
                if ($saisie === '') {
                    continue;
                }
                [$montant, $erreur] = TresorerieSaisie::montant($saisie, $devises[$deviseId], true);
                if ($erreur !== null) {
                    $erreurs['salaires-' . $deviseId] = $devises[$deviseId]['code'] . ' : ' . $erreur;
                } elseif ($montant > 0) {
                    $salaires[$deviseId] = $montant;
                }
            }
        }

        if ($erreurs !== []) {
            $this->formulaire($utilisateur, $employe, $v, $erreurs, 422);
            return;
        }
        $id = $this->personnel->enregistrer($employe !== null ? (int) $employe['id'] : null, [
            'utilisateur_id' => $compte !== null ? (int) $compte['id'] : null,
            'nom' => $v['nom'], 'telephone' => $telephone, 'poste' => $v['poste'] !== '' ? $v['poste'] : ($compte['poste'] ?? null),
            'etablissement_id' => (int) $v['etablissement_id'], 'etablissements' => $autres,
            'contrat' => $v['contrat'], 'debut' => $v['debut'], 'fin' => $fin, 'periode' => $v['periode'],
            'commission_pct' => $commission, 'pourboires' => $v['pourboires'], 'notes' => $v['notes'] !== '' ? $v['notes'] : null,
            'salaires' => $salaires,
        ], $uid);
        Session::flash(t($employe === null ? 'pers.employe_cree' : 'pers.employe_modifie', ['nom' => $v['nom']]));
        rediriger('/gestion/personnel/employes/' . $id);
    }

    private function formulaire(array $utilisateur, ?array $employe, array $valeurs, array $erreurs = [], int $code = 200): void
    {
        http_response_code($code);
        $uid = (int) $utilisateur['id'];
        View::afficher('gestion/personnel/employes/formulaire', [
            'titre' => $employe ? t('pers.modifier_employe', ['nom' => $employe['nom']]) : t('pers.nouvel_employe'),
            'utilisateur' => $utilisateur,
            'employe' => $employe,
            'valeurs' => $valeurs,
            'erreurs' => $erreurs,
            'etablissements' => PersonnelAcces::etablissements($uid, 'personnel.gerer'),
            'comptes' => $this->personnel->comptesLibres($employe['utilisateur_id'] ?? null),
            'devises' => array_filter(PersonnelAffichage::devises(), static fn (array $d) => (bool) ($d['active'] ?? true)),
            'peutSalaire' => $employe === null ? Acces::a($uid, 'personnel.paie') : PersonnelAcces::peutEmploye($uid, 'personnel.paie', $employe),
        ], 'gestion');
    }

    public function activer(string $id): void
    {
        $utilisateur = PersonnelAcces::connecte(true);
        $employe = PersonnelAcces::employeOu404($id, (int) $utilisateur['id'], 'personnel.gerer');
        $actif = ($_POST['actif'] ?? '') === '1';
        $this->personnel->definirActif((int) $employe['id'], $actif, (int) $utilisateur['id']);
        Session::flash(t($actif ? 'pers.employe_reactive' : 'pers.employe_desactive', ['nom' => $employe['nom']]));
        rediriger('/gestion/personnel/employes/' . (int) $employe['id']);
    }

    /** PIN de pointage d'un employé sans compte (téléphone, secours à la caisse). */
    public function pin(string $id): void
    {
        $utilisateur = PersonnelAcces::connecte(true);
        $employe = PersonnelAcces::employeOu404($id, (int) $utilisateur['id'], 'personnel.gerer');
        if ($employe['utilisateur_id'] !== null) {
            rediriger('/gestion/personnel/employes/' . (int) $employe['id']);
        }
        $pin = preg_replace('/\D+/', '', (string) ($_POST['pin'] ?? '')) ?? '';
        try {
            $this->personnel->definirPinPointage((int) $employe['id'], $pin, (int) $utilisateur['id']);
        } catch (\InvalidArgumentException $e) {
            http_response_code(422);
            $this->ficheAvecErreur($utilisateur, $employe, t($e->getMessage() === 'simple' ? 'pers.erreur_pin_simple' : 'pers.erreur_pin_format'), null);
            return;
        }
        Session::flash(t('pers.pin_enregistre', ['nom' => $employe['nom']]));
        rediriger('/gestion/personnel/employes/' . (int) $employe['id'] . '#pointage');
    }

    public function debloquer(string $id): void
    {
        $utilisateur = PersonnelAcces::connecte(true);
        $employe = PersonnelAcces::employeOu404($id, (int) $utilisateur['id'], 'personnel.gerer');
        $this->personnel->debloquerPin((int) $employe['id'], (int) $utilisateur['id']);
        Session::flash(t('pers.pin_debloque', ['nom' => $employe['nom']]));
        rediriger('/gestion/personnel/employes/' . (int) $employe['id'] . '#pointage');
    }

    public function retirerTelephone(string $id, string $telephone): void
    {
        $utilisateur = PersonnelAcces::connecte(true);
        $employe = PersonnelAcces::employeOu404($id, (int) $utilisateur['id'], 'personnel.gerer');
        if (ctype_digit($telephone)) {
            $this->personnel->retirerTelephone((int) $employe['id'], (int) $telephone, (int) $utilisateur['id']);
        }
        Session::flash(t('pers.telephone_retire'));
        rediriger('/gestion/personnel/employes/' . (int) $employe['id'] . '#pointage');
    }

    public function ajouterDocument(string $id): void
    {
        $utilisateur = PersonnelAcces::connecte(true);
        $employe = PersonnelAcces::employeOu404($id, (int) $utilisateur['id'], 'personnel.gerer');
        $fichier = $_FILES['document'] ?? null;
        $erreur = null;
        if (!is_array($fichier) || ($fichier['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $erreur = t('pers.erreur_document_vide');
        } elseif ($fichier['error'] !== UPLOAD_ERR_OK || $fichier['size'] > PersonnelRepository::DOCUMENT_MAX) {
            $erreur = t('pers.erreur_document_taille', ['n' => 5]);
        } else {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($fichier['tmp_name']) ?: '';
            if (!isset(PersonnelRepository::DOCUMENTS[$mime])) {
                $erreur = t('pers.erreur_document_type');
            }
        }
        $nom = mb_substr(TresorerieSaisie::post('nom'), 0, 120);
        if ($erreur !== null) {
            http_response_code(422);
            $this->ficheAvecErreur($utilisateur, $employe, null, $erreur);
            return;
        }
        $nom = $nom !== '' ? $nom : mb_substr(TresorerieSaisie::texte((string) $fichier['name']), 0, 120);
        $this->personnel->ajouterDocument((int) $employe['id'], $nom, $fichier['tmp_name'], $mime, (int) $fichier['size'], (int) $utilisateur['id']);
        Session::flash(t('pers.document_ajoute', ['nom' => $nom]));
        rediriger('/gestion/personnel/employes/' . (int) $employe['id'] . '#documents');
    }

    public function document(string $id, string $document): void
    {
        $utilisateur = PersonnelAcces::connecte();
        $employe = PersonnelAcces::employeOu404($id, (int) $utilisateur['id'], 'personnel.gerer');
        $doc = ctype_digit($document) ? $this->personnel->document((int) $employe['id'], (int) $document) : null;
        $chemin = $doc ? PersonnelRepository::dossierDocuments() . '/' . basename($doc['fichier']) : null;
        if ($doc === null || !is_file($chemin)) {
            pageIntrouvable();
        }
        $extension = PersonnelRepository::DOCUMENTS[$doc['type_mime']] ?? 'bin';
        $nomFichier = preg_replace('/[^A-Za-z0-9._-]+/', '-', $doc['nom']) ?: 'document';
        header('Content-Type: ' . $doc['type_mime']);
        header('Content-Length: ' . filesize($chemin));
        header('Content-Disposition: ' . (str_starts_with($doc['type_mime'], 'image/') || $doc['type_mime'] === 'application/pdf' ? 'inline' : 'attachment')
            . '; filename="' . $nomFichier . (str_ends_with(strtolower($nomFichier), '.' . $extension) ? '' : '.' . $extension) . '"');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
        header('Cache-Control: private, no-store');
        readfile($chemin);
    }

    public function supprimerDocument(string $id, string $document): void
    {
        $utilisateur = PersonnelAcces::connecte(true);
        $employe = PersonnelAcces::employeOu404($id, (int) $utilisateur['id'], 'personnel.gerer');
        $doc = ctype_digit($document) ? $this->personnel->document((int) $employe['id'], (int) $document) : null;
        if ($doc === null) {
            pageIntrouvable();
        }
        $this->personnel->supprimerDocument($doc, (int) $utilisateur['id']);
        Session::flash(t('pers.document_supprime', ['nom' => $doc['nom']]));
        rediriger('/gestion/personnel/employes/' . (int) $employe['id'] . '#documents');
    }

    private function ficheAvecErreur(array $utilisateur, array $employe, ?string $erreurPin, ?string $erreurDocument): void
    {
        $this->afficherFiche($utilisateur, $employe, $erreurPin, $erreurDocument, null);
    }

    private function afficherFiche(array $utilisateur, array $employe, ?string $erreurPin, ?string $erreurDocument, ?string $flash): void
    {
        $uid = (int) $utilisateur['id'];
        [$du, $au] = PaieRepository::mois(date('Y-m'));
        $voirPaie = PersonnelAcces::peutEmploye($uid, 'personnel.paie', $employe);
        $gerer = PersonnelAcces::peutEmploye($uid, 'personnel.gerer', $employe);
        $paie = new PaieRepository();
        $pointages = new PointageRepository();
        View::afficher('gestion/personnel/employes/fiche', [
            'titre' => $employe['nom'], 'utilisateur' => $utilisateur, 'flash' => $flash, 'employe' => $employe,
            'etablissementsNoms' => array_column(Database::tous('SELECT id, nom FROM etablissements'), 'nom', 'id'),
            'bilan' => (new PlanningRepository())->bilan([(int) $employe['id']], $du, $au)[(int) $employe['id']], 'moisDu' => $du,
            'present' => $pointages->ouverte((int) $employe['id']),
            'voirPaie' => $voirPaie, 'gerer' => $gerer,
            'bulletins' => $voirPaie ? $paie->bulletins(null, ['employe' => (int) $employe['id']]) : [],
            'avances' => $voirPaie ? $paie->avances(null, ['employe' => (int) $employe['id']]) : [],
            'dettes' => $voirPaie ? $this->personnel->dettes($employe) : null,
            'manquants' => $voirPaie ? $this->personnel->manquants($employe, false) : [],
            'telephones' => $gerer ? $this->personnel->telephones((int) $employe['id']) : [],
            'documents' => $gerer ? $this->personnel->documents((int) $employe['id']) : [],
            'corrections' => $pointages->corrections(null, (int) $employe['id'], 10),
            'voirDettes' => Acces::a($uid, 'dettes.voir'),
            'erreurPin' => $erreurPin, 'erreurDocument' => $erreurDocument,
        ], 'gestion');
    }
}
