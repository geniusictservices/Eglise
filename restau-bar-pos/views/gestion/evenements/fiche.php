<?php
/**
 * Fiche d'une soirée : informations, recettes de la nuit (de l'ouverture à la fermeture), coûts rattachés
 * (dépenses), coût des marchandises si connu, résultat comparé au budget ; recettes par heure, par catégorie et
 * produits ; rattacher ou détacher des dépenses.
 * @var ?string $flash
 * @var ?string $erreur
 * @var array $ev
 * @var array $bilan
 * @var list<array> $candidates
 * @var bool $peutGerer
 * @var bool $peutDepenser
 * @var bool $voirDepenses
 * @var bool $voirMarges
 * @var array<int, array> $devises
 * @var ?array $locale
 * @var ?array $reference
 * @var float $taux
 */

use App\Controllers\Gestion\DetteAffichage as D;
use App\Controllers\Gestion\EvenementController;
use App\Controllers\Gestion\RapportAffichage as R;
use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;

$enLocale = static fn (float $m): string => R::arrondi($m, $locale);
$enRef = static fn (float $m): string => $reference ? A::montant($m, $reference) : '';
$etat = EvenementController::etat($ev);
$t = $bilan['totaux'];
$jeton = e(App\Core\Session::jetonCsrf());
$id = (int) $ev['id'];
$quand = R::jourLong($ev['debut']) . ', ' . R::heure($ev['debut']) . ' – ' . R::heure($ev['fin']);
$badge = ['a_venir' => 'badge--glace', 'en_cours' => 'badge--succes', 'passe' => 'badge--neutre', 'annule' => 'badge--erreur'][$etat];
?>
<link rel="stylesheet" href="/assets/css/gestion-tresorerie.css">
<link rel="stylesheet" href="/assets/css/gestion-rapports.css">

<p class="tre-retour rap-pas-imprimer"><a class="lien" href="/gestion/evenements">← <?= e(t('evenements.titre')) ?></a></p>
<div class="tre-entete rap-entete">
    <div>
        <p class="surtitre"><?= e($ev['etablissement_nom']) ?> <span class="badge <?= $badge ?>"><?= e(t('evenements.statut_' . $etat)) ?></span></p>
        <h1><?= e($ev['titre']) ?></h1>
        <p class="aide"><?= e($quand) ?></p>
    </div>
    <div class="tre-entete__actions">
        <?php if ($peutGerer): ?><a class="bouton bouton--secondaire" href="/gestion/evenements/<?= $id ?>/modifier"><?= e(t('evenements.modifier')) ?></a><?php endif; ?>
        <?= View::rendre('gestion/rapports/_exporter', ['titre' => $ev['titre'], 'periode' => $quand]) ?>
    </div>
</div>

<?php if ($flash): ?><p class="alerte" role="status"><?= e($flash) ?></p><?php endif; ?>
<?php if ($erreur): ?><p class="alerte alerte--erreur" role="alert"><?= e($erreur) ?></p><?php endif; ?>

