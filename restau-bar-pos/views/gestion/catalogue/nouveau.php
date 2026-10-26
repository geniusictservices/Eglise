<?php
/**
 * Création d'un produit : informations, photo, premier format et son prix, établissements.
 * @var array $categories
 * @var array $etablissements
 * @var array $devises
 * @var ?array $reference
 * @var ?array $grille
 * @var array $calcul
 * @var array $infos
 * @var array $format
 * @var array $prix
 * @var array $vente
 * @var string $credit
 * @var string $photo chemin choisi dans la médiathèque (gardé si le formulaire est réaffiché)
 * @var string[] $erreurs
 * @var array $formatsParDefaut
 * @var int $octetsMax
 */
use App\Core\Images;

$unite = t('unite.' . ($infos['unite_base'] ?? 'bouteille'));
?>
<link rel="stylesheet" href="/assets/css/gestion-catalogue.css">
<link rel="stylesheet" href="/assets/css/champ-image.css">

<p class="cat-retour"><a class="lien" href="/gestion/catalogue">← <?= e(t('catalogue.titre')) ?></a></p>
<h1><?= e(t('catalogue.nouveau_produit')) ?></h1>
<p class="aide"><?= e(t('catalogue.nouveau_aide')) ?></p>

<?php if ($categories === []): ?>
    <section class="bloc">
        <p><?= e(t('catalogue.premiers_pas_texte')) ?></p>
        <p><a class="bouton" href="/gestion/catalogue/categories"><?= e(t('categories.ajouter')) ?></a></p>
    </section>
<?php else: ?>
    <?= App\Core\View::rendre('gestion/catalogue/_erreurs', ['erreurs' => $erreurs]) ?>

    <form class="formulaire cat-formulaire" method="post" action="/gestion/catalogue/produits/nouveau" enctype="multipart/form-data"
          data-suivi data-nouveau data-formats-defaut="<?= e(json_encode(array_map(static fn (array $f) => ['fr' => $f[0], 'en' => $f[1]], $formatsParDefaut), JSON_UNESCAPED_UNICODE)) ?>"
          data-unites="<?= e(json_encode(array_combine(App\Repositories\GestionCatalogueRepository::UNITES, array_map(static fn (string $u) => t('unite.' . $u), App\Repositories\GestionCatalogueRepository::UNITES)), JSON_UNESCAPED_UNICODE)) ?>">
        <input type="hidden" name="csrf" value="<?= e(App\Core\Session::jetonCsrf()) ?>">

        <section class="bloc cat-section" id="infos">
            <h2>1. <?= e(t('catalogue.section_infos')) ?></h2>
            <?= App\Core\View::rendre('gestion/catalogue/_infos', ['infos' => $infos, 'categories' => $categories]) ?>
        </section>

        <section class="bloc cat-section" id="photo">
            <h2>2. <?= e(t('catalogue.section_photo')) ?> <span class="cat-facultatif"><?= e(t('catalogue.facultatif')) ?></span></h2>
            <div class="cat-photo">
                <?= App\Core\View::rendre('gestion/_champ-image', [
                    'id' => 'photo', 'fichier' => 'photo', 'nom' => 'photo_media', 'chemin' => $photo !== '' ? $photo : null,
                    'vide' => t('catalogue.pas_de_photo'), 'libelleFichier' => t('catalogue.photo_choisir'),
                    'aide' => t('catalogue.photo_aide', ['max' => Images::tailleLisible($octetsMax)]) . ($erreurs !== [] && $photo === '' ? ' ' . t('catalogue.photo_a_rechoisir') : ''),
                    'credit' => 'photo_credit', 'retirerTexte' => t('catalogue.photo_retirer'), 'octetsMax' => $octetsMax,
                ]) ?>
                <div class="cat-photo__formulaires">
                    <div class="champ">
                        <label for="photo_credit"><?= e(t('catalogue.photo_credit')) ?> <span class="cat-facultatif"><?= e(t('catalogue.facultatif')) ?></span></label>
                        <input id="photo_credit" name="photo_credit" maxlength="255" value="<?= e($credit) ?>" placeholder="<?= e(t('catalogue.photo_credit_exemple')) ?>">
                    </div>
                </div>
            </div>
        </section>

        <section class="bloc cat-section" id="formats">
            <h2>3. <?= e(t('catalogue.premier_format')) ?></h2>
            <p class="aide"><?= e(t('catalogue.premier_format_aide')) ?></p>
            <?= App\Core\View::rendre('gestion/catalogue/_format', ['prefixe' => 'nf', 'valeurs' => $format, 'unite' => $unite, 'groupe' => 'format', 'simple' => true]) ?>

            <?php if ($devises !== []): ?>
                <fieldset class="cat-prix-ligne" data-calcul="<?= e(json_encode($calcul, JSON_UNESCAPED_UNICODE)) ?>">
                    <legend><?= e(t('catalogue.prix_de_vente')) ?><?= $grille ? ' · ' . e(champ($grille, 'nom')) : '' ?></legend>
                    <?php foreach ($devises as $did => $devise): ?>
                        <div class="champ">
                            <label for="prix-<?= (int) $did ?>"><?= e($devise['code']) ?><?php if ($devise['est_reference']): ?> <span class="pastille"><?= e(t('gestion.reference')) ?></span><?php endif; ?></label>
                            <input id="prix-<?= (int) $did ?>" class="cat-prix__case" name="prix[<?= (int) $did ?>]" type="text" inputmode="decimal" autocomplete="off"
                                   value="<?= e(is_string($prix[$did] ?? null) ? $prix[$did] : '') ?>"
                                   data-format="0" data-grille="<?= (int) ($grille['id'] ?? 0) ?>" data-devise="<?= (int) $did ?>">
                        </div>
                    <?php endforeach; ?>
                    <p class="aide"><?= e(t('catalogue.prix_nouveau_aide', ['reference' => $reference['code'] ?? ''])) ?></p>
                </fieldset>
            <?php endif; ?>
        </section>

        <section class="bloc cat-section" id="vente">
            <h2>4. <?= e(t('catalogue.section_vente')) ?></h2>
            <?= App\Core\View::rendre('gestion/catalogue/_vente', ['vente' => $vente, 'etablissements' => $etablissements]) ?>
        </section>

        <div class="cat-actions cat-actions--fin">
            <button class="bouton" type="submit"><?= e(t('catalogue.creer_produit')) ?></button>
            <a class="lien" href="/gestion/catalogue"><?= e(t('catalogue.annuler')) ?></a>
        </div>
    </form>
<?php endif; ?>

<script src="/assets/js/champ-image.js" defer></script>
<script src="/assets/js/gestion-catalogue.js" defer></script>
