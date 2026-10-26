<?php
/**
 * Options d'une liste de comptes, groupées par moyen (espèces, mobile money, banque),
 * avec ce qu'il faut au JavaScript : devise, solde, moyen.
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
        <?php foreach ($parType[$type] as $compte): $devise = $devises[(int) $compte['devise_id']] ?? null; if (!$devise) continue; ?>
            <option value="<?= (int) $compte['id'] ?>" data-type="<?= e($type) ?>" data-devise="<?= (int) $devise['id'] ?>"
                    data-solde="<?= e((string) (float) $compte['solde']) ?>"
                    <?= (int) ($choisi ?? 0) === (int) $compte['id'] ? 'selected' : '' ?>><?= e($compte['nom'] . ' · ' . A::montant($compte['solde'], $devise)) ?></option>
        <?php endforeach; ?>
    </optgroup>
<?php endforeach; ?>
