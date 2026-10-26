<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\CaissePartagee;
use App\Core\Database;
use App\Core\Journal;
use App\Core\Parametres;

/**
 * Pointage (cahier des charges, sections 13.4 et 17).
 *
 * Borne : une tablette à l'entrée, autorisée par un gérant (cookie « rbp_borne », empreinte en base, comme
 * les caisses partagées), affiche un code QR qui change toutes les FENETRE secondes. Le code porte
 * « borne-fenêtre-signature » (HMAC-SHA256 avec le secret de l'instance) : on ne peut ni le fabriquer
 * ni réutiliser une photo prise plus tôt (il n'est accepté que pendant VALIDITE fenêtres, le temps de
 * donner sa position), et un même code ne sert qu'une fois à une même personne.
 *
 * Téléphone : la première fois, l'employé donne son numéro et son PIN de 6 chiffres ; le téléphone est
 * ensuite reconnu par un cookie de longue durée (« rbp_pointage »). Un seul téléphone par employé.
 *
 * Position : le téléphone envoie sa position GPS ; la distance à l'établissement doit rester sous son rayon
 * (100 m par défaut). Pointage de secours à la caisse : PIN de l'employé validé par le PIN d'un gérant.
 */
final class PointageRepository
{
    public const COOKIE_BORNE = 'rbp_borne';
    public const COOKIE_TELEPHONE = 'rbp_pointage';
    /** Durée d'un code QR à l'écran (secondes) */
    public const FENETRE = 30;
    /** Fenêtres acceptées : la courante et les trois précédentes (le temps de scanner et de donner sa position) */
    public const VALIDITE = 4;
    /** Une présence ouverte depuis plus longtemps est un départ oublié (ce n'est plus « présent ») */
    public const HEURES_MAX = 16;
    /** Deux pointages d'une même personne à moins d'une minute : double scan */
    public const ECART_MIN = 60;
    /** Précision GPS au-delà de laquelle la position est refusée (mètres) */
    public const PRECISION_MAX = 300;
    public const ESSAIS_PIN = 5;
    /** Essais d'identification ratés depuis une même adresse IP en 10 minutes avant refus */
    public const ECHECS_IP = 10;

    private static array|false|null $borne = null;

    // ---------- Bornes ----------

    /** Borne de ce navigateur (cookie), ou null. */
    public static function borne(): ?array
    {
        if (self::$borne === null) {
            self::$borne = false;
            $jeton = $_COOKIE[self::COOKIE_BORNE] ?? '';
            if (is_string($jeton) && preg_match('/^[a-f0-9]{64}$/', $jeton)) {
                $borne = Database::un(
                    'SELECT b.*, e.nom AS etablissement_nom, e.slug, e.type AS etablissement_type, e.latitude, e.longitude
                       FROM pointage_bornes b JOIN etablissements e ON e.id = b.etablissement_id
                      WHERE b.jeton = ? AND b.actif = 1 AND e.actif = 1',
                    [hash('sha256', $jeton)]
                );
                if ($borne !== null) {
                    self::$borne = $borne;
                    if ($borne['dernier_usage'] === null || strtotime($borne['dernier_usage']) < time() - 300) {
                        Database::executer('UPDATE pointage_bornes SET dernier_usage = NOW() WHERE id = ?', [$borne['id']]);
                    }
                }
            }
        }
        return self::$borne ?: null;
    }

    public static function autoriserBorne(string $nom, int $etablissementId, int $par): int
    {
        $jeton = bin2hex(random_bytes(32));
        Database::executer('INSERT INTO pointage_bornes (etablissement_id, nom, jeton, autorise_par) VALUES (?, ?, ?, ?)',
            [$etablissementId, $nom, hash('sha256', $jeton), $par]);
        $id = Database::dernierId();
        setcookie(self::COOKIE_BORNE, $jeton, [
            'expires' => time() + 400 * 86400, 'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Strict',
        ]);
        $_COOKIE[self::COOKIE_BORNE] = $jeton;
        self::$borne = null;
        Journal::ecrire('borne_autorisee', $nom, ['etablissement' => $etablissementId, 'borne' => $id], $par);
        return $id;
    }

