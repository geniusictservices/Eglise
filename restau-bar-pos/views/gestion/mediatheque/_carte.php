<?php
/**
 * Carte d'une image dans la grille de la médiathèque.
 * @var array $media ligne de medias avec « utilisations »
 */
use App\Repositories\MediathequeRepository;

$n = (int) ($media['utilisations'] ?? 0);
$image = urlImageSure((string) $media['chemin']);
?>
<li class="med-carte" id="media-<?= (int) $media['id'] ?>">
    <a class="med-carte__lien" href="/gestion/mediatheque/<?= (int) $media['id'] ?>">
        <span class="med-carte__vignette">
            <?php if ($image): ?><img src="<?= e($image) ?>" alt="" loading="lazy" width="240" height="240"><?php endif; ?>
        </span>
        <span class="med-carte__texte">
            <span class="med-carte__nom"><?= e($media['nom']) ?></span>
            <span class="med-carte__meta nombre"><?= e(MediathequeRepository::dimensions($media)) ?> · <?= e(MediathequeRepository::poidsLisible($media['octets'] === null ? null : (int) $media['octets'])) ?></span>
            <span class="badge <?= $n > 0 ? 'badge--succes' : 'badge--neutre' ?>"><?= e(MediathequeRepository::texteUtilisations($n)) ?></span>
        </span>
    </a>
</li>
