<?php

declare(strict_types=1);

namespace App\Console;

/**
 * E-mails de la console (relances d'abonnement) par la fonction mail() de PHP, disponible sur l'hébergement
 * mutualisé LWS. Expéditeur : réglage email.expediteur (une adresse du domaine, créée dans cPanel).
 */
final class Courriel
{
    public static function pret(): bool
    {
        return filter_var(Reglages::get('email.expediteur'), FILTER_VALIDATE_EMAIL) !== false;
    }

    /** @return array{ok: bool, erreur: ?string} */
    public static function envoyer(string $a, string $sujet, string $texte): array
    {
        $expediteur = (string) Reglages::get('email.expediteur');
        $nom = (string) Reglages::get('plateforme.nom', 'restau-bar-pos');
        $entetes = [
            'From' => mb_encode_mimeheader($nom, 'UTF-8') . ' <' . $expediteur . '>',
            'Reply-To' => Reglages::get('plateforme.contact_email', $expediteur),
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => '8bit',
        ];
        $ok = @mail($a, mb_encode_mimeheader($sujet, 'UTF-8'), $texte, $entetes, '-f' . $expediteur);
        return ['ok' => $ok, 'erreur' => $ok ? null : 'mail() a refusé le message'];
    }
}
