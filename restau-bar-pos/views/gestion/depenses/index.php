<?php
/**
 * Liste des dépenses : filtres (période, catégorie, établissement, moyen), totaux par devise
 * et équivalent en devise de référence, répartition par catégorie.
 * @var ?string $flash
 * @var array $periode
 * @var array $filtres
 * @var array $depenses
 * @var array<int, array> $devises
 * @var ?array $reference
 * @var array<int, float> $totaux
 * @var float $totalReference
 * @var array $parCategorie
 * @var array $categories
 * @var array $etablissements
 */

use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;

$filtre = $filtres['categorie'] || $filtres['etablissement'] !== '' || $filtres['moyen'];
?>
<link rel="stylesheet" href="/assets/css/gestion-tresorerie.css">

<div class="tre-entete">
    <div>
        <h1><?= e(t('depenses.titre')) ?></h1>
        <p class="aide"><?= e(t('depenses.aide')) ?></p>
    </div>
    <div class="tre-entete__actions">
        <a class="bouton" href="/gestion/depenses/nouvelle">+ <?= e(t('depenses.nouvelle')) ?></a>
        <a class="bouton bouton--secondaire" href="/gestion/depenses/categories"><?= e(t('depenses.gerer_categories')) ?></a>
    </div>
</div>

<?= View::rendre('gestion/tresorerie/_onglets', ['actif' => 'depenses']) ?>

<?php if ($flash): ?>
    <p class="alerte" role="status"><?= e($flash) ?></p>
<?php endif; ?>

