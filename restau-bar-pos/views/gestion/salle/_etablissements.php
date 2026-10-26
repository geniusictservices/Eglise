<?php
/**
 * Choix de l'établissement (puces), en gardant les autres paramètres de l'adresse.
 * @var list<array> $etablissements
 * @var array $etab
 * @var array $garder paramètres à garder
 */
if (count($etablissements) < 2) {
    return;
}
?>
<nav class="sal-puces" aria-label="<?= e(t('gestion.etablissement')) ?>">
    <?php foreach ($etablissements as $e): ?>
        <a class="puce" href="?<?= e(http_build_query(array_filter(($garder ?? []) + ['etablissement' => (int) $e['id']], static fn ($v) => $v !== null && $v !== ''))) ?>"<?= (int) $e['id'] === (int) $etab['id'] ? ' aria-current="true"' : '' ?>><?= e($e['nom']) ?></a>
    <?php endforeach; ?>
</nav>
