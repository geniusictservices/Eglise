<?php
/**
 * Achats > Commandes : bons de commande par statut et fournisseur.
 * @var list<array> $commandes
 * @var ?string $statut
 * @var ?int $fournisseur
 * @var list<array> $fournisseurs
 * @var array<int, array> $devises
 * @var bool $peutCommander
 */

use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;
use App\Repositories\AchatRepository;

$badges = ['brouillon' => 'badge--neutre', 'envoyee' => 'badge--glace', 'partielle' => 'badge--braise', 'recue' => 'badge--succes', 'annulee' => 'badge--erreur'];
?>
<?= View::rendre('gestion/stock/_onglets', ['flash' => $flash ?? null]) ?>

<div class="tre-entete">
    <div>
        <h1><?= e(t('achats.commandes_titre')) ?></h1>
        <p class="aide"><?= e(t('achats.commandes_aide')) ?></p>
    </div>
    <div class="tre-entete__actions">
        <a class="bouton bouton--secondaire" href="/gestion/stock/suggestions"><?= e(t('stock.suggestions_titre')) ?></a>
        <?php if ($peutCommander): ?><a class="bouton" href="/gestion/achats/commandes/nouvelle">+ <?= e(t('achats.nouvelle_commande')) ?></a><?php endif; ?>
    </div>
</div>

<section class="bloc tre-filtres" aria-label="<?= e(t('stock.filtres')) ?>">
    <nav class="tre-puces" aria-label="<?= e(t('stock.statut')) ?>">
        <a class="puce" href="<?= e(urlAvec(['statut' => null])) ?>"<?= $statut === null ? ' aria-current="true"' : '' ?>><?= e(t('stock.tous_statuts')) ?></a>
        <?php foreach (AchatRepository::STATUTS_COMMANDE as $s): ?>
            <a class="puce" href="<?= e(urlAvec(['statut' => $s])) ?>"<?= $statut === $s ? ' aria-current="true"' : '' ?>><?= e(t('achats.statut_' . $s)) ?></a>
        <?php endforeach; ?>
    </nav>
    <form class="tre-filtres__formulaire" method="get" action="/gestion/achats/commandes">
        <?php if ($statut): ?><input type="hidden" name="statut" value="<?= e($statut) ?>"><?php endif; ?>
        <div class="champ"><label for="f-fournisseur"><?= e(t('achats.fournisseur')) ?></label>
            <select id="f-fournisseur" name="fournisseur"><option value=""><?= e(t('achats.tous_fournisseurs')) ?></option>
                <?php foreach ($fournisseurs as $f): ?><option value="<?= (int) $f['id'] ?>" <?= $fournisseur === (int) $f['id'] ? 'selected' : '' ?>><?= e($f['nom']) ?></option><?php endforeach; ?></select></div>
        <div class="tre-filtres__boutons"><button class="bouton bouton--secondaire" type="submit"><?= e(t('tresorerie.afficher')) ?></button></div>
    </form>
</section>

<section class="bloc">
    <?php if ($commandes === []): ?><p class="aide"><?= e(t('achats.aucune_commande')) ?></p><?php else: ?>
        <div class="tre-defile">
            <table class="tableau stk-table">
                <thead><tr><th scope="col"><?= e(t('stock.col_numero')) ?></th><th scope="col"><?= e(t('achats.fournisseur')) ?></th><th scope="col" class="nombre"><?= e(t('achats.montant_prevu')) ?></th><th scope="col"><?= e(t('stock.statut')) ?></th></tr></thead>
                <tbody>
                    <?php foreach ($commandes as $c): ?>
                        <tr data-commande="<?= (int) $c['id'] ?>">
                            <td><a class="stk-numero" href="/gestion/achats/commandes/<?= (int) $c['id'] ?>"><?= e($c['numero']) ?></a><span class="tre-sous-texte"><?= e(A::jour($c['date_commande'])) ?></span></td>
                            <td><?= e($c['fournisseur']) ?><span class="tre-sous-texte"><?= e($c['lieu_nom'] . ' · ' . ($c['etablissement_nom'] ?? t('stock.central'))) ?> · <?= e(t((int) $c['nb_lignes'] > 1 ? 'stock.n_lignes' : 'stock.une_ligne', ['n' => (int) $c['nb_lignes']])) ?></span></td>
                            <td class="nombre"><?= (float) $c['montant'] > 0 && isset($devises[(int) $c['devise_id']]) ? e(A::montant($c['montant'], $devises[(int) $c['devise_id']])) : '—' ?></td>
                            <td><span class="badge <?= $badges[$c['statut']] ?>"><?= e(t('achats.statut_' . $c['statut'])) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
