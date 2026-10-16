<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Les en-têtes qui protègent chaque page : pas d'inclusion dans un cadre étranger
 * (détournement de clic sur le formulaire de don ou l'application), pas de devinette
 * de type de contenu sur les fichiers servis, HTTPS imposé une fois établi.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'geolocation=(), microphone=(), payment=()');
        if (! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', "frame-ancestors 'self'; object-src 'none'; base-uri 'self'");
        }
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
