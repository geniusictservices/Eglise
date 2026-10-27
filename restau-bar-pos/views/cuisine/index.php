<?php
/**
 * Écran de cuisine : bons en cours du poste choisi (ou de tous), en grandes cartes. Rempli par cuisine.js.
 * @var array $etab
 * @var list<array> $etablissements
 * @var list<array> $postes
 * @var ?array $poste
 * @var ?array $ecran écran autorisé (tablette de la cuisine), null pour une personne connectée
 * @var ?array $utilisateur
 * @var bool $peutAutoriser
 * @var bool $peutRupture
 */
$textes = [];
foreach (['commencer', 'pret', 'servi', 'retour', 'imprimer', 'a_suivre', 'retard', 'minutes', 'allergie', 'par', 'statut_recu', 'statut_preparation',
          'statut_pret', 'statut_servi', 'disponible', 'rupture', 'aucun_plat', 'nouveau_bon', 'erreur', 'ligne_prete', 'ligne_preparation'] as $cle) {
    $textes[$cle] = t('cuisine.' . $cle);
}
$json = json_encode([
    'etab' => $etab['slug'], 'poste' => $poste ? (int) $poste['id'] : 0, 'csrf' => App\Core\Session::jetonCsrf(),
    'textes' => $textes, 'intervalle' => 5000, 'langue' => App\Core\I18n::langue(), 'rupture' => $peutRupture,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
$lien = static fn (?int $p): string => '/caisse/cuisine?' . http_build_query(array_filter(['etab' => $ecran === null ? $etab['slug'] : null, 'poste' => $p ?? 0], static fn ($v) => $v !== null));
?>
<div class="cuisine" id="cuisine">
    <header class="cuisine__haut">
        <div class="cuisine__lieu">
            <?php if (count($etablissements) > 1): ?>
                <form method="get" action="/caisse/cuisine" class="cuisine__etab-form">
                    <label class="visuellement-cache" for="cuisine-etab"><?= e(t('gestion.etablissement')) ?></label>
                    <select id="cuisine-etab" name="etab" onchange="this.form.submit()">
                        <?php foreach ($etablissements as $e): ?>
                            <option value="<?= e($e['slug']) ?>" <?= (int) $e['id'] === (int) $etab['id'] ? 'selected' : '' ?>><?= e($e['nom']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <noscript><button type="submit"><?= e(t('caisse.changer')) ?></button></noscript>
                </form>
            <?php else: ?>
                <p class="cuisine__etab"><?= e($etab['nom']) ?></p>
            <?php endif; ?>
            <?php if ($ecran !== null): ?><p class="cuisine__ecran"><?= e($ecran['nom']) ?></p><?php endif; ?>
        </div>
        <nav class="cuisine__postes" aria-label="<?= e(t('cuisine.postes')) ?>">
            <a class="puce" href="<?= e($lien(0)) ?>"<?= $poste === null ? ' aria-current="page"' : '' ?>><?= e(t('cuisine.tous_postes')) ?></a>
            <?php foreach ($postes as $p): ?>
                <a class="puce" href="<?= e($lien((int) $p['id'])) ?>"<?= $poste && (int) $poste['id'] === (int) $p['id'] ? ' aria-current="page"' : '' ?>><?= e($p['nom']) ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="cuisine__reglages">
            <span class="cuisine__heure" id="cuisine-heure" aria-hidden="true"></span>
            <button type="button" class="puce" id="cuisine-son" aria-pressed="false"><?= e(t('cuisine.son')) ?></button>
            <button type="button" class="puce" id="cuisine-impression" aria-pressed="false"><?= e(t('cuisine.impression_auto')) ?></button>
            <?php if ($peutRupture): ?>
                <button type="button" class="puce" id="cuisine-ruptures" aria-haspopup="dialog"><?= e(t('cuisine.ruptures')) ?></button>
            <?php endif; ?>
            <?= App\Core\View::rendre('layouts/_bouton-theme') ?>
            <?php if ($utilisateur !== null): ?>
                <a class="puce" href="/gestion"><?= e(t('cuisine.gestion')) ?></a>
            <?php endif; ?>
        </div>
    </header>

    <?php if ($postes === []): ?>
        <p class="cuisine__alerte" role="status"><?= e(t('cuisine.sans_postes')) ?></p>
    <?php endif; ?>
    <?php if ($peutAutoriser): ?>
        <details class="cuisine__autoriser">
            <summary class="lien"><?= e(t('cuisine.autoriser')) ?></summary>
            <form method="post" action="/caisse/cuisine/autoriser" class="cuisine__autoriser-form">
                <input type="hidden" name="csrf" value="<?= e(App\Core\Session::jetonCsrf()) ?>">
                <input type="hidden" name="etab" value="<?= e($etab['slug']) ?>">
                <p><?= e(t('cuisine.autoriser_texte')) ?></p>
                <label class="champ"><span><?= e(t('cuisine.ecran_nom')) ?></span>
                    <input class="saisie" name="nom" maxlength="80" placeholder="<?= e(t('cuisine.ecran_exemple')) ?>"></label>
                <label class="champ"><span><?= e(t('cuisine.ecran_poste')) ?></span>
                    <select class="saisie" name="poste">
                        <option value="0"><?= e(t('cuisine.tous_postes')) ?></option>
                        <?php foreach ($postes as $p): ?><option value="<?= (int) $p['id'] ?>" <?= $poste && (int) $poste['id'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['nom']) ?></option><?php endforeach; ?>
                    </select></label>
                <button class="bouton" type="submit"><?= e(t('cuisine.autoriser_oui')) ?></button>
            </form>
        </details>
    <?php endif; ?>

    <main class="cuisine__bons" id="bons" aria-label="<?= e(t('cuisine.bons')) ?>"></main>
    <p class="cuisine__vide" id="bons-vide" hidden><?= e(t('cuisine.aucun_bon')) ?></p>

    <?php if ($peutRupture): ?>
        <dialog class="cuisine-ruptures" id="fenetre-ruptures" aria-labelledby="ruptures-titre">
            <div class="cuisine-ruptures__tete">
                <h2 id="ruptures-titre"><?= e(t('cuisine.ruptures')) ?></h2>
                <form method="dialog"><button class="bouton bouton--secondaire" value="fermer"><?= e(t('cuisine.fermer')) ?></button></form>
            </div>
            <p class="aide"><?= e(t('cuisine.ruptures_aide')) ?></p>
            <div class="cuisine-ruptures__liste" id="ruptures-liste"></div>
        </dialog>
    <?php endif; ?>
</div>
<?php /* Enfant direct de <body> : la feuille d'impression ne montre que lui (comme à la caisse) */ ?>
<div id="zone-impression" aria-hidden="true"></div>
<script type="application/json" id="donnees-cuisine"><?= $json ?></script>
