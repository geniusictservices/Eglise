<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Acces;
use App\Core\Avatars;
use App\Core\CaissePartagee;
use App\Core\Config;
use App\Core\Journal;
use App\Core\Session;
use App\Core\View;
use App\Repositories\EtablissementRepository;
use App\Repositories\GestionUtilisateurRepository;

/**
 * Paramètres > Utilisateurs : les personnes, leurs rôles (avec la portée), leur PIN de caisse
 * et leur mot de passe de gestion.
 *
 * Règles de sécurité :
 * - on ne donne que des droits qu'on a soi-même (pour les établissements où on les a) ;
 * - seul un propriétaire modifie un propriétaire ou donne ce rôle ;
 * - il reste toujours au moins un propriétaire actif ;
 * - on ne peut pas se retirer à soi-même la gestion des utilisateurs, ni se désactiver ;
 * - deux personnes actives n'ont jamais le même PIN (sinon l'une pourrait entrer au nom de l'autre).
 */
final class UtilisateurController
{
    private const MDP_MIN = 10;
    private const MDP_MAX = 72;

    private GestionUtilisateurRepository $depot;

    public function __construct()
    {
        $this->depot = new GestionUtilisateurRepository();
    }

    public function index(): void
    {
        $moi = Session::exigerConnexion();
        View::afficher('gestion/utilisateurs/index', [
            'titre' => t('utilisateurs.titre'),
            'utilisateur' => $moi,
            'flash' => Session::lireFlash(),
            'personnes' => $this->depot->tous(),
        ], 'gestion');
    }

    public function nouveau(): void
    {
        $moi = Session::exigerConnexion();
        $this->afficherFiche($moi, null, ['valeurs' => ['langue' => \App\Core\I18n::langue()], 'roles' => []]);
    }

    public function creer(): void
    {
        $moi = Session::exigerConnexion();
        Session::verifierCsrf();
        $this->enregistrer($moi, null);
    }

    public function fiche(string $id): void
    {
        $moi = Session::exigerConnexion();
        $personne = $this->personneOu404($id);
        $this->afficherFiche($moi, $personne, []);
    }

    public function modifier(string $id): void
    {
        $moi = Session::exigerConnexion();
        Session::verifierCsrf();
        $personne = $this->personneOu404($id);
        $this->exigerDroitSur($moi, $personne);
        $this->enregistrer($moi, $personne);
    }

    /** Nouveau PIN : tapé par le gérant devant la personne, ou tiré au hasard et montré une seule fois. */
    public function pin(string $id): void
    {
        $moi = Session::exigerConnexion();
        Session::verifierCsrf();
        $personne = $this->personneOu404($id);
        $this->exigerDroitSur($moi, $personne);

        if (($_POST['action'] ?? '') === 'retirer') {
            $this->depot->supprimerPin((int) $personne['id']);
            Journal::ecrire('pin_retire', $personne['nom']);
            Session::flash(t('utilisateurs.pin_retire', ['nom' => $personne['nom']]));
            rediriger('/gestion/utilisateurs/' . (int) $personne['id'] . '#section-pin');
        }
        if (($_POST['action'] ?? '') === 'generer') {
            $pin = $this->pinAuHasard((int) $personne['id']);
            $this->depot->definirPin((int) $personne['id'], $pin);
            Journal::ecrire('pin_defini', $personne['nom'], ['genere' => true]);
            // Montré une seule fois, sur la page qui suit
            $_SESSION['pin_montre'] = ['id' => (int) $personne['id'], 'pin' => $pin];
            rediriger('/gestion/utilisateurs/' . (int) $personne['id'] . '#section-pin');
        }
        $pin = (string) ($_POST['pin'] ?? '');
        $erreur = $this->erreurPin($pin, (int) $personne['id']);
        if ($erreur !== null) {
            $this->afficherFiche($moi, $personne, ['erreurs' => ['pin' => $erreur]], 422);
            return;
        }
        $this->depot->definirPin((int) $personne['id'], $pin);
        Journal::ecrire('pin_defini', $personne['nom']);
        Session::flash(t('utilisateurs.pin_defini', ['nom' => $personne['nom']]));
        rediriger('/gestion/utilisateurs/' . (int) $personne['id'] . '#section-pin');
    }

