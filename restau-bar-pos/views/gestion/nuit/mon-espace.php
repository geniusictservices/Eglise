<?php
/**
 * Nuit > Mon espace (promoteur) : ses invités pour chaque soirée à venir (il en ajoute), ses commissions.
 * Il ne voit que ses propres invités et commissions.
 * @var ?array $promoteur @var list<array> $evenements @var ?array $evenement @var list<array> $invites @var list<array> $tarifs
 * @var array $periode @var string $du @var string $au @var array $releve @var array<int, array> $devises @var ?string $flash
 */
use App\Core\View;
?>
<link rel="stylesheet" href="/assets/css/gestion-salle.css">
<link rel="stylesheet" href="/assets/css/gestion-tresorerie.css">
<link rel="stylesheet" href="/assets/css/gestion-nuit.css">
<div class="sal-entete">
    <div>
        <h1><?= e(t('gnuit.mon_espace_titre')) ?></h1>
        <p class="aide"><?= e(t('gnuit.mon_espace_aide')) ?></p>
    </div>
</div>
<?php if ($flash ?? null): ?><p class="alerte" role="status"><?= e($flash) ?></p><?php endif; ?>
<?php if ($promoteur === null): ?>
    <p class="alerte"><?= e(t('gnuit.pas_promoteur')) ?></p>
    <?php return; ?>
<?php endif; ?>
<?php if ($evenements !== []): ?>
    <nav class="sal-puces" aria-label="<?= e(t('gnuit.soiree')) ?>">
        <?php foreach ($evenements as $ev): ?>
            <a class="puce" href="?evenement=<?= (int) $ev['id'] ?>"<?= $evenement && (int) $ev['id'] === (int) $evenement['id'] ? ' aria-current="true"' : '' ?>><?= e($ev['titre']) ?> · <?= e(date('d/m', strtotime($ev['debut']))) ?></a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>
<?php if ($evenement): ?>
    <section class="bloc" aria-labelledby="mes-invites">
        <h2 id="mes-invites"><?= e(t('gnuit.mes_invites', ['soiree' => $evenement['titre']])) ?></h2>
        <div class="defile">
            <table class="tableau">
                <thead><tr><th><?= e(t('gnuit.nom')) ?></th><th><?= e(t('gnuit.entree')) ?></th><th class="nombre"><?= e(t('gnuit.entres')) ?></th></tr></thead>
                <tbody>
                    <?php foreach ($invites as $i): ?>
                        <tr><td><strong><?= e($i['nom']) ?></strong><?= (int) $i['accompagnants'] > 0 ? ' +' . (int) $i['accompagnants'] : '' ?></td>
                            <td><?= e($i['entree'] === 'gratuite' ? t('nuit.c_invite_gratuite') : t('nuit.c_invite_reduite')) ?></td>
                            <td class="nombre"><?= (int) $i['arrives'] ?> / <?= 1 + (int) $i['accompagnants'] ?></td></tr>
                    <?php endforeach; ?>
                    <?php if ($invites === []): ?><tr><td colspan="3"><?= e(t('nuit.c_invite_aucun')) ?></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($evenement['fin'] >= date('Y-m-d H:i:s')): ?>
            <h3><?= e(t('gnuit.invite_ajouter')) ?></h3>
            <?= View::rendre('gestion/nuit/_invite-form', ['action' => '/gestion/nuit/mon-espace/invite', 'evenement' => $evenement, 'invite' => null, 'promoteurs' => [], 'tarifs' => $tarifs]) ?>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?= View::rendre('gestion/tresorerie/_periode', ['periode' => $periode, 'action' => '/gestion/nuit/mon-espace', 'garder' => ['evenement' => $evenement['id'] ?? null]]) ?>
<section class="bloc" aria-labelledby="mes-commissions">
    <h2 id="mes-commissions"><?= e(t('gnuit.mes_commissions')) ?></h2>
    <?= View::rendre('gestion/nuit/_releve', ['releve' => $releve, 'promoteur' => $promoteur, 'devises' => $devises]) ?>
</section>
