<?php
/**
 * Stock > Pertes : déclarer une perte (motif obligatoire) et liste de la période avec la valeur perdue.
 * @var array $periode
 * @var array $filtres
 * @var array<int, array> $lieuxVisibles
 * @var list<array> $lieux lieux où la personne peut déclarer
 * @var array<int, array> $articles
 * @var array<int, array> $tousArticles
 * @var list<array> $pertes
 * @var bool $voirValeur
 * @var ?array $reference
 * @var array $valeurs
 * @var array<string, string> $erreurs
 */

use App\Controllers\Gestion\StockAffichage as S;
use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;
use App\Repositories\GestionStockRepository;

$jeton = e(App\Core\Session::jetonCsrf());
$invalide = static fn (string $champ): string => isset($erreurs[$champ]) ? ' aria-invalid="true" aria-describedby="erreur-' . $champ . '"' : '';
$erreurChamp = static fn (string $champ): string => isset($erreurs[$champ]) ? '<p class="champ__erreur" id="erreur-' . $champ . '">' . e($erreurs[$champ]) . '</p>' : '';
$total = array_sum(array_map(static fn (array $p): float => (float) $p['cout'], $pertes));
?>
<?= View::rendre('gestion/stock/_onglets', ['flash' => $flash ?? null]) ?>

<div class="tre-entete">
    <div>
        <h1><?= e(t('stock.pertes_titre')) ?></h1>
        <p class="aide"><?= e(t('stock.pertes_aide')) ?></p>
    </div>
</div>

