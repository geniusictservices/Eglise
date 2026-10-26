<?php
/**
 * Tableau d'un regroupement du rapport des créances (par responsable, serveur, établissement, devise, client) :
 * nombre de dettes ouvertes, reste par devise, équivalent en devise de référence, dont en retard, part du total.
 * @var string $cle responsable | servi | etablissement | devise | client
 * @var string $titre
 * @var ?string $aide
 * @var list<array> $lignes nb, devises, reference, retard, sans_taux, nom?, avatar?, id?
 * @var array<int, array> $devises
 * @var ?array $reference
 * @var float $total équivalent total (pour la part)
 * @var bool $voirDettes
 * @var bool $voirClients
 */

use App\Controllers\Gestion\DetteAffichage as D;
use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;

$personne = in_array($cle, ['responsable', 'servi'], true);
$pourcent = static fn (float $part): string => (string) round($part * 100) . "\u{00A0}%";
$filtre = ['responsable' => 'responsable', 'servi' => 'servi', 'etablissement' => 'etablissement', 'devise' => 'devise', 'client' => 'client'][$cle];
?>
<section class="bloc det-rapport-groupe" aria-labelledby="rc-<?= e($cle) ?>" data-groupe="<?= e($cle) ?>">
    <h2 id="rc-<?= e($cle) ?>"><?= e($titre) ?></h2>
    <?php if (!empty($aide)): ?><p class="aide"><?= e($aide) ?></p><?php endif; ?>
    <div class="tre-defile">
        <table class="tableau det-liste det-liste--rapport">
            <thead>
                <tr>
                    <th scope="col"><?= e(t('rcreances.col_' . $cle)) ?></th>
                    <th scope="col" class="nombre"><?= e(t('rcreances.col_nb')) ?></th>
                    <th scope="col" class="nombre"><?= e(t('rcreances.col_reste')) ?></th>
                    <?php if ($reference): ?><th scope="col" class="nombre"><?= e(t('rcreances.col_reference', ['code' => $reference['code']])) ?></th><?php endif; ?>
                    <th scope="col"><?= e(t('rcreances.col_part')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lignes as $l):
                    $nom = match ($cle) {
                        'devise' => ($devises[(int) $l['id']]['code'] ?? '?') . ' · ' . champ($devises[(int) $l['id']] ?? ['nom_fr' => ''], 'nom'),
                        default => $l['nom'] ?? t($personne ? 'rcreances.personne_inconnue' : 'gventes.vendeur_inconnu'),
                    };
                    $part = $total > 0 ? $l['reference'] / $total : 0.0;
                    $lien = null;
                    if ($cle === 'client' && $voirClients) {
                        $lien = '/gestion/clients/' . (int) $l['id'];
                    } elseif ($cle !== 'client' && $voirDettes && ($l['id'] ?? null) !== null) {
                        $lien = '/gestion/dettes?' . http_build_query([$filtre => (int) $l['id']]);
                    }
                    ?>
                    <tr data-ligne="<?= e((string) ($l['id'] ?? '0')) ?>">
                        <td class="det-cellule--titre">
                            <?php if ($personne && isset($l['nom'])): ?>
                                <span class="det-personne"><?= View::rendre('partials/_avatar', ['nom' => $l['nom'], 'photo' => $l['avatar'] ?? null, 'classe' => 'avatar--petit']) ?>
                                    <?php if ($lien): ?><a class="det-lien-fort" href="<?= e($lien) ?>"><?= e($nom) ?></a><?php else: ?><strong><?= e($nom) ?></strong><?php endif; ?></span>
                            <?php elseif ($lien): ?>
                                <a class="det-lien-fort" href="<?= e($lien) ?>"><?= e($nom) ?></a>
                            <?php else: ?>
                                <strong><?= e($nom) ?></strong>
                            <?php endif; ?>
                            <?php if ($cle === 'client' && ($l['type'] ?? '') === 'personnel'): ?><span class="badge badge--glace"><?= e(t('gclients.type_personnel')) ?></span><?php endif; ?>
                        </td>
                        <td class="nombre det-cellule--nb" data-libelle="<?= e(t('rcreances.col_nb')) ?>"><?= (int) $l['nb'] ?></td>
                        <td class="nombre det-cellule--montant">
                            <?php foreach (D::ordonner($l['devises'], $devises) as $did => $m): ?>
                                <span class="montant det-montant-ligne" data-devise="<?= e($devises[$did]['code'] ?? '') ?>"><?= e(D::montant($m, $did, $devises)) ?></span>
                            <?php endforeach; ?>
                        </td>
                        <?php if ($reference): ?>
                            <td class="nombre det-cellule--reste">
                                <strong class="montant" data-reference><?= e(A::montant($l['reference'], $reference)) ?><?= $l['sans_taux'] ? ' *' : '' ?></strong>
                                <?php if ($l['retard'] > 0): ?><span class="tre-sous-texte det-retard"><?= e(t('rcreances.dont_retard', ['montant' => A::montant($l['retard'], $reference)])) ?></span><?php endif; ?>
                            </td>
                        <?php endif; ?>
                        <td class="det-cellule--part"><span class="det-part"><span class="tre-categories__barre"><span style="width: <?= e((string) round(min(1, $part) * 100, 1)) ?>%"></span></span><span class="nombre"><?= e($pourcent($part)) ?></span></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
