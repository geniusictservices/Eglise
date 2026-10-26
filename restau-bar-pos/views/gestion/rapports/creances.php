<?php
/**
 * Rapports > Créances : ce qui est dû aujourd'hui (dettes ouvertes) par devise et en devise de référence au taux du
 * jour, ancienneté, puis qui en répond : par responsable, par serveur, par établissement, par devise ; les clients
 * qui doivent le plus ; les garanties qui attendent une réponse.
 * @var ?int $etablissement
 * @var array $etablissements
 * @var array $rapport total, retard, tranches, groupes, garanties, nb
 * @var array<int, array> $devises
 * @var ?array $reference
 * @var bool $voirDettes
 * @var bool $voirClients
 */

use App\Controllers\Gestion\DetteAffichage as D;
use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;
use App\Repositories\GestionDetteRepository;

$total = $rapport['total'];
$enRef = static fn (float $m): string => $reference ? A::montant($m, $reference) : '';
$commun = ['devises' => $devises, 'reference' => $reference, 'total' => $total['reference'], 'voirDettes' => $voirDettes, 'voirClients' => $voirClients];
?>
<link rel="stylesheet" href="/assets/css/gestion-tresorerie.css">
<link rel="stylesheet" href="/assets/css/gestion-dettes.css">

<div class="tre-entete">
    <div>
        <h1><?= e(t('rcreances.titre')) ?></h1>
        <p class="aide"><?= e(t('rcreances.aide', ['date' => A::jour(date('Y-m-d'))])) ?></p>
    </div>
</div>

<?php if (count($etablissements) > 1): ?>
    <section class="bloc tre-filtres" aria-label="<?= e(t('gestion.etablissement')) ?>">
        <nav class="tre-puces" aria-label="<?= e(t('gestion.etablissement')) ?>">
            <a class="puce" href="<?= e(urlAvec(['etablissement' => null])) ?>"<?= !$etablissement ? ' aria-current="true"' : '' ?>><?= e(t('gventes.tous_etablissements')) ?></a>
            <?php foreach ($etablissements as $etab): ?>
                <a class="puce" href="<?= e(urlAvec(['etablissement' => (int) $etab['id']])) ?>"<?= $etablissement === (int) $etab['id'] ? ' aria-current="true"' : '' ?>><?= e($etab['nom']) ?></a>
            <?php endforeach; ?>
        </nav>
    </section>
<?php endif; ?>

<?php if ($rapport['nb'] === 0): ?>
    <p class="bloc aide" data-creances="0"><?= e(t('rcreances.aucune')) ?></p>
