<?php
/**
 * Une ligne de l'éditeur d'horaires : un service, ses jours et ses heures.
 * @var int|string $index  numéro de la ligne (« __N__ » dans le modèle copié par le JavaScript)
 * @var array $ligne       service_fr, service_en, jours, ouverture, fermeture
 */
$nom = 'horaires[' . $index . ']';
$id = 'etb-h' . $index;
$ouverture = (string) ($ligne['ouverture'] ?? $ligne['ouverture_saisie'] ?? '');
$fermeture = (string) ($ligne['fermeture'] ?? $ligne['fermeture_saisie'] ?? '');
$jours = array_map('intval', $ligne['jours'] ?? []);
$lendemain = $ouverture !== '' && $fermeture !== '' && $fermeture < $ouverture;
$journee = $ouverture !== '' && $ouverture === $fermeture;
?>
<fieldset class="etb-horaire<?= !empty($ligne['erreur']) ? ' etb-horaire--erreur' : '' ?>" data-horaire>
    <legend class="etb-cache"><?= e(t('etab.horaires_ligne', ['n' => is_int($index) ? $index + 1 : ''])) ?></legend>
    <div class="etb-horaire__service">
        <div class="champ">
            <label for="<?= $id ?>-fr"><?= e(t('etab.service_fr')) ?></label>
            <input id="<?= $id ?>-fr" name="<?= $nom ?>[service_fr]" maxlength="64" lang="fr" list="etb-services-fr"
                   value="<?= e($ligne['service_fr'] ?? '') ?>" placeholder="<?= e(t('etab.service_exemple_fr')) ?>">
        </div>
        <div class="champ">
            <label for="<?= $id ?>-en"><?= e(t('etab.service_en')) ?> <span class="etb-facultatif"><?= e(t('etab.facultatif')) ?></span></label>
            <input id="<?= $id ?>-en" name="<?= $nom ?>[service_en]" maxlength="64" lang="en"
                   value="<?= e($ligne['service_en'] ?? '') ?>" placeholder="<?= e(t('etab.service_exemple_en')) ?>">
        </div>
    </div>

    <div class="etb-horaire__jours" role="group" aria-label="<?= e(t('etab.jours')) ?>">
        <?php for ($j = 1; $j <= 7; $j++): ?>
            <label class="etb-jour">
                <input type="checkbox" name="<?= $nom ?>[jours][]" value="<?= $j ?>" <?= in_array($j, $jours, true) ? 'checked' : '' ?>>
                <span title="<?= e(t('jour_long.' . $j)) ?>"><?= e(t('jour.' . $j)) ?></span>
            </label>
        <?php endfor; ?>
        <button class="etb-jour etb-jour--tous" type="button" data-tous-jours hidden><?= e(t('etab.tous_les_jours')) ?></button>
    </div>

    <div class="etb-horaire__heures">
        <div class="champ">
            <label for="<?= $id ?>-ouv"><?= e(t('etab.ouverture')) ?></label>
            <input id="<?= $id ?>-ouv" name="<?= $nom ?>[ouverture]" type="time" value="<?= e($ouverture) ?>" data-ouverture>
        </div>
        <span class="etb-horaire__fleche" aria-hidden="true">→</span>
        <div class="champ">
            <label for="<?= $id ?>-ferm"><?= e(t('etab.fermeture')) ?></label>
            <input id="<?= $id ?>-ferm" name="<?= $nom ?>[fermeture]" type="time" value="<?= e($fermeture) ?>" data-fermeture>
        </div>
    </div>
    <p class="etb-horaire__note" data-note-lendemain <?= $lendemain ? '' : 'hidden' ?>><?= e(t('etab.lendemain')) ?></p>
    <p class="etb-horaire__note" data-note-journee <?= $journee ? '' : 'hidden' ?>><?= e(t('etab.journee_entiere')) ?></p>
    <button class="bouton bouton--secondaire etb-horaire__retirer" type="button" data-retirer-horaire hidden><?= e(t('etab.horaires_retirer')) ?></button>
</fieldset>
