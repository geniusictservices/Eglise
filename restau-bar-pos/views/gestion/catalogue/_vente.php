<?php
/**
 * Champs de la section « Où il est vendu » (création et fiche).
 * @var array $vente
 * @var array $etablissements
 */
?>
<fieldset class="cat-cases">
    <legend><?= e(t('catalogue.vendu_dans')) ?></legend>
    <?php foreach ($etablissements as $etab): ?>
        <label class="cat-case">
            <input type="checkbox" name="etablissements[]" value="<?= (int) $etab['id'] ?>" <?= in_array((int) $etab['id'], $vente['etablissements'], true) ? 'checked' : '' ?>>
            <span><?= e($etab['nom']) ?> <span class="cat-facultatif"><?= e(t('type.' . $etab['type'])) ?></span></span>
        </label>
    <?php endforeach; ?>
    <p class="aide"><?= e(t('catalogue.vendu_dans_aide')) ?></p>
</fieldset>

<fieldset class="cat-cases">
    <legend><?= e(t('catalogue.affichage')) ?></legend>
    <label class="cat-case">
        <input type="checkbox" name="visible_public" value="1" <?= $vente['visible_public'] ? 'checked' : '' ?>>
        <span><?= e(t('catalogue.visible_public')) ?></span>
    </label>
    <label class="cat-case">
        <input type="checkbox" name="disponible" value="1" <?= $vente['disponible'] ? 'checked' : '' ?>>
        <span><?= e(t('catalogue.disponible_case')) ?></span>
    </label>
    <p class="aide"><?= e(t('catalogue.affichage_aide')) ?></p>
</fieldset>
