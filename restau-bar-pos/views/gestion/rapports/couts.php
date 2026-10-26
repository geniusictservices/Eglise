<?php
/**
 * Rapports > Coûts et rentabilité : coût matière et rentabilité par plat ; écarts entre consommation attendue
 * et réelle par produit.
 * @var array $periode
 * @var list<array> $etablissements
 * @var ?int $etablissement
 * @var list<array> $lieux
 * @var ?array $lieu
 * @var string $vue plats | ecarts
 * @var list<array> $plats
 * @var array{ca: float, cout: float} $totaux
 * @var list<array> $ecarts
 * @var int $inventaires inventaires validés sur la période
 * @var ?array $reference
 * @var bool $peutFiches
 */

use App\Controllers\Gestion\StockAffichage as S;
use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;

$m = static fn (?float $v): string => $v === null ? '—' : ($reference ? A::montant($v, $reference) : (string) round($v, 2));
$pct = static fn (?float $v): string => $v === null ? '—' : S::nombre(round($v * 100, 1)) . "\u{00A0}%";
$q = static fn (float $v, string $unite): string => S::nombre(round($v, 3)) . ' ' . S::unite($unite, abs($v));
$garder = ['etablissement' => $etablissement, 'lieu' => $lieu ? (int) $lieu['id'] : null, 'vue' => $vue === 'ecarts' ? 'ecarts' : null];
$margeTotale = $totaux['ca'] - $totaux['cout'];
?>
<link rel="stylesheet" href="/assets/css/gestion-tresorerie.css">
<link rel="stylesheet" href="/assets/css/gestion-stock.css">

<div class="tre-entete">
    <div>
        <h1><?= e(t('couts.titre')) ?></h1>
        <p class="aide"><?= e(t('couts.aide', ['code' => $reference['code'] ?? ''])) ?></p>
    </div>
    <?= View::rendre('gestion/rapports/_exporter', ['titre' => t('couts.titre') . ' · ' . t($vue === 'ecarts' ? 'couts.vue_ecarts' : 'couts.vue_plats'),
        'periode' => $periode['du'] !== null ? App\Controllers\Gestion\RapportAffichage::periode($periode['du'], $periode['au']) : null]) ?>
</div>

<section class="bloc tre-filtres" aria-label="<?= e(t('stock.filtres')) ?>">
    <nav class="tre-puces" aria-label="<?= e(t('couts.vues')) ?>">
        <a class="puce" href="<?= e(urlAvec(['vue' => null])) ?>"<?= $vue === 'plats' ? ' aria-current="true"' : '' ?>><?= e(t('couts.vue_plats')) ?></a>
        <a class="puce" href="<?= e(urlAvec(['vue' => 'ecarts'])) ?>"<?= $vue === 'ecarts' ? ' aria-current="true"' : '' ?>><?= e(t('couts.vue_ecarts')) ?></a>
    </nav>
    <?= View::rendre('gestion/tresorerie/_periode', ['periode' => $periode, 'action' => '/gestion/rapports/couts', 'garder' => $garder]) ?>
    <?php if (count($etablissements) > 1): ?>
        <nav class="tre-puces" aria-label="<?= e(t('gestion.etablissement')) ?>">
            <a class="puce" href="<?= e(urlAvec(['etablissement' => null, 'lieu' => null])) ?>"<?= $etablissement === null ? ' aria-current="true"' : '' ?>><?= e(t('gventes.tous_etablissements')) ?></a>
            <?php foreach ($etablissements as $etab): ?>
                <a class="puce" href="<?= e(urlAvec(['etablissement' => (int) $etab['id'], 'lieu' => null])) ?>"<?= $etablissement === (int) $etab['id'] ? ' aria-current="true"' : '' ?>><?= e($etab['nom']) ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>
    <?php if ($vue === 'ecarts' && count($lieux) > 1): ?>
        <nav class="tre-puces stk-lieux" aria-label="<?= e(t('stock.choisir_lieu')) ?>">
            <a class="puce" href="<?= e(urlAvec(['lieu' => null])) ?>"<?= $lieu === null ? ' aria-current="true"' : '' ?>><?= e(t('stock.tous_lieux')) ?></a>
            <?php foreach ($lieux as $l): ?>
                <a class="puce" href="<?= e(urlAvec(['lieu' => (int) $l['id']])) ?>"<?= $lieu && (int) $lieu['id'] === (int) $l['id'] ? ' aria-current="true"' : '' ?>><?= e($l['nom']) ?><?php if ($etablissement === null): ?> <span class="stk-puce-etab">· <?= e($l['etablissement_nom'] ?? t('stock.central')) ?></span><?php endif; ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>
