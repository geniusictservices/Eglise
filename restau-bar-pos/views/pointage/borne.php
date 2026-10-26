<?php
/**
 * Borne de pointage : code QR changeant en plein écran (tablette posée à l'entrée).
 * Le code est renouvelé toutes les FENETRE secondes par /pointage/borne/jeton ; sans réseau, la borne le dit.
 * @var array $borne
 * @var array{jeton: string, url: string, expire_dans: int, duree: int} $code
 * @var bool $sansPosition
 */
?>
<main class="borne" id="borne"
      data-url="<?= e($code['url']) ?>" data-expire="<?= (int) $code['expire_dans'] ?>" data-duree="<?= (int) $code['duree'] ?>"
      data-texte-renouvele="<?= e(t('pointage.borne_renouvele')) ?>" data-texte-hors-ligne="<?= e(t('pointage.borne_hors_ligne')) ?>"
      data-texte-retire="<?= e(t('pointage.borne_retiree')) ?>">
    <header class="borne__tete">
        <p class="surtitre"><?= e(t('pointage.borne_surtitre')) ?></p>
        <h1 class="borne__nom"><?= e($borne['etablissement_nom']) ?></h1>
        <p class="borne__heure" id="borne-heure" aria-live="off"><?= e(date('H:i')) ?></p>
    </header>
    <div class="borne__qr-cadre">
        <div class="borne__qr" id="borne-qr" role="img" aria-label="<?= e(t('pointage.borne_qr_alt')) ?>"></div>
        <div class="borne__progression" aria-hidden="true"><span id="borne-barre"></span></div>
    </div>
    <p class="borne__appel"><?= e(t('pointage.borne_appel')) ?></p>
    <p class="borne__aide"><?= e(t('pointage.borne_aide')) ?></p>
    <p class="borne__etat" id="borne-etat" role="status"><?= e(t('pointage.borne_renouvele', ['n' => (int) $code['expire_dans']])) ?></p>
    <?php if ($sansPosition): ?>
        <p class="borne__alerte" role="alert"><?= e(t('pointage.borne_sans_position')) ?></p>
    <?php endif; ?>
    <noscript><p class="borne__alerte"><?= e(t('pointage.borne_sans_js')) ?></p></noscript>
    <p class="borne__pied"><?= e($borne['nom']) ?></p>
</main>
