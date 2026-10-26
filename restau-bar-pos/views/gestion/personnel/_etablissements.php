<?php
/**
 * Choix de l'établissement (puces), les autres paramètres de l'adresse sont gardés.
 * @var array $etablissements
 * @var ?array $etab
 * @var bool $tous proposer « Tous »
 */
?>
<?php if (count($etablissements) > 1 || !empty($tous)): ?>
    <nav class="tre-puces per-etabs" aria-label="<?= e(t('gestion.etablissement')) ?>">
        <?php if (!empty($tous)): ?>
            <a class="puce" href="<?= e(urlAvec(['etablissement' => null, 'page' => null])) ?>"<?= $etab === null ? ' aria-current="true"' : '' ?>><?= e(t('pers.tous_etablissements')) ?></a>
        <?php endif; ?>
        <?php foreach ($etablissements as $e): ?>
            <a class="puce" href="<?= e(urlAvec(['etablissement' => (int) $e['id'], 'page' => null])) ?>"<?= $etab && (int) $etab['id'] === (int) $e['id'] ? ' aria-current="true"' : '' ?>><?= e($e['nom']) ?></a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>
