<?php
/**
 * Caisse : fenêtres de la salle et des notes ouvertes (remplies par caisse-salle.js).
 * - Plan de salle : zones, tables avec leur statut (libre, occupée, addition demandée, réservée), montant et durée,
 *   notes nommées ; sert aussi à choisir la table d'un envoi, d'un transfert ou d'une fusion.
 * - Précisions d'une ligne : cuisson, « sans piment », allergie, « à lancer plus tard ».
 * - Annuler une ligne déjà envoyée : motif, et le PIN d'un supérieur si la personne n'en a pas le droit.
 * - Partager l'addition : par articles ou en parts égales.
 * @var array $salle
 * @var array $etab
 * @var list<array> $zones
 */
$fermer = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M6.4 5 5 6.4 10.6 12 5 17.6 6.4 19l5.6-5.6 5.6 5.6 1.4-1.4-5.6-5.6L19 6.4 17.6 5 12 10.6z"/></svg>';
?>
<dialog class="salle-fenetre" id="salle-fenetre" aria-labelledby="salle-titre">
    <div class="salle-fenetre__tete">
        <div class="salle-fenetre__titres">
            <h2 id="salle-titre"><?= e(t('note.salle')) ?></h2>
            <p class="salle-fenetre__aide" id="salle-aide" hidden></p>
        </div>
        <button type="button" class="icone" data-fermer-salle title="<?= e(t('note.fermer')) ?>"><?= $fermer ?><span class="visuellement-cache"><?= e(t('note.fermer')) ?></span></button>
    </div>
    <nav class="salle-zones" id="salle-zones" aria-label="<?= e(t('gestion.zone')) ?>"></nav>
    <div class="salle-fenetre__corps">
        <div class="plan" id="plan" role="group" aria-label="<?= e(t('note.salle')) ?>"></div>
        <p class="salle-vide" id="salle-vide" hidden><?= e(t('note.tables_vides')) ?></p>
        <section class="salle-notes" aria-labelledby="salle-notes-titre">
            <h3 id="salle-notes-titre"><?= e(t('note.notes_sans_table')) ?></h3>
            <div class="salle-notes__liste" id="salle-notes"></div>
            <form class="salle-nouvelle" id="salle-nouvelle" autocomplete="off">
                <label class="visuellement-cache" for="salle-nom"><?= e(t('note.nom_note')) ?></label>
                <input id="salle-nom" maxlength="80" placeholder="<?= e(t('note.nom_exemple')) ?>" enterkeyhint="go">
                <button type="submit" class="bouton bouton--secondaire" id="salle-nouvelle-ok"><?= e(t('note.nouvelle_note')) ?></button>
            </form>
        </section>
    </div>
    <div class="salle-fenetre__pied">
        <p class="salle-legende" aria-hidden="true">
            <span class="salle-legende__item salle-legende__item--libre"><?= e(t('note.libre')) ?></span>
            <span class="salle-legende__item salle-legende__item--occupee"><?= e(t('note.occupee')) ?></span>
            <span class="salle-legende__item salle-legende__item--addition"><?= e(t('note.addition_demandee')) ?></span>
            <span class="salle-legende__item salle-legende__item--reservee"><?= e(t('note.reservee')) ?></span>
        </p>
        <button type="button" class="bouton bouton--secondaire" id="salle-comptoir"><?= e(t('note.vente_comptoir')) ?></button>
    </div>
</dialog>

<dialog class="formats ligne-fenetre" id="ligne-fenetre" aria-labelledby="ligne-titre">
    <form method="dialog" class="ligne-form" id="ligne-form">
        <h2 id="ligne-titre"></h2>
        <div class="ligne-remarques" id="ligne-remarques" role="group" aria-label="<?= e(t('note.remarque')) ?>"></div>
        <div class="ardoise-champ">
            <label class="paiement-fenetre__etiquette" for="ligne-remarque"><?= e(t('note.remarque_libre')) ?></label>
            <input id="ligne-remarque" maxlength="160" autocomplete="off">
        </div>
        <div class="ardoise-champ ligne-allergie">
            <label class="paiement-fenetre__etiquette" for="ligne-allergie"><?= e(t('note.allergie')) ?></label>
            <input id="ligne-allergie" maxlength="160" autocomplete="off" aria-describedby="ligne-allergie-aide">
            <span class="champ__aide" id="ligne-allergie-aide"><?= e(t('note.allergie_aide')) ?></span>
        </div>
        <?php if ($salle['modules']['cuisine']): ?>
            <label class="ardoise-case ligne-temps">
                <input type="checkbox" id="ligne-temps">
                <span><strong><?= e(t('note.temps_2')) ?></strong><br><small><?= e(t('note.temps_2_aide')) ?></small></span>
            </label>
        <?php endif; ?>
        <div class="paiement-fenetre__actions">
            <button class="bouton bouton--secondaire" value="annuler"><?= e(t('caisse.annuler')) ?></button>
            <button class="bouton" value="valider" id="ligne-valider"><?= e(t('note.valider')) ?></button>
        </div>
    </form>
