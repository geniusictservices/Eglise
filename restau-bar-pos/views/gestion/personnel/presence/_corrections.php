<?php
/**
 * Journal des corrections de présence (motif obligatoire).
 * @var list<array> $corrections
 * @var bool $avecNom
 */
use App\Controllers\Gestion\PersonnelAffichage as P;

$heures = static function (?string $json): string {
    $v = $json ? json_decode($json, true) : null;
    if (!is_array($v)) {
        return '—';
    }
    return P::jourCourt((string) $v['arrivee']) . ' ' . P::heure($v['arrivee']) . ' → ' . ($v['depart'] ? P::heure($v['depart']) : '…');
};
?>
<ul class="det-lignes per-corrections">
    <?php foreach ($corrections as $c): ?>
        <li>
            <span>
                <strong><?= e(t('pers.correction_' . $c['action'])) ?></strong><?= $avecNom ? ' · ' . e($c['employe_nom']) : '' ?>
                <span class="tre-sous-texte"><?= e(($c['avant'] ? $heures($c['avant']) . ' ⇒ ' : '') . ($c['apres'] ? $heures($c['apres']) : t('pers.supprime'))) ?></span>
                <span class="tre-sous-texte">« <?= e($c['motif']) ?> » — <?= e(($c['par_nom'] ?? '?') . ', ' . App\Controllers\Gestion\TresorerieAffichage::dateHeure($c['le'])) ?></span>
            </span>
        </li>
    <?php endforeach; ?>
</ul>
