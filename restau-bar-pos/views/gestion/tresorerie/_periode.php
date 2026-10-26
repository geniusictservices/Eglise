<?php
/**
 * Choix de la période : raccourcis (puces) et dates « du » / « au ».
 * Les autres paramètres de l'adresse (filtres) sont gardés.
 * @var array $periode cle, du, au
 * @var string $action adresse du formulaire
 * @var array $garder paramètres à garder dans le formulaire des dates (nom => valeur)
 * @var bool $avecDates false : seulement les raccourcis (la page a son propre formulaire de filtres)
 */
$garder = array_filter($garder ?? [], static fn ($v) => $v !== null && $v !== '');
?>
<div class="tre-periode">
    <nav class="tre-puces" aria-label="<?= e(t('tresorerie.periode')) ?>">
        <?php foreach (App\Controllers\Gestion\TresorerieSaisie::PERIODES as $cle): ?>
            <a class="puce" href="<?= e(urlAvec(['periode' => $cle, 'du' => null, 'au' => null])) ?>"<?= $periode['cle'] === $cle ? ' aria-current="true"' : '' ?>><?= e(t('tresorerie.periode_' . $cle)) ?></a>
        <?php endforeach; ?>
    </nav>
    <?php if ($avecDates ?? true): ?>
    <form class="tre-periode__dates" method="get" action="<?= e($action) ?>">
        <?php foreach ($garder as $nom => $valeur): ?>
            <input type="hidden" name="<?= e($nom) ?>" value="<?= e((string) $valeur) ?>">
        <?php endforeach; ?>
        <div class="champ">
            <label for="periode-du"><?= e(t('tresorerie.du')) ?></label>
            <input type="date" id="periode-du" name="du" value="<?= e((string) $periode['du']) ?>">
        </div>
        <div class="champ">
            <label for="periode-au"><?= e(t('tresorerie.au')) ?></label>
            <input type="date" id="periode-au" name="au" value="<?= e((string) $periode['au']) ?>">
        </div>
        <button class="bouton bouton--secondaire" type="submit"><?= e(t('tresorerie.afficher')) ?></button>
    </form>
    <?php endif; ?>
</div>
