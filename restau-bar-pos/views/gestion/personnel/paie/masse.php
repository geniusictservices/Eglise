<?php
/**
 * Personnel > Paie > Masse salariale : par établissement et par mois (bulletins validés ou payés), et versé.
 * @var array $etablissements
 * @var ?array $etab
 * @var int $annee
 * @var array $masse
 */

use App\Controllers\Gestion\PersonnelAffichage as P;
use App\Core\View;
?>
<?= View::rendre('gestion/personnel/_onglets-paie', ['actif' => 'masse']) ?>
<h1><?= e(t('pers.masse_titre')) ?></h1>
<p class="aide"><?= e(t('pers.masse_aide')) ?></p>

<section class="bloc per-filtres">
    <?= View::rendre('gestion/personnel/_etablissements', ['etablissements' => $etablissements, 'etab' => $etab, 'tous' => true]) ?>
    <nav class="per-jours" aria-label="<?= e(t('pers.annee')) ?>">
        <a class="bouton bouton--secondaire bouton--petit" href="<?= e(urlAvec(['annee' => $annee - 1])) ?>" rel="prev">← <?= $annee - 1 ?></a>
        <strong class="per-jours__titre"><?= $annee ?></strong>
        <a class="bouton bouton--secondaire bouton--petit" href="<?= e(urlAvec(['annee' => $annee + 1])) ?>" rel="next"><?= $annee + 1 ?> →</a>
    </nav>
</section>

<?= View::rendre('gestion/personnel/paie/_masse', ['masse' => $masse]) ?>
