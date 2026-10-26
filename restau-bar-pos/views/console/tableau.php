<?php
/**
 * Tableau de bord de la console.
 * @var array $stats
 * @var ?string $dernierPassage
 */

use App\Console\Affichage as A;

$retard = count(array_filter($stats['retard'], static fn ($c) => in_array($c['statut']['code'], ['grace', 'lecture_seule', 'suspendu'], true)));
?>
<div class="con-entete">
    <div>
        <h1><?= e(t('console.tableau.titre')) ?></h1>
        <p class="aide"><?= e(t('console.tableau.aide')) ?></p>
    </div>
    <a class="bouton" href="/clients/nouveau"><?= e(t('console.client.nouveau_titre')) ?></a>
</div>

<section class="chiffres con-chiffres" aria-label="<?= e(t('console.tableau.chiffres')) ?>">
    <div class="chiffre">
        <span class="chiffre__valeur" data-chiffre="clients"><?= (int) $stats['clients'] ?></span>
        <span class="chiffre__libelle"><?= e(t('console.tableau.clients', ['n' => $stats['a_installer']])) ?></span>
    </div>
    <div class="chiffre">
        <span class="chiffre__valeur montant" data-chiffre="mrr"><?= e(A::montant($stats['mrr'])) ?></span>
        <span class="chiffre__libelle"><?= e(t('console.tableau.mrr')) ?></span>
    </div>
    <div class="chiffre">
        <span class="chiffre__valeur montant" data-chiffre="encaisse">
            <?php if ($stats['encaisse'] === []): ?>—<?php else: ?>
                <?= implode('<br>', array_map(static fn ($l) => e(A::montant($l['total'], $l['devise'])), $stats['encaisse'])) ?>
            <?php endif; ?>
        </span>
        <span class="chiffre__libelle"><?= e(t('console.tableau.encaisse')) ?></span>
    </div>
    <div class="chiffre">
        <span class="chiffre__valeur" data-chiffre="retard"><?= $retard ?></span>
        <span class="chiffre__libelle"><?= e(t('console.tableau.retard')) ?></span>
    </div>
    <div class="chiffre">
        <span class="chiffre__valeur" data-chiffre="essais"><?= count($stats['essais']) ?></span>
        <span class="chiffre__libelle"><?= e(t('console.tableau.essais')) ?></span>
    </div>
</section>

<nav class="con-puces" aria-label="<?= e(t('console.clients.par_statut')) ?>">
    <?php foreach ($stats['par_statut'] as $code => $n): ?>
        <a class="puce" href="/clients?statut=<?= e($code) ?>"><span class="badge badge--<?= e(A::TONS[$code]) ?>"><?= e(t('console.statut.' . $code)) ?></span> <span class="montant"><?= (int) $n ?></span></a>
    <?php endforeach; ?>
</nav>

<section class="bloc" aria-labelledby="con-suivre">
    <h2 id="con-suivre"><?= e(t('console.tableau.a_suivre')) ?></h2>
    <?php if ($stats['retard'] === [] && $stats['essais'] === []): ?>
        <p class="aide"><?= e(t('console.tableau.rien')) ?></p>
    <?php else: ?>
        <div class="defile">
            <table class="tableau">
                <thead><tr><th><?= e(t('console.client.client')) ?></th><th><?= e(t('console.client.statut')) ?></th><th><?= e(t('console.client.echeance')) ?></th><th><?= e(t('console.client.formule')) ?></th><th class="nombre"><?= e(t('console.client.prix')) ?></th></tr></thead>
                <tbody>
                <?php foreach (array_merge($stats['retard'], $stats['essais']) as $c): ?>
                    <tr>
                        <td><a class="lien" href="/clients/<?= (int) $c['id'] ?>"><?= e($c['nom']) ?></a><br><span class="aide con-petit"><?= e($c['contact_telephone'] ?? $c['contact_email'] ?? '') ?></span></td>
                        <td><?= A::badge($c) ?></td>
                        <td class="montant"><?= e(A::date($c['echeance'])) ?><br><span class="aide con-petit"><?= e(A::relatif($c['statut']['jours'])) ?></span></td>
                        <td><?= e($c['formule_nom'] ?? '—') ?></td>
                        <td class="nombre"><?= e(A::montant(App\Console\Abonnement::prix($c, $c['periode']))) ?> <span class="aide con-petit">/ <?= e(t('console.periode.' . $c['periode'])) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <p class="aide con-petit"><?= e($dernierPassage ? t('console.tableau.dernier_passage', ['date' => A::dateHeure($dernierPassage)]) : t('console.tableau.jamais_passe')) ?></p>
</section>
