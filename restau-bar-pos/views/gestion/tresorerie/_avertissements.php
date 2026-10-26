<?php
/**
 * Avertissements avant d'enregistrer (découvert, taux inhabituel) : le formulaire est renvoyé
 * avec un bouton « Enregistrer quand même » (confirme=1), à placer dans le formulaire.
 * @var string[] $avertissements
 */
?>
<?php if (!empty($avertissements)): ?>
    <div class="tre-avertissement" role="alert" id="avertissement">
        <p class="tre-avertissement__titre"><?= e(t('tresorerie.avertissement_titre')) ?></p>
        <ul>
            <?php foreach ($avertissements as $avertissement): ?>
                <li><?= e($avertissement) ?></li>
            <?php endforeach; ?>
        </ul>
        <p class="aide"><?= e(t('tresorerie.avertissement_aide')) ?></p>
        <div class="tre-actions">
            <button class="bouton" type="submit" name="confirme" value="1"><?= e(t('tresorerie.enregistrer_quand_meme')) ?></button>
        </div>
    </div>
<?php endif; ?>
