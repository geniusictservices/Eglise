<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\Installation;
use App\Core\Migrations;
use App\Core\Plateforme;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Ce que la console fait dans la base d'une instance, avec les accès de config/instances/<sous-domaine>.php :
 * tester la connexion, compter l'utilisation (jamais de données commerciales : seulement des nombres),
 * appliquer les migrations, préparer l'entrée de l'assistance, inscrire ses actions au journal de l'instance.
 */
final class Instances
{
    /** Connexion avec des accès saisis (installation) ; délai court pour une base injoignable. */
    public static function connecter(array $db): PDO
    {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['hote'] ?: 'localhost', (int) ($db['port'] ?: 3306), $db['nom']),
            $db['utilisateur'],
            $db['mot_de_passe'] ?? '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 5]
        );
        $pdo->exec("SET time_zone = '" . date('P') . "'");
        return $pdo;
    }

    /** Connexion à une instance installée (fichier de configuration), sinon RuntimeException. */
    public static function pdo(string $sousDomaine): PDO
    {
        $config = Installation::lireConfig($sousDomaine);
        if ($config === null) {
            throw new RuntimeException('config/instances/' . $sousDomaine . '.php introuvable');
        }
        return self::connecter($config['db']);
    }

    /**
     * Essai de connexion : succès avec ce que contient la base, ou l'erreur expliquée.
     * @return array{ok: bool, erreur: ?string, etat?: string, tables?: int, version?: string}
     */
    public static function tester(array $db): array
    {
        try {
            $examen = Installation::examinerBase(self::connecter($db));
            return ['ok' => true, 'erreur' => null] + $examen;
        } catch (PDOException $e) {
            return ['ok' => false, 'erreur' => self::expliquer($e)];
        }
    }

    /** Message d'erreur MySQL compréhensible (sans le mot de passe). */
    public static function expliquer(PDOException $e): string
    {
        $code = (int) ($e->errorInfo[1] ?? 0);
        return match (true) {
            $code === 1045 => t('console.db.erreur_acces'),
            $code === 1044 || $code === 1049 => t('console.db.erreur_base'),
            $code === 2002 || $code === 2005 || $code === 2006 => t('console.db.erreur_hote'),
            default => t('console.db.erreur_autre', ['message' => mb_substr(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 0, 200)]),
        };
    }

    /**
     * Utilisation d'une instance : personnes actives, dernière connexion, établissements actifs, messages du mois,
     * migrations en attente. Seulement des nombres et des dates, jamais de ventes, de clients ni de dettes.
     * @return array{ok: bool, erreur: ?string, utilisateurs?: int, derniere_connexion?: ?string, etablissements?: int, sms?: int, whatsapp?: int, simules?: int, migrations?: list<string>}
     */
    public static function usage(string $sousDomaine, ?string $mois = null): array
    {
        try {
            $pdo = self::pdo($sousDomaine);
        } catch (Throwable $e) {
            return ['ok' => false, 'erreur' => $e instanceof PDOException ? self::expliquer($e) : $e->getMessage()];
        }
        $assistance = (int) self::valeur($pdo, 'SELECT valeur FROM parametres WHERE cle = ?', [Plateforme::CLE_ASSISTANCE_UTILISATEUR]);
        $resultat = [
            'ok' => true, 'erreur' => null,
            'utilisateurs' => (int) self::valeur($pdo, 'SELECT COUNT(*) FROM utilisateurs WHERE actif = 1 AND id <> ?', [$assistance]),
            'derniere_connexion' => self::valeur($pdo, 'SELECT MAX(derniere_connexion) FROM utilisateurs WHERE id <> ?', [$assistance]),
            'etablissements' => (int) self::valeur($pdo, 'SELECT COUNT(*) FROM etablissements WHERE actif = 1'),
        ] + self::messagesDuMois($pdo, $mois);
        try {
            $resultat['migrations'] = Migrations::enAttente($pdo);
        } catch (Throwable) {
            $resultat['migrations'] = [];
        }
        return $resultat;
    }

    /** @return array{sms: int, whatsapp: int, simules: int} messages envoyés ce mois-ci (AAAA-MM), par canal */
    public static function messagesDuMois(PDO $pdo, ?string $mois = null): array
    {
        $debut = ($mois ?? date('Y-m')) . '-01';
        $fin = Abonnement::ajouterMois($debut, 1);
        $resultat = ['sms' => 0, 'whatsapp' => 0, 'simules' => 0];
        try {
            $requete = $pdo->prepare('SELECT canal, statut, COUNT(*) AS n FROM messages WHERE cree_le >= ? AND cree_le < ? GROUP BY canal, statut');
            $requete->execute([$debut, $fin]);
            foreach ($requete->fetchAll() as $ligne) {
                if ($ligne['statut'] === 'envoye') {
                    $resultat[$ligne['canal']] = ($resultat[$ligne['canal']] ?? 0) + (int) $ligne['n'];
                } elseif ($ligne['statut'] === 'simule') {
                    $resultat['simules'] += (int) $ligne['n'];
                }
            }
        } catch (Throwable) {
            // Instance pas encore à jour (pas de table messages) : rien à compter
        }
        return $resultat;
    }

    private static function valeur(PDO $pdo, string $sql, array $params = []): mixed
    {
        try {
            $requete = $pdo->prepare($sql);
            $requete->execute($params);
            $valeur = $requete->fetchColumn();
            return $valeur === false ? null : $valeur;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return string[] migrations appliquées */
    public static function migrer(string $sousDomaine): array
    {
        $pdo = self::pdo($sousDomaine);
        $appliquees = Migrations::appliquer($pdo);
        if ($appliquees !== []) {
            self::journaliser($pdo, 'plateforme_mise_a_jour', implode(', ', $appliquees));
        }
        return $appliquees;
    }

    /** Sous-domaines qui ont un fichier config/instances/*.php mais aucun client dans la console. */
    public static function nonRattachees(array $connus): array
    {
        $resultat = [];
        foreach (glob(RACINE . '/config/instances/*.php') ?: [] as $fichier) {
            $sousDomaine = basename($fichier, '.php');
            if (preg_match(Installation::MOTIF_SOUS_DOMAINE, $sousDomaine) && !in_array($sousDomaine, $connus, true)) {
                $resultat[] = $sousDomaine;
            }
        }
        sort($resultat);
        return $resultat;
    }

    /** Fin de l'accord d'assistance donné par le propriétaire, ou null. */
    public static function assistanceJusqua(PDO $pdo): ?string
    {
        $jusqua = self::valeur($pdo, 'SELECT valeur FROM parametres WHERE cle = ?', [Plateforme::CLE_ASSISTANCE_JUSQUA]);
        return is_string($jusqua) && strtotime($jusqua) > time() ? $jusqua : null;
    }

    /**
     * Jeton à usage unique (deux minutes) pour entrer dans l'instance comme « Assistance » ; null sans accord.
     * Seule son empreinte est écrite dans l'instance.
     */
    public static function jetonAssistance(PDO $pdo, string $admin): ?string
    {
        if (self::assistanceJusqua($pdo) === null) {
            return null;
        }
        $jeton = bin2hex(random_bytes(24));
        $valeur = hash('sha256', $jeton) . '|' . (time() + 120) . '|' . mb_substr(str_replace('|', ' ', $admin), 0, 80);
        $pdo->prepare('INSERT INTO parametres (cle, valeur) VALUES (?, ?) ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)')
            ->execute([Plateforme::CLE_ASSISTANCE_JETON, $valeur]);
        return $jeton;
    }

    /** Action de la plateforme inscrite au journal de l'instance (le client la voit). */
    public static function journaliser(PDO $pdo, string $action, ?string $cible = null): void
    {
        try {
            $pdo->prepare('INSERT INTO journal (utilisateur_id, action, cible, ip) VALUES (NULL, ?, ?, ?)')
                ->execute([$action, $cible, substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null]);
        } catch (Throwable) {
            // Instance trop ancienne (pas de journal) : rien
        }
    }
}
