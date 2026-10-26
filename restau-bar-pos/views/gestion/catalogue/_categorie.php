<?php
/**
 * Champs d'une catégorie.
 * @var string $prefixe
 * @var array $valeurs
 * @var array $parentes catégories principales proposées comme parente
 * @var ?array $categorie la catégorie modifiée (null à la création)
 */
$categorie ??= null;
$parentes ??= [];
$choisie = (int) ($valeurs['parent_id'] ?? 0);
$aDesSous = $categorie !== null && (int) ($categorie['nb_sous'] ?? 0) > 0;
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
<div class="champ">
    <label for="<?= e($prefixe) ?>-parent"><?= e(t('categories.parente')) ?></label>
    <select id="<?= e($prefixe) ?>-parent" name="parent_id" aria-describedby="<?= e($prefixe) ?>-parent-aide">
        <option value=""><?= e(t('categories.parente_aucune')) ?></option>
        <?php foreach ($parentes as $parente):
            if ($categorie !== null && (int) $parente['id'] === (int) $categorie['id']) {
                continue;
            } ?>
            <option value="<?= (int) $parente['id'] ?>" <?= $choisie === (int) $parente['id'] ? 'selected' : '' ?>><?= e(t('categories.parente_dans', ['nom' => champ($parente, 'nom')])) ?></option>
        <?php endforeach; ?>
    </select>
    <p class="aide" id="<?= e($prefixe) ?>-parent-aide"><?= e($aDesSous
        ? t('categories.parente_aide_a_sous', ['nom' => champ($categorie, 'nom')])
        : t('categories.parente_aide')) ?></p>
</div>
<label class="cat-case">
    <input type="checkbox" name="visible_public" value="1" <?= !empty($valeurs['visible_public']) ? 'checked' : '' ?>>
    <span><?= e(t('categories.visible_public')) ?></span>
</label>
