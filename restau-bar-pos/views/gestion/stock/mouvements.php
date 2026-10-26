<?php
/**
 * Stock > Mouvements : journal (qui, quand, pourquoi) filtré par période, lieu, type et article.
 * @var array $periode
 * @var array $filtres
 * @var array<int, array> $lieux
 * @var array<int, array> $articles
 * @var list<array> $mouvements
 * @var int $total
 * @var int $page
 * @var int $pages
 * @var bool $voirVentes
 * @var bool $voirAchats
 */

use App\Controllers\Gestion\StockAffichage as S;
use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;
use App\Repositories\StockRepository;

$lien = static function (array $m) use ($voirVentes, $voirAchats): ?string {
    $id = $m['source_id'] !== null ? (int) $m['source_id'] : null;
    if ($id === null) {
        return null;
    }
    return match ($m['type']) {
        'vente', 'recette', 'vide_recupere', 'annulation_vente' => $voirVentes ? '/gestion/ventes/' . $id : null,
        'reception', 'vide_rendu' => $voirAchats ? '/gestion/achats/receptions/' . $id : null,
        'transfert_sortie', 'transfert_entree', 'transfert_retour' => '/gestion/stock/transferts/' . $id,
        'inventaire' => '/gestion/stock/inventaires/' . $id,
        default => null,
    };
};
?>
<?= View::rendre('gestion/stock/_onglets', []) ?>

<div class="tre-entete">
    <div>
        <h1><?= e(t('stock.mouvements_titre')) ?></h1>
        <p class="aide"><?= e(t('stock.mouvements_aide')) ?></p>
    </div>
</div>

