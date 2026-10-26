<?php
/**
 * Clients : liste avec le statut de l'abonnement, filtres par statut, instances non rattachées.
 * @var list<array> $clients
 * @var ?string $filtre
 * @var array<string, int> $nombres
 * @var int $total
 * @var list<string> $nonRattachees
 */

use App\Console\Abonnement;
use App\Console\Affichage as A;
use App\Console\SessionConsole;

?>
<div class="con-entete">
    <div>
        <h1><?= e(t('console.clients.titre')) ?></h1>
        <p class="aide"><?= e(t('console.clients.aide')) ?></p>
    </div>
    <div class="con-boutons">
        <a class="bouton" href="/clients/nouveau"><?= e(t('console.client.nouveau_titre')) ?></a>
        <form method="post" action="/clients/mettre-a-jour">
            <input type="hidden" name="csrf" value="<?= e(SessionConsole::jetonCsrf()) ?>">
            <button class="bouton bouton--secondaire" type="submit"><?= e(t('console.instance.tout_mettre_a_jour')) ?></button>
        </form>
    </div>
</div>

<nav class="con-puces" aria-label="<?= e(t('console.clients.par_statut')) ?>">
    <a class="puce" href="/clients"<?= $filtre === null ? ' aria-current="true"' : '' ?>><?= e(t('console.clients.tous')) ?> <span class="montant"><?= $total ?></span></a>
    <?php foreach ($nombres as $code => $n): if ($n === 0 && $filtre !== $code) { continue; } ?>
        <a class="puce" href="/clients?statut=<?= e($code) ?>"<?= $filtre === $code ? ' aria-current="true"' : '' ?>><?= e(t('console.statut.' . $code)) ?> <span class="montant"><?= $n ?></span></a>
    <?php endforeach; ?>
</nav>

<section class="bloc" aria-label="<?= e(t('console.clients.titre')) ?>">
    <?php if ($clients === []): ?>
        <p class="aide"><?= e(t($total === 0 ? 'console.clients.aucun' : 'console.clients.aucun_filtre')) ?></p>
    <?php else: ?>
        <div class="defile">
            <table class="tableau con-clients">
                <thead><tr>
                    <th><?= e(t('console.client.client')) ?></th><th><?= e(t('console.client.statut')) ?></th>
                    <th><?= e(t('console.client.formule')) ?></th><th><?= e(t('console.client.echeance')) ?></th>
                    <th><?= e(t('console.client.contact')) ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($clients as $c): ?>
                    <tr data-client="<?= e($c['sous_domaine']) ?>">
                        <td><a class="lien con-lien-fort" href="/clients/<?= (int) $c['id'] ?>"><?= e($c['nom']) ?></a><br><span class="aide con-petit"><?= e($c['sous_domaine']) ?></span></td>
                        <td><?= A::badge($c) ?></td>
                        <td><?= e($c['formule_nom'] ?? '—') ?><br><span class="aide con-petit"><?= e(A::montant(Abonnement::prix($c, $c['periode']))) ?> / <?= e(t('console.periode.' . $c['periode'])) ?></span></td>
                        <td class="montant"><?= e(A::date($c['echeance'])) ?><br><span class="aide con-petit"><?= e(A::relatif($c['statut']['jours'])) ?></span></td>
                        <td><?= e($c['contact_nom'] ?? '') ?><br><span class="aide con-petit"><?= e($c['contact_telephone'] ? afficherTelephone($c['contact_telephone']) : ($c['contact_email'] ?? '')) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php if ($nonRattachees !== []): ?>
    <section class="bloc" aria-labelledby="con-non-rattachees">
        <h2 id="con-non-rattachees"><?= e(t('console.clients.non_rattachees')) ?></h2>
        <p class="aide"><?= e(t('console.clients.non_rattachees_aide')) ?></p>
        <ul class="con-liste">
            <?php foreach ($nonRattachees as $sd): ?>
                <li><code><?= e($sd) ?></code> <a class="bouton bouton--petit bouton--secondaire" href="/clients/nouveau?instance=<?= e(rawurlencode($sd)) ?>"><?= e(t('console.clients.rattacher')) ?></a></li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>
