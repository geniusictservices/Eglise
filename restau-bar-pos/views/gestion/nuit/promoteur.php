<?php
/**
 * Nuit > Promoteurs > un promoteur : relevé des commissions d'une période et paiement par la trésorerie (dépense de la
 * catégorie « Commissions », compte qui tient la devise du promoteur), paiements passés.
 * @var array $promoteur @var array $periode @var string $du @var string $au @var array $releve @var list<array> $paiements
 * @var array<int, array> $devises @var list<array> $comptes @var bool $peutPayer @var ?string $flash
 */
use App\Core\View;

$jeton = e(App\Core\Session::jetonCsrf());
$formateur = new App\Repositories\DeviseRepository();
$d = $devises[(int) $promoteur['devise_id']] ?? null;
?>
<link rel="stylesheet" href="/assets/css/gestion-salle.css">
<link rel="stylesheet" href="/assets/css/gestion-tresorerie.css">
<link rel="stylesheet" href="/assets/css/gestion-nuit.css">
<div class="sal-entete">
    <div>
        <h1><?= e($promoteur['nom']) ?></h1>
        <p class="aide"><?= e(t('gnuit.releve_aide')) ?></p>
    </div>
    <a class="bouton bouton--secondaire" href="/gestion/nuit/promoteurs?modifier=<?= (int) $promoteur['id'] ?>#formulaire"><?= e(t('gnuit.modifier')) ?></a>
</div>
<?php if ($flash): ?><p class="alerte" role="status"><?= e($flash) ?></p><?php endif; ?>
<?= View::rendre('gestion/tresorerie/_periode', ['periode' => $periode, 'action' => '/gestion/nuit/promoteurs/' . (int) $promoteur['id'], 'garder' => []]) ?>
<section class="bloc" aria-labelledby="nuit-releve">
    <h2 id="nuit-releve"><?= e(t('gnuit.releve', ['du' => date('d/m/Y', strtotime($du)), 'au' => date('d/m/Y', strtotime($au))])) ?></h2>
    <?= View::rendre('gestion/nuit/_releve', ['releve' => $releve, 'promoteur' => $promoteur, 'devises' => $devises]) ?>
</section>
<?php if ($peutPayer && $releve['du'] > 0): ?>
    <section class="bloc" aria-labelledby="nuit-payer">
        <h2 id="nuit-payer"><?= e(t('gnuit.payer_titre', ['montant' => $d ? $formateur->formater($releve['du'], $d) : ''])) ?></h2>
        <?php if ($comptes === []): ?>
            <p class="alerte alerte--erreur"><?= e(t('gnuit.aucun_compte_devise', ['devise' => $d['code'] ?? ''])) ?></p>
        <?php else: ?>
            <form class="sal-form" method="post" action="/gestion/nuit/promoteurs/<?= (int) $promoteur['id'] ?>/payer">
                <input type="hidden" name="csrf" value="<?= $jeton ?>">
                <input type="hidden" name="du" value="<?= e($du) ?>">
                <input type="hidden" name="au" value="<?= e($au) ?>">
                <input type="hidden" name="cle" value="<?= e(bin2hex(random_bytes(16))) ?>">
                <label class="champ"><span><?= e(t('nuit.c_compte')) ?></span>
                    <select class="saisie" name="compte"><?php foreach ($comptes as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['nom']) ?> · <?= e($formateur->formater(App\Repositories\GestionTresorerieRepository::solde($c, (int) $promoteur['devise_id']), $d)) ?></option><?php endforeach; ?></select></label>
                <div class="sal-form__actions"><button class="bouton" type="submit"><?= e(t('gnuit.payer')) ?></button></div>
            </form>
            <p class="aide"><?= e(t('gnuit.payer_aide')) ?></p>
        <?php endif; ?>
    </section>
<?php endif; ?>
<section class="bloc" aria-labelledby="nuit-paiements">
    <h2 id="nuit-paiements"><?= e(t('gnuit.paiements')) ?></h2>
    <div class="defile">
        <table class="tableau">
            <thead><tr><th><?= e(t('recu.date')) ?></th><th><?= e(t('gnuit.periode')) ?></th><th class="nombre"><?= e(t('gnuit.montant')) ?></th><th><?= e(t('gnuit.par')) ?></th></tr></thead>
            <tbody>
                <?php foreach ($paiements as $p): ?>
                    <tr><td><?= e(date('d/m/Y H:i', strtotime($p['cree_le']))) ?></td><td><?= e(date('d/m', strtotime($p['du'])) . ' – ' . date('d/m/Y', strtotime($p['au']))) ?></td>
                        <td class="nombre"><?= e($d ? $formateur->formater((float) $p['montant'], $d) : $p['montant']) ?></td><td><?= e($p['par_nom'] ?? '') ?></td></tr>
                <?php endforeach; ?>
                <?php if ($paiements === []): ?><tr><td colspan="4"><?= e(t('gnuit.aucun_paiement')) ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