    /** @return list<array> */
    public function bornes(?array $portee): array
    {
        $params = [];
        $where = '1 = 1';
        if ($portee !== null) {
            if ($portee === []) {
                return [];
            }
            $where = 'b.etablissement_id IN (' . implode(',', array_fill(0, count($portee), '?')) . ')';
            $params = array_map('intval', $portee);
        }
        return Database::tous(
            "SELECT b.*, e.nom AS etablissement_nom, u.nom AS autorise_par_nom FROM pointage_bornes b
               JOIN etablissements e ON e.id = b.etablissement_id LEFT JOIN utilisateurs u ON u.id = b.autorise_par
              WHERE $where ORDER BY b.actif DESC, e.nom, b.nom",
            $params
        );
    }

    public function borneParId(int $id): ?array
    {
        return Database::un('SELECT * FROM pointage_bornes WHERE id = ?', [$id]);
    }

    public function retirerBorne(int $id, int $par): void
    {
        Database::executer('UPDATE pointage_bornes SET actif = 0 WHERE id = ?', [$id]);
        Journal::ecrire('borne_retiree', 'borne ' . $id, [], $par);
    }

    // ---------- Codes QR ----------

    public static function fenetre(?int $horodatage = null): int
    {
        return intdiv($horodatage ?? time(), self::FENETRE);
    }

    private static function secret(): string
    {
        $secret = (string) Parametres::get('pointage.secret', '');
        if ($secret === '') {
            throw new \LogicException('Secret de pointage absent (migration 018)');
        }
        return $secret;
    }

    public static function jeton(array $borne, int $fenetre): string
    {
        $signature = substr(hash_hmac('sha256', 'pointage|' . (int) $borne['id'] . '|' . (int) $borne['etablissement_id'] . '|' . $fenetre, self::secret()), 0, 24);
        return (int) $borne['id'] . '-' . $fenetre . '-' . $signature;
    }

    /**
     * Code QR lu par le téléphone : borne active, signature juste, pas trop vieux.
     * @return array{borne: array, fenetre: int}
     * @throws \DomainException « qr_invalide », « qr_expire »
     */
    public function lireJeton(string $jeton, ?int $maintenant = null): array
    {
        if (!preg_match('/^(\d{1,10})-(\d{1,12})-([a-f0-9]{24})$/', $jeton, $m)) {
            throw new \DomainException('qr_invalide');
        }
        $borne = Database::un(
            'SELECT b.*, e.nom AS etablissement_nom, e.latitude, e.longitude, e.pointage_rayon, e.sessions_caisse
               FROM pointage_bornes b JOIN etablissements e ON e.id = b.etablissement_id WHERE b.id = ? AND b.actif = 1 AND e.actif = 1',
            [(int) $m[1]]
        );
        if ($borne === null || !hash_equals(self::jeton($borne, (int) $m[2]), $jeton)) {
            throw new \DomainException('qr_invalide');
        }
        $age = self::fenetre($maintenant) - (int) $m[2];
        if ($age < 0 || $age >= self::VALIDITE) {
            throw new \DomainException('qr_expire');
        }
        return ['borne' => $borne, 'fenetre' => (int) $m[2]];
    }

    // ---------- Téléphone de l'employé ----------

    /** Employé reconnu par le cookie de ce téléphone, ou null. */
    public function employeDuTelephone(): ?array
    {
        $jeton = $_COOKIE[self::COOKIE_TELEPHONE] ?? '';
        if (!is_string($jeton) || !preg_match('/^[a-f0-9]{64}$/', $jeton)) {
            return null;
        }
        $ligne = Database::un(
            'SELECT t.id AS telephone_id, t.dernier_usage AS telephone_usage, e.* FROM pointage_telephones t JOIN employes e ON e.id = t.employe_id
              LEFT JOIN utilisateurs u ON u.id = e.utilisateur_id
              WHERE t.jeton = ? AND t.actif = 1 AND e.actif = 1 AND (e.utilisateur_id IS NULL OR u.actif = 1)',
            [hash('sha256', $jeton)]
        );
        if ($ligne === null) {
            return null;
        }
        if ($ligne['telephone_usage'] === null || strtotime($ligne['telephone_usage']) < time() - 300) {
            Database::executer('UPDATE pointage_telephones SET dernier_usage = NOW() WHERE id = ?', [$ligne['telephone_id']]);
        }
        $ligne['etablissements'] = array_map('intval', array_column(
            Database::tous('SELECT etablissement_id FROM employe_etablissements WHERE employe_id = ?', [(int) $ligne['id']]), 'etablissement_id'));
        return $ligne;
    }

