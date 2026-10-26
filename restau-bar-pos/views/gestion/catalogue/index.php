<?php
/**
 * @var ?string $flash
 * @var array $filtres
 * @var bool $filtre
 * @var array $groupes
 * @var array $categories
 * @var array $etablissements
 * @var ?array $reference
 * @var App\Repositories\DeviseRepository $devises
 * @var int $nbResultats
 */
$retour = $_SERVER['REQUEST_URI'] ?? '/gestion/catalogue';
$jeton = App\Core\Session::jetonCsrf();
// L'ordre n'a de sens que sur la liste complète d'une catégorie
$ordreModifiable = !$filtres['etablissement'] && $filtres['recherche'] === '' && !$filtres['desactives'];
?>
<link rel="stylesheet" href="/assets/css/gestion-catalogue.css">

<div class="cat-entete">
    <h1><?= e($filtres['desactives'] ? t('catalogue.titre_desactives') : t('catalogue.titre')) ?></h1>
    <div class="cat-entete__actions">
        <?php if ($categories !== []): ?>
            <a class="bouton" href="/gestion/catalogue/produits/nouveau<?= $filtres['categorie'] ? '?categorie=' . (int) $filtres['categorie'] : '' ?>">+ <?= e(t('catalogue.nouveau_produit')) ?></a>
        <?php endif; ?>
        <a class="bouton bouton--secondaire" href="/gestion/catalogue/categories"><?= e(t('catalogue.gerer_categories')) ?></a>
    </div>
</div>
<p class="aide"><?= e(t('catalogue.aide')) ?></p>

<p class="alerte cat-annonce" role="status" data-annonce <?= $flash ? '' : 'hidden' ?>><?= e((string) $flash) ?></p>

<?php if ($categories === []): ?>
    <section class="bloc">
        <h2><?= e(t('catalogue.premiers_pas')) ?></h2>
        <p><?= e(t('catalogue.premiers_pas_texte')) ?></p>
        <p><a class="bouton" href="/gestion/catalogue/categories"><?= e(t('categories.ajouter')) ?></a></p>
    </section>
<?php else: ?>
    <form class="formulaire formulaire--ligne cat-filtres" method="get" action="/gestion/catalogue" role="search">
        <?php if ($filtres['desactives']): ?><input type="hidden" name="desactives" value="1"><?php endif; ?>
        <div class="champ">
            <label for="filtre-categorie"><?= e(t('catalogue.categorie')) ?></label>
            <select id="filtre-categorie" name="categorie" data-auto>
                <option value=""><?= e(t('catalogue.toutes')) ?></option>
                <?php foreach ($categories as $categorie): ?>
                    <option value="<?= (int) $categorie['id'] ?>" <?= $filtres['categorie'] === (int) $categorie['id'] ? 'selected' : '' ?>><?= e(($categorie['niveau'] ? '— ' : '') . champ($categorie, 'nom')) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if (count($etablissements) > 1): ?>
            <div class="champ">
                <label for="filtre-etablissement"><?= e(t('gestion.etablissement')) ?></label>
                <select id="filtre-etablissement" name="etablissement" data-auto>
                    <option value=""><?= e(t('catalogue.tous')) ?></option>
                    <?php foreach ($etablissements as $etab): ?>
                        <option value="<?= (int) $etab['id'] ?>" <?= $filtres['etablissement'] === (int) $etab['id'] ? 'selected' : '' ?>><?= e($etab['nom']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <div class="champ">
            <label for="filtre-q"><?= e(t('catalogue.rechercher')) ?></label>
            <input id="filtre-q" name="q" type="search" value="<?= e($filtres['recherche']) ?>" placeholder="<?= e(t('catalogue.rechercher_exemple')) ?>">
        </div>
        <div class="cat-filtres__boutons">
            <button class="bouton" type="submit"><?= e(t('catalogue.filtrer')) ?></button>
            <?php if ($filtres['categorie'] || $filtres['etablissement'] || $filtres['recherche'] !== ''): ?>
                <a class="lien" href="/gestion/catalogue<?= $filtres['desactives'] ? '?desactives=1' : '' ?>"><?= e(t('catalogue.effacer')) ?></a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($filtre): ?>
        <p class="aide cat-compte"><?= e($nbResultats === 1 ? t('catalogue.un_resultat') : t('catalogue.n_resultats', ['n' => $nbResultats])) ?></p>
    <?php endif; ?>

    <?php if ($groupes === []): ?>
        <section class="bloc"><p><?= e($filtres['desactives'] ? t('catalogue.aucun_desactive') : t('catalogue.aucun_resultat')) ?></p></section>
    <?php endif; ?>

    <?php foreach ($groupes as $groupe): $categorie = $groupe['categorie']; $sous = !empty($categorie['niveau']); ?>
        <section class="bloc cat-groupe<?= $sous ? ' cat-groupe--sous' : '' ?>" id="categorie-<?= (int) $categorie['id'] ?>">
            <div class="cat-groupe__titre">
                <h2>
                    <?php if ($sous): ?><span class="cat-groupe__parente"><?= e(champ($categorie['parente'], 'nom')) ?> ›</span><?php endif; ?>
                    <?= e(champ($categorie, 'nom')) ?>
                    <span class="pastille"><?= count($groupe['produits']) ?></span>
                    <?php if (!$categorie['visible_public']): ?><span class="pastille pastille--alerte"><?= e(t('catalogue.categorie_cachee')) ?></span><?php endif; ?>
                </h2>
                <?php if (!$filtres['desactives'] && $categorie['actif']): ?>
                    <a class="lien" href="/gestion/catalogue/produits/nouveau?categorie=<?= (int) $categorie['id'] ?>">+ <?= e(t('catalogue.ajouter_ici')) ?></a>
                <?php endif; ?>
            </div>

            <?php if ($groupe['produits'] === []): ?>
                <p class="aide"><?= e((int) ($categorie['nb_sous'] ?? 0) > 0 ? t('catalogue.categorie_vide_sous') : t('catalogue.categorie_vide')) ?></p>
            <?php else: ?>
                <ul class="cat-liste">
                    <?php foreach ($groupe['produits'] as $i => $produit): ?>
                        <?= App\Core\View::rendre('gestion/catalogue/_produit-ligne', [
                            'produit' => $produit, 'taille' => false, 'filtres' => $filtres, 'jeton' => $jeton, 'retour' => $retour,
                            'reference' => $reference, 'devises' => $devises,
                            'ordre' => $ordreModifiable, 'premier' => $i === 0, 'dernier' => $i === count($groupe['produits']) - 1,
                        ]) ?>
                        <?php foreach ($produit['tailles'] as $taille): ?>
                            <?= App\Core\View::rendre('gestion/catalogue/_produit-ligne', [
                                'produit' => $taille, 'taille' => true, 'filtres' => $filtres, 'jeton' => $jeton, 'retour' => $retour,
                                'reference' => $reference, 'devises' => $devises, 'ordre' => false, 'premier' => false, 'dernier' => false,
                            ]) ?>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>

    <p class="cat-pied">
        <?php if ($filtres['desactives']): ?>
            <a class="lien" href="/gestion/catalogue">← <?= e(t('catalogue.retour_actifs')) ?></a>
        <?php else: ?>
            <a class="lien" href="/gestion/catalogue?desactives=1"><?= e(t('catalogue.voir_desactives')) ?></a>
        <?php endif; ?>
    </p>
<?php endif; ?>

<script src="/assets/js/gestion-catalogue.js" defer></script>
