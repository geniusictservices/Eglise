<?php
/** @var string[] $erreurs */
?>
<?php if (!empty($erreurs)): ?>
    <div class="alerte alerte--erreur dev-erreurs" role="alert">
        <?php if (count($erreurs) === 1): ?>
            <?= e(reset($erreurs)) ?>
        <?php else: ?>
            <?= e(t('devises.corrigez_plusieurs')) ?>
            <ul>
                <?php foreach ($erreurs as $erreur): ?>
                    <li><?= e($erreur) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
<?php endif; ?>