    /**
     * Identifier ce téléphone : numéro (du compte ou de la fiche employé) et PIN de 6 chiffres.
     * Le PIN d'un compte est celui de la caisse (mêmes essais, même blocage) ; sans compte, le PIN de pointage.
     * @throws \DomainException « trop_essais », « identification », « bloque », « sans_pin »
     */
    public function identifier(string $saisieTelephone, string $pin, string $appareil): array
    {
        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $echecs = (int) Database::un("SELECT COUNT(*) AS n FROM journal WHERE action = 'pointage_identification_echec' AND ip = ? AND cree_le >= ?",
            [$ip, date('Y-m-d H:i:s', time() - 600)])['n'];
        if ($echecs >= self::ECHECS_IP) {
            throw new \DomainException('trop_essais');
        }
        $telephone = DetteRepository::normaliserTelephone($saisieTelephone);
        $employe = null;
        if ($telephone !== null) {
            $employe = Database::un(
                'SELECT e.*, u.pin AS compte_pin, u.pin_essais AS compte_essais, u.pin_bloque_le AS compte_bloque, u.nom AS compte_nom
                   FROM employes e JOIN utilisateurs u ON u.id = e.utilisateur_id
                  WHERE u.telephone = ? AND u.actif = 1 AND e.actif = 1',
                [$telephone]
            ) ?? Database::un('SELECT e.*, NULL AS compte_pin FROM employes e WHERE e.telephone = ? AND e.actif = 1 AND e.utilisateur_id IS NULL', [$telephone]);
        }
        if ($employe === null) {
            Journal::ecrire('pointage_identification_echec', $telephone ?? mb_substr($saisieTelephone, 0, 40), ['raison' => 'inconnu']);
            throw new \DomainException('identification');
        }
        $avecCompte = $employe['utilisateur_id'] !== null;
        $empreinte = $avecCompte ? $employe['compte_pin'] : $employe['pin_pointage'];
        $bloque = $avecCompte ? $employe['compte_bloque'] : $employe['pin_bloque_le'];
        if ($empreinte === null) {
            throw new \DomainException('sans_pin');
        }
        if ($bloque !== null) {
            throw new \DomainException('bloque');
        }
        if (!CaissePartagee::pinValide($pin) || !password_verify($pin, $empreinte)) {
            $essais = (int) ($avecCompte ? $employe['compte_essais'] : $employe['pin_essais']) + 1;
            $bloquer = $essais >= self::ESSAIS_PIN;
            if ($avecCompte) {
                Database::executer('UPDATE utilisateurs SET pin_essais = ?, pin_bloque_le = ? WHERE id = ?',
                    [$bloquer ? 0 : $essais, $bloquer ? date('Y-m-d H:i:s') : null, (int) $employe['utilisateur_id']]);
            } else {
                Database::executer('UPDATE employes SET pin_essais = ?, pin_bloque_le = ? WHERE id = ?',
                    [$bloquer ? 0 : $essais, $bloquer ? date('Y-m-d H:i:s') : null, (int) $employe['id']]);
            }
            Journal::ecrire('pointage_identification_echec', $employe['nom'], ['raison' => 'pin', 'employe' => (int) $employe['id']], $avecCompte ? (int) $employe['utilisateur_id'] : null);
            if ($bloquer) {
                Journal::ecrire($avecCompte ? 'pin_bloque' : 'employe_pin_bloque', $employe['nom'], ['pointage' => true], $avecCompte ? (int) $employe['utilisateur_id'] : null);
                throw new \DomainException('bloque');
            }
            throw new \DomainException('identification');
        }
        if ($avecCompte) {
            Database::executer('UPDATE utilisateurs SET pin_essais = 0 WHERE id = ?', [(int) $employe['utilisateur_id']]);
        } else {
            Database::executer('UPDATE employes SET pin_essais = 0 WHERE id = ?', [(int) $employe['id']]);
        }
        // Un seul téléphone par employé : le précédent est oublié
        $jeton = bin2hex(random_bytes(32));
        Database::executer('UPDATE pointage_telephones SET actif = 0 WHERE employe_id = ?', [(int) $employe['id']]);
        Database::executer('INSERT INTO pointage_telephones (employe_id, jeton, appareil, dernier_usage) VALUES (?, ?, ?, NOW())',
            [(int) $employe['id'], hash('sha256', $jeton), mb_substr($appareil, 0, 255) ?: null]);
        setcookie(self::COOKIE_TELEPHONE, $jeton, [
            'expires' => time() + 400 * 86400, 'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Lax',
        ]);
        $_COOKIE[self::COOKIE_TELEPHONE] = $jeton;
        Journal::ecrire('pointage_telephone_identifie', $employe['nom'], ['employe' => (int) $employe['id']], $avecCompte ? (int) $employe['utilisateur_id'] : null);
        return $this->employeDuTelephone() ?? $employe;
    }

