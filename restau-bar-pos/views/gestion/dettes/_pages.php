<?php
/**
 * Pages d'une liste (?page=) : précédente, « Page 2 sur 5 », suivante. Les autres paramètres de l'adresse sont gardés.
 * @var int $page
 * @var int $pages
 */
?>
<?php if ($pages > 1): ?>
    <nav class="det-pages" aria-label="<?= e(t('gventes.pages')) ?>">
        <?php if ($page > 1): ?><a class="bouton bouton--secondaire bouton--petit" href="<?= e(urlAvec(['page' => $page - 1 > 1 ? $page - 1 : null])) ?>" rel="prev">← <?= e(t('gventes.page_precedente')) ?></a><?php endif; ?>
        <span class="nombre"><?= e(t('gventes.page_sur', ['page' => $page, 'pages' => $pages])) ?></span>
        <?php if ($page < $pages): ?><a class="bouton bouton--secondaire bouton--petit" href="<?= e(urlAvec(['page' => $page + 1])) ?>" rel="next"><?= e(t('gventes.page_suivante')) ?> →</a><?php endif; ?>
    </nav>
<?php endif; ?>
