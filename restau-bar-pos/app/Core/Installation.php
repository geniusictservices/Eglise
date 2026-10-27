<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

/**
 * Installation d'une instance client, commune à bin/creer-instance.php (ligne de commande, serveur où l'on est
 * administrateur MySQL) et à la console super admin (hébergement mutualisé cPanel : la base et son utilisateur
 * sont créés à la main dans cPanel, la console installe ensuite les tables).
 *
 * Étapes : vérifier le sous-domaine, (créer la base et l'utilisateur MySQL), installer les tables (schema.sql puis
 * toutes les migrations), les réglages de départ ou la démonstration, le compte propriétaire, et écrire
 * config/instances/<sous-domaine>.php.
 */
final class Installation
{
    public const MOTIF_SOUS_DOMAINE = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

    /** Message d'erreur (en français, pour la ligne de commande) ou null si le sous-domaine convient. */
    public static function erreurSousDomaine(string $sousDomaine): ?string
    {
        if (!preg_match(self::MOTIF_SOUS_DOMAINE, $sousDomaine)) {
            return 'invalide';
        }
        if (in_array($sousDomaine, Config::get('sous_domaines_reserves', []), true)) {
            return 'reserve';
        }
        return null;
    }

    public static function fichierConfig(string $sousDomaine): string
    {
        return RACINE . '/config/instances/' . $sousDomaine . '.php';
    }

    /** Configuration d'une instance existante (tableau ['actif' => …, 'db' => […]]) ou null. */
    public static function lireConfig(string $sousDomaine): ?array
    {
        if (!preg_match(self::MOTIF_SOUS_DOMAINE, $sousDomaine)) {
            return null;
        }
        $fichier = self::fichierConfig($sousDomaine);
        if (!is_file($fichier)) {
            return null;
        }
        $config = require $fichier;
        return is_array($config) && isset($config['db']['nom']) ? $config : null;
    }

    /** Peut-on écrire le fichier de configuration de cette instance (dossier config/instances accessible) ? */
    public static function configInscriptible(string $sousDomaine): bool
    {
        $fichier = self::fichierConfig($sousDomaine);
        return is_file($fichier) ? is_writable($fichier) : is_writable(dirname($fichier));
    }

    /** Contenu du fichier config/instances/<sous-domaine>.php. */
    public static function contenuConfig(string $sousDomaine, array $db, string $origine): string
    {
        return "<?php\n\n// Instance « $sousDomaine », créée le " . date('Y-m-d H:i') . " par $origine\nreturn "
            . var_export(['actif' => true, 'db' => $db], true) . ";\n";
    }

