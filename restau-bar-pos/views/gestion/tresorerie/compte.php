<?php
/**
 * Fiche d'un compte : solde de chaque devise, mouvements d'une période (toutes les devises ou une seule)
 * avec le solde de la devise après chaque ligne.
 * @var ?string $flash
 * @var array $compte avec devises (devise_id => solde…)
 * @var array<int, array> $devises
 * @var ?int $filtre devise choisie, sinon toutes
 * @var array $periode
 * @var array $mouvements
 * @var array<int, float> $soldesDebut devise_id => solde en début de période
 * @var array<int, float> $soldesFin
 * @var array<int, float> $entrees
 * @var array<int, float> $sorties
 * @var string[] $erreurs
 * @var bool $confirmerDesactivation
 */

use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;

$jeton = e(App\Core\Session::jetonCsrf());
$id = (int) $compte['id'];
$inconnue = ['code' => '?', 'symbole' => '', 'symbole_avant' => 0, 'decimales' => 2, 'arrondi' => 0];
$devise = static fn (int $deviseId): array => $devises[$deviseId] ?? $inconnue;
$plusieurs = count($compte['devises']) > 1;
$details = array_filter([
    t('tresorerie.type_' . $compte['type']),
    $compte['operateur'],
    $compte['numero'],
    $compte['etablissement_nom'] ?? t('tresorerie.tous_etablissements'),
]);
$nonNuls = array_filter($compte['devises'], static fn (array $d): bool => abs($d['solde']) >= 0.0005);
$texteSoldes = implode(' · ', array_map(static fn (array $d): string => A::montant($d['solde'], $devise($d['devise_id'])), $nonNuls));
$libelle = static function (array $m) use ($id, $devise): array {
    // [texte principal, détail, lien]
    switch ($m['nature']) {
        case 'solde_initial':
            return [t('tresorerie.nature_solde_initial'), '', null];
        case 'transfert':
            $lien = '/gestion/tresorerie/transferts?du=' . substr($m['date_operation'], 0, 10) . '&au=' . substr($m['date_operation'], 0, 10) . '#transfert-' . (int) $m['source_id'];
            if ((int) $m['compte_source_id'] === (int) $m['compte_destination_id']) {
                // Change dans le même compte (dollars contre francs dans la caisse)
                return [t('tresorerie.change_interne', ['de' => $devise((int) $m['devise_source_id'])['code'], 'vers' => $devise((int) $m['devise_destination_id'])['code']]), (string) $m['motif'], $lien];
            }
            $versAutre = (int) $m['compte_source_id'] === $id;
            return [
                $versAutre ? t('tresorerie.transfert_vers', ['compte' => (string) $m['destination_nom']]) : t('tresorerie.transfert_depuis', ['compte' => (string) $m['source_nom']]),
                (string) $m['motif'],
                $lien,
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
$codes = implode(' · ', array_map(static fn (array $d): string => $d['code'], $compte['devises']));
?>
<link rel="stylesheet" href="/assets/css/gestion-tresorerie.css">

<p class="tre-retour"><a class="lien" href="/gestion/tresorerie">← <?= e(t('tresorerie.titre')) ?></a></p>

<div class="tre-entete">
    <div>
        <p class="surtitre"><?= View::rendre('gestion/tresorerie/_icone', ['type' => $compte['type']]) ?> <?= e($codes) ?></p>
        <h1>
            <?= e($compte['nom']) ?>
            <?php if (!$compte['active']): ?><span class="badge badge--neutre"><?= e(t('tresorerie.desactive')) ?></span><?php endif; ?>
        </h1>
        <p class="aide"><?= e(implode(' · ', $details)) ?></p>
    </div>
    <div class="tre-soldes" aria-label="<?= e(t('tresorerie.solde_actuel')) ?>">
        <?php foreach ($compte['devises'] as $deviseId => $ligne): ?>
            <div class="tre-solde" data-devise="<?= e($devise($deviseId)['code']) ?>">
                <span class="tre-solde__libelle"><?= e(t('tresorerie.solde_en', ['code' => $devise($deviseId)['code']])) ?></span>
                <span class="tre-solde__valeur montant<?= $ligne['solde'] < -0.0005 ? ' tre-negatif' : '' ?>"><?= e(A::montant($ligne['solde'], $devise($deviseId))) ?></span>
            </div>
        <?php endforeach; ?>
        <?php if ($compte['devises'] === []): ?>
            <div class="tre-solde"><span class="tre-solde__libelle"><?= e(t('tresorerie.aucune_devise')) ?></span></div>
        <?php endif; ?>
    </div>
</div>
<?= View::rendre('gestion/rapports/_exporter', ['titre' => $compte['nom'], 'periode' => $periode['du'] !== null ? App\Controllers\Gestion\RapportAffichage::periode($periode['du'], $periode['au']) : null]) ?>

<?php if ($flash): ?>
    <p class="alerte" role="status"><?= e($flash) ?></p>
<?php endif; ?>
<?= View::rendre('gestion/tresorerie/_erreurs', ['erreurs' => $erreurs]) ?>

<?php if ($confirmerDesactivation): ?>
    <section class="tre-avertissement" role="alertdialog" aria-labelledby="tre-desactiver-titre" id="confirmation">
        <p class="tre-avertissement__titre" id="tre-desactiver-titre"><?= e(t('tresorerie.confirmer_desactiver_titre', ['nom' => $compte['nom']])) ?></p>
        <p><?= e(t('tresorerie.confirmer_desactiver_texte', ['solde' => $texteSoldes])) ?></p>
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
    <?php if ($plusieurs): ?>
        <nav class="tre-puces tre-filtre-devise" aria-label="<?= e(t('tresorerie.filtre_devise')) ?>">
            <a class="puce" href="<?= e(urlAvec(['devise' => null])) ?>"<?= $filtre === null ? ' aria-current="true"' : '' ?>><?= e(t('tresorerie.toutes_devises')) ?></a>
            <?php foreach ($compte['devises'] as $deviseId => $ligne): ?>
                <a class="puce" href="<?= e(urlAvec(['devise' => $deviseId])) ?>"<?= $filtre === $deviseId ? ' aria-current="true"' : '' ?>><?= e($devise($deviseId)['code']) ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>
    <?= View::rendre('gestion/tresorerie/_periode', ['periode' => $periode, 'action' => '/gestion/tresorerie/comptes/' . $id, 'garder' => ['devise' => $filtre]]) ?>

    <div class="defile tre-defile">
        <table class="tableau tre-journal">
            <thead>
            <tr>
                <th><?= e(t('tresorerie.col_date')) ?></th>
                <th><?= e(t('tresorerie.col_devise')) ?></th>
                <th><?= e(t('tresorerie.col_libelle')) ?></th>
                <th class="nombre"><?= e(t('tresorerie.col_entree')) ?></th>
                <th class="nombre"><?= e(t('tresorerie.col_sortie')) ?></th>
                <th class="nombre"><?= e(t('tresorerie.col_solde')) ?></th>
            </tr>
            </thead>
            <tbody>
            <?php if ($periode['du'] !== null): foreach ($soldesDebut as $deviseId => $montant): ?>
                <tr class="tre-journal__report">
                    <td class="nombre tre-journal__date"><?= e(A::jour($periode['du'])) ?></td>
                    <td class="tre-journal__devise"><?= e($devise($deviseId)['code']) ?></td>
                    <td class="tre-journal__libelle"><?= e(t('tresorerie.solde_debut')) ?></td>
                    <td class="tre-journal__entree"></td><td class="tre-journal__sortie"></td>
                    <td class="nombre montant tre-journal__solde<?= $montant < -0.0005 ? ' tre-negatif' : '' ?>"><?= e(A::montant($montant, $devise($deviseId))) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            <?php if ($mouvements === []): ?>
                <tr><td colspan="6" class="aide"><?= e(t('tresorerie.aucun_mouvement')) ?></td></tr>
            <?php endif; ?>
            <?php foreach ($mouvements as $m): [$texte, $detail, $lien] = $libelle($m); $d = $devise((int) $m['devise_id']); ?>
                <tr data-devise="<?= e($d['code']) ?>">
                    <td class="nombre tre-journal__date"><?= e(A::date($m['date_operation'])) ?></td>
                    <td class="tre-journal__devise"><?= e($d['code']) ?></td>
                    <td class="tre-journal__libelle">
                        <?php if ($lien): ?><a href="<?= e($lien) ?>"><?= e($texte) ?></a><?php else: ?><?= e($texte) ?><?php endif; ?>
                        <?php if ($detail !== ''): ?><span class="tre-sous-texte"><?= e($detail) ?></span><?php endif; ?>
                    </td>
                    <td class="nombre montant tre-entree tre-journal__entree"><?= $m['sens'] === 'entree' ? e(A::montant($m['montant'], $d)) : '' ?></td>
                    <td class="nombre montant tre-journal__sortie"><?= $m['sens'] === 'sortie' ? e(A::montant($m['montant'], $d)) : '' ?></td>
                    <td class="nombre montant tre-journal__solde<?= $m['solde_apres'] < -0.0005 ? ' tre-negatif' : '' ?>"><?= e(A::montant($m['solde_apres'], $d)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
            <?php foreach ($soldesFin as $deviseId => $montant): $d = $devise($deviseId); ?>
                <tr data-devise="<?= e($d['code']) ?>">
                    <th scope="row" colspan="2"><?= e(($periode['au'] !== null ? t('tresorerie.solde_fin', ['date' => A::jour($periode['au'])]) : t('tresorerie.solde_fin_tout')) . ' · ' . $d['code']) ?></th>
                    <td class="tre-journal__vide"></td>
                    <td class="nombre montant" data-libelle="<?= e(t('tresorerie.col_entree')) ?> :"><?= e(A::montant($entrees[$deviseId] ?? 0, $d)) ?></td>
                    <td class="nombre montant" data-libelle="<?= e(t('tresorerie.col_sortie')) ?> :"><?= e(A::montant($sorties[$deviseId] ?? 0, $d)) ?></td>
                    <td class="nombre montant<?= $montant < -0.0005 ? ' tre-negatif' : '' ?>" data-libelle="<?= e(t('tresorerie.col_solde')) ?> :"><strong><?= e(A::montant($montant, $d)) ?></strong></td>
                </tr>
            <?php endforeach; ?>
            </tfoot>
        </table>
    </div>
</section>