</dialog>

<dialog class="formats annuler-fenetre" id="annuler-fenetre" aria-labelledby="annuler-titre">
    <form class="ligne-form" id="annuler-form" autocomplete="off" novalidate>
        <h2 id="annuler-titre"><?= e(t('note.annuler_ligne')) ?></h2>
        <p class="quitter__texte" id="annuler-ligne"></p>
        <div class="ardoise-champ" id="annuler-quantite-bloc" hidden>
            <span class="paiement-fenetre__etiquette"><?= e(t('note.quantite_annuler')) ?></span>
            <div class="pas" id="annuler-quantite"></div>
        </div>
        <div class="ardoise-champ">
            <span class="paiement-fenetre__etiquette"><?= e(t('note.motif')) ?></span>
            <div class="ligne-remarques" id="annuler-motifs" role="group" aria-label="<?= e(t('note.motif')) ?>"></div>
        </div>
        <div class="annuler-validation" id="annuler-validation" hidden>
            <p class="annuler-validation__titre"><strong><?= e(t('note.pin_titre')) ?></strong><br><span><?= e(t('note.pin_aide')) ?></span></p>
            <div class="annuler-valideurs" id="annuler-valideurs" role="group" aria-label="<?= e(t('note.pin_qui')) ?>"></div>
            <label class="visuellement-cache" for="annuler-pin">PIN</label>
            <input class="annuler-pin" id="annuler-pin" type="password" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="off" placeholder="••••••">
        </div>
        <p class="paiement-fenetre__erreur" id="annuler-erreur" role="alert" hidden></p>
        <div class="paiement-fenetre__actions">
            <button type="button" class="bouton bouton--secondaire" data-fermer-annuler><?= e(t('caisse.annuler')) ?></button>
            <button type="submit" class="bouton bouton--danger" id="annuler-valider"><?= e(t('note.confirmer_annulation')) ?></button>
        </div>
    </form>
</dialog>

<dialog class="paiement-fenetre partage-fenetre" id="partage-fenetre" aria-labelledby="partage-titre">
    <div class="paiement-fenetre__corps">
        <div class="paiement-fenetre__tete">
            <h2 id="partage-titre"><?= e(t('note.partager')) ?></h2>
            <button type="button" class="icone" data-fermer-partage title="<?= e(t('note.fermer')) ?>"><?= $fermer ?><span class="visuellement-cache"><?= e(t('note.fermer')) ?></span></button>
        </div>
        <div class="segments segments--large" id="partage-modes" role="group" aria-label="<?= e(t('note.partager')) ?>">
            <button type="button" data-mode="lignes" aria-pressed="true"><?= e(t('note.par_articles')) ?></button>
            <button type="button" data-mode="egal" aria-pressed="false"><?= e(t('note.parts_egales')) ?></button>
        </div>
        <div id="partage-lignes" class="partage-lignes"></div>
        <div id="partage-egal" class="partage-egal" hidden>
            <span class="paiement-fenetre__etiquette"><?= e(t('note.nb_parts')) ?></span>
            <div class="segments segments--large" id="partage-nombres"></div>
            <p class="partage-egal__part" id="partage-part" aria-live="polite"></p>
        </div>
        <div class="paiement-fenetre__bilan paiement-fenetre__bilan--regle" id="partage-bilan" aria-live="polite"></div>
        <button type="button" class="bouton bouton--large paiement-fenetre__valider" id="partage-payer" disabled><?= e(t('note.payer_selection')) ?></button>
    </div>
</dialog>

<div class="prets-bandeau" id="prets-bandeau" role="status" hidden></div>
<div class="salle-toast" id="salle-toast" role="status" aria-live="polite" hidden></div>