    public static function oublierTelephone(): void
    {
        $jeton = $_COOKIE[self::COOKIE_TELEPHONE] ?? '';
        if (is_string($jeton) && preg_match('/^[a-f0-9]{64}$/', $jeton)) {
            Database::executer('UPDATE pointage_telephones SET actif = 0 WHERE jeton = ?', [hash('sha256', $jeton)]);
        }
        setcookie(self::COOKIE_TELEPHONE, '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        unset($_COOKIE[self::COOKIE_TELEPHONE]);
    }

    // ---------- Présences ----------

    /** Distance en mètres entre deux points (formule de haversine). */
    public static function distance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return 2 * $r * asin(min(1.0, sqrt($a)));
    }

    /** Présence ouverte (arrivée sans départ, depuis moins de HEURES_MAX) de l'employé, dans n'importe quel établissement. */
    public function ouverte(int $employeId, ?int $maintenant = null, bool $verrouiller = false): ?array
    {
        return Database::un(
            'SELECT p.*, e.nom AS etablissement_nom FROM pointages p JOIN etablissements e ON e.id = p.etablissement_id
              WHERE p.employe_id = ? AND p.depart IS NULL AND p.arrivee >= ? ORDER BY p.arrivee DESC LIMIT 1' . ($verrouiller ? ' FOR UPDATE' : ''),
            [$employeId, date('Y-m-d H:i:s', ($maintenant ?? time()) - self::HEURES_MAX * 3600)]
        );
    }

    /** Est-elle pointée (présente) dans cet établissement en ce moment ? */
    public function estPresent(int $employeId, int $etablissementId): bool
    {
        $ouverte = $this->ouverte($employeId);
        return $ouverte !== null && (int) $ouverte['etablissement_id'] === $etablissementId;
    }

    /**
     * Journée d'exploitation d'une arrivée : celle du créneau planifié qui l'entoure (4 h avant son début
     * jusqu'à sa fin), sinon celle de l'heure (Journee : la veille avant l'heure de fin de journée de l'établissement).
     */
    public static function journee(int $employeId, int $etablissementId, string $arrivee): string
    {
        $creneau = Database::un(
            'SELECT journee FROM plannings WHERE employe_id = ? AND etablissement_id = ? AND ? BETWEEN DATE_SUB(debut, INTERVAL 4 HOUR) AND fin
              ORDER BY ABS(TIMESTAMPDIFF(MINUTE, debut, ?)) LIMIT 1',
            [$employeId, $etablissementId, $arrivee, $arrivee]
        );
        if ($creneau !== null) {
            return $creneau['journee'];
        }
        return \App\Core\Journee::du($arrivee, Database::un('SELECT * FROM etablissements WHERE id = ?', [$etablissementId]));
    }

