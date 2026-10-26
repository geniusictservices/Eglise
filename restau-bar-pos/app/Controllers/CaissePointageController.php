<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Acces;
use App\Core\CaissePartagee;
use App\Core\Database;
use App\Core\Journal;
use App\Core\Session;
use App\Repositories\PersonnelRepository;
use App\Repositories\PointageRepository;

/**
 * Pointage et caisse.
 *
 * - « Pointage obligatoire pour ouvrir la caisse » (au choix de chaque établissement) : un employé (fiche liée
 *   à son compte) qui n'a pas pointé son arrivée dans l'établissement ne peut pas encaisser ni ouvrir sa
 *   session de caisse (refus 409, code « pointage_requis »). Une personne sans fiche employé (propriétaire…)
 *   n'est pas concernée.
 * - Pointage de secours (employé sans smartphone, téléphone en panne) : sur la caisse, PIN de l'employé
 *   (celui de son compte, ou son PIN de pointage) validé par le PIN d'un gérant (personnel.presence).
 */
final class CaissePointageController
{
    /**
     * État du pointage de la personne à la caisse (bandeau, fenêtre de la personne).
     * @return array{obligatoire: bool, employe: ?array, present: ?array, requis: bool, employes: list<array>, valideurs: list<array>}
     */
    public static function etat(array $etab, int $utilisateurId): array
    {
        static $cache = [];
        $etabId = (int) $etab['id'];
        if (isset($cache[$etabId . '-' . $utilisateurId])) {
            return $cache[$etabId . '-' . $utilisateurId];
        }
        $pointages = new PointageRepository();
        $employe = (new PersonnelRepository())->parUtilisateur($utilisateurId);
        if ($employe !== null && !$employe['actif']) {
            $employe = null;
        }
        $ouverte = $employe ? $pointages->ouverte((int) $employe['id']) : null;
        $present = $ouverte !== null && (int) $ouverte['etablissement_id'] === $etabId ? $ouverte : null;
        $obligatoire = !empty($etab['pointage_obligatoire']) && \App\Core\Plateforme::moduleInclus('personnel');
        $employes = [];
        foreach ((new PersonnelRepository())->deLEtablissement($etabId) as $e) {
            $o = $pointages->ouverte((int) $e['id']);
            $employes[] = ['id' => (int) $e['id'], 'nom' => $e['nom'], 'present' => $o !== null && (int) $o['etablissement_id'] === $etabId,
                'depuis' => $o ? date('H:i', strtotime($o['arrivee'])) : null, 'pin' => $e['utilisateur_id'] !== null ? (bool) $e['a_pin'] : $e['pin_pointage'] !== null];
        }
        $valideurs = [];
        foreach (Database::tous('SELECT id, nom FROM utilisateurs WHERE actif = 1 AND pin IS NOT NULL ORDER BY nom') as $u) {
            if (Acces::a((int) $u['id'], 'personnel.presence', $etabId)) {
                $valideurs[] = ['id' => (int) $u['id'], 'nom' => $u['nom']];
            }
        }
        return $cache[$etabId . '-' . $utilisateurId] = [
            'obligatoire' => $obligatoire, 'employe' => $employe, 'present' => $present,
            'requis' => $obligatoire && $employe !== null && $present === null,
            'employes' => $employes, 'valideurs' => $valideurs,
        ];
    }

