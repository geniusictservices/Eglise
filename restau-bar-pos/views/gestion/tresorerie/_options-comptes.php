<?php
/**
 * Options d'une liste de comptes, groupées par type (caisse, mobile money, banque), avec ce qu'il faut
 * au JavaScript : type, et devises tenues avec leur solde (data-devises = [[devise_id, solde], …],
 * dans l'ordre des devises : locale, dollar, autres).
 * @var array<int, array> $comptes
 * @var array<int, array> $devises
 * @var ?int $choisi
 */

use App\Controllers\Gestion\TresorerieAffichage as A;

$parType = [];
foreach ($comptes as $compte) {
    $parType[$compte['type']][] = $compte;
}
?>
<?php foreach (App\Repositories\GestionTresorerieRepository::TYPES as $type): if (empty($parType[$type])) continue; ?>
    <optgroup label="<?= e(t('tresorerie.type_' . $type)) ?>" data-type="<?= e($type) ?>">
        <?php foreach ($parType[$type] as $compte):
            $tenues = array_filter($compte['devises'], static fn (array $d): bool => $d['tenue'] && isset($devises[$d['devise_id']]));
            $soldes = implode(' · ', array_map(static fn (array $d): string => A::montant($d['solde'], $devises[$d['devise_id']]), $tenues));
            ?>
            <option value="<?= (int) $compte['id'] ?>" data-type="<?= e($type) ?>"
                    data-devises="<?= e((string) json_encode(array_values(array_map(static fn (array $d): array => [$d['devise_id'], $d['solde']], $tenues)))) ?>"
                    <?= (int) ($choisi ?? 0) === (int) $compte['id'] ? 'selected' : '' ?>><?= e($compte['nom'] . ($soldes !== '' ? ' (' . $soldes . ')' : '')) ?></option>
        <?php endforeach; ?>
    </optgroup>
<?php endforeach; ?>
