<?php
/**
 * @var ?string $flash
 * @var array $categories
 * @var array $nouvelle
 * @var array $saisies
 * @var array $erreurs
 */
$jeton = e(App\Core\Session::jetonCsrf());
$dernier = count($categories) - 1;
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
        <?php foreach ($categories as $i => $categorie):
            $cid = (int) $categorie['id'];
            $valeurs = $saisies[$cid] ?? $categorie;
            $nom = champ($categorie, 'nom');
            $enErreur = !empty($erreurs['categorie-' . $cid]);
            ?>
            <li class="cat-categorie" id="categorie-<?= $cid ?>">
                <div class="cat-categorie__ligne">
                    <div class="cat-ordre">
                        <form method="post" action="/gestion/catalogue/categories/<?= $cid ?>/monter">
                            <input type="hidden" name="csrf" value="<?= $jeton ?>">
                            <button class="cat-ordre__bouton" type="submit" <?= $i === 0 ? 'disabled' : '' ?> aria-label="<?= e(t('catalogue.monter', ['nom' => $nom])) ?>" title="<?= e(t('catalogue.monter', ['nom' => $nom])) ?>">↑</button>
                        </form>
                        <form method="post" action="/gestion/catalogue/categories/<?= $cid ?>/descendre">
                            <input type="hidden" name="csrf" value="<?= $jeton ?>">
                            <button class="cat-ordre__bouton" type="submit" <?= $i === $dernier ? 'disabled' : '' ?> aria-label="<?= e(t('catalogue.descendre', ['nom' => $nom])) ?>" title="<?= e(t('catalogue.descendre', ['nom' => $nom])) ?>">↓</button>
                        </form>
                    </div>
                    <div class="cat-categorie__texte">
                        <p class="cat-categorie__nom">
                            <?= e($nom) ?>
                            <?php if (!$categorie['visible_public']): ?><span class="pastille pastille--alerte"><?= e(t('catalogue.categorie_cachee')) ?></span><?php endif; ?>
                        </p>
                        <p class="aide">
                            <a class="lien" href="/gestion/catalogue?categorie=<?= $cid ?>"><?= e((int) $categorie['nb_produits'] === 1 ? t('categories.un_produit') : t('categories.n_produits', ['n' => (int) $categorie['nb_produits']])) ?></a>
                        </p>
                    </div>
                </div>

                <?= App\Core\View::rendre('gestion/catalogue/_erreurs', ['erreurs' => $erreurs['categorie-' . $cid] ?? []]) ?>

                <details class="cat-depliant" <?= $enErreur && isset($saisies[$cid]) ? 'open' : '' ?>>
                    <summary class="bouton bouton--secondaire"><?= e(t('categories.modifier')) ?></summary>
                    <form class="formulaire cat-formulaire" method="post" action="/gestion/catalogue/categories/<?= $cid ?>#categorie-<?= $cid ?>" data-suivi>
                        <input type="hidden" name="csrf" value="<?= $jeton ?>">
                        <?= App\Core\View::rendre('gestion/catalogue/_categorie', ['prefixe' => 'c' . $cid, 'valeurs' => $valeurs]) ?>
                        <div class="cat-actions">
                            <button class="bouton" type="submit"><?= e(t('catalogue.enregistrer')) ?></button>
                            <span class="cat-modifie" hidden><?= e(t('catalogue.non_enregistre')) ?></span>
                        </div>
                    </form>
                </details>

                <?php if ((int) $categorie['nb_produits'] === 0): ?>
                    <details class="cat-confirmer">
                        <summary class="bouton bouton--secondaire"><?= e(t('categories.supprimer')) ?></summary>
                        <div class="cat-confirmer__corps">
                            <p><?= e(t('categories.supprimer_texte', ['nom' => $nom])) ?></p>
                            <form method="post" action="/gestion/catalogue/categories/<?= $cid ?>/supprimer">
                                <input type="hidden" name="csrf" value="<?= $jeton ?>">
                                <button class="bouton cat-bouton-danger" type="submit"><?= e(t('categories.supprimer_oui')) ?></button>
                            </form>
                        </div>
                    </details>
                <?php else: ?>
                    <p class="aide cat-categorie__note"><?= e(t('categories.suppression_impossible')) ?></p>
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
        <?= App\Core\View::rendre('gestion/catalogue/_categorie', ['prefixe' => 'nouvelle', 'valeurs' => $nouvelle]) ?>
        <div class="cat-actions">
            <button class="bouton" type="submit">+ <?= e(t('categories.ajouter')) ?></button>
            <span class="cat-modifie" hidden><?= e(t('catalogue.non_enregistre')) ?></span>
        </div>
    </form>
</section>

<script src="/assets/js/gestion-catalogue.js" defer></script>
