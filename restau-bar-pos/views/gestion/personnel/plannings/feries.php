<?php
/**
 * Personnel > Plannings > Jours fériés : liste de la RDC (modifiable), prochains jours fériés.
 * @var ?string $flash
 * @var list<array> $feries
 * @var array<string, string> $prochains
 * @var bool $peutGerer
 * @var array<string, string> $erreurs
 * @var ?array $saisie
 */

use App\Controllers\Gestion\PersonnelAffichage as P;
use App\Core\View;

$jeton = e(App\Core\Session::jetonCsrf());
$invalide = static fn (string $champ): string => isset($erreurs[$champ]) ? ' aria-invalid="true" aria-describedby="erreur-' . $champ . '"' : '';
$erreur = static fn (string $champ): string => isset($erreurs[$champ]) ? '<p class="champ__erreur" id="erreur-' . $champ . '">' . e($erreurs[$champ]) . '</p>' : '';
?>
<?= View::rendre('gestion/personnel/_onglets-plannings', ['actif' => 'feries']) ?>
<h1><?= e(t('pers.feries_titre')) ?></h1>
<p class="aide"><?= e(t('pers.feries_aide')) ?></p>
<?php if ($flash): ?><p class="alerte" role="status"><?= e($flash) ?></p><?php endif; ?>
<?= View::rendre('gestion/tresorerie/_erreurs', ['erreurs' => array_values($erreurs)]) ?>

<div class="det-colonnes">
    <section class="bloc" aria-labelledby="per-feries">
        <h2 id="per-feries"><?= e(t('pers.feries_liste')) ?></h2>
        <ul class="det-lignes">
            <?php foreach ($feries as $f): ?>
                <li<?= $f['actif'] ? '' : ' class="det-ligne--close"' ?>>
                    <span><strong><?= e(sprintf('%02d/%02d', $f['jour'], $f['mois']) . ($f['annee'] ? '/' . $f['annee'] : '')) ?></strong> · <?= e(champ($f, 'nom')) ?>
                        <?= $f['annee'] ? '' : '<span class="badge badge--neutre">' . e(t('pers.chaque_annee')) . '</span>' ?><?= $f['actif'] ? '' : ' <span class="badge badge--neutre">' . e(t('pers.desactive')) . '</span>' ?></span>
                    <?php if ($peutGerer): ?>
                        <form method="post" action="/gestion/personnel/plannings/feries/<?= (int) $f['id'] ?>" class="per-boutons">
                            <input type="hidden" name="csrf" value="<?= $jeton ?>">
                            <button class="lien" type="submit" name="action" value="<?= $f['actif'] ? 'desactiver' : 'activer' ?>"><?= e(t($f['actif'] ? 'pers.desactiver' : 'pers.reactiver')) ?></button>
                            <button class="lien" type="submit" name="action" value="supprimer"><?= e(t('pers.supprimer')) ?></button>
                        </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <div>
        <section class="bloc" aria-labelledby="per-prochains">
            <h2 id="per-prochains"><?= e(t('pers.prochains_feries')) ?></h2>
            <ul class="det-lignes">
                <?php foreach (array_slice($prochains, 0, 8, true) as $date => $nom): ?><li><span><?= e(P::jourCourt($date)) ?> <?= e(substr($date, 0, 4)) ?></span><span><?= e($nom) ?></span></li><?php endforeach; ?>
            </ul>
        </section>
        <?php if ($peutGerer): ?>
            <section class="bloc" aria-labelledby="per-ajouter-ferie">
                <h2 id="per-ajouter-ferie"><?= e(t('pers.ajouter_ferie')) ?></h2>
                <form method="post" action="/gestion/personnel/plannings/feries" class="tre-formulaire" novalidate>
                    <input type="hidden" name="csrf" value="<?= $jeton ?>">
                    <div class="champ">
                        <label for="date"><?= e(t('pers.champ_date')) ?></label>
                        <input id="date" name="date" type="date" value="<?= e((string) ($saisie['date'] ?? '')) ?>"<?= $invalide('date') ?>>
                        <?= $erreur('date') ?>
                    </div>
                    <label class="coche"><input type="checkbox" name="chaque_annee" value="1" <?= !empty($saisie['chaque_annee']) ? 'checked' : '' ?>> <?= e(t('pers.chaque_annee')) ?></label>
                    <div class="champ">
                        <label for="nom_fr"><?= e(t('pers.champ_nom_fr')) ?></label>
                        <input id="nom_fr" name="nom_fr" maxlength="128" value="<?= e((string) ($saisie['nom_fr'] ?? '')) ?>"<?= $invalide('nom_fr') ?>>
                        <?= $erreur('nom_fr') ?>
                    </div>
                    <div class="champ">
                        <label for="nom_en"><?= e(t('pers.champ_nom_en')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label>
                        <input id="nom_en" name="nom_en" maxlength="128" value="<?= e((string) ($saisie['nom_en'] ?? '')) ?>">
                    </div>
                    <div class="tre-actions"><button class="bouton" type="submit"><?= e(t('pers.ajouter')) ?></button></div>
                </form>
            </section>
        <?php endif; ?>
    </div>
</div>
