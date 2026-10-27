<?php
/**
 * Relevé des commissions d'un promoteur sur une période (gestion et espace du promoteur).
 * @var array $releve @var array $promoteur @var array<int, array> $devises
 */
$formateur = new App\Repositories\DeviseRepository();
$d = $devises[(int) $promoteur['devise_id']] ?? null;
$m = static fn (float $x): string => $d ? $formateur->formater($x, $d) : (string) $x;
$badges = ['due' => 'badge--braise', 'payee' => 'badge--succes', 'annulee' => 'badge--neutre'];
?>
<div class="chiffres">
    <div class="chiffre"><span class="chiffre__libelle"><?= e(t('gnuit.du')) ?></span><strong class="chiffre__valeur montant" data-chiffre="du"><?= e($m($releve['du'])) ?></strong></div>
    <div class="chiffre"><span class="chiffre__libelle"><?= e(t('gnuit.paye')) ?></span><strong class="chiffre__valeur montant"><?= e($m($releve['paye'])) ?></strong></div>
</div>
<div class="defile">
    <table class="tableau">
        <thead><tr><th><?= e(t('gnuit.nuit')) ?></th><th><?= e(t('gnuit.origine')) ?></th><th class="nombre"><?= e(t('gnuit.base')) ?></th><th class="nombre"><?= e(t('gnuit.commission')) ?></th><th><?= e(t('gnuit.statut')) ?></th></tr></thead>
        <tbody>
            <?php foreach ($releve['lignes'] as $l): ?>
                <tr>
                    <td><?= e(date('d/m/Y', strtotime($l['journee']))) ?><?= $l['evenement_titre'] ? '<br><small>' . e($l['evenement_titre']) . '</small>' : '' ?></td>
                    <td><?= $l['type'] === 'entree' ? e(t('gnuit.commission_entrees', ['nom' => $l['invite_nom'] ?? '', 'n' => (int) $l['base']])) : e(t('gnuit.commission_note', ['note' => $l['table_nom'] ? t('salle.table_nom', ['nom' => $l['table_nom']]) : ($l['note_nom'] ?? '')])) ?></td>
                    <td class="nombre"><?= $l['type'] === 'entree' ? (int) $l['base'] : e($m((float) $l['base'])) ?></td>
                    <td class="nombre"><?= e($m((float) $l['montant'])) ?></td>
                    <td><span class="badge <?= $badges[$l['statut']] ?>"><?= e(t('gnuit.commission_' . $l['statut'])) ?></span></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($releve['lignes'] === []): ?><tr><td colspan="5"><?= e(t('gnuit.aucune_commission')) ?></td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