<section class="rap-kpis" aria-labelledby="evt-chiffres">
    <h2 class="visuellement-cache" id="evt-chiffres"><?= e(t('evenements.rentabilite')) ?></h2>
    <div class="rap-kpi" data-kpi="recettes">
        <span class="rap-kpi__libelle"><?= e(t('evenements.recettes')) ?></span>
        <strong class="rap-kpi__valeur montant"><?= e($enLocale($t['ca_locale'])) ?></strong>
        <span class="rap-kpi__second montant"><?= e($enRef($t['ca_reference'])) ?></span>
        <span class="rap-kpi__pied"><?= e(t('rtableau.tickets_panier', ['n' => $t['tickets'], 'panier' => $enLocale($t['panier_locale'])])) ?><?php if ($t['credit_locale'] > 0.5): ?> · <?= e(t('rtableau.dont_credit', ['montant' => $enLocale($t['credit_locale'])])) ?><?php endif; ?></span>
    </div>
    <div class="rap-kpi" data-kpi="couts">
        <span class="rap-kpi__libelle"><?= e(t('evenements.couts')) ?></span>
        <strong class="rap-kpi__valeur montant"><?= e($enRef($bilan['couts'])) ?></strong>
        <span class="rap-kpi__second montant">≈ <?= e($enLocale($bilan['couts'] * $taux)) ?></span>
        <span class="rap-kpi__pied">
            <?php if ($bilan['budget_reference'] !== null): $part = $bilan['budget_reference'] > 0 ? $bilan['couts'] / $bilan['budget_reference'] : null; ?>
                <span class="rap-ecart <?= $part !== null && $part > 1.0005 ? 'rap-ecart--mauvais' : 'rap-ecart--bon' ?>" data-cle="budget"><?= e(t('evenements.du_budget', ['part' => $part !== null ? R::pourcent($part) : '—'])) ?></span>
                <span><?= e(t('evenements.budget_de', ['montant' => D::montant($ev['budget'], (int) $ev['budget_devise_id'], $devises)])) ?></span>
            <?php else: ?><span><?= e(t('evenements.sans_budget')) ?></span><?php endif; ?>
        </span>
    </div>
    <?php if ($voirMarges): ?>
        <div class="rap-kpi" data-kpi="marchandises">
            <span class="rap-kpi__libelle"><?= e(t('evenements.marchandises')) ?></span>
            <strong class="rap-kpi__valeur montant"><?= e($enRef($bilan['marchandises'])) ?></strong>
            <span class="rap-kpi__pied"><?php if ($t['couverture'] < 0.995 && $t['ca_reference'] > 0): ?><span class="rap-note"><?= e(t('rtableau.couverture', ['part' => R::pourcent($t['couverture'])])) ?></span><?php endif; ?></span>
        </div>
    <?php endif; ?>
    <div class="rap-kpi rap-kpi--resultat<?= $bilan['resultat'] < 0 ? ' rap-kpi--negatif' : '' ?>" data-kpi="resultat">
        <span class="rap-kpi__libelle"><?= e(t('evenements.resultat')) ?></span>
        <strong class="rap-kpi__valeur montant" data-reference="<?= e((string) round($bilan['resultat'], 2)) ?>"><?= e($enRef($bilan['resultat'])) ?></strong>
        <span class="rap-kpi__second montant">≈ <?= e($enLocale($bilan['resultat'] * $taux)) ?></span>
        <span class="rap-kpi__pied">
            <?php if ($bilan['marge_pct'] !== null): ?><span><?= e(t('evenements.marge_pct', ['part' => R::pourcent($bilan['marge_pct'])])) ?></span><?php endif; ?>
            <span class="rap-note"><?= e($voirMarges ? t('evenements.resultat_aide_marge') : t('evenements.resultat_aide')) ?></span>
        </span>
    </div>
</section>
<p class="aide rap-comparaison"><?= e(t('evenements.recettes_aide', ['quand' => $quand])) ?> <?= e(t('rtableau.taux_note')) ?></p>

<div class="rap-grille">
    <section class="bloc" aria-labelledby="evt-infos">
        <h2 id="evt-infos"><?= e(t('evenements.la_soiree')) ?></h2>
        <dl class="evt-infos">
            <div><dt><?= e(t('evenements.quand')) ?></dt><dd><?= e($quand) ?></dd></div>
            <div><dt><?= e(t('gestion.etablissement')) ?></dt><dd><?= e($ev['etablissement_nom']) ?></dd></div>
            <?php if ($ev['theme']): ?><div><dt><?= e(t('evenements.theme')) ?></dt><dd><?= e($ev['theme']) ?></dd></div><?php endif; ?>
            <?php if ($ev['artistes']): ?><div><dt><?= e(t('evenements.artistes')) ?></dt><dd><?= e($ev['artistes']) ?></dd></div><?php endif; ?>
            <div><dt><?= e(t('evenements.budget')) ?></dt><dd><?= $ev['budget'] !== null ? e(D::montant($ev['budget'], (int) $ev['budget_devise_id'], $devises)) : e(t('evenements.sans_budget')) ?></dd></div>
            <?php if ($ev['description']): ?><div><dt><?= e(t('evenements.description')) ?></dt><dd><?= e($ev['description']) ?></dd></div><?php endif; ?>
            <?php if ($ev['cree_par_nom']): ?><div><dt><?= e(t('evenements.cree_par')) ?></dt><dd><?= e($ev['cree_par_nom']) ?> · <?= e(A::dateHeure($ev['cree_le'])) ?></dd></div><?php endif; ?>
        </dl>
        <p class="aide"><?= e(t('evenements.entrees_bientot')) ?></p>
    </section>

    <?php if ($bilan['categories'] !== []): ?>
        <section class="bloc" aria-labelledby="evt-categories">
            <h2 id="evt-categories"><?= e(t('evenements.par_categorie')) ?></h2>
            <?php $maxC = max(array_map(static fn (array $c) => (float) $c['locale'], $bilan['categories'])) ?: 1; ?>
            <ol class="rap-classement" data-liste="categories">
                <?php foreach ($bilan['categories'] as $c): ?>
                    <li>
                        <span class="rap-classement__nom"><?= e($c['nom']) ?></span>
                        <span class="rap-jauge" aria-hidden="true"><span style="width: <?= round((float) $c['locale'] / $maxC * 100, 1) ?>%"></span></span>
                        <span class="rap-classement__chiffres"><strong class="montant"><?= e($enLocale((float) $c['locale'])) ?></strong><span class="rap-sous"><?= e(t('rapports.quantite', ['n' => R::nombre((float) $c['quantite'])])) ?> · <?= e($enRef((float) $c['reference'])) ?></span></span>
                    </li>
                <?php endforeach; ?>
            </ol>
        </section>
    <?php endif; ?>
