<?php
/**
 * Une catégorie de la liste en arbre : ordre dans son niveau, nom, nombre de produits,
 * modification (nom, parente, affichage) et suppression.
 * @var array $categorie ligne de GestionCatalogueRepository::categories()
 * @var bool $premiere première de son niveau
 * @var bool $derniere dernière de son niveau
 * @var array $saisies
 * @var array $erreurs
 * @var array $parentes
 * @var string $jeton
 */
$cid = (int) $categorie['id'];
$valeurs = $saisies[$cid] ?? $categorie;
$nom = champ($categorie, 'nom');
$enErreur = !empty($erreurs['categorie-' . $cid]);
$nbProduits = (int) $categorie['nb_produits'];
$nbSous = (int) $categorie['nb_sous'];
?>
<div class="cat-categorie__ligne">
    <div class="cat-ordre">
        <form method="post" action="/gestion/catalogue/categories/<?= $cid ?>/monter">
            <input type="hidden" name="csrf" value="<?= $jeton ?>">
            <button class="cat-ordre__bouton" type="submit" <?= $premiere ? 'disabled' : '' ?> aria-label="<?= e(t('catalogue.monter', ['nom' => $nom])) ?>" title="<?= e(t('catalogue.monter', ['nom' => $nom])) ?>">↑</button>
        </form>
        <form method="post" action="/gestion/catalogue/categories/<?= $cid ?>/descendre">
            <input type="hidden" name="csrf" value="<?= $jeton ?>">
            <button class="cat-ordre__bouton" type="submit" <?= $derniere ? 'disabled' : '' ?> aria-label="<?= e(t('catalogue.descendre', ['nom' => $nom])) ?>" title="<?= e(t('catalogue.descendre', ['nom' => $nom])) ?>">↓</button>
        </form>
    </div>
    <div class="cat-categorie__texte">
        <p class="cat-categorie__nom">
            <?php if ($categorie['niveau'] === 1): ?><span class="cat-categorie__parente"><?= e(champ($categorie['parente'], 'nom')) ?> ›</span><?php endif; ?>
            <?= e($nom) ?>
            <?php if (!$categorie['visible_public']): ?><span class="pastille pastille--alerte"><?= e(t('catalogue.categorie_cachee')) ?></span><?php endif; ?>
        </p>
        <p class="aide">
            <a class="lien" href="/gestion/catalogue?categorie=<?= $cid ?>"><?= e($nbProduits === 1 ? t('categories.un_produit') : t('categories.n_produits', ['n' => $nbProduits])) ?></a>
            <?php if ($nbSous > 0): ?>
                <span class="cat-categorie__sous"> · <?= e($nbSous === 1 ? t('categories.une_sous_categorie') : t('categories.n_sous_categories', ['n' => $nbSous])) ?></span>
            <?php endif; ?>
        </p>
    </div>
</div>

<?= App\Core\View::rendre('gestion/catalogue/_erreurs', ['erreurs' => $erreurs['categorie-' . $cid] ?? []]) ?>

<details class="cat-depliant" <?= $enErreur && isset($saisies[$cid]) ? 'open' : '' ?>>
    <summary class="bouton bouton--secondaire"><?= e(t('categories.modifier')) ?><span class="cat-cache"> · <?= e($nom) ?></span></summary>
    <form class="formulaire cat-formulaire" method="post" action="/gestion/catalogue/categories/<?= $cid ?>#categorie-<?= $cid ?>" data-suivi>
        <input type="hidden" name="csrf" value="<?= $jeton ?>">
        <?= App\Core\View::rendre('gestion/catalogue/_categorie', ['prefixe' => 'c' . $cid, 'valeurs' => $valeurs, 'parentes' => $parentes, 'categorie' => $categorie]) ?>
        <div class="cat-actions">
            <button class="bouton" type="submit"><?= e(t('catalogue.enregistrer')) ?></button>
            <span class="cat-modifie" hidden><?= e(t('catalogue.non_enregistre')) ?></span>
        </div>
    </form>
</details>

<?php if ($nbSous === 0 && $nbProduits === 0): ?>
    <details class="cat-confirmer">
        <summary class="bouton bouton--secondaire"><?= e(t('categories.supprimer')) ?><span class="cat-cache"> · <?= e($nom) ?></span></summary>
        <div class="cat-confirmer__corps">
            <p><?= e(t('categories.supprimer_texte', ['nom' => $nom])) ?></p>
            <form method="post" action="/gestion/catalogue/categories/<?= $cid ?>/supprimer">
                <input type="hidden" name="csrf" value="<?= $jeton ?>">
                <button class="bouton cat-bouton-danger" type="submit"><?= e(t('categories.supprimer_oui')) ?></button>
            </form>
        </div>
    </details>
<?php elseif ($nbSous > 0): ?>
    <p class="aide cat-categorie__note"><?= e(t('categories.suppression_impossible_sous')) ?></p>
<?php else: ?>
    <p class="aide cat-categorie__note"><?= e(t('categories.suppression_impossible')) ?></p>
<?php endif; ?>
