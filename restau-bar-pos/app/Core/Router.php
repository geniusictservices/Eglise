<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Routeur minimal : get('/e/{slug}', [Controleur::class, 'methode']).
 * Les paramètres {nom} acceptent lettres minuscules, chiffres et tirets.
 */
final class Router
{
    private array $routes = [];

    public function get(string $motif, array $action): void
    {
        $this->ajouter('GET', $motif, $action);
    }

    public function post(string $motif, array $action): void
    {
        $this->ajouter('POST', $motif, $action);
    }

    private function ajouter(string $methode, string $motif, array $action): void
    {
        $regex = '#^' . preg_replace('#\{([a-z_]+)\}#', '(?P<$1>[a-z0-9-]+)', $motif) . '$#';
        $this->routes[] = [$methode, $regex, $action];
    }

    public function traiter(string $methode, string $chemin): void
    {
        $chemin = '/' . trim($chemin, '/');
        $methodeAutorisee = false;

        foreach ($this->routes as [$methodeRoute, $regex, [$classe, $fonction]]) {
            if (!preg_match($regex, $chemin, $correspondances)) {
                continue;
            }
            if ($methodeRoute !== $methode) {
                $methodeAutorisee = true;
                continue;
            }
            $params = array_filter($correspondances, 'is_string', ARRAY_FILTER_USE_KEY);
            (new $classe())->$fonction(...$params);
            return;
        }

        if ($methodeAutorisee) {
            http_response_code(405);
            echo 'Méthode non autorisée';
            return;
        }
        pageIntrouvable();
    }
}