</div>

<?php if (count($bilan['heures']) > 1 && $t['tickets'] > 0): ?>
    <section class="bloc" aria-labelledby="evt-heures">
        <h2 id="evt-heures"><?= e(t('evenements.par_heure')) ?></h2>
        <?= View::rendre('gestion/rapports/_barres', [
            'id' => 'graphique-heures',
            'titre' => t('evenements.graphique_heures', ['code' => $locale['code'] ?? '']),
            'etiquettes' => array_map(static fn (string $h) => substr($h, 11, 2) . 'h', array_keys($bilan['heures'])),
            'series' => [
                ['nom' => t('rtableau.encaisse'), 'valeurs' => array_values(array_map(static fn (array $h) => $h['encaisse_locale'], $bilan['heures']))],
                ['nom' => t('rtableau.credit'), 'valeurs' => array_values(array_map(static fn (array $h) => $h['credit_locale'], $bilan['heures']))],
            ],
            'formater' => static fn (float $m): string => R::compact($m, $locale),
        ]) ?>
    </section>
<?php endif; ?>

<section class="bloc" id="couts" aria-labelledby="evt-couts">
    <div class="tre-entete">
        <div>
            <h2 id="evt-couts"><?= e(t('evenements.couts_titre')) ?></h2>
            <p class="aide"><?= e(t('evenements.couts_aide')) ?></p>
        </div>
        <?php if ($peutGerer && $peutDepenser): ?>
            <div class="tre-entete__actions"><a class="bouton bouton--secondaire" href="/gestion/depenses/nouvelle?evenement=<?= $id ?>">+ <?= e(t('evenements.nouvelle_depense')) ?></a></div>
        <?php endif; ?>
    </div>
    <?php if ($bilan['categories_couts'] !== []): ?>
        <ul class="rap-liste-simple" data-liste="couts-categories">
            <?php foreach ($bilan['categories_couts'] as $c): ?>
                <li><span><?= e($c['nom']) ?> <span class="rap-note">(<?= (int) $c['nombre'] ?>)</span></span><span class="montant"><?= e($enRef($c['reference'])) ?></span></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if ($bilan['depenses'] === [] && $candidates === []): ?>
        <p class="aide" data-vide="couts"><?= e(t('evenements.aucun_cout')) ?></p>
    <?php elseif ($peutGerer): ?>
        <form method="post" action="/gestion/evenements/<?= $id ?>/couts">
            <input type="hidden" name="csrf" value="<?= $jeton ?>">
            <?php if ($bilan['depenses'] !== []): ?>
                <h3><?= e(t('evenements.rattachees')) ?></h3>
                <ul class="evt-lier" data-liste="rattachees">
                    <?php foreach ($bilan['depenses'] as $d): ?>
                        <li data-depense="<?= (int) $d['id'] ?>">
                            <label>
                                <input type="checkbox" name="detacher[]" value="<?= (int) $d['id'] ?>" aria-label="<?= e(t('evenements.detacher', ['libelle' => $d['libelle']])) ?>">
                                <span><?php if ($voirDepenses): ?><a href="/gestion/depenses/<?= (int) $d['id'] ?>"><?= e($d['libelle']) ?></a><?php else: ?><?= e($d['libelle']) ?><?php endif; ?>
                                    <span class="rap-sous"><?= e(A::jour($d['date_depense'])) ?> · <?= e(champ($d, 'categorie_nom')) ?><?= $d['beneficiaire'] ? ' · ' . e($d['beneficiaire']) : '' ?></span></span>
                                <span class="montant"><?= e(D::montants($d['montants'], $devises)) ?></span>
                            </label>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="aide"><?= e(t('evenements.cocher_detacher')) ?></p>
            <?php endif; ?>
            <?php if ($candidates !== []): ?>
                <h3><?= e(t('evenements.a_rattacher')) ?></h3>
                <p class="aide"><?= e(t('evenements.a_rattacher_aide')) ?></p>
                <ul class="evt-lier" data-liste="candidates">
                    <?php foreach ($candidates as $d): ?>
                        <li data-depense="<?= (int) $d['id'] ?>">
                            <label>
                                <input type="checkbox" name="lier[]" value="<?= (int) $d['id'] ?>" aria-label="<?= e(t('evenements.rattacher', ['libelle' => $d['libelle']])) ?>">
                                <span><?= e($d['libelle']) ?><span class="rap-sous"><?= e(A::jour($d['date_depense'])) ?> · <?= e(champ($d, 'categorie_nom')) ?></span></span>
                                <span class="montant"><?= e(D::montants($d['montants'], $devises)) ?></span>
                            </label>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <div class="tre-actions"><button class="bouton bouton--secondaire" type="submit"><?= e(t('evenements.enregistrer_couts')) ?></button></div>
        </form>
    <?php else: ?>
        <ul class="evt-lier" data-liste="rattachees">
            <?php foreach ($bilan['depenses'] as $d): ?>
                <li><span></span><span><?= e($d['libelle']) ?><span class="rap-sous"><?= e(A::jour($d['date_depense'])) ?> · <?= e(champ($d, 'categorie_nom')) ?></span></span><span class="montant"><?= e(D::montants($d['montants'], $devises)) ?></span></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<?php if ($bilan['produits'] !== []): ?>
    <section class="bloc" aria-labelledby="evt-produits">
        <h2 id="evt-produits"><?= e(t('evenements.produits')) ?></h2>
        <ul class="rap-liste-simple">
            <?php foreach ($bilan['produits'] as $p): ?>
                <li><span><?= e($p['nom']) ?> <span class="rap-note"><?= e(t('rapports.quantite', ['n' => R::nombre($p['quantite'])])) ?></span></span><span class="montant"><?= e($enLocale($p['locale'])) ?></span></li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<?php if ($peutGerer): ?>
    <section class="bloc rap-pas-imprimer" aria-labelledby="evt-actions">
        <h2 id="evt-actions"><?= e(t('evenements.actions')) ?></h2>
        <div class="tre-actions">
            <form method="post" action="/gestion/evenements/<?= $id ?>/statut">
                <input type="hidden" name="csrf" value="<?= $jeton ?>">
                <input type="hidden" name="statut" value="<?= $ev['statut'] === 'annule' ? 'prevu' : 'annule' ?>">
                <button class="bouton bouton--secondaire" type="submit"><?= e($ev['statut'] === 'annule' ? t('evenements.retablir') : t('evenements.annuler')) ?></button>
            </form>
            <?php if ($bilan['depenses'] === []): ?>
                <form method="post" action="/gestion/evenements/<?= $id ?>/supprimer">
                    <input type="hidden" name="csrf" value="<?= $jeton ?>">
                    <button class="bouton bouton--danger" type="submit"><?= e(t('evenements.supprimer')) ?></button>
                </form>
            <?php endif; ?>
        </div>
        <p class="aide"><?= e(t('evenements.actions_aide')) ?></p>
    </section>
<?php endif; ?>
