<?php
/**
 * Catégories de dépenses.
 * @var ?string $flash
 * @var array $categories
 * @var array $nouvelle
 * @var array<string, string> $erreursNouvelle
 * @var array<int, array> $saisies saisies refusées par catégorie
 * @var array<int, array<string, string>> $erreursLignes
 * @var string[] $erreurs
 */

use App\Core\View;

$jeton = e(App\Core\Session::jetonCsrf());
$toutesErreurs = array_merge($erreurs, array_values($erreursNouvelle), ...array_map('array_values', array_values($erreursLignes)));
?>
<link rel="stylesheet" href="/assets/css/gestion-tresorerie.css">

<p class="tre-retour"><a class="lien" href="/gestion/depenses">← <?= e(t('depenses.titre')) ?></a></p>
<h1><?= e(t('categories_depenses.titre')) ?></h1>
<p class="aide"><?= e(t('categories_depenses.aide')) ?></p>

<?php if ($flash): ?>
    <p class="alerte" role="status"><?= e($flash) ?></p>
<?php endif; ?>
<?= View::rendre('gestion/tresorerie/_erreurs', ['erreurs' => $toutesErreurs]) ?>

<section class="bloc" aria-labelledby="tre-nouvelle-categorie">
    <h2 id="tre-nouvelle-categorie"><?= e(t('categories_depenses.ajouter')) ?></h2>
    <form method="post" action="/gestion/depenses/categories" class="tre-categorie-form" novalidate>
        <input type="hidden" name="csrf" value="<?= $jeton ?>">
        <div class="champ">
            <label for="nouvelle-nom-fr"><?= e(t('categories_depenses.champ_nom_fr')) ?></label>
            <input id="nouvelle-nom-fr" name="nom_fr" maxlength="128" lang="fr" value="<?= e($nouvelle['nom_fr']) ?>" placeholder="Publicité"
                   <?= isset($erreursNouvelle['nom_fr']) ? 'aria-invalid="true"' : '' ?>>
        </div>
        <div class="champ">
            <label for="nouvelle-nom-en"><?= e(t('categories_depenses.champ_nom_en')) ?></label>
            <input id="nouvelle-nom-en" name="nom_en" maxlength="128" lang="en" value="<?= e($nouvelle['nom_en']) ?>" placeholder="Advertising"
                   <?= isset($erreursNouvelle['nom_en']) ? 'aria-invalid="true"' : '' ?>>
        </div>
        <div class="champ tre-champ-position">
            <label for="nouvelle-position"><?= e(t('tresorerie.champ_position')) ?></label>
            <input id="nouvelle-position" name="position" inputmode="numeric" maxlength="3" value="<?= e((string) $nouvelle['position']) ?>"
                   <?= isset($erreursNouvelle['position']) ? 'aria-invalid="true"' : '' ?>>
        </div>
        <button class="bouton" type="submit">+ <?= e(t('categories_depenses.ajouter_bouton')) ?></button>
    </form>
</section>

<section class="bloc" aria-labelledby="tre-liste-categories">
    <h2 id="tre-liste-categories"><?= e(t('categories_depenses.liste')) ?></h2>
    <ul class="tre-categories-liste">
        <?php foreach ($categories as $categorie):
            $id = (int) $categorie['id'];
            $valeurs = $saisies[$id] ?? $categorie;
            $erreursLigne = $erreursLignes[$id] ?? [];
            $nb = (int) $categorie['nb_depenses'];
            ?>
            <li class="tre-categorie<?= $categorie['active'] ? '' : ' tre-categorie--inactive' ?>" id="categorie-<?= $id ?>">
                <form method="post" action="/gestion/depenses/categories/<?= $id ?>" class="tre-categorie-form" novalidate>
                    <input type="hidden" name="csrf" value="<?= $jeton ?>">
                    <div class="champ">
                        <label for="cat-<?= $id ?>-fr"><?= e(t('categories_depenses.champ_nom_fr')) ?></label>
                        <input id="cat-<?= $id ?>-fr" name="nom_fr" maxlength="128" lang="fr" value="<?= e($valeurs['nom_fr']) ?>"
                               <?= isset($erreursLigne['nom_fr']) ? 'aria-invalid="true"' : '' ?>>
                    </div>
                    <div class="champ">
                        <label for="cat-<?= $id ?>-en"><?= e(t('categories_depenses.champ_nom_en')) ?></label>
                        <input id="cat-<?= $id ?>-en" name="nom_en" maxlength="128" lang="en" value="<?= e($valeurs['nom_en']) ?>"
                               <?= isset($erreursLigne['nom_en']) ? 'aria-invalid="true"' : '' ?>>
                    </div>
                    <div class="champ tre-champ-position">
                        <label for="cat-<?= $id ?>-position"><?= e(t('tresorerie.champ_position')) ?></label>
                        <input id="cat-<?= $id ?>-position" name="position" inputmode="numeric" maxlength="3" value="<?= e((string) $valeurs['position']) ?>"
                               <?= isset($erreursLigne['position']) ? 'aria-invalid="true"' : '' ?>>
                    </div>
                    <button class="bouton bouton--secondaire bouton--petit" type="submit"
                            aria-label="<?= e(t('categories_depenses.enregistrer_nom', ['nom' => champ($categorie, 'nom')])) ?>"><?= e(t('tresorerie.enregistrer')) ?></button>
                </form>
                <div class="tre-categorie__pied">
                    <span class="tre-sous-texte">
                        <?= e($nb === 0 ? t('categories_depenses.aucune_depense') : ($nb === 1 ? t('depenses.une_depense') : t('depenses.n_depenses', ['n' => $nb]))) ?>
                        <?php if (!$categorie['active']): ?> · <span class="badge badge--neutre"><?= e(t('tresorerie.desactivee')) ?></span><?php endif; ?>
                    </span>
                    <form method="post" action="/gestion/depenses/categories/<?= $id ?>/<?= $categorie['active'] ? 'desactiver' : 'activer' ?>">
                        <input type="hidden" name="csrf" value="<?= $jeton ?>">
                        <button class="lien" type="submit"><?= e($categorie['active'] ? t('tresorerie.desactiver') : t('tresorerie.activer')) ?></button>
                    </form>
                    <?php if ($nb === 0): ?>
                        <form method="post" action="/gestion/depenses/categories/<?= $id ?>/supprimer">
                            <input type="hidden" name="csrf" value="<?= $jeton ?>">
                            <button class="lien tre-lien-danger" type="submit"
                                    aria-label="<?= e(t('categories_depenses.supprimer_nom', ['nom' => champ($categorie, 'nom')])) ?>"><?= e(t('categories_depenses.supprimer')) ?></button>
                        </form>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
