<?php

namespace Database\Seeders;

use App\Services\DemoCommunityBuilder;
use Illuminate\Database\Seeder;

/**
 * Communauté de démonstration du poste de développement, des tests de bout
 * en bout et des captures du manuel.
 *
 * Connexion : 0990 000 001 / Waumini2026 (administrateur)
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'Waumini2026';

    public function run(DemoCommunityBuilder $builder): void
    {
        $builder->build(fn (int $n) => sprintf('+2439900000%02d', $n), self::PASSWORD);
    }
}
