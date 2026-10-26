<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Acces;
use App\Core\Parametres;
use App\Core\Session;
use App\Core\View;
use App\Repositories\EtablissementRepository;
use App\Repositories\PersonnelRepository;
use App\Repositories\PlanningRepository;
use App\Repositories\PointageRepository;

/**
 * Personnel > Présence : tableau du jour (planning comparé aux pointages), liste des pointages avec
 * corrections du gérant (motif obligatoire, journal), bornes de pointage (tablettes autorisées qui
 * affichent le code QR), réglages (position GPS, rayon, pointage obligatoire pour ouvrir la caisse).
 */
final class PresenceController
{
    private PointageRepository $pointages;

    public function __construct()
    {
        $this->pointages = new PointageRepository();
    }

    public function index(): void
    {
        $utilisateur = PersonnelAcces::connecte();
        $uid = (int) $utilisateur['id'];
        $etablissements = PersonnelAcces::etablissements($uid, 'personnel.voir');
        $etab = PersonnelAcces::etablissementChoisi($etablissements, $_GET['etablissement'] ?? null);
        $jour = self::date($_GET['jour'] ?? null) ?? PointageRepository::journee(0, 0, date('Y-m-d H:i:s'));
        $employes = $etab ? (new PersonnelRepository())->deLEtablissement((int) $etab['id'], false) : [];
        $bilan = $etab ? (new PlanningRepository())->bilan(array_map(static fn ($e) => (int) $e['id'], $employes), $jour, $jour, (int) $etab['id']) : [];
        $lignes = [];
        foreach ($employes as $e) {
            $j = $bilan[(int) $e['id']]['jours'][$jour] ?? null;
            if ($j !== null || $e['actif']) {
                $lignes[] = ['employe' => $e, 'jour' => $j];
            }
        }
        // Planifiés d'abord, puis présents sans planning, puis les autres
        usort($lignes, static fn ($a, $b) => [empty($a['jour']['creneaux']), empty($a['jour']['pointages']), $a['employe']['nom']]
            <=> [empty($b['jour']['creneaux']), empty($b['jour']['pointages']), $b['employe']['nom']]);
        View::afficher('gestion/personnel/presence/index', [
            'titre' => t('pers.presence_titre'),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'etablissements' => $etablissements,
            'etab' => $etab,
            'jour' => $jour,
            'lignes' => $lignes,
            'peutCorriger' => $etab && Acces::a($uid, 'personnel.presence', (int) $etab['id']),
            'peutGerer' => $etab && Acces::a($uid, 'personnel.gerer', (int) $etab['id']),
            'sansPosition' => $etab && ($etab['latitude'] === null || $etab['longitude'] === null),
            'sansBorne' => $etab && array_filter($this->pointages->bornes([(int) $etab['id']]), static fn ($b) => $b['actif']) === [],
        ], 'gestion');
    }

    public function liste(): void
    {
        $utilisateur = PersonnelAcces::connecte();
        $uid = (int) $utilisateur['id'];
        $periode = TresorerieSaisie::periode('7j');
        $etablissements = PersonnelAcces::etablissements($uid, 'personnel.voir');
        $etab = PersonnelAcces::etablissementChoisi($etablissements, $_GET['etablissement'] ?? null, true);
        $portee = PersonnelAcces::portee($uid, 'personnel.voir');
        $employes = (new PersonnelRepository())->liste($portee, ['statut' => 'tous', 'etablissement' => $etab ? (int) $etab['id'] : null]);
        $employe = TresorerieSaisie::id($_GET['employe'] ?? null);
        if ($employe !== null && !in_array($employe, array_map(static fn ($e) => (int) $e['id'], $employes), true)) {
            $employe = null;
        }
        View::afficher('gestion/personnel/presence/liste', [
            'titre' => t('pers.pointages_titre'),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'periode' => $periode,
            'etablissements' => $etablissements,
            'etab' => $etab,
            'employes' => $employes,
            'employe' => $employe,
            'pointages' => $this->pointages->liste($portee, [
                'debut' => substr($periode['debut'], 0, 10), 'fin' => substr($periode['fin'], 0, 10),
                'etablissement' => $etab ? (int) $etab['id'] : null, 'employe' => $employe,
            ]),
            'corrections' => $this->pointages->corrections($portee, $employe, 15),
            'peutCorriger' => Acces::a($uid, 'personnel.presence'),
            'porteeCorriger' => PersonnelAcces::portee($uid, 'personnel.presence'),
        ], 'gestion');
    }

