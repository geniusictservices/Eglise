<?php
/**
 * Fiche d'un compte : solde, mouvements d'une période avec le solde après chaque ligne.
 * @var ?string $flash
 * @var array $compte
 * @var ?array $devise
 * @var array $periode
 * @var array $mouvements
 * @var float $soldeDebut
 * @var float $soldeFin
 * @var float $entrees
 * @var float $sorties
 * @var string[] $erreurs
 * @var bool $confirmerDesactivation
 */

use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;

$jeton = e(App\Core\Session::jetonCsrf());
$id = (int) $compte['id'];
$devise ??= ['code' => '', 'symbole' => '', 'symbole_avant' => 0, 'decimales' => 2, 'arrondi' => 0];
$details = array_filter([
    t('tresorerie.type_' . $compte['type']),
    $compte['operateur'],
    $compte['numero'],
    $compte['etablissement_nom'] ?? t('tresorerie.tous_etablissements'),
]);
$libelle = static function (array $m) use ($id): array {
    // [texte principal, détail, lien]
    switch ($m['nature']) {
        case 'solde_initial':
            return [t('tresorerie.nature_solde_initial'), '', null];
        case 'transfert':
            $versAutre = (int) $m['compte_source_id'] === $id;
            return [
                $versAutre ? t('tresorerie.transfert_vers', ['compte' => (string) $m['destination_nom']]) : t('tresorerie.transfert_depuis', ['compte' => (string) $m['source_nom']]),
                (string) $m['motif'],
                '/gestion/tresorerie/transferts?du=' . substr($m['date_operation'], 0, 10) . '&au=' . substr($m['date_operation'], 0, 10) . '#transfert-' . (int) $m['source_id'],
            ];
        case 'depense':
            return [
                (string) ($m['depense_libelle'] ?? $m['libelle']),
                implode(' · ', array_filter([champ($m, 'categorie_nom'), $m['beneficiaire']])),
                $m['source_id'] ? '/gestion/depenses/' . (int) $m['source_id'] : null,
            ];
        case 'vente':
            // Encaissement, monnaie rendue ou annulation d'une vente : lien vers sa fiche (Ventes > Tickets)
            return [t('tresorerie.nature_vente'), (string) $m['libelle'], $m['source_id'] ? '/gestion/ventes/' . (int) $m['source_id'] : null];
        default:
            return [t('tresorerie.nature_' . $m['nature']), (string) $m['libelle'], null];
    }
};
?>
<link rel="stylesheet" href="/assets/css/gestion-tresorerie.css">

<p class="tre-retour"><a class="lien" href="/gestion/tresorerie">← <?= e(t('tresorerie.titre')) ?></a></p>

<div class="tre-entete">
    <div>
        <p class="surtitre"><?= View::rendre('gestion/tresorerie/_icone', ['type' => $compte['type']]) ?> <?= e($devise['code']) ?></p>
        <h1>
            <?= e($compte['nom']) ?>
            <?php if (!$compte['active']): ?><span class="badge badge--neutre"><?= e(t('tresorerie.desactive')) ?></span><?php endif; ?>
        </h1>
        <p class="aide"><?= e(implode(' · ', $details)) ?></p>
    </div>
    <div class="tre-solde">
        <span class="tre-solde__libelle"><?= e(t('tresorerie.solde_actuel')) ?></span>
        <span class="tre-solde__valeur montant<?= (float) $compte['solde'] < -0.0005 ? ' tre-negatif' : '' ?>"><?= e(A::montant($compte['solde'], $devise)) ?></span>
    </div>
</div>

<?php if ($flash): ?>
    <p class="alerte" role="status"><?= e($flash) ?></p>
<?php endif; ?>
<?= View::rendre('gestion/tresorerie/_erreurs', ['erreurs' => $erreurs]) ?>

<?php if ($confirmerDesactivation): ?>
    <section class="tre-avertissement" role="alertdialog" aria-labelledby="tre-desactiver-titre" id="confirmation">
        <p class="tre-avertissement__titre" id="tre-desactiver-titre"><?= e(t('tresorerie.confirmer_desactiver_titre', ['nom' => $compte['nom']])) ?></p>
        <p><?= e(t('tresorerie.confirmer_desactiver_texte', ['solde' => A::montant($compte['solde'], $devise)])) ?></p>
        <div class="tre-actions">
            <form method="post" action="/gestion/tresorerie/comptes/<?= $id ?>/desactiver">
                <input type="hidden" name="csrf" value="<?= $jeton ?>">
                <input type="hidden" name="confirme" value="1">
                <button class="bouton bouton--danger" type="submit"><?= e(t('tresorerie.desactiver_oui')) ?></button>
            </form>
            <a class="bouton bouton--secondaire" href="/gestion/tresorerie/transferts?source=<?= $id ?>#nouveau-transfert"><?= e(t('tresorerie.transferer_solde')) ?></a>
        </div>
    </section>
