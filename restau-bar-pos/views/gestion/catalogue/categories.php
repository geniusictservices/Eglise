<?php
/**
 * @var ?string $flash
 * @var array $categories
 * @var array $nouvelle
 * @var array $saisies
 * @var array $erreurs
 */
$jeton = e(App\Core\Session::jetonCsrf());
// Arbre : catégories principales, et sous-catégories de chacune (deux niveaux au plus)
$racines = array_values(array_filter($categories, static fn (array $c) => $c['niveau'] === 0));
$enfants = [];
foreach ($categories as $categorie) {
    if ($categorie['niveau'] === 1) {
        $enfants[(int) $categorie['parente']['id']][] = $categorie;
    }
}
?>
<link rel="stylesheet" href="/assets/css/gestion-catalogue.css">

<p class="cat-retour"><a class="lien" href="/gestion/catalogue">← <?= e(t('catalogue.titre')) ?></a></p>
<h1><?= e(t('categories.titre')) ?></h1>
<p class="aide"><?= e(t('categories.aide')) ?></p>

<?php if ($flash): ?>
    <p class="alerte cat-annonce" role="status"><?= e($flash) ?></p>
<?php endif; ?>

<?php if ($categories !== []): ?>
    <ol class="cat-categories">
        <?php foreach ($racines as $i => $categorie): $cid = (int) $categorie['id']; $sous = $enfants[$cid] ?? []; ?>
            <li class="cat-categorie" id="categorie-<?= $cid ?>">
                <?= App\Core\View::rendre('gestion/catalogue/_categorie-ligne', [
                    'categorie' => $categorie, 'premiere' => $i === 0, 'derniere' => $i === count($racines) - 1,
                    'saisies' => $saisies, 'erreurs' => $erreurs, 'parentes' => $racines, 'jeton' => $jeton,
                ]) ?>
                <?php if ($sous !== []): ?>
                    <ol class="cat-categories cat-categories--sous" aria-label="<?= e(t('categories.sous_categories_de', ['nom' => champ($categorie, 'nom')])) ?>">
                        <?php foreach ($sous as $j => $enfant): ?>
                            <li class="cat-categorie cat-categorie--sous" id="categorie-<?= (int) $enfant['id'] ?>">
                                <?= App\Core\View::rendre('gestion/catalogue/_categorie-ligne', [
                                    'categorie' => $enfant, 'premiere' => $j === 0, 'derniere' => $j === count($sous) - 1,
                                    'saisies' => $saisies, 'erreurs' => $erreurs, 'parentes' => $racines, 'jeton' => $jeton,
                                ]) ?>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
<?php endif; ?>

<section class="bloc" id="nouvelle">
    <h2><?= e(t('categories.ajouter')) ?></h2>
    <?= App\Core\View::rendre('gestion/catalogue/_erreurs', ['erreurs' => $erreurs['nouvelle'] ?? []]) ?>
    <form class="formulaire cat-formulaire" method="post" action="/gestion/catalogue/categories#nouvelle" data-suivi>
        <input type="hidden" name="csrf" value="<?= $jeton ?>">
        <?= App\Core\View::rendre('gestion/catalogue/_categorie', ['prefixe' => 'nouvelle', 'valeurs' => $nouvelle, 'parentes' => $racines]) ?>
        <div class="cat-actions">
            <button class="bouton" type="submit">+ <?= e(t('categories.ajouter')) ?></button>
            <span class="cat-modifie" hidden><?= e(t('catalogue.non_enregistre')) ?></span>
        </div>
    </form>
</section>

<script src="/assets/js/gestion-catalogue.js" defer></script>
