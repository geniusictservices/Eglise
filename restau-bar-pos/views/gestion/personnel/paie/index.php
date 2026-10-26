<?php
/**
 * Personnel > Paie : période (mois ou semaine) et établissement, bulletins, employés à préparer, pourboires.
 * @var ?string $flash
 * @var array $etablissements
 * @var ?array $etab
 * @var array{type: string, debut: string, fin: string, valeur: string} $periode
 * @var list<array> $bulletins
 * @var list<array> $aPreparer
 * @var array{net: array, paye: array, brut: array} $totaux
 * @var array $brouillonsPourboires
 * @var array<int, array> $devises
 * @var array<string, string> $erreurs
 */

use App\Controllers\Gestion\PersonnelAffichage as P;
use App\Core\View;

$jeton = e(App\Core\Session::jetonCsrf());
$cachePeriode = '<input type="hidden" name="type" value="' . e($periode['type']) . '"><input type="hidden" name="' . e($periode['type']) . '" value="' . e($periode['valeur']) . '">';
$precedent = $periode['type'] === 'mois' ? date('Y-m', strtotime($periode['debut'] . ' -1 month')) : date('Y-m-d', strtotime($periode['debut'] . ' -7 days'));
$suivant = $periode['type'] === 'mois' ? date('Y-m', strtotime($periode['debut'] . ' +1 month')) : date('Y-m-d', strtotime($periode['debut'] . ' +7 days'));
?>
<?= View::rendre('gestion/personnel/_onglets-paie', ['actif' => 'bulletins']) ?>
<h1><?= e(t('pers.paie_titre')) ?></h1>
<p class="aide"><?= e(t('pers.paie_aide')) ?></p>
<?php if ($flash): ?><p class="alerte" role="status"><?= e($flash) ?></p><?php endif; ?>
<?= View::rendre('gestion/tresorerie/_erreurs', ['erreurs' => array_values($erreurs)]) ?>

<section class="bloc per-filtres">
    <?= View::rendre('gestion/personnel/_etablissements', ['etablissements' => $etablissements, 'etab' => $etab]) ?>
    <nav class="tre-puces" aria-label="<?= e(t('pers.type_periode')) ?>">
        <a class="puce" href="<?= e(urlAvec(['type' => null, 'semaine' => null])) ?>"<?= $periode['type'] === 'mois' ? ' aria-current="true"' : '' ?>><?= e(t('pers.par_mois_court')) ?></a>
        <a class="puce" href="<?= e(urlAvec(['type' => 'semaine', 'mois' => null])) ?>"<?= $periode['type'] === 'semaine' ? ' aria-current="true"' : '' ?>><?= e(t('pers.par_semaine_court')) ?></a>
    </nav>
    <nav class="per-jours" aria-label="<?= e(t('pers.col_periode')) ?>">
        <a class="bouton bouton--secondaire bouton--petit" href="<?= e(urlAvec([$periode['type'] => $precedent])) ?>" rel="prev">←<span class="visuellement-cache"> <?= e(t('pers.periode_precedente')) ?></span></a>
        <strong class="per-jours__titre" data-periode><?= e(P::periode($periode['debut'], $periode['fin'])) ?></strong>
        <a class="bouton bouton--secondaire bouton--petit" href="<?= e(urlAvec([$periode['type'] => $suivant])) ?>" rel="next">→<span class="visuellement-cache"> <?= e(t('pers.periode_suivante')) ?></span></a>
    </nav>
</section>

<?php if ($etab): ?>
<div class="chiffres per-chiffres">
    <div class="chiffre"><span class="chiffre__valeur"><?= count($bulletins) ?></span><span class="chiffre__libelle"><?= e(t('pers.bulletins')) ?></span></div>
    <div class="chiffre"><span class="chiffre__valeur montant per-chiffre-montant" data-total="brut"><?= e(P::montants($totaux['brut'])) ?></span><span class="chiffre__libelle"><?= e(t('pers.total_brut')) ?></span></div>
    <div class="chiffre"><span class="chiffre__valeur montant per-chiffre-montant" data-total="net"><?= e(P::montants($totaux['net'])) ?></span><span class="chiffre__libelle"><?= e(t('pers.col_net')) ?></span></div>
    <div class="chiffre"><span class="chiffre__valeur montant per-chiffre-montant" data-total="paye"><?= e(P::montants($totaux['paye'])) ?></span><span class="chiffre__libelle"><?= e(t('pers.col_paye')) ?></span></div>
</div>