    /** Photo de profil choisie par le gérant (ou retirée). */
    public function photo(string $id): void
    {
        $moi = Session::exigerConnexion();
        Session::verifierCsrf();
        $personne = $this->personneOu404($id);
        $this->exigerDroitSur($moi, $personne);
        $erreur = Avatars::traiterFormulaire((int) $personne['id'], $personne['nom']);
        if ($erreur !== null) {
            $this->afficherFiche($moi, $personne, ['erreurPhoto' => $erreur], 422);
            return;
        }
        Session::flash(t('profil.photo_enregistree', ['nom' => $personne['nom']]));
        rediriger('/gestion/utilisateurs/' . (int) $personne['id']);
    }

    public function debloquer(string $id): void
    {
        $moi = Session::exigerConnexion();
        Session::verifierCsrf();
        $personne = $this->personneOu404($id);
        $this->exigerDroitSur($moi, $personne);
        $this->depot->debloquer((int) $personne['id']);
        Journal::ecrire('utilisateur_debloque', $personne['nom']);
        Session::flash(t('utilisateurs.debloque', ['nom' => $personne['nom']]));
        rediriger('/gestion/utilisateurs/' . (int) $personne['id']);
    }

    public function desactiver(string $id): void
    {
        $this->changerEtat($id, false);
    }

    public function activer(string $id): void
    {
        $this->changerEtat($id, true);
    }

    private function changerEtat(string $id, bool $actif): void
    {
        $moi = Session::exigerConnexion();
        Session::verifierCsrf();
        $personne = $this->personneOu404($id);
        $this->exigerDroitSur($moi, $personne);
        if (!$actif) {
            if ((int) $personne['id'] === (int) $moi['id']) {
                $this->afficherFiche($moi, $personne, ['erreurs' => ['general' => t('utilisateurs.erreur_soi_desactiver')]], 422);
                return;
            }
            if ($this->dernierProprietaire((int) $personne['id'])) {
                $this->afficherFiche($moi, $personne, ['erreurs' => ['general' => t('utilisateurs.erreur_dernier_proprietaire')]], 422);
                return;
            }
        }
        $this->depot->definirActif((int) $personne['id'], $actif);
        Acces::oublier((int) $personne['id']);
        Journal::ecrire($actif ? 'utilisateur_active' : 'utilisateur_desactive', $personne['nom']);
        Session::flash(t($actif ? 'utilisateurs.active' : 'utilisateurs.desactive', ['nom' => $personne['nom']]));
        rediriger('/gestion/utilisateurs');
    }

    // ---------- Enregistrement ----------

    private function enregistrer(array $moi, ?array $personne): void
    {
        $idPersonne = $personne ? (int) $personne['id'] : null;
        $nom = $this->texte('nom', 128);
        $poste = $this->texte('poste', 80);
        $telephoneSaisi = $this->texte('telephone', 32);
        $telephone = $telephoneSaisi !== '' ? normaliserTelephone($telephoneSaisi) : '';
        $email = mb_strtolower($this->texte('email', 128));
        $langue = in_array($_POST['langue'] ?? '', Config::get('langues', ['fr', 'en']), true) ? $_POST['langue'] : 'fr';
        $motDePasse = is_string($_POST['mot_de_passe'] ?? null) ? $_POST['mot_de_passe'] : '';
        $pin = is_string($_POST['pin'] ?? null) ? trim($_POST['pin']) : '';
        $roles = $this->lireRoles();

        $erreurs = [];
        if ($nom === '') {
            $erreurs['nom'] = t('utilisateurs.erreur_nom');
        }
        if ($telephone !== '' && !preg_match('/^\+?\d{9,15}$/', $telephone)) {
            $erreurs['telephone'] = t('utilisateurs.erreur_telephone');
        } elseif ($telephone !== '' && $this->depot->existe('telephone', $telephone, $idPersonne)) {
            $erreurs['telephone'] = t('utilisateurs.erreur_telephone_pris');
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $erreurs['email'] = t('utilisateurs.erreur_email');
        } elseif ($email !== '' && $this->depot->existe('email', $email, $idPersonne)) {
            $erreurs['email'] = t('utilisateurs.erreur_email_pris');
        }
        if ($motDePasse !== '' && (mb_strlen($motDePasse) < self::MDP_MIN || strlen($motDePasse) > self::MDP_MAX)) {
            $erreurs['mot_de_passe'] = t('utilisateurs.erreur_mot_de_passe', ['min' => self::MDP_MIN, 'max' => self::MDP_MAX]);
        }
        if ($pin !== '') {
            $erreurPin = $this->erreurPin($pin, $idPersonne);
            if ($erreurPin !== null) {
                $erreurs['pin'] = $erreurPin;
            }
        }
        $erreurRoles = $this->erreurRoles($moi, $personne, $roles);
        if ($erreurRoles !== null) {
            $erreurs['roles'] = $erreurRoles;
        }

        $valeurs = ['nom' => $nom, 'poste' => $poste ?: null, 'telephone' => $telephone ?: null, 'email' => $email ?: null, 'langue' => $langue];
        if ($erreurs !== []) {
            $this->afficherFiche($moi, $personne, [
                'erreurs' => $erreurs,
                'valeurs' => $valeurs + ['telephone_saisi' => $telephoneSaisi],
                'roles' => $roles,
            ], 422);
            return;
        }

        $id = $this->depot->enregistrer($idPersonne, $valeurs, $roles, $motDePasse !== '' ? $motDePasse : null, $pin !== '' ? $pin : null);
        Acces::oublier($id);
        Journal::ecrire($personne ? 'utilisateur_modifie' : 'utilisateur_cree', $nom, [
            'roles' => array_map(static fn (array $r) => $r['role_id'] . '@' . ($r['etablissement_id'] ?? '*'), $roles),
            'mot_de_passe' => $motDePasse !== '',
            'pin' => $pin !== '',
        ]);
        if ((int) $moi['id'] === $id) {
            // Son propre nom apparaît dans la barre et à la caisse
            $_SESSION['utilisateur']['nom'] = $nom;
        }
        Session::flash(t($personne ? 'utilisateurs.modifie' : 'utilisateurs.cree', ['nom' => $nom]));
        rediriger('/gestion/utilisateurs/' . $id);
    }

