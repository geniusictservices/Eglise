<?php
/**
 * Reçu d'une vente (ticket de caisse) : à l'écran après l'encaissement, à imprimer (80 mm), et dans Ventes.
 * Montants dans la devise du ticket (devise locale), puis l'équivalent dans les autres devises, le dollar d'abord.
 *
 * @var array $vente
 * @var list<array> $lignes
 * @var list<array> $paiements
 * @var list<array> $credits part à l'ardoise (dettes de la vente)
 * @var list<array> $totaux
 * @var array<int, array> $devises
 * @var array $etablissement
 */
$formateur = new App\Repositories\DeviseRepository();
$montant = static fn (float|string $m, int $deviseId): string => isset($devises[$deviseId]) ? $formateur->formater((float) $m, $devises[$deviseId]) : (string) $m;
$ticket = (int) $vente['devise_id'];
$autres = array_values(array_filter($totaux, static fn (array $t) => (int) $t['devise_id'] !== $ticket));
?>
<article class="recu<?= $vente['statut'] === 'annulee' ? ' recu--annule' : '' ?>" aria-labelledby="recu-numero-<?= (int) $vente['id'] ?>">
    <header class="recu__tete">
        <p class="recu__etab"><?= e($etablissement['nom']) ?></p>
        <?php if (!empty($etablissement['adresse'])): ?><p class="recu__info"><?= e($etablissement['adresse']) ?></p><?php endif; ?>
        <?php if (!empty($etablissement['telephone'])): ?><p class="recu__info"><?= e(afficherTelephone($etablissement['telephone'])) ?></p><?php endif; ?>
    </header>
    <dl class="recu__meta">
        <div><dt><?= e(t('recu.numero')) ?></dt><dd id="recu-numero-<?= (int) $vente['id'] ?>"><?= e($vente['numero']) ?></dd></div>
        <div><dt><?= e(t('recu.date')) ?></dt><dd><?= e(date('d/m/Y H:i', strtotime($vente['cree_le']))) ?></dd></div>
        <?php if (!empty($vente['vendeur'])): ?><div><dt><?= e(t('recu.vendeur')) ?></dt><dd><?= e($vente['vendeur']) ?></dd></div><?php endif; ?>
        <?php if (!empty($vente['zone_nom_fr'])): ?><div><dt><?= e(t('recu.zone')) ?></dt><dd><?= e(champ($vente, 'zone_nom')) ?></dd></div><?php endif; ?>
    </dl>
    <?php if ($vente['statut'] === 'annulee'): ?>
        <p class="recu__annule"><?= e(t('recu.annule')) ?></p>
    <?php endif; ?>
    <table class="recu__lignes">
        <tbody>
            <?php foreach ($lignes as $l): ?>
                <tr>
                    <td class="recu__qte"><?= (int) $l['quantite'] ?> ×</td>
                    <td><?= e($l['libelle']) ?><?php if ($l['format']): ?><br><small><?= e($l['format']) ?></small><?php endif; ?>
                        <?php if ((int) $l['quantite'] > 1): ?><br><small><?= e($montant($l['prix_unitaire'], $ticket)) ?></small><?php endif; ?></td>
                    <td class="recu__montant"><?= e($montant($l['montant'], $ticket)) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div class="recu__total">
        <span><?= e(t('recu.total')) ?></span>
        <strong><?= e($montant($vente['total'], $ticket)) ?></strong>
    </div>
    <?php if ($autres !== []): ?>
        <p class="recu__equivalent"><?= e(t('recu.soit')) ?> <?= e(implode(' · ', array_map(static fn (array $t) => $montant($t['total'], (int) $t['devise_id']), $autres))) ?></p>
    <?php endif; ?>
    <table class="recu__paiements">
        <tbody>
            <?php foreach ($paiements as $p): ?>
                <tr><td><?= e($p['compte']) ?><br><small><?= e(t('tresorerie.type_' . $p['moyen'])) ?></small></td><td class="recu__montant"><?= e($montant($p['recu'], (int) $p['devise_id'])) ?></td></tr>
            <?php endforeach; ?>
            <?php foreach ($credits ?? [] as $c): ?>
                <tr class="recu__ardoise"><td><?= e(t('recu.ardoise')) ?><br><small><?= e(t('recu.ardoise_detail', ['client' => $c['client_nom'], 'date' => date('d/m/Y', strtotime($c['echeance']))])) ?></small></td><td class="recu__montant"><?= e($montant($c['montant'], (int) $c['devise_id'])) ?></td></tr>
            <?php endforeach; ?>
            <?php if ((float) $vente['rendu'] > 0): ?>
                <tr class="recu__rendu"><td><?= e(t('recu.rendu')) ?></td><td class="recu__montant"><?= e($montant($vente['rendu'], (int) $vente['rendu_devise_id'])) ?></td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    <p class="recu__merci"><?= e(t('recu.merci')) ?></p>
</article>