<?php if ($lieux !== []): ?>
    <section class="bloc" aria-labelledby="nouvelle-perte">
        <h2 id="nouvelle-perte"><?= e(t('stock.declarer_perte')) ?></h2>
        <?= View::rendre('gestion/tresorerie/_erreurs', ['erreurs' => array_values($erreurs)]) ?>
        <form method="post" action="/gestion/stock/pertes" class="stk-form-grille" novalidate>
            <input type="hidden" name="csrf" value="<?= $jeton ?>">
            <div class="champ">
                <label for="lieu_id"><?= e(t('stock.lieu')) ?></label>
                <select id="lieu_id" name="lieu_id"<?= $invalide('lieu_id') ?>>
                    <option value=""><?= e(t('stock.choisir_lieu')) ?></option>
                    <?php foreach ($lieux as $l): ?>
                        <option value="<?= (int) $l['id'] ?>" <?= (int) $valeurs['lieu_id'] === (int) $l['id'] ? 'selected' : '' ?>><?= e($l['nom'] . ' · ' . ($l['etablissement_nom'] ?? t('stock.central'))) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $erreurChamp('lieu_id') ?>
            </div>
            <div class="champ stk-champ-large">
                <label for="conditionnement_id"><?= e(t('stock.article_format')) ?></label>
                <select id="conditionnement_id" name="conditionnement_id"<?= $invalide('conditionnement_id') ?>>
                    <option value=""><?= e(t('stock.choisir_article')) ?></option>
                    <?= View::rendre('gestion/stock/_options-formats', ['articles' => $articles, 'choisi' => $valeurs['conditionnement_id'], 'achat' => false]) ?>
                </select>
                <?= $erreurChamp('conditionnement_id') ?>
            </div>
            <div class="champ">
                <label for="quantite"><?= e(t('stock.quantite')) ?></label>
                <input id="quantite" name="quantite" inputmode="decimal" autocomplete="off" value="<?= e($valeurs['quantite']) ?>"<?= $invalide('quantite') ?>>
                <?= $erreurChamp('quantite') ?>
            </div>
            <div class="champ">
                <label for="motif"><?= e(t('stock.motif_perte')) ?></label>
                <select id="motif" name="motif"<?= $invalide('motif') ?>>
                    <?php foreach (GestionStockRepository::MOTIFS_PERTE as $m): ?>
                        <option value="<?= $m ?>" <?= $valeurs['motif'] === $m ? 'selected' : '' ?>><?= e(t('stock.motif_perte_' . $m)) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $erreurChamp('motif') ?>
            </div>
            <div class="champ stk-champ-large">
                <label for="commentaire"><?= e(t('stock.commentaire_perte')) ?></label>
                <input id="commentaire" name="commentaire" maxlength="255" required value="<?= e($valeurs['commentaire']) ?>" placeholder="<?= e(t('stock.commentaire_perte_exemple')) ?>"<?= $invalide('commentaire') ?>>
                <?= $erreurChamp('commentaire') ?>
            </div>
            <div class="champ">
                <label for="date"><?= e(t('tresorerie.champ_date')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label>
                <input type="datetime-local" id="date" name="date" value="<?= e($valeurs['date']) ?>"<?= $invalide('date') ?>>
                <?= $erreurChamp('date') ?>
            </div>
            <div class="tre-actions"><button class="bouton" type="submit"><?= e(t('stock.enregistrer_perte')) ?></button></div>
        </form>
    </section>
<?php endif; ?>

<section class="bloc tre-filtres" aria-label="<?= e(t('stock.filtres')) ?>">
    <?= View::rendre('gestion/tresorerie/_periode', ['periode' => $periode, 'action' => '/gestion/stock/pertes', 'garder' => ['lieu' => $filtres['lieu'], 'motif' => $filtres['motif']]]) ?>
    <nav class="tre-puces" aria-label="<?= e(t('stock.motif_perte')) ?>">
        <a class="puce" href="<?= e(urlAvec(['motif' => null])) ?>"<?= $filtres['motif'] === null ? ' aria-current="true"' : '' ?>><?= e(t('stock.tous_motifs')) ?></a>
        <?php foreach (GestionStockRepository::MOTIFS_PERTE as $m): ?>
            <a class="puce" href="<?= e(urlAvec(['motif' => $m])) ?>"<?= $filtres['motif'] === $m ? ' aria-current="true"' : '' ?>><?= e(t('stock.motif_perte_' . $m)) ?></a>
        <?php endforeach; ?>
    </nav>
</section>

<section class="bloc" aria-labelledby="liste-pertes">
    <div class="stk-titre-liste">
        <h2 id="liste-pertes"><?= e(t(count($pertes) > 1 ? 'stock.n_pertes' : 'stock.une_perte', ['n' => count($pertes)])) ?></h2>
        <?php if ($voirValeur && $reference && $pertes !== []): ?><p class="stk-valeur"><?= e(t('stock.valeur_perdue')) ?> <strong class="montant" data-valeur-pertes><?= e(A::montant($total, $reference)) ?></strong></p><?php endif; ?>
    </div>
    <?php if ($pertes === []): ?>
        <p class="aide"><?= e(t('stock.aucune_perte')) ?></p>
    <?php else: ?>
        <div class="tre-defile">
            <table class="tableau stk-table">
                <thead>
                    <tr>
                        <th scope="col"><?= e(t('stock.col_date')) ?></th>
                        <th scope="col"><?= e(t('stock.col_article')) ?></th>
                        <th scope="col" class="nombre"><?= e(t('stock.col_quantite')) ?></th>
                        <th scope="col"><?= e(t('stock.motif_perte')) ?></th>
                        <?php if ($voirValeur): ?><th scope="col" class="nombre"><?= e(t('stock.col_valeur')) ?></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pertes as $p): $a = $tousArticles[(int) $p['produit_id']] ?? ['unite_base' => $p['unite_base'], 'conditionnements' => []]; ?>
                        <tr data-perte="<?= (int) $p['id'] ?>">
                            <td class="tre-journal__date"><?= e(A::dateHeure($p['date_perte'])) ?></td>
                            <td><strong><?= e(champ($p, 'nom')) ?></strong><span class="tre-sous-texte"><?= e($p['lieu_nom'] . ' · ' . ($p['etablissement_nom'] ?? t('stock.central'))) ?></span></td>
                            <td class="nombre"><?= e(S::quantite($p['quantite_base'], $a)) ?></td>
                            <td><span class="badge badge--neutre"><?= e(t('stock.motif_perte_' . $p['motif'])) ?></span> <?= e($p['commentaire']) ?><span class="tre-sous-texte"><?= e((string) $p['auteur']) ?></span></td>
                            <?php if ($voirValeur): ?><td class="nombre"><?= $p['cout'] !== null && $reference ? e(A::montant($p['cout'], $reference)) : '—' ?></td><?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