<section class="bloc" aria-labelledby="per-bulletins">
    <h2 id="per-bulletins"><?= e(t('pers.bulletins_periode')) ?></h2>
    <?php if ($bulletins === []): ?>
        <p class="aide"><?= e(t('pers.aucun_bulletin_periode')) ?></p>
    <?php else: ?>
        <div class="defile">
            <table class="tableau per-tableau">
                <thead><tr><th scope="col"><?= e(t('pers.col_employe')) ?></th><th scope="col"><?= e(t('pers.statut')) ?></th><th scope="col" class="nombre"><?= e(t('pers.total_brut')) ?></th><th scope="col" class="nombre"><?= e(t('pers.total_retenues')) ?></th><th scope="col" class="nombre"><?= e(t('pers.col_net')) ?></th><th scope="col" class="nombre"><?= e(t('pers.col_paye')) ?></th></tr></thead>
                <tbody>
                    <?php foreach ($bulletins as $b): ?>
                        <tr data-bulletin="<?= (int) $b['id'] ?>">
                            <th scope="row"><a class="det-lien-fort" href="/gestion/personnel/paie/<?= (int) $b['id'] ?>"><?= e($b['employe_nom']) ?></a><span class="tre-sous-texte"><?= e(P::periode($b['debut'], $b['fin'])) ?></span></th>
                            <td><span class="badge badge--<?= ['brouillon' => 'neutre', 'valide' => 'braise', 'paye' => 'succes', 'annule' => 'erreur'][$b['statut']] ?>"><?= e(t('pers.bulletin_' . $b['statut'])) ?></span></td>
                            <td class="nombre montant"><?= e(P::montants($b['totaux']['gains'])) ?></td>
                            <td class="nombre montant"><?= e(P::montants($b['totaux']['retenues'])) ?></td>
                            <td class="nombre montant"><strong><?= e(P::montants($b['totaux']['net'])) ?></strong></td>
                            <td class="nombre montant"><?= e(P::montants($b['paye'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<div class="det-colonnes">
    <section class="bloc" aria-labelledby="per-preparer">
        <h2 id="per-preparer"><?= e(t('pers.preparer_titre')) ?></h2>
        <?php if ($aPreparer === []): ?>
            <p class="aide"><?= e(t('pers.rien_a_preparer')) ?></p>
        <?php else: ?>
            <p class="aide"><?= e(t('pers.preparer_aide')) ?></p>
            <form method="post" action="/gestion/personnel/paie/preparer">
                <input type="hidden" name="csrf" value="<?= $jeton ?>">
                <input type="hidden" name="etablissement_id" value="<?= (int) $etab['id'] ?>">
                <?= $cachePeriode ?>
                <fieldset class="per-cases">
                    <legend class="visuellement-cache"><?= e(t('pers.employes_titre')) ?></legend>
                    <?php foreach ($aPreparer as $e): ?>
                        <label class="coche"><input type="checkbox" name="employes[]" value="<?= (int) $e['id'] ?>" checked> <?= e($e['nom']) ?> <span class="tre-sous-texte">&nbsp;<?= e(t('pers.par_' . $e['periode'])) ?></span></label>
                    <?php endforeach; ?>
                </fieldset>
                <div class="tre-actions"><button class="bouton" type="submit"><?= e(t('pers.preparer')) ?></button></div>
            </form>
        <?php endif; ?>
    </section>

    <section class="bloc" aria-labelledby="per-pourboires">
        <h2 id="per-pourboires"><?= e(t('pers.pourboires_titre')) ?></h2>
        <p class="aide"><?= e(t('pers.pourboires_aide')) ?></p>
        <?php if ($brouillonsPourboires === []): ?>
            <p class="aide"><?= e(t('pers.pourboires_sans_brouillon')) ?></p>
        <?php else: ?>
            <form method="post" action="/gestion/personnel/paie/pourboires" class="per-ligne-formulaire" novalidate>
                <input type="hidden" name="csrf" value="<?= $jeton ?>">
                <input type="hidden" name="etablissement_id" value="<?= (int) $etab['id'] ?>">
                <?= $cachePeriode ?>
                <div class="champ">
                    <label for="pourboires-montant"><?= e(t('pers.montant')) ?></label>
                    <input id="pourboires-montant" name="montant" inputmode="decimal"<?= isset($erreurs['pourboires']) ? ' aria-invalid="true"' : '' ?>>
                </div>
                <div class="champ">
                    <label for="pourboires-devise"><?= e(t('depenses.champ_devise')) ?></label>
                    <select id="pourboires-devise" name="devise_id"><?php foreach ($devises as $d => $dv): ?><option value="<?= (int) $d ?>"><?= e($dv['code']) ?></option><?php endforeach; ?></select>
                </div>
                <button class="bouton bouton--secondaire" type="submit"><?= e(t('pers.repartir')) ?></button>
            </form>
        <?php endif; ?>
    </section>
</div>
<?php endif; ?>
