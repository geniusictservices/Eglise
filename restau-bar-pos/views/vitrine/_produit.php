<?php
/**
 * Une ligne du menu public. Un produit à tailles reste une seule ligne, avec un prix
 * par taille et par format vendu (« Petite 33 cl · $1,50 ») ; une taille épuisée est marquée seule.
 * @var array $produit produit du menu (CatalogueRepository::menu)
 * @var string $balise balise du nom : h3 sous une catégorie, h4 sous une sous-catégorie
 */
$recherche = $produit['nom_fr'] . ' ' . $produit['nom_en'] . ' ' . champ($produit, 'description');
foreach ($produit['tailles'] as $taille) {
    $recherche .= ' ' . $taille['taille_fr'] . ' ' . $taille['taille_en'] . ' ' . $taille['contenance'];
}

// Lignes de prix : formats du produit, puis ceux de chaque taille
$lignes = [];
foreach ($produit['conditionnements'] as $cond) {
    $lignes[] = ['format' => champ($cond, 'nom'), 'montant' => $cond['montant_formate'], 'epuise' => false];
}
// Un produit vendu seulement à l'unité n'a pas besoin d'afficher « Bouteille » ou « Assiette »
$uniteSeule = $produit['tailles'] === [] && count($produit['conditionnements']) === 1
    && (float) $produit['conditionnements'][0]['quantite_base'] === 1.0;
foreach ($produit['tailles'] as $taille) {
    $seul = count($taille['conditionnements']) === 1 && (float) $taille['conditionnements'][0]['quantite_base'] === 1.0;
    foreach ($taille['conditionnements'] as $cond) {
        $lignes[] = [
            'format' => $taille['libelle_taille'] . ($seul ? '' : ' · ' . champ($cond, 'nom')),
            'montant' => $cond['montant_formate'],
            'epuise' => !$taille['disponible'] && $produit['disponible'],
        ];
    }
}
?>
<li class="produit<?= $produit['disponible'] ? '' : ' produit--epuise' ?><?= $produit['tailles'] !== [] ? ' produit--tailles' : '' ?>" data-produit
    data-recherche="<?= e(mb_strtolower($recherche)) ?>">
    <?php if ($produit['photo']): ?>
        <img class="produit__photo" src="<?= e($produit['photo']) ?>" alt="" loading="lazy" width="80" height="80">
    <?php endif; ?>
    <div class="produit__texte">
        <<?= $balise ?> class="produit__nom">
            <?= e(champ($produit, 'nom')) ?>
            <?php foreach ($produit['badges'] as $badge): ?>
                <span class="badge badge--<?= e($badge) ?>"><?= e(t('badge.' . $badge)) ?></span>
            <?php endforeach; ?>
            <?php if (!$produit['disponible']): ?>
                <span class="badge badge--epuise"><?= e(t('badge.epuise')) ?></span>
            <?php endif; ?>
        </<?= $balise ?>>
        <?php if (champ($produit, 'description') !== ''): ?>
            <p class="produit__description"><?= e(champ($produit, 'description')) ?></p>
        <?php endif; ?>
        <?php if (champ($produit, 'allergenes') !== ''): ?>
            <p class="produit__allergenes"><?= e(t('vitrine.allergenes', ['liste' => champ($produit, 'allergenes')])) ?></p>
        <?php endif; ?>
    </div>
    <dl class="produit__prix">
        <?php foreach ($lignes as $ligne): ?>
            <div class="prix<?= $ligne['epuise'] ? ' prix--epuise' : '' ?>">
                <dt class="prix__format<?= $uniteSeule ? ' visuellement-cache' : '' ?>"><?= e($ligne['format']) ?></dt>
                <dd class="prix__montant">
                    <?php if ($ligne['epuise']): ?><span class="badge badge--epuise"><?= e(t('badge.epuise')) ?></span><?php endif; ?>
                    <span class="prix__valeur"><?= e($ligne['montant']) ?></span>
                </dd>
            </div>
        <?php endforeach; ?>
    </dl>
    <?php if ($produit['photo'] && !empty($produit['photo_credit'])): ?>
        <p class="produit__credit"><?= e(creditPhoto($produit['photo_credit'])) ?></p>
    <?php endif; ?>
</li>