    // ---------- Corrections ----------

    public function nouveau(): void
    {
        $utilisateur = PersonnelAcces::connecte();
        $employes = $this->employesCorrigeables((int) $utilisateur['id']);
        if ($employes === []) {
            PersonnelAcces::interdit($utilisateur);
        }
        $jour = self::date($_GET['jour'] ?? null) ?? date('Y-m-d');
        $this->formulaire($utilisateur, null, [
            'employe_id' => TresorerieSaisie::id($_GET['employe'] ?? null), 'etablissement_id' => TresorerieSaisie::id($_GET['etablissement'] ?? null),
            'arrivee' => $jour . 'T08:00', 'depart' => '', 'motif' => '',
        ]);
    }

    public function creer(): void
    {
        $utilisateur = PersonnelAcces::connecte(true);
        $this->enregistrer($utilisateur, null);
    }

    public function fiche(string $id): void
    {
        $utilisateur = PersonnelAcces::connecte();
        $pointage = $this->pointageOu404($id, (int) $utilisateur['id']);
        $this->formulaire($utilisateur, $pointage, [
            'employe_id' => (int) $pointage['employe_id'], 'etablissement_id' => (int) $pointage['etablissement_id'],
            'arrivee' => TresorerieAffichage::champDate($pointage['arrivee']), 'depart' => TresorerieAffichage::champDate($pointage['depart']), 'motif' => '',
        ]);
    }

    public function modifier(string $id): void
    {
        $utilisateur = PersonnelAcces::connecte(true);
        $this->enregistrer($utilisateur, $this->pointageOu404($id, (int) $utilisateur['id']));
    }

    public function supprimer(string $id): void
    {
        $utilisateur = PersonnelAcces::connecte(true);
        $pointage = $this->pointageOu404($id, (int) $utilisateur['id']);
        $motif = mb_substr(TresorerieSaisie::post('motif'), 0, 255);
        if ($motif === '') {
            $this->formulaire($utilisateur, $pointage, [
                'employe_id' => (int) $pointage['employe_id'], 'etablissement_id' => (int) $pointage['etablissement_id'],
                'arrivee' => TresorerieAffichage::champDate($pointage['arrivee']), 'depart' => TresorerieAffichage::champDate($pointage['depart']), 'motif' => '',
            ], ['motif_suppression' => t('pers.erreur_motif')], 422);
            return;
        }
        $this->pointages->supprimer($pointage, $motif, (int) $utilisateur['id']);
        Session::flash(t('pers.pointage_supprime', ['nom' => $pointage['employe_nom']]));
        rediriger('/gestion/personnel/presence/pointages?employe=' . (int) $pointage['employe_id']);
    }