    /**
     * Pointer l'arrivée ou le départ (selon qu'une présence est ouverte), par code QR ou par secours.
     * @param array{source: string, borne_id?: ?int, fenetre?: ?int, lat?: ?float, lng?: ?float, precision?: ?int, distance?: ?int,
     *              valide_par?: ?int, appareil_id?: ?int, telephone_id?: ?int, sessions?: bool} $d
     * @return array{sens: string, pointage: array}
     * @throws \DomainException « autre_etablissement », « deja », « trop_tot », « caisse_ouverte »
     */
    public function pointer(array $employe, int $etablissementId, array $d, ?int $maintenant = null): array
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            // Un employé à la fois : deux scans simultanés ne créent pas deux arrivées
            Database::un('SELECT id FROM employes WHERE id = ? FOR UPDATE', [(int) $employe['id']]);
            $t = $maintenant ?? time();
            $date = date('Y-m-d H:i:s', $t);
            $derniere = Database::un('SELECT * FROM pointages WHERE employe_id = ? ORDER BY GREATEST(arrivee, COALESCE(depart, arrivee)) DESC, id DESC LIMIT 1', [(int) $employe['id']]);
            if ($derniere !== null && isset($d['fenetre'], $d['borne_id'])) {
                foreach (['arrivee', 'depart'] as $sens) {
                    if ((int) $derniere[$sens . '_borne_id'] === (int) $d['borne_id'] && (string) $derniere[$sens . '_fenetre'] === (string) $d['fenetre']) {
                        throw new \DomainException('deja');
                    }
                }
            }
            if ($derniere !== null) {
                $dernierMoment = strtotime((string) ($derniere['depart'] ?? $derniere['arrivee']));
                if ($t - $dernierMoment < self::ECART_MIN && $t >= $dernierMoment) {
                    throw new \DomainException('trop_tot');
                }
            }
            $ouverte = $this->ouverte((int) $employe['id'], $t, true);
            $valeurs = [
                $d['source'], $d['lat'] ?? null, $d['lng'] ?? null, $d['precision'] ?? null, $d['distance'] ?? null,
                $d['borne_id'] ?? null, $d['fenetre'] ?? null, $d['valide_par'] ?? null,
            ];
            if ($ouverte !== null) {
                if ((int) $ouverte['etablissement_id'] !== $etablissementId) {
                    throw new \DomainException('autre_etablissement');
                }
                // Pour dépointer, il faut d'abord clôturer sa caisse (si l'établissement a des sessions de caisse)
                if (!empty($d['sessions']) && !empty($employe['utilisateur_id']) && Database::un(
                    "SELECT id FROM sessions_caisse WHERE etablissement_id = ? AND utilisateur_id = ? AND statut = 'ouverte' LIMIT 1",
                    [$etablissementId, (int) $employe['utilisateur_id']]
                ) !== null) {
                    throw new \DomainException('caisse_ouverte');
                }
                Database::executer(
                    'UPDATE pointages SET depart = ?, depart_source = ?, depart_lat = ?, depart_lng = ?, depart_precision = ?, depart_distance = ?,
                            depart_borne_id = ?, depart_fenetre = ?, depart_valide_par = ? WHERE id = ?',
                    [$date, ...$valeurs, (int) $ouverte['id']]
                );
                $id = (int) $ouverte['id'];
                $sens = 'depart';
            } else {
                Database::executer(
                    'INSERT INTO pointages (employe_id, etablissement_id, journee, arrivee, arrivee_source, arrivee_lat, arrivee_lng, arrivee_precision,
                            arrivee_distance, arrivee_borne_id, arrivee_fenetre, arrivee_valide_par, appareil_id, telephone_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [(int) $employe['id'], $etablissementId, self::journee((int) $employe['id'], $etablissementId, $date), $date, ...$valeurs,
                        $d['appareil_id'] ?? null, $d['telephone_id'] ?? null]
                );
                $id = Database::dernierId();
                $sens = 'arrivee';
            }
            Journal::ecrire('pointage_' . $sens, $employe['nom'], ['employe' => (int) $employe['id'], 'source' => $d['source'], 'distance' => $d['distance'] ?? null,
                'valide_par' => $d['valide_par'] ?? null], $employe['utilisateur_id'] ? (int) $employe['utilisateur_id'] : null, $d['appareil_id'] ?? null);
            $pdo->commit();
            return ['sens' => $sens, 'pointage' => $this->pointage($id)];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function pointage(int $id): ?array
    {
        return Database::un(
            'SELECT p.*, e.nom AS employe_nom, e.utilisateur_id, x.nom AS etablissement_nom,
                    va.nom AS arrivee_valide_nom, vd.nom AS depart_valide_nom
               FROM pointages p JOIN employes e ON e.id = p.employe_id JOIN etablissements x ON x.id = p.etablissement_id
               LEFT JOIN utilisateurs va ON va.id = p.arrivee_valide_par LEFT JOIN utilisateurs vd ON vd.id = p.depart_valide_par
              WHERE p.id = ?',
            [$id]
        );
    }

