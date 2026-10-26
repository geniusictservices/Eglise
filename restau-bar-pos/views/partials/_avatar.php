<?php
/**
 * Avatar d'une personne : sa photo, sinon ses initiales sur une couleur tirée de son nom.
 * @var string $nom
 * @var ?string $photo chemin public de la photo
 * @var string $classe classe de taille (ex. « avatar--grand »)
 */
use App\Core\Avatars;

$photo = isset($photo) && is_string($photo) && $photo !== '' ? urlImageSure($photo) : null;
?>
<span class="avatar <?= e($classe ?? '') ?>" style="--teinte: <?= Avatars::teinte($nom) ?>" aria-hidden="true"><?php if ($photo): ?><img src="<?= e($photo) ?>" alt="" loading="lazy"><?php else: ?><?= e(Avatars::initiales($nom)) ?><?php endif; ?></span>
