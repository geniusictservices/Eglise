<?php
/**
 * Rapports > Ventes : ventes par vendeur sur une période (serveur de la période) et dix produits les plus vendus.
 * @var array $periode
 * @var list<string> $periodes
 * @var array $filtres
 * @var array $etablissements
 * @var list<array> $vendeurs
 * @var array $total tickets, articles, total, reference
 * @var list<array> $produits
 * @var ?array $locale
 * @var ?array $reference
 * @var array<int, array> $devises
 * @var bool $lienTickets
 */

use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;

$enLocale = static fn ($m): string => $locale ? A::montant($m, $locale) : (string) $m;
$enReference = static fn ($m): string => $reference ? A::montant($m, $reference) : '';
$montant = static fn ($m, $deviseId): string => isset($devises[(int) $deviseId]) ? A::montant($m, $devises[(int) $deviseId]) : (string) $m;
$garder = ['etablissement' => $filtres['etablissement']];
$maxProduit = $produits !== [] ? max(array_map(static fn (array $p) => (int) $p['quantite'], $produits)) : 0;
$pourcent = static fn (float $part): string => (string) round($part * 100) . "\u{00A0}%";
?>
<link rel="stylesheet" href="/assets/css/gestion-tresorerie.css">
<link rel="stylesheet" href="/assets/css/gestion-ventes.css">

<div class="tre-entete">
    <div>
        <h1><?= e(t('rventes.titre')) ?></h1>
        <p class="aide"><?= e(t('rventes.aide')) ?></p>
    </div>
</div>

<section class="bloc tre-filtres" aria-label="<?= e(t('gventes.filtres')) ?>">
    <?= View::rendre('gestion/tresorerie/_periode', ['periode' => $periode, 'periodes' => $periodes, 'action' => '/gestion/rapports/ventes', 'garder' => $garder]) ?>
    <?php if (count($etablissements) > 1): ?>
        <nav class="tre-puces" aria-label="<?= e(t('gestion.etablissement')) ?>">
            <a class="puce" href="<?= e(urlAvec(['etablissement' => null])) ?>"<?= !$filtres['etablissement'] ? ' aria-current="true"' : '' ?>><?= e(t('gventes.tous_etablissements')) ?></a>
            <?php foreach ($etablissements as $etab): ?>
                <a class="puce" href="<?= e(urlAvec(['etablissement' => (int) $etab['id']])) ?>"<?= $filtres['etablissement'] === (int) $etab['id'] ? ' aria-current="true"' : '' ?>><?= e($etab['nom']) ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>
</section>

<?php if ($vendeurs === []): ?>
    <p class="bloc aide"><?= e(t('rventes.aucune')) ?></p>
