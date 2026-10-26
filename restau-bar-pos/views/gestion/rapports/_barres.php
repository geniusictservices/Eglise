<?php
/**
 * Graphique en colonnes (empilées si plusieurs séries), en SVG dans la page : les colonnes s'étirent avec
 * la largeur, les textes (axe, étiquettes, valeurs) sont en HTML et gardent leur taille. Chaque colonne a sa
 * bulle (survol, toucher, clavier) et un titre SVG ; le tableau des données est dans « Voir les données ».
 * Couleurs : jetons --graph-1, --graph-2 (gestion-rapports.css, validés en jour et en nuit).
 * @var string $id
 * @var string $titre
 * @var list<string> $etiquettes une par colonne
 * @var list<array{nom: string, valeurs: list<float>}> $series dans l'ordre d'empilement (bas → haut)
 * @var callable $formater float → texte
 * @var ?list<string> $infos texte de la bulle de chaque colonne (sinon : étiquette et valeurs)
 * @var ?list<string> $etiquettesLongues étiquettes du tableau des données (sinon $etiquettes)
 */
$n = count($etiquettes);
$totaux = [];
for ($i = 0; $i < $n; $i++) {
    $totaux[$i] = 0.0;
    foreach ($series as $s) {
        $totaux[$i] += max(0.0, (float) ($s['valeurs'][$i] ?? 0));
    }
}
$max = $totaux !== [] ? max($totaux) : 0.0;
// Graduation ronde : 1, 2 ou 5 × 10^k au-dessus du maximum
$haut = 1.0;
if ($max > 0) {
    $puissance = 10 ** floor(log10($max));
    foreach ([1, 2, 2.5, 5, 10] as $m) {
        if ($m * $puissance >= $max) {
            $haut = $m * $puissance;
            break;
        }
    }
}
// Au plus 6 étiquettes : lisibles sur un téléphone
$pas = max(1, (int) ceil($n / 6));
$largeur = $n * 10;
$etiquettesLongues ??= $etiquettes;
?>
<figure class="rap-graphique" id="<?= e($id) ?>" style="--colonnes: <?= max(1, $n) ?>" aria-labelledby="<?= e($id) ?>-titre">
    <figcaption class="rap-graphique__titre" id="<?= e($id) ?>-titre"><?= e($titre) ?></figcaption>
    <?php if (count($series) > 1): ?>
        <ul class="rap-legende">
            <?php foreach ($series as $k => $s): ?>
                <li><span class="rap-legende__cle rap-serie-<?= $k + 1 ?>" aria-hidden="true"></span><?= e($s['nom']) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <div class="rap-graphique__corps">
        <div class="rap-graphique__axe" aria-hidden="true">
            <span><?= e($formater($haut)) ?></span>
            <span><?= e($formater($haut / 2)) ?></span>
            <span>0</span>
        </div>
        <div class="rap-graphique__trace">
            <svg viewBox="0 0 <?= $largeur ?> 100" preserveAspectRatio="none" role="img" aria-label="<?= e($titre) ?>" focusable="false">
                <line class="rap-trait-grille" x1="0" y1="0.5" x2="<?= $largeur ?>" y2="0.5" vector-effect="non-scaling-stroke"/>
                <line class="rap-trait-grille" x1="0" y1="50" x2="<?= $largeur ?>" y2="50" vector-effect="non-scaling-stroke"/>
                <?php for ($i = 0; $i < $n; $i++):
                    $info = $infos[$i] ?? ($etiquettesLongues[$i] . ' : ' . implode(' · ', array_map(static fn (array $s) => $s['nom'] . ' ' . $formater((float) ($s['valeurs'][$i] ?? 0)), $series)));
                    $y = 100.0; ?>
                    <g class="rap-colonne" data-info="<?= e($info) ?>" tabindex="0">
                        <title><?= e($info) ?></title>
                        <rect class="rap-cible" x="<?= $i * 10 ?>" y="0" width="10" height="100"/>
                        <?php foreach ($series as $k => $s):
                            $valeur = max(0.0, (float) ($s['valeurs'][$i] ?? 0));
                            $h = $haut > 0 ? $valeur / $haut * 100 : 0;
                            if ($h <= 0) { continue; }
                            $y -= $h; ?>
                            <rect class="rap-barre rap-serie-<?= $k + 1 ?>" x="<?= $i * 10 + 2 ?>" y="<?= round($y, 3) ?>" width="6" height="<?= round($h, 3) ?>" vector-effect="non-scaling-stroke"/>
                        <?php endforeach; ?>
                    </g>
                <?php endfor; ?>
                <line class="rap-base" x1="0" y1="99.5" x2="<?= $largeur ?>" y2="99.5" vector-effect="non-scaling-stroke"/>
            </svg>
            <div class="rap-graphique__x" aria-hidden="true">
                <?php foreach ($etiquettes as $i => $etiquette): ?>
                    <span<?= $i % $pas !== 0 ? ' class="rap-cache"' : '' ?>><?= e($etiquette) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <details class="rap-donnees">
        <summary><?= e(t('rapports.voir_donnees')) ?></summary>
        <div class="rap-defile">
            <table class="tableau">
                <thead><tr><th scope="col"><?= e(t('rapports.col_jour')) ?></th>
                    <?php foreach ($series as $s): ?><th scope="col" class="nombre"><?= e($s['nom']) ?></th><?php endforeach; ?>
                    <?php if (count($series) > 1): ?><th scope="col" class="nombre"><?= e(t('rapports.total')) ?></th><?php endif; ?>
                </tr></thead>
                <tbody>
                    <?php for ($i = 0; $i < $n; $i++): ?>
                        <tr>
                            <th scope="row"><?= e($etiquettesLongues[$i]) ?></th>
                            <?php foreach ($series as $s): ?><td class="nombre"><?= e($formater((float) ($s['valeurs'][$i] ?? 0))) ?></td><?php endforeach; ?>
                            <?php if (count($series) > 1): ?><td class="nombre"><strong><?= e($formater($totaux[$i])) ?></strong></td><?php endif; ?>
                        </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>
    </details>
</figure>
