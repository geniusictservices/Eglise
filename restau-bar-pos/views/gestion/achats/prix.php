<?php
/**
 * Achats > Prix d'achat : historique des prix par produit et fournisseur, prix par format et par unité de
 * base, CUMP actuel (avec rapports.marges).
 * @var array $periode
 * @var array $filtres
 * @var list<array> $lignes
 * @var array<int, array> $articles
 * @var list<array> $fournisseurs
 * @var array<int, float> $cumps
 * @var array<int, array> $devises
 * @var ?array $reference
 * @var bool $voirCump
 */

use App\Controllers\Gestion\StockAffichage as S;
use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;
?>
<?= View::rendre('gestion/stock/_onglets', []) ?>

<div class="tre-entete">
    <div>
        <h1><?= e(t('achats.prix_titre')) ?></h1>
        <p class="aide"><?= e(t('achats.prix_aide')) ?></p>
    </div>
</div>

<section class="bloc tre-filtres" aria-label="<?= e(t('stock.filtres')) ?>">
    <?= View::rendre('gestion/tresorerie/_periode', ['periode' => $periode, 'action' => '/gestion/achats/prix', 'avecDates' => false]) ?>
    <form class="tre-filtres__formulaire" method="get" action="/gestion/achats/prix">
        <?php if ($periode['cle'] !== 'perso'): ?><input type="hidden" name="periode" value="<?= e($periode['cle']) ?>"><?php endif; ?>
        <div class="champ"><label for="f-produit"><?= e(t('stock.article')) ?></label>
            <select id="f-produit" name="produit"><option value=""><?= e(t('stock.tous_articles')) ?></option>
                <?php foreach ($articles as $a): ?><option value="<?= (int) $a['id'] ?>" <?= $filtres['produit'] === (int) $a['id'] ? 'selected' : '' ?>><?= e(champ($a, 'nom')) ?></option><?php endforeach; ?></select></div>
        <div class="champ"><label for="f-fournisseur"><?= e(t('achats.fournisseur')) ?></label>
            <select id="f-fournisseur" name="fournisseur"><option value=""><?= e(t('achats.tous_fournisseurs')) ?></option>
                <?php foreach ($fournisseurs as $f): ?><option value="<?= (int) $f['id'] ?>" <?= $filtres['fournisseur'] === (int) $f['id'] ? 'selected' : '' ?>><?= e($f['nom']) ?></option><?php endforeach; ?></select></div>
        <div class="tre-filtres__boutons"><button class="bouton bouton--secondaire" type="submit"><?= e(t('tresorerie.afficher')) ?></button></div>
    </form>
</section>

<?php if ($voirCump && $filtres['produit'] && isset($cumps[$filtres['produit']], $articles[$filtres['produit']]) && $reference): $a = $articles[$filtres['produit']]; ?>
    <p class="bloc stk-cump"><?= e(t('achats.cump_actuel', ['article' => champ($a, 'nom'), 'cout' => A::montant($cumps[$filtres['produit']], $reference), 'unite' => S::unite((string) $a['unite_base'])])) ?></p>
<?php endif; ?>

<section class="bloc">
    <?php if ($lignes === []): ?><p class="aide"><?= e(t('achats.aucun_prix')) ?></p><?php else: ?>
        <div class="tre-defile">
            <table class="tableau stk-table">
                <thead><tr><th scope="col"><?= e(t('stock.col_date')) ?></th><th scope="col"><?= e(t('stock.col_article')) ?></th><th scope="col"><?= e(t('achats.fournisseur')) ?></th><th scope="col" class="nombre"><?= e(t('achats.prix_format')) ?></th><th scope="col" class="nombre"><?= e(t('achats.prix_unite')) ?></th></tr></thead>
                <tbody>
                    <?php foreach ($lignes as $l): $d = $devises[(int) $l['devise_id']] ?? null; $a = $articles[(int) $l['produit_id']] ?? ['unite_base' => $l['unite_base'], 'conditionnements' => []];
                        $parBase = (float) $l['quantite_base'] > 0 ? (float) $l['montant'] / (float) $l['quantite_base'] : null; ?>
                        <tr>
                            <td class="tre-journal__date"><a href="/gestion/achats/receptions/<?= (int) $l['reception_id'] ?>"><?= e(A::jour($l['date_reception'])) ?></a></td>
                            <td><strong><?= e(champ($l, 'nom')) ?></strong><span class="tre-sous-texte"><?= e(champ(['nom_fr' => (string) $l['format_nom_fr'], 'nom_en' => (string) $l['format_nom_en']], 'nom')) ?> · <?= e(S::nombre($l['quantite'])) ?></span></td>
                            <td><?= e($l['fournisseur']) ?></td>
                            <td class="nombre"><?= $d ? e(A::montant($l['prix_unitaire'], $d)) : '' ?></td>
                            <td class="nombre"><?= $d && $parBase !== null ? e(A::montant($parBase, ['decimales' => max(2, (int) $d['decimales'])] + $d)) . ' / ' . e(S::unite((string) $a['unite_base'])) : '' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
