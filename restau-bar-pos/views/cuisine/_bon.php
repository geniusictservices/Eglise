<?php
/**
 * Bon de cuisine (80 mm) : un envoi d'une note à un poste. Table ou nom de la note, heure, serveur, puis les plats
 * en grand avec leurs précisions ; les ALLERGIES en évidence ; les plats « à suivre » en bas.
 * @var array $envoi
 * @var list<array> $lignes
 * @var list<array> $suivants
 */
?>
<article class="bon" aria-label="<?= e(t('cuisine.bon')) ?>">
    <header class="bon__tete">
        <p class="bon__poste"><?= e($envoi['poste_nom']) ?></p>
        <p class="bon__titre"><?= e($envoi['titre']) ?></p>
        <p class="bon__info"><?= e(date('d/m H:i', strtotime($envoi['cree_le']))) ?><?= $envoi['par_nom'] ? ' · ' . e($envoi['par_nom']) : '' ?><?= !empty($envoi['couverts']) ? ' · ' . e(t('cuisine.couverts', ['n' => (int) $envoi['couverts']])) : '' ?> · #<?= (int) $envoi['id'] ?></p>
    </header>
    <ul class="bon__lignes">
        <?php foreach ($lignes as $l): ?>
            <li class="bon__ligne<?= $l['annulee_le'] !== null ? ' bon__ligne--annulee' : '' ?>">
                <span class="bon__qte"><?= (int) $l['quantite'] ?></span>
                <span class="bon__plat"><?= e($l['libelle']) ?><?= $l['format'] ? ' · ' . e($l['format']) : '' ?>
                    <?php if ($l['remarque']): ?><span class="bon__remarque"><?= e($l['remarque']) ?></span><?php endif; ?>
                    <?php if ($l['allergie']): ?><span class="bon__allergie"><?= e(t('cuisine.allergie', ['texte' => $l['allergie']])) ?></span><?php endif; ?>
                    <?php if ($l['annulee_le'] !== null): ?><span class="bon__remarque"><?= e(t('cuisine.annule')) ?></span><?php endif; ?>
                </span>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php if ($suivants !== []): ?>
        <p class="bon__suite"><?= e(t('cuisine.a_suivre')) ?></p>
        <ul class="bon__lignes bon__lignes--suite">
            <?php foreach ($suivants as $l): ?>
                <li class="bon__ligne"><span class="bon__qte"><?= (int) $l['quantite'] ?></span><span class="bon__plat"><?= e($l['libelle']) ?>
                    <?php if ($l['allergie']): ?><span class="bon__allergie"><?= e(t('cuisine.allergie', ['texte' => $l['allergie']])) ?></span><?php endif; ?></span></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</article>