    /**
     * Présences d'une période (journées du … au …), pour la gestion.
     * @param array{debut: string, fin: string, etablissement?: ?int, employe?: ?int} $f
     * @return list<array>
     */
    public function liste(?array $portee, array $f): array
    {
        $params = [$f['debut'], $f['fin']];
        $where = ['p.journee BETWEEN ? AND ?'];
        if ($portee !== null) {
            if ($portee === []) {
                return [];
            }
            $where[] = 'p.etablissement_id IN (' . implode(',', array_fill(0, count($portee), '?')) . ')';
            array_push($params, ...array_map('intval', $portee));
        }
        if (!empty($f['etablissement'])) {
            $where[] = 'p.etablissement_id = ?';
            $params[] = (int) $f['etablissement'];
        }
        if (!empty($f['employe'])) {
            $where[] = 'p.employe_id = ?';
            $params[] = (int) $f['employe'];
        }
        return Database::tous(
            'SELECT p.*, e.nom AS employe_nom, e.poste, u.avatar, x.nom AS etablissement_nom, va.nom AS arrivee_valide_nom, vd.nom AS depart_valide_nom,
                    (SELECT COUNT(*) FROM pointage_corrections c WHERE c.pointage_id = p.id) AS corrections
               FROM pointages p JOIN employes e ON e.id = p.employe_id JOIN etablissements x ON x.id = p.etablissement_id
               LEFT JOIN utilisateurs u ON u.id = e.utilisateur_id
               LEFT JOIN utilisateurs va ON va.id = p.arrivee_valide_par LEFT JOIN utilisateurs vd ON vd.id = p.depart_valide_par
              WHERE ' . implode(' AND ', $where) . ' ORDER BY p.journee DESC, p.arrivee DESC LIMIT 1000',
            $params
        );
    }

    // ---------- Corrections du gérant ----------