    /** @return list<array{role_id: int, etablissement_id: ?int}> */
    private function lireRoles(): array
    {
        $rolesSaisis = is_array($_POST['role'] ?? null) ? $_POST['role'] : [];
        $portees = is_array($_POST['portee'] ?? null) ? $_POST['portee'] : [];
        $existants = array_column($this->depot->roles(), null, 'id');
        $etablissements = array_column((new EtablissementRepository())->actifs(), null, 'id');
        $roles = [];
        foreach ($rolesSaisis as $i => $roleId) {
            $roleId = (int) $roleId;
            if ($roleId <= 0 || !isset($existants[$roleId])) {
                continue;
            }
            $portee = (int) ($portees[$i] ?? 0);
            $cle = $roleId . '@' . $portee;
            $roles[$cle] = ['role_id' => $roleId, 'etablissement_id' => $portee > 0 && isset($etablissements[$portee]) ? $portee : null];
        }
        return array_values($roles);
    }

    private function erreurRoles(array $moi, ?array $personne, array $roles): ?string
    {
        $moiId = (int) $moi['id'];
        $tousRoles = array_column($this->depot->roles(), null, 'id');
        foreach ($roles as $role) {
            $detail = $tousRoles[$role['role_id']];
            if ($detail['tout'] && !Acces::droits($moiId)['tout']) {
                return t('utilisateurs.erreur_donner_proprietaire');
            }
            foreach ($detail['permissions'] as $permission) {
                if (!$this->peutDonner($moiId, $permission, $role['etablissement_id'])) {
                    return t('utilisateurs.erreur_donner_droit', ['role' => champ($detail, 'nom')]);
                }
            }
        }
        if ($personne !== null) {
            $resteProprietaire = false;
            $garde = false;
            foreach ($roles as $role) {
                $detail = $tousRoles[$role['role_id']];
                if ($detail['tout'] && $role['etablissement_id'] === null) {
                    $resteProprietaire = true;
                }
                if (($detail['tout'] && $role['etablissement_id'] === null) || in_array('parametres.utilisateurs', $detail['permissions'], true)) {
                    $garde = true;
                }
            }
            if (!$resteProprietaire && $this->dernierProprietaire((int) $personne['id'])) {
                return t('utilisateurs.erreur_dernier_proprietaire');
            }
            if ((int) $personne['id'] === $moiId && !$garde) {
                return t('utilisateurs.erreur_soi_droits');
            }
        }
        return null;
    }

    /** Peut-on donner cette permission pour cet établissement (null = tous) ? Seulement si on l'a soi-même. */
    private function peutDonner(int $moiId, string $permission, ?int $etablissementId): bool
    {
        if ($etablissementId === null) {
            return Acces::etablissements($moiId, $permission) === null && Acces::a($moiId, $permission);
        }
        return Acces::a($moiId, $permission, $etablissementId);
    }