    public static function ecrireConfig(string $sousDomaine, array $db, string $origine): void
    {
        $fichier = self::fichierConfig($sousDomaine);
        if (file_put_contents($fichier, self::contenuConfig($sousDomaine, $db, $origine), LOCK_EX) === false) {
            throw new RuntimeException("Impossible d'écrire $fichier");
        }
        @chmod($fichier, 0600);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($fichier, true);
        }
    }

    /**
     * Crée la base et un utilisateur MySQL dédié (serveur où l'on est administrateur MySQL ; pas sur un
     * hébergement mutualisé, où cPanel s'en charge). Lève une RuntimeException si la base existe déjà.
     * @return array{hote: string, port: int, nom: string, utilisateur: string, mot_de_passe: string}
     */
    public static function creerBaseEtUtilisateur(PDO $admin, string $sousDomaine, string $hote, int $port): array
    {
        $nomBase = 'rbp_' . str_replace('-', '_', $sousDomaine);
        $utilisateur = substr($nomBase, 0, 32);
        $motDePasse = bin2hex(random_bytes(16));
        if ($admin->query('SHOW DATABASES LIKE ' . $admin->quote($nomBase))->fetch()) {
            throw new RuntimeException("La base « $nomBase » existe déjà.");
        }
        $admin->exec("CREATE DATABASE `$nomBase` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        // L'utilisateur se connecte depuis la même machine ; adapter l'hôte si la base est sur un autre serveur
        foreach (['localhost', '127.0.0.1'] as $origine) {
            $admin->exec(sprintf('CREATE USER %s@%s IDENTIFIED BY %s', $admin->quote($utilisateur), $admin->quote($origine), $admin->quote($motDePasse)));
            $admin->exec(sprintf('GRANT ALL PRIVILEGES ON `%s`.* TO %s@%s', $nomBase, $admin->quote($utilisateur), $admin->quote($origine)));
        }
        return ['hote' => $hote, 'port' => $port, 'nom' => $nomBase, 'utilisateur' => $utilisateur, 'mot_de_passe' => $motDePasse];
    }

    /**
     * Ce que contient la base : « vide », « importee » (tables d'une instance sans aucun utilisateur : le fichier
     * installation/instance-vide.sql importé avec phpMyAdmin), « instance » (instance déjà installée) ou « autre ».
     * @return array{etat: string, tables: int, version: string}
     */
    public static function examinerBase(PDO $pdo): array
    {
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        if ($tables === []) {
            return ['etat' => 'vide', 'tables' => 0, 'version' => $version];
        }
        $instance = in_array('parametres', $tables, true) && in_array('utilisateurs', $tables, true) && in_array('schema_migrations', $tables, true);
        if ($instance && (int) $pdo->query('SELECT COUNT(*) FROM utilisateurs')->fetchColumn() === 0) {
            return ['etat' => 'importee', 'tables' => count($tables), 'version' => $version];
        }
        return ['etat' => $instance ? 'instance' : 'autre', 'tables' => count($tables), 'version' => $version];
    }

    /**
     * Tables (schema.sql puis toutes les migrations), puis la démonstration ou les réglages de départ.
     * $nomClient null : garder celui de la démonstration.
     * @return string[] migrations appliquées
     */
    public static function installerTables(PDO $pdo, ?string $nomClient, bool $demo = false, ?string $langue = null, bool $dejaImportee = false): array
    {
        // Base importée depuis installation/instance-vide.sql : seulement les migrations plus récentes que le fichier
        if (!$dejaImportee) {
            Migrations::executerFichier($pdo, RACINE . '/database/schema.sql');
        }
        $migrations = Migrations::appliquer($pdo);
        if ($demo) {
            Migrations::executerFichier($pdo, RACINE . '/database/demo.sql');
            // Illustrations SVG des produits et des établissements, puis trésorerie de démonstration
            foreach (['demo-illustrations.sql', 'demo-tresorerie.sql'] as $fichier) {
                if (is_file(RACINE . '/database/' . $fichier)) {
                    Migrations::executerFichier($pdo, RACINE . '/database/' . $fichier);
                }
            }
        } else {
            // Grille des prix de la carte : sans elle, la seule grille d'une base neuve serait « Prix de gros »
            // (migration 021), proposée par défaut au premier établissement et au catalogue
            $pdo->exec("INSERT INTO grilles_prix (code, nom_fr, nom_en)
                        SELECT 'normal', 'Prix normal', 'Standard price' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM grilles_prix WHERE code = 'normal')");
            $pdo->prepare('REPLACE INTO parametres (cle, valeur) VALUES (?, ?), (?, ?), (?, ?)')->execute([
                'nom_client', (string) $nomClient,
                'langue_defaut', in_array($langue, Config::get('langues', ['fr']), true) ? $langue : Config::get('langue_defaut'),
                'fuseau_horaire', Config::get('fuseau_horaire'),
            ]);
        }
        if ($nomClient !== null) {
            $pdo->prepare('REPLACE INTO parametres (cle, valeur) VALUES (?, ?)')->execute(['nom_client', $nomClient]);
        }
        // Secret des codes QR de pointage (migration 018) : propre à chaque instance. Une base importée depuis
        // installation/instance-vide.sql (paquet LWS) n'en a pas, ou celui d'un ancien paquet, commun à tous : nouveau secret
        $secret = $pdo->query("SELECT valeur FROM parametres WHERE cle = 'pointage.secret'")->fetchColumn();
        if ($dejaImportee || !is_string($secret) || $secret === '') {
            $pdo->prepare('REPLACE INTO parametres (cle, valeur) VALUES (?, ?)')->execute(['pointage.secret', bin2hex(random_bytes(32))]);
        }
        return $migrations;
    }

    /**
     * Compte propriétaire (tous les droits, dans tous les établissements), avec un mot de passe tiré au hasard,
     * rendu une seule fois. $temporaire : il devra le changer à sa première connexion.
     * @return array{id: int, mot_de_passe: string}
     */
    public static function creerProprietaire(PDO $pdo, string $email, string $nom = 'Administrateur', ?string $telephone = null, string $langue = 'fr', bool $temporaire = false): array
    {
        $motDePasse = self::motDePasse();
        $pdo->prepare('INSERT INTO utilisateurs (nom, email, telephone, mot_de_passe, langue) VALUES (?, ?, ?, ?, ?)')->execute([
            $nom, $email, $telephone, password_hash($motDePasse, PASSWORD_DEFAULT), $langue,
        ]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO utilisateur_roles (utilisateur_id, role_id) SELECT ?, id FROM roles WHERE code = 'proprietaire'")->execute([$id]);
        if ($temporaire) {
            $pdo->prepare('REPLACE INTO parametres (cle, valeur) VALUES (?, ?)')->execute([Plateforme::CLE_MDP_TEMPORAIRE . $id, '1']);
        }
        return ['id' => $id, 'mot_de_passe' => $motDePasse];
    }

    /** Employés de démonstration (après le propriétaire, qui garde l'identifiant 1). */
    public static function installerUtilisateursDemo(PDO $pdo): void
    {
        if (is_file(RACINE . '/database/demo-utilisateurs.sql')) {
            Migrations::executerFichier($pdo, RACINE . '/database/demo-utilisateurs.sql');
        }
    }

    /** 16 caractères lisibles (lettres et chiffres), assez longs pour la règle des 10 caractères de la gestion. */
    public static function motDePasse(int $longueur = 16): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $resultat = '';
        for ($i = 0; $i < $longueur; $i++) {
            $resultat .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $resultat;
    }
}
