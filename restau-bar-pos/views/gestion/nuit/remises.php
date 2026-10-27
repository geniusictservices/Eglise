<?php
/**
 * Ventes > Remises et offerts : ventes brutes, remises, offerts et net de la période (devise du ticket) ; chaque remise
 * (ticket, ligne, motif, qui l'a accordée, qui l'a validée) ; limite de remise de chaque rôle.
 * @var array $periode @var list<array> $etablissements @var ?int $etablissement @var ?string $motif @var array<int, array> $totaux
 * @var list<array> $remises @var list<array> $roles @var array<int, array> $devises @var bool $peutLimiter @var ?string $flash
 */
use App\Core\View;

$jeton = e(App\Core\Session::jetonCsrf());
$formateur = new App\Repositories\DeviseRepository();
$m = static fn (float $x, int $id): string => isset($devises[$id]) ? $formateur->formater($x, $devises[$id]) : (string) $x;
?>
<link rel="stylesheet" href="/assets/css/gestion-salle.css">
<link rel="stylesheet" href="/assets/css/gestion-tresorerie.css">
<link rel="stylesheet" href="/assets/css/gestion-nuit.css">
<div class="sal-entete">
    <div>
        <h1><?= e(t('gnuit.remises_titre')) ?></h1>
        <p class="aide"><?= e(t('gnuit.remises_aide')) ?></p>
    </div>
</div>
<?php if ($flash): ?><p class="alerte" role="status"><?= e($flash) ?></p><?php endif; ?>
<?= View::rendre('gestion/tresorerie/_periode', ['periode' => $periode, 'action' => '/gestion/remises', 'garder' => ['etablissement' => $etablissement, 'motif' => $motif]]) ?>
<form class="sal-jour" method="get" action="/gestion/remises">
    <?php foreach (['periode' => $periode['cle'], 'du' => $periode['du'], 'au' => $periode['au']] as $k => $v): if ($v) { ?><input type="hidden" name="<?= $k ?>" value="<?= e((string) $v) ?>"><?php } endforeach; ?>
    <label class="champ"><span><?= e(t('gestion.etablissement')) ?></span>
        <select class="saisie" name="etablissement"><option value=""><?= e(t('gnuit.tous_etablissements')) ?></option>
            <?php foreach ($etablissements as $e): ?><option value="<?= (int) $e['id'] ?>" <?= $etablissement === (int) $e['id'] ? 'selected' : '' ?>><?= e($e['nom']) ?></option><?php endforeach; ?></select></label>
    <label class="champ"><span><?= e(t('nuit.c_motif')) ?></span>
        <select class="saisie" name="motif"><option value=""><?= e(t('gnuit.tous')) ?></option>
            <?php foreach (App\Core\Remises::MOTIFS as $mo): ?><option value="<?= $mo ?>" <?= $motif === $mo ? 'selected' : '' ?>><?= e(t('nuit.motif_' . $mo)) ?></option><?php endforeach; ?></select></label>
    <button class="bouton bouton--secondaire" type="submit"><?= e(t('tresorerie.afficher')) ?></button>
</form>
<section class="bloc" aria-labelledby="remises-totaux">
    <h2 id="remises-totaux"><?= e(t('gnuit.ventes_et_remises')) ?></h2>
    <?php foreach ($totaux as $deviseId => $tt): ?>
        <div class="chiffres gnuit-chiffres" data-devise="<?= e($devises[$deviseId]['code'] ?? '') ?>">
            <div class="chiffre"><span class="chiffre__libelle"><?= e(t('gnuit.ventes_brutes')) ?></span><strong class="chiffre__valeur montant" data-chiffre="brut"><?= e($m($tt['brut'], $deviseId)) ?></strong><span class="aide"><?= e(t('gnuit.tickets_n', ['n' => $tt['tickets']])) ?></span></div>
            <div class="chiffre"><span class="chiffre__libelle"><?= e(t('nuit.recu_remises')) ?></span><strong class="chiffre__valeur montant" data-chiffre="remises"><?= $tt['remises'] > 0 ? '−' : '' ?><?= e($m($tt['remises'], $deviseId)) ?></strong><span class="aide"><?= e(t('gnuit.tickets_remises', ['n' => $tt['remises_n']])) ?></span></div>
            <div class="chiffre"><span class="chiffre__libelle"><?= e(t('gnuit.dont_offerts')) ?></span><strong class="chiffre__valeur montant" data-chiffre="offerts"><?= e($m($tt['offerts'], $deviseId)) ?></strong></div>
            <div class="chiffre"><span class="chiffre__libelle"><?= e(t('gnuit.ventes_nettes')) ?></span><strong class="chiffre__valeur montant" data-chiffre="net"><?= e($m($tt['net'], $deviseId)) ?></strong></div>
        </div>
    <?php endforeach; ?>
    <?php if ($totaux === []): ?><p class="aide"><?= e(t('gnuit.aucune_vente')) ?></p><?php endif; ?>
