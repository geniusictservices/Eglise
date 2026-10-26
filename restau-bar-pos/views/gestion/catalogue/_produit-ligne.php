<?php
/**
 * Une ligne de la liste du catalogue : un produit, ou une taille listée sous son produit principal (en retrait).
 * @var array $produit ligne de GestionCatalogueRepository::produits() (ou une de ses « tailles »)
 * @var bool $taille vrai pour une taille affichée sous son produit principal
 * @var array $filtres
 * @var string $jeton
 * @var string $retour
 * @var ?array $reference
 * @var App\Repositories\DeviseRepository $devises
 * @var bool $ordre boutons monter / descendre
 * @var bool $premier
 * @var bool $dernier
 */
use App\Repositories\GestionCatalogueRepository;

$id = (int) $produit['id'];
$estVariante = $produit['parent_id'] !== null;
$libelleTaille = $estVariante ? GestionCatalogueRepository::libelleTaille($produit) : '';
// Nom affiché : « Petite 33 cl » sous son produit ; « Primus · Petite 33 cl » pour une taille listée seule
if ($taille) {
    $nom = $libelleTaille;
} elseif ($estVariante) {
    $nom = champ(['nom_fr' => $produit['parent_fr'] ?? '', 'nom_en' => $produit['parent_en'] ?? ''], 'nom') . ' · ' . $libelleTaille;
} else {
    $nom = champ($produit, 'nom');
}
$nomComplet = $estVariante ? champ($produit, 'nom') : $nom; // « Primus Petite », pour les libellés des boutons
$avecPrix = array_values(array_filter($produit['formats'], static fn (array $f) => $f['montant'] !== null));
$tailles = $produit['tailles'] ?? [];
$badges = $taille ? [] : array_filter(explode(',', (string) $produit['badges']));
?>
<li class="cat-produit<?= $produit['disponible'] ? '' : ' cat-produit--epuise' ?><?= $taille ? ' cat-produit--taille' : '' ?>" id="produit-<?= $id ?>">
    <a class="cat-produit__photo" href="/gestion/catalogue/produits/<?= $id ?>" tabindex="-1" aria-hidden="true">
        <?php if (urlImageSure($produit['photo'])): ?>
            <img src="<?= e($produit['photo']) ?>" alt="" loading="lazy" width="56" height="56">
        <?php else: ?>
            <span><?= e(mb_strtoupper(mb_substr($nom, 0, 1))) ?></span>
        <?php endif; ?>
    </a>
    <div class="cat-produit__texte">
        <p class="cat-produit__nom">
            <a href="/gestion/catalogue/produits/<?= $id ?>"<?php if ($taille): ?> aria-label="<?= e(trim($nomComplet . ' ' . (string) $produit['contenance'])) ?>"<?php endif; ?>><?= e($nom) ?></a>
            <?php if ($estVariante && !$taille): ?><span class="pastille"><?= e(t('tailles.pastille')) ?></span><?php endif; ?>
            <?php foreach ($badges as $badge): ?><span class="pastille"><?= e(t('badge.' . $badge)) ?></span><?php endforeach; ?>
            <?php if (!$produit['visible_public']): ?><span class="pastille pastille--alerte"><?= e(t('catalogue.cache_public')) ?></span><?php endif; ?>
        </p>
        <p class="cat-produit__prix">
            <?php if ($tailles !== []): ?>
                <span class="cat-produit__tailles"><?= e(count($tailles) === 1 ? t('tailles.une_taille') : t('tailles.n_tailles', ['n' => count($tailles)])) ?></span>
            <?php endif; ?>
            <?php if ($avecPrix !== [] && $reference !== null): ?>
                <span class="nombre"><?= e(champ($avecPrix[0], 'nom')) ?> · <?= e($devises->formater((float) $avecPrix[0]['montant'], $reference, false)) ?></span>
                <?php if (count($produit['formats']) > 1): ?>
                    <span class="aide"><?= e(count($produit['formats']) === 2 ? t('catalogue.plus_un_format') : t('catalogue.plus_n_formats', ['n' => count($produit['formats']) - 1])) ?></span>
                <?php endif; ?>
            <?php elseif ($tailles !== []): ?>
                <?php // Produit principal sans format à lui : ses tailles portent les prix ?>
            <?php elseif ($produit['formats'] === []): ?>
                <span class="pastille pastille--alerte"><?= e(t('catalogue.sans_format')) ?></span>
            <?php else: ?>
                <span class="pastille pastille--alerte"><?= e(t('catalogue.sans_prix')) ?></span>
            <?php endif; ?>
        </p>
        <?php if (!$taille): ?>
            <p class="cat-produit__lieux">
                <?php if ($produit['etablissements'] === []): ?>
                    <span class="pastille pastille--alerte"><?= e(t('catalogue.vendu_nulle_part')) ?></span>
                <?php else: ?>
                    <?= e(implode(' · ', $produit['etablissements'])) ?>
                <?php endif; ?>
            </p>
        <?php elseif ($produit['etablissements'] === []): ?>
            <p class="cat-produit__lieux"><span class="pastille pastille--alerte"><?= e(t('catalogue.vendu_nulle_part')) ?></span></p>
        <?php endif; ?>
    </div>

    <?php if ($filtres['desactives']): ?>
        <?php if (!$taille): ?>
            <a class="bouton bouton--secondaire cat-produit__action" href="/gestion/catalogue/produits/<?= $id ?>#reactiver"><?= e(t('catalogue.reactiver')) ?></a>
        <?php endif; ?>
    <?php else: ?>
        <form class="cat-produit__dispo" method="post" action="/gestion/catalogue/produits/<?= $id ?>/disponible" data-bascule>
            <input type="hidden" name="csrf" value="<?= e($jeton) ?>">
            <input type="hidden" name="retour" value="<?= e($retour) ?>">
            <button class="cat-bascule" type="submit" aria-pressed="<?= $produit['disponible'] ? 'false' : 'true' ?>"
                    data-texte-disponible="<?= e(t('catalogue.disponible')) ?>" data-texte-epuise="<?= e(t('badge.epuise')) ?>"
                    data-aide-disponible="<?= e(t($tailles !== [] ? 'tailles.toucher_pour_epuise' : 'catalogue.toucher_pour_epuise', ['nom' => $nomComplet])) ?>"
                    data-aide-epuise="<?= e(t('catalogue.toucher_pour_disponible', ['nom' => $nomComplet])) ?>"
                    title="<?= e($produit['disponible'] ? t($tailles !== [] ? 'tailles.toucher_pour_epuise' : 'catalogue.toucher_pour_epuise', ['nom' => $nomComplet]) : t('catalogue.toucher_pour_disponible', ['nom' => $nomComplet])) ?>">
                <span class="cat-bascule__point" aria-hidden="true"></span>
                <span class="cat-bascule__texte"><?= e($produit['disponible'] ? t('catalogue.disponible') : t('badge.epuise')) ?></span>
                <span class="cat-cache"> · <?= e($nomComplet) ?></span>
            </button>
        </form>
        <?php if ($ordre): ?>
            <div class="cat-ordre">
                <form method="post" action="/gestion/catalogue/produits/<?= $id ?>/monter">
                    <input type="hidden" name="csrf" value="<?= e($jeton) ?>">
                    <input type="hidden" name="retour" value="<?= e($retour) ?>">
                    <button class="cat-ordre__bouton" type="submit" <?= $premier ? 'disabled' : '' ?> aria-label="<?= e(t('catalogue.monter', ['nom' => $nom])) ?>" title="<?= e(t('catalogue.monter', ['nom' => $nom])) ?>">↑</button>
                </form>
                <form method="post" action="/gestion/catalogue/produits/<?= $id ?>/descendre">
                    <input type="hidden" name="csrf" value="<?= e($jeton) ?>">
                    <input type="hidden" name="retour" value="<?= e($retour) ?>">
                    <button class="cat-ordre__bouton" type="submit" <?= $dernier ? 'disabled' : '' ?> aria-label="<?= e(t('catalogue.descendre', ['nom' => $nom])) ?>" title="<?= e(t('catalogue.descendre', ['nom' => $nom])) ?>">↓</button>
                </form>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</li>
