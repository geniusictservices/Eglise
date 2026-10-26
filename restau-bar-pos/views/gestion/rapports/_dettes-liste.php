<?php
/**
 * Détail des dettes d'une personne (rapport du personnel) : client, ticket, date, échéance, reste dû par devise.
 * @var list<array> $dettes lignes de RapportRepository::dettesParResponsable()
 * @var array<int, array> $devises
 * @var bool $lienDettes
 */

use App\Controllers\Gestion\DetteAffichage as D;
use App\Controllers\Gestion\TresorerieAffichage as A;
?>
<div class="rap-defile">
    <table class="tableau rap-tableau">
        <thead><tr>
            <th scope="col"><?= e(t('gdettes.col_client')) ?></th>
            <th scope="col"><?= e(t('rpersonnel.csv_date')) ?></th>
            <th scope="col"><?= e(t('rpersonnel.csv_echeance')) ?></th>
            <th scope="col"><?= e(t('gestion.etablissement')) ?></th>
            <th scope="col" class="nombre"><?= e(t('rpersonnel.csv_montant')) ?></th>
            <th scope="col" class="nombre"><?= e(t('rpersonnel.csv_reste')) ?></th>
        </tr></thead>
        <tbody>
            <?php foreach ($dettes as $d): ?>
                <tr data-dette="<?= (int) $d['id'] ?>">
                    <th scope="row"><?php if ($lienDettes): ?><a href="/gestion/dettes/<?= (int) $d['id'] ?>"><?= e($d['client_nom']) ?></a><?php else: ?><?= e($d['client_nom']) ?><?php endif; ?>
                        <?php if ($d['numero']): ?><span class="rap-sous"><?= e($d['numero']) ?></span><?php endif; ?></th>
                    <td><?= e(A::jour($d['cree_le'])) ?></td>
                    <td><?= e(A::jour($d['echeance'])) ?><?php if ($d['en_retard']): ?> <span class="badge badge--erreur"><?= e(t('rtableau.en_retard')) ?></span><?php endif; ?></td>
                    <td><?= e($d['etablissement_nom']) ?></td>
                    <td class="nombre montant"><?= e(D::montant($d['montant'], (int) $d['devise_id'], $devises)) ?></td>
                    <td class="nombre"><strong class="montant"><?= e(D::montant($d['reste'], (int) $d['devise_id'], $devises)) ?></strong></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
