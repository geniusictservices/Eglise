<?php
/** @var array $erreurs messages (indexés par champ ou non) */
?>
<?php if (!empty($erreurs)): ?>
    <div class="alerte alerte--erreur etb-erreurs" role="alert">
        <p><?= e(count($erreurs) > 1 ? t('etab.corrigez_plusieurs') : t('etab.corrigez')) ?></p>
        <ul>
            <?php foreach ($erreurs as $erreur): ?>
                <li><?= e($erreur) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
