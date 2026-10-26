<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use DateTimeImmutable;

final class EtablissementRepository
{
    public function actifs(): array
    {
        return Database::tous('SELECT * FROM etablissements WHERE actif = 1 ORDER BY position, nom');
    }

    public function parSlug(string $slug): ?array
    {
        return Database::un('SELECT * FROM etablissements WHERE slug = ? AND actif = 1', [$slug]);
    }

    public function parId(int $id): ?array
    {
        return Database::un('SELECT * FROM etablissements WHERE id = ?', [$id]);
    }

    public function zoneParCode(int $etablissementId, string $code): ?array
    {
        return Database::un('SELECT * FROM zones WHERE etablissement_id = ? AND code = ?', [$etablissementId, $code]);
    }

    public function zones(int $etablissementId): array
    {
        return Database::tous('SELECT * FROM zones WHERE etablissement_id = ? ORDER BY id', [$etablissementId]);
    }

    /** Identifiants des devises acceptées par l'établissement. */
    public function deviseIds(int $etablissementId): array
    {
        return array_map('intval', array_column(
            Database::tous('SELECT devise_id FROM etablissement_devises WHERE etablissement_id = ?', [$etablissementId]),
            'devise_id'
        ));
    }

    public function horaires(int $etablissementId): array
    {
        return Database::tous(
            'SELECT * FROM horaires WHERE etablissement_id = ? ORDER BY jour, ouverture',
            [$etablissementId]
        );
    }

    /**
     * Horaires regroupés par service puis par plage, pour l'affichage :
     * [['service' => ligne, 'plages' => [['jours' => [1,2,3], 'ouverture' => '11:00', 'fermeture' => '15:00']]]]
     */
    public function horairesRegroupes(array $horaires): array
    {
        $services = [];
        foreach ($horaires as $h) {
            $cle = $h['service_fr'];
            $services[$cle] ??= ['service' => $h, 'plages' => []];
            $plage = substr($h['ouverture'], 0, 5) . '-' . substr($h['fermeture'], 0, 5);
            $services[$cle]['plages'][$plage] ??= [
                'jours' => [],
                'ouverture' => substr($h['ouverture'], 0, 5),
                'fermeture' => substr($h['fermeture'], 0, 5),
            ];
            $services[$cle]['plages'][$plage]['jours'][] = (int) $h['jour'];
        }
        foreach ($services as &$service) {
            $service['plages'] = array_values($service['plages']);
        }
        return array_values($services);
    }

    /**
     * État d'ouverture à l'instant donné.
     * Retourne ['ouvert' => bool, 'service' => ?ligne, 'jusqua' => ?'HH:MM', 'prochain' => ?['jour' => n, 'heure' => 'HH:MM']]
     */
    public function etatOuverture(array $horaires, DateTimeImmutable $maintenant): array
    {
        $jour = (int) $maintenant->format('N');
        $hier = $jour === 1 ? 7 : $jour - 1;
        $heure = $maintenant->format('H:i:s');

        foreach ($horaires as $h) {
            $passeMinuit = $h['fermeture'] <= $h['ouverture'];
            $ouvertAujourdhui = (int) $h['jour'] === $jour && $heure >= $h['ouverture']
                && ($passeMinuit || $heure < $h['fermeture']);
            $ouvertDepuisHier = $passeMinuit && (int) $h['jour'] === $hier && $heure < $h['fermeture'];

            if ($ouvertAujourdhui || $ouvertDepuisHier) {
                return ['ouvert' => true, 'service' => $h, 'jusqua' => substr($h['fermeture'], 0, 5), 'prochain' => null];
            }
        }

        // Prochaine ouverture dans les 7 jours
        for ($decalage = 0; $decalage < 7; $decalage++) {
            $j = (($jour - 1 + $decalage) % 7) + 1;
            foreach ($horaires as $h) {
                if ((int) $h['jour'] === $j && ($decalage > 0 || $h['ouverture'] > $heure)) {
                    return [
                        'ouvert' => false,
                        'service' => $h,
                        'jusqua' => null,
                        'prochain' => ['jour' => $j, 'aujourdhui' => $decalage === 0, 'heure' => substr($h['ouverture'], 0, 5)],
                    ];
                }
            }
        }
        return ['ouvert' => false, 'service' => null, 'jusqua' => null, 'prochain' => null];
    }
}
