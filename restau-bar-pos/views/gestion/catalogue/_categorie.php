<?php
/**
 * Champs d'une catégorie.
 * @var string $prefixe
 * @var array $valeurs
 */
?>
<div class="cat-paire">
    <div class="champ">
        <label for="<?= e($prefixe) ?>-nom_fr"><?= e(t('categories.nom_fr')) ?></label>
        <input id="<?= e($prefixe) ?>-nom_fr" name="nom_fr" required maxlength="128" lang="fr" value="<?= e($valeurs['nom_fr'] ?? '') ?>" placeholder="<?= e(t('categories.exemple_fr')) ?>">
    </div>
    <div class="champ">
        <label for="<?= e($prefixe) ?>-nom_en"><?= e(t('categories.nom_en')) ?> <span class="cat-facultatif"><?= e(t('catalogue.facultatif')) ?></span></label>
        <input id="<?= e($prefixe) ?>-nom_en" name="nom_en" maxlength="128" lang="en" value="<?= e($valeurs['nom_en'] ?? '') ?>" placeholder="<?= e(t('categories.exemple_en')) ?>">
    </div>
</div>
<label class="cat-case">
    <input type="checkbox" name="visible_public" value="1" <?= !empty($valeurs['visible_public']) ? 'checked' : '' ?>>
    <span><?= e(t('categories.visible_public')) ?></span>
</label>