    /**
     * Ajouter une présence oubliée, ou modifier les heures d'une présence, avec un motif.
     * @throws \DomainException « heures », « chevauchement »
     */
    public function enregistrerCorrection(?int $pointageId, array $employe, int $etablissementId, string $arrivee, ?string $depart, string $motif, int $par): int
    {
        if ($depart !== null && (strtotime($depart) <= strtotime($arrivee) || strtotime($depart) - strtotime($arrivee) > 24 * 3600)) {
            throw new \DomainException('heures');
        }
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $fin = $depart ?? date('Y-m-d H:i:s', strtotime($arrivee) + 60);
            if (Database::un(
                'SELECT id FROM pointages WHERE employe_id = ? AND id <> ? AND arrivee < ? AND COALESCE(depart, DATE_ADD(arrivee, INTERVAL 1 MINUTE)) > ? LIMIT 1',
                [(int) $employe['id'], $pointageId ?? 0, $fin, $arrivee]
            ) !== null) {
                throw new \DomainException('chevauchement');
            }
            $journee = self::journee((int) $employe['id'], $etablissementId, $arrivee);
            if ($pointageId === null) {
                Database::executer(
                    "INSERT INTO pointages (employe_id, etablissement_id, journee, arrivee, depart, arrivee_source, depart_source, arrivee_valide_par, depart_valide_par, note)
                     VALUES (?, ?, ?, ?, ?, 'gestion', ?, ?, ?, ?)",
                    [(int) $employe['id'], $etablissementId, $journee, $arrivee, $depart, $depart !== null ? 'gestion' : null, $par, $depart !== null ? $par : null, mb_substr($motif, 0, 255)]
                );
                $pointageId = Database::dernierId();
                $this->noterCorrection($pointageId, (int) $employe['id'], 'ajout', null, ['arrivee' => $arrivee, 'depart' => $depart], $motif, $par);
            } else {
                $avant = Database::un('SELECT * FROM pointages WHERE id = ? FOR UPDATE', [$pointageId]);
                $departChange = $avant['depart'] !== $depart;
                Database::executer(
                    // Source et validation avant l'heure : MySQL évalue les affectations dans l'ordre
                    'UPDATE pointages SET arrivee_source = IF(arrivee <> ?, \'gestion\', arrivee_source), arrivee_valide_par = IF(arrivee <> ?, ?, arrivee_valide_par),
                            journee = ?, arrivee = ?, depart = ?, depart_source = ?, depart_valide_par = ? WHERE id = ?',
                    [$arrivee, $arrivee, $par, $journee, $arrivee, $depart,
                        $depart === null ? null : ($departChange ? 'gestion' : $avant['depart_source']),
                        $depart === null ? null : ($departChange ? $par : $avant['depart_valide_par']), $pointageId]
                );
                $this->noterCorrection($pointageId, (int) $employe['id'], 'modification', ['arrivee' => $avant['arrivee'], 'depart' => $avant['depart']],
                    ['arrivee' => $arrivee, 'depart' => $depart], $motif, $par);
            }
            $pdo->commit();
            return $pointageId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function supprimer(array $pointage, string $motif, int $par): void
    {
        $this->noterCorrection((int) $pointage['id'], (int) $pointage['employe_id'], 'suppression',
            ['arrivee' => $pointage['arrivee'], 'depart' => $pointage['depart']], null, $motif, $par);
        Database::executer('DELETE FROM pointages WHERE id = ?', [(int) $pointage['id']]);
    }

    private function noterCorrection(?int $pointageId, int $employeId, string $action, ?array $avant, ?array $apres, string $motif, int $par): void
    {
        Database::executer(
            'INSERT INTO pointage_corrections (pointage_id, employe_id, action, avant, apres, motif, par) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$pointageId, $employeId, $action, $avant !== null ? json_encode($avant) : null, $apres !== null ? json_encode($apres) : null, mb_substr($motif, 0, 255), $par]
        );
        Journal::ecrire('pointage_correction', 'employe ' . $employeId, ['action' => $action, 'pointage' => $pointageId, 'motif' => mb_substr($motif, 0, 255)], $par);
    }

    /** @return list<array> corrections récentes (d'un employé, ou de la portée) */
    public function corrections(?array $portee, ?int $employeId = null, int $limite = 50): array
    {
        $params = [];
        $where = [PersonnelRepository::conditionPortee($portee, $params)];
        if ($employeId !== null) {
            $where[] = 'c.employe_id = ?';
            $params[] = $employeId;
        }
        return Database::tous(
            'SELECT c.*, e.nom AS employe_nom, u.nom AS par_nom FROM pointage_corrections c JOIN employes e ON e.id = c.employe_id
               LEFT JOIN utilisateurs u ON u.id = c.par WHERE ' . implode(' AND ', $where) . ' ORDER BY c.le DESC, c.id DESC LIMIT ' . max(1, min(500, $limite)),
            $params
        );
    }

    // ---------- Réglages de l'établissement ----------

    public function reglerEtablissement(int $id, ?float $lat, ?float $lng, int $rayon, bool $obligatoire, int $par): void
    {
        Database::executer('UPDATE etablissements SET latitude = ?, longitude = ?, pointage_rayon = ?, pointage_obligatoire = ? WHERE id = ?', [
            $lat !== null ? number_format($lat, 6, '.', '') : null, $lng !== null ? number_format($lng, 6, '.', '') : null,
            max(20, min(5000, $rayon)), $obligatoire ? 1 : 0, $id,
        ]);
        Journal::ecrire('pointage_reglages', 'etablissement ' . $id, ['lat' => $lat, 'lng' => $lng, 'rayon' => $rayon, 'obligatoire' => $obligatoire], $par);
    }
}
