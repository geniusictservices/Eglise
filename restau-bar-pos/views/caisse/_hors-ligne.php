<?php
/**
 * Mode hors ligne (caisse et écran de verrouillage) : données de caisse-hors-ligne.js, fenêtre des ventes en attente,
 * bandeau (session expirée, réseau revenu). L'indicateur lui-même est dans l'en-tête de chaque écran.
 *
 * @var array $horsLigne CaisseHorsLigneController::donnees()
 */
$json = json_encode($horsLigne, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
?>
<p class="hl-bandeau" id="hl-bandeau" role="status" hidden>
    <span id="hl-bandeau-texte"></span>
    <button type="button" class="bouton bouton--petit" id="hl-bandeau-action" hidden></button>
</p>
<dialog class="paiement-fenetre hl-fenetre" id="hl-fenetre" aria-labelledby="hl-titre">
    <div class="paiement-fenetre__corps">
        <div class="paiement-fenetre__tete">
            <h2 id="hl-titre"><?= e(t('horsligne.titre')) ?></h2>
            <button type="button" class="icone" data-fermer-hl title="<?= e(t('horsligne.fermer')) ?>">
                <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M6.4 5 5 6.4 10.6 12 5 17.6 6.4 19l5.6-5.6 5.6 5.6 1.4-1.4-5.6-5.6L19 6.4 17.6 5 12 10.6z"/></svg>
                <span class="visuellement-cache"><?= e(t('horsligne.fermer')) ?></span>
            </button>
        </div>
        <p class="hl-statut" id="hl-statut" aria-live="polite"></p>
        <ul class="hl-liste" id="hl-liste"></ul>
        <p class="hl-vide" id="hl-vide" hidden><?= e(t('horsligne.aucune')) ?></p>
        <button type="button" class="bouton bouton--large" id="hl-envoyer"><?= e(t('horsligne.envoyer')) ?></button>
        <div class="hl-aide">
            <p><?= e(t('horsligne.aide')) ?></p>
            <p><?= e(t('horsligne.aide_reseau')) ?></p>
        </div>
    </div>
</dialog>
<script type="application/json" id="donnees-hors-ligne"><?= $json ?></script>
