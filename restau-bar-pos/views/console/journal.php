<?php
/**
 * Journal de la console et messages envoyés par la console.
 * @var string $vueChoisie
 * @var list<array> $journal
 * @var list<array> $messages
 */

use App\Console\Affichage as A;

?>
<h1><?= e(t('console.journal.titre')) ?></h1>
<p class="aide"><?= e(t('console.journal.aide')) ?></p>
<nav class="con-puces" aria-label="<?= e(t('console.journal.titre')) ?>">
    <a class="puce" href="/journal"<?= $vueChoisie === 'actions' ? ' aria-current="true"' : '' ?>><?= e(t('console.journal.actions')) ?></a>
    <a class="puce" href="/journal?vue=messages"<?= $vueChoisie === 'messages' ? ' aria-current="true"' : '' ?>><?= e(t('console.journal.messages')) ?></a>
</nav>
<section class="bloc">
    <?php if ($vueChoisie === 'actions'): ?>
        <?= App\Core\View::rendre('console/_journal', ['journal' => $journal, 'avecClient' => true]) ?>
    <?php elseif ($messages === []): ?>
        <p class="aide"><?= e(t('console.relances.aucune')) ?></p>
    <?php else: ?>
        <div class="defile"><table class="tableau">
            <thead><tr><th><?= e(t('console.date')) ?></th><th><?= e(t('console.client.client')) ?></th><th><?= e(t('console.relances.etape')) ?></th><th><?= e(t('console.relances.canal')) ?></th><th><?= e(t('console.relances.statut')) ?></th></tr></thead>
            <tbody><?php foreach ($messages as $m): ?>
                <tr><td class="montant"><?= e(A::dateHeure($m['cree_le'])) ?></td>
                    <td><?= $m['client_id'] ? '<a class="lien" href="/clients/' . (int) $m['client_id'] . '">' . e((string) $m['client_nom']) . '</a>' : e(t('console.relances.essai')) ?></td>
                    <td><?= e((string) ($m['etape'] ?? '—')) ?></td>
                    <td><?= e(t('console.canal.' . $m['canal'])) ?><br><span class="aide con-petit"><?= e($m['destinataire']) ?></span></td>
                    <td><span class="badge badge--<?= $m['statut'] === 'envoye' ? 'succes' : ($m['statut'] === 'echec' ? 'erreur' : 'neutre') ?>"><?= e(t('console.message.' . $m['statut'])) ?></span><?= $m['erreur'] ? '<br><span class="aide con-petit">' . e($m['erreur']) . '</span>' : '' ?></td></tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</section>
