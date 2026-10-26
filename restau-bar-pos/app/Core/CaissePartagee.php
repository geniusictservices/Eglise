<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Caisse partagée : un appareil (tablette du bar…) autorisé par un gérant, où chacun se connecte
 * avec son PIN de 6 chiffres. Sans appareil autorisé, la connexion par PIN est refusée : on ne peut
 * pas essayer des PIN depuis n'importe quel navigateur.
 *
 * L'appareil garde un jeton dans un cookie ; la base n'en garde que l'empreinte SHA-256.
 * La personne connectée par PIN est dans la session (« caissier »), liée à cet appareil.
 */
final class CaissePartagee
{
    public const COOKIE = 'rbp_caisse';
    public const LONGUEUR_PIN = 6;
    public const ESSAIS_PIN = 5;
    /** Au-delà, l'appareil refuse les PIN pendant 10 minutes (essais sur plusieurs comptes) */
    public const ECHECS_APPAREIL = 15;

    private static array|false|null $appareil = null;

    /** Appareil autorisé de ce navigateur, ou null. */
    public static function appareil(): ?array
    {
        if (self::$appareil === null) {
            self::$appareil = false;
            $jeton = $_COOKIE[self::COOKIE] ?? '';
            if (is_string($jeton) && preg_match('/^[a-f0-9]{64}$/', $jeton)) {
                $appareil = Database::un(
                    'SELECT a.*, e.slug, e.nom AS etablissement_nom FROM appareils_caisse a
                       JOIN etablissements e ON e.id = a.etablissement_id
                      WHERE a.jeton = ? AND a.actif = 1',
                    [hash('sha256', $jeton)]
                );
                if ($appareil !== null) {
                    self::$appareil = $appareil;
                    if ($appareil['dernier_usage'] === null || strtotime($appareil['dernier_usage']) < time() - 300) {
                        Database::executer('UPDATE appareils_caisse SET dernier_usage = NOW() WHERE id = ?', [$appareil['id']]);
                    }
                }
            }
        }
        return self::$appareil ?: null;
    }

    /** Autorise ce navigateur comme caisse partagée de l'établissement. */
    public static function autoriser(string $nom, int $etablissementId, int $utilisateurId): int
    {
        $jeton = bin2hex(random_bytes(32));
        Database::executer(
            'INSERT INTO appareils_caisse (nom, etablissement_id, jeton, autorise_par) VALUES (?, ?, ?, ?)',
            [$nom, $etablissementId, hash('sha256', $jeton), $utilisateurId]
        );
        $id = Database::dernierId();
        setcookie(self::COOKIE, $jeton, [
            'expires' => time() + 400 * 86400,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        $_COOKIE[self::COOKIE] = $jeton;
        self::$appareil = null;
        return $id;
    }

    /** Personne connectée par PIN sur cet appareil, ou null (session d'un autre appareil ignorée). */
    public static function caissier(): ?array
    {
        Session::demarrer();
        $caissier = $_SESSION['caissier'] ?? null;
        $appareil = self::appareil();
        if ($caissier === null || $appareil === null || (int) $caissier['appareil_id'] !== (int) $appareil['id']) {
            return null;
        }
        return $caissier;
    }

    public static function connecter(array $utilisateur, array $appareil): void
    {
        Session::demarrer();
        session_regenerate_id(true);
        // Sur une caisse partagée, la session de gestion d'un gérant ne doit pas rester ouverte derrière un serveur
        unset($_SESSION['utilisateur']);
        $_SESSION['caissier'] = ['id' => (int) $utilisateur['id'], 'nom' => $utilisateur['nom'], 'appareil_id' => (int) $appareil['id']];
    }

    public static function verrouiller(): void
    {
        Session::demarrer();
        unset($_SESSION['caissier']);
        session_regenerate_id(true);
    }

    /** Règle de verrouillage de l'établissement, en une phrase (écran de verrouillage, fenêtre de la caisse). */
    public static function regle(array $etab): ?string
    {
        $minutes = (int) ($etab['verrou_minutes'] ?? 0);
        $apres = !empty($etab['verrou_apres_encaissement']);
        return match (true) {
            $apres && $minutes > 0 => t('verrou.regle_les_deux', ['n' => $minutes]),
            $apres => t('verrou.regle_encaissement'),
            $minutes > 0 => t('verrou.regle_minutes', ['n' => $minutes]),
            default => null,
        };
    }

    public static function pinValide(string $pin): bool
    {
        return preg_match('/^\d{' . self::LONGUEUR_PIN . '}$/', $pin) === 1;
    }

    /** PIN trop simple : tous les chiffres identiques ou une suite (123456, 654321). */
    public static function pinTropSimple(string $pin): bool
    {
        if (count(array_unique(str_split($pin))) === 1) {
            return true;
        }
        return str_contains('0123456789', $pin) || str_contains('9876543210', $pin);
    }

    /** Trop d'échecs récents sur l'appareil (tous comptes confondus) ? */
    public static function appareilSature(int $appareilId): bool
    {
        $ligne = Database::un(
            "SELECT COUNT(*) AS n FROM journal WHERE action = 'pin_echec' AND appareil_id = ? AND cree_le >= ?",
            [$appareilId, date('Y-m-d H:i:s', time() - 600)]
        );
        return (int) $ligne['n'] >= self::ECHECS_APPAREIL;
    }
}
