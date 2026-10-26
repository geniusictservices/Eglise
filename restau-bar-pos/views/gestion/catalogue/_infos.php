<?php
/**
 * Champs de la section « Informations » (création et fiche).
 * @var array $infos
 * @var array $categories
 */
$badges = array_filter(explode(',', (string) ($infos['badges'] ?? '')));
?>
<div class="champ">
    <label for="categorie_id"><?= e(t('catalogue.categorie')) ?></label>
    <select id="categorie_id" name="categorie_id" required>
        <option value=""><?= e(t('catalogue.choisir')) ?></option>
        <?php foreach ($categories as $categorie): ?>
            <option value="<?= (int) $categorie['id'] ?>" <?= (int) ($infos['categorie_id'] ?? 0) === (int) $categorie['id'] ? 'selected' : '' ?>><?= e(champ($categorie, 'nom')) ?></option>
        <?php endforeach; ?>
    </select>
</div>

<div class="cat-paire">
    <div class="champ">
        <label for="nom_fr"><?= e(t('catalogue.nom_fr')) ?></label>
        <input id="nom_fr" name="nom_fr" required maxlength="128" lang="fr" value="<?= e($infos['nom_fr'] ?? '') ?>" placeholder="<?= e(t('catalogue.nom_exemple_fr')) ?>">
    </div>
    <div class="champ">
        <label for="nom_en"><?= e(t('catalogue.nom_en')) ?> <span class="cat-facultatif"><?= e(t('catalogue.facultatif')) ?></span></label>
        <input id="nom_en" name="nom_en" maxlength="128" lang="en" value="<?= e($infos['nom_en'] ?? '') ?>" placeholder="<?= e(t('catalogue.nom_exemple_en')) ?>">
    </div>
</div>
<p class="aide"><?= e(t('catalogue.anglais_aide')) ?></p>

<div class="cat-paire">
    <div class="champ">
        <label for="description_fr"><?= e(t('catalogue.description_fr')) ?> <span class="cat-facultatif"><?= e(t('catalogue.facultatif')) ?></span></label>
        <textarea id="description_fr" name="description_fr" rows="3" maxlength="2000" lang="fr"><?= e($infos['description_fr'] ?? '') ?></textarea>
    </div>
    <div class="champ">
        <label for="description_en"><?= e(t('catalogue.description_en')) ?> <span class="cat-facultatif"><?= e(t('catalogue.facultatif')) ?></span></label>
        <textarea id="description_en" name="description_en" rows="3" maxlength="2000" lang="en"><?= e($infos['description_en'] ?? '') ?></textarea>
    </div>
</div>

<div class="champ">
    <label for="unite_base"><?= e(t('catalogue.unite_base')) ?></label>
    <select id="unite_base" name="unite_base" required data-unite>
        <?php foreach (App\Repositories\GestionCatalogueRepository::UNITES as $unite): ?>
            <option value="<?= e($unite) ?>" <?= ($infos['unite_base'] ?? '') === $unite ? 'selected' : '' ?>><?= e(t('unite.' . $unite)) ?></option>
        <?php endforeach; ?>
    </select>
    <p class="aide"><?= e(t('catalogue.unite_aide')) ?></p>
</div>

<fieldset class="cat-cases">
    <legend><?= e(t('catalogue.badges')) ?> <span class="cat-facultatif"><?= e(t('catalogue.facultatif')) ?></span></legend>
    <?php foreach (App\Repositories\GestionCatalogueRepository::BADGES as $badge): ?>
        <label class="cat-case">
            <input type="checkbox" name="badges[]" value="<?= e($badge) ?>" <?= in_array($badge, $badges, true) ? 'checked' : '' ?>>
            <span><?= e(t('badge.' . $badge)) ?></span>
        </label>
    <?php endforeach; ?>
</fieldset>

<div class="cat-paire">
    <div class="champ">
        <label for="allergenes_fr"><?= e(t('catalogue.allergenes_fr')) ?> <span class="cat-facultatif"><?= e(t('catalogue.facultatif')) ?></span></label>
        <input id="allergenes_fr" name="allergenes_fr" maxlength="255" lang="fr" value="<?= e($infos['allergenes_fr'] ?? '') ?>" placeholder="<?= e(t('catalogue.allergenes_exemple_fr')) ?>">
    </div>
    <div class="champ">
        <label for="allergenes_en"><?= e(t('catalogue.allergenes_en')) ?> <span class="cat-facultatif"><?= e(t('catalogue.facultatif')) ?></span></label>
        <input id="allergenes_en" name="allergenes_en" maxlength="255" lang="en" value="<?= e($infos['allergenes_en'] ?? '') ?>" placeholder="<?= e(t('catalogue.allergenes_exemple_en')) ?>">
    </div>
</div>
