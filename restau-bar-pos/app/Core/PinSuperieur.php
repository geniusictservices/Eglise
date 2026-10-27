<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Validation par un supérieur à la caisse (cahier des charges, section 6.2) : il touche son nom et tape son PIN sur
 * l'appareil de l'employé (remise au-delà de la limite, minimum de consommation levé…). Mêmes règles que la
 * connexion par PIN : 5 PIN faux et le PIN est bloqué ; trop d'échecs sur une caisse partagée et l'appareil refuse
 * les PIN 10 minutes. Le journal garde le nom des deux personnes (écrit par l'appelant).
 */
final class PinSuperieur
{
    /**
     * @param callable(int): bool $permis la personne peut-elle valider (droit, limite) ?
     * @return array{id: int, nom: string} le supérieur
     * @throws PinRefuse
     */
    public static function verifier(int $valideurId, string $pin, ?array $appareil, callable $permis, string $contexte): array
    {
        if ($appareil !== null && CaissePartagee::appareilSature((int) $appareil['id'])) {
            throw new PinRefuse(t('caisse_pin.appareil_sature'), 429);
        }
        $valideur = $valideurId > 0 ? Database::un('SELECT * FROM utilisateurs WHERE id = ? AND actif = 1 AND pin IS NOT NULL', [$valideurId]) : null;
        if ($valideur === null || !$permis($valideurId)) {
            throw new PinRefuse(t('nuit.validation_requise'), 403, 'validation');
        }
        if ($valideur['pin_bloque_le'] !== null) {
            throw new PinRefuse(t('caisse_pin.bloque', ['nom' => $valideur['nom']]), 423);
        }
        if (!CaissePartagee::pinValide($pin) || !password_verify($pin, (string) $valideur['pin'])) {
            $essais = (int) $valideur['pin_essais'] + 1;
            $bloque = $essais >= CaissePartagee::ESSAIS_PIN;
            Database::executer('UPDATE utilisateurs SET pin_essais = ?, pin_bloque_le = ? WHERE id = ?', [$bloque ? 0 : $essais, $bloque ? date('Y-m-d H:i:s') : null, $valideurId]);
            if ($appareil !== null) {
                Database::executer('UPDATE appareils_caisse SET pin_echecs = pin_echecs + 1 WHERE id = ?', [(int) $appareil['id']]);
            }
            Journal::ecrire($bloque ? 'pin_bloque' : 'pin_echec', $valideur['nom'], ['validation' => $contexte], $valideurId, $appareil ? (int) $appareil['id'] : null);
            throw new PinRefuse(
                $bloque ? t('caisse_pin.bloque', ['nom' => $valideur['nom']]) : t('caisse_pin.incorrect', ['reste' => CaissePartagee::ESSAIS_PIN - $essais]),
                422
            );
        }
        Database::executer('UPDATE utilisateurs SET pin_essais = 0 WHERE id = ?', [$valideurId]);
        return ['id' => $valideurId, 'nom' => (string) $valideur['nom']];
    }

    /**
     * Personnes qui peuvent valider ici (PIN connu, actives), pour la fenêtre de la caisse.
     * @param callable(int): bool $permis
     * @return list<array{id: int, nom: string, avatar: ?string, bloque: bool}>
     */
    public static function valideurs(callable $permis): array
    {
        $liste = [];
        foreach (Database::tous('SELECT id, nom, avatar, pin_bloque_le FROM utilisateurs WHERE actif = 1 AND pin IS NOT NULL ORDER BY nom') as $u) {
            if ($permis((int) $u['id'])) {
                $liste[] = ['id' => (int) $u['id'], 'nom' => $u['nom'], 'avatar' => $u['avatar'], 'bloque' => $u['pin_bloque_le'] !== null];
            }
        }
        return $liste;
    }
}
