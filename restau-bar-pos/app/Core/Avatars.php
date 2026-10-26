<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Photos de profil : envoyées par le gérant (fiche de la personne), par la personne elle-même
 * (Mon compte, ou un selfie sur la caisse si l'établissement le permet).
 * Elles ne vont pas dans la médiathèque (réservée au catalogue et aux établissements).
 */
final class Avatars
{
    /** Enregistre la nouvelle photo, efface l'ancienne et rend le chemin public. @throws \RuntimeException message traduit */
    public static function remplacer(int $utilisateurId, array $fichier): string
    {
        $chemin = Images::enregistrer($fichier, 'avatars', 600, (string) $utilisateurId);
        $ancienne = Database::un('SELECT avatar FROM utilisateurs WHERE id = ?', [$utilisateurId])['avatar'] ?? null;
        Database::executer('UPDATE utilisateurs SET avatar = ? WHERE id = ?', [$chemin, $utilisateurId]);
        Images::supprimer($ancienne);
        return $chemin;
    }

    /**
     * Formulaire de photo (partials/_photo-form.php) : « retirer », ou une nouvelle image dans $_FILES['photo'].
     * Rend un message d'erreur traduit, ou null si c'est fait.
     */
    public static function traiterFormulaire(int $utilisateurId, string $nom): ?string
    {
        if (($_POST['action'] ?? '') === 'retirer') {
            self::retirer($utilisateurId);
            Journal::ecrire('photo_retiree', $nom);
            return null;
        }
        if (Images::requeteTropLourde()) {
            return t('image.trop_lourde', ['max' => Images::tailleLisible(Images::octetsMax())]);
        }
        $fichier = $_FILES['photo'] ?? null;
        if (!is_array($fichier) || ($fichier['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return t('profil.photo_choisir');
        }
        try {
            self::remplacer($utilisateurId, $fichier);
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }
        Journal::ecrire('photo_changee', $nom);
        return null;
    }

    public static function retirer(int $utilisateurId): void
    {
        $ancienne = Database::un('SELECT avatar FROM utilisateurs WHERE id = ?', [$utilisateurId])['avatar'] ?? null;
        Database::executer('UPDATE utilisateurs SET avatar = NULL WHERE id = ?', [$utilisateurId]);
        Images::supprimer($ancienne);
    }

    /** Initiales du nom : « Aline Mukeba » → « AM ». */
    public static function initiales(string $nom): string
    {
        $mots = array_slice(preg_split('/\s+/', trim($nom)) ?: [], 0, 2);
        return mb_strtoupper(implode('', array_map(static fn (string $m) => mb_substr($m, 0, 1), $mots)));
    }

    /** Teinte stable tirée du nom, pour que chacun garde la même couleur sans photo. */
    public static function teinte(string $nom): int
    {
        return (int) (hexdec(substr(md5($nom), 0, 4)) % 360);
    }
}