<section class="bloc tre-filtres" aria-label="<?= e(t('stock.filtres')) ?>">
    <?= View::rendre('gestion/tresorerie/_periode', ['periode' => $periode, 'action' => '/gestion/stock/mouvements', 'avecDates' => false]) ?>
    <form class="tre-filtres__formulaire" method="get" action="/gestion/stock/mouvements">
        <?php if ($periode['cle'] !== 'perso'): ?><input type="hidden" name="periode" value="<?= e($periode['cle']) ?>"><?php endif; ?>
        <div class="champ">
            <label for="filtre-du"><?= e(t('tresorerie.du')) ?></label>
            <input type="date" id="filtre-du" name="du" value="<?= e($periode['cle'] === 'perso' ? (string) $periode['du'] : '') ?>">
        </div>
        <div class="champ">
            <label for="filtre-au"><?= e(t('tresorerie.au')) ?></label>
            <input type="date" id="filtre-au" name="au" value="<?= e($periode['cle'] === 'perso' ? (string) $periode['au'] : '') ?>">
        </div>
        <div class="champ">
            <label for="filtre-lieu"><?= e(t('stock.lieu')) ?></label>
            <select id="filtre-lieu" name="lieu">
                <option value=""><?= e(t('stock.tous_lieux')) ?></option>
                <?php foreach ($lieux as $l): ?>
                    <option value="<?= (int) $l['id'] ?>" <?= $filtres['lieu'] === (int) $l['id'] ? 'selected' : '' ?>><?= e($l['nom'] . ' · ' . ($l['etablissement_nom'] ?? t('stock.central'))) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="champ">
            <label for="filtre-type"><?= e(t('stock.type')) ?></label>
            <select id="filtre-type" name="type">
                <option value=""><?= e(t('stock.tous_types')) ?></option>
                <?php foreach (StockRepository::TYPES as $type): ?>
                    <option value="<?= e($type) ?>" <?= $filtres['type'] === $type ? 'selected' : '' ?>><?= e(t('stock.mvt_' . $type)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="champ">
            <label for="filtre-produit"><?= e(t('stock.article')) ?></label>
            <select id="filtre-produit" name="produit">
                <option value=""><?= e(t('stock.tous_articles')) ?></option>
                <?php foreach ($articles as $a): ?>
                    <option value="<?= (int) $a['id'] ?>" <?= $filtres['produit'] === (int) $a['id'] ? 'selected' : '' ?>><?= e(champ($a, 'nom')) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="tre-filtres__boutons">
            <button class="bouton bouton--secondaire" type="submit"><?= e(t('tresorerie.afficher')) ?></button>
            <a class="lien" href="/gestion/stock/mouvements"><?= e(t('stock.effacer_filtres')) ?></a>
        </div>
    </form>
</section>

<section class="bloc" aria-labelledby="stk-mvt-titre">
    <h2 id="stk-mvt-titre"><?= e(t($total > 1 ? 'stock.n_mouvements' : 'stock.un_mouvement', ['n' => $total])) ?></h2>
    <?php if ($mouvements === []): ?>
        <p class="aide"><?= e(t('stock.aucun_mouvement')) ?></p>
    <?php else: ?>
        <div class="tre-defile">
            <table class="tableau stk-table stk-journal">
                <thead>
                    <tr>
                        <th scope="col"><?= e(t('stock.col_date')) ?></th>
                        <th scope="col"><?= e(t('stock.col_article')) ?></th>
                        <th scope="col"><?= e(t('stock.type')) ?></th>
                        <th scope="col" class="nombre"><?= e(t('stock.col_quantite')) ?></th>
                        <th scope="col" class="nombre"><?= e(t('stock.col_apres')) ?></th>
                        <th scope="col"><?= e(t('stock.col_qui_pourquoi')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($mouvements as $m):
                        $article = $articles[(int) $m['produit_id']] ?? ['unite_base' => $m['unite_base'], 'conditionnements' => []];
                        $q = (float) $m['quantite'];
                        $url = $lien($m);
                        ?>
                        <tr data-mouvement="<?= (int) $m['id'] ?>" data-type="<?= e($m['type']) ?>">
                            <td class="tre-journal__date"><?= e(A::dateHeure($m['date_mouvement'])) ?></td>
                            <td><strong><?= e(champ($m, 'nom')) ?></strong><span class="tre-sous-texte"><?= e($m['lieu_nom'] . ' · ' . ($m['etablissement_nom'] ?? t('stock.central'))) ?></span></td>
                            <td><span class="badge <?= $q >= 0 ? 'badge--succes' : 'badge--neutre' ?>"><?= e(t('stock.mvt_' . $m['type'])) ?></span></td>
                            <td class="nombre"><strong class="<?= $q < 0 ? 'tre-negatif' : 'tre-entree' ?>" data-quantite="<?= e((string) $q) ?>"><?= $q > 0 ? '+' : '' ?><?= e(S::quantite($q, $article)) ?></strong>
                                <?php if ($m['quantite_saisie'] !== null && $m['format_nom_fr'] !== null): ?><span class="tre-sous-texte"><?= e(S::nombre(abs((float) $m['quantite_saisie'])) . ' × ' . champ(['nom_fr' => $m['format_nom_fr'], 'nom_en' => $m['format_nom_en']], 'nom')) ?></span><?php endif; ?></td>
                            <td class="nombre"><?= $m['stock_apres'] !== null ? e(S::quantite($m['stock_apres'], $article)) : '' ?></td>
                            <td class="stk-journal__motif">
                                <?php if ($url): ?><a href="<?= e($url) ?>"><?= e((string) $m['motif']) ?></a><?php else: ?><?= e((string) $m['motif']) ?><?php endif; ?>
                                <span class="tre-sous-texte"><?= e($m['auteur'] ?? t('stock.systeme')) ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($pages > 1): ?>
            <nav class="stk-pages" aria-label="<?= e(t('stock.pages')) ?>">
                <?php if ($page > 1): ?><a class="bouton bouton--secondaire bouton--petit" href="<?= e(urlAvec(['page' => $page - 1 > 1 ? $page - 1 : null])) ?>" rel="prev">← <?= e(t('gventes.page_precedente')) ?></a><?php endif; ?>
                <span class="nombre"><?= e(t('gventes.page_sur', ['page' => $page, 'pages' => $pages])) ?></span>
                <?php if ($page < $pages): ?><a class="bouton bouton--secondaire bouton--petit" href="<?= e(urlAvec(['page' => $page + 1])) ?>" rel="next"><?= e(t('gventes.page_suivante')) ?> →</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
