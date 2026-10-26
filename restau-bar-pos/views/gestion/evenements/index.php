<?php
/**
 * Événements > Soirées : calendrier des soirées à venir (par mois), puis des soirées passées avec leurs recettes
 * et leur résultat.
 * @var ?string $flash
 * @var array $etablissements
 * @var ?int $etablissement
 * @var list<array> $aVenir
 * @var list<array> $passes
 * @var bool $peutCreer
 * @var ?array $locale
 * @var ?array $reference
 * @var float $taux
 */

use App\Controllers\Gestion\RapportAffichage as R;
use App\Core\View;

$parMois = static function (array $liste): array {
    $groupes = [];
    foreach ($liste as $ev) {
        $groupes[substr($ev['debut'], 0, 7)][] = $ev;
    }
    return $groupes;
};
$carte = ['locale' => $locale, 'reference' => $reference, 'taux' => $taux];
?>
<link rel="stylesheet" href="/assets/css/gestion-tresorerie.css">
<link rel="stylesheet" href="/assets/css/gestion-rapports.css">

<div class="tre-entete rap-entete">
    <div>
        <h1><?= e(t('evenements.titre')) ?></h1>
        <p class="aide"><?= e(t('evenements.aide')) ?></p>
    </div>
    <div class="tre-entete__actions">
        <?php if ($peutCreer): ?><a class="bouton" href="/gestion/evenements/nouveau<?= $etablissement ? '?etablissement=' . (int) $etablissement : '' ?>">+ <?= e(t('evenements.nouveau')) ?></a><?php endif; ?>
        <?= View::rendre('gestion/rapports/_exporter', ['titre' => t('evenements.titre')]) ?>
    </div>
</div>

<?php if ($flash): ?><p class="alerte" role="status"><?= e($flash) ?></p><?php endif; ?>

<?php if (count($etablissements) > 1): ?>
    <section class="bloc tre-filtres rap-filtres" aria-label="<?= e(t('gestion.etablissement')) ?>">
        <nav class="tre-puces" aria-label="<?= e(t('gestion.etablissement')) ?>">
            <a class="puce" href="<?= e(urlAvec(['etablissement' => null])) ?>"<?= !$etablissement ? ' aria-current="true"' : '' ?>><?= e(t('gventes.tous_etablissements')) ?></a>
            <?php foreach ($etablissements as $etab): ?>
                <a class="puce" href="<?= e(urlAvec(['etablissement' => (int) $etab['id']])) ?>"<?= $etablissement === (int) $etab['id'] ? ' aria-current="true"' : '' ?>><?= e($etab['nom']) ?></a>
            <?php endforeach; ?>
        </nav>
    </section>
<?php endif; ?>

<section aria-labelledby="evt-a-venir">
    <h2 id="evt-a-venir" class="evt-section"><?= e(t('evenements.a_venir')) ?></h2>
    <?php if ($aVenir === []): ?>
        <p class="bloc aide" data-vide="a-venir"><?= e(t('evenements.aucune_a_venir')) ?></p>
    <?php endif; ?>
    <?php foreach ($parMois($aVenir) as $mois => $liste): ?>
        <div class="evt-mois">
            <h3 class="evt-mois__titre"><?= e(R::mois($mois . '-01')) ?></h3>
            <ul class="evt-liste" data-liste="a-venir">
                <?php foreach ($liste as $ev): ?><?= View::rendre('gestion/evenements/_carte', ['ev' => $ev] + $carte) ?><?php endforeach; ?>
            </ul>
        </div>
    <?php endforeach; ?>
</section>

<section aria-labelledby="evt-passes">
    <h2 id="evt-passes" class="evt-section"><?= e(t('evenements.passees')) ?></h2>
    <?php if ($passes === []): ?>
        <p class="bloc aide" data-vide="passes"><?= e(t('evenements.aucune_passee')) ?></p>
    <?php else: ?>
        <p class="aide"><?= e(t('evenements.passees_aide')) ?> <a class="lien rap-lien" href="/gestion/evenements/rentabilite"><?= e(t('evenements.voir_rentabilite')) ?></a></p>
    <?php endif; ?>
    <?php foreach ($parMois($passes) as $mois => $liste): ?>
        <div class="evt-mois">
            <h3 class="evt-mois__titre"><?= e(R::mois($mois . '-01')) ?></h3>
            <ul class="evt-liste" data-liste="passes">
                <?php foreach ($liste as $ev): ?><?= View::rendre('gestion/evenements/_carte', ['ev' => $ev] + $carte) ?><?php endforeach; ?>
            </ul>
        </div>
    <?php endforeach; ?>
</section>
