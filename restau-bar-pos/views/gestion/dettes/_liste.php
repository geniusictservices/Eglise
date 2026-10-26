<?php
/**
 * Tableau des dettes (liste des dettes, fiche d'un client) ; en cartes sur téléphone.
 * @var list<array> $dettes avec client_nom, etablissement_nom, vente_numero, responsable_nom, garant_nom
 * @var array<int, array> $devises
 * @var bool $avecClient colonne du client
 * @var bool $liens lien vers la fiche de chaque dette (dettes.voir)
 * @var string $aujourdhui Y-m-d
 */

use App\Controllers\Gestion\DetteAffichage as D;
use App\Controllers\Gestion\TresorerieAffichage as A;

$aujourdhui ??= date('Y-m-d');
?>
<div class="tre-defile">
    <table class="tableau det-liste det-liste--dettes">
        <thead>
            <tr>
                <th scope="col"><?= e(t('gdettes.col_dette')) ?></th>
                <?php if (!empty($avecClient)): ?><th scope="col"><?= e(t('gdettes.col_client')) ?></th><?php endif; ?>
                <th scope="col"><?= e(t('gestion.etablissement')) ?></th>
                <th scope="col" class="nombre"><?= e(t('gdettes.col_montant')) ?></th>
                <th scope="col" class="nombre"><?= e(t('gdettes.col_reste')) ?></th>
                <th scope="col"><?= e(t('gdettes.col_echeance')) ?></th>
                <th scope="col"><?= e(t('gdettes.col_responsable')) ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($dettes as $d):
                $ouverte = $d['statut'] === 'ouverte';
                $retard = $ouverte && $d['echeance'] < $aujourdhui;
                ?>
                <tr data-dette="<?= (int) $d['id'] ?>" class="<?= $ouverte ? '' : 'det-ligne--close' ?>">
                    <td class="det-cellule--titre">
                        <?php if (!empty($liens)): ?>
                            <a class="det-lien-fort" href="/gestion/dettes/<?= (int) $d['id'] ?>"><?= e(t('gdettes.fiche_titre', ['id' => $d['id']])) ?></a>
                        <?php else: ?>
                            <strong><?= e(t('gdettes.fiche_titre', ['id' => $d['id']])) ?></strong>
                        <?php endif; ?>
                        <span class="badge <?= D::badgeDette($d['statut']) ?>"><?= e(t('gdettes.statut_' . $d['statut'])) ?></span>
                        <span class="tre-sous-texte"><?= e(A::dateHeure($d['cree_le'])) ?><?= $d['vente_numero'] ? ' · ' . e($d['vente_numero']) : '' ?></span>
                    </td>
                    <?php if (!empty($avecClient)): ?>
                        <td class="det-cellule--client">
                            <a class="lien" href="/gestion/clients/<?= (int) $d['client_id'] ?>"><?= e($d['client_nom']) ?></a>
                            <?php if (($d['client_type'] ?? '') === 'personnel'): ?><span class="badge badge--glace"><?= e(t('gclients.type_personnel')) ?></span><?php endif; ?>
                        </td>
                    <?php endif; ?>
                    <td class="det-cellule--etab"><?= e($d['etablissement_nom']) ?></td>
                    <td class="nombre det-cellule--montant" data-libelle="<?= e(t('gdettes.col_montant')) ?>"><span class="montant"><?= e(D::montant($d['montant'], $d['devise_id'], $devises)) ?></span></td>
                    <td class="nombre det-cellule--reste"><strong class="montant"><?= e($ouverte ? D::montant($d['reste'], $d['devise_id'], $devises) : '—') ?></strong></td>
                    <td class="det-cellule--echeance">
                        <?php if ($ouverte): ?>
                            <span class="<?= $retard ? 'det-retard' : '' ?>"><?= e(t($retard ? 'gdettes.echeance_depassee' : 'gdettes.echeance_le', ['date' => A::jour($d['echeance'])])) ?></span>
                        <?php else: ?>
                            <span class="aide"><?= e(A::jour($d['cloturee_le'] ?? $d['echeance'])) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="det-cellule--personne">
                        <?= e($d['responsable_nom'] ?? t('gdettes.sans_responsable')) ?>
                        <?php if (($d['garant_statut'] ?? null) === 'en_attente' && $ouverte): ?>
                            <span class="badge badge--glace" title="<?= e(t('gdettes.garant_attente_titre', ['nom' => (string) $d['garant_nom']])) ?>"><?= e(t('gdettes.badge_garant_attente')) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
