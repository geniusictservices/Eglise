<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\I18n;

/**
 * Devises configurées, taux en vigueur, conversions et affichage des montants.
 * Un taux = nombre d'unités de la devise pour 1 unité de la devise de référence.
 */
final class DeviseRepository
{
    /** @var array<int, array>|null devises actives indexées par id, avec leur taux en vigueur */
    private ?array $devises = null;

    /** @return array<int, array> */
    public function actives(): array
    {
        if ($this->devises === null) {
            $lignes = Database::tous(
                'SELECT d.*,
                        (SELECT t.taux FROM taux_change t
                          WHERE t.devise_id = d.id AND t.valable_depuis <= ?
                          ORDER BY t.valable_depuis DESC, t.id DESC LIMIT 1) AS taux
                   FROM devises d
                  WHERE d.active = 1
                  ORDER BY d.position, d.code',
                // Heure locale de l'instance (les taux sont saisis en heure locale)
                [date('Y-m-d H:i:s')]
            );
            $this->devises = [];
            foreach ($lignes as $ligne) {
                if ($ligne['est_reference']) {
                    $ligne['taux'] = '1';
                }
                $this->devises[(int) $ligne['id']] = $ligne;
            }
        }
        return $this->devises;
    }

    public function reference(): ?array
    {
        foreach ($this->actives() as $devise) {
            if ($devise['est_reference']) {
                return $devise;
            }
        }
        return null;
    }

    /** Convertit un montant en devise de référence vers une devise, avec la règle d'arrondi de celle-ci. */
    public function convertirDepuisReference(float $montant, array $devise): ?float
    {
        if ($devise['taux'] === null || (float) $devise['taux'] <= 0) {
            return null;
        }
        return $this->arrondir($montant * (float) $devise['taux'], $devise);
    }

    public function arrondir(float $montant, array $devise): float
    {
        $pas = (float) $devise['arrondi'];
        if ($pas > 0) {
            $montant = round($montant / $pas) * $pas;
        }
        return round($montant, (int) $devise['decimales']);
    }

    /**
     * « 5 500 FC », « $2.50 », « 2,50 $ »…
     * En mode compact, un montant rond s'affiche sans décimales : « $2 » plutôt que « $2.00 ».
     */
    public function formater(float $montant, array $devise, bool $compact = true): string
    {
        $decimales = (int) $devise['decimales'];
        if ($compact && abs($montant - round($montant)) < 0.005) {
            $decimales = 0;
        }
        $nombre = I18n::langue() === 'fr'
            ? number_format($montant, $decimales, ',', "\u{202F}")
            : number_format($montant, $decimales, '.', ',');

        return $devise['symbole_avant']
            ? $devise['symbole'] . $nombre
            : $nombre . "\u{00A0}" . $devise['symbole'];
    }
}
