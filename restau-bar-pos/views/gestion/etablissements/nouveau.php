<?php
/**
 * Création d'un établissement : informations, puis prix et devises (pré-remplis).
 * Le reste (apparence, horaires, zones) se règle ensuite sur la fiche.
 * @var array $infos
 * @var array $prix
 * @var array $grilles
 * @var array $devises
 * @var array $erreurs ['infos' => [...], 'prix' => [...]]
 */
$erreursInfos = $erreurs['infos'] ?? [];
$erreursPrix = $erreurs['prix'] ?? [];
?>
<link rel="stylesheet" href="/assets/css/gestion-etablissements.css">

<p class="etb-retour"><a class="lien" href="/gestion/etablissements">← <?= e(t('etab.titre')) ?></a></p>
<h1><?= e(t('etab.nouveau')) ?></h1>
<p class="aide"><?= e(t('etab.nouveau_aide')) ?></p>

<?= App\Core\View::rendre('gestion/etablissements/_erreurs', ['erreurs' => array_merge(array_values($erreursInfos), array_values($erreursPrix))]) ?>

<form class="formulaire etb-formulaire" method="post" action="/gestion/etablissements/nouveau" data-suivi>
    <input type="hidden" name="csrf" value="<?= e(App\Core\Session::jetonCsrf()) ?>">

    <section class="bloc etb-section" id="infos">
        <h2>1. <?= e(t('etab.section_infos')) ?></h2>
        <?= App\Core\View::rendre('gestion/etablissements/_infos', ['infos' => $infos, 'erreurs' => $erreursInfos, 'creation' => true]) ?>
    </section>

    <section class="bloc etb-section" id="prix">
        <h2>2. <?= e(t('etab.section_prix')) ?></h2>
        <?= App\Core\View::rendre('gestion/etablissements/_prix', ['prix' => $prix, 'grilles' => $grilles, 'devises' => $devises, 'erreurs' => $erreursPrix]) ?>
    </section>

    <div class="etb-actions etb-actions--fin">
        <button class="bouton" type="submit" <?= $grilles === [] ? 'disabled' : '' ?>><?= e(t('etab.creer')) ?></button>
        <a class="lien" href="/gestion/etablissements"><?= e(t('etab.annuler')) ?></a>
    </div>
</form>

<script src="/assets/js/gestion-etablissements.js" defer></script>
