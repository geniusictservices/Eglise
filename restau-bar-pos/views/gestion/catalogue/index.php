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
                    <option value="<?= (int) $categorie['id'] ?>" <?= $filtres['categorie'] === (int) $categorie['id'] ? 'selected' : '' ?>><?= e(champ($categorie, 'nom')) ?></option>
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

    <?php foreach ($groupes as $groupe): $categorie = $groupe['categorie']; ?>
        <section class="bloc cat-groupe" id="categorie-<?= (int) $categorie['id'] ?>">
            <div class="cat-groupe__titre">
                <h2>
                    <?= e(champ($categorie, 'nom')) ?>
                    <span class="pastille"><?= count($groupe['produits']) ?></span>
                    <?php if (!$categorie['visible_public']): ?><span class="pastille pastille--alerte"><?= e(t('catalogue.categorie_cachee')) ?></span><?php endif; ?>
                </h2>
                <?php if (!$filtres['desactives'] && $categorie['actif']): ?>
                    <a class="lien" href="/gestion/catalogue/produits/nouveau?categorie=<?= (int) $categorie['id'] ?>">+ <?= e(t('catalogue.ajouter_ici')) ?></a>
                <?php endif; ?>
            </div>

            <?php if ($groupe['produits'] === []): ?>
                <p class="aide"><?= e(t('catalogue.categorie_vide')) ?></p>
            <?php else: ?>
                <ul class="cat-liste">
                    <?php foreach ($groupe['produits'] as $i => $produit):
                        $id = (int) $produit['id'];
                        $nom = champ($produit, 'nom');
                        $avecPrix = array_values(array_filter($produit['formats'], static fn (array $f) => $f['montant'] !== null));
                        $badges = array_filter(explode(',', (string) $produit['badges']));
                        ?>
                        <li class="cat-produit<?= $produit['disponible'] ? '' : ' cat-produit--epuise' ?>" id="produit-<?= $id ?>">
                            <a class="cat-produit__photo" href="/gestion/catalogue/produits/<?= $id ?>" tabindex="-1" aria-hidden="true">
                                <?php if (urlImageSure($produit['photo'])): ?>
                                    <img src="<?= e($produit['photo']) ?>" alt="" loading="lazy" width="56" height="56">
                                <?php else: ?>
                                    <span><?= e(mb_strtoupper(mb_substr($nom, 0, 1))) ?></span>
                                <?php endif; ?>
                            </a>
                            <div class="cat-produit__texte">
                                <p class="cat-produit__nom">
                                    <a href="/gestion/catalogue/produits/<?= $id ?>"><?= e($nom) ?></a>
                                    <?php foreach ($badges as $badge): ?><span class="pastille"><?= e(t('badge.' . $badge)) ?></span><?php endforeach; ?>
                                    <?php if (!$produit['visible_public']): ?><span class="pastille pastille--alerte"><?= e(t('catalogue.cache_public')) ?></span><?php endif; ?>
                                </p>
                                <p class="cat-produit__prix">
                                    <?php if ($avecPrix !== [] && $reference !== null): ?>
                                        <span class="nombre"><?= e(champ($avecPrix[0], 'nom')) ?> · <?= e($devises->formater((float) $avecPrix[0]['montant'], $reference, false)) ?></span>
                                        <?php if (count($produit['formats']) > 1): ?>
                                            <span class="aide"><?= e(count($produit['formats']) === 2 ? t('catalogue.plus_un_format') : t('catalogue.plus_n_formats', ['n' => count($produit['formats']) - 1])) ?></span>
                                        <?php endif; ?>
                                    <?php elseif ($produit['formats'] === []): ?>
                                        <span class="pastille pastille--alerte"><?= e(t('catalogue.sans_format')) ?></span>
                                    <?php else: ?>
                                        <span class="pastille pastille--alerte"><?= e(t('catalogue.sans_prix')) ?></span>
                                    <?php endif; ?>
                                </p>
                                <p class="cat-produit__lieux">
                                    <?php if ($produit['etablissements'] === []): ?>
                                        <span class="pastille pastille--alerte"><?= e(t('catalogue.vendu_nulle_part')) ?></span>
                                    <?php else: ?>
                                        <?= e(implode(' · ', $produit['etablissements'])) ?>
                                    <?php endif; ?>
                                </p>
                            </div>

                            <?php if ($filtres['desactives']): ?>
                                <a class="bouton bouton--secondaire cat-produit__action" href="/gestion/catalogue/produits/<?= $id ?>#reactiver"><?= e(t('catalogue.reactiver')) ?></a>
                            <?php else: ?>
                                <form class="cat-produit__dispo" method="post" action="/gestion/catalogue/produits/<?= $id ?>/disponible" data-bascule>
                                    <input type="hidden" name="csrf" value="<?= e($jeton) ?>">
                                    <input type="hidden" name="retour" value="<?= e($retour) ?>">
                                    <button class="cat-bascule" type="submit" aria-pressed="<?= $produit['disponible'] ? 'false' : 'true' ?>"
                                            data-texte-disponible="<?= e(t('catalogue.disponible')) ?>" data-texte-epuise="<?= e(t('badge.epuise')) ?>"
                                            data-aide-disponible="<?= e(t('catalogue.toucher_pour_epuise', ['nom' => $nom])) ?>"
                                            data-aide-epuise="<?= e(t('catalogue.toucher_pour_disponible', ['nom' => $nom])) ?>"
                                            title="<?= e($produit['disponible'] ? t('catalogue.toucher_pour_epuise', ['nom' => $nom]) : t('catalogue.toucher_pour_disponible', ['nom' => $nom])) ?>">
                                        <span class="cat-bascule__point" aria-hidden="true"></span>
                                        <span class="cat-bascule__texte"><?= e($produit['disponible'] ? t('catalogue.disponible') : t('badge.epuise')) ?></span>
                                        <span class="cat-cache"> · <?= e($nom) ?></span>
                                    </button>
                                </form>
                                <?php if ($ordreModifiable): ?>
                                    <div class="cat-ordre">
                                        <form method="post" action="/gestion/catalogue/produits/<?= $id ?>/monter">
                                            <input type="hidden" name="csrf" value="<?= e($jeton) ?>">
                                            <input type="hidden" name="retour" value="<?= e($retour) ?>">
                                            <button class="cat-ordre__bouton" type="submit" <?= $i === 0 ? 'disabled' : '' ?> aria-label="<?= e(t('catalogue.monter', ['nom' => $nom])) ?>" title="<?= e(t('catalogue.monter', ['nom' => $nom])) ?>">↑</button>
                                        </form>
                                        <form method="post" action="/gestion/catalogue/produits/<?= $id ?>/descendre">
                                            <input type="hidden" name="csrf" value="<?= e($jeton) ?>">
                                            <input type="hidden" name="retour" value="<?= e($retour) ?>">
                                            <button class="cat-ordre__bouton" type="submit" <?= $i === count($groupe['produits']) - 1 ? 'disabled' : '' ?> aria-label="<?= e(t('catalogue.descendre', ['nom' => $nom])) ?>" title="<?= e(t('catalogue.descendre', ['nom' => $nom])) ?>">↓</button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </li>
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
