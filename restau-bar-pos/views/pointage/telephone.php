<?php
/**
 * Téléphone de l'employé, ouvert en scannant le code QR de la borne : identification la première fois
 * (téléphone + PIN), puis « Pointer mon arrivée » / « Pointer mon départ » avec la position GPS.
 * @var string $jeton
 * @var ?array $qr borne et fenêtre du code, s'il est valable
 * @var ?string $erreurQr qr_invalide | qr_expire
 * @var ?array $employe employé reconnu par ce téléphone
 * @var ?array $ouverte présence ouverte (arrivée sans départ)
 * @var bool $rattache l'employé travaille-t-il dans l'établissement de la borne ?
 * @var ?string $erreurIdentification
 * @var string $telephone
 */
$csrf = App\Core\Session::jetonCsrf();
$etabNom = $qr['borne']['etablissement_nom'] ?? null;
$depart = $ouverte !== null && $qr !== null && (int) $ouverte['etablissement_id'] === (int) $qr['borne']['etablissement_id'];
?>
<main class="pointage__page">
    <header class="pointage__tete">
        <p class="surtitre"><?= e(t('pointage.surtitre')) ?></p>
        <h1 class="titre-3"><?= e($etabNom ?? t('pointage.titre')) ?></h1>
    </header>

    <?php if ($erreurQr !== null): ?>
        <p class="pointage__alerte" role="alert" data-erreur="<?= e($erreurQr) ?>"><?= e(t('pointage.erreur_' . $erreurQr)) ?></p>
    <?php endif; ?>

    <?php if ($employe === null): ?>
        <section class="pointage__carte" aria-labelledby="identification-titre">
            <h2 id="identification-titre"><?= e(t('pointage.identification_titre')) ?></h2>
            <p class="aide"><?= e(t('pointage.identification_aide')) ?></p>
            <?php if ($erreurIdentification !== null): ?>
                <p class="pointage__alerte" role="alert" id="erreur-identification"><?= e($erreurIdentification) ?></p>
            <?php endif; ?>
            <form method="post" action="/pointer/identifier" class="pointage__formulaire" novalidate>
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="t" value="<?= e($jeton) ?>">
                <div class="champ">
                    <label for="telephone"><?= e(t('pointage.champ_telephone')) ?></label>
                    <input id="telephone" name="telephone" type="tel" inputmode="tel" autocomplete="tel" required value="<?= e($telephone) ?>"
                           placeholder="+243 81 000 0103"<?= $erreurIdentification ? ' aria-invalid="true" aria-describedby="erreur-identification"' : '' ?>>
                </div>
                <div class="champ">
                    <label for="pin"><?= e(t('pointage.champ_pin')) ?></label>
                    <input id="pin" name="pin" type="password" inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="off" required
                           <?= $erreurIdentification ? ' aria-invalid="true" aria-describedby="erreur-identification"' : '' ?>>
                    <p class="champ__aide"><?= e(t('pointage.champ_pin_aide')) ?></p>
                </div>
                <button class="bouton bouton--large" type="submit"><?= e(t('pointage.identifier')) ?></button>
            </form>
        </section>
    <?php else: ?>
        <section class="pointage__carte pointage__carte--moi" aria-labelledby="moi-titre">
            <p class="surtitre"><?= e(t('pointage.bonjour')) ?></p>
            <h2 id="moi-titre" class="pointage__nom"><?= e($employe['nom']) ?></h2>
            <p class="pointage__statut" id="statut">
                <?php if ($ouverte !== null): ?>
                    <span class="badge badge--succes"><?= e(t('pointage.present')) ?></span>
                    <?= e(t('pointage.present_depuis', ['heure' => date('H:i', strtotime($ouverte['arrivee'])), 'etablissement' => $ouverte['etablissement_nom']])) ?>
                <?php else: ?>
                    <span class="badge badge--neutre"><?= e(t('pointage.absent')) ?></span>
                <?php endif; ?>
            </p>
        </section>

        <?php if ($qr !== null && $rattache): ?>
            <section class="pointage__carte pointage__action" id="action" aria-live="polite"
                     data-t="<?= e($jeton) ?>" data-csrf="<?= e($csrf) ?>"
                     data-texte-position="<?= e(t('pointage.position_en_cours')) ?>"
                     data-texte-refus="<?= e(t('pointage.erreur_geoloc_refus')) ?>"
                     data-texte-indisponible="<?= e(t('pointage.erreur_geoloc_indisponible')) ?>"
                     data-texte-delai="<?= e(t('pointage.erreur_geoloc_delai')) ?>"
                     data-texte-reseau="<?= e(t('pointage.erreur_reseau')) ?>"
                     data-texte-envoi="<?= e(t('pointage.envoi')) ?>">
                <button class="bouton bouton--large pointage__bouton" type="button" id="pointer" data-sens="<?= $depart ? 'depart' : 'arrivee' ?>">
                    <?= e(t($depart ? 'pointage.pointer_depart' : 'pointage.pointer_arrivee')) ?>
                </button>
                <p class="aide pointage__position"><?= e(t('pointage.position_aide', ['rayon' => (int) $qr['borne']['pointage_rayon']])) ?></p>
                <div class="pointage__resultat" id="resultat" hidden tabindex="-1"></div>
                <noscript><p class="pointage__alerte"><?= e(t('pointage.sans_js')) ?></p></noscript>
            </section>
        <?php elseif ($qr !== null): ?>
            <p class="pointage__alerte" role="alert"><?= e(t('pointage.erreur_pas_rattache', ['etablissement' => $etabNom])) ?></p>
        <?php else: ?>
            <section class="pointage__carte">
                <p><?= e(t('pointage.scanner')) ?></p>
            </section>
        <?php endif; ?>

        <form method="post" action="/pointer/oublier" class="pointage__oublier">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <button class="lien" type="submit"><?= e(t('pointage.pas_moi', ['nom' => $employe['nom']])) ?></button>
        </form>
    <?php endif; ?>
</main>
