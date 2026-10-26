<?php
/**
 * Ventes > Sessions de caisse : liste (période, établissement, statut) avec les écarts par devise.
 * @var ?string $flash
 * @var array $periode
 * @var list<string> $periodes
 * @var ?string $statut
 * @var ?int $etablissement
 * @var list<array> $etablissements
 * @var list<array> $sessions
 * @var array<int, array<int, float>> $ecarts
 * @var array<int, array> $devises
 * @var bool $actives au moins un établissement demande des sessions
 */

use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;

$signe = static fn (float $m, int $d): string => ($m > 0 ? '+' : ($m < 0 ? '−' : '')) . (isset($devises[$d]) ? A::montant(abs($m), $devises[$d]) : (string) abs($m));
?>
<link rel="stylesheet" href="/assets/css/gestion-tresorerie.css">
<link rel="stylesheet" href="/assets/css/gestion-ventes.css">

<div class="tre-entete">
    <div>
        <h1><?= e(t('gsession.titre')) ?></h1>
        <p class="aide"><?= e(t('gsession.aide')) ?></p>
    </div>
</div>
<?php if ($flash): ?><p class="alerte" role="status"><?= e($flash) ?></p><?php endif; ?>
<?php if (!$actives): ?><p class="bloc aide"><?= e(t('gsession.non_reglees')) ?></p><?php endif; ?>

<section class="bloc tre-filtres ven-filtres" aria-label="<?= e(t('gventes.filtres')) ?>">
    <?= View::rendre('gestion/tresorerie/_periode', ['periode' => $periode, 'periodes' => $periodes, 'action' => '/gestion/sessions-caisse',
        'garder' => ['statut' => $statut, 'etablissement' => $etablissement]]) ?>
    <nav class="tre-puces" aria-label="<?= e(t('gsession.titre')) ?>">
        <?php foreach ([null => 'gsession.toutes', 'ouverte' => 'gsession.ouvertes', 'fermee' => 'gsession.fermees'] as $cle => $libelle): ?>
            <a class="puce" href="<?= e(urlAvec(['statut' => $cle ?: null])) ?>"<?= $statut === ($cle ?: null) ? ' aria-current="true"' : '' ?>><?= e(t($libelle)) ?></a>
        <?php endforeach; ?>
        <?php if (count($etablissements) > 1): ?>
            <?php foreach ($etablissements as $etab): ?>
                <a class="puce" href="<?= e(urlAvec(['etablissement' => $etablissement === (int) $etab['id'] ? null : (int) $etab['id']])) ?>"<?= $etablissement === (int) $etab['id'] ? ' aria-current="true"' : '' ?>><?= e($etab['nom']) ?></a>
            <?php endforeach; ?>
        <?php endif; ?>
    </nav>
</section>

<?php if ($sessions === []): ?>
    <p class="bloc aide"><?= e(t('gsession.aucune')) ?></p>
<?php else: ?>
    <ul class="ven-liste">
        <?php foreach ($sessions as $s): ?>
            <?php $e = $ecarts[(int) $s['id']] ?? []; $manquant = array_filter($e, static fn (float $m) => $m < 0) !== []; ?>
            <li>
                <a class="ven-ligne" href="/gestion/sessions-caisse/<?= (int) $s['id'] ?>">
                    <span class="ven-ligne__numero">
                        <?= e($s['utilisateur_nom']) ?>
                        <span class="badge <?= $s['statut'] === 'ouverte' ? 'badge--lave' : 'badge--neutre' ?>"><?= e(t($s['statut'] === 'ouverte' ? 'gsession.ouverte' : 'gsession.fermee')) ?></span>
                    </span>
                    <span class="ven-ligne__info"><?= e($s['etablissement_nom']) ?> · <?= e(date('d/m/Y H:i', strtotime($s['ouverte_le']))) ?><?= $s['fermee_le'] ? ' → ' . e(date('H:i', strtotime($s['fermee_le']))) : '' ?> · <?= e(t('gsession.tickets', ['n' => (int) $s['tickets']])) ?></span>
                    <span class="ven-ligne__montant<?= $manquant ? ' tre-negatif' : '' ?>">
                        <?php if ($s['statut'] === 'fermee'): ?>
                            <?= $e === [] || array_filter($e, static fn (float $m) => abs($m) >= 0.001) === [] ? e(t('gsession.sans_ecart')) : e(implode(' · ', array_map($signe, $e, array_keys($e)))) ?>
                        <?php endif; ?>
                    </span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
