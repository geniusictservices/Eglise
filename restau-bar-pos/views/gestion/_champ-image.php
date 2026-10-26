<?php
/**
 * Champ image réutilisable (photo d'un produit, logo, couverture d'un établissement).
 *
 * Avec JavaScript (public/assets/js/champ-image.js) : glisser-déposer un fichier, toucher pour choisir,
 * coller une image (Ctrl+V), « Choisir dans la médiathèque », « Retirer l'image ». L'image envoyée va
 * dans la médiathèque, puis son chemin est placé dans le champ caché $nom.
 * Sans JavaScript : l'<input type="file"> $fichier fonctionne comme avant, et le champ caché garde
 * l'image actuelle (aucun changement).
 *
 * À charger dans la page : /assets/css/champ-image.css et /assets/js/champ-image.js.
 *
 * @var string  $id              préfixe des identifiants, ex. « photo »
 * @var string  $fichier         nom de l'<input type="file">, ex. « photo »
 * @var string  $nom             nom du champ caché (chemin choisi), ex. « photo_media »
 * @var ?string $chemin          image actuelle
 * @var ?string $alt             texte alternatif de l'image actuelle
 * @var string  $vide            texte quand il n'y a pas d'image
 * @var string  $libelleFichier  libellé de l'<input type="file">
 * @var ?string $aide
 * @var ?string $format          « carre » (par défaut), « logo » ou « large »
 * @var ?string $credit          id du champ de crédit à pré-remplir avec celui de la médiathèque
 * @var ?string $retirerForm     id d'un formulaire de retrait, pour le bouton « Retirer » sans JavaScript
 * @var ?string $retirerTexte
 * @var ?bool   $invalide
 * @var ?int    $octetsMax
 */
$format ??= 'carre';
$image = urlImageSure($chemin ?? null);
$retirerTexte ??= t('champ_image.retirer');
$decrit = $id . '-aide ' . $id . '-statut';
?>
<div class="champ-image champ-image--<?= e($format) ?>" data-champ-image
     <?= !empty($credit) ? 'data-credit="' . e($credit) . '"' : '' ?>
    <?= App\Core\View::rendre('gestion/_config-images', ['octetsMax' => $octetsMax ?? null]) ?>>
    <div class="champ-image__zone" data-zone aria-describedby="<?= e($decrit) ?>">
        <div class="champ-image__apercu" data-apercu>
            <?php if ($image): ?>
                <img src="<?= e($image) ?>" alt="<?= e($alt ?? '') ?>" data-image>
            <?php endif; ?>
            <span class="champ-image__vide" data-vide<?= $image ? ' hidden' : '' ?>>
                <span class="champ-image__vide-texte"><?= e($vide) ?></span>
                <span class="champ-image__invite" data-invite hidden>
                    <span class="champ-image__invite--glisser"><?= e(t('champ_image.invite_glisser')) ?></span>
                    <span class="champ-image__invite--toucher"><?= e(t('champ_image.invite_toucher')) ?></span>
                </span>
            </span>
        </div>
        <div class="champ-image__survol" aria-hidden="true"><?= e(t('champ_image.deposer')) ?></div>
        <div class="champ-image__progression" data-progression hidden>
            <progress max="100" value="0" aria-label="<?= e(t('champ_image.progression')) ?>"></progress>
            <span data-pourcentage>0 %</span>
        </div>
    </div>

    <div class="champ-image__actions" data-actions hidden>
        <button class="bouton bouton--secondaire bouton--petit" type="button" data-parcourir><?= e(t('champ_image.choisir_fichier')) ?></button>
        <button class="bouton bouton--secondaire bouton--petit" type="button" data-ouvrir-mediatheque aria-haspopup="dialog"><?= e(t('champ_image.choisir_mediatheque')) ?></button>
        <button class="bouton bouton--fantome bouton--petit champ-image__retirer" type="button" data-retirer<?= $image ? '' : ' hidden' ?>><?= e($retirerTexte) ?></button>
    </div>

    <div class="champ champ-image__repli" data-repli>
        <label for="<?= e($id) ?>-fichier"><?= e($libelleFichier) ?></label>
        <input id="<?= e($id) ?>-fichier" name="<?= e($fichier) ?>" type="file" accept="image/jpeg,image/png,image/webp" data-fichier
               aria-describedby="<?= e($id) ?>-aide"<?= !empty($invalide) ? ' aria-invalid="true"' : '' ?>>
    </div>
    <?php if ($image && !empty($retirerForm)): ?>
        <button class="bouton bouton--secondaire champ-image__repli-retirer" type="submit" form="<?= e($retirerForm) ?>" data-repli><?= e($retirerTexte) ?></button>
    <?php endif; ?>

    <?php if (!empty($aide)): ?><p class="aide" id="<?= e($id) ?>-aide"><?= e($aide) ?></p><?php else: ?><span id="<?= e($id) ?>-aide" hidden></span><?php endif; ?>
    <input type="hidden" name="<?= e($nom) ?>" value="<?= e((string) ($chemin ?? '')) ?>" data-valeur>
    <p class="champ-image__statut" id="<?= e($id) ?>-statut" data-statut role="status" aria-live="polite"></p>
    <p class="champ-image__erreur" data-erreur role="alert" hidden></p>
</div>