    private function erreurPin(string $pin, ?int $saufId): ?string
    {
        if (!CaissePartagee::pinValide($pin)) {
            return t('utilisateurs.erreur_pin', ['n' => CaissePartagee::LONGUEUR_PIN]);
        }
        if (CaissePartagee::pinTropSimple($pin)) {
            return t('utilisateurs.erreur_pin_simple');
        }
        if ($this->depot->pinDejaPris($pin, $saufId)) {
            return t('utilisateurs.erreur_pin_pris');
        }
        return null;
    }

    private function pinAuHasard(int $saufId): string
    {
        do {
            $pin = str_pad((string) random_int(0, 999999), CaissePartagee::LONGUEUR_PIN, '0', STR_PAD_LEFT);
        } while (CaissePartagee::pinTropSimple($pin) || $this->depot->pinDejaPris($pin, $saufId));
        return $pin;
    }

    private function dernierProprietaire(int $id): bool
    {
        $proprietaires = $this->depot->proprietaires();
        return $proprietaires === [$id];
    }

    /**
     * On ne modifie (rôles, PIN, mot de passe, état) qu'une personne dont on pourrait donner soi-même
     * tous les rôles actuels : sinon un gérant pourrait changer le mot de passe du comptable,
     * puis se connecter avec ses droits. Seul un propriétaire modifie un propriétaire.
     */
    private function exigerDroitSur(array $moi, array $personne): void
    {
        if (!$this->peutModifier((int) $moi['id'], $personne)) {
            http_response_code(403);
            View::afficher('gestion/interdit', ['titre' => t('acces.interdit_titre'), 'utilisateur' => $moi], 'gestion');
            exit;
        }
    }

    private function peutModifier(int $moiId, array $personne): bool
    {
        if (Acces::droits($moiId)['tout']) {
            return true;
        }
        $tousRoles = array_column($this->depot->roles(), null, 'id');
        foreach ($personne['roles'] as $role) {
            $detail = $tousRoles[(int) $role['role_id']] ?? null;
            if ($detail === null || $detail['tout']) {
                return false;
            }
            $portee = $role['etablissement_id'] === null ? null : (int) $role['etablissement_id'];
            foreach ($detail['permissions'] as $permission) {
                if (!$this->peutDonner($moiId, $permission, $portee)) {
                    return false;
                }
            }
        }
        return true;
    }

    private function personneOu404(string $id): array
    {
        $personne = ctype_digit($id) ? $this->depot->parId((int) $id) : null;
        if ($personne === null) {
            pageIntrouvable();
        }
        return $personne;
    }

    private function texte(string $cle, int $max): string
    {
        $valeur = is_string($_POST[$cle] ?? null) ? trim((string) preg_replace('/\p{Cc}/u', '', $_POST[$cle])) : '';
        return mb_substr($valeur, 0, $max);
    }

    private function afficherFiche(array $moi, ?array $personne, array $etat, int $code = 200): void
    {
        http_response_code($code);
        $pinMontre = null;
        if ($personne !== null && isset($_SESSION['pin_montre']) && (int) $_SESSION['pin_montre']['id'] === (int) $personne['id']) {
            $pinMontre = $_SESSION['pin_montre']['pin'];
            unset($_SESSION['pin_montre']);
        }
        $valeurs = $etat['valeurs'] ?? ($personne ?? []);
        $roles = $etat['roles'] ?? array_map(
            static fn (array $r) => ['role_id' => (int) $r['role_id'], 'etablissement_id' => $r['etablissement_id'] === null ? null : (int) $r['etablissement_id']],
            $personne['roles'] ?? []
        );
        View::afficher('gestion/utilisateurs/fiche', [
            'titre' => $personne ? $personne['nom'] : t('utilisateurs.nouveau'),
            'utilisateur' => $moi,
            'flash' => Session::lireFlash(),
            'personne' => $personne,
            'valeurs' => $valeurs,
            'rolesChoisis' => $roles,
            'roles' => $this->depot->roles(),
            'etablissements' => (new EtablissementRepository())->actifs(),
            'erreurs' => $etat['erreurs'] ?? [],
            'erreurPhoto' => $etat['erreurPhoto'] ?? null,
            'pinMontre' => $pinMontre,
            'moiProprietaire' => Acces::droits((int) $moi['id'])['tout'],
            'aLaGestion' => $personne ? Acces::aLaGestion((int) $personne['id']) : false,
            'modifiable' => $personne === null || $this->peutModifier((int) $moi['id'], $personne),
        ], 'gestion');
    }
}