<?php else: ?>
    <section class="tre-totaux" aria-labelledby="rc-totaux">
        <h2 class="visuellement-cache" id="rc-totaux"><?= e(t('rcreances.totaux')) ?></h2>
        <div class="chiffres det-chiffres">
            <?php if ($reference): ?>
                <div class="chiffre chiffre--principal">
                    <span class="chiffre__valeur" data-total="reference">≈ <?= e($enRef($total['reference'])) ?><?= $total['sans_taux'] ? ' *' : '' ?></span>
                    <span class="chiffre__libelle"><?= e(t('rcreances.total_reference', ['code' => $reference['code'], 'n' => $rapport['nb']])) ?></span>
                </div>
            <?php endif; ?>
            <?php foreach (D::ordonner($total['devises'], $devises) as $did => $m): ?>
                <div class="chiffre">
                    <span class="chiffre__valeur" data-total="devise-<?= (int) $did ?>"><?= e(D::montant($m, $did, $devises)) ?></span>
                    <span class="chiffre__libelle"><?= e(t('gdettes.reste_en', ['code' => $devises[$did]['code'] ?? ''])) ?></span>
                </div>
            <?php endforeach; ?>
            <?php if ($reference): ?>
                <div class="chiffre">
                    <span class="chiffre__valeur<?= $rapport['retard']['reference'] > 0 ? ' det-retard' : '' ?>" data-total="retard">≈ <?= e($enRef($rapport['retard']['reference'])) ?></span>
                    <span class="chiffre__libelle"><?= e(t('rcreances.en_retard', ['n' => $rapport['retard']['nb']])) ?></span>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($total['sans_taux']): ?><p class="aide">* <?= e(t('rcreances.sans_taux')) ?></p><?php endif; ?>
    </section>

    <section class="bloc" aria-labelledby="rc-anciennete">
        <h2 id="rc-anciennete"><?= e(t('gdettes.anciennete')) ?></h2>
        <p class="aide"><?= e(t('gdettes.anciennete_aide')) ?></p>
        <ul class="det-tranches-cartes">
            <?php foreach (GestionDetteRepository::TRANCHES as $tranche): $tr = $rapport['tranches'][$tranche]; $part = $total['reference'] > 0 ? $tr['reference'] / $total['reference'] : 0; ?>
                <li class="det-tranche det-tranche--<?= e($tranche) ?>" data-tranche="<?= e($tranche) ?>">
                    <span class="surtitre"><?= e(t('gdettes.tranche_' . $tranche)) ?></span>
                    <strong class="det-tranche__valeur" data-reference><?= e($reference ? '≈ ' . $enRef($tr['reference']) : D::montants($tr['devises'], $devises)) ?></strong>
                    <span class="tre-sous-texte"><?= e(D::montants($tr['devises'], $devises) ?: '—') ?></span>
                    <span class="tre-sous-texte"><?= e(t($tr['nb'] > 1 ? 'gdettes.n_dettes_n' : 'gdettes.une_dette_n', ['n' => $tr['nb']])) ?> · <?= e((string) round($part * 100)) ?>&nbsp;%</span>
                    <span class="tre-categories__barre"><span style="width: <?= e((string) round($part * 100, 1)) ?>%"></span></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <?= View::rendre('gestion/rapports/_groupe-creances', $commun + ['cle' => 'responsable', 'titre' => t('rcreances.par_responsable'), 'aide' => t('rcreances.par_responsable_aide'), 'lignes' => $rapport['groupes']['responsable']]) ?>
    <?= View::rendre('gestion/rapports/_groupe-creances', $commun + ['cle' => 'servi', 'titre' => t('rcreances.par_servi'), 'aide' => t('rcreances.par_servi_aide'), 'lignes' => $rapport['groupes']['servi']]) ?>
    <?= View::rendre('gestion/rapports/_groupe-creances', $commun + ['cle' => 'etablissement', 'titre' => t('rcreances.par_etablissement'), 'aide' => null, 'lignes' => $rapport['groupes']['etablissement']]) ?>
    <?= View::rendre('gestion/rapports/_groupe-creances', $commun + ['cle' => 'devise', 'titre' => t('rcreances.par_devise'), 'aide' => null, 'lignes' => $rapport['groupes']['devise']]) ?>
    <?= View::rendre('gestion/rapports/_groupe-creances', $commun + ['cle' => 'client', 'titre' => t('rcreances.top_clients'), 'aide' => null, 'lignes' => $rapport['groupes']['client']]) ?>

    <section class="bloc" aria-labelledby="rc-garanties" data-garanties-attente="<?= count($rapport['garanties']) ?>">
        <h2 id="rc-garanties"><?= e(t('rcreances.garanties')) ?></h2>
        <?php if ($rapport['garanties'] === []): ?>
            <p class="aide"><?= e(t('rcreances.garanties_aucune')) ?></p>
        <?php else: ?>
            <p class="aide"><?= e(t('rcreances.garanties_aide')) ?></p>
            <ul class="det-lignes">
                <?php foreach ($rapport['garanties'] as $g): ?>
                    <li data-dette="<?= (int) $g['id'] ?>">
                        <span>
                            <?php if ($voirDettes): ?><a class="det-lien-fort" href="/gestion/dettes/<?= (int) $g['id'] ?>"><?= e($g['client_nom']) ?></a><?php else: ?><strong><?= e($g['client_nom']) ?></strong><?php endif; ?>
                            <span class="tre-sous-texte"><?= e(t('rcreances.garant_attend', ['garant' => (string) $g['garant_nom'], 'servi' => $g['servi_nom'] ?? '—', 'date' => A::jour($g['cree_le'])])) ?> · <?= e($g['etablissement_nom']) ?></span>
                        </span>
                        <strong class="montant"><?= e(D::montant($g['reste'], $g['devise_id'], $devises)) ?></strong>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
<?php endif; ?>