    private function enregistrer(array $utilisateur, ?array $pointage): void
    {
        $uid = (int) $utilisateur['id'];
        $v = [
            'employe_id' => $pointage ? (int) $pointage['employe_id'] : TresorerieSaisie::id($_POST['employe_id'] ?? null),
            'etablissement_id' => $pointage ? (int) $pointage['etablissement_id'] : TresorerieSaisie::id($_POST['etablissement_id'] ?? null),
            'arrivee' => TresorerieSaisie::post('arrivee'), 'depart' => TresorerieSaisie::post('depart'),
            'motif' => mb_substr(TresorerieSaisie::post('motif'), 0, 300),
        ];
        $erreurs = [];
        $employe = $v['employe_id'] !== null ? (new PersonnelRepository())->employe($v['employe_id']) : null;
        if ($employe === null || !PersonnelAcces::peutEmploye($uid, 'personnel.presence', $employe)) {
            $erreurs['employe_id'] = t('pers.erreur_employe');
        } elseif ($v['etablissement_id'] === null || !in_array($v['etablissement_id'], $employe['etablissements'], true)
            || !Acces::a($uid, 'personnel.presence', $v['etablissement_id'])) {
            $erreurs['etablissement_id'] = t('pers.erreur_etablissement');
        }
        $arrivee = TresorerieSaisie::dateHeure($v['arrivee']);
        if ($arrivee === null) {
            $erreurs['arrivee'] = t('pers.erreur_heure_arrivee');
        } elseif ($arrivee > new \DateTimeImmutable('+5 minutes')) {
            $erreurs['arrivee'] = t('pers.erreur_futur');
        }
        $depart = null;
        if ($v['depart'] !== '') {
            $depart = TresorerieSaisie::dateHeure($v['depart']);
            if ($depart === null) {
                $erreurs['depart'] = t('pers.erreur_heure_depart');
            } elseif ($depart > new \DateTimeImmutable('+5 minutes')) {
                $erreurs['depart'] = t('pers.erreur_futur');
            }
        }
        if ($v['motif'] === '') {
            $erreurs['motif'] = t('pers.erreur_motif');
        } elseif (mb_strlen($v['motif']) > 255) {
            $erreurs['motif'] = t('tresorerie.erreur_texte_long', ['n' => 255]);
        }
        if ($erreurs === []) {
            try {
                $id = $this->pointages->enregistrerCorrection($pointage ? (int) $pointage['id'] : null, $employe, (int) $v['etablissement_id'],
                    $arrivee->format('Y-m-d H:i:s'), $depart?->format('Y-m-d H:i:s'), $v['motif'], $uid);
                Session::flash(t($pointage ? 'pers.pointage_modifie' : 'pers.pointage_ajoute', ['nom' => $employe['nom']]));
                rediriger('/gestion/personnel/presence/pointages?employe=' . (int) $employe['id'] . '&du=' . substr($arrivee->format('Y-m-d'), 0, 10)
                    . '&au=' . date('Y-m-d') . '#pointage-' . $id);
            } catch (\DomainException $e) {
                $erreurs[$e->getMessage() === 'heures' ? 'depart' : 'arrivee'] = t('pers.erreur_' . $e->getMessage());
            }
        }
        $this->formulaire($utilisateur, $pointage, $v, $erreurs, 422);
    }

    private function formulaire(array $utilisateur, ?array $pointage, array $valeurs, array $erreurs = [], int $code = 200): void
    {
        http_response_code($code);
        View::afficher('gestion/personnel/presence/formulaire', [
            'titre' => $pointage ? t('pers.corriger_pointage') : t('pers.ajouter_pointage'),
            'utilisateur' => $utilisateur,
            'pointage' => $pointage,
            'valeurs' => $valeurs,
            'erreurs' => $erreurs,
            'employes' => $this->employesCorrigeables((int) $utilisateur['id']),
            'etablissements' => PersonnelAcces::etablissements((int) $utilisateur['id'], 'personnel.presence'),
            'corrections' => $pointage ? array_values(array_filter($this->pointages->corrections(null, (int) $pointage['employe_id'], 100),
                static fn ($c) => (int) $c['pointage_id'] === (int) $pointage['id'])) : [],
        ], 'gestion');
    }

    /** @return list<array> */
    private function employesCorrigeables(int $uid): array
    {
        return (new PersonnelRepository())->liste(PersonnelAcces::portee($uid, 'personnel.presence'));
    }

