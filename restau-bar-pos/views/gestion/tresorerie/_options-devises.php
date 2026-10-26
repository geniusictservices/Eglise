<?php
/**
 * Options de la devise d'un mouvement : celles que le compte choisi tient (toutes les devises proposées
 * s'il n'y a pas encore de compte). Le JavaScript les remplace à chaque changement de compte.
 * @var ?array $compte compte choisi (forme de GestionTresorerieRepository::comptes())
 * @var array<int, array> $devises
 * @var ?int $choisie
 */
$liste = [];
if ($compte !== null) {
    foreach ($compte['devises'] as $deviseId => $ligne) {
        if ($ligne['tenue'] && isset($devises[$deviseId])) {
            $liste[$deviseId] = $devises[$deviseId];
        }
    }
} else {
    $liste = array_filter($devises, static fn (array $d): bool => (bool) ($d['active'] ?? true));
}
?>
<?php foreach ($liste as $deviseId => $devise): ?>
    <option value="<?= (int) $deviseId ?>" <?= (int) ($choisie ?? 0) === (int) $deviseId ? 'selected' : '' ?>><?= e($devise['code']) ?></option>
<?php endforeach; ?>