<section class="bloc tre-filtres" aria-label="<?= e(t('depenses.filtres')) ?>">
    <?= View::rendre('gestion/tresorerie/_periode', ['periode' => $periode, 'action' => '/gestion/depenses', 'avecDates' => false]) ?>
    <nav class="tre-puces" aria-label="<?= e(t('depenses.filtre_moyen')) ?>">
        <a class="puce" href="<?= e(urlAvec(['moyen' => null])) ?>"<?= !$filtres['moyen'] ? ' aria-current="true"' : '' ?>><?= e(t('depenses.tous_moyens')) ?></a>
        <?php foreach (App\Repositories\GestionTresorerieRepository::TYPES as $type): ?>
            <a class="puce" href="<?= e(urlAvec(['moyen' => $type])) ?>"<?= $filtres['moyen'] === $type ? ' aria-current="true"' : '' ?>><?= View::rendre('gestion/tresorerie/_icone', ['type' => $type]) ?><?= e(t('tresorerie.type_' . $type)) ?></a>
        <?php endforeach; ?>
    </nav>
    <form class="tre-filtres__formulaire" method="get" action="/gestion/depenses">
        <?php if ($filtres['moyen']): ?><input type="hidden" name="moyen" value="<?= e($filtres['moyen']) ?>"><?php endif; ?>
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
            <label for="filtre-categorie"><?= e(t('depenses.champ_categorie')) ?></label>
            <select id="filtre-categorie" name="categorie">
                <option value=""><?= e(t('depenses.toutes_categories')) ?></option>
                <?php foreach ($categories as $categorie): ?>
                    <option value="<?= (int) $categorie['id'] ?>" <?= $filtres['categorie'] === (int) $categorie['id'] ? 'selected' : '' ?>><?= e(champ($categorie, 'nom')) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if (count($etablissements) > 0): ?>
            <div class="champ">
                <label for="filtre-etablissement"><?= e(t('gestion.etablissement')) ?></label>
                <select id="filtre-etablissement" name="etablissement">
                    <option value=""><?= e(t('depenses.tous_etablissements')) ?></option>
                    <option value="commun" <?= $filtres['etablissement'] === 'commun' ? 'selected' : '' ?>><?= e(t('depenses.communes')) ?></option>
                    <?php foreach ($etablissements as $etab): ?>
                        <option value="<?= (int) $etab['id'] ?>" <?= $filtres['etablissement'] === (string) $etab['id'] ? 'selected' : '' ?>><?= e($etab['nom']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <div class="tre-filtres__boutons">
            <button class="bouton bouton--secondaire" type="submit"><?= e(t('tresorerie.afficher')) ?></button>
            <?php if ($filtre || $periode['cle'] !== 'mois'): ?>
                <a class="lien" href="/gestion/depenses"><?= e(t('depenses.effacer_filtres')) ?></a>
            <?php endif; ?>
        </div>
    </form>
</section>

<section class="tre-totaux" aria-labelledby="tre-totaux-depenses">
    <h2 class="visuellement-cache" id="tre-totaux-depenses"><?= e(t('tresorerie.totaux')) ?></h2>
    <?php if ($depenses === []): ?>
        <p class="bloc aide"><?= e(t('depenses.aucune')) ?></p>
    <?php else: ?>
        <div class="chiffres">
            <?php foreach ($totaux as $deviseId => $montant): $devise = $devises[$deviseId] ?? null; if (!$devise) continue; ?>
                <div class="chiffre">
                    <span class="chiffre__valeur"><?= e(A::montant($montant, $devise)) ?></span>
                    <span class="chiffre__libelle"><?= e($filtres['moyen']
                        ? t('depenses.paye_en_moyen', ['code' => $devise['code'], 'moyen' => mb_strtolower(t('tresorerie.type_' . $filtres['moyen']))])
                        : t('depenses.paye_en', ['code' => $devise['code']])) ?></span>
                </div>
            <?php endforeach; ?>
            <?php if ($reference): ?>
                <div class="chiffre">
                    <span class="chiffre__valeur">≈ <?= e(A::montant($totalReference, $reference)) ?></span>
                    <span class="chiffre__libelle"><?= e(t('depenses.equivalent_total', ['code' => $reference['code'], 'n' => count($depenses)])) ?></span>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>

<?php if ($depenses !== []): ?>
    <section class="bloc" aria-labelledby="tre-liste-depenses">
        <h2 id="tre-liste-depenses"><?= e(t('depenses.liste')) ?></h2>
        <ul class="tre-depenses">
            <?php foreach ($depenses as $depense): ?>
                <li class="tre-depense" id="depense-<?= (int) $depense['id'] ?>">
                    <div class="tre-depense__tete">
                        <span class="tre-depense__date"><?= e(A::date($depense['date_depense'])) ?></span>
                        <span class="badge badge--braise"><?= e(champ($depense, 'categorie_nom')) ?></span>
                        <span class="tre-depense__etab"><?= e($depense['etablissement_nom'] ?? t('depenses.commune')) ?></span>
                    </div>
                    <a class="tre-depense__libelle" href="/gestion/depenses/<?= (int) $depense['id'] ?>"><?= e($depense['libelle']) ?></a>
                    <?php if ($depense['beneficiaire']): ?>
                        <span class="tre-sous-texte"><?= e(t('depenses.a_beneficiaire', ['nom' => $depense['beneficiaire']])) ?></span>
                    <?php endif; ?>
                    <ul class="tre-depense__paiements">
                        <?php foreach ($depense['paiements'] as $p): $devise = $devises[(int) $p['devise_id']] ?? null; ?>
                            <li class="<?= $filtres['moyen'] && $p['compte_type'] !== $filtres['moyen'] ? 'tre-hors-filtre' : '' ?>">
                                <?= View::rendre('gestion/tresorerie/_icone', ['type' => $p['compte_type']]) ?>
                                <span class="visuellement-cache"><?= e(t('tresorerie.type_' . $p['compte_type'])) ?> :</span>
                                <span><?= e($p['compte_nom']) ?></span>
                                <strong class="montant"><?= $devise ? e(A::montant($p['montant'], $devise)) : '' ?></strong>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ($reference && (count($depense['paiements']) > 1 || (int) ($depense['paiements'][0]['devise_id'] ?? 0) !== (int) $reference['id'])): ?>
                        <span class="tre-depense__equivalent montant">≈ <?= e(A::montant($depense['equivalent'], $reference)) ?></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if (count($depenses) >= 500): ?>
            <p class="aide"><?= e(t('depenses.limite')) ?></p>
        <?php endif; ?>
    </section>

    <?php if ($reference && count($parCategorie) > 1): ?>
        <section class="bloc" aria-labelledby="tre-par-categorie">
            <h2 id="tre-par-categorie"><?= e(t('depenses.par_categorie', ['code' => $reference['code']])) ?></h2>
            <ul class="tre-categories">
                <?php foreach ($parCategorie as $ligne): $part = $totalReference > 0 ? $ligne['montant'] / $totalReference : 0; ?>
                    <li>
                        <span class="tre-categories__nom"><?= e($ligne['nom']) ?> <span class="tre-sous-texte"><?= e(t($ligne['nombre'] > 1 ? 'depenses.n_depenses' : 'depenses.une_depense', ['n' => $ligne['nombre']])) ?></span></span>
                        <span class="tre-categories__barre" aria-hidden="true"><span style="width: <?= round($part * 100, 1) ?>%"></span></span>
                        <span class="montant"><?= e(A::montant($ligne['montant'], $reference)) ?> <span class="tre-sous-texte"><?= e(round($part * 100)) ?> %</span></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>
<?php endif; ?>