    private function pointageOu404(string $id, int $uid): array
    {
        $pointage = ctype_digit($id) && strlen($id) < 10 ? $this->pointages->pointage((int) $id) : null;
        if ($pointage === null || !Acces::a($uid, 'personnel.presence', (int) $pointage['etablissement_id'])) {
            pageIntrouvable();
        }
        return $pointage;
    }

    // ---------- Bornes ----------

    public function bornes(): void
    {
        $utilisateur = PersonnelAcces::connecte();
        $uid = (int) $utilisateur['id'];
        View::afficher('gestion/personnel/presence/bornes', [
            'titre' => t('pers.bornes_titre'),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'bornes' => $this->pointages->bornes(PersonnelAcces::portee($uid, 'personnel.voir')),
            'etablissements' => PersonnelAcces::etablissements($uid, 'personnel.gerer'),
            'cetteBorne' => PointageRepository::borne(),
            'erreur' => null,
        ], 'gestion');
    }

    /** Ce navigateur devient la borne de pointage de l'établissement ; le gérant est déconnecté (comme une caisse partagée). */
    public function autoriserBorne(): void
    {
        $utilisateur = PersonnelAcces::connecte(true);
        $uid = (int) $utilisateur['id'];
        $etab = PersonnelAcces::etablissementChoisi(PersonnelAcces::etablissements($uid, 'personnel.gerer'), $_POST['etablissement_id'] ?? null, true);
        if ($etab === null) {
            PersonnelAcces::interdit($utilisateur);
        }
        $nom = mb_substr(TresorerieSaisie::post('nom'), 0, 80);
        PointageRepository::autoriserBorne($nom !== '' ? $nom : t('pers.borne_nom_defaut', ['etablissement' => $etab['nom']]), (int) $etab['id'], $uid);
        Session::deconnecter();
        rediriger('/pointage/borne');
    }

    public function retirerBorne(string $id): void
    {
        $utilisateur = PersonnelAcces::connecte(true);
        $borne = ctype_digit($id) ? $this->pointages->borneParId((int) $id) : null;
        if ($borne === null || !Acces::a((int) $utilisateur['id'], 'personnel.gerer', (int) $borne['etablissement_id'])) {
            pageIntrouvable();
        }
        $this->pointages->retirerBorne((int) $borne['id'], (int) $utilisateur['id']);
        Session::flash(t('pers.borne_retiree', ['nom' => $borne['nom']]));
        rediriger('/gestion/personnel/presence/bornes');
    }

    // ---------- Réglages ----------

    public function reglages(): void
    {
        $utilisateur = PersonnelAcces::connecte();
        $this->afficherReglages($utilisateur, null, [], Session::lireFlash());
    }

