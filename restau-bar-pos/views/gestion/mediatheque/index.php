<?php
/**
 * Médiathèque : zone de dépôt, recherche, filtres et grille des images.
 * @var ?string $flash
 * @var array $medias
 * @var int $total
 * @var array $compteurs toutes, utilisees, non-utilisees
 * @var string $recherche
 * @var string $filtre
 * @var string $tri
 * @var int $page
 * @var int $pages
 * @var string[] $erreursEnvoi
 * @var int $octetsMax
 */
use App\Core\Images;

$tailleMax = Images::tailleLisible($octetsMax);
?>
<link rel="stylesheet" href="/assets/css/champ-image.css">
<link rel="stylesheet" href="/assets/css/gestion-mediatheque.css">

<h1><?= e(t('mediatheque.titre')) ?></h1>
<p class="aide med-intro"><?= e(t('mediatheque.intro')) ?></p>

<?php if ($flash): ?>
    <p class="alerte" role="status"><?= e($flash) ?></p>
<?php endif; ?>
<?php if ($erreursEnvoi !== []): ?>
    <div class="alerte alerte--erreur" role="alert">
        <p><?= e(t('mediatheque.envoi_refuse')) ?></p>
        <ul><?php foreach ($erreursEnvoi as $erreur): ?><li><?= e($erreur) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<form class="depot" method="post" action="/gestion/mediatheque/envoyer" enctype="multipart/form-data" data-depot
    <?= App\Core\View::rendre('gestion/_config-images', ['octetsMax' => $octetsMax]) ?>>
    <input type="hidden" name="csrf" value="<?= e(App\Core\Session::jetonCsrf()) ?>">
    <div class="depot__invite" data-invite hidden>
        <svg class="depot__icone" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 16V4m0 0-4.5 4.5M12 4l4.5 4.5M4 15v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <p class="depot__titre depot__titre--glisser"><?= e(t('mediatheque.depot_titre')) ?></p>
        <p class="depot__aide depot__aide--glisser"><?= e(t('mediatheque.depot_aide')) ?></p>
        <button class="bouton depot__bouton" type="button" data-parcourir><?= e(t('mediatheque.ajouter')) ?></button>
        <p class="depot__aide"><?= e(t('mediatheque.depot_formats', ['max' => $tailleMax])) ?></p>
    </div>
    <div class="depot__repli" data-repli>
        <div class="champ">
            <label for="med-fichiers"><?= e(t('mediatheque.choisir_images')) ?></label>
            <input id="med-fichiers" name="images[]" type="file" accept="image/jpeg,image/png,image/webp" multiple data-fichier>
            <p class="aide"><?= e(t('mediatheque.depot_formats', ['max' => $tailleMax])) ?></p>
        </div>
        <button class="bouton" type="submit"><?= e(t('mediatheque.envoyer')) ?></button>
    </div>
    <div class="depot__survol" aria-hidden="true"><?= e(t('mediatheque.deposer')) ?></div>
</form>
<ul class="envois" data-envois aria-label="<?= e(t('mediatheque.envois')) ?>" hidden></ul>
<p class="visuellement-cache" data-annonce role="status" aria-live="polite"></p>

<section class="med-liste" aria-labelledby="med-liste-titre">
    <h2 class="visuellement-cache" id="med-liste-titre"><?= e(t('mediatheque.images')) ?></h2>
    <form class="med-filtres" method="get" action="/gestion/mediatheque" role="search">
        <div class="champ med-filtres__recherche">
            <label for="med-q"><?= e(t('mediatheque.rechercher')) ?></label>
            <input id="med-q" name="q" type="search" value="<?= e($recherche) ?>" placeholder="<?= e(t('mediatheque.rechercher_exemple')) ?>" autocomplete="off">
        </div>
        <div class="champ">
            <label for="med-tri"><?= e(t('mediatheque.tri')) ?></label>
            <select id="med-tri" name="tri" data-auto>
                <?php foreach (App\Repositories\MediathequeRepository::TRIS as $choix): ?>
                    <option value="<?= e($choix) ?>"<?= $tri === $choix ? ' selected' : '' ?>><?= e(t('mediatheque.tri_' . $choix)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($filtre !== 'toutes'): ?><input type="hidden" name="filtre" value="<?= e($filtre) ?>"><?php endif; ?>
        <button class="bouton bouton--secondaire" type="submit"><?= e(t('mediatheque.rechercher_bouton')) ?></button>
    </form>

    <nav class="med-puces" aria-label="<?= e(t('mediatheque.filtrer')) ?>">
        <?php foreach (App\Repositories\MediathequeRepository::FILTRES as $choix): ?>
            <a class="puce" href="<?= e(urlAvec(['filtre' => $choix === 'toutes' ? null : $choix, 'page' => null])) ?>"<?= $filtre === $choix ? ' aria-current="page"' : '' ?>>
                <?= e(t('mediatheque.filtre_' . $choix)) ?> <span class="med-puces__nombre nombre" data-compteur="<?= e($choix) ?>"><?= (int) $compteurs[$choix] ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <p class="aide med-resultats"><?= e($recherche !== ''
        ? t($total === 1 ? 'mediatheque.resultat_recherche_un' : 'mediatheque.resultats_recherche', ['n' => $total, 'q' => $recherche])
        : t($total === 1 ? 'mediatheque.resultat_un' : 'mediatheque.resultats', ['n' => $total])) ?></p>

    <?php // Les images envoyées s'ajoutent en tête de grille quand elles correspondent à l'affichage ?>
    <ul class="med-grille" data-grille<?= $recherche === '' && $filtre !== 'utilisees' && $tri === 'recentes' && $page === 1 ? ' data-inserer' : '' ?>>
        <?php foreach ($medias as $media): ?>
            <?= App\Core\View::rendre('gestion/mediatheque/_carte', ['media' => $media]) ?>
        <?php endforeach; ?>
    </ul>
    <?php if ($medias === []): ?>
        <p class="med-vide" data-vide><?= e($recherche !== '' || $filtre !== 'toutes' ? t('mediatheque.aucune_filtre') : t('mediatheque.aucune')) ?></p>
    <?php endif; ?>

    <?php if ($pages > 1): ?>
        <nav class="med-pages" aria-label="<?= e(t('mediatheque.pages')) ?>">
            <?php if ($page > 1): ?><a class="bouton bouton--secondaire bouton--petit" href="<?= e(urlAvec(['page' => $page - 1 > 1 ? $page - 1 : null])) ?>">← <?= e(t('mediatheque.page_precedente')) ?></a><?php endif; ?>
            <span class="nombre"><?= e(t('mediatheque.page_sur', ['page' => $page, 'pages' => $pages])) ?></span>
            <?php if ($page < $pages): ?><a class="bouton bouton--secondaire bouton--petit" href="<?= e(urlAvec(['page' => $page + 1])) ?>"><?= e(t('mediatheque.page_suivante')) ?> →</a><?php endif; ?>
        </nav>
    <?php endif; ?>
</section>

<script src="/assets/js/champ-image.js" defer></script>
<script src="/assets/js/gestion-mediatheque.js" defer></script>
