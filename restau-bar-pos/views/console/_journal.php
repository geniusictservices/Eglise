<?php
/**
 * Lignes du journal de la console.
 * @var list<array> $journal
 * @var bool $avecClient
 */

use App\Console\Affichage as A;

if ($journal === []): ?>
    <p class="aide"><?= e(t('console.journal.vide')) ?></p>
<?php else: ?>
    <div class="defile"><table class="tableau con-journal">
        <thead><tr><th><?= e(t('console.date')) ?></th><th><?= e(t('console.journal.qui')) ?></th><?php if ($avecClient): ?><th><?= e(t('console.client.client')) ?></th><?php endif; ?><th><?= e(t('console.journal.action')) ?></th><th><?= e(t('console.journal.details')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($journal as $j):
            $details = json_decode((string) $j['details'], true);
            $resume = is_array($details) ? implode(' · ', array_filter(array_map(
                static fn ($k, $v) => is_array($v) ? null : ($v === null || $v === '' || $v === false ? null : $k . ' : ' . (is_bool($v) ? t('console.oui') : $v)),
                array_keys($details), $details
            ))) : '';
            $libelle = t('console.journal.action_' . $j['action']);
            ?>
            <tr data-action="<?= e($j['action']) ?>">
                <td class="montant"><?= e(A::dateHeure($j['cree_le'])) ?></td>
                <td><?= e($j['admin_nom'] ?? '—') ?></td>
                <?php if ($avecClient): ?><td><?= $j['client_id'] ? '<a class="lien" href="/clients/' . (int) $j['client_id'] . '">' . e((string) $j['client_nom']) . '</a>' : '—' ?></td><?php endif; ?>
                <td><?= e($libelle === 'console.journal.action_' . $j['action'] ? $j['action'] : $libelle) ?></td>
                <td class="con-petit"><?= e(mb_substr($resume, 0, 240)) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
<?php endif;
