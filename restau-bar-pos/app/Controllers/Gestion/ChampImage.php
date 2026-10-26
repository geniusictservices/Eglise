<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Repositories\MediathequeRepository;

/**
 * Lecture d'un « champ image » (views/gestion/_champ-image.php) envoyé avec un formulaire :
 * - un fichier dans l'ancien <input type="file"> (repli sans JavaScript) ;
 * - ou le chemin d'une image de la médiathèque dans le champ caché (choix, glisser-déposer, collage) ;
 * - ou un champ caché vide : l'image est retirée.
 * Le chemin choisi doit exister dans la table medias.
 */
final class ChampImage
{
    /**
     * @param string  $champFichier nom de l'<input type="file">, ex. « photo »
     * @param string  $champMedia   nom du champ caché, ex. « photo_media »
     * @param ?string $actuel       image enregistrée aujourd'hui
     * @return array{action: string, fichier: ?array, chemin: ?string, media: ?array, erreur: ?string}
     *         action : « aucune », « fichier », « choisie » ou « retiree »
     */
    public static function lire(string $champFichier, string $champMedia, ?string $actuel, MediathequeRepository $mediatheque): array
    {
        $resultat = ['action' => 'aucune', 'fichier' => null, 'chemin' => null, 'media' => null, 'erreur' => null];

        $fichier = $_FILES[$champFichier] ?? null;
        if (is_array($fichier) && ($fichier['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            return ['action' => 'fichier', 'fichier' => $fichier] + $resultat;
        }

        $valeur = $_POST[$champMedia] ?? null;
        if (!is_string($valeur)) {
            return $resultat;
        }
        $valeur = trim($valeur);
        $actuel = (string) $actuel;
        if ($valeur === $actuel) {
            return $resultat;
        }
        if ($valeur === '') {
            return ['action' => 'retiree'] + $resultat;
        }
        $media = $mediatheque->parChemin($valeur);
        if ($media === null) {
            return ['erreur' => t('champ_image.erreur_inconnue')] + $resultat;
        }
        return ['action' => 'choisie', 'chemin' => (string) $media['chemin'], 'media' => $media] + $resultat;
    }
}
