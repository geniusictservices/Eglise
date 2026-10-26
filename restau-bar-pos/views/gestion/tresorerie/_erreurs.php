<?php
/** @var string[] $erreurs */
?>
<?php if (!empty($erreurs)): ?>
    <div class="alerte alerte--erreur tre-erreurs" role="alert" id="erreurs" tabindex="-1">
        <?php if (count($erreurs) === 1): ?>
            <?= e(reset($erreurs)) ?>
        <?php else: ?>
            <?= e(t('tresorerie.corrigez_plusieurs')) ?>
            <ul>
                <?php foreach ($erreurs as $erreur): ?>
                    <li><?= e($erreur) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
<?php endif; ?>