    /** Refus 409 (JSON) si l'établissement exige le pointage et que la personne n'a pas pointé son arrivée ici. */
    public static function exigerPointage(array $etab, array $personne): void
    {
        if (empty($etab['pointage_obligatoire']) || !PersonnelRepository::installe() || !\App\Core\Plateforme::moduleInclus('personnel')) {
            return;
        }
        $employe = (new PersonnelRepository())->parUtilisateur((int) $personne['id']);
        if ($employe === null || !$employe['actif'] || (new PointageRepository())->estPresent((int) $employe['id'], (int) $etab['id'])) {
            return;
        }
        http_response_code(409);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['erreur' => t('caisse_pointage.requis', ['etablissement' => $etab['nom']]), 'code' => 'pointage_requis'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** POST /caisse/pointage : etab, employe_id, pin_employe, valideur_id, pin_valideur. */
    public function pointer(): void
    {
        $json = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
        if (!\App\Core\Plateforme::moduleInclus('personnel')) {
            pageIntrouvable();
        }
        Session::demarrer();
        $jeton = $_POST['csrf'] ?? '';
        if (!is_string($jeton) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $jeton)) {
            $this->repondre($json, 419, t('encaisser.expire'), null);
        }
        $vendeur = CaisseController::vendeur(is_string($_POST['etab'] ?? null) ? $_POST['etab'] : null);
        if ($vendeur === null) {
            $this->repondre($json, 401, t('encaisser.connexion'), null);
        }
        ['etab' => $etab, 'appareil' => $appareil] = $vendeur;
        $etabId = (int) $etab['id'];
        $appareilId = $appareil ? (int) $appareil['id'] : null;
        if ($appareilId !== null && CaissePartagee::appareilSature($appareilId)) {
            $this->repondre($json, 429, t('caisse_pin.appareil_sature'), $etab);
        }
        $employeId = (int) ($_POST['employe_id'] ?? 0);
        $employe = null;
        foreach ((new PersonnelRepository())->deLEtablissement($etabId) as $e) {
            if ((int) $e['id'] === $employeId) {
                $employe = $e;
            }
        }
        if ($employe === null) {
            $this->repondre($json, 422, t('caisse_pointage.choisir_employe'), $etab);
        }
        $valideurId = (int) ($_POST['valideur_id'] ?? 0);
        $valideur = $valideurId > 0 ? Database::un('SELECT * FROM utilisateurs WHERE id = ? AND actif = 1 AND pin IS NOT NULL', [$valideurId]) : null;
        if ($valideur === null || !Acces::a($valideurId, 'personnel.presence', $etabId)) {
            $this->repondre($json, 422, t('caisse_pointage.choisir_valideur'), $etab);
        }
        if ($employe['utilisateur_id'] !== null && (int) $employe['utilisateur_id'] === $valideurId) {
            $this->repondre($json, 422, t('caisse_pointage.autre_valideur'), $etab);
        }
        // PIN de l'employé (compte ou PIN de pointage) ; sans aucun PIN, celui du gérant suffit (inscrit au journal)
        $pinEmploye = preg_replace('/\D+/', '', (string) ($_POST['pin_employe'] ?? '')) ?? '';
        if ($employe['utilisateur_id'] !== null) {
            $compte = Database::un('SELECT * FROM utilisateurs WHERE id = ? AND actif = 1', [(int) $employe['utilisateur_id']]);
            if ($compte !== null && $compte['pin'] !== null) {
                $erreur = $this->verifierPinCompte($compte, $pinEmploye, $appareilId);
                if ($erreur !== null) {
                    $this->repondre($json, 422, t('caisse_pointage.pin_employe') . ' ' . $erreur, $etab);
                }
            }
        } elseif ($employe['pin_pointage'] !== null) {
            $erreur = $this->verifierPinEmploye($employe, $pinEmploye, $appareilId);
            if ($erreur !== null) {
                $this->repondre($json, 422, t('caisse_pointage.pin_employe') . ' ' . $erreur, $etab);
            }
        }
        $erreur = $this->verifierPinCompte($valideur, preg_replace('/\D+/', '', (string) ($_POST['pin_valideur'] ?? '')) ?? '', $appareilId);
        if ($erreur !== null) {
            $this->repondre($json, 422, t('caisse_pointage.pin_valideur') . ' ' . $erreur, $etab);
        }
        try {
            $resultat = (new PointageRepository())->pointer($employe, $etabId, [
                'source' => 'secours', 'valide_par' => $valideurId, 'appareil_id' => $appareilId, 'sessions' => !empty($etab['sessions_caisse']),
            ]);
        } catch (\DomainException $e) {
            $this->repondre($json, 409, t('pointage.erreur_' . $e->getMessage(), ['etablissement' => $etab['nom']]), $etab);
        }
        $p = $resultat['pointage'];
        $heure = date('H:i', strtotime($resultat['sens'] === 'arrivee' ? $p['arrivee'] : $p['depart']));
        $this->repondre($json, 200, t('caisse_pointage.ok_' . $resultat['sens'], ['nom' => $employe['nom'], 'heure' => $heure, 'valideur' => $valideur['nom']]), $etab, $resultat['sens']);
    }

    /** @return ?string message d'erreur, null si le PIN est bon (mêmes règles que la caisse : 5 essais puis blocage) */
    private function verifierPinCompte(array $compte, string $pin, ?int $appareilId): ?string
    {
        if ($compte['pin_bloque_le'] !== null) {
            return t('caisse_pin.bloque', ['nom' => $compte['nom']]);
        }
        if (CaissePartagee::pinValide($pin) && password_verify($pin, (string) $compte['pin'])) {
            Database::executer('UPDATE utilisateurs SET pin_essais = 0 WHERE id = ?', [(int) $compte['id']]);
            return null;
        }
        $essais = (int) $compte['pin_essais'] + 1;
        $bloque = $essais >= CaissePartagee::ESSAIS_PIN;
        Database::executer('UPDATE utilisateurs SET pin_essais = ?, pin_bloque_le = ? WHERE id = ?', [$bloque ? 0 : $essais, $bloque ? date('Y-m-d H:i:s') : null, (int) $compte['id']]);
        Journal::ecrire($bloque ? 'pin_bloque' : 'pin_echec', $compte['nom'], ['pointage_secours' => true], (int) $compte['id'], $appareilId);
        if ($bloque) {
            Journal::ecrire('pin_echec', $compte['nom'], ['pointage_secours' => true], (int) $compte['id'], $appareilId);
        }
        return $bloque ? t('caisse_pin.bloque', ['nom' => $compte['nom']]) : t('caisse_pin.incorrect', ['reste' => CaissePartagee::ESSAIS_PIN - $essais]);
    }

    private function verifierPinEmploye(array $employe, string $pin, ?int $appareilId): ?string
    {
        if ($employe['pin_bloque_le'] !== null) {
            return t('caisse_pin.bloque', ['nom' => $employe['nom']]);
        }
        if (CaissePartagee::pinValide($pin) && password_verify($pin, (string) $employe['pin_pointage'])) {
            Database::executer('UPDATE employes SET pin_essais = 0 WHERE id = ?', [(int) $employe['id']]);
            return null;
        }
        $essais = (int) $employe['pin_essais'] + 1;
        $bloque = $essais >= CaissePartagee::ESSAIS_PIN;
        Database::executer('UPDATE employes SET pin_essais = ?, pin_bloque_le = ? WHERE id = ?', [$bloque ? 0 : $essais, $bloque ? date('Y-m-d H:i:s') : null, (int) $employe['id']]);
        Journal::ecrire('pin_echec', $employe['nom'], ['pointage_secours' => true, 'employe' => (int) $employe['id']], null, $appareilId);
        return $bloque ? t('caisse_pin.bloque', ['nom' => $employe['nom']]) : t('caisse_pin.incorrect', ['reste' => CaissePartagee::ESSAIS_PIN - $essais]);
    }

    private function repondre(bool $json, int $code, string $message, ?array $etab, ?string $sens = null): never
    {
        if ($json) {
            http_response_code($code);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($code === 200 ? ['message' => $message, 'sens' => $sens, 'csrf' => Session::jetonCsrf()] : ['erreur' => $message], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // Sans JavaScript : retour à la caisse avec le message
        $_SESSION['caisse_pointage'] = ['ok' => $code === 200, 'message' => $message];
        rediriger('/caisse' . ($etab !== null && CaissePartagee::appareil() === null ? '?e=' . $etab['slug'] : ''));
    }
}
