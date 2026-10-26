<?php
/**
 * Formulaire de photo de profil (gestion, Mon compte, caisse).
 * @var string $action URL du formulaire (POST, multipart)
 * @var string $nom
 * @var ?string $photo photo actuelle
 * @var bool $selfie proposer l'appareil photo de face (tablette de caisse, téléphone)
 * @var ?string $erreur
 */
use App\Core\Images;
use App\Core\View;
$identifiant = 'photo-' . substr(md5($action), 0, 6);
?>
<form method="post" action="<?= e($action) ?>" enctype="multipart/form-data" class="photo-profil" data-photo-profil>
    <input type="hidden" name="csrf" value="<?= e(App\Core\Session::jetonCsrf()) ?>">
    <input type="hidden" name="MAX_FILE_SIZE" value="<?= Images::octetsMax() ?>">
    <div class="photo-profil__apercu" data-apercu>
        <?= View::rendre('partials/_avatar', ['nom' => $nom, 'photo' => $photo, 'classe' => 'avatar--grand']) ?>
    </div>
    <div class="photo-profil__actions">
        <input class="visuellement-cache" id="<?= $identifiant ?>" type="file" name="photo" accept="image/jpeg,image/png,image/webp" <?= $selfie ? 'capture="user"' : '' ?>>
        <label class="bouton bouton--secondaire photo-profil__choisir" for="<?= $identifiant ?>">
            <?= e($selfie ? t('profil.photo_prendre') : ($photo ? t('profil.photo_changer') : t('profil.photo_ajouter'))) ?>
        </label>
        <button class="bouton" type="submit" data-envoyer><?= e(t('profil.photo_enregistrer')) ?></button>
        <?php if ($photo): ?>
            <button class="bouton bouton--fantome" type="submit" name="action" value="retirer"><?= e(t('profil.photo_retirer')) ?></button>
        <?php endif; ?>
    </div>
    <p class="champ__aide"><?= e(t('profil.photo_aide', ['max' => Images::tailleLisible(Images::octetsMax())])) ?></p>
    <?php if (!empty($erreur)): ?>
        <p class="champ__erreur" role="alert"><?= e($erreur) ?></p>
    <?php endif; ?>
</form>
