<?php
/**
 * Champs d'un format (conditionnement).
 * @var string $prefixe préfixe des id HTML, unique dans la page
 * @var array $valeurs
 * @var string $unite libellé de l'unité de base
 * @var ?string $groupe nom du tableau envoyé (« format » à la création), sinon champs simples
 * @var bool $simple vrai à la création : seulement nom et quantité
 */
$groupe ??= null;
$simple ??= false;
$nomChamp = static fn (string $cle): string => $groupe ? $groupe . '[' . $cle . ']' : $cle;
?>
<div class="cat-paire">
    <div class="champ">
        <label for="<?= e($prefixe) ?>-nom_fr"><?= e(t('catalogue.format_nom_fr')) ?></label>
        <input id="<?= e($prefixe) ?>-nom_fr" name="<?= e($nomChamp('nom_fr')) ?>" maxlength="64" lang="fr" <?= $simple ? '' : 'required' ?>
               value="<?= e($valeurs['nom_fr'] ?? '') ?>" placeholder="<?= e(t('catalogue.format_exemple_fr')) ?>" data-format-nom="fr">
    </div>
    <div class="champ">
        <label for="<?= e($prefixe) ?>-nom_en"><?= e(t('catalogue.format_nom_en')) ?> <span class="cat-facultatif"><?= e(t('catalogue.facultatif')) ?></span></label>
        <input id="<?= e($prefixe) ?>-nom_en" name="<?= e($nomChamp('nom_en')) ?>" maxlength="64" lang="en"
               value="<?= e($valeurs['nom_en'] ?? '') ?>" placeholder="<?= e(t('catalogue.format_exemple_en')) ?>" data-format-nom="en">
    </div>
</div>
<div class="cat-paire">
    <div class="champ">
        <label for="<?= e($prefixe) ?>-quantite"><?= e(t('catalogue.format_quantite')) ?></label>
        <div class="cat-unite">
            <input id="<?= e($prefixe) ?>-quantite" name="<?= e($nomChamp('quantite_base')) ?>" inputmode="decimal" required autocomplete="off"
                   value="<?= e($valeurs['quantite_saisie'] ?? '1') ?>" aria-describedby="<?= e($prefixe) ?>-unite">
            <span id="<?= e($prefixe) ?>-unite" data-unite-libelle><?= e($unite) ?></span>
        </div>
    </div>
    <?php if (!$simple): ?>
        <div class="champ">
            <label for="<?= e($prefixe) ?>-code"><?= e(t('catalogue.format_code_barres')) ?> <span class="cat-facultatif"><?= e(t('catalogue.facultatif')) ?></span></label>
            <input id="<?= e($prefixe) ?>-code" name="<?= e($nomChamp('code_barres')) ?>" maxlength="64" inputmode="numeric" autocomplete="off"
                   value="<?= e($valeurs['code_barres'] ?? '') ?>">
        </div>
    <?php endif; ?>
</div>
<?php if (!$simple): ?>
    <div class="cat-cases cat-cases--ligne">
        <label class="cat-case">
            <input type="checkbox" name="<?= e($nomChamp('vendable')) ?>" value="1" <?= !empty($valeurs['vendable']) ? 'checked' : '' ?>>
            <span><?= e(t('catalogue.format_vendable')) ?></span>
        </label>
        <label class="cat-case">
            <input type="checkbox" name="<?= e($nomChamp('achetable')) ?>" value="1" <?= !empty($valeurs['achetable']) ? 'checked' : '' ?>>
            <span><?= e(t('catalogue.format_achetable')) ?></span>
        </label>
    </div>
<?php endif; ?>