<?php else: ?>
    <section class="tre-totaux" aria-labelledby="rv-totaux">
        <h2 class="visuellement-cache" id="rv-totaux"><?= e(t('gventes.totaux')) ?></h2>
        <div class="chiffres ven-chiffres">
            <div class="chiffre">
                <span class="chiffre__valeur" data-total="tickets"><?= (int) $total['tickets'] ?></span>
                <span class="chiffre__libelle"><?= e(t('rventes.tickets_articles', ['articles' => $total['articles']])) ?></span>
            </div>
            <div class="chiffre">
                <span class="chiffre__valeur" data-total="local"><?= e($enLocale($total['total'])) ?></span>
                <span class="chiffre__libelle"><?= e(t('rventes.chiffre_affaires', ['code' => $locale['code'] ?? ''])) ?></span>
            </div>
            <?php if ($reference): ?>
                <div class="chiffre">
                    <span class="chiffre__valeur" data-total="reference">≈ <?= e($enReference($total['reference'])) ?></span>
                    <span class="chiffre__libelle"><?= e(t('gventes.equivalent_encaisse', ['code' => $reference['code']])) ?></span>
                </div>
            <?php endif; ?>
            <div class="chiffre">
                <span class="chiffre__valeur" data-total="panier"><?= e($enLocale($total['tickets'] > 0 ? $total['total'] / $total['tickets'] : 0)) ?></span>
                <span class="chiffre__libelle"><?= e(t('rventes.panier_moyen')) ?></span>
            </div>
        </div>
    </section>

    <section class="bloc" aria-labelledby="rv-classement">
        <h2 id="rv-classement"><?= e(t('rventes.classement')) ?></h2>
        <p class="aide"><?= e(t('rventes.classement_aide', ['code' => $locale['code'] ?? ''])) ?></p>
        <div class="ven-defile">
            <table class="tableau rv-vendeurs">
                <thead>
                    <tr>
                        <th scope="col" class="nombre">#</th>
                        <th scope="col"><?= e(t('gventes.vendeur')) ?></th>
                        <th scope="col" class="nombre"><?= e(t('rventes.col_tickets')) ?></th>
                        <th scope="col" class="nombre"><?= e(t('gventes.col_articles')) ?></th>
                        <th scope="col" class="nombre"><?= e(t('rventes.col_total', ['code' => $locale['code'] ?? ''])) ?></th>
                        <?php if ($reference): ?><th scope="col" class="nombre"><?= e(t('rventes.col_reference', ['code' => $reference['code']])) ?></th><?php endif; ?>
                        <th scope="col" class="nombre"><?= e(t('rventes.panier_moyen')) ?></th>
                        <th scope="col"><?= e(t('rventes.col_part')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($vendeurs as $rang => $v): $part = $total['total'] > 0 ? $v['total'] / $total['total'] : 0; $nom = $v['nom'] ?? t('gventes.vendeur_inconnu'); ?>
                        <tr<?= $rang === 0 ? ' class="rv-premier"' : '' ?> data-vendeur="<?= (int) $v['utilisateur_id'] ?>">
                            <td class="nombre rv-rang"><?= $rang + 1 ?></td>
                            <td class="rv-nom">
                                <span class="ven-vendeur">
                                    <?= View::rendre('partials/_avatar', ['nom' => $nom, 'photo' => $v['avatar'], 'classe' => 'avatar--petit']) ?>
                                    <span>
                                        <?php if ($lienTickets && $v['utilisateur_id'] !== null): ?>
                                            <a href="/gestion/ventes?<?= e(http_build_query(array_filter(['vendeur' => $v['utilisateur_id'], 'etablissement' => $filtres['etablissement'], 'statut' => 'payee'] + ($periode['cle'] === 'perso' ? ['du' => $periode['du'], 'au' => $periode['au']] : ['periode' => $periode['cle']])))) ?>"><?= e($nom) ?></a>
                                        <?php else: ?>
                                            <?= e($nom) ?>
                                        <?php endif; ?>
                                        <?php if ($rang === 0 && count($vendeurs) > 1): ?><span class="badge badge--braise"><?= e(t('rventes.meilleur')) ?></span><?php endif; ?>
                                    </span>
                                </span>
                            </td>
                            <td class="nombre" data-libelle="<?= e(t('rventes.col_tickets')) ?>" data-cle="tickets"><?= (int) $v['tickets'] ?></td>
                            <td class="nombre" data-libelle="<?= e(t('gventes.col_articles')) ?>" data-cle="articles"><?= (int) $v['articles'] ?></td>
                            <td class="nombre rv-total" data-cle="total"><strong class="montant"><?= e($enLocale($v['total'])) ?></strong></td>
                            <?php if ($reference): ?><td class="nombre rv-reference" data-cle="reference">≈ <?= e($enReference($v['reference'])) ?></td><?php endif; ?>
                            <td class="nombre" data-libelle="<?= e(t('rventes.panier_moyen')) ?>" data-cle="panier"><?= e($enLocale($v['tickets'] > 0 ? $v['total'] / $v['tickets'] : 0)) ?></td>
                            <td class="rv-part" data-cle="part">
                                <span class="tre-categories__barre" aria-hidden="true"><span style="width: <?= round($part * 100, 1) ?>%"></span></span>
                                <span class="nombre"><?= e($pourcent($part)) ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php if ($produits !== []): ?>
        <section class="bloc" aria-labelledby="rv-produits">
            <h2 id="rv-produits"><?= e(t('rventes.top_produits')) ?></h2>
            <ol class="rv-produits">
                <?php foreach ($produits as $p): ?>
                    <li data-produit="<?= (int) $p['conditionnement_id'] ?>">
                        <span class="rv-produits__nom"><?= e($p['libelle']) ?><?php if ($p['format']): ?> <span class="tre-sous-texte"><?= e($p['format']) ?></span><?php endif; ?></span>
                        <span class="tre-categories__barre" aria-hidden="true"><span style="width: <?= $maxProduit > 0 ? round((int) $p['quantite'] / $maxProduit * 100, 1) : 0 ?>%"></span></span>
                        <span class="rv-produits__chiffres">
                            <strong class="nombre" data-cle="quantite"><?= e(t('rventes.quantite', ['n' => (int) $p['quantite']])) ?></strong>
                            <span class="montant tre-sous-texte" data-cle="montant"><?= e($montant($p['montant'], $p['devise_id'])) ?></span>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ol>
        </section>
    <?php endif; ?>
<?php endif; ?>
