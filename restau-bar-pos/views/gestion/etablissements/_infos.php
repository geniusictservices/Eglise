<?php
/**
 * Champs de la section « Informations » (création et fiche).
 * @var array $infos
 * @var array $erreurs indexées par champ
 * @var bool $creation
 */
$champ = static fn (string $nom, array $options = []): string => App\Core\View::rendre(
    'gestion/etablissements/_champ',
    ['nom' => $nom, 'libelle' => t('etab.' . $nom), 'valeurs' => $infos, 'erreurs' => $erreurs] + $options
);
$slugErreur = $erreurs['slug'] ?? null;
$hote = $_SERVER['HTTP_HOST'] ?? '';
?>
<fieldset class="etb-groupe">
    <legend><?= e(t('etab.groupe_essentiel')) ?></legend>

    <?= $champ('nom', ['requis' => true, 'max' => 128, 'exemple' => t('etab.nom_exemple'), 'attributs' => 'data-slug-source autocomplete="organization"']) ?>

    <div class="champ">
        <label for="etb-slug"><?= e(t('etab.slug')) ?></label>
        <div class="etb-slug">
            <span class="etb-slug__prefixe" aria-hidden="true"><?= e($hote) ?>/e/</span>
            <input id="etb-slug" name="slug" maxlength="64" pattern="[a-z0-9]+(-[a-z0-9]+)*" autocapitalize="off" spellcheck="false"
                   value="<?= e($infos['slug'] ?? '') ?>" placeholder="<?= e(t('etab.slug_exemple')) ?>"
                   data-slug <?= !empty($creation) && ($infos['slug'] ?? '') === '' ? 'data-slug-auto' : '' ?>
                   aria-describedby="<?= $slugErreur !== null ? 'etb-slug-erreur ' : '' ?>etb-slug-aide"<?= $slugErreur !== null ? ' aria-invalid="true"' : '' ?>>
        </div>
        <?php if ($slugErreur !== null): ?><p class="etb-erreur-champ" id="etb-slug-erreur"><?= e($slugErreur) ?></p><?php endif; ?>
        <p class="aide" id="etb-slug-aide"><?= e(!empty($creation) ? t('etab.slug_aide_creation') : t('etab.slug_aide')) ?></p>
    </div>

    <fieldset class="etb-cases etb-cases--types">
        <legend><?= e(t('etab.type')) ?></legend>
        <?php foreach (App\Controllers\Gestion\EtablissementSaisie::TYPES as $type): ?>
            <label class="etb-case etb-case--grande">
                <input type="radio" name="type" value="<?= e($type) ?>" required <?= ($infos['type'] ?? '') === $type ? 'checked' : '' ?>>
                <span><strong><?= e(t('type.' . $type)) ?></strong> <span class="etb-facultatif"><?= e(t('etab.type_aide_' . $type)) ?></span></span>
            </label>
        <?php endforeach; ?>
        <?php if (isset($erreurs['type'])): ?><p class="etb-erreur-champ"><?= e($erreurs['type']) ?></p><?php endif; ?>
    </fieldset>
</fieldset>

<fieldset class="etb-groupe">
    <legend><?= e(t('etab.groupe_presentation')) ?></legend>
    <div class="etb-paire">
        <?= $champ('slogan_fr', ['max' => 255, 'facultatif' => true, 'langue' => 'fr', 'exemple' => t('etab.slogan_exemple_fr')]) ?>
        <?= $champ('slogan_en', ['max' => 255, 'facultatif' => true, 'langue' => 'en', 'exemple' => t('etab.slogan_exemple_en')]) ?>
    </div>
    <div class="etb-paire">
        <?= $champ('description_fr', ['type' => 'textarea', 'max' => 5000, 'facultatif' => true, 'langue' => 'fr']) ?>
        <?= $champ('description_en', ['type' => 'textarea', 'max' => 5000, 'facultatif' => true, 'langue' => 'en']) ?>
    </div>
    <p class="aide"><?= e(t('etab.anglais_aide')) ?></p>
</fieldset>

<fieldset class="etb-groupe">
    <legend><?= e(t('etab.groupe_adresse')) ?></legend>
    <div class="etb-paire">
        <?= $champ('adresse', ['max' => 255, 'facultatif' => true, 'exemple' => t('etab.adresse_exemple'), 'attributs' => 'autocomplete="street-address"']) ?>
        <?= $champ('ville', ['max' => 128, 'facultatif' => true, 'exemple' => t('etab.ville_exemple'), 'attributs' => 'autocomplete="address-level2"']) ?>
    </div>
    <?= $champ('lien_carte', ['max' => 512, 'facultatif' => true, 'exemple' => 'https://maps.app.goo.gl/…', 'aide' => t('etab.lien_carte_aide'), 'attributs' => 'inputmode="url"']) ?>
</fieldset>

<fieldset class="etb-groupe">
    <legend><?= e(t('etab.groupe_contact')) ?></legend>
    <div class="etb-paire">
        <?= $champ('telephone', ['type' => 'tel', 'max' => 32, 'facultatif' => true, 'exemple' => '+243 970 000 000', 'attributs' => 'autocomplete="tel"']) ?>
        <?= $champ('whatsapp', ['type' => 'tel', 'max' => 32, 'facultatif' => true, 'exemple' => '+243 970 000 000', 'aide' => t('etab.whatsapp_aide')]) ?>
    </div>
    <?= $champ('email', ['type' => 'email', 'max' => 128, 'facultatif' => true, 'exemple' => t('etab.email_exemple'), 'attributs' => 'autocomplete="email"']) ?>
</fieldset>

<fieldset class="etb-groupe">
    <legend><?= e(t('etab.groupe_reseaux')) ?></legend>
    <p class="aide"><?= e(t('etab.reseaux_aide')) ?></p>
    <div class="etb-trio">
        <?= $champ('facebook', ['max' => 255, 'facultatif' => true, 'exemple' => '@monbar', 'attributs' => 'autocapitalize="off" spellcheck="false"']) ?>
        <?= $champ('instagram', ['max' => 255, 'facultatif' => true, 'exemple' => '@monbar', 'attributs' => 'autocapitalize="off" spellcheck="false"']) ?>
        <?= $champ('tiktok', ['max' => 255, 'facultatif' => true, 'exemple' => '@monbar', 'attributs' => 'autocapitalize="off" spellcheck="false"']) ?>
    </div>
</fieldset>