    public function enregistrerReglages(): void
    {
        $utilisateur = PersonnelAcces::connecte(true);
        $uid = (int) $utilisateur['id'];
        $etablissements = PersonnelAcces::etablissements($uid, 'personnel.gerer');
        $etab = PersonnelAcces::etablissementChoisi($etablissements, $_POST['etablissement_id'] ?? null, true);
        if ($etab === null) {
            PersonnelAcces::interdit($utilisateur);
        }
        $erreurs = [];
        $lat = TresorerieSaisie::post('latitude');
        $lng = TresorerieSaisie::post('longitude');
        $latitude = $longitude = null;
        if ($lat !== '' || $lng !== '') {
            $la = DeviseAffichage::lireNombre($lat);
            $lo = DeviseAffichage::lireNombre($lng);
            if ($la === null || (float) $la < -90 || (float) $la > 90) {
                $erreurs['latitude'] = t('pers.erreur_latitude');
            }
            if ($lo === null || (float) $lo < -180 || (float) $lo > 180) {
                $erreurs['longitude'] = t('pers.erreur_longitude');
            }
            $latitude = $la !== null ? (float) $la : null;
            $longitude = $lo !== null ? (float) $lo : null;
        }
        $rayon = TresorerieSaisie::post('rayon');
        if (!ctype_digit($rayon) || (int) $rayon < 20 || (int) $rayon > 5000) {
            $erreurs['rayon'] = t('pers.erreur_rayon');
        }
        $obligatoire = !empty($_POST['obligatoire']);
        // Règles de paie et journée d'exploitation : communes à l'instance, pour qui gère tous les établissements
        $globaux = PersonnelAcces::portee($uid, 'personnel.gerer') === null;
        $regles = [];
        if ($globaux) {
            foreach (['tolerance_retard' => [0, 60, 'personnel.tolerance_retard'], 'fin_heure' => [0, 12, 'journee.fin_heure']] as $champ => [$min, $max, $cle]) {
                $valeur = TresorerieSaisie::post($champ);
                if (!ctype_digit($valeur) || (int) $valeur < $min || (int) $valeur > $max) {
                    $erreurs[$champ] = t('pers.erreur_entier', ['min' => $min, 'max' => $max]);
                } else {
                    $regles[$cle] = (string) (int) $valeur;
                }
            }
            foreach (['majoration_heures_sup' => 'personnel.majoration_heures_sup', 'majoration_ferie' => 'personnel.majoration_ferie'] as $champ => $cle) {
                $n = DeviseAffichage::lireNombre(TresorerieSaisie::post($champ));
                if ($n === null || (float) $n < 1 || (float) $n > 5) {
                    $erreurs[$champ] = t('pers.erreur_majoration');
                } else {
                    $regles[$cle] = (string) round((float) $n, 2);
                }
            }
        }
        if ($erreurs !== []) {
            http_response_code(422);
            $this->afficherReglages($utilisateur, $etab, $erreurs, null, $_POST);
            return;
        }
        $this->pointages->reglerEtablissement((int) $etab['id'], $latitude, $longitude, (int) $rayon, $obligatoire, $uid);
        foreach ($regles as $cle => $valeur) {
            Parametres::definir($cle, $valeur, $uid);
        }
        Session::flash(t('pers.reglages_enregistres', ['etablissement' => $etab['nom']]));
        rediriger('/gestion/personnel/presence/reglages?etablissement=' . (int) $etab['id']);
    }

    private function afficherReglages(array $utilisateur, ?array $etab, array $erreurs, ?string $flash, ?array $saisie = null): void
    {
        $uid = (int) $utilisateur['id'];
        $etablissements = PersonnelAcces::etablissements($uid, 'personnel.voir');
        $etab ??= PersonnelAcces::etablissementChoisi($etablissements, $_GET['etablissement'] ?? null);
        if ($etab !== null) {
            $etab = (new EtablissementRepository())->parId((int) $etab['id']) ?? $etab;
        }
        View::afficher('gestion/personnel/presence/reglages', [
            'titre' => t('pers.reglages_titre'),
            'utilisateur' => $utilisateur,
            'flash' => $flash,
            'etablissements' => $etablissements,
            'etab' => $etab,
            'erreurs' => $erreurs,
            'saisie' => $saisie,
            'peutGerer' => $etab && Acces::a($uid, 'personnel.gerer', (int) $etab['id']),
            'globaux' => PersonnelAcces::portee($uid, 'personnel.gerer') === null,
            'regles' => [
                'tolerance_retard' => Parametres::get('personnel.tolerance_retard', '5'), 'fin_heure' => Parametres::get('journee.fin_heure', '7'),
                'majoration_heures_sup' => Parametres::get('personnel.majoration_heures_sup', '1.3'), 'majoration_ferie' => Parametres::get('personnel.majoration_ferie', '2'),
            ],
        ], 'gestion');
    }

    public static function date(mixed $valeur): ?string
    {
        if (!is_string($valeur)) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $valeur);
        return $d !== false && $d->format('Y-m-d') === $valeur && $valeur >= '2000-01-01' && $valeur <= '2100-01-01' ? $valeur : null;
    }
}
