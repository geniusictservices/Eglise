<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Affiche views/<nom>.php à l'intérieur d'un gabarit de views/layouts/.
 * Le contenu de la vue est disponible dans le gabarit sous $contenu.
 */
final class View
{
    public static function afficher(string $nom, array $donnees = [], ?string $gabarit = 'vitrine'): void
    {
        $contenu = self::rendre($nom, $donnees);
        if ($gabarit === null) {
            echo $contenu;
            return;
        }
        echo self::rendre('layouts/' . $gabarit, $donnees + ['contenu' => $contenu]);
    }

    public static function rendre(string $nom, array $donnees = []): string
    {
        extract($donnees, EXTR_SKIP);
        ob_start();
        require RACINE . '/views/' . $nom . '.php';
        return (string) ob_get_clean();
    }
}
