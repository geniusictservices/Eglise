<?php
/** @var string[] $erreurs */
?>
<?php if (!empty($erreurs)): ?>
    <div class="alerte alerte--erreur cat-erreurs" role="alert">
        <p><?= e(count($erreurs) > 1 ? t('catalogue.corrigez_plusieurs') : t('catalogue.corrigez')) ?></p>
        <ul>
            <?php foreach ($erreurs as $erreur): ?>
                <li><?= e($erreur) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