</section>
<section class="bloc" aria-labelledby="remises-liste">
    <h2 id="remises-liste"><?= e(t('gnuit.remises_accordees')) ?></h2>
    <div class="defile">
        <table class="tableau">
            <thead><tr><th><?= e(t('recu.date')) ?></th><th><?= e(t('gnuit.vente')) ?></th><th><?= e(t('nuit.c_cible')) ?></th><th><?= e(t('nuit.c_remise')) ?></th><th class="nombre"><?= e(t('gnuit.montant')) ?></th><th><?= e(t('nuit.c_motif')) ?></th><th><?= e(t('gnuit.accordee_par')) ?></th></tr></thead>
            <tbody>
                <?php foreach ($remises as $r): ?>
                    <tr<?= $r['vente_statut'] === 'annulee' ? ' class="sal-annulee"' : '' ?>>
                        <td><?= e(date('d/m/Y H:i', strtotime($r['cree_le']))) ?></td>
                        <td><a href="/gestion/ventes/<?= (int) $r['vente_id'] ?>"><?= e($r['numero']) ?></a><br><small><?= e($r['etablissement_nom']) ?></small></td>
                        <td><?= $r['vente_ligne_id'] ? e($r['libelle']) : e(t('nuit.c_tout_ticket')) ?></td>
                        <td><?= e($r['type'] === 'offert' || (float) $r['taux'] >= 0.999999 ? t('nuit.c_offert') : ($r['type'] === 'montant' ? $m((float) $r['valeur'], (int) $r['devise_id']) : rtrim(rtrim(number_format((float) $r['taux'] * 100, 1, ',', ''), '0'), ',') . ' %')) ?></td>
                        <td class="nombre"><?= e($m((float) $r['montant'], (int) $r['ticket_devise_id'])) ?></td>
                        <td><?= e(t('nuit.motif_' . $r['motif'])) ?><?= $r['precision_motif'] ? '<br><small>' . e($r['precision_motif']) . '</small>' : '' ?></td>
                        <td><?= e($r['accorde_nom'] ?? '') ?><?= $r['valide_nom'] ? '<br><small>' . e(t('gnuit.validee_par', ['nom' => $r['valide_nom']])) . '</small>' : '' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($remises === []): ?><tr><td colspan="7"><?= e(t('gnuit.aucune_remise')) ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<section class="bloc" id="limites" aria-labelledby="remises-limites">
    <h2 id="remises-limites"><?= e(t('gnuit.limites_titre')) ?></h2>
    <p class="aide"><?= e(t('gnuit.limites_aide')) ?></p>
    <form class="sal-form" method="post" action="/gestion/remises/limites">
        <input type="hidden" name="csrf" value="<?= $jeton ?>">
        <?php foreach ($roles as $role): ?>
            <label class="champ sal-champ--court"><span><?= e(champ($role, 'nom')) ?></span>
                <?php if ((int) $role['tout'] === 1): ?>
                    <input class="saisie" value="<?= e(t('gnuit.sans_limite')) ?>" disabled>
                <?php else: ?>
                    <input class="saisie" name="limite[<?= (int) $role['id'] ?>]" inputmode="decimal" placeholder="<?= e(t('gnuit.sans_limite')) ?>" value="<?= $role['remise_max'] !== null ? e(rtrim(rtrim((string) $role['remise_max'], '0'), '.')) : '' ?>"<?= $peutLimiter ? '' : ' disabled' ?>>
                <?php endif; ?></label>
        <?php endforeach; ?>
        <?php if ($peutLimiter): ?><div class="sal-form__actions"><button class="bouton" type="submit"><?= e(t('gsalle.enregistrer')) ?></button></div><?php endif; ?>
    </form>
</section>