<?php endif; ?>

<div class="tre-actions">
    <?php if ($compte['active']): ?>
        <a class="bouton bouton--secondaire" href="/gestion/tresorerie/transferts?source=<?= $id ?>#nouveau-transfert"><?= e(t('tresorerie.action_transfert')) ?></a>
        <a class="bouton bouton--secondaire" href="/gestion/depenses/nouvelle?compte=<?= $id ?>">+ <?= e(t('depenses.nouvelle')) ?></a>
    <?php endif; ?>
    <a class="bouton bouton--fantome" href="/gestion/tresorerie/comptes/<?= $id ?>/modifier"><?= e(t('tresorerie.modifier')) ?></a>
</div>

<section class="bloc" aria-labelledby="tre-mouvements-titre">
    <h2 id="tre-mouvements-titre"><?= e(t('tresorerie.mouvements')) ?></h2>
    <?= View::rendre('gestion/tresorerie/_periode', ['periode' => $periode, 'action' => '/gestion/tresorerie/comptes/' . $id]) ?>

    <div class="defile tre-defile">
        <table class="tableau tre-journal">
            <thead>
            <tr>
                <th><?= e(t('tresorerie.col_date')) ?></th>
                <th><?= e(t('tresorerie.col_libelle')) ?></th>
                <th class="nombre"><?= e(t('tresorerie.col_entree')) ?></th>
                <th class="nombre"><?= e(t('tresorerie.col_sortie')) ?></th>
                <th class="nombre"><?= e(t('tresorerie.col_solde')) ?></th>
            </tr>
            </thead>
            <tbody>
            <?php if ($periode['du'] !== null): ?>
                <tr class="tre-journal__report">
                    <td class="nombre"><?= e(A::jour($periode['du'])) ?></td>
                    <td><?= e(t('tresorerie.solde_debut')) ?></td>
                    <td></td><td></td>
                    <td class="nombre montant"><?= e(A::montant($soldeDebut, $devise)) ?></td>
                </tr>
            <?php endif; ?>
            <?php if ($mouvements === []): ?>
                <tr><td colspan="5" class="aide"><?= e(t('tresorerie.aucun_mouvement')) ?></td></tr>
            <?php endif; ?>
            <?php foreach ($mouvements as $m): [$texte, $detail, $lien] = $libelle($m); ?>
                <tr>
                    <td class="nombre tre-journal__date"><?= e(A::date($m['date_operation'])) ?></td>
                    <td>
                        <?php if ($lien): ?><a href="<?= e($lien) ?>"><?= e($texte) ?></a><?php else: ?><?= e($texte) ?><?php endif; ?>
                        <?php if ($detail !== ''): ?><span class="tre-sous-texte"><?= e($detail) ?></span><?php endif; ?>
                    </td>
                    <td class="nombre montant tre-entree"><?= $m['sens'] === 'entree' ? e(A::montant($m['montant'], $devise)) : '' ?></td>
                    <td class="nombre montant"><?= $m['sens'] === 'sortie' ? e(A::montant($m['montant'], $devise)) : '' ?></td>
                    <td class="nombre montant<?= $m['solde_apres'] < -0.0005 ? ' tre-negatif' : '' ?>"><?= e(A::montant($m['solde_apres'], $devise)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
            <tr>
                <th scope="row" colspan="2"><?= e($periode['au'] !== null ? t('tresorerie.solde_fin', ['date' => A::jour($periode['au'])]) : t('tresorerie.solde_fin_tout')) ?></th>
                <td class="nombre montant" data-libelle="<?= e(t('tresorerie.col_entree')) ?> :"><?= e(A::montant($entrees, $devise)) ?></td>
                <td class="nombre montant" data-libelle="<?= e(t('tresorerie.col_sortie')) ?> :"><?= e(A::montant($sorties, $devise)) ?></td>
                <td class="nombre montant<?= $soldeFin < -0.0005 ? ' tre-negatif' : '' ?>" data-libelle="<?= e(t('tresorerie.col_solde')) ?> :"><strong><?= e(A::montant($soldeFin, $devise)) ?></strong></td>
            </tr>
            </tfoot>
        </table>
    </div>
</section>