</section>

<?php if ($vue === 'plats'): ?>
    <?php if ($plats === []): ?>
        <p class="bloc aide"><?= e(t('couts.aucune_vente')) ?></p>
    <?php else: ?>
        <section class="tre-totaux" aria-labelledby="couts-totaux">
            <h2 class="visuellement-cache" id="couts-totaux"><?= e(t('couts.totaux')) ?></h2>
            <div class="chiffres">
                <div class="chiffre"><span class="chiffre__valeur" data-total="ca"><?= e($m($totaux['ca'])) ?></span><span class="chiffre__libelle"><?= e(t('couts.chiffre_ca')) ?></span></div>
                <div class="chiffre"><span class="chiffre__valeur" data-total="cout"><?= e($m($totaux['cout'])) ?></span><span class="chiffre__libelle"><?= e(t('couts.chiffre_cout', ['part' => $pct($totaux['ca'] > 0 ? $totaux['cout'] / $totaux['ca'] : null)])) ?></span></div>
                <div class="chiffre"><span class="chiffre__valeur" data-total="marge"><?= e($m($margeTotale)) ?></span><span class="chiffre__libelle"><?= e(t('couts.chiffre_marge', ['taux' => $pct($totaux['ca'] > 0 ? $margeTotale / $totaux['ca'] : null)])) ?></span></div>
            </div>
        </section>
        <section class="bloc" aria-labelledby="couts-plats">
            <h2 id="couts-plats"><?= e(t('couts.vue_plats')) ?></h2>
            <p class="aide"><?= e(t('couts.plats_aide')) ?></p>
            <div class="tre-defile">
                <table class="tableau stk-table stk-table--cartes stk-couts">
                    <thead>
                        <tr>
                            <th scope="col"><?= e(t('couts.col_produit')) ?></th>
                            <th scope="col" class="nombre"><?= e(t('couts.col_quantite')) ?></th>
                            <th scope="col" class="nombre"><?= e(t('couts.col_ca')) ?></th>
                            <th scope="col" class="nombre"><?= e(t('couts.col_cout')) ?></th>
                            <th scope="col" class="nombre"><?= e(t('couts.col_part_cout')) ?></th>
                            <th scope="col" class="nombre"><?= e(t('couts.col_marge')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($plats as $p): ?>
                            <tr data-plat="<?= (int) $p['produit_id'] ?>">
                                <td data-libelle="<?= e(t('couts.col_produit')) ?>"><strong><?php if ($p['recette'] && $peutFiches): ?><a class="lien" href="/gestion/stock/recettes/<?= (int) $p['produit_id'] ?>"><?= e(champ($p, 'nom')) ?></a><?php else: ?><?= e(champ($p, 'nom')) ?><?php endif; ?></strong>
                                    <span class="tre-sous-texte"><?= e($p['recette'] ? t('couts.avec_fiche') : t('couts.sans_fiche')) ?><?= $p['gros'] > 0 ? ' · ' . e(t('couts.dont_gros', ['quantite' => $q($p['gros'], (string) $p['unite_base'])])) : '' ?><?= $p['sans_cout'] > 0 ? ' · ' . e(t('couts.cout_partiel')) : '' ?></span></td>
                                <td class="nombre" data-libelle="<?= e(t('couts.col_quantite')) ?>"><?= e($q($p['quantite'], (string) $p['unite_base'])) ?></td>
                                <td class="nombre" data-libelle="<?= e(t('couts.col_ca')) ?>"><?= e($m($p['ca'])) ?></td>
                                <td class="nombre" data-libelle="<?= e(t('couts.col_cout')) ?>"><?= e($m($p['cout'])) ?>
                                    <?php if ($p['quantite'] > 0): ?><span class="tre-sous-texte"><?= e(t('stock.cout_par', ['cout' => $m($p['cout'] / $p['quantite']), 'unite' => S::unite((string) $p['unite_base'])])) ?></span><?php endif; ?></td>
                                <td class="nombre" data-libelle="<?= e(t('couts.col_part_cout')) ?>" data-part-cout="<?= $p['part_cout'] !== null ? e((string) round($p['part_cout'] * 100, 1)) : '' ?>"><?= e($pct($p['part_cout'])) ?></td>
                                <td class="nombre" data-libelle="<?= e(t('couts.col_marge')) ?>"><strong class="<?= ($p['marge'] ?? 0) < 0 ? 'tre-negatif' : '' ?>"><?= e($m($p['marge'])) ?></strong>
                                    <span class="tre-sous-texte"><?= e($pct($p['taux_marge'])) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="aide"><?= e(t('couts.plats_note')) ?></p>
        </section>
    <?php endif; ?>
<?php else: ?>
    <section class="bloc" aria-labelledby="couts-ecarts">
        <h2 id="couts-ecarts"><?= e(t('couts.vue_ecarts')) ?></h2>
        <p class="aide"><?= e(t('couts.ecarts_aide')) ?></p>
        <?php if ($inventaires === 0): ?>
            <p class="alerte" role="status" data-sans-inventaire><?= e(t('couts.sans_inventaire')) ?></p>
        <?php else: ?>
            <p class="aide" data-inventaires="<?= (int) $inventaires ?>"><?= e(t($inventaires > 1 ? 'couts.n_inventaires' : 'couts.un_inventaire', ['n' => $inventaires])) ?></p>
        <?php endif; ?>
        <?php if ($ecarts === []): ?>
            <p class="aide"><?= e(t('couts.aucun_mouvement')) ?></p>
        <?php else: ?>
            <div class="tre-defile">
                <table class="tableau stk-table stk-table--cartes stk-couts">
                    <thead>
                        <tr>
                            <th scope="col"><?= e(t('couts.col_produit')) ?></th>
                            <th scope="col" class="nombre"><?= e(t('couts.col_attendue')) ?></th>
                            <th scope="col" class="nombre"><?= e(t('couts.col_pertes')) ?></th>
                            <th scope="col" class="nombre"><?= e(t('couts.col_inventaire')) ?></th>
                            <th scope="col" class="nombre"><?= e(t('couts.col_reelle')) ?></th>
                            <th scope="col" class="nombre"><?= e(t('couts.col_ecart')) ?></th>
                            <th scope="col" class="nombre"><?= e(t('couts.col_valeur_ecart')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ecarts as $x): $u = (string) $x['unite_base']; ?>
                            <tr data-ecart-produit="<?= (int) $x['produit_id'] ?>">
                                <td data-libelle="<?= e(t('couts.col_produit')) ?>"><strong><?= e(champ($x, 'nom')) ?></strong>
                                    <?php if ((float) $x['production'] > 0.0005): ?><span class="tre-sous-texte"><?= e(t('couts.dont_production', ['quantite' => $q((float) $x['production'], $u)])) ?></span><?php endif; ?></td>
                                <td class="nombre" data-libelle="<?= e(t('couts.col_attendue')) ?>"><?= e($q($x['attendue'], $u)) ?></td>
                                <td class="nombre" data-libelle="<?= e(t('couts.col_pertes')) ?>"><?= abs((float) $x['pertes']) > 0.0005 ? e($q((float) $x['pertes'], $u)) : '—' ?></td>
                                <td class="nombre" data-libelle="<?= e(t('couts.col_inventaire')) ?>"><?= abs((float) $x['inventaire'] + (float) $x['ajustements']) > 0.0005 ? e($q((float) $x['inventaire'] + (float) $x['ajustements'], $u)) : '—' ?></td>
                                <td class="nombre" data-libelle="<?= e(t('couts.col_reelle')) ?>"><?= e($q($x['reelle'], $u)) ?></td>
                                <td class="nombre" data-libelle="<?= e(t('couts.col_ecart')) ?>"><strong class="<?= $x['ecart'] > 0.0005 ? 'tre-negatif' : '' ?>"><?= e(($x['ecart'] > 0.0005 ? '+' : '') . $q($x['ecart'], $u)) ?></strong>
                                    <span class="tre-sous-texte"><?= e($pct($x['ecart_pct'])) ?></span></td>
                                <td class="nombre" data-libelle="<?= e(t('couts.col_valeur_ecart')) ?>"><?= e($m((float) $x['valeur_ecart'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="aide"><?= e(t('couts.ecarts_note')) ?></p>
        <?php endif; ?>
    </section>
<?php endif; ?>
