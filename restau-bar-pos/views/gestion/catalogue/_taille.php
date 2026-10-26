<?php
/**
 * Champs d'une taille : nom en français et en anglais, contenance.
 * @var string $prefixe préfixe des id HTML, unique dans la page
 * @var array $valeurs
 */
?>
<div class="cat-paire">
    <div class="champ">
        <label for="<?= e($prefixe) ?>-taille_fr"><?= e(t('tailles.nom_fr')) ?></label>
        <input id="<?= e($prefixe) ?>-taille_fr" name="taille_fr" required maxlength="64" lang="fr"
               value="<?= e($valeurs['taille_fr'] ?? '') ?>" placeholder="<?= e(t('tailles.exemple_fr')) ?>">
    </div>
    <div class="champ">
        <label for="<?= e($prefixe) ?>-taille_en"><?= e(t('tailles.nom_en')) ?> <span class="cat-facultatif"><?= e(t('catalogue.facultatif')) ?></span></label>
        <input id="<?= e($prefixe) ?>-taille_en" name="taille_en" maxlength="64" lang="en"
               value="<?= e($valeurs['taille_en'] ?? '') ?>" placeholder="<?= e(t('tailles.exemple_en')) ?>">
    </div>
</div>
<div class="champ cat-champ-court">
    <label for="<?= e($prefixe) ?>-contenance"><?= e(t('tailles.contenance')) ?> <span class="cat-facultatif"><?= e(t('catalogue.facultatif')) ?></span></label>
    <input id="<?= e($prefixe) ?>-contenance" name="contenance" maxlength="32" autocomplete="off"
           value="<?= e($valeurs['contenance'] ?? '') ?>" placeholder="<?= e(t('tailles.contenance_exemple')) ?>" aria-describedby="<?= e($prefixe) ?>-contenance-aide">
    <p class="aide" id="<?= e($prefixe) ?>-contenance-aide"><?= e(t('tailles.contenance_aide')) ?></p>
</div>
