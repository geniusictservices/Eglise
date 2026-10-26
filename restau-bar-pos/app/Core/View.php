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
        // Portée isolée : seules les données transmises sont visibles dans la vue,
        // et aucune variable interne ne peut masquer une donnée (« nom », « donnees »…).
        $inclure = static function (string $__fichier, array $__variables): void {
            extract($__variables, EXTR_SKIP);
            require $__fichier;
        };
        ob_start();
        $inclure(RACINE . '/views/' . $nom . '.php', $donnees);
        return (string) ob_get_clean();
    }
}
